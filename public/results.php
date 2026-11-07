<?php
/**
 * AFM#2 - Tournament Archive & Historical Results
 * Permanent Hall of Fame and stage-by-stage audit trail of qualifications, eliminations, and the 2026 Champion.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/import_helper.php';

require_auth();

$currentUserId = get_current_user_id();
$historicalData = get_historical_results($currentUserId);

// CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="AFM2_2026_Tournament_Results_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'Stage', 
        'Rank Position', 
        'Song Title', 
        'Artist', 
        'Total Score', 
        'Emotion', 
        'Replayability', 
        'Melody', 
        'Vocals', 
        'Lyrics', 
        'Outcome', 
        'Official Reason', 
        'Decided At'
    ]);

    foreach ($historicalData as $stage) {
        foreach ($stage['results'] as $row) {
            fputcsv($output, [
                sanitize_csv_cell($stage['round_name']),
                sanitize_csv_cell($row['rank_position'], true),
                sanitize_csv_cell($row['title']),
                sanitize_csv_cell($row['artist']),
                sanitize_csv_cell($row['total'], true),
                sanitize_csv_cell($row['emotion'], true),
                sanitize_csv_cell($row['replayability'], true),
                sanitize_csv_cell($row['melody'], true),
                sanitize_csv_cell($row['vocals'], true),
                sanitize_csv_cell($row['lyrics'], true),
                sanitize_csv_cell(strtoupper((string)($row['result'] ?? ''))),
                sanitize_csv_cell($row['reason']),
                sanitize_csv_cell($row['decided_at'])
            ]);
        }
    }

    fclose($output);
    exit;
}

$pageTitle = 'Tournament Archive & Results — AFM#2 2026';

// Retrieve authoritative Champion from finalized Finals stage
$winnerSong = get_tournament_champion($currentUserId);

$canonicalStageOrder = [
    1 => 'Formal Auditions',
    2 => 'Top 200',
    3 => 'Top 100',
    4 => 'Top 50',
    5 => 'Top 20',
    6 => 'Top 10',
    7 => 'Finals'
];

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-container">
    <!-- Header -->
    <div class="page-header" style="margin-bottom: 1.5rem;">
        <div>
            <h1 class="page-title">Tournament Archive &amp; Hall of Fame</h1>
            <p class="page-subtitle">
                Comprehensive historical audit trail of stage decisions, qualifications, and final tournament rankings.
            </p>
        </div>
        <div class="header-actions">
            <?php if (!empty($historicalData)): ?>
                <a href="results.php?export=csv" class="btn btn-secondary" title="Download results as CSV">
                    <span class="btn-icon">📥</span> Export CSV
                </a>
            <?php endif; ?>
            <a href="tournament.php" class="btn btn-primary">
                <span class="btn-icon">🏆</span> Active Tournament
            </a>
        </div>
    </div>

    <!-- Prominent Stage Navigation Component (Sticky) -->
    <?php if (!empty($historicalData) || $winnerSong): ?>
    <div class="archive-nav-sticky-wrap">
        <div class="archive-stage-nav" id="archiveStageNav">
            <?php if ($winnerSong): ?>
                <a href="#stage-champion" class="archive-nav-item item-champion" id="navItemChampion">
                    <span>👑 Champion</span>
                    <span class="archive-nav-count"><?= (int)$winnerSong['total'] ?>/50</span>
                </a>
            <?php endif; ?>
            <?php foreach ($canonicalStageOrder as $seq => $name): 
                $stgData = $historicalData[$name] ?? null;
                $pCount = $stgData ? count($stgData['results']) : 0;
                $hasData = ($stgData !== null && $pCount > 0);
            ?>
                <a 
                    href="#stage-round-<?= $seq ?>" 
                    class="archive-nav-item <?= $hasData ? '' : 'disabled' ?>"
                    data-stage-seq="<?= $seq ?>"
                    title="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?><?= $hasData ? " ({$pCount} songs archived)" : ' (Not yet completed)' ?>"
                >
                    <span><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($hasData): ?>
                        <span class="archive-nav-count"><?= $pCount ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="archive-controls-bar">
            <div class="archive-search-box">
                <span class="archive-search-icon">🔍</span>
                <input type="text" id="archiveFilterInput" placeholder="Filter archived songs by title or artist..." aria-label="Filter archived songs">
            </div>
            <div class="archive-toggle-actions">
                <button type="button" class="btn btn-xs btn-secondary" id="btnExpandAllStages">
                    <span>▼</span> Expand All
                </button>
                <button type="button" class="btn btn-xs btn-secondary" id="btnCollapseAllStages">
                    <span>▲</span> Collapse All
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Champion Podium (Authoritative Finals Result) -->
    <?php if ($winnerSong): ?>
    <section class="archive-stage-section" id="stage-champion">
        <div class="card champion-card mb-4">
            <div class="card-body p-4 text-center">
                <div class="champion-crown-wrap">
                    <span class="champion-crown" aria-hidden="true">👑</span>
                </div>
                <div class="champion-badge">AFM#2 2026 TOURNAMENT CHAMPION</div>
                <h2 class="champion-title mt-2"><?= htmlspecialchars($winnerSong['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="champion-artist mt-1 font-semibold">
                    <?= htmlspecialchars($winnerSong['artist'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                
                <div class="champion-score-box mt-3">
                    <div class="champion-total font-mono font-bold">
                        <span class="champ-score-val"><?= (int)$winnerSong['total'] ?></span> <span class="champ-score-max">/ 50</span>
                    </div>
                    <div class="champion-criteria mt-3">
                        <span class="criteria-tag" title="Emotion: <?= (int)$winnerSong['emotion'] ?>/10">
                            <svg class="criterion-svg icon-emotion" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                            <span class="crit-name">Emotion</span>
                            <strong><?= (int)$winnerSong['emotion'] ?></strong>
                        </span>
                        <span class="criteria-tag" title="Replay: <?= (int)$winnerSong['replayability'] ?>/10">
                            <svg class="criterion-svg icon-replayability" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                            <span class="crit-name">Replay</span>
                            <strong><?= (int)$winnerSong['replayability'] ?></strong>
                        </span>
                        <span class="criteria-tag" title="Melody: <?= (int)$winnerSong['melody'] ?>/10">
                            <svg class="criterion-svg icon-harmonic" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <span class="crit-name">Melody</span>
                            <strong><?= (int)$winnerSong['melody'] ?></strong>
                        </span>
                        <span class="criteria-tag" title="Vocals: <?= (int)$winnerSong['vocals'] ?>/10">
                            <svg class="criterion-svg icon-vocals" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                            <span class="crit-name">Vocals</span>
                            <strong><?= (int)$winnerSong['vocals'] ?></strong>
                        </span>
                        <span class="criteria-tag" title="Lyrics: <?= (int)$winnerSong['lyrics'] ?>/10">
                            <svg class="criterion-svg icon-lyrics" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            <span class="crit-name">Lyrics</span>
                            <strong><?= (int)$winnerSong['lyrics'] ?></strong>
                        </span>
                    </div>
                </div>

                <p class="champion-note text-sm text-muted mt-3">
                    Decided in <strong><?= htmlspecialchars($winnerSong['winning_stage'] ?? 'Finals', ENT_QUOTES, 'UTF-8') ?></strong> &bull; Completed on <?= htmlspecialchars(format_local_timestamp($winnerSong['decided_at'] ?? null, 'F j, Y')) ?>
                </p>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Historical Stages List -->
    <?php if (empty($historicalData)): ?>
        <div class="empty-state">
            <div class="empty-icon">📜</div>
            <h2 class="empty-title">No Completed Stages in Archive</h2>
            <p class="empty-desc">
                The tournament has not concluded any stages yet. Once Formal Auditions or subsequent stages are advanced,
                their complete qualification and elimination records will be permanently archived here.
            </p>
            <div class="empty-actions">
                <a href="tournament.php" class="btn btn-accent">Go to Active Stage</a>
                <a href="auditions.php" class="btn btn-secondary">Score Auditions</a>
            </div>
        </div>
    <?php else: ?>
        <div class="archive-stages-list">
            <?php 
            // Render stages in canonical sequence order
            foreach ($canonicalStageOrder as $seq => $stageName): 
                if (!isset($historicalData[$stageName])) {
                    continue;
                }
                $stage = $historicalData[$stageName];
                $results = $stage['results'];
                $totalInStage = count($results);
                $qualCount = 0;
                $elimCount = 0;
                foreach ($results as $res) {
                    if ($res['result'] === 'qualified' || $res['result'] === 'winner') {
                        $qualCount++;
                    } else {
                        $elimCount++;
                    }
                }
            ?>
            <section class="archive-stage-section" id="stage-round-<?= $seq ?>" data-stage-seq="<?= $seq ?>" data-stage-name="<?= htmlspecialchars($stage['round_name'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="card mb-4 archive-stage-card" id="card-stage-<?= $seq ?>">
                    <div class="card-header flex-between align-center">
                        <div>
                            <div class="flex-align-center gap-2">
                                <h3 class="card-title text-lg font-bold mb-0">
                                    Stage <?= $seq ?>: <?= htmlspecialchars($stage['round_name'], ENT_QUOTES, 'UTF-8') ?>
                                </h3>
                                <span class="badge badge-success text-xs">Stage Completed</span>
                            </div>
                            <?php if ($stage['completed_at']): ?>
                                <div class="text-xs text-muted mt-1">
                                    Completed on <?= htmlspecialchars(format_local_timestamp($stage['completed_at'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="flex-align-center gap-3" style="flex-wrap: wrap;">
                            <div class="stage-summary-pills flex-align-center gap-2">
                                <span class="badge badge-accent">Total: <?= $totalInStage ?></span>
                                <span class="badge badge-success">Advanced: <?= $qualCount ?></span>
                                <span class="badge badge-danger">Eliminated: <?= $elimCount ?></span>
                            </div>
                            <button type="button" class="stage-collapse-btn" aria-expanded="true" data-target="#card-stage-<?= $seq ?>">
                                <span class="stage-collapse-icon">▼</span>
                                <span class="stage-collapse-text">Collapse</span>
                            </button>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="stage-table-scroll-container">
                            <table class="data-table table-sm archive-table">
                                <thead>
                                    <tr>
                                        <th class="col-rank">Rank</th>
                                        <th class="col-song">Song Title &amp; Artist</th>
                                        <th class="col-total text-center">Score</th>
                                        <th class="col-criteria text-center" title="Emotion">
                                            <span class="th-criterion"><svg class="criterion-svg icon-emotion" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg> Emo</span>
                                        </th>
                                        <th class="col-criteria text-center" title="Replayability">
                                            <span class="th-criterion"><svg class="criterion-svg icon-replayability" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg> Rep</span>
                                        </th>
                                        <th class="col-criteria text-center" title="Melody">
                                            <span class="th-criterion"><svg class="criterion-svg icon-harmonic" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> Mel</span>
                                        </th>
                                        <th class="col-criteria text-center" title="Vocals">
                                            <span class="th-criterion"><svg class="criterion-svg icon-vocals" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg> Voc</span>
                                        </th>
                                        <th class="col-criteria text-center" title="Lyrics">
                                            <span class="th-criterion"><svg class="criterion-svg icon-lyrics" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> Lyr</span>
                                        </th>
                                        <th class="col-status text-center">Outcome</th>
                                        <th class="col-reason">Audit Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $item): 
                                        $rankPos = (int)$item['rank_position'];
                                        $outcome = $item['result'];
                                    ?>
                                    <tr class="archive-row">
                                        <td class="col-rank font-bold text-center" data-label="Rank">
                                            <div class="archive-rank-badge rank-badge <?= $rankPos <= 3 ? 'rank-podium rank-' . $rankPos : '' ?>">
                                                #<?= $rankPos ?>
                                            </div>
                                        </td>
                                        <td class="col-song col-title-artist" data-label="Song">
                                            <div class="archive-mobile-card-header">
                                                <span class="archive-mobile-rank rank-badge <?= $rankPos <= 3 ? 'rank-podium rank-' . $rankPos : '' ?>">
                                                    #<?= $rankPos ?>
                                                </span>
                                                <span class="archive-mobile-outcome">
                                                    <?php if ($outcome === 'winner'): ?>
                                                        <span class="badge badge-gold font-bold">👑 Winner</span>
                                                    <?php elseif ($outcome === 'qualified'): ?>
                                                        <span class="badge badge-success">Qualified</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-danger">Eliminated</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="song-meta">
                                                <span class="song-title font-bold candidate-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="song-artist text-muted text-xs candidate-artist"><?= htmlspecialchars($item['artist'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </td>
                                        <td class="col-total text-center font-mono font-bold text-accent" data-label="Score">
                                            <div class="archive-score-pill font-mono">
                                                <?= (int)$item['total'] ?> <span class="text-xs text-muted">/50</span>
                                            </div>
                                        </td>
                                        <td class="col-criteria col-criteria-emotion text-center font-mono text-xs" data-label="Emotion" data-criterion="emotion">
                                            <span class="archive-criterion-badge" title="Emotion: <?= (int)$item['emotion'] ?>/10">
                                                <svg class="criterion-svg icon-emotion" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                                <span class="crit-num"><?= (int)$item['emotion'] ?></span>
                                            </span>
                                        </td>
                                        <td class="col-criteria col-criteria-replay text-center font-mono text-xs" data-label="Replay" data-criterion="replayability">
                                            <span class="archive-criterion-badge" title="Replay: <?= (int)$item['replayability'] ?>/10">
                                                <svg class="criterion-svg icon-replayability" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                                                <span class="crit-num"><?= (int)$item['replayability'] ?></span>
                                            </span>
                                        </td>
                                        <td class="col-criteria col-criteria-melody text-center font-mono text-xs" data-label="Melody" data-criterion="melody">
                                            <span class="archive-criterion-badge" title="Melody: <?= (int)$item['melody'] ?>/10">
                                                <svg class="criterion-svg icon-harmonic" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                                <span class="crit-num"><?= (int)$item['melody'] ?></span>
                                            </span>
                                        </td>
                                        <td class="col-criteria col-criteria-vocals text-center font-mono text-xs" data-label="Vocals" data-criterion="vocals">
                                            <span class="archive-criterion-badge" title="Vocals: <?= (int)$item['vocals'] ?>/10">
                                                <svg class="criterion-svg icon-vocals" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                                                <span class="crit-num"><?= (int)$item['vocals'] ?></span>
                                            </span>
                                        </td>
                                        <td class="col-criteria col-criteria-lyrics text-center font-mono text-xs" data-label="Lyrics" data-criterion="lyrics">
                                            <span class="archive-criterion-badge" title="Lyrics: <?= (int)$item['lyrics'] ?>/10">
                                                <svg class="criterion-svg icon-lyrics" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                                <span class="crit-num"><?= (int)$item['lyrics'] ?></span>
                                            </span>
                                        </td>
                                        <td class="col-status text-center" data-label="Outcome">
                                            <?php if ($outcome === 'winner'): ?>
                                                <span class="badge badge-gold font-bold">👑 Winner</span>
                                            <?php elseif ($outcome === 'qualified'): ?>
                                                <span class="badge badge-success">Qualified</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Eliminated</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-reason text-xs text-muted" data-label="Audit Reason">
                                            <?= htmlspecialchars($item['reason'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="stage-table-footer-actions">
                            <span>Showing <?= $totalInStage ?> historical records for <?= htmlspecialchars($stage['round_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                </div>
            </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__) . '/includes/footer.php';
