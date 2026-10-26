<?php
/**
 * AFM#2 - Secure Logout Handler
 * Requires an authenticated POST request protected by CSRF token.
 * Plain GET requests are rejected without altering session state.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

// Method check: Only POST requests can perform logout
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>405 Method Not Allowed — AFM#2</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:sans-serif;background:var(--bg-primary,#121214);color:var(--text-primary,#e4e4e7);">
    <div style="text-align:center;padding:2rem;max-width:480px;background:var(--bg-surface,#18181b);border:1px solid var(--border-color,#27272a);border-radius:8px;">
        <h2 style="margin-top:0;">405 Method Not Allowed</h2>
        <p style="color:var(--text-secondary,#a1a1aa);margin:1rem 0;">Logout must be performed via an authenticated POST request with a valid CSRF token.</p>
        <p><a href="index.php" style="color:var(--accent,#f59e0b);text-decoration:none;font-weight:600;">Return to Dashboard</a></p>
    </div>
</body>
</html>
    <?php
    exit;
}

// Extract submitted CSRF token (check POST form field, HTTP header, or JSON body)
$submittedToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (empty($submittedToken) && !empty($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $rawJson = json_decode(file_get_contents('php://input'), true);
    if (is_array($rawJson) && !empty($rawJson['csrf_token'])) {
        $submittedToken = (string)$rawJson['csrf_token'];
    }
}

// Validate CSRF token before performing any action
if (!validate_csrf_token($submittedToken)) {
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
        <p style="color:var(--text-secondary,#a1a1aa);margin:1rem 0;">Security token (CSRF) validation failed. Your session remains active.</p>
        <p><a href="index.php" style="color:var(--accent,#f59e0b);text-decoration:none;font-weight:600;">Return to Dashboard</a></p>
    </div>
</body>
</html>
    <?php
    exit;
}

// Ensure caller is authenticated
if (!is_authenticated()) {
    header('Location: login.php');
    exit;
}

// Authenticated POST with valid CSRF: safely terminate session
logout();
header('Location: login.php?logged_out=1');
exit;
