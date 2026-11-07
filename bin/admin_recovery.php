<?php
/**
 * AFM#2 - Administrator Account Access & Credential Recovery CLI Utility
 * 
 * Usage:
 *   php bin/admin_recovery.php --status
 *   php bin/admin_recovery.php --reset-password --username=adminuser
 *   php bin/admin_recovery.php --backfill-legacy --username=adminuser
 * 
 * Security Controls:
 *   - Strictly executable from CLI (fails closed in web environments).
 *   - Never exposes password hashes or stored secrets.
 *   - Generates high-entropy, cryptographically random temporary credentials.
 *   - Requires explicit administrator username for password reset.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

$pdo = get_db_connection();

// Parse CLI options
$options = getopt('', ['status', 'reset-password', 'backfill-legacy', 'set-role', 'username:', 'password:', 'role:', 'help']);

if (isset($options['help']) || empty($options)) {
    echo "======================================================================\n";
    echo " AFM#2 — Administrator Account Access & Recovery CLI\n";
    echo "======================================================================\n";
    echo "Commands:\n";
    echo "  --status                                Inspect accounts and readiness\n";
    echo "  --reset-password --username= [--password=] Reset password with secure credential\n";
    echo "  --set-role --username= --role=admin|user Change account role\n";
    echo "  --backfill-legacy --username=           Assign unowned legacy data to user\n";
    echo "  --help                                  Show this help message\n\n";
    exit(0);
}

// -----------------------------------------------------------------------------
// Action: --status
// -----------------------------------------------------------------------------
if (isset($options['status'])) {
    echo "======================================================================\n";
    echo " ADMINISTRATOR & ACCOUNT STATUS AUDIT\n";
    echo "======================================================================\n";

    $stmt = $pdo->query("
        SELECT 
            `user_id`, 
            `username`, 
            `email`, 
            `role`, 
            `is_active`, 
            (`password_hash` IS NOT NULL AND `password_hash` != '') AS has_password,
            `created_at`, 
            `last_login`
        FROM `app_users` 
        ORDER BY `user_id` ASC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo "No accounts found in database.\n";
        exit(0);
    }

    echo sprintf("%-8s | %-16s | %-8s | %-8s | %-12s | %-20s\n", "User ID", "Username", "Role", "Active", "Password Set", "Last Login");
    echo str_repeat("-", 80) . "\n";

    $adminCount = 0;
    foreach ($users as $u) {
        $isActive = $u['is_active'] ? "YES" : "NO (Blocked)";
        $hasPw = $u['has_password'] ? "Configured" : "Missing";
        $lastLogin = $u['last_login'] ?? "Never";
        if ($u['role'] === 'admin') {
            $adminCount++;
        }

        echo sprintf("%-8d | %-16s | %-8s | %-8s | %-12s | %-20s\n", 
            $u['user_id'], 
            $u['username'], 
            $u['role'], 
            $isActive, 
            $hasPw, 
            $lastLogin
        );
    }

    echo "\nTotal Accounts: " . count($users) . " (Administrators: {$adminCount})\n";

    // Legacy unowned catalogue check
    $unownedSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` IS NULL")->fetchColumn();
    $totalSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
    echo "Catalogue:      {$totalSongs} total songs ({$unownedSongs} currently unassigned legacy)\n\n";
    exit(0);
}

// -----------------------------------------------------------------------------
// Action: --reset-password --username=<name>
// -----------------------------------------------------------------------------
if (isset($options['reset-password'])) {
    $targetUsername = trim($options['username'] ?? '');
    if (empty($targetUsername)) {
        echo "ERROR: --username parameter is required for password reset.\n";
        echo "Example: php bin/admin_recovery.php --reset-password --username=adminuser\n";
        exit(1);
    }

    $stmt = $pdo->prepare("SELECT `user_id`, `username`, `role`, `is_active` FROM `app_users` WHERE `username` = :uname");
    $stmt->execute([':uname' => $targetUsername]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo "ERROR: Account '{$targetUsername}' was not found in database.\n";
        exit(1);
    }

    if ($user['role'] !== 'admin') {
        echo "Notice: Promoting user '{$targetUsername}' to 'admin' role for administrator access.\n";
    }

    $providedPass = trim($options['password'] ?? '');
    if (!empty($providedPass)) {
        $tempPassword = $providedPass;
    } else {
        // Generate high-entropy 20-character temporary password
        $randomHex = bin2hex(random_bytes(8));
        $tempPassword = "Tmp!" . $randomHex . "9#";
    }
    $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);

    $updStmt = $pdo->prepare("UPDATE `app_users` SET `password_hash` = :hash, `role` = 'admin', `is_active` = 1 WHERE `user_id` = :uid");
    $updStmt->execute([
        ':hash' => $hashedPassword,
        ':uid'  => (int)$user['user_id']
    ]);

    echo "======================================================================\n";
    echo " ADMINISTRATOR CREDENTIAL RECOVERY SUCCESSFUL\n";
    echo "======================================================================\n";
    echo "Account:             {$user['username']} (User ID: {$user['user_id']})\n";
    echo "Role:                admin\n";
    echo "Status:              Active (Reactivated)\n";
    echo "Password Set:        {$tempPassword}\n";
    echo "----------------------------------------------------------------------\n";
    echo "IMPORTANT NOTICE FOR PROJECT OWNER:\n";
    echo "1. Log in immediately at /login.php using this username and password.\n";
    echo "2. Go to /account.php if you wish to link Google OIDC or WebAuthn Passkeys.\n";
    echo "3. Clear your terminal scrollback or do not persist this credential in logs.\n";
    echo "======================================================================\n\n";
    exit(0);
}

// -----------------------------------------------------------------------------
// Action: --set-role --username=<name> --role=admin|user
// -----------------------------------------------------------------------------
if (isset($options['set-role'])) {
    $targetUsername = trim($options['username'] ?? '');
    $targetRole = trim($options['role'] ?? '');

    if (empty($targetUsername) || !in_array($targetRole, ['admin', 'user'], true)) {
        echo "ERROR: --username and valid --role (admin|user) are required.\n";
        echo "Example: php bin/admin_recovery.php --set-role --username=gulshan --role=user\n";
        exit(1);
    }

    $stmt = $pdo->prepare("SELECT `user_id`, `username`, `role` FROM `app_users` WHERE `username` = :uname");
    $stmt->execute([':uname' => $targetUsername]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo "ERROR: User '{$targetUsername}' not found in database.\n";
        exit(1);
    }

    $upd = $pdo->prepare("UPDATE `app_users` SET `role` = :role, `is_active` = 1 WHERE `user_id` = :uid");
    $upd->execute([':role' => $targetRole, ':uid' => (int)$user['user_id']]);

    echo "======================================================================\n";
    echo " USER ROLE UPDATED SUCCESSFULLY\n";
    echo "======================================================================\n";
    echo "User:    {$user['username']} (ID: {$user['user_id']})\n";
    echo "Role:    {$targetRole}\n";
    echo "Status:  Active (1)\n";
    echo "======================================================================\n\n";
    exit(0);
}

// -----------------------------------------------------------------------------
// Action: --backfill-legacy --username=<name>
// -----------------------------------------------------------------------------
if (isset($options['backfill-legacy'])) {
    $targetUsername = trim($options['username'] ?? '');
    if (empty($targetUsername)) {
        echo "ERROR: --username parameter is required for legacy backfill.\n";
        echo "Example: php bin/admin_recovery.php --backfill-legacy --username=adminuser\n";
        exit(1);
    }

    $stmt = $pdo->prepare("SELECT `user_id`, `username` FROM `app_users` WHERE `username` = :uname");
    $stmt->execute([':uname' => $targetUsername]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo "ERROR: User '{$targetUsername}' not found.\n";
        exit(1);
    }

    $targetUid = (int)$user['user_id'];

    echo "======================================================================\n";
    echo " BACKFILLING UNASSIGNED LEGACY CATALOGUE TO USER '{$targetUsername}' (ID: {$targetUid})\n";
    echo "======================================================================\n";

    try {
        $pdo->beginTransaction();

        // 1. Backfill songs
        $stmtSongs = $pdo->prepare("UPDATE `songs` SET `user_id` = :uid WHERE `user_id` IS NULL");
        $stmtSongs->execute([':uid' => $targetUid]);
        $songsUpdated = $stmtSongs->rowCount();

        // 2. Backfill afm_entries
        $stmtEntries = $pdo->prepare("UPDATE `afm_entries` SET `user_id` = :uid WHERE `user_id` IS NULL");
        $stmtEntries->execute([':uid' => $targetUid]);
        $entriesUpdated = $stmtEntries->rowCount();

        // 3. Backfill ratings
        $stmtRatings = $pdo->prepare("UPDATE `ratings` SET `user_id` = :uid WHERE `user_id` IS NULL");
        $stmtRatings->execute([':uid' => $targetUid]);
        $ratingsUpdated = $stmtRatings->rowCount();

        // 4. Backfill round_results
        $stmtResults = $pdo->prepare("UPDATE `round_results` SET `user_id` = :uid WHERE `user_id` IS NULL");
        $stmtResults->execute([':uid' => $targetUid]);
        $resultsUpdated = $stmtResults->rowCount();

        // 5. Backfill song_discovery
        $stmtDisc = $pdo->prepare("UPDATE `song_discovery` SET `user_id` = :uid WHERE `user_id` IS NULL");
        $stmtDisc->execute([':uid' => $targetUid]);
        $discUpdated = $stmtDisc->rowCount();

        // 6. Initialize user_round_progress if empty
        $stmtUrpCheck = $pdo->prepare("SELECT COUNT(*) FROM `user_round_progress` WHERE `user_id` = :uid");
        $stmtUrpCheck->execute([':uid' => $targetUid]);
        $urpCount = (int)$stmtUrpCheck->fetchColumn();

        if ($urpCount === 0) {
            $pdo->prepare("
                INSERT INTO `user_round_progress` (`user_id`, `round_id`, `status`, `completed_at`)
                SELECT :uid, `round_id`, `status`, `completed_at` FROM `rounds`
            ")->execute([':uid' => $targetUid]);
        }

        $pdo->commit();

        echo "  ✔ Backfilled songs:          {$songsUpdated}\n";
        echo "  ✔ Backfilled audition entries: {$entriesUpdated}\n";
        echo "  ✔ Backfilled ratings:        {$ratingsUpdated}\n";
        echo "  ✔ Backfilled round results:  {$resultsUpdated}\n";
        echo "  ✔ Backfilled discovery:      {$discUpdated}\n";
        echo "\nLegacy catalogue successfully assigned to user '{$targetUsername}'!\n";
        echo "======================================================================\n\n";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "ERROR during backfill: " . $e->getMessage() . "\n";
        exit(1);
    }
    exit(0);
}
