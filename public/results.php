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
                <div class="champion-crown">👑</div>
                <div class="champion-badge">AFM#2 2026 CHAMPION</div>
                <h2 class="champion-title mt-2"><?= htmlspecialchars($winnerSong['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="champion-artist text-gold text-lg mt-1 font-semibold">
                    <?= htmlspecialchars($winnerSong['artist'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                
                <div class="champion-score-box mt-3">
                    <div class="champion-total font-mono font-bold">
                        <?= (int)$winnerSong['total'] ?> <span class="text-sm text-muted">/ 50</span>
                    </div>
                    <div class="champion-criteria mt-2">
                        <span class="criteria-tag">Emotion: <strong><?= (int)$winnerSong['emotion'] ?></strong></span>
                        <span class="criteria-tag">Replay: <strong><?= (int)$winnerSong['replayability'] ?></strong></span>
                        <span class="criteria-tag">Melody: <strong><?= (int)$winnerSong['melody'] ?></strong></span>
                        <span class="criteria-tag">Vocals: <strong><?= (int)$winnerSong['vocals'] ?></strong></span>
                        <span class="criteria-tag">Lyrics: <strong><?= (int)$winnerSong['lyrics'] ?></strong></span>
                    </div>
                </div>

                <p class="champion-note text-sm text-muted mt-3">
                    Decided in <strong><?= htmlspecialchars($winnerSong['winning_stage'] ?? 'Finals', ENT_QUOTES, 'UTF-8') ?></strong> on <?= htmlspecialchars(format_local_timestamp($winnerSong['decided_at'] ?? null, 'F j, Y')) ?>
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
                                        <th class="col-criteria text-center">Emo</th>
                                        <th class="col-criteria text-center">Rep</th>
                                        <th class="col-criteria text-center">Mel</th>
                                        <th class="col-criteria text-center">Voc</th>
                                        <th class="col-criteria text-center">Lyr</th>
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
                                            #<?= $rankPos ?>
                                        </td>
                                        <td class="col-song col-title-artist" data-label="Song">
                                            <div class="song-meta">
                                                <span class="song-title font-bold candidate-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="song-artist text-muted text-xs candidate-artist"><?= htmlspecialchars($item['artist'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </td>
                                        <td class="col-total text-center font-mono font-bold text-accent" data-label="Score">
                                            <?= (int)$item['total'] ?>
                                        </td>
                                        <td class="col-criteria text-center font-mono text-xs" data-label="Emotion"><?= (int)$item['emotion'] ?></td>
                                        <td class="col-criteria text-center font-mono text-xs" data-label="Replay"><?= (int)$item['replayability'] ?></td>
                                        <td class="col-criteria text-center font-mono text-xs" data-label="Melody"><?= (int)$item['melody'] ?></td>
                                        <td class="col-criteria text-center font-mono text-xs" data-label="Vocals"><?= (int)$item['vocals'] ?></td>
                                        <td class="col-criteria text-center font-mono text-xs" data-label="Lyrics"><?= (int)$item['lyrics'] ?></td>
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
