<?php
/**
 * AFM#2 - Invitation Acceptance & Onboarding
 * Allows an invited user to accept an administrator invitation
 * and establish credentials (password or Google account).
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/google_auth_service.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$invitation = validate_invitation_token($token);

$error = '';
$pageTitle = 'Accept Invitation — AFM#2';

if (!$invitation) {
    $error = 'This invitation link is invalid, expired, or has already been used. Please request a new invitation from the administrator.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!validate_csrf_token($csrfToken)) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $res = accept_invitation_with_password($token, $username, $password);
            if ($res['success']) {
                header('Location: index.php?welcome=1');
                exit;
            } else {
                $error = $res['error'] ?? 'Failed to complete registration.';
            }
        }
    }
}

$googleInviteUrl = '';
if ($invitation && GoogleAuthService::is_enabled()) {
    $googleInviteUrl = GoogleAuthService::generate_auth_url('invite', $token);
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
    <link rel="stylesheet" href="assets/css/style.css?v=3.6">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body style="background: radial-gradient(circle at top, #1a1e36 0%, #0c0e14 100%); min-height: 100vh; position: relative; overflow-x: hidden; font-family: 'Outfit', sans-serif;">
    <div class="ambient-glow-orb orb-1" aria-hidden="true"></div>
    <div class="ambient-glow-orb orb-2" aria-hidden="true"></div>

    <div class="auth-wrapper card p-4" style="position: relative; z-index: 10; max-width: 480px; margin: 3rem auto;">
        <div class="auth-header" style="text-align: center; margin-bottom: 2rem;">
            <div class="auth-logo" style="margin-bottom: 1rem;">
                <img src="assets/img/afm2-logo.png" alt="AFM#2" width="160" height="87">
            </div>
            <h1 class="auth-title" style="font-size: 1.65rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">Join AFM#2</h1>
            <p class="auth-subtitle" style="color: var(--text-secondary); font-size: 0.9rem;">Personal Music Journal &amp; Audition Tournament</p>
        </div>

        <?php if ($error && !$invitation): ?>
            <div class="alert alert-danger" style="padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; line-height: 1.5;">
                <div style="font-weight: 600; margin-bottom: 0.5rem;">Invitation Unavailable</div>
                <?= htmlspecialchars($error) ?>
            </div>
            <div style="text-align: center;">
                <a href="login.php" class="btn btn-primary" style="text-decoration: none;">Return to Sign In</a>
            </div>
        <?php else: ?>
            <div style="background: var(--card-glass-bg, rgba(255,255,255,0.03)); border: 1px solid var(--card-glass-border, var(--border-color)); border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.88rem; color: var(--text-secondary);">
                <div style="color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem;">Invited Account</div>
                <div style="font-weight: 600; color: var(--primary);"><?= htmlspecialchars($invitation['email']) ?></div>
                <div style="color: var(--text-muted); font-size: 0.8rem; margin-top: 0.25rem;">Role: <span style="text-transform: capitalize; color: var(--primary); font-weight: 600;"><?= htmlspecialchars($invitation['role']) ?></span></div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger mb-3" style="font-size: 0.85rem;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($googleInviteUrl): ?>
                <div style="margin-bottom: 1.5rem;">
                    <a href="<?= htmlspecialchars($googleInviteUrl) ?>" class="btn" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.65rem; background: #ffffff; color: #1e293b; font-weight: 600; border-radius: 8px; padding: 0.75rem 1rem; text-decoration: none; border: 1px solid rgba(0,0,0,0.1); box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                        Sign Up with Google
                    </a>
                </div>
                <div style="display: flex; align-items: center; margin: 1.25rem 0; color: var(--text-muted); font-size: 0.8rem;">
                    <div style="flex: 1; height: 1px; background: var(--border-subtle, var(--border-color));"></div>
                    <span style="padding: 0 0.75rem;">or create password</span>
                    <div style="flex: 1; height: 1px; background: var(--border-subtle, var(--border-color));"></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="invite.php" class="auth-form" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <div class="form-group mb-3">
                    <label for="username" class="form-label">Choose Username</label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        class="form-control" 
                        required 
                        placeholder="e.g. musiclover"
                        pattern="^[a-zA-Z0-9_\-]+$"
                        title="Letters, numbers, underscores, and hyphens only"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        autofocus
                    >
                </div>

                <div class="form-group mb-3">
                    <label for="password" class="form-label">Set Password (min. 8 characters)</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-control" 
                        required 
                        minlength="8"
                        placeholder="••••••••"
                    >
                </div>

                <div class="form-group mb-4">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <input 
                        type="password" 
                        id="confirm_password" 
                        name="confirm_password" 
                        class="form-control" 
                        required 
                        minlength="8"
                        placeholder="••••••••"
                    >
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.75rem; font-weight: 700; border-radius: 6px;">
                    Activate Account &amp; Sign In
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
