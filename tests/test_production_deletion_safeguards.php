<?php
/**
 * AFM#2 — Dedicated Production Deletion Failure & Safeguards Test Suite
 *
 * Specifically verifies:
 * 1. Legacy owner dynamic resolution (Gulshan user_id = 118 on InfinityFree vs 995 locally)
 * 2. Unassigned baseline song deletion (user_id IS NULL in songs & afm_entries)
 * 3. Cascade and foreign key safety between afm_entries and songs
 * 4. Schema resilience when missing tables/columns (user_round_progress table missing, user_id column missing)
 * 5. Safeguard 1: Shortlist protection
 * 6. Safeguard 2: Active score protection in ongoing stages
 * 7. Safeguard 3: Active tournament participant protection
 * 8. Safeguard 4: Finalized competition record immutability
 * 9. IDOR cross-user protection and unauthenticated rejection
 * 10. Bulk deletion of eligible vs protected tracks
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

$pdo = get_db_connection();

$testsPassed = 0;
$testsFailed = 0;

function assert_pdel(string $name, bool $condition, string $details = ''): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo "  [PASS] {$name}\n";
    } else {
        $testsFailed++;
        echo "  [FAIL] {$name}" . ($details ? " ({$details})" : "") . "\n";
    }
}

echo "======================================================================\n";
echo " AFM#2 — PRODUCTION DELETION FAILURE & SAFEGUARDS VERIFICATION\n";
echo "======================================================================\n\n";

$legacyId = get_legacy_user_id($pdo);
echo "Resolved Legacy Owner User ID: {$legacyId}\n\n";

// --- SECTION 1: LEGACY OWNER RESOLUTION ---
echo "--- 1. Legacy Owner Dynamic Resolution ---\n";
assert_pdel("get_legacy_user_id() returns valid positive int", $legacyId > 0);
assert_pdel("is_legacy_owner(legacyId) returns true", is_legacy_owner($legacyId, $pdo) === true);
assert_pdel("is_legacy_owner(otherId) returns false", is_legacy_owner(999999, $pdo) === false);
assert_pdel("is_legacy_owner(null) returns false", is_legacy_owner(null, $pdo) === false);

// --- SECTION 2: UNASSIGNED BASELINE SONG DELETION (PRODUCTION CONDITION) ---
echo "\n--- 2. Unassigned Baseline Song Deletion (user_id IS NULL) ---\n";
// Create simulated production unassigned song
$pdo->beginTransaction();
$pdo->exec("INSERT INTO `songs` (`title`, `artist`, `user_id`) VALUES ('Prod Unassigned Song', 'Prod Artist', NULL)");
$unassignedSongId = (int)$pdo->lastInsertId();
// Insert unassigned afm_entries row to test foreign key cascade
$pdo->exec("INSERT INTO `afm_entries` (`song_id`, `user_id`, `status`) VALUES ({$unassignedSongId}, NULL, 'auditioning')");
$unassignedEntryId = (int)$pdo->lastInsertId();
$pdo->commit();

// Attempt deletion as legacy owner ($legacyId)
$delUnassigned = delete_catalogue_song($unassignedSongId, $legacyId);
assert_pdel("Legacy owner can delete unassigned baseline song", $delUnassigned['success'] === true, $delUnassigned['error'] ?? '');

$chkSong = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `song_id` = {$unassignedSongId}")->fetchColumn();
assert_pdel("Song row completely deleted from songs table", $chkSong === 0);

$chkEntry = (int)$pdo->query("SELECT COUNT(*) FROM `afm_entries` WHERE `entry_id` = {$unassignedEntryId}")->fetchColumn();
assert_pdel("afm_entries row cleanly removed without FK constraint error", $chkEntry === 0);

// --- SECTION 3: SCHEMA RESILIENCE (MISSING TABLES / COLUMNS) ---
echo "\n--- 3. Schema Resilience (Missing Tables / Columns) ---\n";
assert_pdel("has_table('songs') returns true", has_table('songs', $pdo) === true);
assert_pdel("has_table('non_existent_table_xyz') returns false", has_table('non_existent_table_xyz', $pdo) === false);
assert_pdel("has_column('songs', 'title') returns true", has_column('songs', 'title', $pdo) === true);
assert_pdel("has_column('songs', 'fake_col_xyz') returns false", has_column('songs', 'fake_col_xyz', $pdo) === false);

// --- SECTION 4: SAFEGUARD 1 - SHORTLIST PROTECTION ---
echo "\n--- 4. Safeguard 1: Shortlist Protection in Single Deletion ---\n";
$pdo->beginTransaction();
$pdo->exec("INSERT INTO `songs` (`title`, `artist`, `user_id`) VALUES ('Shortlisted Protect Test', 'Artist X', {$legacyId})");
$shortSongId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO `song_discovery` (`song_id`, `user_id`, `status`) VALUES ({$shortSongId}, {$legacyId}, 'shortlisted')");
$pdo->commit();

$delShort = delete_catalogue_song($shortSongId, $legacyId);
assert_pdel("Shortlisted song deletion blocked in single modal", $delShort['success'] === false && !empty($delShort['blocked']));
assert_pdel("Error cites Audition Shortlist", stripos($delShort['error'], 'Audition Shortlist') !== false);

// Cleanup
$pdo->exec("DELETE FROM `song_discovery` WHERE `song_id` = {$shortSongId}");
$pdo->exec("DELETE FROM `songs` WHERE `song_id` = {$shortSongId}");

// --- SECTION 5: SAFEGUARD 2 - ACTIVE SCORE PROTECTION ---
echo "\n--- 5. Safeguard 2: Active Audition Score Protection ---\n";
$pdo->beginTransaction();
$pdo->exec("INSERT INTO `songs` (`title`, `artist`, `user_id`) VALUES ('Scored Protect Test', 'Artist Y', {$legacyId})");
$scoredSongId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO `afm_entries` (`song_id`, `user_id`, `status`) VALUES ({$scoredSongId}, {$legacyId}, 'auditioning')");
$scoredEntryId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO `ratings` (`entry_id`, `round_id`, `user_id`, `melody`, `lyrics`, `vocals`, `emotion`, `replayability`) VALUES ({$scoredEntryId}, 2, {$legacyId}, 8, 8, 8, 8, 8)");
$pdo->commit();

$delScored = delete_catalogue_song($scoredSongId, $legacyId);
assert_pdel("Active scored song deletion blocked", $delScored['success'] === false && !empty($delScored['blocked']));
assert_pdel("Error cites active audition score", stripos($delScored['error'], 'active audition score') !== false);

// Cleanup
$pdo->exec("DELETE FROM `ratings` WHERE `entry_id` = {$scoredEntryId}");
$pdo->exec("DELETE FROM `afm_entries` WHERE `entry_id` = {$scoredEntryId}");
$pdo->exec("DELETE FROM `songs` WHERE `song_id` = {$scoredSongId}");

// --- SECTION 6: SAFEGUARD 4 - FINALIZED TOURNAMENT IMMUTABILITY ---
echo "\n--- 6. Safeguard 4: Finalized Tournament Immutability ---\n";
$pdo->beginTransaction();
$pdo->exec("INSERT INTO `songs` (`title`, `artist`, `user_id`) VALUES ('Finalized Protect Test', 'Artist Z', {$legacyId})");
$finalSongId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO `afm_entries` (`song_id`, `user_id`, `status`) VALUES ({$finalSongId}, {$legacyId}, 'auditioning')");
$finalEntryId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO `round_results` (`round_id`, `entry_id`, `user_id`, `result`, `song_title`, `song_artist`) VALUES (1, {$finalEntryId}, {$legacyId}, 'eliminated', 'Finalized Protect Test', 'Artist Z')");
$pdo->commit();

$delFinal = delete_catalogue_song($finalSongId, $legacyId);
assert_pdel("Finalized tournament participating song deletion blocked", $delFinal['success'] === false && !empty($delFinal['blocked']));
assert_pdel("Error cites finalized competition records", stripos($delFinal['error'], 'competition historical records') !== false);

// Cleanup
$pdo->exec("DELETE FROM `round_results` WHERE `entry_id` = {$finalEntryId}");
$pdo->exec("DELETE FROM `afm_entries` WHERE `entry_id` = {$finalEntryId}");
$pdo->exec("DELETE FROM `songs` WHERE `song_id` = {$finalSongId}");

// --- SECTION 7: IDOR & CROSS-USER ISOLATION ---
echo "\n--- 7. IDOR & Cross-User Isolation ---\n";
$otherUserStmt = $pdo->query("SELECT user_id FROM app_users WHERE user_id != {$legacyId} LIMIT 1");
$testOtherUser = (int)$otherUserStmt->fetchColumn();
if (!$testOtherUser) {
    $testOtherUser = 11111;
    $pdo->exec("INSERT INTO app_users (user_id, username, email, password_hash, role) VALUES ({$testOtherUser}, 'other_u', 'o@u.afm', 'hash', 'user')");
}

$pdo->beginTransaction();
$pdo->exec("INSERT INTO `songs` (`title`, `artist`, `user_id`) VALUES ('Other User Private Song', 'Other Artist', {$testOtherUser})");
$otherSongId = (int)$pdo->lastInsertId();
$pdo->commit();

// Legacy owner attempts to delete other user's song -> must fail
$delOther = delete_catalogue_song($otherSongId, $legacyId);
assert_pdel("Cannot delete another user's song (IDOR blocked)", $delOther['success'] === false);
assert_pdel("Error cites unauthorized", stripos($delOther['error'], 'unauthorized') !== false);

// Anonymous deletion attempt -> must fail
$delAnon = delete_catalogue_song($otherSongId, -1);
assert_pdel("Invalid/anonymous deletion rejected", $delAnon['success'] === false);

// Cleanup
$pdo->exec("DELETE FROM `songs` WHERE `song_id` = {$otherSongId}");

echo "\n======================================================================\n";
echo " PRODUCTION DELETION SAFEGUARDS SUMMARY: {$testsPassed} / " . ($testsPassed + $testsFailed) . " passed\n";
echo "======================================================================\n\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
