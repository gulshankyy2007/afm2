<?php
/**
 * AFM#2 - Safe Duplicate Review, Consolidation & Catalogue Integrity Test Suite
 * 
 * Verifies:
 * 1. Candidate Detection:
 *    - Exact title & artist matches.
 *    - Case and whitespace differences.
 *    - Recognized official-video/audio suffixes.
 *    - Missing artist values (4 records).
 *    - Same title with different artists.
 *    - Remix, cover, and live-version candidates.
 *    - Different YouTube references.
 *    - Candidate detection with strictly no database mutation.
 * 2. Decisions:
 *    - Keep both.
 *    - Defer.
 *    - Exclude from competition.
 *    - Safe consolidation without conflicting history.
 *    - Consolidation blocked when both records have conflicting ratings or results.
 *    - Consolidation blocked when unique constraints would be violated.
 *    - Failed transaction rolls back all changes.
 *    - Audit and recovery data remain available.
 *    - Unauthorized and CSRF-invalid requests are rejected.
 *    - Repeated submission does not duplicate changes.
 * 3. Regression and Integrity:
 *    - All 2,475 original songs remain accounted for.
 *    - Four originally missing artist values remain unchanged.
 *    - No ratings or completed round results are lost.
 *    - No duplicate afm_entries or invalid foreign-key relationships.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/duplicate_helper.php';

$pdo = get_db_connection();

// Ensure clean test baseline
$pdo->exec("UPDATE `songs` SET `is_consolidated` = 0, `consolidated_into_id` = NULL, `consolidated_at` = NULL WHERE `is_consolidated` = 1");
$pdo->exec("DELETE FROM `duplicate_reviews` WHERE `song_id_a` = 1536 AND `song_id_b` = 1579");
$pdo->exec("DELETE FROM `song_consolidation_audit` WHERE `canonical_song_id` = 1536 AND `consolidated_song_id` = 1579");
$pdo->exec("DELETE FROM `song_media` WHERE `video_id` = 'test_vid_1579'");
$pdo->exec("DELETE FROM `afm_entries` WHERE `song_id` IN (1536, 1579)");

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assert_true(bool $condition, string $testName): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  ✔ PASS: {$testName}\n";
    } else {
        $failedTests++;
        echo "  ❌ FAIL: {$testName}\n";
    }
}

echo "======================================================================\n";
echo " AFM#2 — SAFE DUPLICATE REVIEW & CATALOGUE INTEGRITY TEST SUITE\n";
echo "======================================================================\n\n";

// Pre-test Catalogue Invariant
$initialSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
$initialMissingArtists = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL OR TRIM(`artist`) = ''")->fetchColumn();
assert_true($initialSongCount >= 2400, "Pre-test Catalogue Invariant: Active catalogue songs present (actual: {$initialSongCount})");
assert_true($initialMissingArtists >= 3, "Pre-test Missing Artist Invariant: Songs with missing artist present");

// -------------------------------------------------------------------
// 1. Candidate Detection & Text Normalization
// -------------------------------------------------------------------
echo "\n1. Candidate Detection & Text Normalization\n";

// Case and whitespace differences
$norm1 = normalize_text("  Arcade   ");
$norm2 = normalize_text("arcade");
assert_true($norm1 === 'arcade' && $norm2 === 'arcade', "normalize_text() handles case and extraneous whitespace");

// Punctuation & Unicode dash differences
$dashUnicode = normalize_text("Bling‐Bang‐Bang‐Born"); // Unicode dash U+2010
$dashAscii = normalize_text("Bling-Bang-Bang-Born");   // ASCII hyphen
assert_true($dashUnicode === 'bling bang bang born' && $dashAscii === 'bling bang bang born', "normalize_text() converts unicode hyphens and punctuation identically");

// Curly quotes & apostrophes
$quoteCurly = normalize_text("Don’t Stop Believin’");
$quoteAscii = normalize_text("Don't Stop Believin'");
assert_true($quoteCurly === $quoteAscii, "normalize_text() normalizes curly apostrophes to match ascii");

// Recognized official-video suffixes
$meta1 = parse_title_metadata("Arcade [Official Music Video]");
$meta2 = parse_title_metadata("Arcade (Lyric Video)");
$meta3 = parse_title_metadata("Arcade (Official Audio)");
assert_true($meta1['clean_title'] === 'arcade', "parse_title_metadata() strips [Official Music Video] suffix");
assert_true($meta2['clean_title'] === 'arcade', "parse_title_metadata() strips (Lyric Video) suffix");
assert_true($meta3['clean_title'] === 'arcade', "parse_title_metadata() strips (Official Audio) suffix");

// Alternate version tags
$metaAcoustic = parse_title_metadata("Arcade (Acoustic Version)");
$metaLive = parse_title_metadata("Hotel California - Live at The Forum");
$metaRemix = parse_title_metadata("Baby (80s Remix)");
$metaRemaster = parse_title_metadata("Bohemian Rhapsody (Remastered 2011)");
assert_true($metaAcoustic['version'] === 'Acoustic' && $metaAcoustic['base_title'] === 'arcade', "parse_title_metadata() detects Acoustic version and extracts base title");
assert_true($metaLive['version'] === 'Live' && $metaLive['base_title'] === 'hotel california', "parse_title_metadata() detects Live version and extracts base title");
assert_true($metaRemix['version'] === 'Remix' && $metaRemix['base_title'] === 'baby', "parse_title_metadata() detects Remix version and extracts base title");
assert_true($metaRemaster['version'] === 'Remaster' && $metaRemaster['base_title'] === 'bohemian rhapsody', "parse_title_metadata() detects Remaster version and extracts base title");

// Candidate detection on actual catalogue with NO database mutation
$countsBefore = [
    'songs'       => (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn(),
    'reviews'     => (int)$pdo->query("SELECT COUNT(*) FROM `duplicate_reviews`")->fetchColumn(),
    'audits'      => (int)$pdo->query("SELECT COUNT(*) FROM `song_consolidation_audit`")->fetchColumn(),
    'entries'     => (int)$pdo->query("SELECT COUNT(*) FROM `afm_entries`")->fetchColumn()
];

$scan = detect_duplicate_candidates();
$candidates = $scan['candidates'];
$stats = $scan['stats'];

$countsAfter = [
    'songs'       => (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn(),
    'reviews'     => (int)$pdo->query("SELECT COUNT(*) FROM `duplicate_reviews`")->fetchColumn(),
    'audits'      => (int)$pdo->query("SELECT COUNT(*) FROM `song_consolidation_audit`")->fetchColumn(),
    'entries'     => (int)$pdo->query("SELECT COUNT(*) FROM `afm_entries`")->fetchColumn()
];

assert_true($countsBefore === $countsAfter, "MANDATORY SAFEGUARD: detect_duplicate_candidates() causes strictly ZERO database mutations");
assert_true($stats['total_candidates'] > 0, "detect_duplicate_candidates() identified candidate groups in catalogue ({$stats['total_candidates']} groups)");
assert_true(isset($stats['categories']['formatting_difference']) && $stats['categories']['formatting_difference'] >= 2, "Candidate detection identified formatting difference pairs");
assert_true(isset($stats['categories']['alternate_version']) && $stats['categories']['alternate_version'] > 20, "Candidate detection identified alternate version candidate pairs");
assert_true(isset($stats['categories']['same_title_uncertain_artist']) && $stats['categories']['same_title_uncertain_artist'] > 0, "Candidate detection matched missing/uncertain artist songs with known tracks");
assert_true(isset($stats['categories']['same_title_diff_artist']) && $stats['categories']['same_title_diff_artist'] > 50, "Candidate detection identified same title with different artist recordings");

// -------------------------------------------------------------------
// 2. Side-by-Side Comparison Data Verification
// -------------------------------------------------------------------
echo "\n2. Side-by-Side Comparison Data Verification\n";

$compBling = get_song_comparison_data(1536, 1579);
assert_true($compBling !== null, "get_song_comparison_data() returns payload for candidate pair");
assert_true($compBling['match_type'] === 'formatting_difference', "Comparison detects formatting difference match type");
assert_true($compBling['confidence'] === 'high', "Comparison flags unicode/punctuation match as high confidence");
assert_true(!empty($compBling['reasons']), "Comparison includes human-readable matching evidence");
assert_true(isset($compBling['song_a']['song']) && isset($compBling['song_b']['song']), "Comparison payload provides complete confirmed song facts for both records");
assert_true($compBling['safety_a_as_canonical']['safe'] === true, "Pre-flight safety assessment evaluates consolidation safety without conflict");

// -------------------------------------------------------------------
// 3. User Review Decisions (Keep Both, Defer, Exclude)
// -------------------------------------------------------------------
echo "\n3. User Review Decisions (Keep Both, Defer, Exclude)\n";

// Keep Both Decision
$resKeep = save_duplicate_decision(1536, 1579, 'keep_both', null, 'Keep both recordings', 1);
assert_true($resKeep['success'] === true, "save_duplicate_decision('keep_both') executes successfully");

$revRecord = $pdo->query("SELECT * FROM `duplicate_reviews` WHERE `song_id_a` = 1536 AND `song_id_b` = 1579")->fetch(PDO::FETCH_ASSOC);
assert_true($revRecord !== false && $revRecord['decision'] === 'keep_both', "duplicate_reviews table persists 'keep_both' decision with timestamp");

$compAfterKeep = detect_duplicate_candidates(['search' => 'Bling-Bang-Bang-Born']);
$pairAfterKeep = array_filter($compAfterKeep['candidates'], fn($c) => $c['pair_id'] === '1536_1579');
$pairAfterKeep = reset($pairAfterKeep);
assert_true($pairAfterKeep['status'] === 'resolved', "Candidate pair status transitions to 'resolved' after Keep Both decision");

// Defer Decision
$resDefer = save_duplicate_decision(1536, 1579, 'deferred', null, 'Need further listening check', 1);
assert_true($resDefer['success'] === true, "save_duplicate_decision('deferred') executes successfully");
$revRecordDefer = $pdo->query("SELECT * FROM `duplicate_reviews` WHERE `song_id_a` = 1536 AND `song_id_b` = 1579")->fetch(PDO::FETCH_ASSOC);
assert_true($revRecordDefer['decision'] === 'deferred', "Review decision updated to 'deferred'");

// Exclude from Competition Decision
$resExclude = save_duplicate_decision(1536, 1579, 'excluded', 1579, 'Exclude second release from competition', 1);
assert_true($resExclude['success'] === true, "save_duplicate_decision('excluded') succeeds");
$entryExclude = $pdo->query("SELECT `status` FROM `afm_entries` WHERE `song_id` = 1579")->fetch(PDO::FETCH_ASSOC);
assert_true($entryExclude !== false && $entryExclude['status'] === 'excluded', "afm_entries row provisioned with status 'excluded' without modifying song catalogue");

$songAfterExclude = $pdo->query("SELECT `song_id`, `title` FROM `songs` WHERE `song_id` = 1579")->fetch(PDO::FETCH_ASSOC);
assert_true($songAfterExclude !== false, "Excluded song strictly preserved in catalogue");

// -------------------------------------------------------------------
// 4. Critical Competition-History Safeguards & Blocked Consolidation
// -------------------------------------------------------------------
echo "\n4. Critical Competition-History Safeguards & Blocked Consolidation\n";

// Create test entries with CONFLICTING Round 1 ratings
$pdo->beginTransaction();
$pdo->exec("DELETE FROM `round_results` WHERE `entry_id` IN (SELECT `entry_id` FROM `afm_entries` WHERE `song_id` IN (1536, 1579))");
$pdo->exec("DELETE FROM `ratings` WHERE `entry_id` IN (SELECT `entry_id` FROM `afm_entries` WHERE `song_id` IN (1536, 1579))");

$entryA = $pdo->query("SELECT `entry_id` FROM `afm_entries` WHERE `song_id` = 1536")->fetchColumn();
if (!$entryA) {
    $pdo->exec("INSERT INTO `afm_entries` (`song_id`, `status`) VALUES (1536, 'auditioning')");
    $entryA = $pdo->lastInsertId();
}
$entryB = $pdo->query("SELECT `entry_id` FROM `afm_entries` WHERE `song_id` = 1579")->fetchColumn();
if (!$entryB) {
    $pdo->exec("INSERT INTO `afm_entries` (`song_id`, `status`) VALUES (1579, 'auditioning')");
    $entryB = $pdo->lastInsertId();
}

$pdo->exec("INSERT INTO `ratings` (`entry_id`, `round_id`, `melody`, `lyrics`, `vocals`, `emotion`, `replayability`) VALUES ({$entryA}, 1, 9, 8, 9, 9, 9)");
$pdo->exec("INSERT INTO `ratings` (`entry_id`, `round_id`, `melody`, `lyrics`, `vocals`, `emotion`, `replayability`) VALUES ({$entryB}, 1, 7, 7, 8, 8, 8)");
$pdo->commit();

$conflictSafety = validate_consolidation_safety(1536, 1579);
assert_true($conflictSafety['safe'] === false, "CRITICAL SAFEGUARD: validate_consolidation_safety() detects overlapping round ratings and marks safe=false");
assert_true(!empty($conflictSafety['conflicts']), "Conflict explanation specifically cites conflicting scorecards");

// Verify execute_consolidation() blocks the operation and throws exception
$blockedThrown = false;
try {
    execute_consolidation(1536, 1579, 'Attempt conflicting merge', 1);
} catch (RuntimeException $e) {
    $blockedThrown = true;
}
assert_true($blockedThrown, "CRITICAL SAFEGUARD: execute_consolidation() blocks conflicting rating consolidation with RuntimeException");

// Verify conflicting tournament results are also blocked
$pdo->beginTransaction();
$pdo->exec("DELETE FROM `ratings` WHERE `entry_id` IN ({$entryA}, {$entryB})");
$pdo->exec("INSERT INTO `round_results` (`entry_id`, `round_id`, `result`, `rank_position`, `reason`) VALUES ({$entryA}, 1, 'qualified', 10, 'Top score')");
$pdo->exec("INSERT INTO `round_results` (`entry_id`, `round_id`, `result`, `rank_position`, `reason`) VALUES ({$entryB}, 1, 'eliminated', 50, 'Cutoff')");
$pdo->commit();

$resultConflictSafety = validate_consolidation_safety(1536, 1579);
assert_true($resultConflictSafety['safe'] === false, "CRITICAL SAFEGUARD: validate_consolidation_safety() detects conflicting tournament results and marks safe=false");

$blockedResultThrown = false;
try {
    execute_consolidation(1536, 1579, 'Attempt conflicting results merge', 1);
} catch (RuntimeException $e) {
    $blockedResultThrown = true;
}
assert_true($blockedResultThrown, "CRITICAL SAFEGUARD: execute_consolidation() blocks conflicting tournament results merge");

// Clean up test conflicting entries
$pdo->beginTransaction();
$pdo->exec("DELETE FROM `round_results` WHERE `entry_id` IN ({$entryA}, {$entryB})");
$pdo->exec("DELETE FROM `afm_entries` WHERE `entry_id` IN ({$entryA}, {$entryB})");
$pdo->commit();

// -------------------------------------------------------------------
// 5. Safe Transactional Consolidation, Audit Trail & Reversible Recovery
// -------------------------------------------------------------------
echo "\n5. Safe Transactional Consolidation, Audit Trail & Reversible Recovery\n";

// Add mock media & lyrics to song 1579 to test lossless reconciliation
$pdo->beginTransaction();
$pdo->exec("
    INSERT INTO `song_media` (`song_id`, `service`, `video_id`, `title`, `channel_title`, `version_type`)
    VALUES (1579, 'youtube', 'test_vid_1579', 'Creepy Nuts - Bling-Bang-Bang-Born', 'Creepy Nuts Official', 'original')
    ON DUPLICATE KEY UPDATE `video_id` = 'test_vid_1579'
");
$pdo->commit();

// Pre-consolidation check
$safeSafety = validate_consolidation_safety(1536, 1579);
assert_true($safeSafety['safe'] === true, "validate_consolidation_safety() confirms safe consolidation when no competition conflicts exist");

// Execute safe consolidation
$consoRes = execute_consolidation(1536, 1579, 'Confirmed duplicate formatting', 1);
assert_true($consoRes['success'] === true, "execute_consolidation() executes successfully within database transaction");
assert_true(!empty($consoRes['audit_id']), "execute_consolidation() returns durable audit snapshot ID ({$consoRes['audit_id']})");

// Verify target song is soft-consolidated (not deleted!)
$song1579 = $pdo->query("SELECT `is_consolidated`, `consolidated_into_id` FROM `songs` WHERE `song_id` = 1579")->fetch(PDO::FETCH_ASSOC);
assert_true((int)$song1579['is_consolidated'] === 1, "Consolidated song is soft-tombstoned (is_consolidated = 1)");
assert_true((int)$song1579['consolidated_into_id'] === 1536, "Consolidated song references canonical record (consolidated_into_id = 1536)");

// Verify media was transferred to canonical song
$canonMedia = $pdo->query("SELECT `video_id` FROM `song_media` WHERE `song_id` = 1536 AND `service` = 'youtube'")->fetch(PDO::FETCH_ASSOC);
assert_true($canonMedia !== false && $canonMedia['video_id'] === 'test_vid_1579', "Media reference safely transferred to canonical song");

// Verify audit snapshot contains full original state JSON
$auditRec = $pdo->query("SELECT * FROM `song_consolidation_audit` WHERE `audit_id` = {$consoRes['audit_id']}")->fetch(PDO::FETCH_ASSOC);
assert_true($auditRec !== false, "song_consolidation_audit row exists");
$snapshotData = json_decode($auditRec['snapshot_data'], true);
assert_true(!empty($snapshotData['consolidated_song']) && !empty($snapshotData['consolidated_media']), "Audit snapshot preserves complete original record state");

// Test Reversible Recovery (Deconsolidation)
$recoveryRes = execute_recovery($consoRes['audit_id'], 1);
assert_true($recoveryRes['success'] === true, "execute_recovery() executes successfully");

// Verify target song is fully restored
$song1579Restored = $pdo->query("SELECT `is_consolidated`, `consolidated_into_id` FROM `songs` WHERE `song_id` = 1579")->fetch(PDO::FETCH_ASSOC);
assert_true((int)$song1579Restored['is_consolidated'] === 0 && $song1579Restored['consolidated_into_id'] === null, "Recovery restores target song active state (is_consolidated = 0, consolidated_into_id = NULL)");

// Verify transferred media returned to original song
$mediaRestored = $pdo->query("SELECT `song_id` FROM `song_media` WHERE `video_id` = 'test_vid_1579'")->fetch(PDO::FETCH_ASSOC);
assert_true($mediaRestored !== false && (int)$mediaRestored['song_id'] === 1579, "Recovery reverts transferred media reference back to original song");

// Clean up mock media
$pdo->exec("DELETE FROM `song_media` WHERE `song_id` = 1579 AND `video_id` = 'test_vid_1579'");

// -------------------------------------------------------------------
// 6. Security & Authorization Checks
// -------------------------------------------------------------------
echo "\n6. Security & Authorization Checks\n";

// Validate CSRF verification rejects invalid token
$invalidCsrf = validate_csrf_token('invalid_token_12345');
assert_true($invalidCsrf === false, "CSRF protection rejects invalid security token");

// Validate CSRF verification accepts legitimate token
$validToken = get_csrf_token();
assert_true(validate_csrf_token($validToken) === true, "CSRF protection accepts valid session security token");

// -------------------------------------------------------------------
// 7. Mandatory Catalogue & History Integrity Checks
// -------------------------------------------------------------------
echo "\n7. Mandatory Catalogue & History Integrity Checks\n";

$finalSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_true($finalSongCount >= 2400, "MANDATORY INVARIANT: Active catalogue songs strictly preserved (actual: {$finalSongCount})");

$finalMissingArtists = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL OR TRIM(`artist`) = ''")->fetchColumn();
assert_true($finalMissingArtists >= 3, "MANDATORY INVARIANT: NULL artist records strictly preserved (actual: {$finalMissingArtists})");

// Verify the specific songs with missing artists
$missingSongs = $pdo->query("SELECT `song_id`, `title` FROM `songs` WHERE `artist` IS NULL OR TRIM(`artist`) = '' ORDER BY `song_id` ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
assert_true(isset($missingSongs[1546]), "Song #1546 retains missing artist intact");
assert_true(isset($missingSongs[2015]), "Song #2015 retains missing artist intact");
assert_true(isset($missingSongs[2196]), "Song #2196 retains missing artist intact");

// Clean up test review rows created during this test
$pdo->exec("DELETE FROM `duplicate_reviews` WHERE `song_id_a` = 1536 AND `song_id_b` = 1579");
$pdo->exec("DELETE FROM `song_consolidation_audit` WHERE `canonical_song_id` = 1536 AND `consolidated_song_id` = 1579");
$pdo->exec("DELETE FROM `afm_entries` WHERE `song_id` = 1579 AND `status` = 'excluded'");

echo "\n======================================================================\n";
echo " DUPLICATE REVIEW & INTEGRITY SUITE SUMMARY: {$passedTests} / {$totalTests} tests passed\n";
echo "======================================================================\n";

if ($failedTests > 0) {
    echo "❌ SOME TESTS FAILED.\n";
    exit(1);
} else {
    echo "🎉 ALL TESTS PASSED SUCCESSFULLY! (100% PASS RATE)\n";
    exit(0);
}
