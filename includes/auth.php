<?php
/**
 * AFM#2 - Authentication & Session Security
 * Modular Multi-Method Authentication Architecture:
 * - Username/Email & Password verification
 * - Google Sign-In (OAuth 2.0 / OpenID Connect)
 * - Passkeys using WebAuthn (FIDO2)
 * - Administrator-only user provisioning & invitations
 * 
 * Strictly preserves existing user IDs, session cookies, rate limits, and catalogue data.
 */

if (!defined('AFM_APP')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
require_once dirname(__DIR__) . '/config/db.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get current user ID (with database verification, active status check, and session self-healing)
 */
function get_current_user_id(): ?int {
    if (empty($_SESSION['afm_user_id'])) {
        return null;
    }

    $rawId = (int)$_SESSION['afm_user_id'];
    if ($rawId <= 0) {
        return null;
    }

    static $verifiedCache = [];
    if (isset($verifiedCache[$rawId])) {
        return $verifiedCache[$rawId];
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT `user_id`, `username`, `role`, `is_active` FROM `app_users` WHERE `user_id` = :uid LIMIT 1");
        $stmt->execute([':uid' => $rawId]);
        $row = $stmt->fetch();
        if ($row) {
            // If user is disabled, immediately fail closed and invalidate session
            if ((int)$row['is_active'] !== 1) {
                $_SESSION = [];
                return null;
            }
            $verifiedCache[$rawId] = (int)$row['user_id'];
            $_SESSION['afm_role'] = $row['role'] ?? 'user';
            return $verifiedCache[$rawId];
        }

        // Unrecognized, deleted, or invalid user ID: fail closed
        return null;
    } catch (Exception $e) {
        error_log("AFM2 get_current_user_id database error: " . $e->getMessage());
        // Fail closed on database errors
        return null;
    }
}

/**
 * Check if current user is logged in
 */
function is_authenticated(): bool {
    return get_current_user_id() !== null;
}

/**
 * Get current username
 */
function get_current_username(): ?string {
    if (!is_authenticated()) {
        return null;
    }
    return $_SESSION['afm_username'] ?? null;
}

/**
 * Get current user's role ('admin' or 'user')
 */
function get_current_user_role(): ?string {
    if (!is_authenticated()) {
        return null;
    }
    return $_SESSION['afm_role'] ?? 'user';
}

/**
 * Check if current user is an administrator
 */
function is_admin(): bool {
    return is_authenticated() && get_current_user_role() === 'admin';
}

/**
 * Require authentication for protected pages; redirect to login or return 401
 */
function require_auth(bool $isApi = false): void {
    if (!is_authenticated()) {
        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Authentication required. Please log in.']);
            exit;
        }
        
        $loginUrl = 'login.php';
        header("Location: {$loginUrl}");
        exit;
    }
}

/**
 * Require administrator role; return 403 or redirect
 */
function require_admin(bool $isApi = false): void {
    require_auth($isApi);
    if (!is_admin()) {
        if ($isApi) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Administrative privileges required.']);
            exit;
        }

        http_response_code(403);
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>403 Forbidden — AFM#2</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:sans-serif;background:var(--bg-primary,#121214);color:var(--text-primary,#e4e4e7);">
    <div style="text-align:center;padding:2rem;max-width:480px;background:var(--bg-surface,#18181b);border:1px solid var(--border-color,#27272a);border-radius:8px;">
        <h2 style="margin-top:0;color:var(--danger,#ef4444);">403 Forbidden</h2>
        <p style="color:var(--text-secondary,#a1a1aa);margin:1rem 0;">You do not have administrative privileges to access this area.</p>
        <p><a href="index.php" style="color:var(--accent,#f59e0b);text-decoration:none;font-weight:600;">Return to Journal</a></p>
    </div>
</body>
</html>
        <?php
        exit;
    }
}

/**
 * Check if the application has any registered user accounts
 */
function has_users(): bool {
    try {
        $pdo = get_db_connection();
        $count = $pdo->query("SELECT COUNT(*) FROM `app_users`")->fetchColumn();
        return (int)$count > 0;
    } catch (Exception $e) {
        error_log("AFM2 has_users error: " . $e->getMessage());
        return false;
    }
}

