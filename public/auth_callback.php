<?php
/**
 * AFM#2 - External OAuth Callback Endpoint
 * Handles authorization callbacks from Google OAuth 2.0 / OpenID Connect.
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/google_auth_service.php';

// Instruct all search crawlers not to index or cache OAuth callbacks
header('X-Robots-Tag: noindex, nofollow, noarchive', true);

$provider = trim($_GET['provider'] ?? 'google');
$code = trim($_GET['code'] ?? '');
$state = trim($_GET['state'] ?? '');
$errorParam = trim($_GET['error'] ?? '');

$pageTitle = 'Authenticating — AFM#2';
$error = '';

if ($errorParam) {
    $error = 'Sign-in cancelled or denied by identity provider: ' . htmlspecialchars($errorParam, ENT_QUOTES, 'UTF-8');
} elseif ($provider !== 'google') {
    $error = 'Unsupported identity provider.';
} elseif (empty($code) || empty($state)) {
    $error = 'Missing authorization code or state from identity provider callback.';
} else {
    // Process callback
    $result = GoogleAuthService::handle_callback($code, $state);
    if ($result['success']) {
        $action = $result['action'] ?? '';
        if ($action === 'linked' || $action === 'already_linked') {
            header('Location: account.php?linked=google');
            exit;
        }
        // Logged in successfully
        header('Location: index.php');
        exit;
    } else {
        $error = $result['error'] ?? 'Authentication failed.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="assets/img/afm2-favicon-32x32.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body style="background: radial-gradient(circle at top, #1a1e36 0%, #0c0e14 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; font-family: 'Outfit', sans-serif; color: #f1f5f9;">
    <div style="max-width: 480px; width: 100%; background: #131722; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 2rem; box-shadow: 0 16px 40px rgba(0,0,0,0.5); text-align: center;">
        <div style="font-size: 2.5rem; margin-bottom: 1rem;">⚠️</div>
        <h2 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 0.75rem; color: #ef4444;">Sign-In Unsuccessful</h2>
        <div style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.25); border-radius: 8px; padding: 1rem; color: #fca5a5; font-size: 0.9rem; line-height: 1.5; margin-bottom: 1.5rem; text-align: left;">
            <?= htmlspecialchars($error) ?>
        </div>
        <div style="display: flex; gap: 0.75rem; justify-content: center;">
            <a href="login.php" class="btn btn-primary" style="padding: 0.65rem 1.25rem; font-size: 0.9rem; text-decoration: none;">Return to Sign In</a>
            <?php if (is_authenticated()): ?>
                <a href="account.php" class="btn btn-secondary" style="padding: 0.65rem 1.25rem; font-size: 0.9rem; text-decoration: none;">Account Settings</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
