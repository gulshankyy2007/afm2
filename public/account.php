<?php
/**
 * AFM#2 - Account Security & Multi-Method Authentication Management
 * Allows users to manage passwords, link/unlink Google, and register/revoke Passkeys.
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
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

<div class="page-container" style="max-width: 860px; margin: 2rem auto; padding: 0 1rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 1.25rem;">
        <div>
            <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0 0 0.25rem 0; color: #f8fafc;">Account Security &amp; Sign-In Methods</h1>
            <p style="margin: 0; color: #94a3b8; font-size: 0.9rem;">Manage your login credentials, Google identity, and WebAuthn Passkeys.</p>
        </div>
        <div>
            <?php if (is_admin()): ?>
                <a href="users.php" class="btn btn-secondary btn-sm" style="text-decoration: none;">⚙️ User Management</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger mb-4" style="background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; padding: 1rem; border-radius: 8px;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success mb-4" style="background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.3); color: #86efac; padding: 1rem; border-radius: 8px;">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <!-- User Profile Overview -->
    <div style="background: #141824; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem;">
        <h2 style="font-size: 1.15rem; font-weight: 600; margin: 0 0 1rem 0; color: #f1f5f9;">Identity Profile</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <div>
                <div style="font-size: 0.78rem; color: #94a3b8; text-transform: uppercase;">Username</div>
                <div style="font-size: 1.05rem; font-weight: 600; color: #f8fafc; margin-top: 0.2rem;"><?= htmlspecialchars($username ?? '') ?></div>
            </div>
            <div>
                <div style="font-size: 0.78rem; color: #94a3b8; text-transform: uppercase;">Email Address</div>
                <div style="font-size: 1.05rem; font-weight: 600; color: #38bdf8; margin-top: 0.2rem;">
                    <?= !empty($userRow['email']) ? htmlspecialchars($userRow['email']) : '<span style="color: #64748b; font-style: italic;">Not configured</span>' ?>
                </div>
            </div>
            <div>
                <div style="font-size: 0.78rem; color: #94a3b8; text-transform: uppercase;">Account Role</div>
                <div style="font-size: 1.05rem; font-weight: 600; color: #f59e0b; margin-top: 0.2rem; text-transform: capitalize;"><?= htmlspecialchars($userRole ?? 'user') ?></div>
            </div>
            <div>
                <div style="font-size: 0.78rem; color: #94a3b8; text-transform: uppercase;">Active Sign-In Methods</div>
                <div style="font-size: 1.05rem; font-weight: 600; color: #4ade80; margin-top: 0.2rem;"><?= (int)$authMethods['total_methods'] ?> configured</div>
            </div>
        </div>
    </div>

    <!-- Sign-In Methods Grid -->
    <div style="display: grid; grid-template-columns: 1fr; gap: 2rem;">

        <!-- 1. Password Management -->
        <div style="background: #141824; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 1.5rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <div style="font-size: 1.5rem;">🔑</div>
                    <div>
                        <h2 style="font-size: 1.15rem; font-weight: 600; margin: 0; color: #f1f5f9;">Password</h2>
                        <p style="margin: 0; font-size: 0.85rem; color: #94a3b8;">
                            <?= $authMethods['has_password'] ? 'Standard password authentication is active.' : 'No password set (OAuth-only account).' ?>
                        </p>
                    </div>
                </div>
                <span class="badge" style="background: <?= $authMethods['has_password'] ? 'rgba(34,197,94,0.15)' : 'rgba(234,179,8,0.15)' ?>; color: <?= $authMethods['has_password'] ? '#86efac' : '#fde047' ?>; padding: 0.35rem 0.75rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600;">
                    <?= $authMethods['has_password'] ? 'Configured' : 'Not Set' ?>
                </span>
            </div>

            <form method="POST" action="account.php" style="margin-top: 1.25rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">

                <?php if ($authMethods['has_password']): ?>
                <div class="form-group" style="margin-bottom: 1rem;">
                    <label for="current_password" class="form-label" style="display: block; font-size: 0.85rem; color: #cbd5e1; margin-bottom: 0.35rem;">Current Password</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required style="width: 100%; max-width: 400px; padding: 0.6rem 0.8rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; color: #fff;">
                </div>
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1rem; max-width: 600px;">
                    <div class="form-group">
                        <label for="new_password" class="form-label" style="display: block; font-size: 0.85rem; color: #cbd5e1; margin-bottom: 0.35rem;">New Password (min. 8 characters)</label>
                        <input type="password" id="new_password" name="new_password" class="form-control" required minlength="8" style="width: 100%; padding: 0.6rem 0.8rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; color: #fff;">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password" class="form-label" style="display: block; font-size: 0.85rem; color: #cbd5e1; margin-bottom: 0.35rem;">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="8" style="width: 100%; padding: 0.6rem 0.8rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; color: #fff;">
                    </div>
                </div>

                <button type="submit" class="btn btn-secondary btn-sm" style="padding: 0.55rem 1rem; font-weight: 600;">
                    <?= $authMethods['has_password'] ? 'Update Password' : 'Set Account Password' ?>
                </button>
            </form>
        </div>

        <!-- 2. Google Account Sign-In -->
        <div style="background: #141824; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 1.5rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <svg width="24" height="24" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                    <div>
                        <h2 style="font-size: 1.15rem; font-weight: 600; margin: 0; color: #f1f5f9;">Google Account</h2>
                        <p style="margin: 0; font-size: 0.85rem; color: #94a3b8;">Sign in quickly using your verified Google profile.</p>
                    </div>
                </div>
                <span class="badge" style="background: <?= count($authMethods['google_accounts']) > 0 ? 'rgba(34,197,94,0.15)' : 'rgba(148,163,184,0.15)' ?>; color: <?= count($authMethods['google_accounts']) > 0 ? '#86efac' : '#94a3b8' ?>; padding: 0.35rem 0.75rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600;">
                    <?= count($authMethods['google_accounts']) > 0 ? 'Linked' : 'Not Linked' ?>
                </span>
            </div>

            <?php if (!GoogleAuthService::is_enabled()): ?>
                <div style="background: rgba(255,255,255,0.02); border: 1px dashed rgba(255,255,255,0.1); border-radius: 8px; padding: 1rem; color: #94a3b8; font-size: 0.85rem;">
                    ℹ️ Google OAuth is currently not configured in this environment (requires <code>GOOGLE_CLIENT_ID</code> and <code>GOOGLE_CLIENT_SECRET</code> in <code>.env</code>). Password and Passkey sign-in remain fully functional.
                </div>
            <?php elseif (count($authMethods['google_accounts']) > 0): ?>
                <div style="margin-top: 1rem;">
                    <?php foreach ($authMethods['google_accounts'] as $gAcc): ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 0.5rem;">
                            <div>
                                <div style="font-weight: 600; color: #f8fafc; font-size: 0.95rem;"><?= htmlspecialchars($gAcc['email'] ?? 'Google User') ?></div>
                                <div style="font-size: 0.78rem; color: #94a3b8; margin-top: 0.15rem;">
                                    Linked on <?= htmlspecialchars(date('M d, Y', strtotime($gAcc['created_at']))) ?>
                                    <?php if (!empty($gAcc['last_used_at'])): ?>
                                        &bull; Last used <?= htmlspecialchars(date('M d, Y H:i', strtotime($gAcc['last_used_at']))) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <form method="POST" action="account.php" onsubmit="return confirm('Unlink this Google account from your AFM#2 profile?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="unlink_google">
                                <button type="submit" class="btn btn-danger btn-sm" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">Unlink</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="margin-top: 1rem;">
                    <a href="<?= htmlspecialchars($googleLinkUrl) ?>" class="btn" style="display: inline-flex; align-items: center; gap: 0.5rem; background: #ffffff; color: #1e293b; font-weight: 600; border-radius: 6px; padding: 0.55rem 1rem; text-decoration: none; font-size: 0.88rem;">
                        <svg width="16" height="16" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg>
                        Link Google Account
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- 3. WebAuthn Passkeys -->
        <div style="background: #141824; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 1.5rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <div style="font-size: 1.5rem;">🛡️</div>
                    <div>
                        <h2 style="font-size: 1.15rem; font-weight: 600; margin: 0; color: #f1f5f9;">Passkeys (WebAuthn / FIDO2)</h2>
                        <p style="margin: 0; font-size: 0.85rem; color: #94a3b8;">Sign in seamlessly using Touch ID, Face ID, Windows Hello, or security keys.</p>
                    </div>
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="btnRegisterPasskey" style="padding: 0.45rem 0.9rem; font-size: 0.85rem;">
                    + Register New Passkey
                </button>
            </div>

            <!-- Passkey Registration Status Display -->
            <div id="passkeyStatusMsg" style="display: none; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem;"></div>

            <?php if (count($authMethods['passkeys']) > 0): ?>
                <div style="margin-top: 1rem;">
                    <?php foreach ($authMethods['passkeys'] as $pk): ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 0.75rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <span style="font-size: 1.25rem;">📱</span>
                                <div>
                                    <div style="font-weight: 600; color: #f8fafc; font-size: 0.95rem;"><?= htmlspecialchars($pk['device_name']) ?></div>
                                    <div style="font-size: 0.78rem; color: #94a3b8; margin-top: 0.15rem;">
                                        Registered on <?= htmlspecialchars(date('M d, Y', strtotime($pk['created_at']))) ?>
                                        <?php if (!empty($pk['last_used_at'])): ?>
                                            &bull; Last authenticated <?= htmlspecialchars(date('M d, Y H:i', strtotime($pk['last_used_at']))) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <form method="POST" action="account.php" style="display: inline;" onsubmit="return confirm('Remove this passkey?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_passkey">
                                    <input type="hidden" name="passkey_id" value="<?= (int)$pk['passkey_id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">Remove</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="background: rgba(255,255,255,0.02); border: 1px dashed rgba(255,255,255,0.1); border-radius: 8px; padding: 1.25rem; color: #94a3b8; font-size: 0.88rem; text-align: center;">
                    No passkeys registered yet. Click <strong>+ Register New Passkey</strong> to enable phishing-resistant biometrics on this device.
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script src="assets/js/auth.js?v=<?= file_exists(__DIR__ . '/assets/js/auth.js') ? filemtime(__DIR__ . '/assets/js/auth.js') : '1.0' ?>"></script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
