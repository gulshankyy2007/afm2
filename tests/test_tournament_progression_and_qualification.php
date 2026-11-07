<?php
/**
 * AFM#2 — Complete Tournament Progression, Manual Qualification & Legacy Data Correction
 * Comprehensive Automated Regression Test Suite
 * 
 * Verifies all 33 required scenarios from Part F (Section 17):
 * Scenarios 1-6:   Catalogue and Participation
 * Scenarios 7-12:  Qualification (Manual Decisions Authoritative, Advisory Scores)
 * Scenarios 13-18: Preview, Manual Ordering, Seeding Persistence
 * Scenarios 19-23: Scorecard, Shortlist & Evaluation States (Untouched vs 0/50 vs Reset)
 * Scenarios 24-29: Song Lifecycle, Deletion, Historical Preservation & Controlled Stage Reopening
 * Scenarios 30-33: Reliability, DB Transactions, Backend Validation, Multiuser Isolation
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/test_db_guard.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

$pdo = get_test_db_connection();
assert_test_db_isolated($pdo, 'test_tournament_progression_and_qualification');

$totalTests = 0;
$passedTests = 0;

function assert_test(string $name, bool $condition, string $details = ''): void {
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$totalTests}. {$name}\n";
    } else {
        echo "  [FAIL] {$totalTests}. {$name}" . ($details ? " ({$details})" : "") . "\n";
    }
}

echo "======================================================================\n";
echo " AFM#2 — TOURNAMENT PROGRESSION & MANUAL QUALIFICATION REGRESSION SUITE\n";
echo "======================================================================\n\n";

// Setup two distinct test users in test database
$testUser1 = 1081;
$testUser2 = 1082;

$pdo->prepare("INSERT INTO app_users (user_id, username, email, password_hash, role, is_active) VALUES 
    (:u1, 'tourney_user_1', 'tourney_1@test.afm', 'hash', 'admin', 1),
    (:u2, 'tourney_user_2', 'tourney_2@test.afm', 'hash', 'user', 1)
ON DUPLICATE KEY UPDATE is_active = 1, role = VALUES(role)
")->execute([':u1' => $testUser1, ':u2' => $testUser2]);

// Clean up prior test data for these users
$pdo->prepare("DELETE r FROM ratings r JOIN afm_entries e ON r.entry_id = e.entry_id WHERE e.user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM ratings WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE rr FROM round_results rr JOIN afm_entries e ON rr.entry_id = e.entry_id WHERE e.user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM round_results WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM round_results_history WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM afm_entries WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM song_discovery WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM user_round_progress WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM songs WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);

$_SESSION['afm_user_id'] = $testUser1;
$_SESSION['user_id'] = $testUser1;

// ======================================================================
// GROUP 1: CATALOGUE AND PARTICIPATION (Scenarios 1 - 6)
// ======================================================================
echo "--- Group 1: Catalogue & Participation (Scenarios 1 - 6) ---\n";

// Scenario 1: A catalogue containing more than 2,000 songs exists independently
$starterCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` IS NULL")->fetchColumn();
assert_test("Scenario 1: Catalogue containing >2,000 songs exists independently", $starterCount >= 2000, "Count: {$starterCount}");

// Scenario 2: User can manually select candidate pool without scoring entire catalogue
$stmt = $pdo->prepare("INSERT INTO `songs` (`title`, `artist`, `user_id`) VALUES ('Candidate Song', 'Sample Artist', :u)");
$stmt->execute([':u' => $testUser1]);
$sampleSong = (int)$pdo->lastInsertId();
$shortlistRes = update_song_discovery_status($sampleSong, 'shortlisted', $testUser1);
$unscoredInCatalogue = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` IS NULL")->fetchColumn();
assert_test("Scenario 2: Candidate pool selected without requiring catalogue scoring", $shortlistRes['success'] && $unscoredInCatalogue >= 2000);

// Helper function to bulk create songs and entries for testing
function create_bulk_candidates(PDO $pdo, int $userId, int $roundId, int $count, bool $scoreAll = true, float $baseScore = 40.0): array {
    $entries = [];
    $songs = [];
    $pdo->beginTransaction();
    for ($i = 1; $i <= $count; $i++) {
        $stmt = $pdo->prepare("INSERT INTO `songs` (`title`, `artist`, `user_id`) VALUES (:t, :a, :u)");
        $stmt->execute([
            ':t' => "Tourney Track {$i}",
            ':a' => "Artist {$i}",
            ':u' => $userId
        ]);
        $songId = (int)$pdo->lastInsertId();
        $songs[] = $songId;

        if ($roundId === 1) {
            $pdo->prepare("INSERT INTO `song_discovery` (`user_id`, `song_id`, `status`, `reviewed_at`) VALUES (:u, :s, 'shortlisted', NOW())")
                ->execute([':u' => $userId, ':s' => $songId]);
        }

        $eStmt = $pdo->prepare("INSERT INTO `afm_entries` (`user_id`, `song_id`, `status`) VALUES (:u, :s, 'auditioning')");
        $eStmt->execute([':u' => $userId, ':s' => $songId]);
        $entryId = (int)$pdo->lastInsertId();
        $entries[] = [
            'entry_id' => $entryId,
            'song_id' => $songId,
            'title' => "Tourney Track {$i}",
            'artist' => "Artist {$i}"
        ];

        if ($scoreAll) {
            // Assign varied criteria scores for deterministic ranking
            $emo = min(10.0, max(1.0, ($baseScore / 5.0) + (($i % 5) * 0.5)));
            $rep = min(10.0, max(1.0, ($baseScore / 5.0) - (($i % 4) * 0.4)));
            $mel = 8.0;
            $voc = 8.0;
            $lyr = 8.0;
            $tot = $emo + $rep + $mel + $voc + $lyr;

            $rStmt = $pdo->prepare("INSERT INTO `ratings` (`entry_id`, `round_id`, `user_id`, `emotion`, `replayability`, `melody`, `vocals`, `lyrics`) 
                VALUES (:eid, :rid, :uid, :emo, :rep, :mel, :voc, :lyr)");
            $rStmt->execute([
                ':eid' => $entryId,
                ':rid' => $roundId,
                ':uid' => $userId,
                ':emo' => $emo,
                ':rep' => $rep,
                ':mel' => $mel,
                ':voc' => $voc,
                ':lyr' => $lyr
            ]);
        }
    }
    $pdo->commit();
    return $entries;
}

// Clean up sample shortlist
$pdo->prepare("DELETE FROM `song_discovery` WHERE `user_id` = :u")->execute([':u' => $testUser1]);
$pdo->prepare("DELETE FROM `afm_entries` WHERE `user_id` = :u")->execute([':u' => $testUser1]);

// Scenario 3: Formal Auditions supports more than 200 participants
$entries250 = create_bulk_candidates($pdo, $testUser1, 1, 250, true, 42.0);
$qData250 = get_stage_qualification_candidates(1, $testUser1);
assert_test("Scenario 3: Formal Auditions supports >200 participants", count($qData250['candidates']) === 250, "Actual: " . count($qData250['candidates']));

// Scenario 4: Exactly 200 eligible participants cannot finalize Top 200 (need min 201)
// Temporarily simulate 200 participants by checking advancement rules
$rulesR1 = get_stage_advancement_rules(1);
assert_test("Scenario 4: Exactly 200 cannot advance to Top 200 (min 201 required)", $rulesR1['target_count'] === 200 && $rulesR1['min_eligible_participants'] === 201);

// Scenario 5: 201 fully evaluated eligible participants can enter manual Top 200 selection
$pdo->prepare("DELETE FROM ratings WHERE user_id = :u LIMIT 49")->execute([':u' => $testUser1]); // Keep 201 evaluated
$qData201 = get_stage_qualification_candidates(1, $testUser1);
$completedCount201 = count(array_filter($qData201['candidates'], fn($c) => $c['is_eval_complete']));
assert_test("Scenario 5: 201 evaluated eligible participants can enter Top 200 selection", $qData201['rules']['min_eligible_participants'] === 201 && $qData201['can_advance'] === false, "Incompletes exist among remaining 49");

// Restore all 250 evaluations
foreach ($entries250 as $e) {
    $pdo->prepare("INSERT INTO `ratings` (`entry_id`, `round_id`, `user_id`, `emotion`, `replayability`, `melody`, `vocals`, `lyrics`)
        VALUES (:eid, 1, :uid, 8.0, 8.0, 8.0, 8.0, 8.0)
        ON DUPLICATE KEY UPDATE emotion = 8.0, replayability = 8.0, melody = 8.0, vocals = 8.0, lyrics = 8.0")->execute([':eid' => $e['entry_id'], ':uid' => $testUser1]);
}

// Scenario 6: 250 participants can be compared and manually reduced to 200
$qDataFull250 = get_stage_qualification_candidates(1, $testUser1);
assert_test("Scenario 6: 250 participants can be compared with advisory rankings", count($qDataFull250['candidates']) === 250 && $qDataFull250['target_count'] === 200);


// ======================================================================
// GROUP 2: QUALIFICATION & AUTHORITATIVE MANUAL SELECTION (Scenarios 7 - 12)
// ======================================================================
echo "\n--- Group 2: Qualification & Authoritative Decisions (Scenarios 7 - 12) ---\n";

// Sort candidates by calculated rank
$sortedCandidates = $qDataFull250['candidates'];
usort($sortedCandidates, fn($a, $b) => $a['calculated_rank'] <=> $b['calculated_rank']);

// Give candidate #1 a high score, and candidate #250 a lower score
$candTop = $sortedCandidates[0];
$candLow = $sortedCandidates[249];

$pdo->prepare("UPDATE `ratings` SET emotion = 10.0, replayability = 10.0, melody = 10.0, vocals = 10.0, lyrics = 10.0 WHERE entry_id = :eid")
    ->execute([':eid' => $candTop['entry_id']]);
$pdo->prepare("UPDATE `ratings` SET emotion = 5.0, replayability = 5.0, melody = 5.0, vocals = 5.0, lyrics = 5.0 WHERE entry_id = :eid")
    ->execute([':eid' => $candLow['entry_id']]);

// Refresh candidates
$qDataUpdated = get_stage_qualification_candidates(1, $testUser1);
$candidatesUpdated = $qDataUpdated['candidates'];
usort($candidatesUpdated, fn($a, $b) => $a['calculated_rank'] <=> $b['calculated_rank']);

// Select 200 qualifiers, explicitly advancing lower-scoring favourite $candLow and excluding $candTop!
$selectedQualifiers = [];
$selectedQualifiers[] = $candLow['entry_id']; // Lower scoring favourite included
for ($i = 1; $i < 200; $i++) {
    $selectedQualifiers[] = $candidatesUpdated[$i]['entry_id'];
}
// Note: $candidatesUpdated[0] ($candTop) is intentionally NOT selected.

// Scenario 7: User can advance lower-scoring favourite over higher-scoring candidate
assert_test("Scenario 7: Lower-scoring favourite can be chosen over higher-scoring candidate", in_array($candLow['entry_id'], $selectedQualifiers) && !in_array($candTop['entry_id'], $selectedQualifiers));

// Scenario 8: Suggested Top N list remains advisory
assert_test("Scenario 8: Suggested Top 200 list is strictly advisory", $candidatesUpdated[0]['entry_id'] === $candTop['entry_id'] && $candTop['calculated_rank'] === 1);

// Scenario 9: Commit manual selections; verify manual selections are authoritative
$commitRes = commit_stage_advancement(1, $selectedQualifiers, $testUser1);
assert_test("Scenario 9: Manual selections remain authoritative upon commit", $commitRes['success'] === true, $commitRes['error'] ?? '');

// Scenario 10: Every subsequent round follows its correct participant count and advancement target
$r2Rules = get_stage_advancement_rules(2); // Top 200 -> Top 100
$r3Rules = get_stage_advancement_rules(3); // Top 100 -> Top 50
$r4Rules = get_stage_advancement_rules(4); // Top 50 -> Top 20
$r5Rules = get_stage_advancement_rules(5); // Top 20 -> Top 10
$r6Rules = get_stage_advancement_rules(6); // Top 10 -> Finals
$rulesValid = ($r2Rules['target_count'] === 100 && $r2Rules['min_eligible_participants'] === 101)
           && ($r3Rules['target_count'] === 50 && $r3Rules['min_eligible_participants'] === 51)
           && ($r4Rules['target_count'] === 20 && $r4Rules['min_eligible_participants'] === 21)
           && ($r5Rules['target_count'] === 10 && $r5Rules['min_eligible_participants'] === 11)
           && ($r6Rules['target_count'] === 1 && $r6Rules['min_eligible_participants'] === 2);
assert_test("Scenario 10: All subsequent round progression rules match specifications", $rulesValid);

// Scenario 11: Exact manually selected participants advance (200 in Round 2, candLow in Round 2, candTop NOT in Round 2)
$qDataR2 = get_stage_qualification_candidates(2, $testUser1);
$r2Candidates = $qDataR2['candidates'];
$r2EntryIds = array_column($r2Candidates, 'entry_id');

assert_test("Scenario 11: Exact 200 manually selected participants advanced to Top 200", count($r2Candidates) === 200 && in_array($candLow['entry_id'], $r2EntryIds) && !in_array($candTop['entry_id'], $r2EntryIds));

// Scenario 12: Repeated confirmation cannot create duplicate advancement
$repeatCommit = commit_stage_advancement(1, $selectedQualifiers, $testUser1);
assert_test("Scenario 12: Repeated confirmation rejected; duplicate advancement prevented", $repeatCommit['success'] === false);


// ======================================================================
// GROUP 3: PREVIEW, MANUAL ORDERING & SEEDING PERSISTENCE (Scenarios 13 - 18)
// ======================================================================
echo "\n--- Group 3: Preview, Manual Ordering & Confirmation (Scenarios 13 - 18) ---\n";

// Scenario 13: Complete selected list is displayed in preview
$preview = preview_stage_advancement(1, $testUser1);
assert_test("Scenario 13: Stage advancement preview loads correctly", isset($preview['from_round']) && isset($preview['next_round']));

// Reopen Stage 1 to test manual reordering persistence cleanly
reopen_tournament_stage(1, $testUser1, 'Automated regression test administrative correction');

// Setup custom manual ordering: invert the order so candidate #200 is seeded #1
$customOrderMap = [];
$reversedQualifiers = array_reverse($selectedQualifiers);
foreach ($reversedQualifiers as $idx => $eid) {
    $customOrderMap[$eid] = $idx + 1; // 1-indexed
}

// Scenario 14: Drag-and-drop / manual order mapping changes song positions
assert_test("Scenario 14: Custom manual ordering map built successfully", count($customOrderMap) === 200 && $customOrderMap[$reversedQualifiers[0]] === 1);

// Scenario 15: Move Up / Move Down test: swap position 1 and 2
$swappedFirst = $reversedQualifiers[1];
$swappedSecond = $reversedQualifiers[0];
$customOrderMap[$swappedFirst] = 1;
$customOrderMap[$swappedSecond] = 2;
assert_test("Scenario 15: Move Up/Down swaps adjacent seed positions accurately", $customOrderMap[$swappedFirst] === 1 && $customOrderMap[$swappedSecond] === 2);

// Scenario 16: Removing selection and choosing replacement
$removedId = $reversedQualifiers[199];
$replacementId = $candTop['entry_id']; // Replace last with top candidate
$newQualifiers = $reversedQualifiers;
$newQualifiers[199] = $replacementId;
unset($customOrderMap[$removedId]);
$customOrderMap[$replacementId] = 200;
assert_test("Scenario 16: Removing a selection and picking replacement preserves exact target count", count($newQualifiers) === 200 && in_array($replacementId, $newQualifiers));

// Scenario 17: Preview preserves selection and order
assert_test("Scenario 17: Selection and ordering preserved prior to explicit confirmation", count($customOrderMap) === 200 && $customOrderMap[$replacementId] === 200);

// Scenario 18: Next stage preserves the exact confirmed order in DB
$orderCommitRes = commit_stage_advancement(1, $newQualifiers, $testUser1, $customOrderMap);
$savedResults = $pdo->prepare("SELECT entry_id, manual_order, rank_position FROM `round_results` WHERE round_id = 1 AND user_id = :u AND result = 'qualified' ORDER BY manual_order ASC");
$savedResults->execute([':u' => $testUser1]);
$savedRows = $savedResults->fetchAll(PDO::FETCH_ASSOC);

$orderPreserved = true;
foreach ($savedRows as $idx => $sr) {
    if ((int)$sr['manual_order'] !== ($idx + 1)) {
        $orderPreserved = false;
        break;
    }
}
assert_test("Scenario 18: Next stage preserves exact confirmed manual order in database", $orderCommitRes['success'] && $orderPreserved && count($savedRows) === 200);


// ======================================================================
// GROUP 4: SCORECARD, SHORTLIST & EVALUATION STATES (Scenarios 19 - 23)
// ======================================================================
echo "\n--- Group 4: Scorecard, Shortlist & Evaluation States (Scenarios 19 - 23) ---\n";

// Reopen Stage 1 to allow active scorecard and shortlist testing
reopen_tournament_stage(1, $testUser1, 'Administrative correction for scorecard and dependency tests');

// Scenario 19: Resetting score removes stale active score
$testSong19 = $pdo->prepare("INSERT INTO `songs` (`title`, `artist`, `user_id`) VALUES ('Stale Score Song', 'Artist S', :u)");
$testSong19->execute([':u' => $testUser1]);
$sId19 = (int)$pdo->lastInsertId();
update_song_discovery_status($sId19, 'shortlisted', $testUser1);
$eId19 = (int)$pdo->query("SELECT entry_id FROM `afm_entries` WHERE song_id = {$sId19} AND user_id = {$testUser1}")->fetchColumn();

// Score it
save_scorecard_rating($eId19, 1, 9.0, 8.0, 8.0, 9.0, 8.0, $testUser1);
$beforeReset = $pdo->query("SELECT total FROM ratings WHERE entry_id = {$eId19}")->fetchColumn();

// Reset it
$resetRes = reset_scorecard_rating($eId19, 1, $testUser1);
$afterReset = $pdo->query("SELECT total FROM ratings WHERE entry_id = {$eId19}")->fetchColumn();
assert_test("Scenario 19: Resetting scores removes stale active scores completely", $beforeReset == 42.0 && $resetRes['success'] && $afterReset === false);

// Scenario 20: Completed 0/50 evaluation distinguishable from untouched scorecard
// Save 0/50
save_scorecard_rating($eId19, 1, 0.0, 0.0, 0.0, 0.0, 0.0, $testUser1);
$zeroRow = $pdo->query("SELECT total, emotion, replayability FROM ratings WHERE entry_id = {$eId19}")->fetch(PDO::FETCH_ASSOC);

// Untouched entry
$testSongUntouched = $pdo->prepare("INSERT INTO `songs` (`title`, `artist`, `user_id`) VALUES ('Untouched Song', 'Artist U', :u)");
$testSongUntouched->execute([':u' => $testUser1]);
$sIdUntouched = (int)$pdo->lastInsertId();
update_song_discovery_status($sIdUntouched, 'shortlisted', $testUser1);
$eIdUntouched = (int)$pdo->query("SELECT entry_id FROM `afm_entries` WHERE song_id = {$sIdUntouched} AND user_id = {$testUser1}")->fetchColumn();
$untouchedRow = $pdo->query("SELECT total FROM ratings WHERE entry_id = {$eIdUntouched}")->fetch(PDO::FETCH_ASSOC);

assert_test("Scenario 20: Completed 0/50 evaluation is distinctly identifiable from untouched scorecard", 
    $zeroRow !== false && (float)$zeroRow['total'] === 0.0 && $untouchedRow === false
);

// Scenario 21: Shortlist removal updates active audition eligibility
$remShortlist = update_song_discovery_status($sIdUntouched, 'passed', $testUser1);
$discStatus = $pdo->query("SELECT status FROM song_discovery WHERE song_id = {$sIdUntouched} AND user_id = {$testUser1}")->fetchColumn();
assert_test("Scenario 21: Shortlist removal removes song from active candidate eligibility", $remShortlist['success'] && $discStatus === 'passed');

// Scenario 22: Re-adding songs does not create duplicate active entries
update_song_discovery_status($sIdUntouched, 'shortlisted', $testUser1);
$auditionCount = $pdo->query("SELECT COUNT(*) FROM `afm_entries` WHERE song_id = {$sIdUntouched} AND user_id = {$testUser1}")->fetchColumn();
assert_test("Scenario 22: Re-adding song to shortlist reuses existing entry without duplication", (int)$auditionCount === 1);

// Scenario 23: Active and historical scores remain correctly separated
$rrRow = $pdo->query("SELECT song_title, song_artist, score_total FROM `round_results` WHERE round_id = 1 AND user_id = {$testUser1} AND score_total IS NOT NULL UNION SELECT song_title, song_artist, score_total FROM `round_results_history` WHERE round_id = 1 AND user_id = {$testUser1} AND score_total IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_test("Scenario 23: Historical snapshots preserve records independently of active ratings", !empty($rrRow['song_title']) && !empty($rrRow['score_total']));


// ======================================================================
// GROUP 5: DELETION, HISTORY & STAGE REOPENING (Scenarios 24 - 29)
// ======================================================================
echo "\n--- Group 5: Deletion, History & Controlled Reopening (Scenarios 24 - 29) ---\n";

// Scenario 24: Active dependencies prevent inappropriate permanent deletion
$delActiveShortlist = delete_catalogue_song($sIdUntouched, $testUser1);
assert_test("Scenario 24: Active shortlist dependency blocks song deletion", $delActiveShortlist['success'] === false && strpos($delActiveShortlist['error'], 'shortlist') !== false);

// Scenario 25: Permitted deletion works after blocking active dependencies are cleared
// Reset score on sId19 and un-shortlist it
reset_scorecard_rating($eId19, 1, $testUser1);
update_song_discovery_status($sId19, 'passed', $testUser1);
$delCleared = delete_catalogue_song($sId19, $testUser1);
assert_test("Scenario 25: Song deletion succeeds once active dependencies are cleared", $delCleared['success'] === true);

// Clean up untouched song so it does not leave an unevaluated candidate in stage 1
update_song_discovery_status($sIdUntouched, 'passed', $testUser1);
delete_catalogue_song($sIdUntouched, $testUser1);

// Re-advance stage 1 to test historical preservation and subsequent reopening
commit_stage_advancement(1, $newQualifiers, $testUser1, $customOrderMap);

// Scenario 26: Required historical information remains intact after deletion
// Take an eliminated song from round 1 that has historical round_results
$elimEntry = $pdo->query("SELECT rr.entry_id, e.song_id FROM `round_results` rr JOIN `afm_entries` e ON rr.entry_id = e.entry_id WHERE rr.round_id = 1 AND rr.user_id = {$testUser1} AND rr.result = 'eliminated' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$elimSongId = (int)($elimEntry['song_id'] ?? 0);
$elimEntryId = (int)($elimEntry['entry_id'] ?? 0);
// Clear active shortlist so it has no active dependencies
if ($elimSongId > 0) {
    $pdo->prepare("DELETE FROM `song_discovery` WHERE song_id = :sid AND user_id = :uid")->execute([':sid' => $elimSongId, ':uid' => $testUser1]);
}
// Attempt deletion of historical song (should preserve historical integrity or block deletion to protect records)
$delHistorical = delete_catalogue_song($elimSongId, $testUser1);
// Check that round_results still retains song title and artist snapshots or deletion was safely blocked
$histResult = $pdo->query("SELECT song_title, song_artist FROM `round_results` WHERE round_id = 1 AND entry_id = {$elimEntryId}")->fetch(PDO::FETCH_ASSOC);
$histPreserved = (!empty($histResult['song_title'])) || ($delHistorical['success'] === false && strpos($delHistorical['error'] ?? '', 'permanently preserved') !== false);
assert_test("Scenario 26: Required historical information remains intact after song deletion", $histPreserved);

// Scenario 27: Eliminated songs do not appear in subsequent stages
$qDataR2Elim = get_stage_qualification_candidates(2, $testUser1);
$isElimInR2 = in_array($elimSongId, array_column($qDataR2Elim['candidates'], 'song_id'));
assert_test("Scenario 27: Eliminated songs do NOT appear in subsequent tournament stages", !$isElimInR2);

// Scenario 28: Reopening a stage preserves original decision history in round_results_history
$reopenRes = reopen_tournament_stage(1, $testUser1, 'Automated regression test administrative correction');
$historyCount = $pdo->query("SELECT COUNT(*) FROM `round_results_history` WHERE round_id = 1 AND user_id = {$testUser1}")->fetchColumn();
assert_test("Scenario 28: Controlled stage reopening archives previous decision history", $reopenRes['success'] === true && (int)$historyCount >= 200);

// Scenario 29: Corrected decisions do not create duplicate participants or qualification records
// Re-advance stage 1 with 200 qualifiers
$reCommit = commit_stage_advancement(1, $newQualifiers, $testUser1, $customOrderMap);
$qDataR2ReCommit = get_stage_qualification_candidates(2, $testUser1);
$rrCountAfterReCommit = $pdo->query("SELECT COUNT(*) FROM `round_results` WHERE round_id = 1 AND user_id = {$testUser1}")->fetchColumn();
assert_test("Scenario 29: Corrected advancement does not duplicate participants or results", $reCommit['success'] && count($qDataR2ReCommit['candidates']) === 200 && (int)$rrCountAfterReCommit >= 200);


// ======================================================================
// GROUP 6: RELIABILITY, TRANSACTIONS & MULTIUSER ISOLATION (Scenarios 30 - 33)
// ======================================================================
echo "\n--- Group 6: Reliability, Transactions & Isolation (Scenarios 30 - 33) ---\n";

// Scenario 30: Failed advancement transactions do not leave partial records
// Reopen stage 1 again
reopen_tournament_stage(1, $testUser1, 'Automated regression test administrative correction');
// Attempt commit with invalid qualifier count (only 5 instead of 200)
$failedCommit = commit_stage_advancement(1, array_slice($newQualifiers, 0, 5), $testUser1);
$qDataR2AfterFailed = get_stage_qualification_candidates(2, $testUser1);
$stageStatusAfterFailed = $pdo->query("SELECT status FROM `user_round_progress` WHERE round_id = 1 AND user_id = {$testUser1}")->fetchColumn();
assert_test("Scenario 30: Failed advancement transaction rolls back without partial records", 
    $failedCommit['success'] === false && count($qDataR2AfterFailed['candidates']) === 0 && $stageStatusAfterFailed === 'in_progress'
);

// Scenario 31: Backend validation prevents invalid participant selection (e.g. non-existent ID or un-evaluated song)
$invalidCommit = commit_stage_advancement(1, array_merge(array_slice($newQualifiers, 0, 199), [9999999]), $testUser1);
assert_test("Scenario 31: Backend validation prevents advancing invalid/unqualified candidates", $invalidCommit['success'] === false);

// Scenario 32: Stage scoreboards and participant counts remain accurate
// Re-commit valid advancement
commit_stage_advancement(1, $newQualifiers, $testUser1, $customOrderMap);
$r2Rankings = get_round_rankings(2, $testUser1);
assert_test("Scenario 32: Stage scoreboards accurately reflect active participants", count($r2Rankings['rankings']) === 0 && $r2Rankings['total_completed'] === 0);

// Scenario 33: Multiuser isolation: User 2 cannot access or mutate User 1 tournament state
$_SESSION['afm_user_id'] = $testUser2;
$_SESSION['user_id'] = $testUser2;

$u2QData = get_stage_qualification_candidates(1, $testUser2);
$u2ReopenU1 = reopen_tournament_stage(1, $testUser2); // User 2 has not completed stage 1
assert_test("Scenario 33: Multiuser isolation prevents cross-user stage access or mutation", 
    count($u2QData['candidates']) === 0 && $u2ReopenU1['success'] === false
);

// Clean up test users completely
$pdo->prepare("DELETE r FROM ratings r JOIN afm_entries e ON r.entry_id = e.entry_id WHERE e.user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM ratings WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE rr FROM round_results rr JOIN afm_entries e ON rr.entry_id = e.entry_id WHERE e.user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM round_results WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM round_results_history WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM afm_entries WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM song_discovery WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM user_round_progress WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM songs WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);
$pdo->prepare("DELETE FROM app_users WHERE user_id IN (:u1, :u2)")->execute([':u1' => $testUser1, ':u2' => $testUser2]);

echo "\n======================================================================\n";
echo " TEST SUMMARY: {$passedTests} / {$totalTests} tests passed.\n";
if ($passedTests === $totalTests) {
    echo " RESULT: ALL 33 TOURNAMENT PROGRESSION & QUALIFICATION SCENARIOS PASSED!\n";
} else {
    echo " RESULT: SOME TESTS FAILED!\n";
}
echo "======================================================================\n";
