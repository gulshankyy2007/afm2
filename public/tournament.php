<?php
/**
 * AFM#2 - Tournament Stage Management & Dynamic Leaderboard
 * Tracks competition progression across the 7 tournament rounds with criteria tie-breakers and stage advancement.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

require_auth();

$currentUserId = get_current_user_id();

$pageTitle = 'Tournament Progression — AFM#2 2026';

$allRounds = get_tournament_rounds($currentUserId);
$activeRound = get_active_round($currentUserId);

$selectedRoundId = isset($_GET['round_id']) ? (int)$_GET['round_id'] : (int)($activeRound['round_id'] ?? 1);
$currentRound = get_round_by_id($selectedRoundId, $currentUserId) ?? $activeRound;

// Fetch leaderboard data for selected round scoped to current user
$rankData = get_round_rankings($selectedRoundId, $currentUserId);
$rankings = $rankData['rankings'];
$totalCompleted = $rankData['total_completed'];

// Find next round if exists
$pdo = get_db_connection();
$nextStmt = $pdo->prepare("SELECT * FROM `rounds` WHERE `sequence_no` = :seq");
$nextStmt->execute([':seq' => (int)$currentRound['sequence_no'] + 1]);
$nextRound = $nextStmt->fetch() ?: null;
if ($nextRound) {
    $nextRound = get_round_by_id((int)$nextRound['round_id'], $currentUserId) ?? $nextRound;
}

$targetCutoff = $nextRound ? (int)$nextRound['target_count'] : 1;
$isInProgress = ($currentRound['status'] === 'in_progress');
$isCompleted = ($currentRound['status'] === 'completed');

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-container">
    <!-- Header -->
    <div class="page-header" style="margin-bottom: 2.25rem;">
        <div>
            <h1 class="page-title">Tournament Leaderboard & Progression</h1>
            <p class="page-subtitle">
                Official stage rankings determined by total scores and 5-tier criteria tie-breakers.
            </p>
        </div>
        <div class="header-actions">
            <a href="auditions.php?round_id=<?= (int)$selectedRoundId ?>" class="btn btn-secondary">
                <span class="btn-icon">🎧</span> Score Entries
            </a>
            <?php if ($isCompleted): ?>
                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); padding: 0.5rem 0.85rem; border-radius: 6px; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;">
                    🔒 Stage Finalized &amp; Immutable
                </span>
                <?php if (is_admin()): ?>
                    <button 
                        type="button" 
                        class="btn btn-secondary" 
                        id="btnAdminCorrectStage" 
                        data-round-id="<?= (int)$selectedRoundId ?>"
                        data-round-name="<?= htmlspecialchars($currentRound['round_name'], ENT_QUOTES, 'UTF-8') ?>"
                        style="border-color: rgba(239, 68, 68, 0.4); color: #f87171;"
                    >
                        <span class="btn-icon">⚠️</span> Admin: Exceptional Correction
                    </button>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($isInProgress && $nextRound): ?>
                <button 
                    type="button" 
                    class="btn btn-accent" 
                    id="btnPreviewAdvancement" 
                    data-from-round-id="<?= (int)$selectedRoundId ?>"
                >
                    <span class="btn-icon">⚡</span> Manual Qualification & Advance to <?= htmlspecialchars($nextRound['round_name'], ENT_QUOTES, 'UTF-8') ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tournament Stage Progression Navigator -->
    <div class="stage-nav-card">
        <div class="stage-nav-header">
            <div class="stage-nav-title-group">
                <span class="stage-nav-eyebrow">
                    <span class="eyebrow-icon">🏆</span> Tournament Progression
                </span>
                <h2 class="stage-nav-title">Competition Stages & Cutoffs</h2>
            </div>
            <div class="stage-nav-badge-group">
                <span class="stage-nav-hint">Leaderboard Stage: <strong><?= htmlspecialchars($currentRound['round_name'], ENT_QUOTES, 'UTF-8') ?></strong></span>
                <span class="stage-nav-indicator <?= htmlspecialchars($currentRound['status'] ?? 'upcoming', ENT_QUOTES, 'UTF-8') ?>">
                    <?= ($currentRound['status'] ?? '') === 'in_progress' ? '● Stage Active' : (($currentRound['status'] ?? '') === 'completed' ? '✓ Stage Finished' : '○ Upcoming Stage') ?>
                </span>
            </div>
        </div>
        <div class="stage-stepper">
            <?php foreach ($allRounds as $r): 
                $isSelected = ((int)$r['round_id'] === $selectedRoundId);
                $rStatus = $r['status'];
                $rSeq = (int)$r['sequence_no'];
            ?>
            <a 
                href="tournament.php?round_id=<?= (int)$r['round_id'] ?>" 
                class="step-item <?= $isSelected ? 'active' : '' ?> <?= $rStatus ?>"
                aria-current="<?= $isSelected ? 'step' : 'false' ?>"
            >
                <div class="step-num"><?= $rSeq ?></div>
                <div class="step-content">
                    <div class="step-title"><?= htmlspecialchars($r['round_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="step-meta">
                        <?php if ($rStatus === 'completed'): ?>
                            <span class="text-success">✓ Completed</span>
                        <?php elseif ($rStatus === 'in_progress'): ?>
                            <span class="text-accent font-bold">● Active Stage</span>
                        <?php else: ?>
                            <span class="text-muted">Upcoming</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Active Stage Banner -->
    <div class="stage-banner card mb-4">
        <div class="stage-banner-inner">
            <div class="stage-banner-info">
                <div class="stage-status-badge">
                    <?php if ($isCompleted): ?>
                        <span class="badge badge-success">Stage Completed</span>
                        <?php if ($currentRound['completed_at']): ?>
                            <span class="text-muted text-xs ml-2">Decided on <?= htmlspecialchars(format_local_timestamp($currentRound['completed_at'])) ?></span>
                        <?php endif; ?>
                    <?php elseif ($isInProgress): ?>
                        <span class="badge badge-accent">Stage In Progress</span>
                    <?php else: ?>
                        <span class="badge badge-neutral">Upcoming Stage</span>
                    <?php endif; ?>
                </div>
                <h2 class="stage-title mt-1"><?= htmlspecialchars($currentRound['round_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="stage-rules text-muted text-sm mt-1">
                    Ranking hierarchy: <strong>Total (/50)</strong> &rarr; <strong>Emotion</strong> &rarr; <strong>Replayability</strong> &rarr; <strong>Melody</strong> &rarr; <strong>Vocals</strong> &rarr; <strong>Lyrics</strong>.
                </p>
            </div>

            <div class="stage-stats-strip">
                <div class="stage-stat-item">
                    <span class="label">Completed Scores</span>
                    <span class="value text-accent"><?= number_format($totalCompleted) ?></span>
                </div>
                <?php if ($nextRound): ?>
                <div class="stage-stat-item">
                    <span class="label">Target Cutoff</span>
                    <span class="value text-gold">Top <?= number_format($targetCutoff) ?></span>
                </div>
                <?php else: ?>
                <div class="stage-stat-item">
                    <span class="label">Champion</span>
                    <span class="value text-gold">#1 Winner</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Leaderboard Table -->
    <?php if (empty($rankings)): ?>
        <div class="empty-state">
            <div class="empty-icon">📊</div>
            <h2 class="empty-title">No Completed Scores Yet for <?= htmlspecialchars($currentRound['round_name'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="empty-desc">
                Leaderboards are calculated strictly from completed scorecards (where all 5 criteria are evaluated).
                Score entries in the Auditions tab to populate this stage's rankings.
            </p>
            <div class="empty-actions">
                <?php 
                    $stageBtnText = (stripos($currentRound['round_name'], 'Audition') !== false)
                        ? 'Open ' . htmlspecialchars($currentRound['round_name'], ENT_QUOTES, 'UTF-8') . ' Console'
                        : 'Score in ' . htmlspecialchars($currentRound['round_name'], ENT_QUOTES, 'UTF-8') . ' &rarr;';
                ?>
                <a href="auditions.php?round_id=<?= (int)$selectedRoundId ?>" class="btn btn-accent">
                    <?= $stageBtnText ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="table-container mb-4">
            <table class="data-table leaderboard-table" id="leaderboardTable">
                <thead>
                    <tr>
                        <th class="col-rank">Rank</th>
                        <th class="col-song">Song Title & Artist</th>
                        <th class="col-total text-center">Total Score</th>
                        <th class="col-criteria text-center" title="Tie-breaker 1">Emotion</th>
                        <th class="col-criteria text-center" title="Tie-breaker 2">Replay</th>
                        <th class="col-criteria text-center" title="Tie-breaker 3">Melody</th>
                        <th class="col-criteria text-center" title="Tie-breaker 4">Vocals</th>
                        <th class="col-criteria text-center" title="Tie-breaker 5">Lyrics</th>
                        <th class="col-status text-center">Status / Cutoff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $cutoffRendered = false;
                    foreach ($rankings as $idx => $row): 
                        $rank = (int)$row['calculated_rank'];
                        $isQualifiedZone = ($nextRound && $rank <= $targetCutoff);
                        $isBelowCutoff = ($nextRound && $rank > $targetCutoff);
                        
                        // Check if we should insert the visual cutoff divider line
                        if ($nextRound && !$cutoffRendered && $rank > $targetCutoff): 
                            $cutoffRendered = true;
                    ?>
                        <tr class="cutoff-divider-row">
                            <td colspan="9" class="cutoff-divider-cell">
                                <div class="cutoff-indicator">
                                    <span class="cutoff-line"></span>
                                    <span class="cutoff-badge">
                                        ⚡ TARGET CUTOFF LINE &mdash; TOP <?= $targetCutoff ?> ADVANCE TO <?= strtoupper(htmlspecialchars($nextRound['round_name'], ENT_QUOTES, 'UTF-8')) ?>
                                    </span>
                                    <span class="cutoff-line"></span>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <tr class="rank-row <?= $isQualifiedZone ? 'zone-qualifier' : ($isBelowCutoff ? 'zone-eliminated' : '') ?>">
                        <td class="col-rank" data-label="Rank">
                            <div class="rank-badge <?= $rank <= 3 ? 'rank-podium rank-' . $rank : '' ?>">
                                <?php if ($rank === 1): ?>🥇 #1
                                <?php elseif ($rank === 2): ?>🥈 #2
                                <?php elseif ($rank === 3): ?>🥉 #3
                                <?php else: ?>#<?= $rank ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="col-song" data-label="Song">
                            <div class="song-meta">
                                <span class="song-title font-bold"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="song-artist text-muted"><?= htmlspecialchars($row['artist'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if (!empty($row['is_tied_all_criteria'])): ?>
                                    <span class="badge badge-warning text-xs mt-1" title="Tied across all 5 criteria with previous entry">
                                        Equal Criteria Tie
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="col-total text-center" data-label="Total Score">
                            <div class="score-total-pill font-mono font-bold">
                                <?= (int)$row['total'] ?> <span class="text-xs text-muted">/50</span>
                            </div>
                        </td>
                        <td class="col-criteria text-center font-mono <?= (int)$row['emotion'] >= 9 ? 'high-score' : '' ?>" data-label="Emotion">
                            <?= (int)$row['emotion'] ?>
                        </td>
                        <td class="col-criteria text-center font-mono <?= (int)$row['replayability'] >= 9 ? 'high-score' : '' ?>" data-label="Replayability">
                            <?= (int)$row['replayability'] ?>
                        </td>
                        <td class="col-criteria text-center font-mono" data-label="Melody">
                            <?= (int)$row['melody'] ?>
                        </td>
                        <td class="col-criteria text-center font-mono" data-label="Vocals">
                            <?= (int)$row['vocals'] ?>
                        </td>
                        <td class="col-criteria text-center font-mono" data-label="Lyrics">
                            <?= (int)$row['lyrics'] ?>
                        </td>
                        <td class="col-status text-center" data-label="Status">
                            <?php if ($row['result'] === 'winner'): ?>
                                <span class="badge badge-gold">🏆 Champion</span>
                            <?php elseif ($row['result'] === 'qualified'): ?>
                                <span class="badge badge-success">✓ Advanced</span>
                            <?php elseif ($row['result'] === 'eliminated'): ?>
                                <span class="badge badge-danger">Eliminated</span>
                            <?php elseif ($isQualifiedZone): ?>
                                <span class="badge badge-accent">● Qualifying</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">Below Cutoff</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Tournament Manual Qualification & Advancement Modal -->
<div class="modal-overlay" id="advancementModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-content modal-advancement">
        <div class="modal-header">
            <div>
                <span class="eyebrow-tag"><span class="eyebrow-dot"></span> Manual Qualification & Advancement Console</span>
                <h3 class="modal-title" id="modalTitle">Advance Tournament Stage</h3>
            </div>
            <button type="button" class="modal-close" data-modal-close aria-label="Close dialog">&times;</button>
        </div>
        <div class="modal-body" id="advancementModalBody">
            <div class="text-center p-4">Loading qualification candidates...</div>
        </div>
        <div class="modal-footer" id="advancementModalFooter">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
        </div>
    </div>
</div>

<?php if (is_admin()): ?>
<!-- Restricted Exceptional Administrative Stage Correction Modal -->
<div class="modal-overlay" id="adminCorrectionModal" role="dialog" aria-modal="true" aria-labelledby="adminCorrectionModalTitle">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <span class="badge" style="background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4);">Administrative Override</span>
                <h3 class="modal-title" id="adminCorrectionModalTitle" style="color: #f87171;">⚠️ Exceptional Stage Correction</h3>
            </div>
            <button type="button" class="modal-close" data-modal-close aria-label="Close dialog">&times;</button>
        </div>
        <div class="modal-body" id="adminCorrectionModalBody">
            <div class="text-center p-4">Loading stage details...</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
            <button type="button" class="btn btn-danger" id="btnConfirmAdminCorrection">
                <span class="btn-icon">⚠️</span> Authorize Exceptional Correction
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
require_once dirname(__DIR__) . '/includes/footer.php';