/**
 * Client IP helper.
 * Strictly enforces proxy trust boundary: forwarding headers (X-Forwarded-For, CF-Connecting-IP)
 * are NEVER evaluated unless TRUST_PROXY_HEADERS is explicitly set to true.
 */
function get_client_ip(): string {
    $trustProxy = filter_var(env('TRUST_PROXY_HEADERS', false), FILTER_VALIDATE_BOOLEAN);

    if ($trustProxy) {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
                return $cfIp;
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            foreach ($ips as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '127.0.0.1';
}

/**
 * Construct rate limit storage keys for IP, composite (IP+user), and account buckets.
 */
function get_rate_limit_keys(string $ip, ?string $username = null): array {
    $keys = [
        'ip'        => $ip,
        'composite' => null,
        'account'   => null,
        'anon'      => 'a:' . substr(hash('sha256', $ip), 0, 32),
    ];

    $cleanUser = $username !== null ? strtolower(trim($username)) : '';
    if ($cleanUser !== '') {
        $keys['composite'] = 'c:' . substr(hash('sha256', $ip . '|' . $cleanUser), 0, 32);
        $keys['account']   = 'u:' . substr(hash('sha256', $cleanUser), 0, 32);
    }

    return $keys;
}

/**
 * Check login rate limiting using multi-bucket throttling
 */
function is_rate_limited(?string $username = null): bool {
    try {
        $pdo = get_db_connection();
        $ip = get_client_ip();
        $keys = get_rate_limit_keys($ip, $username);

        // Purge expired attempts older than 15 minutes
        $pdo->exec("DELETE FROM `login_rate_limits` WHERE `attempted_at` < (NOW() - INTERVAL 15 MINUTE)");

        // 1. If username is null, check source IP with threshold of 5 attempts
        if ($username === null || trim($username) === '') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM `login_rate_limits`
                WHERE `ip_address` = :ip_key
                  AND `attempted_at` >= (NOW() - INTERVAL 15 MINUTE)
            ");
            $stmt->execute([':ip_key' => $keys['ip']]);
            return (int)$stmt->fetchColumn() >= 5;
        }

        // 2. Check composite limit (5 attempts for this IP+username)
        if ($keys['composite'] !== null) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM `login_rate_limits`
                WHERE `ip_address` = :comp_key
                  AND `attempted_at` >= (NOW() - INTERVAL 15 MINUTE)
            ");
            $stmt->execute([':comp_key' => $keys['composite']]);
            if ((int)$stmt->fetchColumn() >= 5) {
                return true;
            }
        }

        // 3. Check anonymous failures from this IP (5 attempts)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM `login_rate_limits`
            WHERE `ip_address` = :anon_key
              AND `attempted_at` >= (NOW() - INTERVAL 15 MINUTE)
        ");
        $stmt->execute([':anon_key' => $keys['anon']]);
        if ((int)$stmt->fetchColumn() >= 5) {
            return true;
        }

        // 4. Check total source IP limit (15 attempts across any username)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM `login_rate_limits`
            WHERE `ip_address` = :ip_key
              AND `attempted_at` >= (NOW() - INTERVAL 15 MINUTE)
        ");
        $stmt->execute([':ip_key' => $keys['ip']]);
        if ((int)$stmt->fetchColumn() >= 15) {
            return true;
        }

        return false;
    } catch (Exception $e) {
        error_log("AFM2 rate limit check error (failing safe to session fallback): " . $e->getMessage());
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['afm_fallback_login_attempts'])) {
            $lastAttemptTime = (int)($_SESSION['afm_fallback_login_time'] ?? 0);
            return (time() - $lastAttemptTime < 900 && (int)$_SESSION['afm_fallback_login_attempts'] >= 5);
        }
        return false;
    }
}

/**
 * Record a failed login attempt
 */
