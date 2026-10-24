<?php
/**
 * AFM#2 - Secure Initial Account Setup
 * Strictly accessible ONLY when no accounts exist.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

// If users already exist, lock this setup screen completely
if (has_users()) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!validate_csrf_token($token)) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $result = create_initial_account($username, $password);
            if ($result['success']) {
                header('Location: index.php?setup=complete');
                exit;
            } else {
                $error = $result['error'];
            }
        }
    }
}

$pageTitle = 'Initial Setup — AFM#2';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <!-- Favicon & Touch Icons (Approved Compact AFM#2 Symbol) -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/afm2-favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/afm2-favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="48x48" href="assets/img/afm2-favicon-48x48.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/img/afm2-favicon-192x192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png">

    <link rel="stylesheet" href="assets/css/style.css?v=3.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
</head>
<body style="background: radial-gradient(circle at top, #1a1e36 0%, #0c0e14 100%); min-height: 100vh; position: relative; overflow-x: hidden;">
    <!-- Ambient Lighting Effect -->
    <div class="ambient-glow-orb orb-1" aria-hidden="true"></div>
    <div class="ambient-glow-orb orb-2" aria-hidden="true"></div>

    <div class="auth-wrapper" style="position: relative; z-index: 10;">
        <div class="auth-header">
            <div class="auth-logo">
                <a href="setup.php" aria-label="AFM#2 — Annual Musical Finale">
                    <img src="assets/img/afm2-logo.png" 
                         alt="AFM#2 — Annual Musical Finale" 
                         class="auth-logo-img" 
                         width="180" 
                         height="98">
                </a>
            </div>
            <h1 class="auth-title">Initialize Your Journal</h1>
            <p class="auth-subtitle">Create your personal administrator account for the 2026 Annual Musical Finale.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="setup.php" class="auth-form" autocomplete="off">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="username" class="form-label">Username</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    class="form-control" 
                    required 
                    minlength="3" 
                    maxlength="50" 
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
                    minlength="8" 
                    placeholder="At least 8 characters"
                >
            </div>

            <div class="form-group">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <input 
                    type="password" 
                    id="confirm_password" 
                    name="confirm_password" 
                    class="form-control" 
                    required 
                    minlength="8" 
                    placeholder="Re-enter password"
                >
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                Create Account &amp; Start
            </button>
        </form>
    </div>
</body>
</html>
