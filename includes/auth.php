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
 * Client IP helper
 */
function get_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    // Validate IP format
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return '127.0.0.1';
}

/**
 * Check login rate limiting (max 5 failed attempts in 15 minutes)
 */
function is_rate_limited(): bool {
    try {
        $pdo = get_db_connection();
        $ip = get_client_ip();
        
        // Clean attempts older than 15 minutes
        $pdo->exec("DELETE FROM `login_rate_limits` WHERE `attempted_at` < (NOW() - INTERVAL 15 MINUTE)");
        
        // Count recent attempts
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM `login_rate_limits` 
            WHERE `ip_address` = :ip 
              AND `attempted_at` >= (NOW() - INTERVAL 15 MINUTE)
        ");
        $stmt->execute([':ip' => $ip]);
        $attempts = (int)$stmt->fetchColumn();
        
        return $attempts >= 5;
    } catch (Exception $e) {
        error_log("AFM2 rate limit check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Record a failed login attempt
 */
function record_failed_login(): void {
    try {
        $pdo = get_db_connection();
        $ip = get_client_ip();
        $stmt = $pdo->prepare("INSERT INTO `login_rate_limits` (`ip_address`) VALUES (:ip)");
        $stmt->execute([':ip' => $ip]);
    } catch (Exception $e) {
        error_log("AFM2 record failed login error: " . $e->getMessage());
    }
}

/**
 * Clear rate limit records on successful login
 */
function clear_rate_limits(): void {
    try {
        $pdo = get_db_connection();
        $ip = get_client_ip();
        $stmt = $pdo->prepare("DELETE FROM `login_rate_limits` WHERE `ip_address` = :ip");
        $stmt->execute([':ip' => $ip]);
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
    if (is_rate_limited()) {
        return [
            'success' => false, 
            'error' => 'Too many failed login attempts. Please wait 15 minutes before trying again.'
        ];
    }

    $username = trim($username);
    if (empty($username) || empty($password)) {
        return ['success' => false, 'error' => 'Username and password are required.'];
    }

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT `user_id`, `username`, `password_hash` FROM `app_users` WHERE `username` = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            record_failed_login();
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

        // Clear failed rate limit attempts
        clear_rate_limits();

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
