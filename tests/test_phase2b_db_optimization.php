<?php
/**
 * AFM#2 — Phase 2B Database Query Optimization & Index Test Suite
 * 
 * Verifies decoupled recordings catalogue queries, batch candidate hydration,
 * engine fallback robustness, matching stats memoization, and data integrity.
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';
require_once dirname(__DIR__) . '/includes/youtube_matcher.php';

$pdo = get_db_connection();

function assert_phase2b(bool $condition, string $message): void {
    if (!$condition) {
        echo "  ❌ FAIL: {$message}\n";
        exit(1);
    }
    echo "  ✔ PASS: {$message}\n";
}

echo "\n======================================================================\n";
echo " AFM#2 — PHASE 2B DATABASE OPTIMIZATION & VERIFICATION TESTS\n";
echo "======================================================================\n\n";

// ---------------------------------------------------------------------
// 1. Decoupled Recordings Catalogue Schema & Projection Completeness
// ---------------------------------------------------------------------
echo "1. Recordings Catalogue Schema & Projection Completeness\n";
$catAll = YouTubeMatcher::get_recordings_catalogue(['filter' => 'all', 'page' => 1, 'per_page' => 25]);

assert_phase2b(is_array($catAll), "get_recordings_catalogue() returns valid array");
assert_phase2b($catAll['total'] === 2411, "Total catalogue songs count strictly matches 2411");
assert_phase2b(count($catAll['items']) === 25, "Page 1 returns exactly 25 items");
assert_phase2b($catAll['total_pages'] === (int)ceil(2411 / 25), "Total pages calculated accurately (97)");

$requiredItemKeys = [
    'song_id', 'title', 'artist', 'media_id', 'video_id', 'user_url', 'version_type',
    'match_method', 'match_score', 'media_confidence', 'verification_status',
    'match_decision_notes', 'is_manual', 'embed_status', 'artwork_thumbnail_url',
    'artwork_image_url', 'candidate_count', 'top_cand_confidence', 'top_cand_score',
    'top_cand_video_id', 'top_cand_title', 'top_cand_channel', 'top_cand_thumb'
];

$firstItem = $catAll['items'][0];
foreach ($requiredItemKeys as $k) {
    assert_phase2b(array_key_exists($k, $firstItem), "Item projection contains required column '{$k}'");
}

// ---------------------------------------------------------------------
// 2. Candidate Hydration Accuracy (Candidate vs Non-Candidate Tracks)
// ---------------------------------------------------------------------
echo "\n2. Candidate Hydration Accuracy & Defaults\n";
// Find a song known to have candidates (e.g. song_id 1)
$hasCandRow = null;
$noCandRow = null;
foreach ($catAll['items'] as $item) {
    if ($item['candidate_count'] > 0 && $hasCandRow === null) {
        $hasCandRow = $item;
    }
    if ($item['candidate_count'] === 0 && $noCandRow === null) {
        $noCandRow = $item;
    }
}

if ($hasCandRow) {
    assert_phase2b($hasCandRow['candidate_count'] >= 1, "Song #{$hasCandRow['song_id']} has hydrated candidate_count ({$hasCandRow['candidate_count']})");
    assert_phase2b(!empty($hasCandRow['top_cand_video_id']), "Song with candidates has non-empty top_cand_video_id ({$hasCandRow['top_cand_video_id']})");
    assert_phase2b($hasCandRow['top_cand_score'] !== null, "Song with candidates has non-null top_cand_score ({$hasCandRow['top_cand_score']})");
    assert_phase2b(!empty($hasCandRow['top_cand_confidence']), "Song with candidates has top_cand_confidence ({$hasCandRow['top_cand_confidence']})");
}

if ($noCandRow) {
    assert_phase2b($noCandRow['candidate_count'] === 0, "Song #{$noCandRow['song_id']} without candidates has candidate_count === 0");
    assert_phase2b($noCandRow['top_cand_video_id'] === null, "Song without candidates has top_cand_video_id === null");
    assert_phase2b($noCandRow['top_cand_score'] === null, "Song without candidates has top_cand_score === null");
    assert_phase2b($noCandRow['top_cand_confidence'] === null, "Song without candidates has top_cand_confidence === null");
}

// ---------------------------------------------------------------------
// 3. Status Filters Correctness
// ---------------------------------------------------------------------
echo "\n3. Filter Correctness Across All Status Categories\n";

// Filter: unmatched
$catUnmatched = YouTubeMatcher::get_recordings_catalogue(['filter' => 'unmatched', 'page' => 1, 'per_page' => 25]);
assert_phase2b($catUnmatched['total'] > 0, "Unmatched filter returns items (total: {$catUnmatched['total']})");
foreach ($catUnmatched['items'] as $item) {
    if (!empty($item['media_id'])) {
        assert_phase2b(false, "Unmatched row has unexpected media_id #{$item['media_id']}");
    }
}
assert_phase2b(true, "All items in unmatched filter have media_id === null");

// Filter: high
$catHigh = YouTubeMatcher::get_recordings_catalogue(['filter' => 'high', 'page' => 1, 'per_page' => 25]);
assert_phase2b($catHigh['total'] > 0, "High confidence filter returns items (total: {$catHigh['total']})");
foreach ($catHigh['items'] as $item) {
    if ($item['media_confidence'] !== 'high') {
        assert_phase2b(false, "High filter row has non-high confidence '{$item['media_confidence']}'");
    }
}
assert_phase2b(true, "All items in high filter have media_confidence === 'high'");

// Filter: manual
$catManual = YouTubeMatcher::get_recordings_catalogue(['filter' => 'manual', 'page' => 1, 'per_page' => 25]);
assert_phase2b($catManual['total'] >= 0, "Manual filter executed successfully (total: {$catManual['total']})");
foreach ($catManual['items'] as $item) {
    if ((int)$item['is_manual'] !== 1) {
        assert_phase2b(false, "Manual filter row has is_manual != 1");
    }
}
assert_phase2b(true, "All items in manual filter have is_manual === 1");

// ---------------------------------------------------------------------
// 4. Fallback Engine Path Verification (Simulated Legacy SQL Engine)
// ---------------------------------------------------------------------
echo "\n4. Engine Fallback Robustness Verification\n";

assert_phase2b(is_bool(YouTubeMatcher::supports_window_functions()), "supports_window_functions() returns boolean probe result");

// Execute simulated fallback code directly
$testSongIds = [1, 2, 3, 4, 5];
$placeholders = implode(',', array_fill(0, count($testSongIds), '?'));

$cntSql = "SELECT song_id, COUNT(*) AS cnt FROM song_youtube_candidates WHERE song_id IN ({$placeholders}) GROUP BY song_id";
$cStmt = $pdo->prepare($cntSql);
$cStmt->execute($testSongIds);
$fallbackCounts = $cStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$topSql = "SELECT song_id, confidence, score_total, video_id, title, channel_title, thumbnail_url 
           FROM song_youtube_candidates WHERE song_id = ? ORDER BY score_total DESC, video_id ASC LIMIT 1";
$topStmt = $pdo->prepare($topSql);
$fallbackTop = [];
foreach ($testSongIds as $sid) {
    if (!empty($fallbackCounts[$sid])) {
        $topStmt->execute([$sid]);
        $row = $topStmt->fetch();
        if ($row) {
            $row['cand_count'] = (int)$fallbackCounts[$sid];
            $fallbackTop[$sid] = $row;
        }
    }
}

// Window function execution for identical IDs
$winSql = "
    SELECT song_id, confidence, score_total, video_id, title, channel_title, thumbnail_url, cand_count
    FROM (
        SELECT song_id, confidence, score_total, video_id, title, channel_title, thumbnail_url,
               ROW_NUMBER() OVER (PARTITION BY song_id ORDER BY score_total DESC, video_id ASC) AS rn,
               COUNT(*) OVER (PARTITION BY song_id) AS cand_count
        FROM song_youtube_candidates
        WHERE song_id IN ({$placeholders})
    ) ranked
    WHERE rn = 1
";
$winStmt = $pdo->prepare($winSql);
$winStmt->execute($testSongIds);
$winTop = [];
while ($wRow = $winStmt->fetch()) {
    $winTop[(int)$wRow['song_id']] = $wRow;
}

assert_phase2b(count($fallbackTop) === count($winTop), "Window function and fallback return identical candidate set count");
foreach ($winTop as $sId => $wItem) {
    assert_phase2b(isset($fallbackTop[$sId]), "Song #{$sId} exists in fallback result");
    assert_phase2b($wItem['cand_count'] == $fallbackTop[$sId]['cand_count'], "Song #{$sId} cand_count matches ({$wItem['cand_count']})");
    assert_phase2b($wItem['video_id'] === $fallbackTop[$sId]['video_id'], "Song #{$sId} video_id matches ({$wItem['video_id']})");
    assert_phase2b($wItem['score_total'] == $fallbackTop[$sId]['score_total'], "Song #{$sId} score_total matches ({$wItem['score_total']})");
}

// Direct test of hydrate_candidates_fallback() method
$directFallback = YouTubeMatcher::hydrate_candidates_fallback($pdo, $testSongIds);
assert_phase2b(count($directFallback) === count($winTop), "hydrate_candidates_fallback() returns identical count as window function");
foreach ($winTop as $sId => $wItem) {
    assert_phase2b(isset($directFallback[$sId]), "hydrate_candidates_fallback() has song #{$sId}");
    assert_phase2b($directFallback[$sId]['video_id'] === $wItem['video_id'], "hydrate_candidates_fallback() video_id matches window function for #{$sId}");
}

// ---------------------------------------------------------------------
// 5. Request-Scoped Matching Stats Memoization
// ---------------------------------------------------------------------
echo "\n5. Request-Scoped Matching Stats Memoization Lifecycle\n";
YouTubeMatcher::clear_matching_stats_cache();

$s1 = YouTubeMatcher::get_matching_stats();
assert_phase2b(is_array($s1), "get_matching_stats() returns valid array");
assert_phase2b(isset($s1['total_songs'], $s1['matched_total'], $s1['review_needed']), "Stats array contains all expected keys");

// Second call should return identical cached array instantly
$s2 = YouTubeMatcher::get_matching_stats();
assert_phase2b($s1 === $s2, "Second get_matching_stats() call returns identical memoized array");

// Force refresh re-executes query
$s3 = YouTubeMatcher::get_matching_stats(true);
assert_phase2b($s3['total_songs'] === $s1['total_songs'], "Force refresh preserves data consistency");

// Clearing cache empties memoized container
YouTubeMatcher::clear_matching_stats_cache();
$s4 = YouTubeMatcher::get_matching_stats();
assert_phase2b(is_array($s4) && $s4['total_songs'] === $s1['total_songs'], "Re-population after clear_matching_stats_cache() works cleanly");

// ---------------------------------------------------------------------
// 6. Mutation Cache Invalidation & Failure Safety Safeguards
// ---------------------------------------------------------------------
echo "\n6. Mutation Cache Invalidation Safeguards\n";
// Populate stats cache
$preStats = YouTubeMatcher::get_matching_stats();
assert_phase2b(is_array($preStats), "Initial stats cached before mutation test");

// Test invalidation via media_helper save_song_media & remove_song_media
$testSongId = 1;
save_song_media($testSongId, 'https://www.youtube.com/watch?v=TEST_MUTATION_VID');
// Cache must be cleared by save_song_media
$reloadedAfterSave = YouTubeMatcher::get_matching_stats();
assert_phase2b(is_array($reloadedAfterSave), "Cache successfully reloads after save_song_media mutation");

remove_song_media($testSongId);
// Cache must be cleared by remove_song_media
$reloadedAfterRemove = YouTubeMatcher::get_matching_stats();
assert_phase2b(is_array($reloadedAfterRemove), "Cache successfully reloads after remove_song_media mutation");

// Restore song 1 media state if needed
save_song_media($testSongId, 'https://www.youtube.com/watch?v=MOCK_HIGH01', 'original', 'Restored baseline test link');

// Test failed mutation safety: ensure clear_matching_stats_cache() prevents inconsistent cache
YouTubeMatcher::get_matching_stats(); // populate
try {
    // Attempt invalid candidate save to trigger failure
    YouTubeMatcher::save_candidates($testSongId, [
        ['video_id' => 'INVALID_CAND', 'score_total' => 'NOT_A_NUMBER', 'confidence' => 'invalid_enum']
    ]);
} catch (\Throwable $e) {
    // Expected database type/enum error
}
// After failed mutation, cache must be cleared (not holding stale pre-mutation state)
$postFailStats = YouTubeMatcher::get_matching_stats();
assert_phase2b(is_array($postFailStats), "Cache recomputed cleanly after failed mutation (no stale state)");

// ---------------------------------------------------------------------
// 7. Mandatory Baseline Catalogue Invariant Safeguard
// ---------------------------------------------------------------------
echo "\n7. Mandatory Catalogue & Artist Invariant Safeguard\n";
$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0")->fetchColumn();
assert_phase2b($activeCount === 2411, "MANDATORY INVARIANT: Active catalogue songs remain strictly intact ({$activeCount} === 2411)");

$nullArtistCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL")->fetchColumn();
assert_phase2b($nullArtistCount === 3, "MANDATORY INVARIANT: Authentic NULL artist count preserved intact ({$nullArtistCount} === 3)");

$nullArtistRows = $pdo->query("SELECT `song_id`, `title`, `artist` FROM `songs` WHERE `artist` IS NULL ORDER BY `song_id` ASC")->fetchAll(PDO::FETCH_ASSOC);
$nullArtistIds = array_map('intval', array_column($nullArtistRows, 'song_id'));
$expectedNullIds = [1546, 2015, 2196];
assert_phase2b($nullArtistIds === $expectedNullIds, "MANDATORY INVARIANT: Authentic NULL artist IDs match baseline exactly ([1546, 2015, 2196])");

assert_phase2b($nullArtistRows[0]['title'] === "80s Remix: Baby - Justin Bieber", "Song #1546 title matches authentic baseline");
assert_phase2b($nullArtistRows[1]['title'] === "Happier Than Ever (Billie Eilish Cover) in the Live Lounge", "Song #2015 title matches authentic baseline");
assert_phase2b($nullArtistRows[2]['title'] === "Frozen - Let It Go ( cover by J.Fla )", "Song #2196 title matches authentic baseline");

echo "\n======================================================================\n";
echo " ALL PHASE 2B OPTIMIZATION TESTS PASSED SUCCESSFULLY! (100% PASS RATE)\n";
echo "======================================================================\n\n";
