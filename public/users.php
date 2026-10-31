<?php
/**
 * AFM#2 - Administrator User Management & Provisioning
 * Restricted strictly to users with role = 'admin'.
 * Supports direct user creation, shareable invitation links, status toggling, and password resets.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

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
    SELECT u.`user_id`, u.`username`,
           COALESCE(NULLIF(u.`email`, ''), (SELECT i.`email` FROM `user_auth_identities` i WHERE i.`user_id` = u.`user_id` AND i.`email` IS NOT NULL AND i.`email` != '' LIMIT 1)) AS `email`,
           u.`role`, u.`is_active`, u.`created_at`, u.`last_login`,
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
    <div class="page-header flex-between align-center mb-4 pb-3" style="border-bottom: 1px solid var(--border-subtle, var(--border-color)); flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 class="page-title" style="margin: 0 0 0.25rem 0;">User Administration</h1>
            <p class="page-subtitle" style="margin: 0;">Provision, invite, and manage accounts for your private group.</p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="account.php" class="btn btn-secondary btn-sm" style="text-decoration: none;">🛡️ My Security</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger mb-4">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success mb-4">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($generatedInvite): ?>
        <div class="card p-4 mb-4" style="background: var(--primary-light, rgba(56,189,248,0.12)); border: 1.5px solid var(--primary);">
            <div style="font-weight: 700; color: var(--primary); margin-bottom: 0.5rem; font-size: 1.05rem;">🎉 Shareable Invitation Link Created</div>
            <p style="margin: 0 0 0.75rem 0; font-size: 0.88rem; color: var(--text-secondary);">
                Share this unique, one-time invitation link with <strong><?= htmlspecialchars($generatedInvite['email']) ?></strong>.
                Valid for 7 days.
            </p>
            <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                <input type="text" id="inviteUrlBox" readonly value="<?= htmlspecialchars($generatedInvite['invite_url']) ?>" class="form-control font-mono" style="flex: 1; min-width: 260px; font-size: 0.88rem;">
                <button type="button" class="btn btn-primary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('inviteUrlBox').value); alert('Invitation link copied to clipboard!');" style="padding: 0.65rem 1rem;">
                    Copy Link
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Action Bar: Provisioning Options -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem;">
        
        <!-- Invite User Box -->
        <div class="card p-4">
            <h2 class="card-title text-base font-bold mb-2">📨 Invite User (Recommended)</h2>
            <p class="text-sm text-secondary mb-3">Generate a secure, single-use enrollment link. The user chooses their own password or signs up via Google.</p>
            
            <form method="POST" action="users.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_invitation">
                
                <div class="form-group mb-3">
                    <label for="inv_email" class="form-label">Recipient Email</label>
                    <input type="email" id="inv_email" name="email" required placeholder="friend@example.com" class="form-control">
                </div>

                <div class="form-group mb-4">
                    <label for="inv_role" class="form-label">Assigned Role</label>
                    <select id="inv_role" name="role" class="form-control">
                        <option value="user" selected>User (Standard scoring &amp; journal access)</option>
                        <option value="admin">Admin (Full administrative &amp; user management access)</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary btn-sm" style="width: 100%; padding: 0.65rem; font-weight: 700;">
                    Generate Invitation Link
                </button>
            </form>
        </div>

        <!-- Direct Creation Box -->
        <div class="card p-4">
            <h2 class="card-title text-base font-bold mb-2">👤 Direct Account Creation</h2>
            <p class="text-sm text-secondary mb-3">Create an account with a pre-set temporary password.</p>
            
            <form method="POST" action="users.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_user_direct">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.85rem;">
                    <div class="form-group">
                        <label for="dir_username" class="form-label">Username</label>
                        <input type="text" id="dir_username" name="username" required placeholder="username" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="dir_email" class="form-label">Email (Optional)</label>
                        <input type="email" id="dir_email" name="email" placeholder="user@example.com" class="form-control">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label for="dir_password" class="form-label">Initial Password</label>
                        <input type="password" id="dir_password" name="password" required minlength="8" placeholder="min. 8 chars" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="dir_role" class="form-label">Role</label>
                        <select id="dir_role" name="role" class="form-control">
                            <option value="user" selected>User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; padding: 0.65rem; font-weight: 700;">
                    Create User
                </button>
            </form>
        </div>

    </div>

    <!-- Active Users Table -->
    <div class="card p-4 mb-4">
        <h2 class="card-title text-base font-bold mb-3">Active Accounts (<?= count($users) ?>)</h2>
        
        <div class="table-container">
            <table class="data-table table-sm">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Auth Methods</th>
                        <th>Last Login</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php 
                            $hasPw = !empty($u['password_hash']) && !str_contains($u['password_hash'], 'OAUTH_ONLY_NO_PASSWORD');
                            $isSelf = (int)$u['user_id'] === $adminId;
                        ?>
                        <tr>
                            <td class="font-mono text-muted text-xs">#<?= (int)$u['user_id'] ?></td>
                            <td>
                                <div class="font-bold" style="color: var(--text-primary);">
                                    <?= htmlspecialchars($u['username']) ?>
                                    <?php if ($isSelf): ?>
                                        <span class="badge badge-accent text-xs" style="margin-left: 0.25rem;">You</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($u['email'])): ?>
                                    <div class="text-xs text-muted"><?= htmlspecialchars($u['email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $u['role'] === 'admin' ? 'badge-gold' : 'badge-secondary' ?>" style="text-transform: capitalize;">
                                    <?= htmlspecialchars($u['role']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= (int)$u['is_active'] === 1 ? 'badge-success' : 'badge-danger' ?>">
                                    <?= (int)$u['is_active'] === 1 ? 'Active' : 'Disabled' ?>
                                </span>
                            </td>
                            <td class="text-xs text-secondary">
                                <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                    <?php if ($hasPw): ?><span title="Password">🔑</span><?php endif; ?>
                                    <?php if ((int)$u['google_count'] > 0): ?><span title="Google Linked">🌐 Google</span><?php endif; ?>
                                    <?php if ((int)$u['passkeys_count'] > 0): ?><span title="Passkeys"><?= (int)$u['passkeys_count'] ?> 🛡️ Passkey</span><?php endif; ?>
                                </div>
                            </td>
                            <td class="text-xs text-muted">
                                <?= htmlspecialchars(format_local_timestamp($u['last_login'] ?? null)) ?>
                            </td>
                            <td style="text-align: right;">
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
        <div class="card p-4">
            <h2 class="card-title text-base font-bold mb-3">Pending Invitations (<?= count($pendingInvitations) ?>)</h2>
            <div class="table-container">
                <table class="data-table table-sm">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Invited By</th>
                            <th>Expires At</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingInvitations as $inv): ?>
                            <tr>
                                <td style="color: var(--primary); font-weight: 500;"><?= htmlspecialchars($inv['email']) ?></td>
                                <td>
                                    <span class="badge <?= $inv['role'] === 'admin' ? 'badge-gold' : 'badge-secondary' ?>" style="text-transform: capitalize;">
                                        <?= htmlspecialchars($inv['role']) ?>
                                    </span>
                                </td>
                                <td class="text-xs text-muted"><?= htmlspecialchars($inv['creator_name']) ?></td>
                                <td class="text-xs text-muted"><?= htmlspecialchars(format_local_timestamp($inv['expires_at'] ?? null)) ?></td>
                                <td style="text-align: right;">
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
