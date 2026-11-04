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

// Stage-scoped search parameters & pagination
$searchQuery = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$rawPerPage = (int)($_GET['per_page'] ?? 50);
$perPage = in_array($rawPerPage, [25, 50, 75, 100], true) ? $rawPerPage : 50;

// Filter rankings by Exact Rank (numeric) or Title / Artist (case-insensitive partial)
$filteredRankings = $rankings;
if ($searchQuery !== '') {
    if (ctype_digit($searchQuery) && (int)$searchQuery > 0) {
        $targetRank = (int)$searchQuery;
        $filteredRankings = array_filter($rankings, function($row) use ($targetRank) {
            return (int)$row['calculated_rank'] === $targetRank;
        });
    } else {
        $filteredRankings = array_filter($rankings, function($row) use ($searchQuery) {
            return (stripos($row['title'], $searchQuery) !== false || stripos($row['artist'], $searchQuery) !== false);
        });
    }
}
$filteredRankings = array_values($filteredRankings);
$totalMatching = count($filteredRankings);
$totalPages = (int)ceil($totalMatching / $perPage);
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
}
$pagedRankings = array_slice($filteredRankings, ($page - 1) * $perPage, $perPage);

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

    <!-- Stage-Scoped Tournament Search Bar -->
    <?php if (!empty($rankings)): ?>
    <form method="GET" action="tournament.php" class="filter-bar tournament-search-bar" id="tournamentSearchForm" style="margin-bottom: 1.75rem;">
        <input type="hidden" name="round_id" value="<?= (int)$selectedRoundId ?>">
        <div class="filter-bar-row">
            <div class="search-input-wrapper" style="flex: 1;">
                <span class="search-icon" aria-hidden="true">🔍</span>
                <input 
                    type="search" 
                    name="q" 
                    id="tournamentSearchInput"
                    class="search-input" 
                    placeholder="Search track, artist or rank..." 
                    value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="Search tournament records by track title, artist or rank"
                >
                <?php if ($searchQuery !== ''): ?>
                    <a href="tournament.php?round_id=<?= (int)$selectedRoundId ?>" class="btn-search-clear" title="Clear search" aria-label="Clear search">✕</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-search-apply" id="btnTournamentSearch">Search</button>
            </div>
            <?php if ($searchQuery !== ''): ?>
                <div class="filter-reset-wrap">
                    <a href="tournament.php?round_id=<?= (int)$selectedRoundId ?>" class="btn btn-secondary btn-sm" title="Clear search and restore stage listing">
                        <span>↺</span> Clear Search
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($searchQuery !== ''): ?>
            <div class="search-results-summary text-xs text-muted" style="margin-top: 0.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                <span>
                    Showing <strong><?= number_format($totalMatching) ?></strong> matching <?= $totalMatching === 1 ? 'record' : 'records' ?> for "<strong><?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?></strong>" in <?= htmlspecialchars($currentRound['round_name'], ENT_QUOTES, 'UTF-8') ?>
                    <?= (ctype_digit($searchQuery) && (int)$searchQuery > 0) ? '<span class="badge badge-accent text-xs ml-2">Exact Rank #' . (int)$searchQuery . '</span>' : '' ?>
                </span>
                <a href="tournament.php?round_id=<?= (int)$selectedRoundId ?>" class="text-accent text-xs" style="text-decoration: underline;">Reset to Full Stage</a>
            </div>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <!-- Leaderboard Table / Empty States -->
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
    <?php elseif ($totalMatching === 0): ?>
        <div class="empty-state">
            <div class="empty-icon">🔍</div>
            <h2 class="empty-title">No Matching Records Found</h2>
            <p class="empty-desc">
                No songs match "<strong><?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?></strong>" in <?= htmlspecialchars($currentRound['round_name'], ENT_QUOTES, 'UTF-8') ?>.
            </p>
            <div class="empty-actions">
                <a href="tournament.php?round_id=<?= (int)$selectedRoundId ?>" class="btn btn-secondary">
                    <span>↺</span> Clear Search &amp; Show All (<?= number_format(count($rankings)) ?> Records)
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
                        <th class="col-criteria text-center" title="Tie-breaker 1: Emotion">
                            <span class="th-criterion-wrap" title="Emotion (Tie-breaker 1)">
                                <svg class="criterion-svg icon-emotion" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                <span class="th-criterion-text">Emotion</span>
                            </span>
                        </th>
                        <th class="col-criteria text-center" title="Tie-breaker 2: Replayability">
                            <span class="th-criterion-wrap" title="Replay (Tie-breaker 2)">
                                <svg class="criterion-svg icon-replayability" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                                <span class="th-criterion-text">Replay</span>
                            </span>
                        </th>
                        <th class="col-criteria text-center" title="Tie-breaker 3: Melody">
                            <span class="th-criterion-wrap" title="Melody (Tie-breaker 3)">
                                <svg class="criterion-svg icon-melody" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
                                <span class="th-criterion-text">Melody</span>
                            </span>
                        </th>
                        <th class="col-criteria text-center" title="Tie-breaker 4: Vocals">
                            <span class="th-criterion-wrap" title="Vocals (Tie-breaker 4)">
                                <svg class="criterion-svg icon-vocals" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                                <span class="th-criterion-text">Vocals</span>
                            </span>
                        </th>
                        <th class="col-criteria text-center" title="Tie-breaker 5: Lyrics">
                            <span class="th-criterion-wrap" title="Lyrics (Tie-breaker 5)">
                                <svg class="criterion-svg icon-lyrics" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                                <span class="th-criterion-text">Lyrics</span>
                            </span>
                        </th>
                        <th class="col-status text-center">Status / Cutoff</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $cutoffRendered = false;
                    foreach ($pagedRankings as $idx => $row): 
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
                            <div class="mobile-song-header">
                                <span class="mobile-song-label">Song</span>
                                <span class="mobile-rank-badge rank-badge <?= $rank <= 3 ? 'rank-podium rank-' . $rank : '' ?>">
                                    <?php if ($rank === 1): ?>🥇 #1
                                    <?php elseif ($rank === 2): ?>🥈 #2
                                    <?php elseif ($rank === 3): ?>🥉 #3
                                    <?php else: ?>#<?= $rank ?>
                                    <?php endif; ?>
                                </span>
                            </div>
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
                        <td class="col-criteria col-criteria-emotion text-center font-mono <?= (int)$row['emotion'] >= 9 ? 'high-score' : '' ?>" data-label="Emotion" data-criterion="emotion">
                            <span class="criterion-score-badge" title="Emotion: <?= (int)$row['emotion'] ?>/10" aria-label="Emotion: <?= (int)$row['emotion'] ?> out of 10">
                                <svg class="criterion-svg icon-emotion" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                <span class="criterion-num"><?= (int)$row['emotion'] ?></span>
                            </span>
                        </td>
                        <td class="col-criteria col-criteria-replay text-center font-mono <?= (int)$row['replayability'] >= 9 ? 'high-score' : '' ?>" data-label="Replay" data-criterion="replayability">
                            <span class="criterion-score-badge" title="Replayability: <?= (int)$row['replayability'] ?>/10" aria-label="Replayability: <?= (int)$row['replayability'] ?> out of 10">
                                <svg class="criterion-svg icon-replayability" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                                <span class="criterion-num"><?= (int)$row['replayability'] ?></span>
                            </span>
                        </td>
                        <td class="col-criteria col-criteria-melody text-center font-mono <?= (int)$row['melody'] >= 9 ? 'high-score' : '' ?>" data-label="Melody" data-criterion="melody">
                            <span class="criterion-score-badge" title="Melody: <?= (int)$row['melody'] ?>/10" aria-label="Melody: <?= (int)$row['melody'] ?> out of 10">
                                <svg class="criterion-svg icon-melody" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
                                <span class="criterion-num"><?= (int)$row['melody'] ?></span>
                            </span>
                        </td>
                        <td class="col-criteria col-criteria-vocals text-center font-mono <?= (int)$row['vocals'] >= 9 ? 'high-score' : '' ?>" data-label="Vocals" data-criterion="vocals">
                            <span class="criterion-score-badge" title="Vocals: <?= (int)$row['vocals'] ?>/10" aria-label="Vocals: <?= (int)$row['vocals'] ?> out of 10">
                                <svg class="criterion-svg icon-vocals" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                                <span class="criterion-num"><?= (int)$row['vocals'] ?></span>
                            </span>
                        </td>
                        <td class="col-criteria col-criteria-lyrics text-center font-mono <?= (int)$row['lyrics'] >= 9 ? 'high-score' : '' ?>" data-label="Lyrics" data-criterion="lyrics">
                            <span class="criterion-score-badge" title="Lyrics: <?= (int)$row['lyrics'] ?>/10" aria-label="Lyrics: <?= (int)$row['lyrics'] ?> out of 10">
                                <svg class="criterion-svg icon-lyrics" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                                <span class="criterion-num"><?= (int)$row['lyrics'] ?></span>
                            </span>
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

        <?php if ($totalPages > 1): ?>
            <?= render_accessible_pagination(
                $page, 
                $totalPages, 
                $totalMatching, 
                $perPage, 
                'tournament.php', 
                [
                    'round_id' => $selectedRoundId,
                    'q'        => $searchQuery
                ], 
                'ranked records',
                10
            ); ?>
        <?php endif; ?>
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
