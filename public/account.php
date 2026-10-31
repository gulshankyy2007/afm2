<?php
/**
 * AFM#2 - Account Security & Multi-Method Authentication Management
 * Allows users to manage passwords, link/unlink Google, and register/revoke Passkeys.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/webauthn_service.php';
require_once dirname(__DIR__) . '/includes/google_auth_service.php';

require_auth();

$userId = get_current_user_id();
$username = get_current_username();
$userRole = get_current_user_role();

$error = '';
$success = '';

if (isset($_GET['linked']) && $_GET['linked'] === 'google') {
    $success = 'Google account successfully linked to your AFM#2 profile!';
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!validate_csrf_token($csrfToken)) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'change_password') {
            $currentPass = $_POST['current_password'] ?? '';
            $newPass = $_POST['new_password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';

            if ($newPass !== $confirmPass) {
                $error = 'New passwords do not match.';
            } else {
                $res = change_user_password($userId, $currentPass, $newPass);
                if ($res['success']) {
                    $success = 'Password successfully updated!';
                } else {
                    $error = $res['error'] ?? 'Failed to update password.';
                }
            }
        } elseif ($action === 'unlink_google') {
            $res = GoogleAuthService::unlink_google_identity($userId);
            if ($res['success']) {
                $success = 'Google account successfully unlinked.';
            } else {
                $error = $res['error'] ?? 'Failed to unlink Google account.';
            }
        } elseif ($action === 'delete_passkey') {
            $passkeyId = (int)($_POST['passkey_id'] ?? 0);
            $res = WebAuthnService::delete_passkey($userId, $passkeyId);
            if ($res['success']) {
                $success = 'Passkey successfully removed.';
            } else {
                $error = $res['error'] ?? 'Failed to remove passkey.';
            }
        } elseif ($action === 'rename_passkey') {
            $passkeyId = (int)($_POST['passkey_id'] ?? 0);
            $newName = trim($_POST['device_name'] ?? '');
            $res = WebAuthnService::rename_passkey($userId, $passkeyId, $newName);
            if ($res['success']) {
                $success = 'Passkey renamed successfully.';
            } else {
                $error = $res['error'] ?? 'Failed to rename passkey.';
            }
        }
    }
}

$authMethods = get_user_auth_methods($userId);
$userRow = $authMethods['user'] ?? [];
$pageTitle = 'Account Security — AFM#2';

$googleLinkUrl = '';
if (GoogleAuthService::is_enabled()) {
    $googleLinkUrl = GoogleAuthService::generate_auth_url('link');
}

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="account-container page-container">
    <div class="account-page-header">
        <div class="account-header-text">
            <h1 class="account-title">Account Security &amp; Sign-In Methods</h1>
            <p class="account-subtitle">Manage your login credentials, Google identity, and WebAuthn Passkeys.</p>
        </div>
        <div>
            <?php if (is_admin()): ?>
                <div class="account-header-actions">
                    <a href="users.php" class="btn btn-secondary btn-sm" style="text-decoration: none;">⚙️ User Management</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger mb-4 account-alert account-alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success mb-4 account-alert account-alert-success">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <!-- User Profile Overview -->
    <div class="account-card identity-profile-card">
        <h2 class="account-card-title">Identity Profile</h2>
        <div class="identity-profile-grid">
            <div class="identity-profile-item">
                <div class="identity-label">Username</div>
                <div class="identity-value identity-username"><?= htmlspecialchars($username ?? '') ?></div>
            </div>
            <div class="identity-profile-item">
                <div class="identity-label">Email Address</div>
                <div class="identity-value identity-email">
                    <?= !empty($userRow['email']) ? htmlspecialchars($userRow['email']) : '<span class="identity-not-configured">Not configured</span>' ?>
                </div>
            </div>
            <div class="identity-profile-item">
                <div class="identity-label">Account Role</div>
                <div class="identity-value identity-role"><?= htmlspecialchars($userRole ?? 'user') ?></div>
            </div>
            <div class="identity-profile-item">
                <div class="identity-label">Active Sign-In Methods</div>
                <div class="identity-value identity-methods"><?= (int)$authMethods['total_methods'] ?> configured</div>
            </div>
        </div>
    </div>

    <!-- Sign-In Methods Sections -->
    <div class="account-sections-list">

        <!-- 1. Password Management -->
        <div class="account-card password-card">
            <div class="account-card-header">
                <div class="account-card-header-left">
                    <span class="account-section-icon" aria-hidden="true">🔑</span>
                    <div class="account-section-meta">
                        <h2 class="account-section-title">Password</h2>
                        <p class="account-section-desc">
                            <?= $authMethods['has_password'] ? 'Standard password authentication is active.' : 'No password set (OAuth-only account).' ?>
                        </p>
                    </div>
                </div>
                <div class="account-card-header-right">
                    <span class="badge badge-status-pill <?= $authMethods['has_password'] ? 'badge-configured' : 'badge-not-set' ?>">
                        <?= $authMethods['has_password'] ? 'Configured' : 'Not Set' ?>
                    </span>
                </div>
            </div>

            <form method="POST" action="account.php" class="account-password-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">

                <?php if ($authMethods['has_password']): ?>
                <div class="form-group current-password-group">
                    <label for="current_password" class="form-label account-form-label">Current Password</label>
                    <input type="password" id="current_password" name="current_password" class="form-control account-form-input" required autocomplete="current-password">
                </div>
                <?php endif; ?>

                <div class="new-password-row">
                    <div class="form-group">
                        <label for="new_password" class="form-label account-form-label">New Password (min. 8 characters)</label>
                        <input type="password" id="new_password" name="new_password" class="form-control account-form-input" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password" class="form-label account-form-label">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control account-form-input" required minlength="8" autocomplete="new-password">
                    </div>
                </div>

                <div class="password-form-actions">
                    <button type="submit" class="btn btn-secondary btn-sm account-submit-btn">
                        <?= $authMethods['has_password'] ? 'Update Password' : 'Set Account Password' ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- 2. Google Account Sign-In -->
        <div class="account-card google-card">
            <div class="account-card-header">
                <div class="account-card-header-left">
                    <span class="account-section-icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                    </span>
                    <div class="account-section-meta">
                        <h2 class="account-section-title">Google Account</h2>
                        <p class="account-section-desc">Sign in quickly using your verified Google profile.</p>
                    </div>
                </div>
                <div class="account-card-header-right">
                    <span class="badge badge-status-pill <?= count($authMethods['google_accounts']) > 0 ? 'badge-linked' : 'badge-not-linked' ?>">
                        <?= count($authMethods['google_accounts']) > 0 ? 'Linked' : 'Not Linked' ?>
                    </span>
                </div>
            </div>

            <?php if (!GoogleAuthService::is_enabled()): ?>
                <div class="account-notice-box">
                    ℹ️ Google OAuth is currently not configured in this environment (requires <code>GOOGLE_CLIENT_ID</code> and <code>GOOGLE_CLIENT_SECRET</code> in <code>.env</code>). Password and Passkey sign-in remain fully functional.
                </div>
            <?php elseif (count($authMethods['google_accounts']) > 0): ?>
                <div class="auth-identities-list">
                    <?php foreach ($authMethods['google_accounts'] as $gAcc): ?>
                        <div class="auth-identity-item">
                            <div class="auth-identity-info">
                                <div class="auth-identity-primary"><?= htmlspecialchars($gAcc['email'] ?? 'Google User') ?></div>
                                <div class="auth-identity-secondary">
                                    Linked on <?= htmlspecialchars(format_local_timestamp($gAcc['created_at'] ?? null, 'M d, Y')) ?>
                                    <?php if (!empty($gAcc['last_used_at'])): ?>
                                        &bull; Last used <?= htmlspecialchars(format_local_timestamp($gAcc['last_used_at'])) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="auth-identity-action">
                                <form method="POST" action="account.php" onsubmit="return confirm('Unlink this Google account from your AFM#2 profile?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="unlink_google">
                                    <button type="submit" class="btn btn-danger btn-sm auth-action-btn">Unlink</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="auth-link-action" style="margin-top: 1rem;">
                    <a href="<?= htmlspecialchars($googleLinkUrl) ?>" class="btn btn-google-link">
                        <svg width="16" height="16" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                        <span>Link Google Account</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- 3. WebAuthn Passkeys -->
        <div class="account-card passkey-card">
            <div class="account-card-header passkey-card-header">
                <div class="account-card-header-left">
                    <span class="account-section-icon" aria-hidden="true">🛡️</span>
                    <div class="account-section-meta">
                        <h2 class="account-section-title">Passkeys (WebAuthn / FIDO2)</h2>
                        <p class="account-section-desc">Sign in seamlessly using Touch ID, Face ID, Windows Hello, or security keys.</p>
                    </div>
                </div>
                <div class="account-card-header-right passkey-action-wrap">
                    <button type="button" class="btn btn-primary btn-sm btn-register-passkey" id="btnRegisterPasskey">
                        + Register New Passkey
                    </button>
                </div>
            </div>

            <!-- Passkey Registration Status Display -->
            <div id="passkeyStatusMsg" class="passkey-status-msg" style="display: none;"></div>

            <?php if (count($authMethods['passkeys']) > 0): ?>
                <div class="auth-identities-list passkey-list">
                    <?php foreach ($authMethods['passkeys'] as $pk): ?>
                        <div class="auth-identity-item passkey-item">
                            <div class="passkey-item-left">
                                <span class="passkey-device-icon" aria-hidden="true">📱</span>
                                <div class="auth-identity-info">
                                    <div class="auth-identity-primary"><?= htmlspecialchars($pk['device_name']) ?></div>
                                    <div class="auth-identity-secondary">
                                        Registered on <?= htmlspecialchars(format_local_timestamp($pk['created_at'] ?? null, 'M d, Y')) ?>
                                        <?php if (!empty($pk['last_used_at'])): ?>
                                            &bull; Last authenticated <?= htmlspecialchars(format_local_timestamp($pk['last_used_at'])) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="auth-identity-action">
                                <form method="POST" action="account.php" style="display: inline;" onsubmit="return confirm('Remove this passkey?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_passkey">
                                    <input type="hidden" name="passkey_id" value="<?= (int)$pk['passkey_id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm auth-action-btn">Remove</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="account-empty-box">
                    No passkeys registered yet. Click <strong>+ Register New Passkey</strong> to enable phishing-resistant biometrics on this device.
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script src="assets/js/auth.js?v=<?= file_exists(__DIR__ . '/assets/js/auth.js') ? filemtime(__DIR__ . '/assets/js/auth.js') : '1.0' ?>"></script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
