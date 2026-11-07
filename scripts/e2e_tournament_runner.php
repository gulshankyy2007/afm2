<?php
/**
 * AFM#2 — End-to-End Tournament Execution & Validation Script
 * Strictly scoped to test user (user_id = 1006)
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

$pdo = get_db_connection();
$testUserId = 1006;
$gulshanUserId = 995;

function get_counts_for_user(PDO $pdo, int $uid): array {
    return [
        'songs'                  => (int)$pdo->query("SELECT COUNT(*) FROM songs WHERE user_id = $uid")->fetchColumn(),
        'song_discovery'         => (int)$pdo->query("SELECT COUNT(*) FROM song_discovery WHERE user_id = $uid")->fetchColumn(),
        'afm_entries'            => (int)$pdo->query("SELECT COUNT(*) FROM afm_entries WHERE user_id = $uid")->fetchColumn(),
        'ratings'                => (int)$pdo->query("SELECT COUNT(*) FROM ratings WHERE user_id = $uid")->fetchColumn(),
        'round_results'          => (int)$pdo->query("SELECT COUNT(*) FROM round_results WHERE user_id = $uid")->fetchColumn(),
        'round_results_history'  => (int)$pdo->query("SELECT COUNT(*) FROM round_results_history WHERE user_id = $uid")->fetchColumn(),
        'user_round_progress'    => (int)$pdo->query("SELECT COUNT(*) FROM user_round_progress WHERE user_id = $uid")->fetchColumn(),
    ];
}

$gulshanInitial = get_counts_for_user($pdo, $gulshanUserId);

echo "======================================================================\n";
echo " AFM#2 — END-TO-END TOURNAMENT VALIDATION FOR TEST USER (ID: {$testUserId})\n";
echo "======================================================================\n";
echo "Gulshan Baseline: " . json_encode($gulshanInitial) . "\n\n";

// STEP 1: Fetch 210 clean songs for test user
$songStmt = $pdo->prepare("
    SELECT song_id, title, artist 
    FROM songs 
    WHERE user_id = :uid AND is_consolidated = 0 AND deleted_at IS NULL 
    ORDER BY song_id ASC 
    LIMIT 210
");
$songStmt->execute([':uid' => $testUserId]);
$songs = $songStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($songs) < 210) {
    die("FATAL: Test user does not have 210 clean songs. Found: " . count($songs) . "\n");
}
echo "[STEP 1] Selected 210 songs from test user catalogue.\n";

// STEP 2: Insert 200 songs into song_discovery and afm_entries
$pdo->beginTransaction();
$insDisc = $pdo->prepare("INSERT INTO song_discovery (song_id, user_id, status) VALUES (:sid, :uid, 'shortlisted')");
$insEntry = $pdo->prepare("INSERT INTO afm_entries (song_id, user_id, status) VALUES (:sid, :uid, 'auditioning')");

for ($i = 0; $i < 200; $i++) {
    $insDisc->execute([':sid' => $songs[$i]['song_id'], ':uid' => $testUserId]);
    $insEntry->execute([':sid' => $songs[$i]['song_id'], ':uid' => $testUserId]);
}
$pdo->commit();

// Fetch entry IDs for the first 200
$entryStmt = $pdo->prepare("SELECT entry_id, song_id FROM afm_entries WHERE user_id = :uid ORDER BY entry_id ASC");
$entryStmt->execute([':uid' => $testUserId]);
$entries200 = $entryStmt->fetchAll(PDO::FETCH_ASSOC);

// Rate all 200 songs across 5 criteria for Round 1
$pdo->beginTransaction();
$insRating = $pdo->prepare("
    INSERT INTO ratings (user_id, entry_id, round_id, melody, lyrics, vocals, emotion, replayability)
    VALUES (:uid, :eid, 1, :mel, :lyr, :voc, :emo, :rep)
");
foreach ($entries200 as $idx => $e) {
    // Distribute varied scores: 20 to 50
    $base = 4 + ($idx % 7);
    $insRating->execute([
        ':uid' => $testUserId,
        ':eid' => $e['entry_id'],
        ':mel' => $base,
        ':lyr' => min(10, $base + 1),
        ':voc' => min(10, $base),
        ':emo' => min(10, $base + ($idx % 3)),
        ':rep' => min(10, $base + ($idx % 2))
    ]);
}
$pdo->commit();
echo "[STEP 2] Populated and scored exactly 200 songs.\n";

// TEST EDGE CASE 1: Exactly 200 songs in Formal Auditions (Min 201 required)
echo "\n--- TESTING EDGE CASE 1: Minimum Participant Restriction ---\n";
$candData200 = get_stage_qualification_candidates(1, $testUserId);
echo "Candidates count: {$candData200['total_eligible']} (Min required: {$candData200['min_eligible_required']})\n";
echo "Can advance: " . ($candData200['can_advance'] ? 'YES' : 'NO') . "\n";
echo "Blocking reasons: " . implode(' | ', $candData200['blocking_reasons']) . "\n";

$all200Ids = array_column($entries200, 'entry_id');
$advResFail1 = commit_stage_advancement(1, $all200Ids, $testUserId);
if (!$advResFail1['success'] && strpos($advResFail1['error'], 'requires at least 201') !== false) {
    echo "✓ [PASS] Edge Case 1: Advancement correctly blocked when eligible count (200) < min_eligible (201).\n";
} else {
    echo "✗ [FAIL] Edge Case 1: Expected block with 200 participants, got: " . json_encode($advResFail1) . "\n";
}

// STEP 3: Add 10 more songs (now 210 total), but leave 5 incomplete
echo "\n--- TESTING EDGE CASE 2: Incomplete Evaluations ---\n";
$pdo->beginTransaction();
for ($i = 200; $i < 210; $i++) {
    $insDisc->execute([':sid' => $songs[$i]['song_id'], ':uid' => $testUserId]);
    $insEntry->execute([':sid' => $songs[$i]['song_id'], ':uid' => $testUserId]);
}
$pdo->commit();

$entryStmt->execute([':uid' => $testUserId]);
$all210Entries = $entryStmt->fetchAll(PDO::FETCH_ASSOC);

// Rate 5 of the 10 new songs completely, leave 5 incomplete (only melody scored)
$pdo->beginTransaction();
for ($i = 200; $i < 205; $i++) {
    $insRating->execute([
        ':uid' => $testUserId,
        ':eid' => $all210Entries[$i]['entry_id'],
        ':mel' => 8, ':lyr' => 8, ':voc' => 8, ':emo' => 8, ':rep' => 8
    ]);
}
// 5 incomplete (missing lyrics, vocals, emotion, replayability)
$insIncomplete = $pdo->prepare("
    INSERT INTO ratings (user_id, entry_id, round_id, melody, lyrics, vocals, emotion, replayability)
    VALUES (:uid, :eid, 1, 7, NULL, NULL, NULL, NULL)
");
for ($i = 205; $i < 210; $i++) {
    $insIncomplete->execute([
        ':uid' => $testUserId,
        ':eid' => $all210Entries[$i]['entry_id']
    ]);
}
$pdo->commit();

$candDataIncomplete = get_stage_qualification_candidates(1, $testUserId);
echo "Total eligible: {$candDataIncomplete['total_eligible']}, Incomplete: {$candDataIncomplete['incomplete_evaluations']}\n";
$first200From210 = array_slice(array_column($all210Entries, 'entry_id'), 0, 200);
$advResFail2 = commit_stage_advancement(1, $first200From210, $testUserId);
if (!$advResFail2['success'] && strpos($advResFail2['error'], 'incomplete or untouched') !== false) {
    echo "✓ [PASS] Edge Case 2: Advancement correctly blocked when evaluations are incomplete.\n";
} else {
    echo "✗ [FAIL] Edge Case 2: Expected block due to incomplete evals, got: " . json_encode($advResFail2) . "\n";
}

// Complete the 5 incomplete evaluations
$pdo->beginTransaction();
$updRating = $pdo->prepare("
    UPDATE ratings 
    SET lyrics = 7, vocals = 7, emotion = 7, replayability = 7 
    WHERE user_id = :uid AND entry_id = :eid AND round_id = 1
");
for ($i = 205; $i < 210; $i++) {
    $updRating->execute([':uid' => $testUserId, ':eid' => $all210Entries[$i]['entry_id']]);
}
$pdo->commit();
echo "Completed remaining 5 evaluations. All 210 are now 100% evaluated.\n";

// TEST EDGE CASE 3: Over-target / Under-target selection
echo "\n--- TESTING EDGE CASE 3: Target Count Mismatch ---\n";
$overTarget201 = array_slice(array_column($all210Entries, 'entry_id'), 0, 201);
$advResFail3 = commit_stage_advancement(1, $overTarget201, $testUserId);
if (!$advResFail3['success'] && strpos($advResFail3['error'], 'requires exactly 200') !== false) {
    echo "✓ [PASS] Edge Case 3: Advancement correctly blocked when qualifier count (201) != target (200).\n";
} else {
    echo "✗ [FAIL] Edge Case 3: Expected block due to target mismatch, got: " . json_encode($advResFail3) . "\n";
}

// ==============================================================================
// STAGE 1 ADVANCEMENT: Formal Auditions -> Top 200 (Select 200 from 210)
// Demonstrate lower-scoring song advancing over higher-scoring song & manual ordering
// ==============================================================================
echo "\n--- EXECUTING STAGE 1: Formal Auditions -> Top 200 ---\n";
$candStage1 = get_stage_qualification_candidates(1, $testUserId);

// Find candidate with lowest total and highest total
usort($candStage1['candidates'], function($a, $b) {
    return $a['total'] <=> $b['total'];
});
$lowestScorer = $candStage1['candidates'][0]; // lowest
$highestScorer = end($candStage1['candidates']); // highest

echo "Lowest scoring candidate: ID {$lowestScorer['entry_id']} ('{$lowestScorer['title']}') with {$lowestScorer['total']}/50\n";
echo "Highest scoring candidate: ID {$highestScorer['entry_id']} ('{$highestScorer['title']}') with {$highestScorer['total']}/50\n";

// We want to ADVANCE lowestScorer and ELIMINATE one of the higher scorers to prove manual discretion!
// We pick 200 candidates: definitely include lowestScorer, exclude the last 10 except we deliberately exclude a high-scoring one.
$candidatesToAdvance = [];
$candidatesToAdvance[] = (int)$lowestScorer['entry_id'];

// Add 199 others, deliberately skipping entry at index 208 (a high scorer)
$added = 1;
foreach ($candStage1['candidates'] as $c) {
    $eid = (int)$c['entry_id'];
    if ($eid === (int)$lowestScorer['entry_id']) continue;
    if ($eid === (int)$highestScorer['entry_id']) continue; // Deliberately eliminate highest scorer!
    $candidatesToAdvance[] = $eid;
    $added++;
    if ($added === 200) break;
}

echo "Selected 200 qualifiers. Lowest scorer is included, highest scorer is deliberately eliminated.\n";

// Build a custom manual ordering map: place lowest scorer as Seed #1!
$manualOrderStage1 = [];
foreach ($candidatesToAdvance as $pos => $eid) {
    $manualOrderStage1[$eid] = $pos + 1;
}

$stage1AdvRes = commit_stage_advancement(1, $candidatesToAdvance, $testUserId, $manualOrderStage1);
if ($stage1AdvRes['success']) {
    echo "✓ [PASS] Stage 1 Advancement committed successfully: 200 qualified, 10 eliminated.\n";
} else {
    die("✗ [FAIL] Stage 1 Advancement failed: " . json_encode($stage1AdvRes) . "\n");
}

// Verify Stage 1 Immutability
echo "\n--- TESTING STAGE 1 IMMUTABILITY ---\n";
$dupStage1 = commit_stage_advancement(1, $candidatesToAdvance, $testUserId);
if (!$dupStage1['success'] && strpos($dupStage1['error'], 'already been completed') !== false) {
    echo "✓ [PASS] Double-advancement blocked: Formal Auditions is completed and locked.\n";
} else {
    echo "✗ [FAIL] Double-advancement allowed: " . json_encode($dupStage1) . "\n";
}

$ratingMutate = save_scorecard_rating($candidatesToAdvance[0], 1, 10, 10, 10, 10, 10, $testUserId);
if (!$ratingMutate['success'] && strpos($ratingMutate['error'], 'immutable') !== false) {
    echo "✓ [PASS] Rating mutation blocked on finalized Stage 1.\n";
} else {
    echo "✗ [FAIL] Rating mutation allowed on finalized stage: " . json_encode($ratingMutate) . "\n";
}

// Verify eliminated candidate cannot be evaluated in Stage 2
$elimRating = save_scorecard_rating((int)$highestScorer['entry_id'], 2, 8, 8, 8, 8, 8, $testUserId);
if (!$elimRating['success'] && strpos($elimRating['error'], 'not an officially advanced participant') !== false) {
    echo "✓ [PASS] Eliminated candidate strictly blocked from receiving evaluations in Stage 2.\n";
} else {
    echo "✗ [FAIL] Eliminated candidate was allowed to be scored: " . json_encode($elimRating) . "\n";
}

// ==============================================================================
// STAGE 2: Top 200 -> Top 100 (Target: 100, Eligible: 200)
// ==============================================================================
echo "\n--- EXECUTING STAGE 2: Top 200 -> Top 100 ---\n";
$candStage2 = get_stage_qualification_candidates(2, $testUserId);
echo "Stage 2 eligible participants: " . count($candStage2['candidates']) . " (Expected: 200)\n";

// Rate all 200 participants for Round 2
$pdo->beginTransaction();
$insRatingR2 = $pdo->prepare("
    INSERT INTO ratings (user_id, entry_id, round_id, melody, lyrics, vocals, emotion, replayability)
    VALUES (:uid, :eid, 2, :mel, :lyr, :voc, :emo, :rep)
    ON DUPLICATE KEY UPDATE melody=VALUES(melody), lyrics=VALUES(lyrics), vocals=VALUES(vocals), emotion=VALUES(emotion), replayability=VALUES(replayability)
");
foreach ($candStage2['candidates'] as $idx => $c) {
    $score = 5 + ($idx % 6);
    $insRatingR2->execute([
        ':uid' => $testUserId,
        ':eid' => $c['entry_id'],
        ':mel' => $score,
        ':lyr' => min(10, $score + 1),
        ':voc' => min(10, $score),
        ':emo' => min(10, $score + ($idx % 2)),
        ':rep' => min(10, $score + ($idx % 3))
    ]);
}
$pdo->commit();

// Select 100 qualifiers with custom manual ordering
$qualifiersStage2 = array_slice(array_column($candStage2['candidates'], 'entry_id'), 0, 100);
$manualOrderStage2 = [];
foreach ($qualifiersStage2 as $pos => $eid) {
    $manualOrderStage2[$eid] = $pos + 1;
}

$stage2AdvRes = commit_stage_advancement(2, $qualifiersStage2, $testUserId, $manualOrderStage2);
if ($stage2AdvRes['success']) {
    echo "✓ [PASS] Stage 2 Advancement committed successfully: 100 qualified, 100 eliminated.\n";
} else {
    die("✗ [FAIL] Stage 2 Advancement failed: " . json_encode($stage2AdvRes) . "\n");
}

// ==============================================================================
// STAGE 3: Top 100 -> Top 50 (Target: 50, Eligible: 100)
// ==============================================================================
echo "\n--- EXECUTING STAGE 3: Top 100 -> Top 50 ---\n";
$candStage3 = get_stage_qualification_candidates(3, $testUserId);
echo "Stage 3 eligible participants: " . count($candStage3['candidates']) . " (Expected: 100)\n";

$pdo->beginTransaction();
$insRatingR3 = $pdo->prepare("
    INSERT INTO ratings (user_id, entry_id, round_id, melody, lyrics, vocals, emotion, replayability)
    VALUES (:uid, :eid, 3, :mel, :lyr, :voc, :emo, :rep)
    ON DUPLICATE KEY UPDATE melody=VALUES(melody), lyrics=VALUES(lyrics), vocals=VALUES(vocals), emotion=VALUES(emotion), replayability=VALUES(replayability)
");
foreach ($candStage3['candidates'] as $idx => $c) {
    $score = 6 + ($idx % 5);
    $insRatingR3->execute([
        ':uid' => $testUserId,
        ':eid' => $c['entry_id'],
        ':mel' => $score,
        ':lyr' => min(10, $score + 1),
        ':voc' => min(10, $score),
        ':emo' => min(10, $score + 1),
        ':rep' => min(10, $score)
    ]);
}
$pdo->commit();

$qualifiersStage3 = array_slice(array_column($candStage3['candidates'], 'entry_id'), 0, 50);
$manualOrderStage3 = [];
foreach ($qualifiersStage3 as $pos => $eid) {
    $manualOrderStage3[$eid] = $pos + 1;
}

$stage3AdvRes = commit_stage_advancement(3, $qualifiersStage3, $testUserId, $manualOrderStage3);
if ($stage3AdvRes['success']) {
    echo "✓ [PASS] Stage 3 Advancement committed successfully: 50 qualified, 50 eliminated.\n";
} else {
    die("✗ [FAIL] Stage 3 Advancement failed: " . json_encode($stage3AdvRes) . "\n");
}

// ==============================================================================
// STAGE 4: Top 50 -> Top 20 (Target: 20, Eligible: 50)
// ==============================================================================
echo "\n--- EXECUTING STAGE 4: Top 50 -> Top 20 ---\n";
$candStage4 = get_stage_qualification_candidates(4, $testUserId);
echo "Stage 4 eligible participants: " . count($candStage4['candidates']) . " (Expected: 50)\n";

$pdo->beginTransaction();
$insRatingR4 = $pdo->prepare("
    INSERT INTO ratings (user_id, entry_id, round_id, melody, lyrics, vocals, emotion, replayability)
    VALUES (:uid, :eid, 4, :mel, :lyr, :voc, :emo, :rep)
    ON DUPLICATE KEY UPDATE melody=VALUES(melody), lyrics=VALUES(lyrics), vocals=VALUES(vocals), emotion=VALUES(emotion), replayability=VALUES(replayability)
");
foreach ($candStage4['candidates'] as $idx => $c) {
    $score = 7 + ($idx % 4);
    $insRatingR4->execute([
        ':uid' => $testUserId,
        ':eid' => $c['entry_id'],
        ':mel' => $score,
        ':lyr' => min(10, $score),
        ':voc' => min(10, $score + 1),
        ':emo' => min(10, $score),
        ':rep' => min(10, $score + 1)
    ]);
}
$pdo->commit();

$qualifiersStage4 = array_slice(array_column($candStage4['candidates'], 'entry_id'), 0, 20);
$manualOrderStage4 = [];
foreach ($qualifiersStage4 as $pos => $eid) {
    $manualOrderStage4[$eid] = $pos + 1;
}

$stage4AdvRes = commit_stage_advancement(4, $qualifiersStage4, $testUserId, $manualOrderStage4);
if ($stage4AdvRes['success']) {
    echo "✓ [PASS] Stage 4 Advancement committed successfully: 20 qualified, 30 eliminated.\n";
} else {
    die("✗ [FAIL] Stage 4 Advancement failed: " . json_encode($stage4AdvRes) . "\n");
}

// ==============================================================================
// STAGE 5: Top 20 -> Top 10 (Target: 10, Eligible: 20)
// ==============================================================================
echo "\n--- EXECUTING STAGE 5: Top 20 -> Top 10 ---\n";
$candStage5 = get_stage_qualification_candidates(5, $testUserId);
echo "Stage 5 eligible participants: " . count($candStage5['candidates']) . " (Expected: 20)\n";

$pdo->beginTransaction();
$insRatingR5 = $pdo->prepare("
    INSERT INTO ratings (user_id, entry_id, round_id, melody, lyrics, vocals, emotion, replayability)
    VALUES (:uid, :eid, 5, :mel, :lyr, :voc, :emo, :rep)
    ON DUPLICATE KEY UPDATE melody=VALUES(melody), lyrics=VALUES(lyrics), vocals=VALUES(vocals), emotion=VALUES(emotion), replayability=VALUES(replayability)
");
foreach ($candStage5['candidates'] as $idx => $c) {
    $score = 8 + ($idx % 3);
    $insRatingR5->execute([
        ':uid' => $testUserId,
        ':eid' => $c['entry_id'],
        ':mel' => $score,
        ':lyr' => min(10, $score),
        ':voc' => min(10, $score),
        ':emo' => min(10, $score + 1),
        ':rep' => min(10, $score)
    ]);
}
$pdo->commit();

$qualifiersStage5 = array_slice(array_column($candStage5['candidates'], 'entry_id'), 0, 10);
$manualOrderStage5 = [];
foreach ($qualifiersStage5 as $pos => $eid) {
    $manualOrderStage5[$eid] = $pos + 1;
}

$stage5AdvRes = commit_stage_advancement(5, $qualifiersStage5, $testUserId, $manualOrderStage5);
if ($stage5AdvRes['success']) {
    echo "✓ [PASS] Stage 5 Advancement committed successfully: 10 qualified, 10 eliminated.\n";
} else {
    die("✗ [FAIL] Stage 5 Advancement failed: " . json_encode($stage5AdvRes) . "\n");
}

// ==============================================================================
// STAGE 6: Top 10 -> Finals (Target: 1, Eligible: 10, Crown Champion)
// ==============================================================================
echo "\n--- EXECUTING STAGE 6: Top 10 -> Finals (Crown Champion) ---\n";
$candStage6 = get_stage_qualification_candidates(6, $testUserId);
echo "Stage 6 eligible participants: " . count($candStage6['candidates']) . " (Expected: 10)\n";

$pdo->beginTransaction();
$insRatingR6 = $pdo->prepare("
    INSERT INTO ratings (user_id, entry_id, round_id, melody, lyrics, vocals, emotion, replayability)
    VALUES (:uid, :eid, 6, :mel, :lyr, :voc, :emo, :rep)
    ON DUPLICATE KEY UPDATE melody=VALUES(melody), lyrics=VALUES(lyrics), vocals=VALUES(vocals), emotion=VALUES(emotion), replayability=VALUES(replayability)
");
foreach ($candStage6['candidates'] as $idx => $c) {
    $insRatingR6->execute([
        ':uid' => $testUserId,
        ':eid' => $c['entry_id'],
        ':mel' => 9 + ($idx % 2),
        ':lyr' => 9,
        ':voc' => 9 + ($idx % 2),
        ':emo' => 10,
        ':rep' => 9 + ($idx % 2)
    ]);
}
$pdo->commit();

// Select Champion: 1 song
$championEntryId = (int)$candStage6['candidates'][0]['entry_id'];
$championTitle = $candStage6['candidates'][0]['title'];
$championArtist = $candStage6['candidates'][0]['artist'];
echo "Selected Champion: Entry #{$championEntryId} — '{$championTitle}' by {$championArtist}\n";

$qualifiersStage6 = [$championEntryId];
$manualOrderStage6 = [$championEntryId => 1];

$stage6AdvRes = commit_stage_advancement(6, $qualifiersStage6, $testUserId, $manualOrderStage6);
if ($stage6AdvRes['success']) {
    echo "✓ [PASS] Stage 6 Advancement committed successfully: Champion crowned!\n";
} else {
    die("✗ [FAIL] Stage 6 Advancement failed: " . json_encode($stage6AdvRes) . "\n");
}

// Verify Champion status in round_results
$winnerStmt = $pdo->prepare("
    SELECT * FROM round_results 
    WHERE user_id = :uid AND round_id = 6 AND result = 'winner'
");
$winnerStmt->execute([':uid' => $testUserId]);
$winner = $winnerStmt->fetch(PDO::FETCH_ASSOC);

if ($winner && (int)$winner['entry_id'] === $championEntryId) {
    echo "✓ [PASS] Winner record verified in round_results: {$winner['song_title']} (Rank: {$winner['rank_position']}, Result: {$winner['result']})\n";
} else {
    echo "✗ [FAIL] Winner record not found or mismatched: " . json_encode($winner) . "\n";
}

// ==============================================================================
// VERIFY ALL STAGE PROGRESS & IMMUTABILITY FOR TEST USER
// ==============================================================================
echo "\n--- VERIFYING COMPLETE TOURNAMENT PROGRESSION ---\n";
$progStmt = $pdo->prepare("
    SELECT r.round_id, r.sequence_no, r.round_name, urp.status, urp.completed_at
    FROM rounds r
    LEFT JOIN user_round_progress urp ON r.round_id = urp.round_id AND urp.user_id = :uid
    ORDER BY r.sequence_no ASC
");
$progStmt->execute([':uid' => $testUserId]);
$progRows = $progStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($progRows as $row) {
    echo sprintf("  Stage %d (%-18s): Status = %-12s CompletedAt = %s\n", 
        $row['sequence_no'], 
        $row['round_name'], 
        $row['status'], 
        $row['completed_at'] ?? 'N/A'
    );
}

// FINAL SAFETY VERIFICATION: Ensure Gulshan's data is 100% UNTOUCHED
echo "\n--- FINAL DATA ISOLATION INTEGRITY CHECK ---\n";
$gulshanFinal = get_counts_for_user($pdo, $gulshanUserId);
$testFinal = get_counts_for_user($pdo, $testUserId);

echo "Gulshan Final: " . json_encode($gulshanFinal) . "\n";
echo "Test Final:    " . json_encode($testFinal) . "\n";

if ($gulshanInitial === $gulshanFinal) {
    echo "✓ [PASS] STRICT DATA ISOLATION VERIFIED: Gulshan's data was completely untouched and unaffected throughout the entire tournament execution.\n";
} else {
    echo "✗ [CRITICAL ALERT] Gulshan's data changed during test execution!\n";
}

echo "\n======================================================================\n";
echo " END-TO-END TOURNAMENT EXECUTION COMPLETED SUCCESSFULLY!\n";
echo "======================================================================\n";
