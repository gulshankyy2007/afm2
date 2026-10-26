<?php
/**
 * AFM#2 - Authentication & Session Security
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
 * Get current user ID (with database verification and session self-healing)
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
        $stmt = $pdo->prepare("SELECT `user_id`, `username` FROM `app_users` WHERE `user_id` = :uid LIMIT 1");
        $stmt->execute([':uid' => $rawId]);
        $row = $stmt->fetch();
        if ($row) {
            $verifiedCache[$rawId] = (int)$row['user_id'];
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
        // Cloudflare connecting IP
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
                return $cfIp;
            }
        }

        // Standard reverse proxy forwarded header (comma-separated list)
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
 * All keys fit safely within the VARCHAR(45) storage of the login_rate_limits table.
 *
 * @param string $ip Client IP
 * @param string|null $username Optional username attempted
 * @return array{ip: string, composite: ?string, account: ?string}
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
 * Check login rate limiting using multi-bucket throttling (source IP + composite IP/user + anonymous IP).
 * - Composite bucket (specific IP + specific user): max 5 attempts in 15 minutes.
 * - Anonymous bucket (unassociated failures from this IP): max 5 attempts in 15 minutes.
 * - Source IP bucket (any user from this IP): max 15 attempts in 15 minutes.
 * - Account-wide hard lockout is excluded to prevent unauthenticated Account Lockout Denial of Service (DoS).
 * - If $username is null, checks source IP with backwards-compatible threshold of 5 attempts.
 * - Fails closed to session-level defense-in-depth tracking if the database is unavailable.
 *
 * @param string|null $username Username attempted, or null for general IP check
 * @return bool True if rate limited, false otherwise
 */
function is_rate_limited(?string $username = null): bool {
    try {
        $pdo = get_db_connection();
        $ip = get_client_ip();
        $keys = get_rate_limit_keys($ip, $username);

        // Purge expired attempts older than 15 minutes
        $pdo->exec("DELETE FROM `login_rate_limits` WHERE `attempted_at` < (NOW() - INTERVAL 15 MINUTE)");

        // 1. If username is null, check source IP with threshold of 5 attempts (backwards compatibility)
        if ($username === null || trim($username) === '') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM `login_rate_limits`
                WHERE `ip_address` = :ip_key
                  AND `attempted_at` >= (NOW() - INTERVAL 15 MINUTE)
            ");
            $stmt->execute([':ip_key' => $keys['ip']]);
            return (int)$stmt->fetchColumn() >= 5;
        }

        // 2. Check composite limit (most specific: 5 attempts for this IP+username)
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

        // 3. Check anonymous failures from this IP (5 unassociated attempts)
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

        // Account-wide hard lockout is deliberately excluded here to prevent unauthenticated
        // Account Lockout Denial of Service (DoS) attacks. Legitimate users from distinct IPs
        // are not blocked by external failed attempts against their username.
        // Effective brute-force protection is enforced via per-IP composite throttling (5 attempts/IP),
        // source IP throttling (15 attempts/IP), and slow bcrypt verification (~300ms/attempt).

        return false;
    } catch (Exception $e) {
        error_log("AFM2 rate limit check error (failing safe to session fallback): " . $e->getMessage());
        // Database failure fallback: honor session-level attempt count
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['afm_fallback_login_attempts'])) {
            $lastAttemptTime = (int)($_SESSION['afm_fallback_login_time'] ?? 0);
            return (time() - $lastAttemptTime < 900 && (int)$_SESSION['afm_fallback_login_attempts'] >= 5);
        }
        return false;
    }
}

/**
 * Record a failed login attempt across source IP, composite, and account buckets.
 * Never logs plaintext passwords, session IDs, or sensitive tokens.
 *
 * @param string|null $username Username attempted, or null
 */
function record_failed_login(?string $username = null): void {
    // Session fallback defense-in-depth tracking
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

        // 1. Always record source IP
        $stmt->execute([':key' => $keys['ip']]);

        // 2. If username provided, record composite and account keys; otherwise record anonymous failure
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
        // Do not log user passwords or sensitive tokens
        error_log("AFM2 record failed login error: " . $e->getMessage());
    }
}

/**
 * Clear rate limit records on successful authentication or administrative reset.
 *
 * @param string|null $username Username whose bucket to clear, '*' for full purge, or null for source IP only
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
 * Initial account setup - creates the single administrator account.
 * Permanently disabled once an account exists.
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
            INSERT INTO `app_users` (`username`, `password_hash`, `created_at`, `last_login`)
            VALUES (:username, :hash, NOW(), NOW())
        ");
        $stmt->execute([
            ':username' => $username,
            ':hash'     => $hash
        ]);

        $userId = (int)$pdo->lastInsertId();

        // Regenerate session and log in
        if (!headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION['afm_user_id'] = $userId;
        $_SESSION['afm_username'] = $username;

        return ['success' => true, 'user_id' => $userId];
    } catch (Exception $e) {
        error_log("AFM2 create_initial_account error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to create account. Please try again.'];
    }
}

/**
 * Authenticate login attempt
 */
function attempt_login(string $username, string $password): array {
    $trimmedUsername = trim($username);

    if (is_rate_limited($trimmedUsername)) {
        return [
            'success' => false, 
            'error' => 'Too many failed login attempts. Please wait 15 minutes before trying again.'
        ];
    }

    if (empty($trimmedUsername) || empty($password)) {
        return ['success' => false, 'error' => 'Username and password are required.'];
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT `user_id`, `username`, `password_hash` FROM `app_users` WHERE `username` = :username LIMIT 1");
        $stmt->execute([':username' => $trimmedUsername]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            record_failed_login($trimmedUsername);
            return ['success' => false, 'error' => 'Invalid username or password.'];
        }

        // Login successful: regenerate session ID to prevent session fixation
        if (!headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION['afm_user_id'] = (int)$user['user_id'];
        $_SESSION['afm_username'] = $user['username'];

        // Update last login
        $upd = $pdo->prepare("UPDATE `app_users` SET `last_login` = NOW() WHERE `user_id` = :id");
        $upd->execute([':id' => $user['user_id']]);

        // Clear failed rate limit attempts across all buckets for this user & source
        clear_rate_limits($trimmedUsername);

        return ['success' => true, 'username' => $user['username']];
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