function record_failed_login(?string $username = null): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['afm_fallback_login_attempts'] = (int)($_SESSION['afm_fallback_login_attempts'] ?? 0) + 1;
        $_SESSION['afm_fallback_login_time'] = time();
    }

    try {
        $pdo = get_db_connection();
        $ip = get_client_ip();
        $keys = get_rate_limit_keys($ip, $username);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO `login_rate_limits` (`ip_address`) VALUES (:key)");

        // 1. Source IP
        $stmt->execute([':key' => $keys['ip']]);

        // 2. Composite and account keys
        if ($keys['composite'] !== null) {
            $stmt->execute([':key' => $keys['composite']]);
            $stmt->execute([':key' => $keys['account']]);
        } else {
            $stmt->execute([':key' => $keys['anon']]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("AFM2 record failed login error: " . $e->getMessage());
    }
}

/**
 * Clear rate limit records on successful authentication
 */
function clear_rate_limits(?string $username = null): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['afm_fallback_login_attempts'], $_SESSION['afm_fallback_login_time']);
    }

    try {
        $pdo = get_db_connection();

        if ($username === '*') {
            $pdo->exec("DELETE FROM `login_rate_limits`");
            return;
        }

        $ip = get_client_ip();
        $keys = get_rate_limit_keys($ip, $username);

        $toDelete = [$keys['ip'], $keys['anon']];
        if ($keys['composite'] !== null) {
            $toDelete[] = $keys['composite'];
        }
        if ($keys['account'] !== null) {
            $toDelete[] = $keys['account'];
        }

        $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
        $stmt = $pdo->prepare("DELETE FROM `login_rate_limits` WHERE `ip_address` IN ({$placeholders})");
        $stmt->execute($toDelete);
    } catch (Exception $e) {
        error_log("AFM2 clear rate limits error: " . $e->getMessage());
    }
}

/**
 * Common, provider-independent authenticated session establisher.
 * Called upon successful verification by Password, Google, or WebAuthn.
 *
 * @param int $userId Canonical user ID
 * @return array{success: bool, user_id?: int, username?: string, error?: string}
 */
function establish_authenticated_session(int $userId): array {
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT `user_id`, `username`, `role`, `is_active` FROM `app_users` WHERE `user_id` = :uid LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'error' => 'Account identity could not be resolved.'];
        }

        if ((int)$user['is_active'] !== 1) {
            return ['success' => false, 'error' => 'This account has been disabled. Please contact the administrator.'];
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Regenerate session ID to prevent session fixation attacks
        if (!headers_sent()) {
            session_regenerate_id(true);
        }

        $_SESSION['afm_user_id'] = (int)$user['user_id'];
        $_SESSION['afm_username'] = $user['username'];
        $_SESSION['afm_role'] = $user['role'] ?? 'user';

        // Update last login timestamp
        $upd = $pdo->prepare("UPDATE `app_users` SET `last_login` = NOW() WHERE `user_id` = :id");
        $upd->execute([':id' => $user['user_id']]);

        // Clear failed rate limit attempts
        clear_rate_limits($user['username']);

        return [
            'success'  => true,
            'user_id'  => (int)$user['user_id'],
            'username' => $user['username'],
        ];
    } catch (Exception $e) {
        error_log("AFM2 establish_authenticated_session error: " . $e->getMessage());
        return ['success' => false, 'error' => 'An unexpected error occurred establishing session.'];
    }
}

/**
 * Initial account setup - creates the primary administrator account.
 * Permanently disabled once any user account exists.
 */
