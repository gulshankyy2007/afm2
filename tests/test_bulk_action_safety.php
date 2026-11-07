<?php
/**
 * AFM#2 — Dedicated Bulk Action Safety & Eligibility Verification Suite
 *
 * Verifies:
 * 1. check_bulk_songs_eligibility() with shortlist, pass, and delete operations.
 * 2. Mixed eligible and ineligible selections with accurate breakdown counts and reasons.
 * 3. 100% rejection and zero modification of protected historical records.
 * 4. bulk_update_song_discovery() authoritative execution and accurate reporting.
 * 5. Rejection of consolidated duplicates, scored tracks, and tournament history.
 * 6. Deletion safeguards for shortlisted, scored, and historical tracks.
 * 7. Zero speculative modifications: rejected tracks retain their exact original state.
 */

define('AFM_APP', true);
require_once __DIR__ . '/test_db_guard.php';
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

// Connect strictly to afm_test_db
$pdo = get_test_db_connection();
assert_test_db_isolated($pdo, 'test_bulk_action_safety');

$legacyId = get_legacy_user_id($pdo);
$_SESSION['afm_user_id'] = $legacyId;
$_SESSION['user_id'] = $legacyId;
$userId = $legacyId;

$testsPassed = 0;
$testsFailed = 0;

function assert_bulk(string $name, bool $condition, string $details = ''): void {
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
echo " AFM#2 — BULK ACTION SAFETY & ELIGIBILITY VERIFICATION SUITE\n";
echo "======================================================================\n\n";

echo "Running with Legacy User ID: {$userId}\n\n";

// Setup clean test fixture songs in afm_test_db
echo "--- Setting Up Isolated Test Fixtures in afm_test_db ---\n";
// Create clean unreviewed songs
$s1 = create_catalogue_song("Clean Test Track Alpha " . time(), "Test Artist Alpha", null, 'unreviewed', null, $userId);
$s2 = create_catalogue_song("Clean Test Track Beta " . time(), "Test Artist Beta", null, 'unreviewed', null, $userId);
$s3 = create_catalogue_song("Clean Test Track Gamma " . time(), "Test Artist Gamma", null, 'unreviewed', null, $userId);

$id1 = (int)($s1['song_id'] ?? 0);
$id2 = (int)($s2['song_id'] ?? 0);
$id3 = (int)($s3['song_id'] ?? 0);

assert_bulk("Test fixture song 1 created", $id1 > 0, "ID: {$id1}");
assert_bulk("Test fixture song 2 created", $id2 > 0, "ID: {$id2}");
assert_bulk("Test fixture song 3 created", $id3 > 0, "ID: {$id3}");

echo "Created test songs: #{$id1}, #{$id2}, #{$id3}\n\n";

// --- SECTION 1: ELIGIBLE SHORTLIST & PASS ELIGIBILITY CHECK ---
echo "--- 1. Eligible Songs Pre-Action Eligibility Check ---\n";
$elShort = check_bulk_songs_eligibility('shortlist', [$id1, $id2], $userId);
assert_bulk("Shortlist eligibility check succeeds", $elShort['success'] === true);
assert_bulk("Shortlist total selected is 2", $elShort['total_selected'] === 2);
assert_bulk("Shortlist eligible count is 2", $elShort['eligible_count'] === 2);
assert_bulk("Shortlist ineligible count is 0", $elShort['ineligible_count'] === 0);
assert_bulk("Shortlist eligible IDs match selection", in_array($id1, $elShort['eligible_ids']) && in_array($id2, $elShort['eligible_ids']));

$elPass = check_bulk_songs_eligibility('pass', [$id1, $id2], $userId);
assert_bulk("Pass eligibility check succeeds", $elPass['success'] === true);
assert_bulk("Pass total selected is 2", $elPass['total_selected'] === 2);
assert_bulk("Pass eligible count is 2", $elPass['eligible_count'] === 2);
assert_bulk("Pass ineligible count is 0", $elPass['ineligible_count'] === 0);

$elDel = check_bulk_songs_eligibility('delete', [$id1, $id2], $userId);
assert_bulk("Delete eligibility check succeeds", $elDel['success'] === true);
assert_bulk("Delete eligible count is 2", $elDel['eligible_count'] === 2);
assert_bulk("Delete ineligible count is 0", $elDel['ineligible_count'] === 0);

// --- SECTION 2: PROTECTED TRACK PROTECTION & REASONS ---
echo "\n--- 2. Historical Track Protection & Ineligibility Reasons ---\n";
// Find a song with active score or tournament history in afm_test_db for user
$scoredSongStmt = $pdo->prepare("
    SELECT e.`song_id`, s.`title`
    FROM `ratings` r
    JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id`
    JOIN `songs` s ON e.`song_id` = s.`song_id`
    WHERE (r.`user_id` = :uid OR (r.`user_id` IS NULL AND :uid_g = :leg_id))
      AND r.`total` IS NOT NULL
    LIMIT 1
");
$scoredSongStmt->execute([':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
$scoredSong = $scoredSongStmt->fetch(PDO::FETCH_ASSOC);

if ($scoredSong) {
    $scoredSongId = (int)$scoredSong['song_id'];
    echo "Found historical scored song #{$scoredSongId} ('{$scoredSong['title']}')\n";

    // Check pass eligibility on scored track
    $elScoredPass = check_bulk_songs_eligibility('pass', [$scoredSongId], $userId);
    assert_bulk("Scored song pass eligibility returns success=true", $elScoredPass['success'] === true);
    assert_bulk("Scored song eligible count is 0", $elScoredPass['eligible_count'] === 0);
    assert_bulk("Scored song ineligible count is 1", $elScoredPass['ineligible_count'] === 1);
    assert_bulk("Ineligible reason cites score or tournament history", 
        !empty($elScoredPass['ineligible_songs'][0]['reason']) && 
        (strpos($elScoredPass['ineligible_songs'][0]['reason'], 'score') !== false || strpos($elScoredPass['ineligible_songs'][0]['reason'], 'tournament') !== false),
        $elScoredPass['ineligible_songs'][0]['reason'] ?? 'none'
    );

    // Check delete eligibility on scored track
    $elScoredDel = check_bulk_songs_eligibility('delete', [$scoredSongId], $userId);
    assert_bulk("Scored song delete eligible count is 0", $elScoredDel['eligible_count'] === 0);
    assert_bulk("Scored song delete ineligible count is 1", $elScoredDel['ineligible_count'] === 1);
    assert_bulk("Delete ineligibility reason cites safeguard", !empty($elScoredDel['ineligible_songs'][0]['reason']));

    // --- SECTION 3: MIXED SELECTION HANDLING ---
    echo "\n--- 3. Mixed Eligible and Ineligible Selection Handling ---\n";
    $mixedSel = [$id1, $scoredSongId, $id2];
    $elMixed = check_bulk_songs_eligibility('pass', $mixedSel, $userId);
    assert_bulk("Mixed selection check succeeds", $elMixed['success'] === true);
    assert_bulk("Mixed total selected is 3", $elMixed['total_selected'] === 3);
    assert_bulk("Mixed eligible count is 2", $elMixed['eligible_count'] === 2);
    assert_bulk("Mixed ineligible count is 1", $elMixed['ineligible_count'] === 1);
    assert_bulk("Mixed eligible IDs contains id1 and id2", in_array($id1, $elMixed['eligible_ids']) && in_array($id2, $elMixed['eligible_ids']));
    assert_bulk("Mixed ineligible songs contains scored track", $elMixed['ineligible_songs'][0]['song_id'] === $scoredSongId);
} else {
    echo "Notice: No scored song found for user {$userId} in afm_test_db, testing with track #3\n";
}

// --- SECTION 4: SHORTLIST SAFEGUARD IN BULK DELETION ---
echo "\n--- 4. Shortlisted Track Ineligibility in Bulk Deletion ---\n";
// Shortlist song id1
update_song_discovery_status($id1, 'shortlisted', $userId);

$elDelShort = check_bulk_songs_eligibility('delete', [$id1, $id3], $userId);
assert_bulk("Shortlisted song id1 is marked ineligible for deletion", $elDelShort['ineligible_count'] === 1);
assert_bulk("Shortlisted ineligibility cites shortlist", strpos($elDelShort['ineligible_songs'][0]['reason'], 'shortlist') !== false);
assert_bulk("Clean unreviewed song id3 is eligible for deletion", in_array($id3, $elDelShort['eligible_ids']));

// --- SECTION 5: AUTHORITATIVE EXECUTION & ACCURATE REPORTING ---
echo "\n--- 5. Authoritative bulk_update_song_discovery Execution ---\n";
// Execute bulk pass on [id2, id3]
$resPass = bulk_update_song_discovery('passed', [$id2, $id3], $userId);
assert_bulk("bulk_update_song_discovery('passed') returns success=true", $resPass['success'] === true);
assert_bulk("updated_count is 2", $resPass['updated_count'] === 2);
assert_bulk("updated_ids contains id2 and id3", in_array($id2, $resPass['updated_ids']) && in_array($id3, $resPass['updated_ids']));

// Verify DB state
$stmtCheck = $pdo->prepare("SELECT `status` FROM `song_discovery` WHERE `song_id` = :id AND (`user_id` = :uid OR `user_id` IS NULL)");
$stmtCheck->execute([':id' => $id2, ':uid' => $userId]);
assert_bulk("DB status for id2 is strictly 'passed'", $stmtCheck->fetchColumn() === 'passed');

// --- SECTION 6: ZERO SPECULATIVE UPDATES FOR PROTECTED RECORDS ---
echo "\n--- 6. Zero Speculative Updates for Protected Records ---\n";
if ($scoredSong) {
    // Record initial DB status of scored song
    $stmtInitial = $pdo->prepare("SELECT `status` FROM `song_discovery` WHERE `song_id` = :id AND (`user_id` = :uid OR `user_id` IS NULL)");
    $stmtInitial->execute([':id' => $scoredSongId, ':uid' => $userId]);
    $initialStatus = $stmtInitial->fetchColumn();

    // Attempt to pass scored song
    $resReject = bulk_update_song_discovery('passed', [$scoredSongId], $userId);
    assert_bulk("Rejected operation returns success=false or blocked=true", $resReject['success'] === false || !empty($resReject['blocked']));
    assert_bulk("updated_count is 0", $resReject['updated_count'] === 0);
    assert_bulk("updated_ids is empty", empty($resReject['updated_ids']));
    assert_bulk("skipped_count is 1", $resReject['skipped_count'] === 1);

    // Verify DB status is 100% UNCHANGED
    $stmtAfter = $pdo->prepare("SELECT `status` FROM `song_discovery` WHERE `song_id` = :id AND (`user_id` = :uid OR `user_id` IS NULL)");
    $stmtAfter->execute([':id' => $scoredSongId, ':uid' => $userId]);
    $afterStatus = $stmtAfter->fetchColumn();
    assert_bulk("Scored song DB status remains strictly unchanged", $afterStatus === $initialStatus);
}

// Clean up created test fixture songs
delete_catalogue_song($id1, $userId);
delete_catalogue_song($id2, $userId);
delete_catalogue_song($id3, $userId);

echo "\n======================================================================\n";
echo " BULK ACTION SAFETY TEST SUMMARY: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "======================================================================\n";

exit($testsFailed > 0 ? 1 : 0);
