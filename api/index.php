<?php
/**
 * AFM#2 - Vercel Serverless Function Dispatcher & Front Controller
 *
 * Maps incoming serverless HTTP requests to the appropriate AFM#2 public page
 * while preserving query parameters, script paths, and enforcing strict security.
 */

// Determine the requested script path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$urlPath = parse_url($requestUri, PHP_URL_PATH);
$cleanPath = trim((string)$urlPath, '/');

// Allow optional leading public/ prefix if requested directly
if (str_starts_with($cleanPath, 'public/')) {
    $cleanPath = substr($cleanPath, 7);
}

// Disallow arbitrary nested subdirectories; all valid AFM#2 endpoints are top-level
if (strpos($cleanPath, '/') !== false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    exit;
}

// Default route is public/index.php
if ($cleanPath === '' || $cleanPath === 'index.php') {
    $targetScript = 'index.php';
} else {
    $targetScript = $cleanPath;
    // Support clean extensionless URLs (e.g. /library -> library.php)
    if (!str_ends_with($targetScript, '.php')) {
        $targetScript .= '.php';
    }
}

// Strict whitelist of legitimate application entry points in public/
$allowedScripts = [
    'index.php'       => true,
    'auditions.php'   => true,
    'discovery.php'   => true,
    'duplicates.php'  => true,
    'import.php'      => true,
    'library.php'     => true,
    'login.php'       => true,
    'logout.php'      => true,
    'recordings.php'  => true,
    'results.php'     => true,
    'setup.php'       => true,
    'tournament.php'  => true,
    'api.php'         => true,
];

$publicDir = dirname(__DIR__) . '/public';

// Check for Google Search Console HTML verification files
if (preg_match('/^google[a-f0-9]+\.html$/i', $targetScript)) {
    $verifyPath = $publicDir . '/' . $targetScript;
    if (file_exists($verifyPath)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($verifyPath);
        exit;
    }
}

// Reject any unapproved script or unauthorized path
if (!isset($allowedScripts[$targetScript])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    exit;
}

$targetFile = $publicDir . '/' . $targetScript;
if (!file_exists($targetFile)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    exit;
}

// Align working directory with document root (public/)
chdir($publicDir);

// Preserve expected CGI/server variables for navbar active states and routing
$_SERVER['SCRIPT_NAME'] = '/' . $targetScript;
$_SERVER['PHP_SELF'] = '/' . $targetScript;
$_SERVER['SCRIPT_FILENAME'] = realpath($targetFile) ?: $targetFile;

// Execute the requested public page
require $targetFile;