function create_initial_account(string $username, string $password): array {
    if (has_users()) {
        return ['success' => false, 'error' => 'Account setup is locked because an account already exists.'];
    }

    $username = trim($username);
    if (strlen($username) < 3 || strlen($username) > 50) {
        return ['success' => false, 'error' => 'Username must be between 3 and 50 characters.'];
    }

    if (strlen($password) < 8) {
        return ['success' => false, 'error' => 'Password must be at least 8 characters long.'];
    }

    try {
        $pdo = get_db_connection();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO `app_users` (`username`, `password_hash`, `role`, `is_active`, `created_at`, `last_login`)
            VALUES (:username, :hash, 'admin', 1, NOW(), NOW())
        ");
        $stmt->execute([
            ':username' => $username,
            ':hash'     => $hash
        ]);

        $userId = (int)$pdo->lastInsertId();

        // Establish session
        return establish_authenticated_session($userId);
    } catch (Exception $e) {
        error_log("AFM2 create_initial_account error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to create account. Please try again.'];
    }
}

/**
 * Authenticate login attempt using username OR email and password
 */
function attempt_login(string $identifier, string $password): array {
    $trimmedIdentifier = trim($identifier);

    if (is_rate_limited($trimmedIdentifier)) {
        return [
            'success' => false, 
            'error' => 'Too many failed login attempts. Please wait 15 minutes before trying again.'
        ];
    }

    if (empty($trimmedIdentifier) || empty($password)) {
        return ['success' => false, 'error' => 'Username/email and password are required.'];
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            SELECT `user_id`, `username`, `password_hash`, `role`, `is_active` 
            FROM `app_users` 
            WHERE `username` = :uname OR (`email` IS NOT NULL AND `email` = :uemail) 
            LIMIT 1
        ");
        $stmt->execute([':uname' => $trimmedIdentifier, ':uemail' => $trimmedIdentifier]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            record_failed_login($trimmedIdentifier);
            return ['success' => false, 'error' => 'Invalid username or password.'];
        }

        if ((int)$user['is_active'] !== 1) {
            return ['success' => false, 'error' => 'This account has been disabled. Please contact the administrator.'];
        }

        // Establish session
        $sessResult = establish_authenticated_session((int)$user['user_id']);
        if (!$sessResult['success']) {
            return $sessResult;
        }

        // Automatic password rehash if algorithm cost has changed
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $rehashStmt = $pdo->prepare("UPDATE `app_users` SET `password_hash` = :hash WHERE `user_id` = :uid");
            $rehashStmt->execute([':hash' => $newHash, ':uid' => $user['user_id']]);
        }

        return [
            'success'  => true,
            'user_id'  => (int)$user['user_id'],
            'username' => $user['username']
        ];
    } catch (Exception $e) {
        error_log("AFM2 attempt_login error: " . $e->getMessage());
        return ['success' => false, 'error' => 'An unexpected error occurred during login.'];
    }
}

/**
 * Logout and invalidate session
 */
function logout(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), 
            '', 
            [
                'expires'  => time() - 42000,
                'path'     => $params["path"] ?: '/',
                'domain'   => $params["domain"] ?: '',
                'secure'   => $params["secure"],
                'httponly' => $params["httponly"],
                'samesite' => $params["samesite"] ?: 'Lax'
            ]
        );
    }

    session_destroy();
}

/**
 * Get linked authentication methods count and metadata for a user
 */
