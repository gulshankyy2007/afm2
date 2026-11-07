<?php
/**
 * AFM#2 - Personal Music Journal & Tournament
 * Application Configuration & Environment Loader
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

// Load .env file from project root (or ENV_FILE path if configured for test isolation)
$envFile = getenv('ENV_FILE') ?: (dirname(__DIR__) . '/.env');
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
            if (!array_key_exists($key, $_ENV) && getenv($key) === false) {
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
define('APP_SECRET', (string)env('APP_SECRET', 'afm2_default_secret_key_change_me'));
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int)env('DB_PORT', 3306));
define('DB_NAME', env('DB_NAME', 'afm_db'));
define('DB_USER', env('DB_USER', 'gulshan'));
define('DB_PASS', env('DB_PASS', ''));
define('GOOGLE_SITE_VERIFICATION', env('GOOGLE_SITE_VERIFICATION', ''));

// Application Timezone Standard: Store & compute in UTC, convert to Asia/Kolkata for display
date_default_timezone_set('UTC');
define('APP_DISPLAY_TIMEZONE', 'Asia/Kolkata');

// Validate APP_SECRET for production environments (fails closed on missing, empty, or default secrets)
if (APP_ENV === 'production') {
    $insecurePlaceholders = [
        '',
        'afm2_default_secret_key_change_me',
        'generate_a_random_32_char_secret_key',
        'your_secure_generated_64_character_hex_secret'
    ];
    if (in_array(APP_SECRET, $insecurePlaceholders, true) || strlen(APP_SECRET) < 32) {
        error_log('[SECURITY CONFIG ERROR] Missing or insecure APP_SECRET configured for production environment.');
        throw new RuntimeException('Application security configuration error. Please contact the administrator.');
    }
}

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
 * Detect whether the current request transport is verified HTTPS.
 * Requires actual cryptographic TLS transport evidence or explicit trusted-proxy forwarding attestation.
 *
 * Distinct security semantic:
 * Unlike is_https_request(), this helper is NEVER overridden by FORCE_HTTPS because RFC 6797 Section 7.2
 * strictly prohibits emitting Strict-Transport-Security (HSTS) over plain HTTP.
 */
function has_verified_https_transport(): bool {
    // 1. Direct TLS connection signals (authoritative server-level indicators)
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https') {
        return true;
    }

    // 2. Reverse-proxy forwarding headers (evaluated ONLY when proxy trust is explicitly enabled)
    $trustProxy = filter_var(env('TRUST_PROXY_HEADERS', false), FILTER_VALIDATE_BOOLEAN);
    if ($trustProxy) {
        // If X-Forwarded-Proto is supplied, it is the primary protocol signal
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
            return $proto === 'https';
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])) {
            return strtolower(trim($_SERVER['HTTP_X_FORWARDED_SSL'])) === 'on';
        }
        if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
            $visitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
            if (is_array($visitor) && isset($visitor['scheme'])) {
                return strtolower(trim((string)$visitor['scheme'])) === 'https';
            }
            return false;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PORT'])) {
            $port = (int)trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PORT'])[0]);
            return $port === 443;
        }
    }

    return false;
}

/**
 * Detect whether the current request should be treated as HTTPS for application logic
 * (e.g. canonical base URL generation and default session cookie flags).
 *
 * Strict trust boundary rules:
 * 1. Explicit HTTPS override: FORCE_HTTPS env value.
 * 2. Fallback to verified cryptographic transport: has_verified_https_transport().
 */
function is_https_request(): bool {
    // 1. Explicit environment enforcement override (e.g. for URL generation)
    $forceHttps = env('FORCE_HTTPS');
    if ($forceHttps !== null) {
        return filter_var($forceHttps, FILTER_VALIDATE_BOOLEAN);
    }

    // 2. Verified transport
    return has_verified_https_transport();
}

/**
 * Determine whether session cookies must be emitted with the Secure attribute.
 * Allows explicit environment override (SESSION_COOKIE_SECURE), defaulting to is_https_request().
 *
 * Security implication:
 * Setting SESSION_COOKIE_SECURE=true forces Secure cookies even if reverse-proxy headers
 * are untrusted or unverified, ensuring cookies are only transmitted over TLS on public HTTPS domains.
 * Setting SESSION_COOKIE_SECURE=false disables Secure cookies for local plain-HTTP testing.
 */
function is_session_cookie_secure(): bool {
    $forceCookie = env('SESSION_COOKIE_SECURE');
    if ($forceCookie !== null) {
        return filter_var($forceCookie, FILTER_VALIDATE_BOOLEAN);
    }
    return is_https_request();
}

// Configure secure session parameters before starting session
if (session_status() === PHP_SESSION_NONE) {
    session_name('AFM2_SESSID');
    session_set_cookie_params([
        'lifetime' => (int)env('SESSION_LIFETIME', 86400),
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_session_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
}

/**
 * Determine base URL dynamically and securely
 */
function get_base_url(): string {
    $configuredUrl = env('APP_URL');
    if (!empty($configuredUrl)) {
        return rtrim($configuredUrl, '/');
    }

    $protocol = is_https_request() ? 'https://' : 'http://';
    $rawHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    
    // Sanitize and validate Host header against header injection / SSRF (RFC 3986 host:port)
    $host = preg_match('/^[a-zA-Z0-9.\-:]+$/', $rawHost) ? $rawHost : 'localhost';
    
    return $protocol . $host;
}

/**
 * Send standard defensive HTTP security headers.
 * Safe for all AFM#2 pages, API endpoints, YouTube embeds, and InfinityFree challenge scripts.
 */
function send_security_headers(): void {
    if (headers_sent()) {
        return;
    }

    // 1. Prevent MIME-type sniffing
    header('X-Content-Type-Options: nosniff', true);

    // 2. Prevent clickjacking / framing
    header('X-Frame-Options: SAMEORIGIN', true);

    // 3. Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin', true);

    // 4. Permissions Policy - disable unneeded browser features
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()', true);

    // 5. Content Security Policy (narrowly scoped to legitimate AFM#2 requirements and hosting compatibility)
    $csp = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://www.youtube.com https://s.ytimg.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com data:; "
        . "img-src 'self' data: https://i.ytimg.com https://img.youtube.com https://yt3.ggpht.com https://images.unsplash.com https://coverartarchive.org https://archive.org https://*.archive.org; "
        . "frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com; "
        . "connect-src 'self' https://www.youtube.com; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self';";
    header("Content-Security-Policy: {$csp}", true);

    // 6. Strict-Transport-Security (HSTS) - ONLY when connection transport is verified HTTPS
    // RFC 6797 Section 7.2: An HTTP host MUST NOT send HSTS over non-secure transport.
    // FORCE_HTTPS config alone does NOT permit HSTS emission on a plain-HTTP response.
    if (has_verified_https_transport()) {
        header('Strict-Transport-Security: max-age=31536000', true);
    }
}

// Automatically emit security headers for web requests
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    send_security_headers();
}
