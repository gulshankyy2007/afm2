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

/**
 * Detect whether the current request is securely transmitted over HTTPS.
 * Accounts for direct TLS connections and verified reverse proxies (e.g. InfinityFree OpenResty).
 *
 * Conservative verification rules:
 * 1. Explicit configuration override: SESSION_COOKIE_SECURE or FORCE_HTTPS env values.
 * 2. Direct TLS connection: $_SERVER['HTTPS'] === 'on', port 443, or REQUEST_SCHEME === 'https'.
 * 3. Verified reverse proxy headers (HTTP_X_FORWARDED_PROTO, HTTP_X_FORWARDED_SSL):
 *    Trusted in production environments (APP_ENV === 'production' or non-loopback host)
 *    OR when TRUST_PROXY_HEADERS is explicitly enabled.
 *    Untrusted in local loopback development (127.0.0.1 / localhost) to prevent forged
 *    headers from falsely establishing HTTPS and causing cookie lockout.
 */
function is_https_request(): bool {
    // 1. Explicit environment override
    $forceSecure = env('SESSION_COOKIE_SECURE');
    if ($forceSecure !== null) {
        return filter_var($forceSecure, FILTER_VALIDATE_BOOLEAN);
    }

    $forceHttps = env('FORCE_HTTPS');
    if ($forceHttps !== null && filter_var($forceHttps, FILTER_VALIDATE_BOOLEAN)) {
        return true;
    }

    // 2. Direct TLS connection signals (100% reliable)
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https') {
        return true;
    }

    // 3. Reverse-proxy forwarding headers
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $httpHost = $_SERVER['HTTP_HOST'] ?? '';
    $isLoopback = in_array($remoteAddr, ['127.0.0.1', '::1'], true) ||
                  stripos($httpHost, 'localhost') !== false ||
                  stripos($httpHost, '127.0.0.1') !== false;

    $trustProxyHeaders = filter_var(
        env('TRUST_PROXY_HEADERS', APP_ENV === 'production' || !$isLoopback),
        FILTER_VALIDATE_BOOLEAN
    );

    if ($trustProxyHeaders) {
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PORT']) && (int)$_SERVER['HTTP_X_FORWARDED_PORT'] === 443) {
            return true;
        }
        if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
            $visitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
            if (isset($visitor['scheme']) && strtolower($visitor['scheme']) === 'https') {
                return true;
            }
        }
    }

    return false;
}

// Configure secure session parameters before starting session
if (session_status() === PHP_SESSION_NONE) {
    session_name('AFM2_SESSID');
    session_set_cookie_params([
        'lifetime' => (int)env('SESSION_LIFETIME', 86400),
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
}

/**
 * Determine base URL dynamically
 */
function get_base_url(): string {
    $protocol = is_https_request() ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . $host;
}
