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

// Check for Champion / Winner in any stage
$winnerSong = null;
foreach ($historicalData as $stage) {
    foreach ($stage['results'] as $r) {
        if ($r['result'] === 'winner') {
            $winnerSong = $r;
            $winnerSong['winning_stage'] = $stage['round_name'];
            break 2;
        }
    }
}

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-container">
    <!-- Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Tournament Archive & Hall of Fame</h1>
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

    <!-- Champion Podium (if Finals decided) -->
    <?php if ($winnerSong): ?>
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
                Decided in <strong><?= htmlspecialchars($winnerSong['winning_stage'], ENT_QUOTES, 'UTF-8') ?></strong> on <?= htmlspecialchars(format_local_timestamp($winnerSong['decided_at'] ?? null, 'F j, Y')) ?>
            </p>
        </div>
    </div>
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
            <?php foreach ($historicalData as $stageName => $stage): 
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
            <div class="card mb-4 archive-stage-card">
                <div class="card-header flex-between align-center">
                    <div>
                        <div class="flex-align-center gap-2">
                            <h3 class="card-title text-lg font-bold mb-0">
                                <?= htmlspecialchars($stage['round_name'], ENT_QUOTES, 'UTF-8') ?>
                            </h3>
                            <span class="badge badge-success text-xs">Stage Completed</span>
                        </div>
                        <?php if ($stage['completed_at']): ?>
                            <div class="text-xs text-muted mt-1">
                                Completed on <?= htmlspecialchars(format_local_timestamp($stage['completed_at'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="stage-summary-pills flex-align-center gap-2" style="flex-wrap: wrap;">
                        <span class="badge badge-accent">Total: <?= $totalInStage ?></span>
                        <span class="badge badge-success">Advanced: <?= $qualCount ?></span>
                        <span class="badge badge-danger">Eliminated: <?= $elimCount ?></span>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-container">
                        <table class="data-table table-sm">
                            <thead>
                                <tr>
                                    <th class="col-rank">Rank</th>
                                    <th class="col-song">Song Title & Artist</th>
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
                                <tr>
                                    <td class="col-rank font-bold text-center" data-label="Rank">
                                        #<?= $rankPos ?>
                                    </td>
                                    <td class="col-song" data-label="Song">
                                        <div class="song-meta">
                                            <span class="song-title font-bold"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="song-artist text-muted text-xs"><?= htmlspecialchars($item['artist'], ENT_QUOTES, 'UTF-8') ?></span>
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
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__) . '/includes/footer.php';
