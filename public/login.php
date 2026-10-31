<?php
/**
 * AFM#2 - Secure Multi-Method Login Screen
 * Supports Username/Email + Password, Google Sign-In, and WebAuthn Passkeys.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/google_auth_service.php';
require_once dirname(__DIR__) . '/includes/webauthn_service.php';

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
$loggedOut = !empty($_GET['logged_out']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!validate_csrf_token($token)) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $identifier = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $result = attempt_login($identifier, $password);
        if ($result['success']) {
            header('Location: index.php');
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$googleAuthUrl = '';
if (GoogleAuthService::is_enabled()) {
    $googleAuthUrl = GoogleAuthService::generate_auth_url('login');
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
    <link rel="stylesheet" href="assets/css/mobile.css?v=3.6">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
</head>
<body style="background: radial-gradient(circle at top, #1a1e36 0%, #0c0e14 100%); min-height: 100vh; position: relative; overflow-x: hidden;">
    <!-- Ambient Lighting Effect -->
    <div class="ambient-glow-orb orb-1" aria-hidden="true"></div>
    <div class="ambient-glow-orb orb-2" aria-hidden="true"></div>

    <div class="auth-wrapper" style="position: relative; z-index: 10;">
        <div style="text-align: left; margin-bottom: 1rem;">
            <a href="index.php" style="color: #94a3b8; text-decoration: none; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.25rem 0.5rem; border-radius: 6px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06);">
                &larr; Return to AFM#2 Home
            </a>
        </div>

        <div class="auth-header">
            <div class="auth-logo">
                <a href="index.php" aria-label="AFM#2 — Annual Musical Finale 2026 Home">
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

        <?php if ($loggedOut): ?>
            <div class="alert alert-success" role="status" style="background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.3); color: #86efac; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem; text-align: center;">
                You have been safely signed out.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Passkey Notification Box -->
        <div id="passkeyLoginMsg" style="display: none; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem; text-align: center;"></div>

        <!-- Google & Passkey Quick-Auth Buttons -->
        <?php if ($googleAuthUrl || true): ?>
            <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.25rem;">
                <?php if ($googleAuthUrl): ?>
                    <a href="<?= htmlspecialchars($googleAuthUrl) ?>" class="btn" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.65rem; background: #ffffff; color: #1e293b; font-weight: 600; border-radius: 8px; padding: 0.7rem 1rem; text-decoration: none; border: 1px solid rgba(0,0,0,0.1); box-shadow: 0 2px 4px rgba(0,0,0,0.08); font-size: 0.9rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                        Sign in with Google
                    </a>
                <?php endif; ?>

                <button type="button" class="btn" id="btnPasskeyLogin" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.65rem; background: var(--card-glass-bg, var(--bg-card)); color: var(--text-primary); font-weight: 600; border-radius: 8px; padding: 0.7rem 1rem; border: 1px solid var(--border-color); font-size: 0.9rem; cursor: pointer;">
                    <span style="font-size: 1.1rem;">🛡️</span>
                    Sign in with a Passkey
                </button>
            </div>

            <div style="display: flex; align-items: center; margin: 1.25rem 0; color: var(--text-muted); font-size: 0.8rem;">
                <div style="flex: 1; height: 1px; background: var(--border-subtle, var(--border-color));"></div>
                <span style="padding: 0 0.75rem;">or use password</span>
                <div style="flex: 1; height: 1px; background: var(--border-subtle, var(--border-color));"></div>
            </div>
        <?php endif; ?>

        <!-- Password Login Form -->
        <form method="POST" action="login.php" class="auth-form" autocomplete="off">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="username" class="form-label">Username or Email</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    class="form-control" 
                    required 
                    placeholder="Enter your username or email"
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
            <div style="font-weight: 600; color: #cbd5e1; margin-bottom: 0.25rem;">🔒 Private Invitation-Only Music Journal</div>
            AFM#2 (Annual Musical Finale 2026) is an invitation-only personal music tournament tool. Public registration is closed. Accounts are provisioned exclusively by the administrator.
        </div>

        <div class="auth-trust-links" style="margin-top: 1.25rem; display: flex; justify-content: center; gap: 0.75rem; flex-wrap: wrap; font-size: 0.78rem; text-align: center;">
            <a href="about.php" style="color: #94a3b8; text-decoration: none;">About AFM#2</a>
            <span style="color: rgba(255,255,255,0.2);">&bull;</span>
            <a href="privacy.php" style="color: #94a3b8; text-decoration: none;">Privacy Policy</a>
            <span style="color: rgba(255,255,255,0.2);">&bull;</span>
            <a href="terms.php" style="color: #94a3b8; text-decoration: none;">Terms of Use</a>
            <span style="color: rgba(255,255,255,0.2);">&bull;</span>
            <a href="contact.php" style="color: #94a3b8; text-decoration: none;">Contact</a>
        </div>
    </div>

    <script src="assets/js/auth.js?v=<?= file_exists(__DIR__ . '/assets/js/auth.js') ? filemtime(__DIR__ . '/assets/js/auth.js') : '1.0' ?>"></script>
</body>
</html>
