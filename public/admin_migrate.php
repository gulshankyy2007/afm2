<?php
/**
 * AFM#2 - Secure Production Database Migration & Reconciliation Tool
 * 
 * Authentication Guard:
 * Strictly requires either:
 * 1. An authenticated administrator session (is_admin() === true), OR
 * 2. The high-entropy application secret passed via `?secret=<APP_SECRET>`.
 * 
 * Fails closed with HTTP 403 Forbidden on any unauthorized request.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
if (file_exists(dirname(__DIR__) . '/includes/auth.php')) {
    require_once dirname(__DIR__) . '/includes/auth.php';
}

// 1. Enforce Authentication / Secret Authorization
$isAuthorized = false;
$authReason = '';

// Check admin session if auth system is available
if (function_exists('is_authenticated') && is_authenticated() && function_exists('is_admin') && is_admin()) {
    $isAuthorized = true;
    $authReason = 'Authenticated Administrator Session';
}

// Check APP_SECRET token
$providedSecret = $_GET['secret'] ?? $_POST['secret'] ?? '';
if (!empty($providedSecret) && defined('APP_SECRET') && hash_equals(APP_SECRET, $providedSecret)) {
    $isAuthorized = true;
    $authReason = 'Verified Application Secret Token';
}

if (!$isAuthorized) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>403 Forbidden — AFM#2</title>
        <link rel="stylesheet" href="assets/css/style.css?v=3.6">
        <style>
            body { background: #0c0e14; color: #f1f5f9; font-family: 'Plus Jakarta Sans', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .box { background: #161a29; border: 1px solid #2d3748; border-radius: 12px; padding: 32px; max-width: 480px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
            h1 { color: #f87171; font-size: 1.5rem; margin-bottom: 12px; }
            p { color: #94a3b8; font-size: 0.95rem; line-height: 1.5; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>403 Forbidden</h1>
            <p>Access to the database migration utility is restricted. Please log in as an administrator or provide the authorized application secret key via <code>?secret=&lt;APP_SECRET&gt;</code>.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 2. Execute Migrations if requested
require_once dirname(__DIR__) . '/migrations/migration_runner.php';

$pdo = get_db_connection();
$dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
$dbVersion = (string)$pdo->query("SELECT VERSION()")->fetchColumn();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$migrationLogs = [];
$executionMessage = '';

if ($action === 'migrate') {
    try {
        $migrationLogs = run_migrations();
        $executionMessage = 'Migration execution completed successfully.';
    } catch (Exception $e) {
        $executionMessage = 'Migration error: ' . $e->getMessage();
    }
}

if ($action === 'reset_password') {
    $targetUser = trim($_POST['username'] ?? 'adminuser');
    $newPass = trim($_POST['new_password'] ?? '');
    if (empty($newPass)) {
        $newPass = 'Admin!' . bin2hex(random_bytes(6)) . '9#';
    }
    $hash = password_hash($newPass, PASSWORD_DEFAULT);

    $chk = $pdo->prepare("SELECT `user_id` FROM `app_users` WHERE `username` = :u");
    $chk->execute([':u' => $targetUser]);
    $uid = $chk->fetchColumn();

    if ($uid) {
        $up = $pdo->prepare("UPDATE `app_users` SET `password_hash` = :h, `role` = 'admin', `is_active` = 1 WHERE `user_id` = :uid");
        $up->execute([':h' => $hash, ':uid' => (int)$uid]);
        $executionMessage = "Administrator password for <strong>" . htmlspecialchars($targetUser) . "</strong> has been set to: <code>" . htmlspecialchars($newPass) . "</code> (Role: admin, Active: 1). Please log in at /login.php and save this password.";
    } else {
        $ins = $pdo->prepare("INSERT INTO `app_users` (`username`, `password_hash`, `role`, `is_active`, `created_at`) VALUES (:u, :h, 'admin', 1, NOW())");
        $ins->execute([':u' => $targetUser, ':h' => $hash]);
        $executionMessage = "New administrator account <strong>" . htmlspecialchars($targetUser) . "</strong> created with password: <code>" . htmlspecialchars($newPass) . "</code>.";
    }
}

if ($action === 'set_role') {
    $targetUser = trim($_POST['username'] ?? '');
    $newRole = trim($_POST['role'] ?? 'user');
    if (in_array($newRole, ['admin', 'user'], true) && !empty($targetUser)) {
        $up = $pdo->prepare("UPDATE `app_users` SET `role` = :r, `is_active` = 1 WHERE `username` = :u");
        $up->execute([':r' => $newRole, ':u' => $targetUser]);
        $executionMessage = "Account <strong>" . htmlspecialchars($targetUser) . "</strong> role successfully updated to <strong>" . htmlspecialchars($newRole) . "</strong>.";
    }
}

// 3. Collect System & Database Inventory for Display
$appliedMigrations = $pdo->query("SELECT * FROM `schema_migrations` ORDER BY `migration_id` ASC")->fetchAll(PDO::FETCH_ASSOC);

// Check pending migrations
$migrationDir = dirname(__DIR__) . '/migrations';
$allMigFiles = glob($migrationDir . '/*');
$pendingFiles = [];
$appliedNames = array_column($appliedMigrations, 'migration_name');
foreach ($allMigFiles as $f) {
    $b = basename($f);
    if ((pathinfo($f, PATHINFO_EXTENSION) === 'php' || pathinfo($f, PATHINFO_EXTENSION) === 'sql') && $b !== 'migration_runner.php') {
        if (!in_array($b, $appliedNames, true)) {
            $pendingFiles[] = $b;
        }
    }
}
sort($pendingFiles);

// Collect table counts
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$tableCounts = [];
foreach ($tables as $t) {
    try {
        $tableCounts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    } catch (Exception $e) {
        $tableCounts[$t] = 'Error';
    }
}

// User accounts
$users = [];
try {
    $users = $pdo->query("SELECT `user_id`, `username`, `role`, `is_active`, `created_at`, `last_login` FROM `app_users` ORDER BY `user_id` ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // If role / is_active don't exist yet before migration 008
    $users = $pdo->query("SELECT `user_id`, `username`, `created_at`, `last_login` FROM `app_users` ORDER BY `user_id` ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Gulshan ownership counts
$gulshanId = null;
foreach ($users as $u) {
    if ($u['username'] === 'gulshan') {
        $gulshanId = (int)$u['user_id'];
        break;
    }
}

$ownershipCounts = [];
if ($gulshanId) {
    $ownershipTables = ['afm_entries', 'ratings', 'song_discovery', 'user_round_progress', 'youtube_matching_jobs'];
    foreach ($ownershipTables as $ot) {
        if (in_array($ot, $tables, true)) {
            try {
                $ownershipCounts[$ot] = (int)$pdo->query("SELECT COUNT(*) FROM `{$ot}` WHERE `user_id` = {$gulshanId}")->fetchColumn();
            } catch (Exception $e) {
                $ownershipCounts[$ot] = 'N/A';
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration & Reconciliation — AFM#2</title>
    <link rel="stylesheet" href="assets/css/style.css?v=3.6">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { background: #0c0e14; color: #f1f5f9; font-family: 'Plus Jakarta Sans', sans-serif; padding: 40px 20px; line-height: 1.6; }
        .container { max-width: 960px; margin: 0 auto; }
        .card { background: #161a29; border: 1px solid #2d3748; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 8px 24px rgba(0,0,0,0.3); }
        h1, h2, h3 { color: #f8fafc; margin-top: 0; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; }
        .badge-success { background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.4); }
        .badge-warning { background: rgba(234, 179, 8, 0.2); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.4); }
        .badge-info { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 0.9rem; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #2d3748; }
        th { color: #94a3b8; font-weight: 600; background: rgba(255,255,255,0.02); }
        code { font-family: 'JetBrains Mono', monospace; background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 4px; color: #38bdf8; }
        .btn-primary { background: #3b82f6; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: background 0.2s; text-decoration: none; display: inline-block; }
        .btn-primary:hover { background: #2563eb; }
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #86efac; }
        .alert-info { background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); color: #93c5fd; }
    </style>
</head>
<body>
    <div class="container">
        <header style="margin-bottom: 32px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #2d3748; padding-bottom: 20px;">
            <div>
                <h1>AFM#2 Database Migration & Reconciliation</h1>
                <p style="color: #94a3b8; margin: 0;">Authorized via: <strong><?= htmlspecialchars($authReason) ?></strong></p>
            </div>
            <a href="index.php" class="btn-primary" style="background: #334155;">Return to App</a>
        </header>

        <?php if ($executionMessage): ?>
            <div class="alert alert-success">
                <strong>Status:</strong> <?= htmlspecialchars($executionMessage) ?>
                <?php if (!empty($migrationLogs)): ?>
                    <ul style="margin: 8px 0 0 16px; padding: 0;">
                        <?php foreach ($migrationLogs as $log): ?>
                            <li><?= htmlspecialchars($log) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Environment Context -->
        <div class="card">
            <h2>1. Environment & Target Database</h2>
            <table>
                <tr><th style="width: 250px;">Target Database</th><td><code><?= htmlspecialchars($dbName) ?></code></td></tr>
                <tr><th>Database Server</th><td><code><?= htmlspecialchars($dbVersion) ?></code></td></tr>
                <tr><th>Application Environment</th><td><span class="badge badge-info"><?= htmlspecialchars(APP_ENV) ?></span></td></tr>
                <tr><th>Pending Migrations</th><td>
                    <?php if (empty($pendingFiles)): ?>
                        <span class="badge badge-success">Schema Up to Date (0 pending)</span>
                    <?php else: ?>
                        <span class="badge badge-warning"><?= count($pendingFiles) ?> Pending Migration(s)</span>
                    <?php endif; ?>
                </td></tr>
            </table>

            <?php if (!empty($pendingFiles)): ?>
                <div style="margin-top: 20px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="migrate">
                        <?php if (!empty($providedSecret)): ?>
                            <input type="hidden" name="secret" value="<?= htmlspecialchars($providedSecret) ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn-primary" onclick="return confirm('Are you sure you want to execute pending migrations? All existing records will be strictly preserved.');">
                            Apply Pending Migrations Now
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- Migrations Applied -->
        <div class="card">
            <h2>2. Migration History (`schema_migrations`)</h2>
            <table>
                <thead>
                    <tr><th>ID</th><th>Migration Name</th><th>Applied At</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($appliedMigrations as $m): ?>
                        <tr>
                            <td><?= (int)$m['migration_id'] ?></td>
                            <td><code><?= htmlspecialchars($m['migration_name']) ?></code></td>
                            <td><?= htmlspecialchars($m['applied_at']) ?></td>
                            <td><span class="badge badge-success">Applied</span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($pendingFiles as $pf): ?>
                        <tr style="opacity: 0.7;">
                            <td>—</td>
                            <td><code><?= htmlspecialchars($pf) ?></code></td>
                            <td>Pending</td>
                            <td><span class="badge badge-warning">Pending</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Verified User Accounts -->
        <div class="card">
            <h2>3. Account State (`app_users`)</h2>
            <table>
                <thead>
                    <tr><th>User ID</th><th>Username</th><th>Role</th><th>Active</th><th>Created At</th><th>Last Login</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><strong><?= (int)$u['user_id'] ?></strong></td>
                            <td><code><?= htmlspecialchars($u['username']) ?></code> <?= $u['username'] === 'gulshan' ? '<span class="badge badge-success">Data Owner</span>' : '' ?></td>
                            <td><span class="badge badge-info"><?= htmlspecialchars($u['role'] ?? 'N/A') ?></span></td>
                            <td><?= isset($u['is_active']) ? ((int)$u['is_active'] === 1 ? 'Yes' : 'No') : 'N/A' ?></td>
                            <td><?= htmlspecialchars($u['created_at']) ?></td>
                            <td><?= htmlspecialchars($u['last_login'] ?? 'Never') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Administrator Credential & Role Management Tools -->
            <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #2d3748; display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <!-- Password Reset for adminuser -->
                <div style="background: rgba(0,0,0,0.2); padding: 16px; border-radius: 8px; border: 1px solid #1e293b;">
                    <h3 style="font-size: 1rem; margin-top: 0; color: #38bdf8;">🔑 Set / Reset Password for `adminuser`</h3>
                    <p style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 12px;">Set a new password for the administrator account. If left blank, a secure password is generated.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="username" value="adminuser">
                        <?php if (!empty($providedSecret)): ?>
                            <input type="hidden" name="secret" value="<?= htmlspecialchars($providedSecret) ?>">
                        <?php endif; ?>
                        <div style="margin-bottom: 10px;">
                            <input type="text" name="new_password" placeholder="New Password (or leave blank to auto-generate)" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: white; font-family: monospace;">
                        </div>
                        <button type="submit" class="btn-primary" style="padding: 8px 16px; font-size: 0.9rem;" onclick="return confirm('Update password for adminuser?');">
                            Update adminuser Password
                        </button>
                    </form>
                </div>

                <!-- Role Toggle for gulshan -->
                <div style="background: rgba(0,0,0,0.2); padding: 16px; border-radius: 8px; border: 1px solid #1e293b;">
                    <h3 style="font-size: 1rem; margin-top: 0; color: #4ade80;">👤 Account Role Management</h3>
                    <p style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 12px;">Configure the role for user <code>gulshan</code> (e.g. keep as <code>user</code> while <code>adminuser</code> is <code>admin</code>).</p>
                    <form method="POST" style="display: flex; gap: 10px; align-items: center; margin-top: 10px;">
                        <input type="hidden" name="action" value="set_role">
                        <input type="hidden" name="username" value="gulshan">
                        <?php if (!empty($providedSecret)): ?>
                            <input type="hidden" name="secret" value="<?= htmlspecialchars($providedSecret) ?>">
                        <?php endif; ?>
                        <select name="role" style="padding: 8px 12px; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: white;">
                            <option value="user" selected>Role: user (Standard User)</option>
                            <option value="admin">Role: admin (Administrator)</option>
                        </select>
                        <button type="submit" class="btn-primary" style="background: #10b981; padding: 8px 16px; font-size: 0.9rem;">
                            Save Role for gulshan
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Gulshan Ownership Reconciliation -->
        <?php if ($gulshanId): ?>
        <div class="card">
            <h2>4. Gulshan Data Ownership Reconciliation (User ID: <?= $gulshanId ?>)</h2>
            <table>
                <thead>
                    <tr><th>Feature / Table</th><th>Records Owned by Gulshan</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <tr><td>Audition Entries (<code>afm_entries</code>)</td><td><strong><?= $ownershipCounts['afm_entries'] ?? 'N/A' ?></strong> entries</td><td><span class="badge badge-success">Preserved</span></td></tr>
                    <tr><td>Scorecard Ratings (<code>ratings</code>)</td><td><strong><?= $ownershipCounts['ratings'] ?? 'N/A' ?></strong> completed scorecards</td><td><span class="badge badge-success">Preserved</span></td></tr>
                    <tr><td>Song Discovery Shortlists (<code>song_discovery</code>)</td><td><strong><?= $ownershipCounts['song_discovery'] ?? 'N/A' ?></strong> shortlisted/reviewed tracks</td><td><span class="badge badge-success">Preserved</span></td></tr>
                    <tr><td>Tournament Progression (<code>user_round_progress</code>)</td><td><strong><?= $ownershipCounts['user_round_progress'] ?? 'N/A' ?></strong> tournament rounds tracked</td><td><span class="badge badge-success">Active</span></td></tr>
                    <tr><td>YouTube Matching Queue (<code>youtube_matching_jobs</code>)</td><td><strong><?= $ownershipCounts['youtube_matching_jobs'] ?? 'N/A' ?></strong> jobs</td><td><span class="badge badge-success">Preserved</span></td></tr>
                    <tr><td>Base Song Catalogue (<code>songs</code>)</td><td><strong><?= $tableCounts['songs'] ?? 0 ?></strong> songs (Shared catalogue)</td><td><span class="badge badge-success">Preserved</span></td></tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Table Row Counts -->
        <div class="card">
            <h2>5. Complete Database Table Row Counts</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin-top: 12px;">
                <?php foreach ($tableCounts as $tbl => $cnt): ?>
                    <div style="background: rgba(0,0,0,0.2); padding: 10px 14px; border-radius: 6px; border: 1px solid #1e293b; display: flex; justify-content: space-between;">
                        <code><?= htmlspecialchars($tbl) ?></code>
                        <strong><?= htmlspecialchars((string)$cnt) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>
