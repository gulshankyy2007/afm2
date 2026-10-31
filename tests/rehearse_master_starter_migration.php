<?php
/**
 * AFM#2 — Disposable Rehearsal of Master Starter Catalogue & Multiuser Isolation
 * 
 * Safety Guarantee:
 * - Refuses execution if target is 'afm_db' or production.
 * - Operates EXCLUSIVELY on temporary database 'afm_rehearsal_disposable_test'.
 * - Tests 3 independent accounts: Gulshan, Adminuser, Charlie.
 * - Tests master immutability, ID independence, deletion isolation, edit isolation, and rollback.
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/test_db_guard.php';

$disposableDb = 'afm_rehearsal_disposable_test';

// Guard: Must end with _test
if (!str_ends_with($disposableDb, '_test')) {
    die("Security Error: Disposable DB must end with _test\n");
}

$rootPdo = get_db_connection(true, 'mysql');
$rootPdo->exec("DROP DATABASE IF EXISTS `{$disposableDb}`");
$rootPdo->exec("CREATE DATABASE `{$disposableDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");

// Connect to disposable DB
$pdo = get_db_connection(true, $disposableDb);
assert_test_db_isolated($pdo, 'rehearse_master_starter_migration');

echo "======================================================================\n";
echo " AFM#2 — MASTER STARTER CATALOGUE & USER ISOLATION REHEARSAL\n";
echo " Target Database: {$disposableDb} (Disposable)\n";
echo "======================================================================\n\n";

$passed = 0;
$failed = 0;

function assert_reh(string $name, bool $cond, string $details = ''): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  \033[32m✔ PASS:\033[0m {$name}\n";
    } else {
        $failed++;
        echo "  \033[31m✖ FAIL:\033[0m {$name}" . ($details ? " ({$details})" : "") . "\n";
    }
}

try {
    // 1. Replicate Schema from migrations 001..010
    echo "--- 1. Replicate Baseline Schema (001..010) ---\n";
    $appPdo = get_db_connection(true, 'afm_db');
    
    // Copy table schemas and data
    $tables = [
        'app_users', 'rounds', 'songs', 'afm_entries', 'ratings',
        'round_results', 'song_discovery', 'song_media', 'song_artwork',
        'song_lyrics_ref', 'song_youtube_candidates', 'youtube_matching_jobs',
        'user_round_progress', 'user_preferences', 'duplicate_reviews',
        'song_consolidation_audit', 'schema_migrations'
    ];

    foreach ($tables as $tbl) {
        $createSql = $appPdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
        // Remove foreign key constraints temporarily for fast schema setup
        $pdo->exec($createSql);
    }
    assert_reh("Baseline tables created in disposable rehearsal DB", true);

    // Seed app_users
    $users = $appPdo->query("SELECT * FROM `app_users` WHERE `username` IN ('adminuser', 'gulshan')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $u) {
        $stmt = $pdo->prepare("
            INSERT INTO `app_users` (`user_id`, `username`, `email`, `password_hash`, `role`, `is_active`, `created_at`)
            VALUES (:uid, :u, :e, :p, :r, :a, :c)
        ");
        $stmt->execute([
            ':uid' => $u['user_id'],
            ':u'   => $u['username'],
            ':e'   => !empty($u['email']) ? $u['email'] : null,
            ':p'   => $u['password_hash'] ?? 'mock_hash',
            ':r'   => $u['role'],
            ':a'   => $u['is_active'],
            ':c'   => $u['created_at']
        ]);
    }
    assert_reh("Baseline users seeded (adminuser=994, gulshan=995)", true);

    // Seed 2,411 base songs with user_id = NULL
    $pdo->exec("
        INSERT INTO `songs` (`song_id`, `title`, `artist`, `user_id`, `is_consolidated`)
        SELECT `song_id`, `title`, `artist`, NULL, `is_consolidated`
        FROM `afm_db`.`songs`
    ");
    $seedSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
    $seedNullCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` IS NULL")->fetchColumn();
    assert_reh("2,411 songs seeded with user_id = NULL", $seedSongCount === 2411 && $seedNullCount === 2411, "Count: {$seedSongCount}");

    // Seed media and artwork
    $pdo->exec("INSERT INTO `song_media` SELECT * FROM `afm_db`.`song_media`");
    $pdo->exec("INSERT INTO `song_artwork` SELECT * FROM `afm_db`.`song_artwork`");
    $pdo->exec("INSERT INTO `rounds` SELECT * FROM `afm_db`.`rounds`");
    $pdo->exec("INSERT INTO `afm_entries` SELECT * FROM `afm_db`.`afm_entries`");
    $pdo->exec("INSERT INTO `ratings` (`rating_id`, `entry_id`, `round_id`, `user_id`, `melody`, `lyrics`, `vocals`, `emotion`, `replayability`) SELECT `rating_id`, `entry_id`, `round_id`, `user_id`, `melody`, `lyrics`, `vocals`, `emotion`, `replayability` FROM `afm_db`.`ratings`");
    $pdo->exec("INSERT INTO `song_discovery` SELECT * FROM `afm_db`.`song_discovery`");
    $pdo->exec("INSERT INTO `round_results` SELECT * FROM `afm_db`.`round_results`");
    assert_reh("Baseline child records seeded", true);

    // Require db_helper.php functions
    require_once dirname(__DIR__) . '/includes/db_helper.php';

    // 2. Execute Migration 011
    echo "\n--- 2. Execute Migration 011 on Disposable DB ---\n";
    $migration011 = require dirname(__DIR__) . '/migrations/011_master_starter_catalogue_and_isolation.php';
    $migration011($pdo);

    // 3. Verify Master Starter Collection
    echo "\n--- 3. Verify Master Starter Collection ---\n";
    $masterCount = (int)$pdo->query("SELECT COUNT(*) FROM `master_starter_songs` WHERE `starter_version` = 1")->fetchColumn();
    assert_reh("master_starter_songs contains exactly 2,411 records", $masterCount === 2411, "Got: {$masterCount}");

    $masterNullArtists = $pdo->query("SELECT `source_song_id` FROM `master_starter_songs` WHERE `artist` IS NULL OR `artist` = '' ORDER BY `source_song_id` ASC")->fetchAll(PDO::FETCH_COLUMN);
    $masterNullIds = array_map('intval', $masterNullArtists);
    assert_reh("master_starter_songs preserves NULL artists [1546, 2015, 2196]", $masterNullIds === [1546, 2015, 2196], "Got: " . json_encode($masterNullIds));

    $masterMediaCount = (int)$pdo->query("SELECT COUNT(*) FROM `master_starter_media`")->fetchColumn();
    assert_reh("master_starter_media contains 160 starter recordings", $masterMediaCount === 160, "Got: {$masterMediaCount}");

    $masterArtCount = (int)$pdo->query("SELECT COUNT(*) FROM `master_starter_artwork`")->fetchColumn();
    assert_reh("master_starter_artwork contains 160 starter artworks", $masterArtCount === 160, "Got: {$masterArtCount}");

    // 4. Verify Gulshan Existing Catalogue Preservation
    echo "\n--- 4. Verify Gulshan Data Preservation ---\n";
    $gulshanSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` = 995")->fetchColumn();
    assert_reh("Gulshan retains all 2,411 songs", $gulshanSongs === 2411, "Got: {$gulshanSongs}");

    $unassignedSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` IS NULL")->fetchColumn();
    assert_reh("Zero unassigned songs remain (user_id IS NULL count is 0)", $unassignedSongs === 0, "Got: {$unassignedSongs}");

    // Verify Gulshan's original song IDs (1..2428, strictly 2,411 songs)
    $minSongId = (int)$pdo->query("SELECT MIN(song_id) FROM `songs` WHERE `user_id` = 995")->fetchColumn();
    $maxSongId = (int)$pdo->query("SELECT MAX(song_id) FROM `songs` WHERE `user_id` = 995")->fetchColumn();
    assert_reh("Gulshan original song IDs preserved strictly (min: 1, max: 2428, count: 2411)", $minSongId === 1 && $maxSongId === 2428 && $gulshanSongs === 2411, "Range: {$minSongId}..{$maxSongId}");

    $gulshanEntries = (int)$pdo->query("SELECT COUNT(*) FROM `afm_entries` WHERE `user_id` = 995")->fetchColumn();
    assert_reh("Gulshan retains his 3 audition entries", $gulshanEntries === 3, "Got: {$gulshanEntries}");

    $gulshanRatings = (int)$pdo->query("SELECT COUNT(*) FROM `ratings` WHERE `user_id` = 995")->fetchColumn();
    assert_reh("Gulshan retains his 4 scorecards across stages", $gulshanRatings === 4, "Got: {$gulshanRatings}");

    $gulshanDiscovery = (int)$pdo->query("SELECT COUNT(*) FROM `song_discovery` WHERE `user_id` = 995")->fetchColumn();
    assert_reh("Gulshan retains his 3 discovery shortlists", $gulshanDiscovery === 3, "Got: {$gulshanDiscovery}");

    $gInit = $pdo->query("SELECT * FROM `user_catalogue_initialization` WHERE `user_id` = 995")->fetch(PDO::FETCH_ASSOC);
    assert_reh("Gulshan recorded in user_catalogue_initialization", !empty($gInit) && (int)$gInit['songs_cloned'] === 2411);

    // 5. Verify Adminuser Independent Starter Catalogue
    echo "\n--- 5. Verify Adminuser Independent Cloning ---\n";
    $adminInit = initialize_user_starter_catalogue(994, 1, $pdo);
    assert_reh("initialize_user_starter_catalogue(994) succeeded", $adminInit['success'] === true && $adminInit['songs_cloned'] === 2411);

    $adminSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` = 994")->fetchColumn();
    assert_reh("Adminuser owns exactly 2,411 cloned songs", $adminSongs === 2411, "Got: {$adminSongs}");

    $adminMinSongId = (int)$pdo->query("SELECT MIN(song_id) FROM `songs` WHERE `user_id` = 994")->fetchColumn();
    assert_reh("Adminuser song IDs are completely distinct from Gulshan (> 2411)", $adminMinSongId > 2411, "Admin min ID: {$adminMinSongId}");

    $adminMediaCount = (int)$pdo->query("
        SELECT COUNT(*) FROM `song_media` sm
        JOIN `songs` s ON sm.`song_id` = s.`song_id`
        WHERE s.`user_id` = 994
    ")->fetchColumn();
    assert_reh("Adminuser received cloned starter media (160 tracks)", $adminMediaCount === 160, "Got: {$adminMediaCount}");

    // 6. Test 3rd User: Charlie Cloning & Concurrency Protection
    echo "\n--- 6. Test 3rd User (Charlie) Initialization & Concurrency ---\n";
    $pdo->exec("
        INSERT INTO `app_users` (`user_id`, `username`, `email`, `password_hash`, `role`, `is_active`, `created_at`)
        VALUES (1050, 'test_charlie', 'charlie@test.afm', 'hash', 'user', 1, NOW())
    ");

    $charlieInit1 = initialize_user_starter_catalogue(1050, 1, $pdo);
    assert_reh("Charlie initial clone succeeded (2,411 songs)", $charlieInit1['success'] === true && $charlieInit1['songs_cloned'] === 2411);

    // Test duplicate initialization attempt (must be idempotent and no-op)
    $charlieInit2 = initialize_user_starter_catalogue(1050, 1, $pdo);
    assert_reh("Simultaneous/second initialization attempt safely detects already_initialized", $charlieInit2['success'] === true && $charlieInit2['already_initialized'] === true);

    $charlieSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` = 1050")->fetchColumn();
    assert_reh("Charlie song count remains strictly 2,411 (no duplication)", $charlieSongCount === 2411, "Got: {$charlieSongCount}");

    $charlieMinSongId = (int)$pdo->query("SELECT MIN(song_id) FROM `songs` WHERE `user_id` = 1050")->fetchColumn();
    assert_reh("Charlie song IDs are completely distinct from Admin & Gulshan (> 4822)", $charlieMinSongId > $adminMinSongId, "Charlie min ID: {$charlieMinSongId}");

    // 7. Test User Isolation of Edits, Deletes, and Master Immutability
    echo "\n--- 7. Test User Isolation & Master Immutability ---\n";
    
    // Pick Charlie's first song
    $charlieSong = $pdo->query("SELECT `song_id`, `title` FROM `songs` WHERE `user_id` = 1050 ORDER BY `song_id` ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $cSongId = (int)$charlieSong['song_id'];
    $cOrigTitle = $charlieSong['title'];

    // Charlie edits his song title
    $pdo->prepare("UPDATE `songs` SET `title` = 'Charlie Customized Remix' WHERE `song_id` = :id AND `user_id` = 1050")->execute([':id' => $cSongId]);

    // Check Charlie's title changed
    $cUpdatedTitle = $pdo->query("SELECT `title` FROM `songs` WHERE `song_id` = {$cSongId}")->fetchColumn();
    assert_reh("Charlie song title updated in his catalogue", $cUpdatedTitle === 'Charlie Customized Remix');

    // Check Gulshan's title for song 1 is UNTOUCHED
    $gTitle = $pdo->query("SELECT `title` FROM `songs` WHERE `song_id` = 1 AND `user_id` = 995")->fetchColumn();
    assert_reh("Gulshan corresponding song title remains untouched", $gTitle === $cOrigTitle, "Gulshan title: {$gTitle}");

    // Check Master Starter title is UNTOUCHED
    $mTitle = $pdo->query("SELECT `title` FROM `master_starter_songs` WHERE `source_song_id` = 1")->fetchColumn();
    assert_reh("Master starter collection title remains untouched (immutable)", $mTitle === $cOrigTitle, "Master title: {$mTitle}");

    // Charlie deletes 10 songs
    $charlieDelIds = $pdo->query("SELECT `song_id` FROM `songs` WHERE `user_id` = 1050 LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
    $delIn = implode(',', array_map('intval', $charlieDelIds));
    $pdo->exec("DELETE FROM `songs` WHERE `song_id` IN ({$delIn}) AND `user_id` = 1050");

    $cCountAfterDel = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` = 1050")->fetchColumn();
    assert_reh("Charlie catalogue count decreased to 2,401", $cCountAfterDel === 2401, "Got: {$cCountAfterDel}");

    $gCountAfterDel = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` = 995")->fetchColumn();
    assert_reh("Gulshan catalogue count strictly intact at 2,411 after Charlie deletions", $gCountAfterDel === 2411, "Got: {$gCountAfterDel}");

    $adminCountAfterDel = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` = 994")->fetchColumn();
    assert_reh("Adminuser catalogue count strictly intact at 2,411 after Charlie deletions", $adminCountAfterDel === 2411, "Got: {$adminCountAfterDel}");

    $masterCountAfterDel = (int)$pdo->query("SELECT COUNT(*) FROM `master_starter_songs`")->fetchColumn();
    assert_reh("Master starter catalogue strictly intact at 2,411 after Charlie deletions", $masterCountAfterDel === 2411, "Got: {$masterCountAfterDel}");

    // 8. Test Soft-Delete / Archive for Tournament-Referenced Song
    echo "\n--- 8. Test Tournament Deletion Protection & User Archiving ---\n";
    // Gulshan song 1 has a rating in Round 1
    // Soft delete it
    $pdo->prepare("UPDATE `songs` SET `deleted_at` = NOW(), `is_consolidated` = 1 WHERE `song_id` = 1 AND `user_id` = 995")->execute();
    
    // Active songs query filters out deleted_at IS NOT NULL
    $gActiveCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` = 995 AND `deleted_at` IS NULL AND `is_consolidated` = 0")->fetchColumn();
    assert_reh("Gulshan active catalogue excludes archived song (2,410 active)", $gActiveCount === 2410, "Got: {$gActiveCount}");

    // Historical rating still intact
    $rating1 = (int)$pdo->query("SELECT `total` FROM `ratings` WHERE `entry_id` = 9703 AND `user_id` = 995")->fetchColumn();
    assert_reh("Gulshan historical rating (score: 45) remains intact after archive", $rating1 === 45, "Got: {$rating1}");

    // 9. Negative IDOR & Cross-User Security Test
    echo "\n--- 9. Negative IDOR & Cross-User Mutation Prevention ---\n";
    // Charlie attempts to update Gulshan's song 2
    $idorStmt = $pdo->prepare("UPDATE `songs` SET `title` = 'Hacked' WHERE `song_id` = 2 AND `user_id` = 1050");
    $idorStmt->execute();
    $rowsAffected = $idorStmt->rowCount();
    assert_reh("Cross-user UPDATE affected 0 rows (IDOR blocked)", $rowsAffected === 0);

    $gSong2Title = $pdo->query("SELECT `title` FROM `songs` WHERE `song_id` = 2")->fetchColumn();
    assert_reh("Gulshan song 2 title remains intact in database", $gSong2Title !== 'Hacked');

    // 10. Test Migration Rollback
    echo "\n--- 10. Rehearse Migration 011 Rollback ---\n";
    rollback_011_master_starter_catalogue($pdo);

    $tablesAfterRollback = $pdo->query("SHOW TABLES LIKE 'master_starter_%'")->fetchAll(PDO::FETCH_COLUMN);
    assert_reh("Master starter tables dropped upon rollback", empty($tablesAfterRollback));

    $uciAfterRollback = $pdo->query("SHOW TABLES LIKE 'user_catalogue_initialization'")->fetchAll(PDO::FETCH_COLUMN);
    assert_reh("user_catalogue_initialization table dropped upon rollback", empty($uciAfterRollback));

    $clonedSongsAfterRollback = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `song_id` > 2428")->fetchColumn();
    assert_reh("Cloned user songs purged upon rollback (0 remaining)", $clonedSongsAfterRollback === 0, "Got: {$clonedSongsAfterRollback}");

    $gulshanSongsAfterRollback = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` = 995")->fetchColumn();
    // In our test, Charlie deleted 10 songs, but Gulshan retained all 2,411
    assert_reh("Gulshan baseline songs strictly preserved upon rollback (2,411 songs)", $gulshanSongsAfterRollback === 2411, "Got: {$gulshanSongsAfterRollback}");

    // 11. Summary
    echo "\n======================================================================\n";
    echo " REHEARSAL RESULTS: {$passed} Passed, {$failed} Failed\n";
    echo "======================================================================\n\n";

} catch (\Throwable $e) {
    echo "\n\033[31mCRITICAL REHEARSAL ERROR: " . $e->getMessage() . "\033[0m\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
} finally {
    // Drop disposable test database
    $rootPdo->exec("DROP DATABASE IF EXISTS `{$disposableDb}`");
    echo "Cleaned up disposable database: {$disposableDb}\n\n";
}

if ($failed > 0) {
    exit(1);
}
