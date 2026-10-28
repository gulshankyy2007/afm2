<?php
/**
 * AFM#2 - Administrator User Management & Provisioning
 * Restricted strictly to users with role = 'admin'.
 * Supports direct user creation, shareable invitation links, status toggling, and password resets.
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

require_admin();

$adminId = get_current_user_id();
$error = '';
$success = '';
$generatedInvite = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!validate_csrf_token($csrfToken)) {
        $error = 'Security validation failed. Please refresh and try again.';
    } else {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'create_invitation') {
            $email = trim($_POST['email'] ?? '');
            $role = trim($_POST['role'] ?? 'user');
            $res = create_invitation($email, $role, $adminId);
            if ($res['success']) {
                $success = 'Invitation generated successfully!';
                $generatedInvite = $res;
            } else {
                $error = $res['error'] ?? 'Failed to generate invitation.';
            }
        } elseif ($action === 'create_user_direct') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = trim($_POST['role'] ?? 'user');

            if (empty($username) || empty($password)) {
                $error = 'Username and password are required.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters long.';
            } else {
                $pdo = get_db_connection();
                // Check username
                $uChk = $pdo->prepare("SELECT COUNT(*) FROM `app_users` WHERE `username` = :u");
                $uChk->execute([':u' => $username]);
                if ((int)$uChk->fetchColumn() > 0) {
                    $error = 'Username is already taken.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare("
                        INSERT INTO `app_users` (`username`, `email`, `password_hash`, `role`, `is_active`, `created_at`, `last_login`)
                        VALUES (:u, :e, :h, :r, 1, NOW(), NULL)
                    ");
                    $ins->execute([
                        ':u' => $username,
                        ':e' => !empty($email) ? $email : null,
                        ':h' => $hash,
                        ':r' => in_array($role, ['admin', 'user'], true) ? $role : 'user',
                    ]);
                    $success = "User account '{$username}' created successfully.";
                }
            }
        } elseif ($action === 'toggle_status') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $newActive = (int)($_POST['is_active'] ?? 1);
            $res = set_user_active_status($targetUserId, (bool)$newActive, $adminId);
            if ($res['success']) {
                $success = $newActive ? 'Account enabled successfully.' : 'Account disabled successfully.';
            } else {
                $error = $res['error'] ?? 'Failed to update account status.';
            }
        } elseif ($action === 'admin_reset_password') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $newPass = $_POST['new_password'] ?? '';
            $res = admin_reset_user_password($targetUserId, $newPass, $adminId);
            if ($res['success']) {
                $success = 'User password reset successfully.';
            } else {
                $error = $res['error'] ?? 'Failed to reset password.';
            }
        } elseif ($action === 'revoke_invitation') {
            $invId = (int)($_POST['invitation_id'] ?? 0);
            $pdo = get_db_connection();
            $del = $pdo->prepare("DELETE FROM `user_invitations` WHERE `invitation_id` = :id AND `used_at` IS NULL");
            $del->execute([':id' => $invId]);
            $success = 'Invitation revoked.';
        }
    }
}

// Fetch all users
$pdo = get_db_connection();
$usersStmt = $pdo->query("
    SELECT u.`user_id`, u.`username`, u.`email`, u.`role`, u.`is_active`, u.`created_at`, u.`last_login`,
           (SELECT COUNT(*) FROM `user_auth_identities` i WHERE i.`user_id` = u.`user_id`) AS `google_count`,
           (SELECT COUNT(*) FROM `user_passkeys` p WHERE p.`user_id` = u.`user_id`) AS `passkeys_count`,
           u.`password_hash`
    FROM `app_users` u
    ORDER BY u.`user_id` ASC
");
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch pending unexpired invitations
$invStmt = $pdo->query("
    SELECT i.`invitation_id`, i.`email`, i.`role`, i.`expires_at`, i.`created_at`, u.`username` as `creator_name`
    FROM `user_invitations` i
    JOIN `app_users` u ON u.`user_id` = i.`created_by`
    WHERE i.`used_at` IS NULL AND i.`expires_at` > NOW()
    ORDER BY i.`created_at` DESC
");
$pendingInvitations = $invStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'User Management — AFM#2 Admin';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-container" style="max-width: 1040px; margin: 2rem auto; padding: 0 1rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 1.25rem;">
        <div>
            <h1 style="font-size: 1.75rem; font-weight: 700; margin: 0 0 0.25rem 0; color: #f8fafc;">User Administration</h1>
            <p style="margin: 0; color: #94a3b8; font-size: 0.9rem;">Provision, invite, and manage accounts for your private group.</p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="account.php" class="btn btn-secondary btn-sm" style="text-decoration: none;">🛡️ My Security</a>
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

    <?php if ($generatedInvite): ?>
        <div style="background: rgba(56,189,248,0.12); border: 1px solid rgba(56,189,248,0.3); border-radius: 8px; padding: 1.25rem; margin-bottom: 2rem;">
            <div style="font-weight: 600; color: #38bdf8; margin-bottom: 0.5rem; font-size: 1rem;">🎉 Shareable Invitation Link Created</div>
            <p style="margin: 0 0 0.75rem 0; font-size: 0.88rem; color: #cbd5e1;">
                Share this unique, one-time invitation link with <strong><?= htmlspecialchars($generatedInvite['email']) ?></strong>.
                Valid for 7 days.
            </p>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <input type="text" id="inviteUrlBox" readonly value="<?= htmlspecialchars($generatedInvite['invite_url']) ?>" style="flex: 1; padding: 0.65rem 0.85rem; background: #0c0e14; border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; color: #38bdf8; font-family: monospace; font-size: 0.88rem;">
                <button type="button" class="btn btn-primary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('inviteUrlBox').value); alert('Invitation link copied to clipboard!');" style="padding: 0.65rem 1rem;">
                    Copy Link
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Action Bar: Provisioning Options -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem;">
        
        <!-- Invite User Box -->
        <div style="background: #141824; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 1.5rem;">
            <h2 style="font-size: 1.15rem; font-weight: 600; margin: 0 0 0.5rem 0; color: #f1f5f9;">📨 Invite User (Recommended)</h2>
            <p style="margin: 0 0 1rem 0; font-size: 0.85rem; color: #94a3b8;">Generate a secure, single-use enrollment link. The user chooses their own password or signs up via Google.</p>
            
            <form method="POST" action="users.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_invitation">
                
                <div class="form-group" style="margin-bottom: 0.85rem;">
                    <label for="inv_email" class="form-label" style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.25rem;">Recipient Email</label>
                    <input type="email" id="inv_email" name="email" required placeholder="friend@example.com" class="form-control" style="width: 100%; padding: 0.55rem 0.75rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; color: #fff;">
                </div>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label for="inv_role" class="form-label" style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.25rem;">Assigned Role</label>
                    <select id="inv_role" name="role" class="form-control" style="width: 100%; padding: 0.55rem 0.75rem; background: #1a1e2e; border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; color: #fff;">
                        <option value="user" selected>User (Standard scoring &amp; journal access)</option>
                        <option value="admin">Admin (Full administrative &amp; user management access)</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary btn-sm" style="width: 100%; padding: 0.6rem; font-weight: 600;">
                    Generate Invitation Link
                </button>
            </form>
        </div>

        <!-- Direct Creation Box -->
        <div style="background: #141824; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 1.5rem;">
            <h2 style="font-size: 1.15rem; font-weight: 600; margin: 0 0 0.5rem 0; color: #f1f5f9;">👤 Direct Account Creation</h2>
            <p style="margin: 0 0 1rem 0; font-size: 0.85rem; color: #94a3b8;">Create an account with a pre-set temporary password.</p>
            
            <form method="POST" action="users.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_user_direct">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.85rem;">
                    <div>
                        <label for="dir_username" class="form-label" style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.25rem;">Username</label>
                        <input type="text" id="dir_username" name="username" required placeholder="username" class="form-control" style="width: 100%; padding: 0.55rem 0.75rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; color: #fff;">
                    </div>
                    <div>
                        <label for="dir_email" class="form-label" style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.25rem;">Email (Optional)</label>
                        <input type="email" id="dir_email" name="email" placeholder="user@example.com" class="form-control" style="width: 100%; padding: 0.55rem 0.75rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; color: #fff;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                    <div>
                        <label for="dir_password" class="form-label" style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.25rem;">Initial Password</label>
                        <input type="password" id="dir_password" name="password" required minlength="8" placeholder="min. 8 chars" class="form-control" style="width: 100%; padding: 0.55rem 0.75rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; color: #fff;">
                    </div>
                    <div>
                        <label for="dir_role" class="form-label" style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.25rem;">Role</label>
                        <select id="dir_role" name="role" class="form-control" style="width: 100%; padding: 0.55rem 0.75rem; background: #1a1e2e; border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; color: #fff;">
                            <option value="user" selected>User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; padding: 0.6rem; font-weight: 600;">
                    Create User
                </button>
            </form>
        </div>

    </div>

    <!-- Active Users Table -->
    <div style="background: #141824; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem;">
        <h2 style="font-size: 1.15rem; font-weight: 600; margin: 0 0 1rem 0; color: #f1f5f9;">Active Accounts (<?= count($users) ?>)</h2>
        
        <div style="overflow-x: auto;">
            <table class="recordings-table" style="width: 100%; text-align: left; font-size: 0.88rem;">
                <thead>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); color: #94a3b8;">
                        <th style="padding: 0.75rem;">ID</th>
                        <th style="padding: 0.75rem;">User</th>
                        <th style="padding: 0.75rem;">Role</th>
                        <th style="padding: 0.75rem;">Status</th>
                        <th style="padding: 0.75rem;">Auth Methods</th>
                        <th style="padding: 0.75rem;">Last Login</th>
                        <th style="padding: 0.75rem; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php 
                            $hasPw = !empty($u['password_hash']) && !str_contains($u['password_hash'], 'OAUTH_ONLY_NO_PASSWORD');
                            $isSelf = (int)$u['user_id'] === $adminId;
                        ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                            <td style="padding: 0.75rem; color: #64748b; font-family: monospace;">#<?= (int)$u['user_id'] ?></td>
                            <td style="padding: 0.75rem;">
                                <div style="font-weight: 600; color: #f8fafc;">
                                    <?= htmlspecialchars($u['username']) ?>
                                    <?php if ($isSelf): ?>
                                        <span style="font-size: 0.72rem; background: rgba(56,189,248,0.15); color: #38bdf8; padding: 0.15rem 0.45rem; border-radius: 4px; margin-left: 0.25rem;">You</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($u['email'])): ?>
                                    <div style="font-size: 0.78rem; color: #94a3b8;"><?= htmlspecialchars($u['email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.75rem;">
                                <span style="text-transform: capitalize; font-weight: 600; color: <?= $u['role'] === 'admin' ? '#f59e0b' : '#94a3b8' ?>;">
                                    <?= htmlspecialchars($u['role']) ?>
                                </span>
                            </td>
                            <td style="padding: 0.75rem;">
                                <span style="font-size: 0.75rem; padding: 0.2rem 0.55rem; border-radius: 999px; font-weight: 600; background: <?= (int)$u['is_active'] === 1 ? 'rgba(34,197,94,0.15)' : 'rgba(239,68,68,0.15)' ?>; color: <?= (int)$u['is_active'] === 1 ? '#86efac' : '#fca5a5' ?>;">
                                    <?= (int)$u['is_active'] === 1 ? 'Active' : 'Disabled' ?>
                                </span>
                            </td>
                            <td style="padding: 0.75rem; color: #cbd5e1; font-size: 0.82rem;">
                                <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                    <?php if ($hasPw): ?><span title="Password">🔑</span><?php endif; ?>
                                    <?php if ((int)$u['google_count'] > 0): ?><span title="Google Linked">🌐 Google</span><?php endif; ?>
                                    <?php if ((int)$u['passkeys_count'] > 0): ?><span title="Passkeys"><?= (int)$u['passkeys_count'] ?> 🛡️ Passkey</span><?php endif; ?>
                                </div>
                            </td>
                            <td style="padding: 0.75rem; color: #94a3b8; font-size: 0.8rem;">
                                <?= !empty($u['last_login']) ? htmlspecialchars(date('M d, Y H:i', strtotime($u['last_login']))) : 'Never' ?>
                            </td>
                            <td style="padding: 0.75rem; text-align: right;">
                                <?php if (!$isSelf): ?>
                                    <form method="POST" action="users.php" style="display: inline;" onsubmit="return confirm('<?= (int)$u['is_active'] === 1 ? 'Disable this user account?' : 'Enable this user account?' ?>');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="target_user_id" value="<?= (int)$u['user_id'] ?>">
                                        <input type="hidden" name="is_active" value="<?= (int)$u['is_active'] === 1 ? 0 : 1 ?>">
                                        <button type="submit" class="btn btn-sm <?= (int)$u['is_active'] === 1 ? 'btn-danger' : 'btn-success' ?>" style="padding: 0.25rem 0.6rem; font-size: 0.78rem;">
                                            <?= (int)$u['is_active'] === 1 ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pending Invitations -->
    <?php if (count($pendingInvitations) > 0): ?>
        <div style="background: #141824; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 1.5rem;">
            <h2 style="font-size: 1.15rem; font-weight: 600; margin: 0 0 1rem 0; color: #f1f5f9;">Pending Invitations (<?= count($pendingInvitations) ?>)</h2>
            <div style="overflow-x: auto;">
                <table class="recordings-table" style="width: 100%; text-align: left; font-size: 0.88rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); color: #94a3b8;">
                            <th style="padding: 0.65rem;">Email</th>
                            <th style="padding: 0.65rem;">Role</th>
                            <th style="padding: 0.65rem;">Invited By</th>
                            <th style="padding: 0.65rem;">Expires At</th>
                            <th style="padding: 0.65rem; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingInvitations as $inv): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                <td style="padding: 0.65rem; color: #38bdf8; font-weight: 500;"><?= htmlspecialchars($inv['email']) ?></td>
                                <td style="padding: 0.65rem; text-transform: capitalize; color: #f59e0b; font-weight: 600;"><?= htmlspecialchars($inv['role']) ?></td>
                                <td style="padding: 0.65rem; color: #94a3b8;"><?= htmlspecialchars($inv['creator_name']) ?></td>
                                <td style="padding: 0.65rem; color: #94a3b8; font-size: 0.8rem;"><?= htmlspecialchars(date('M d, Y H:i', strtotime($inv['expires_at']))) ?></td>
                                <td style="padding: 0.65rem; text-align: right;">
                                    <form method="POST" action="users.php" style="display: inline;" onsubmit="return confirm('Revoke this invitation?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="revoke_invitation">
                                        <input type="hidden" name="invitation_id" value="<?= (int)$inv['invitation_id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" style="padding: 0.25rem 0.6rem; font-size: 0.78rem;">Revoke</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
