<?php
/**
 * AFM#2 - Personal Music Journal & Tournament
 * Application Configuration & Environment Loader
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

// Load .env file from project root
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            // Strip outer quotes if present
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $val;
                putenv("$key=$val");
            }
        }
    }
}

/**
 * Get environment variable with fallback
 */
function env(string $key, $default = null) {
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    $val = getenv($key);
    return $val !== false ? $val : $default;
}

// App Constants
define('APP_ENV', env('APP_ENV', 'development'));
define('APP_SECRET', env('APP_SECRET', 'afm2_default_secret_key_change_me'));
define('SESSION_DRIVER', strtolower((string)env('SESSION_DRIVER', 'native')));
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int)env('DB_PORT', 3306));
define('DB_NAME', env('DB_NAME', 'afm_db'));
define('DB_USER', env('DB_USER', 'gulshan'));
define('DB_PASS', env('DB_PASS', ''));
define('GOOGLE_SITE_VERIFICATION', env('GOOGLE_SITE_VERIFICATION', ''));

// Error handling based on environment
if (APP_ENV === 'development') {
    ini_set('display_errors', '0'); // Safe error handling: log errors, don't output stack traces directly to user
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// Configure secure session parameters before starting session
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
               (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    
    session_name('AFM2_SESSID');
    session_set_cookie_params([
        'lifetime' => (int)env('SESSION_LIFETIME', 86400),
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    // Register database session handler if opt-in driver is configured
    if (SESSION_DRIVER === 'database' || SESSION_DRIVER === 'db') {
        ini_set('session.lazy_write', '0');
        require_once dirname(__DIR__) . '/includes/session_handler.php';
        init_session_handler();
    }
}

/**
 * Determine base URL dynamically
 */
function get_base_url(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . $host;
}