function get_user_auth_methods(int $userId): array {
    $pdo = get_db_connection();

    // 1. Password check
    $uStmt = $pdo->prepare("SELECT `password_hash`, `email`, `role`, `username`, `is_active` FROM `app_users` WHERE `user_id` = :uid");
    $uStmt->execute([':uid' => $userId]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    $hasPassword = false;
    if ($user && !empty($user['password_hash'])) {
        // Accounts created via external OAuth without password have dummy hash containing OAUTH_ONLY
        $hasPassword = !str_contains($user['password_hash'], 'OAUTH_ONLY_NO_PASSWORD');
    }

    // 2. Google identities
    $gStmt = $pdo->prepare("SELECT `identity_id`, `provider_subject`, `email`, `created_at`, `last_used_at` FROM `user_auth_identities` WHERE `user_id` = :uid AND `provider` = 'google'");
    $gStmt->execute([':uid' => $userId]);
    $googleAccounts = $gStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Passkeys
    $pStmt = $pdo->prepare("SELECT `passkey_id`, `device_name`, `created_at`, `last_used_at` FROM `user_passkeys` WHERE `user_id` = :uid ORDER BY `created_at` DESC");
    $pStmt->execute([':uid' => $userId]);
    $passkeys = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalMethods = ($hasPassword ? 1 : 0) + count($googleAccounts) + count($passkeys);

    return [
        'has_password'    => $hasPassword,
        'google_accounts' => $googleAccounts,
        'passkeys'        => $passkeys,
        'total_methods'   => $totalMethods,
        'user'            => $user,
    ];
}

/**
 * Guard: Prevent removal of the user's last usable authentication method
 */
function can_remove_auth_method(int $userId, string $methodToRemove): bool {
    $methods = get_user_auth_methods($userId);

    if ($methodToRemove === 'password') {
        // Can only remove password if at least 1 alternative method exists (Google or Passkey)
        return (count($methods['google_accounts']) + count($methods['passkeys'])) > 0;
    }

    if ($methodToRemove === 'google') {
        // Can remove 1 Google account if user has password, another Google account, or a passkey
        $remaining = ($methods['has_password'] ? 1 : 0) + (count($methods['google_accounts']) - 1) + count($methods['passkeys']);
        return $remaining > 0;
    }

    if ($methodToRemove === 'passkey') {
        // Can remove 1 passkey if user has password, Google, or another passkey
        $remaining = ($methods['has_password'] ? 1 : 0) + count($methods['google_accounts']) + (count($methods['passkeys']) - 1);
        return $remaining > 0;
    }

    return false;
}

/**
 * Change or set user password
 */
function change_user_password(int $userId, ?string $currentPassword, string $newPassword): array {
    if (strlen($newPassword) < 8) {
        return ['success' => false, 'error' => 'New password must be at least 8 characters long.'];
    }

    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT `password_hash` FROM `app_users` WHERE `user_id` = :uid");
    $stmt->execute([':uid' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return ['success' => false, 'error' => 'User not found.'];
    }

    $existingHash = $user['password_hash'];
    $hasExistingPassword = !empty($existingHash) && !str_contains($existingHash, 'OAUTH_ONLY_NO_PASSWORD');

    if ($hasExistingPassword) {
        if (empty($currentPassword) || !password_verify($currentPassword, $existingHash)) {
            return ['success' => false, 'error' => 'Current password is incorrect.'];
        }
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE `app_users` SET `password_hash` = :hash WHERE `user_id` = :uid");
    $upd->execute([':hash' => $newHash, ':uid' => $userId]);

    return ['success' => true];
}

/**
 * Administrator action: Reset another user's password directly
 */
function admin_reset_user_password(int $targetUserId, string $newPassword, int $actingAdminId): array {
    $adminRoleStmt = get_db_connection()->prepare("SELECT `role` FROM `app_users` WHERE `user_id` = :uid");
    $adminRoleStmt->execute([':uid' => $actingAdminId]);
    if ($adminRoleStmt->fetchColumn() !== 'admin') {
        return ['success' => false, 'error' => 'Administrative privileges required.'];
    }

    if (strlen($newPassword) < 8) {
        return ['success' => false, 'error' => 'Password must be at least 8 characters long.'];
    }

    $pdo = get_db_connection();
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE `app_users` SET `password_hash` = :hash WHERE `user_id` = :uid");
    $upd->execute([':hash' => $newHash, ':uid' => $targetUserId]);

    return ['success' => true];
}

/**
 * Administrator action: Enable or disable a user account
 */
function set_user_active_status(int $targetUserId, bool $isActive, int $actingAdminId): array {
    $pdo = get_db_connection();

    $adminStmt = $pdo->prepare("SELECT `role` FROM `app_users` WHERE `user_id` = :uid");
    $adminStmt->execute([':uid' => $actingAdminId]);
    if ($adminStmt->fetchColumn() !== 'admin') {
        return ['success' => false, 'error' => 'Administrative privileges required.'];
    }

    if ($targetUserId === $actingAdminId && !$isActive) {
        return ['success' => false, 'error' => 'You cannot disable your own administrator account.'];
    }

    $upd = $pdo->prepare("UPDATE `app_users` SET `is_active` = :active WHERE `user_id` = :uid");
    $upd->execute([':active' => $isActive ? 1 : 0, ':uid' => $targetUserId]);

    return ['success' => true];
}

/**
 * Administrator action: Create an invitation token for a new user
 */
function create_invitation(string $email, string $role, int $adminUserId): array {
    $cleanEmail = trim($email);
    if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address format.'];
    }

    $cleanRole = in_array($role, ['admin', 'user'], true) ? $role : 'user';

    $pdo = get_db_connection();

    // Check if user already exists with this email
    $chk = $pdo->prepare("SELECT `user_id` FROM `app_users` WHERE `email` = :email");
    $chk->execute([':email' => $cleanEmail]);
    if ($chk->fetch()) {
        return ['success' => false, 'error' => 'A user with this email address already exists.'];
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    // Invalidate prior unused invitations for this email
    $del = $pdo->prepare("DELETE FROM `user_invitations` WHERE `email` = :email AND `used_at` IS NULL");
    $del->execute([':email' => $cleanEmail]);

    // Insert new invitation valid for 7 days
    $ins = $pdo->prepare("
        INSERT INTO `user_invitations` (`email`, `role`, `token_hash`, `created_by`, `expires_at`, `created_at`)
        VALUES (:email, :role, :th, :creator, NOW() + INTERVAL 7 DAY, NOW())
    ");
    $ins->execute([
        ':email'   => $cleanEmail,
        ':role'    => $cleanRole,
        ':th'      => $tokenHash,
        ':creator' => $adminUserId,
    ]);

    $inviteUrl = get_base_url() . "/invite.php?token={$token}";

    return [
        'success'    => true,
        'token'      => $token,
        'invite_url' => $inviteUrl,
        'email'      => $cleanEmail,
        'role'       => $cleanRole,
    ];
}

/**
 * Validate an invitation token
 */
function validate_invitation_token(string $token): ?array {
    $clean = trim($token);
    if (empty($clean)) {
        return null;
    }

    $tokenHash = hash('sha256', $clean);
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT `invitation_id`, `email`, `role`, `created_by`, `expires_at`
        FROM `user_invitations`
        WHERE `token_hash` = :th AND `used_at` IS NULL AND `expires_at` > NOW()
        LIMIT 1
    ");
    $stmt->execute([':th' => $tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Accept an invitation and register an account with password
 */
function accept_invitation_with_password(string $token, string $username, string $password): array {
    $invitation = validate_invitation_token($token);
    if (!$invitation) {
        return ['success' => false, 'error' => 'Invalid or expired invitation token.'];
    }

    $cleanUsername = trim($username);
    if (strlen($cleanUsername) < 3 || strlen($cleanUsername) > 50) {
        return ['success' => false, 'error' => 'Username must be between 3 and 50 characters.'];
    }
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $cleanUsername)) {
        return ['success' => false, 'error' => 'Username may only contain letters, numbers, hyphens, and underscores.'];
    }

    if (strlen($password) < 8) {
        return ['success' => false, 'error' => 'Password must be at least 8 characters long.'];
    }

    $pdo = get_db_connection();

    // Check if username taken
    $uStmt = $pdo->prepare("SELECT COUNT(*) FROM `app_users` WHERE `username` = :u");
    $uStmt->execute([':u' => $cleanUsername]);
    if ((int)$uStmt->fetchColumn() > 0) {
        return ['success' => false, 'error' => 'That username is already taken. Please choose another.'];
    }

    // Check if email taken
    $eStmt = $pdo->prepare("SELECT COUNT(*) FROM `app_users` WHERE `email` = :e");
    $eStmt->execute([':e' => $invitation['email']]);
    if ((int)$eStmt->fetchColumn() > 0) {
        return ['success' => false, 'error' => 'An account is already registered with this email address.'];
    }

    $pdo->beginTransaction();
    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $pdo->prepare("
            INSERT INTO `app_users` (`username`, `email`, `password_hash`, `role`, `is_active`, `created_at`, `last_login`)
            VALUES (:u, :e, :hash, :role, 1, NOW(), NOW())
        ");
        $ins->execute([
            ':u'    => $cleanUsername,
            ':e'    => $invitation['email'],
            ':hash' => $hash,
            ':role' => $invitation['role'] ?? 'user',
        ]);
        $newUserId = (int)$pdo->lastInsertId();

        // Mark invitation used
        $upd = $pdo->prepare("UPDATE `user_invitations` SET `used_at` = NOW() WHERE `invitation_id` = :id");
        $upd->execute([':id' => $invitation['invitation_id']]);

        $pdo->commit();

        // Log in new user
        return establish_authenticated_session($newUserId);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("AFM2 accept_invitation_with_password error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to create user account.'];
    }
}
