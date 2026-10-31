<?php
/**
 * AFM#2 - Local PHP Built-in Server Router
 * Simulates Apache .htaccess mod_rewrite for local testing and CDP audits
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$projectDir = dirname(__DIR__);
$publicDir = $projectDir . '/public';

// Local testing session helper (CDP & responsive test suites)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if ($uri === '/logout.php' || ($uri === '/login.php' && isset($_GET['logged_out']))) {
    setcookie('test_auth_user', '', time() - 3600, '/');
    unset($_COOKIE['test_auth_user']);
    unset($_SESSION['afm_user_id'], $_SESSION['afm_username'], $_SESSION['afm_role']);
}

if (isset($_COOKIE['test_auth_user'])) {
    if ($_COOKIE['test_auth_user'] === 'guest') {
        unset($_SESSION['afm_user_id'], $_SESSION['afm_username'], $_SESSION['afm_role']);
    } else {
        $uid = (int)$_COOKIE['test_auth_user'];
        if ($uid === 994 || $uid === 995) {
            $_SESSION['afm_user_id'] = $uid;
            $_SESSION['afm_username'] = ($uid === 994 ? 'adminuser' : 'gulshan');
            $_SESSION['afm_role'] = ($uid === 994 ? 'admin' : 'user');
        }
    }
}

// Serve robots.txt or sitemap.xml directly if requested
if ($uri === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    readfile($publicDir . '/robots.txt');
    return true;
}

if ($uri === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    readfile($publicDir . '/sitemap.xml');
    return true;
}

// Serve static assets from public/assets/
if (str_starts_with($uri, '/assets/') || $uri === '/favicon.ico' || str_starts_with($uri, '/favicon')) {
    $filePath = $publicDir . $uri;
    if (file_exists($filePath) && !is_dir($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimes = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'webp' => 'image/webp',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
        ];
        if (isset($mimes[$ext])) {
            header('Content-Type: ' . $mimes[$ext]);
        }
        readfile($filePath);
        return true;
    }
}

// Route root to public/index.php
if ($uri === '/' || $uri === '') {
    require $publicDir . '/index.php';
    return true;
}

// Route PHP endpoints in public/
$script = ltrim($uri, '/');
if (file_exists($publicDir . '/' . $script) && !is_dir($publicDir . '/' . $script)) {
    if (str_ends_with($script, '.php')) {
        require $publicDir . '/' . $script;
        return true;
    }
    return false;
}

// Default 404
http_response_code(404);
echo "404 Not Found: " . htmlspecialchars($uri);
