<?php
/**
 * AFM#2 - YouTube Recording Discovery, Matching & Manual Correction Interface
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';
require_once dirname(__DIR__) . '/includes/youtube_matcher.php';

require_auth();

$pageTitle = 'Recordings — AFM#2 YouTube Matching & Verification';
$currentUserId = get_current_user_id();

// Query parameters
$filter = trim($_GET['filter'] ?? 'all');
$query = trim($_GET['q'] ?? '');
$sortBy = trim($_GET['sort'] ?? 'id_asc');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [25, 50, 100], true)) {
    $perPage = 25;
}

$allowedFilters = ['all', 'unmatched', 'high', 'review', 'low', 'manual', 'blocked'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

// Fetch catalogue listing and stats
$catalogueData = YouTubeMatcher::get_recordings_catalogue([
    'filter'   => $filter,
    'q'        => $query,
    'sort'     => $sortBy,
    'page'     => $page,
    'per_page' => $perPage
], $currentUserId);

$items = $catalogueData['items'];
$total = $catalogueData['total'];
$totalPages = $catalogueData['total_pages'];
$stats = $catalogueData['stats'];

// Active job state
$activeJob = YouTubeMatcher::get_or_create_active_job($currentUserId);

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-container" id="recordingsViewContainer">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-title-group">
            <h1 class="page-title">YouTube Recording Discovery &amp; Matching</h1>
            <p class="page-subtitle">
                Automated multi-signal YouTube candidate ranking, persistent MySQL linking, and manual correction interface for <?= number_format($stats['total_songs']) ?> catalogue tracks.
            </p>
        </div>
        <div class="header-actions">
            <a href="library.php" class="btn btn-secondary" title="Return to Song Library">
                &larr; Song Library
            </a>
            <button type="button" class="btn btn-secondary" id="btnRefreshStats" title="Refresh catalogue matching statistics">
                <span>🔄</span> Refresh Stats
            </button>
        </div>
    </div>

    <!-- Catalogue Statistics Grid -->
    <div class="stats-grid mb-4" id="statsGridStrip">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Total Catalogue</span>
                <span class="stat-icon icon-library">♪</span>
            </div>
            <div class="stat-value text-accent" id="statTotalSongs"><?= number_format($stats['total_songs']) ?></div>
            <div class="stat-desc">Authentic YouTube Music tracks</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Matched &amp; Linked</span>
                <span class="stat-icon icon-completed">✓</span>
            </div>
            <div class="stat-value text-success" id="statMatchedTotal"><?= number_format($stats['matched_total']) ?></div>
            <div class="stat-desc">
                <?= number_format($stats['auto_high_count']) ?> Auto High &bull; <?= number_format($stats['manual_count']) ?> Manual
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Review Needed</span>
                <span class="stat-icon icon-in-progress">⚠️</span>
            </div>
            <div class="stat-value text-gold" id="statReviewNeeded"><?= number_format($stats['review_needed']) ?></div>
            <div class="stat-desc">Medium confidence candidates (50–78%)</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Unresolved</span>
                <span class="stat-icon icon-unreviewed">○</span>
            </div>
            <div class="stat-value text-muted" id="statLowUnresolved"><?= number_format($stats['low_unresolved']) ?></div>
            <div class="stat-desc">Low confidence candidates (&lt;50%)</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Manual Selections</span>
                <span class="stat-icon" style="color: #a855f7;">🔒</span>
            </div>
            <div class="stat-value" style="color: #a855f7;" id="statManualCount"><?= number_format($stats['manual_count']) ?></div>
            <div class="stat-desc">Protected from auto-overwrites</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Embed Blocked</span>
                <span class="stat-icon icon-danger">🚫</span>
            </div>
            <div class="stat-value text-danger" id="statBlockedCount"><?= number_format($stats['blocked_count']) ?></div>
            <div class="stat-desc">Disabled embedding or restricted</div>
        </div>
    </div>

    <!-- Automated Bulk Matching Runner Panel -->
    <div class="batch-runner-panel" id="batchRunnerPanel">
        <div class="batch-runner-header">
            <div class="batch-runner-title-group">
                <h2><span class="batch-spark-icon">⚡</span> Automated Resumable Bulk Matcher</h2>
                <p>Discovers candidates via structured YouTube metadata, evaluates 7 weighted signals, auto-selects &ge;78% matches, and protects manual selections.</p>
            </div>
            <div class="batch-runner-actions">
                <button type="button" class="btn btn-primary" id="btnStartBatch">
                    <span id="startBatchIcon">▶</span> <span id="startBatchText">Start Auto-Match</span>
                </button>
                <button type="button" class="btn btn-secondary" id="btnPauseBatch" style="display: none;">
                    ⏸ Pause
                </button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnResetJob" title="Reset batch runner to song ID 1">
                    ↺ Reset Job
                </button>
            </div>
        </div>

        <!-- Sleek Status & Metrics Strip -->
        <div class="batch-metrics-strip">
            <div class="batch-stat-card">
                <span class="batch-stat-label">Job Status</span>
                <div class="batch-status-pill status-<?= strtolower($activeJob['status'] ?? 'idle') ?>" id="jobStatusPill">
                    <span class="status-pulse-dot"></span>
                    <strong id="jobStatusBadge"><?= htmlspecialchars(strtoupper($activeJob['status'] ?? 'IDLE')) ?></strong>
                </div>
            </div>
            <div class="batch-stat-card">
                <span class="batch-stat-label">Processed</span>
                <div class="batch-stat-value">
                    <span id="jobProcessedCount"><?= number_format($activeJob['processed_count'] ?? 0) ?></span>
                    <span class="batch-stat-total">/ <?= number_format($stats['total_songs']) ?></span>
                </div>
            </div>
            <div class="batch-stat-card">
                <span class="batch-stat-label">Coverage</span>
                <div class="batch-stat-value text-accent" id="batchPercentLabel">
                    <?= $stats['total_songs'] > 0 ? round((($activeJob['processed_count'] ?? 0) / $stats['total_songs']) * 100, 1) : 0 ?>%
                </div>
            </div>
            <div class="batch-stat-card">
                <span class="batch-stat-label">Catalogue Cursor</span>
                <div class="batch-stat-value font-mono" id="logTimer">
                    Last Song ID: #<?= (int)($activeJob['last_song_id'] ?? 0) ?>
                </div>
            </div>
        </div>

        <!-- Progress Bar with Glow -->
        <div class="batch-progress-box">
            <div class="batch-progress-track">
                <div class="batch-progress-fill" id="batchProgressFill" style="width: <?= $stats['total_songs'] > 0 ? min(100, round((($activeJob['processed_count'] ?? 0) / $stats['total_songs']) * 100, 1)) : 0 ?>%;"></div>
            </div>
        </div>

        <!-- Terminal Console Feed -->
        <div class="batch-live-log" id="batchLiveLog">
            <span class="console-prompt">❯</span>
            <span id="logMessage">Ready. Click "Start Auto-Match" to resume processing the catalogue in non-blocking batches.</span>
        </div>
    </div>

    <!-- Search, Filter & Sort Controls -->
    <form method="GET" action="recordings.php" class="filter-bar mb-4" id="recordingsFilterForm">
        <!-- Row 1: Search, Sort, Per Page -->
        <div class="filter-bar-row filter-bar-row-top">
            <div class="search-input-wrapper">
                <span class="search-icon" aria-hidden="true">🔍</span>
                <input 
                    type="text" 
                    name="q" 
                    class="search-input" 
                    placeholder="Search by title, artist, or YouTube video ID..." 
                    value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>"
                    id="recordingsSearchInput"
                >
                <button type="submit" class="btn btn-primary btn-sm btn-search-apply" id="btnSearchApply" title="Execute search">
                    Apply
                </button>
                <?php if ($query !== ''): ?>
                    <a href="recordings.php?filter=<?= urlencode($filter) ?>&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" class="btn-search-clear" title="Clear search query" aria-label="Clear search">&times;</a>
                <?php endif; ?>
            </div>

            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>" id="filterValueHidden">

            <div class="filter-selectors-cluster">
                <div class="sort-input-group">
                    <label for="sortSelect" class="filter-label"><span aria-hidden="true">⇅</span> Sort:</label>
                    <select name="sort" id="sortSelect" class="sort-select" onchange="document.getElementById('recordingsFilterForm').submit()">
                        <option value="id_asc" <?= $sortBy === 'id_asc' ? 'selected' : '' ?>>Catalogue ID (Default)</option>
                        <option value="id_desc" <?= $sortBy === 'id_desc' ? 'selected' : '' ?>>Newest First</option>
                        <option value="title_asc" <?= $sortBy === 'title_asc' ? 'selected' : '' ?>>Title (A &rarr; Z)</option>
                        <option value="title_desc" <?= $sortBy === 'title_desc' ? 'selected' : '' ?>>Title (Z &rarr; A)</option>
                        <option value="artist_asc" <?= $sortBy === 'artist_asc' ? 'selected' : '' ?>>Artist (A &rarr; Z)</option>
                        <option value="score_desc" <?= $sortBy === 'score_desc' ? 'selected' : '' ?>>Match Score (High &rarr; Low)</option>
                        <option value="score_asc" <?= $sortBy === 'score_asc' ? 'selected' : '' ?>>Match Score (Low &rarr; High)</option>
                    </select>
                </div>

                <div class="sort-input-group">
                    <label for="perPageSelect" class="filter-label"><span aria-hidden="true">📄</span> Per Page:</label>
                    <select name="per_page" id="perPageSelect" class="sort-select" onchange="document.getElementById('recordingsFilterForm').submit()">
                        <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25 per page</option>
                        <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50 per page</option>
                        <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100 per page</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Row 2: Status Filter Pills -->
        <div class="filter-bar-row filter-bar-row-bottom">
            <div class="filter-pills">
                <a href="recordings.php?filter=all<?= $query !== '' ? '&q=' . urlencode($query) : '' ?>&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" 
                   class="filter-pill <?= $filter === 'all' ? 'active' : '' ?>">
                    All (<?= number_format($stats['total_songs']) ?>)
                </a>
                <a href="recordings.php?filter=unmatched<?= $query !== '' ? '&q=' . urlencode($query) : '' ?>&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" 
                   class="filter-pill <?= $filter === 'unmatched' ? 'active' : '' ?>">
                    Unmatched (<?= number_format($stats['unmatched_total']) ?>)
                </a>
                <a href="recordings.php?filter=high<?= $query !== '' ? '&q=' . urlencode($query) : '' ?>&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" 
                   class="filter-pill <?= $filter === 'high' ? 'active' : '' ?>">
                    High Confidence (<?= number_format($stats['auto_high_count']) ?>)
                </a>
                <a href="recordings.php?filter=review<?= $query !== '' ? '&q=' . urlencode($query) : '' ?>&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" 
                   class="filter-pill <?= $filter === 'review' ? 'active' : '' ?>">
                    Review Needed (<?= number_format($stats['review_needed']) ?>)
                </a>
                <a href="recordings.php?filter=low<?= $query !== '' ? '&q=' . urlencode($query) : '' ?>&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" 
                   class="filter-pill <?= $filter === 'low' ? 'active' : '' ?>">
                    Low Confidence (<?= number_format($stats['low_unresolved']) ?>)
                </a>
                <a href="recordings.php?filter=manual<?= $query !== '' ? '&q=' . urlencode($query) : '' ?>&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" 
                   class="filter-pill <?= $filter === 'manual' ? 'active' : '' ?>">
                    Manual Selections (<?= number_format($stats['manual_count']) ?>)
                </a>
                <a href="recordings.php?filter=blocked<?= $query !== '' ? '&q=' . urlencode($query) : '' ?>&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" 
                   class="filter-pill <?= $filter === 'blocked' ? 'active' : '' ?>">
                    Blocked Embeds (<?= number_format($stats['blocked_count']) ?>)
                </a>
            </div>

            <?php if ($query !== '' || $filter !== 'all' || $sortBy !== 'id_asc' || $perPage !== 25): ?>
                <div class="filter-reset-wrap">
                    <a href="recordings.php" class="btn btn-secondary btn-sm" title="Reset all search queries and filters">
                        <span>↺</span> Reset Filters
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <!-- Main Recordings Table -->
    <div class="recordings-table-wrap">
        <table class="recordings-table" id="recordingsTable">
            <thead>
                <tr>
                    <th class="th-index" style="width: 48px; text-align: center;">#</th>
                    <th class="th-track" style="width: 32%;">Title</th>
                    <th class="th-media" style="width: 25%;">Linked Recording</th>
                    <th class="th-candidate" style="width: 25%;">Top Candidate / Suggestions</th>
                    <th class="th-actions" style="width: 18%; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 3rem 1rem;">
                            <div class="empty-state" style="padding: 1rem 0;">
                                <div class="empty-icon">🎬</div>
                                <h3 class="empty-title">No Tracks Found</h3>
                                <p class="empty-desc">No songs match your active filter (<?= htmlspecialchars($filter) ?>) and query.</p>
                                <a href="recordings.php" class="btn btn-secondary btn-sm" style="margin-top: 1rem;">Clear Filters</a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $row): 
                        $sId = (int)$row['song_id'];
                        $hasRecording = !empty($row['video_id']);
                        $isManual = !empty($row['is_manual']);
                        $confidence = $row['media_confidence'] ?? '';
                        $score = $row['match_score'] !== null ? round((float)$row['match_score'], 1) : null;
                        $embedStatus = $row['embed_status'] ?? 'allowed';
                        $candCount = (int)$row['candidate_count'];
                        $titleEsc = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');
                        $artistEsc = htmlspecialchars($row['artist'] ?? 'Unknown Artist', ENT_QUOTES, 'UTF-8');
                        $artistAttr = htmlspecialchars($row['artist'] ?? '', ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr id="row-song-<?= $sId ?>" class="rec-table-row" data-song-id="<?= $sId ?>" data-title="<?= $titleEsc ?>" data-artist="<?= $artistAttr ?>">
                            <td class="rec-col-index"><span class="track-index-num"><?= $sId ?></span></td>
                            <td class="rec-col-track">
                                <div class="spotify-track-flex">
                                    <div class="spotify-thumb-box">
                                        <?php 
                                            $thumb = $row['artwork_thumbnail_url'] ?? $row['artwork_image_url'] ?? null;
                                            if (empty($thumb) && !empty($row['video_id'])) {
                                                $thumb = "https://img.youtube.com/vi/{$row['video_id']}/hqdefault.jpg";
                                            }
                                            if (empty($thumb) && !empty($row['top_cand_thumb'])) {
                                                $thumb = $row['top_cand_thumb'];
                                            }
                                            if (empty($thumb)) {
                                                $thumb = 'assets/img/artwork-fallback.svg';
                                            }
                                        ?>
                                        <img src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>" alt="" width="40" height="40" class="spotify-track-thumb" loading="lazy">
                                    </div>
                                    <div class="spotify-track-meta">
                                        <div class="spotify-track-title" title="<?= $titleEsc ?>"><?= $titleEsc ?></div>
                                        <div class="spotify-track-artist" title="<?= $artistEsc ?>"><?= $artistEsc ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="rec-col-media" id="media-cell-<?= $sId ?>">
                                <?php if ($hasRecording): 
                                    $vidEsc = htmlspecialchars($row['video_id'], ENT_QUOTES, 'UTF-8');
                                ?>
                                    <div class="rec-linked-card">
                                        <div class="rec-linked-top">
                                            <a href="https://www.youtube.com/watch?v=<?= $vidEsc ?>" target="_blank" rel="noopener noreferrer" class="rec-yt-link" title="Open YouTube video (<?= $vidEsc ?>) in new tab">
                                                <span class="rec-yt-play-icon">▶</span>
                                                <span class="rec-yt-code"><?= $vidEsc ?></span>
                                                <span class="rec-yt-arrow">↗</span>
                                            </a>
                                        </div>
                                        <div class="rec-badges-row">
                                            <?php if ($isManual): ?>
                                                <span class="badge badge-confidence-manual" title="Manually confirmed/entered link — protected from auto-match overwrites">🔒 Manual</span>
                                            <?php elseif ($confidence === 'high'): ?>
                                                <span class="badge badge-confidence-high" title="Score: <?= $score ?>% (High Confidence Auto-Match)">✓ Matched (<?= $score ?>%)</span>
                                            <?php elseif ($confidence === 'medium'): ?>
                                                <span class="badge badge-confidence-medium" title="Score: <?= $score ?>% (Review Recommended)">⚠️ Review (<?= $score ?>%)</span>
                                            <?php else: ?>
                                                <span class="badge badge-confidence-low" title="Score: <?= $score ?? '0' ?>%">○ Low (<?= $score ?? '0' ?>%)</span>
                                            <?php endif; ?>

                                            <?php if ($embedStatus === 'blocked'): ?>
                                                <span class="badge badge-embed-blocked" title="Embedding disabled by creator or video unavailable">🚫 Blocked</span>
                                            <?php else: ?>
                                                <span class="badge badge-embed-allowed" title="Playback allowed in embedded player">✓ Embed</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="rec-unmatched-card">
                                        <span class="rec-unmatched-dot"></span>
                                        <span class="rec-unmatched-text">Unmatched</span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="rec-col-candidate" id="cand-cell-<?= $sId ?>">
                                <?php if ($candCount > 0): 
                                    $topScore = (float)($row['top_cand_score'] ?? 0);
                                    $scoreBadgeClass = $topScore >= 78 ? 'cand-badge-high' : ($topScore >= 50 ? 'cand-badge-medium' : 'cand-badge-low');
                                    $topCandTitle = htmlspecialchars($row['top_cand_title'] ?? '', ENT_QUOTES, 'UTF-8');
                                ?>
                                    <div class="rec-candidate-box">
                                        <div class="rec-cand-score-line">
                                            <span class="cand-score-chip <?= $scoreBadgeClass ?>"><?= number_format($topScore, 1) ?>% Match</span>
                                            <span class="cand-count-chip"><?= $candCount ?> found</span>
                                        </div>
                                        <div class="rec-cand-title" title="<?= $topCandTitle ?>"><?= $topCandTitle ?></div>
                                        <?php if (!empty($row['top_cand_channel'])): ?>
                                            <div class="rec-cand-channel">
                                                <span class="cand-channel-icon">📺</span> <?= htmlspecialchars(mb_substr($row['top_cand_channel'], 0, 24), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="rec-candidate-empty">
                                        <span class="rec-empty-dot"></span>
                                        <span class="rec-empty-text">No candidates scanned</span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="rec-col-actions" style="text-align: right;">
                                <div class="rec-actions-flex">
                                    <button type="button" class="btn btn-secondary btn-xs btn-open-candidates" data-song-id="<?= $sId ?>" title="Review, rank, or scan candidate YouTube videos"><span>⚡</span> Candidates (<?= $candCount ?>)</button>
                                    <button type="button" class="btn btn-secondary btn-xs btn-open-manual" data-song-id="<?= $sId ?>" data-current-url="<?= htmlspecialchars($row['user_url'] ?? ($hasRecording ? 'https://www.youtube.com/watch?v=' . $row['video_id'] : ''), ENT_QUOTES, 'UTF-8') ?>" title="Manually paste or correct YouTube URL"><span>🔗</span> URL</button>
                                    <?php if ($hasRecording): ?>
                                        <button type="button" class="btn btn-secondary btn-xs btn-preview-video" data-video-id="<?= htmlspecialchars($row['video_id'], ENT_QUOTES, 'UTF-8') ?>" data-title="<?= $titleEsc ?>" title="Preview playback inside player">▶</button>
                                        <button type="button" class="btn btn-danger-subtle btn-xs btn-clear-rec" data-song-id="<?= $sId ?>" title="Clear recording link (preserves song)">&times;</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination-container" id="recordingsPagination">
            <div class="pagination-info">
                Showing <?= number_format((($page - 1) * $perPage) + 1) ?>–<?= number_format(min($total, $page * $perPage)) ?> of <?= number_format($total) ?> songs
            </div>
            <div class="pagination-controls">
                <?php if ($page > 1): ?>
                    <a href="recordings.php?page=1<?= $filter !== 'all' ? '&filter=' . urlencode($filter) : '' ?><?= $query !== '' ? '&q=' . urlencode($query) : '' ?><?= $sortBy !== 'id_asc' ? '&sort=' . urlencode($sortBy) : '' ?>&per_page=<?= $perPage ?>" class="btn-pagination" title="First page">&laquo; First</a>
                    <a href="recordings.php?page=<?= $page - 1 ?><?= $filter !== 'all' ? '&filter=' . urlencode($filter) : '' ?><?= $query !== '' ? '&q=' . urlencode($query) : '' ?><?= $sortBy !== 'id_asc' ? '&sort=' . urlencode($sortBy) : '' ?>&per_page=<?= $perPage ?>" class="btn-pagination" title="Previous page">&lsaquo; Prev</a>
                <?php endif; ?>

                <span class="pagination-current">Page <?= $page ?> of <?= $totalPages ?></span>

                <?php if ($page < $totalPages): ?>
                    <a href="recordings.php?page=<?= $page + 1 ?><?= $filter !== 'all' ? '&filter=' . urlencode($filter) : '' ?><?= $query !== '' ? '&q=' . urlencode($query) : '' ?><?= $sortBy !== 'id_asc' ? '&sort=' . urlencode($sortBy) : '' ?>&per_page=<?= $perPage ?>" class="btn-pagination" title="Next page">Next &rsaquo;</a>
                    <a href="recordings.php?page=<?= $totalPages ?><?= $filter !== 'all' ? '&filter=' . urlencode($filter) : '' ?><?= $query !== '' ? '&q=' . urlencode($query) : '' ?><?= $sortBy !== 'id_asc' ? '&sort=' . urlencode($sortBy) : '' ?>&per_page=<?= $perPage ?>" class="btn-pagination" title="Last page">Last &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- ==================== Modals ==================== -->

<!-- 1. Candidate Review Modal -->
<div class="modal-overlay" id="candidateReviewModal" role="dialog" aria-modal="true" aria-labelledby="candModalTitle">
    <div class="modal-content candidate-modal-content">
        <div class="modal-header">
            <div>
                <h3 class="modal-title font-brand" id="candModalTitle"><span>🎯</span> YouTube Candidates</h3>
                <p class="modal-subtitle" id="candModalSubtitle">Evaluating ranked recordings</p>
            </div>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <button type="button" class="btn btn-secondary btn-sm" id="btnRescanCandidates" title="Trigger a fresh live search on YouTube">
                    <span>⚡</span> Scan Again
                </button>
                <button type="button" class="modal-close" id="btnCloseCandModal" aria-label="Close dialog">&times;</button>
            </div>
        </div>

        <div class="modal-body">
            <div id="candLoadingState" style="display: none; text-align: center; padding: 2.5rem 1rem;">
                <div class="loading-spinner" style="margin: 0 auto 1rem;"></div>
                <div class="font-bold">Discovering &amp; Ranking YouTube Candidates...</div>
                <div class="text-muted" style="font-size: 0.85rem; margin-top: 0.35rem;">Fetching search results and calculating 7-signal compatibility scores</div>
            </div>

            <div id="candEmptyState" style="display: none; text-align: center; padding: 2.5rem 1rem;">
                <div class="empty-icon">🔍</div>
                <h4 style="margin: 0.5rem 0;">No Candidates Discovered</h4>
                <p class="text-muted" style="font-size: 0.85rem;">No candidates are cached for this track yet. You can scan YouTube now or enter a link manually.</p>
                <div style="display: flex; gap: 0.5rem; justify-content: center; margin-top: 1rem; flex-wrap: wrap;">
                    <button type="button" class="btn btn-primary btn-sm" id="btnDiscoverFromEmpty">
                        <span>⚡</span> Discover Candidates
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" id="btnSwitchToManualFromCand">
                        Enter URL Manually
                    </button>
                </div>
            </div>

            <div class="candidate-cards-list" id="candCardsList">
                <!-- Dynamically populated via JS -->
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="btnCancelCandModal">Close</button>
        </div>
    </div>
</div>

<!-- 2. Manual URL Entry & Correction Modal -->
<div class="modal-overlay" id="manualUrlModal" role="dialog" aria-modal="true" aria-labelledby="manualModalTitle">
    <div class="modal-content song-management-modal-content">
        <div class="modal-header">
            <div>
                <h3 class="modal-title font-brand" id="manualModalTitle"><span>✍️</span> Enter / Replace YouTube Recording</h3>
                <p class="modal-subtitle" id="manualModalSubtitle">Save confirmed YouTube recording link</p>
            </div>
            <button type="button" class="modal-close" id="btnCloseManualModal" aria-label="Close dialog">&times;</button>
        </div>

        <div class="modal-body">
            <form id="manualUrlForm">
                <input type="hidden" id="manualSongId" value="">

                <div class="form-group form-group-compact">
                    <label for="manualInputUrl" class="form-label font-bold">YouTube URL or 11-char Video ID <span class="required">*</span></label>
                    <div class="input-with-action">
                        <input type="text" id="manualInputUrl" class="form-input" placeholder="e.g. https://www.youtube.com/watch?v=... or youtu.be/..." required>
                        <button type="button" class="btn btn-secondary" id="btnValidateManualUrl"><span>🔍</span> Verify</button>
                    </div>
                    <span class="form-help">Supports standard YouTube watch URLs, YouTube Music links, shortened youtu.be links, and raw 11-char video IDs.</span>
                </div>

                <!-- Verification Preview Box -->
                <div id="manualVerificationBox" style="display: none; margin-bottom: 1rem; padding: 0.85rem; background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    <div style="display: flex; gap: 0.75rem; align-items: center;">
                        <img id="manualThumbPreview" src="" alt="Thumbnail" style="width: 80px; height: 45px; object-fit: cover; border-radius: 3px; background: #000;">
                        <div style="min-width: 0;">
                            <div id="manualTitlePreview" style="font-weight: 700; font-size: 0.875rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"></div>
                            <div id="manualAuthorPreview" style="font-size: 0.775rem; color: var(--text-secondary);"></div>
                            <div id="manualEmbedNotice" style="font-size: 0.75rem; margin-top: 0.2rem;"></div>
                        </div>
                    </div>
                </div>

                <div class="form-group form-group-compact">
                    <label for="manualVersionType" class="form-label font-bold">Recording Version</label>
                    <select id="manualVersionType" class="form-select">
                        <option value="original">Original Studio Version</option>
                        <option value="live">Live Performance</option>
                        <option value="acoustic">Acoustic Version</option>
                        <option value="remaster">Remastered</option>
                        <option value="cover">Cover Version</option>
                        <option value="other">Other / Special</option>
                    </select>
                </div>

                <div class="form-group form-group-compact">
                    <label for="manualVersionNotes" class="form-label">Version Notes (Optional)</label>
                    <input type="text" id="manualVersionNotes" class="form-input" placeholder="e.g. 2024 Remaster, MTV Unplugged, Official Music Video">
                </div>

                <div id="manualOverwriteWarning" class="existing-song-notice" style="display: none;">
                    <div class="existing-notice-icon">⚠️</div>
                    <div class="existing-notice-content">
                        <strong>Existing Recording Will Be Replaced:</strong>
                        <p>This track already has a linked recording. Saving this manual correction will permanently mark it as manually verified and protect it from automated batch overwrites.</p>
                    </div>
                </div>
            </form>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="btnCancelManualModal">Cancel</button>
            <button type="button" class="btn btn-primary" id="btnSaveManualUrl">
                <span>💾</span> Save &amp; Verify Link
            </button>
        </div>
    </div>
</div>

<!-- 3. Video Playback Preview Modal -->
<div class="modal-overlay" id="videoPreviewModal" role="dialog" aria-modal="true" aria-labelledby="previewModalTitle">
    <div class="modal-content" style="max-width: 720px; width: 95vw;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title font-brand" id="previewModalTitle"><span>▶</span> Recording Playback Preview</h3>
                <p class="modal-subtitle" id="previewModalSubtitle">Verifying embed and playback capability</p>
            </div>
            <button type="button" class="modal-close" id="btnClosePreviewModal" aria-label="Close dialog">&times;</button>
        </div>

        <div class="modal-body">
            <div class="video-preview-iframe-box">
                <iframe id="previewIframe" src="" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
            </div>
            <div id="previewEmbedAlert" style="display: none; padding: 0.65rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.825rem;"></div>
        </div>

        <div class="modal-footer">
            <a href="#" id="previewExternalLink" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">
                Open on YouTube ↗
            </a>
            <button type="button" class="btn btn-secondary" id="btnClosePreviewModalBtn">Close</button>
        </div>
    </div>
</div>

<!-- 4. Clear Recording Confirmation Modal -->
<div class="modal-overlay" id="clearRecordingModal" role="dialog" aria-modal="true" aria-labelledby="clearModalTitle">
    <div class="modal-content song-management-modal-content delete-modal-content">
        <div class="modal-header">
            <div>
                <h3 class="modal-title font-brand text-danger" id="clearModalTitle"><span>⚠️</span> Clear Recording Link</h3>
                <p class="modal-subtitle">Remove selected YouTube recording</p>
            </div>
            <button type="button" class="modal-close" id="btnCloseClearModal" aria-label="Close dialog">&times;</button>
        </div>

        <div class="modal-body">
            <p>Are you sure you want to remove the YouTube recording link for:</p>
            <div style="background: var(--bg-surface); padding: 0.85rem; border-radius: var(--radius-sm); margin: 0.75rem 0; border: 1px solid var(--border-color);">
                <strong id="clearSongTitle" class="text-primary"></strong><br>
                <span id="clearSongArtist" class="text-secondary" style="font-size: 0.85rem;"></span>
            </div>
            <p class="text-muted" style="font-size: 0.85rem;">
                <strong>Note:</strong> The song record, catalogue ID, audition scores, and tournament status will remain completely intact. Only the YouTube recording link will be cleared.
            </p>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="btnCancelClearModal">Cancel</button>
            <button type="button" class="btn btn-danger" id="btnConfirmClearModal">Clear Recording</button>
        </div>
    </div>
</div>

<script src="assets/js/recordings.js?v=<?= filemtime(__DIR__ . '/assets/js/recordings.js') ?>"></script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
