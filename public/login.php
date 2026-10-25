<?php
/**
 * AFM#2 - Secure Login Screen
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

// If no users exist, direct to setup
if (!has_users()) {
    header('Location: setup.php');
    exit;
}

// If already authenticated, go to dashboard
if (is_authenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!validate_csrf_token($token)) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $result = attempt_login($username, $password);
        if ($result['success']) {
            header('Location: index.php');
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Sign In — AFM#2';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="description" content="AFM#2 — Personal Music Journal and Audition Tournament">
    <?php if (defined('GOOGLE_SITE_VERIFICATION') && GOOGLE_SITE_VERIFICATION): ?>
    <meta name="google-site-verification" content="<?= htmlspecialchars(GOOGLE_SITE_VERIFICATION, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <!-- Favicon & Touch Icons (Approved Compact AFM#2 Symbol) -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/afm2-favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/afm2-favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="48x48" href="assets/img/afm2-favicon-48x48.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/img/afm2-favicon-192x192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png">

    <link rel="stylesheet" href="assets/css/style.css?v=3.6">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
</head>
<body style="background: radial-gradient(circle at top, #1a1e36 0%, #0c0e14 100%); min-height: 100vh; position: relative; overflow-x: hidden;">
    <!-- Ambient Lighting Effect -->
    <div class="ambient-glow-orb orb-1" aria-hidden="true"></div>
    <div class="ambient-glow-orb orb-2" aria-hidden="true"></div>

    <div class="auth-wrapper" style="position: relative; z-index: 10;">
        <div class="auth-header">
            <div class="auth-logo">
                <a href="login.php" aria-label="AFM#2 — Annual Musical Finale">
                    <img src="assets/img/afm2-logo.png" 
                         alt="AFM#2 — Annual Musical Finale" 
                         class="auth-logo-img" 
                         width="180" 
                         height="98"
                         decoding="async">
                </a>
            </div>
            <h1 class="auth-title">Welcome Back</h1>
            <p class="auth-subtitle">Sign in to your AFM#2 personal music journal &amp; tournament.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="auth-form" autocomplete="off">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="username" class="form-label">Username</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    class="form-control" 
                    required 
                    placeholder="Enter your username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    class="form-control" 
                    required 
                    placeholder="Enter your password"
                >
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                Sign In
            </button>
        </form>

        <div class="auth-footer-notice" style="margin-top: 1.5rem; padding: 0.85rem 1rem; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; font-size: 0.76rem; color: #94a3b8; line-height: 1.5; text-align: center;">
            <div style="font-weight: 600; color: #cbd5e1; margin-bottom: 0.25rem;">🔒 Private Personal Music Journal</div>
            AFM#2 (Annual Musical Finale 2026) is an independent personal music tournament tool operated exclusively for its creator. This application does not ask for or store external credentials, social accounts, financial data, or banking info.
        </div>
    </div>
</body>
</html>
