<?php
/**
 * AFM#2 - YouTube Recording Discovery, Matching & Manual Correction Interface
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';
require_once dirname(__DIR__) . '/includes/youtube_matcher.php';

require_auth();

$pageTitle = 'Recordings — AFM#2 YouTube Matching & Verification';

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
]);

$items = $catalogueData['items'];
$total = $catalogueData['total'];
$totalPages = $catalogueData['total_pages'];
$stats = $catalogueData['stats'];

// Active job state
$activeJob = YouTubeMatcher::get_or_create_active_job();

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
                    ?>
                        <tr id="row-song-<?= $sId ?>" class="rec-table-row" data-song-id="<?= $sId ?>" data-title="<?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>" data-artist="<?= htmlspecialchars($row['artist'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <!-- # Index (Spotify Column 1) -->
                            <td class="rec-col-index">
                                <span class="track-index-num"><?= $sId ?></span>
                            </td>

                            <!-- Track Info (Artwork + Title + Artist) (Spotify Column 2) -->
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
                                        <img src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>" 
                                             alt="" 
                                             width="40"
                                             height="40"
                                             class="spotify-track-thumb" 
                                             loading="lazy"
                                             onerror="this.onerror=null; this.src='assets/img/artwork-fallback.svg';">
                                    </div>
                                    <div class="spotify-track-meta">
                                        <div class="spotify-track-title" title="<?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <div class="spotify-track-artist" title="<?= htmlspecialchars($row['artist'] ?? 'Unknown Artist', ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($row['artist'] ?? 'Unknown Artist', ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Current Recording (Column 3) -->
                            <td class="rec-col-media" id="media-cell-<?= $sId ?>">
                                <?php if ($hasRecording): ?>
                                    <div class="rec-linked-card">
                                        <div class="rec-linked-top">
                                            <a href="https://www.youtube.com/watch?v=<?= htmlspecialchars($row['video_id'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="rec-yt-link" title="Open YouTube video (<?= htmlspecialchars($row['video_id'], ENT_QUOTES, 'UTF-8') ?>) in new tab">
                                                <span class="rec-yt-play-icon">▶</span>
                                                <span class="rec-yt-code"><?= htmlspecialchars($row['video_id'], ENT_QUOTES, 'UTF-8') ?></span>
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

                            <!-- Candidate Preview (Column 4) -->
                            <td class="rec-col-candidate" id="cand-cell-<?= $sId ?>">
                                <?php if ($candCount > 0): 
                                    $topScore = (float)($row['top_cand_score'] ?? 0);
                                    $scoreBadgeClass = $topScore >= 78 ? 'cand-badge-high' : ($topScore >= 50 ? 'cand-badge-medium' : 'cand-badge-low');
                                ?>
                                    <div class="rec-candidate-box">
                                        <div class="rec-cand-score-line">
                                            <span class="cand-score-chip <?= $scoreBadgeClass ?>"><?= number_format($topScore, 1) ?>% Match</span>
                                            <span class="cand-count-chip"><?= $candCount ?> found</span>
                                        </div>
                                        <div class="rec-cand-title" title="<?= htmlspecialchars($row['top_cand_title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($row['top_cand_title'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </div>
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

                            <!-- Actions (Column 5) -->
                            <td class="rec-col-actions" style="text-align: right;">
                                <div class="rec-actions-flex">
                                    <button type="button" class="btn btn-secondary btn-xs btn-open-candidates" data-song-id="<?= $sId ?>" title="Review, rank, or scan candidate YouTube videos">
                                        <span>⚡</span> Candidates (<?= $candCount ?>)
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-xs btn-open-manual" data-song-id="<?= $sId ?>" data-current-url="<?= htmlspecialchars($row['user_url'] ?? ($hasRecording ? 'https://www.youtube.com/watch?v=' . $row['video_id'] : ''), ENT_QUOTES, 'UTF-8') ?>" title="Manually paste or correct YouTube URL">
                                        <span>🔗</span> URL
                                    </button>
                                    <?php if ($hasRecording): ?>
                                        <button type="button" class="btn btn-secondary btn-xs btn-preview-video" data-video-id="<?= htmlspecialchars($row['video_id'], ENT_QUOTES, 'UTF-8') ?>" data-title="<?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>" title="Preview playback inside player">
                                            ▶
                                        </button>
                                        <button type="button" class="btn btn-danger-subtle btn-xs btn-clear-rec" data-song-id="<?= $sId ?>" title="Clear recording link (preserves song)">
                                            &times;
                                        </button>
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
                <p class="text-muted" style="font-size: 0.85rem;">No suitable YouTube recordings were found matching the track title and artist. You can enter a link manually.</p>
                <button type="button" class="btn btn-primary btn-sm" id="btnSwitchToManualFromCand" style="margin-top: 1rem;">
                    Enter URL Manually
                </button>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // ==================== Modal Helpers ====================
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    // Close on backdrop click
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
        });
    });

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                closeModal(modal.id);
            });
        }
    });

    // ==================== Batch Runner Engine ====================
    let isBatchRunning = false;
    let shouldPauseBatch = false;
    const btnStartBatch = document.getElementById('btnStartBatch');
    const btnPauseBatch = document.getElementById('btnPauseBatch');
    const btnResetJob = document.getElementById('btnResetJob');
    const batchProgressFill = document.getElementById('batchProgressFill');
    const batchPercentLabel = document.getElementById('batchPercentLabel');
    const jobStatusBadge = document.getElementById('jobStatusBadge');
    const jobProcessedCount = document.getElementById('jobProcessedCount');
    const logMessage = document.getElementById('logMessage');
    const logTimer = document.getElementById('logTimer');

    function updateBatchUI(job) {
        if (!job) return;
        const total = <?= (int)$stats['total_songs'] ?>;
        const processed = parseInt(job.processed_songs || 0, 10);
        const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 1000) / 10) : 0;

        batchProgressFill.style.width = pct + '%';
        batchPercentLabel.textContent = pct + '%';
        jobProcessedCount.textContent = Number(processed).toLocaleString();
        jobStatusBadge.textContent = (job.status || 'running').toUpperCase();
        logTimer.textContent = 'Last Song ID: #' + (job.last_processed_song_id || 0);

        const pill = document.getElementById('jobStatusPill');
        if (pill) {
            pill.className = 'batch-status-pill status-' + (job.status || 'running').toLowerCase();
        }
    }

    async function runBatchStep() {
        if (shouldPauseBatch) {
            isBatchRunning = false;
            shouldPauseBatch = false;
            btnStartBatch.style.display = 'inline-flex';
            btnPauseBatch.style.display = 'none';
            batchProgressFill.classList.remove('animating');
            logMessage.textContent = 'Batch runner paused by operator.';
            jobStatusBadge.textContent = 'PAUSED';
            const pill = document.getElementById('jobStatusPill');
            if (pill) pill.className = 'batch-status-pill status-paused';
            return;
        }

        try {
            const res = await fetch('api.php?action=youtube_batch_process', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ batch_size: 10 })
            });
            const data = await res.json();

            if (!data.success) {
                logMessage.textContent = 'Error: ' + (data.error || 'Batch request failed');
                isBatchRunning = false;
                btnStartBatch.style.display = 'inline-flex';
                btnPauseBatch.style.display = 'none';
                batchProgressFill.classList.remove('animating');
                const pill = document.getElementById('jobStatusPill');
                if (pill) pill.className = 'batch-status-pill status-error';
                return;
            }

            const job = data.job;
            updateBatchUI(job);

            if (data.completed || (job && job.status === 'completed')) {
                isBatchRunning = false;
                btnStartBatch.style.display = 'inline-flex';
                btnPauseBatch.style.display = 'none';
                batchProgressFill.classList.remove('animating');
                const pill = document.getElementById('jobStatusPill');
                if (pill) pill.className = 'batch-status-pill status-completed';
                logMessage.textContent = 'All catalogue songs have been processed! Reloading table in 2s...';
                setTimeout(() => window.location.reload(), 2000);
                return;
            }

            const results = data.results || [];
            const highCount = results.filter(r => r.status === 'auto_high').length;
            const reviewCount = results.filter(r => r.status === 'review_needed').length;
            const lowCount = results.filter(r => r.status === 'unresolved').length;

            logMessage.textContent = `Batch of ${data.processed || 0} songs (Auto High: ${highCount}, Review Needed: ${reviewCount}, Low: ${lowCount})`;

            // Continue next batch step asynchronously
            setTimeout(runBatchStep, 600);
        } catch (err) {
            logMessage.textContent = 'Network or server error during batch processing.';
            isBatchRunning = false;
            btnStartBatch.style.display = 'inline-flex';
            btnPauseBatch.style.display = 'none';
            batchProgressFill.classList.remove('animating');
            const pill = document.getElementById('jobStatusPill');
            if (pill) pill.className = 'batch-status-pill status-error';
        }
    }

    btnStartBatch?.addEventListener('click', function() {
        if (isBatchRunning) return;
        isBatchRunning = true;
        shouldPauseBatch = false;
        btnStartBatch.style.display = 'none';
        btnPauseBatch.style.display = 'inline-flex';
        batchProgressFill.classList.add('animating');
        logMessage.textContent = 'Batch processing active...';
        jobStatusBadge.textContent = 'RUNNING';
        const pill = document.getElementById('jobStatusPill');
        if (pill) pill.className = 'batch-status-pill status-running';
        runBatchStep();
    });

    btnPauseBatch?.addEventListener('click', function() {
        if (!isBatchRunning) return;
        shouldPauseBatch = true;
        logMessage.textContent = 'Pausing after current batch completes...';
    });

    btnResetJob?.addEventListener('click', async function() {
        if (!confirm('Reset matching job progress back to Song #1? Existing accepted recordings will not be lost.')) {
            return;
        }
        try {
            const res = await fetch('api.php?action=youtube_job_reset', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                }
            });
            const data = await res.json();
            if (data.success) {
                window.location.reload();
            } else {
                alert('Failed to reset job: ' + (data.error || 'Unknown error'));
            }
        } catch (err) {
            alert('Server error resetting job');
        }
    });

    // Refresh stats button
    document.getElementById('btnRefreshStats')?.addEventListener('click', async function() {
        try {
            const res = await fetch('api.php?action=youtube_job_status');
            const data = await res.json();
            if (data.success && data.stats) {
                document.getElementById('statMatchedTotal').textContent = Number(data.stats.matched_total).toLocaleString();
                document.getElementById('statReviewNeeded').textContent = Number(data.stats.review_needed).toLocaleString();
                document.getElementById('statLowUnresolved').textContent = Number(data.stats.low_unresolved).toLocaleString();
                document.getElementById('statManualCount').textContent = Number(data.stats.manual_count).toLocaleString();
                document.getElementById('statBlockedCount').textContent = Number(data.stats.blocked_count).toLocaleString();
            }
        } catch (e) {
            console.error(e);
        }
    });

    // ==================== Candidate Review Modal ====================
    let activeSongIdForCand = null;
    let activeSongTitleForCand = '';
    let activeSongArtistForCand = '';

    const candModal = document.getElementById('candidateReviewModal');
    const candModalTitle = document.getElementById('candModalTitle');
    const candModalSubtitle = document.getElementById('candModalSubtitle');
    const candCardsList = document.getElementById('candCardsList');
    const candLoadingState = document.getElementById('candLoadingState');
    const candEmptyState = document.getElementById('candEmptyState');

    document.querySelectorAll('.btn-open-candidates').forEach(btn => {
        btn.addEventListener('click', function() {
            const sId = parseInt(this.getAttribute('data-song-id'), 10);
            const row = document.getElementById('row-song-any') || this.closest('tr');
            activeSongIdForCand = sId;
            activeSongTitleForCand = row?.getAttribute('data-title') || '';
            activeSongArtistForCand = row?.getAttribute('data-artist') || '';

            candModalTitle.innerHTML = `<span>🎯</span> Candidates: ${escapeHtml(activeSongTitleForCand)}`;
            candModalSubtitle.textContent = `Artist: ${activeSongArtistForCand} • Track #${sId}`;

            openModal('candidateReviewModal');
            loadCandidates(sId, false);
        });
    });

    document.getElementById('btnRescanCandidates')?.addEventListener('click', function() {
        if (activeSongIdForCand) {
            loadCandidates(activeSongIdForCand, true);
        }
    });

    document.getElementById('btnCloseCandModal')?.addEventListener('click', () => closeModal('candidateReviewModal'));
    document.getElementById('btnCancelCandModal')?.addEventListener('click', () => closeModal('candidateReviewModal'));

    async function loadCandidates(songId, forceDiscover = false) {
        candLoadingState.style.display = 'block';
        candEmptyState.style.display = 'none';
        candCardsList.innerHTML = '';

        try {
            const action = forceDiscover ? 'youtube_discover_candidates' : 'youtube_get_candidates';
            const res = await fetch(`api.php?action=${action}&song_id=${songId}`);
            const data = await res.json();

            candLoadingState.style.display = 'none';

            if (!data.success || !data.candidates || data.candidates.length === 0) {
                candEmptyState.style.display = 'block';
                return;
            }

            renderCandidates(data.candidates, songId);
        } catch (err) {
            candLoadingState.style.display = 'none';
            candCardsList.innerHTML = `<div class="alert alert-danger">Failed to load candidates: ${err.message}</div>`;
        }
    }

    function renderCandidates(candidates, songId) {
        candCardsList.innerHTML = '';
        candidates.forEach(cand => {
            const scoreTotal = parseFloat(cand.score_total || 0).toFixed(1);
            const conf = cand.confidence || 'low';
            const isSelected = parseInt(cand.is_selected || 0, 10) === 1;

            let confBadgeClass = 'badge-confidence-low';
            if (conf === 'high') confBadgeClass = 'badge-confidence-high';
            else if (conf === 'medium') confBadgeClass = 'badge-confidence-medium';

            const card = document.createElement('div');
            card.className = `candidate-card-item ${isSelected ? 'is-current-selection' : ''}`;
            card.innerHTML = `
                <div class="candidate-thumb-box">
                    <img src="${escapeHtml(cand.thumbnail_url || 'assets/img/artwork-fallback.svg')}" alt="Thumb" class="candidate-thumb-img" onerror="this.onerror=null; this.src='assets/img/artwork-fallback.svg';">
                    ${cand.duration ? `<span class="candidate-duration-pill">${escapeHtml(cand.duration)}</span>` : ''}
                </div>
                <div class="candidate-info-col">
                    <div class="candidate-video-title">${escapeHtml(cand.title || '')}</div>
                    <div class="candidate-channel-row">
                        <span>${escapeHtml(cand.channel_title || 'Unknown Channel')}</span>
                        ${cand.channel_is_artist ? '<span class="verified-channel-badge">★ Official Artist</span>' : (cand.channel_is_verified ? '<span class="verified-channel-badge">✓ Verified</span>' : '')}
                        &bull; <span>${cand.view_count ? Number(cand.view_count).toLocaleString() + ' views' : 'Views unavailable'}</span>
                        ${cand.published_at ? `&bull; <span>${escapeHtml(cand.published_at.substring(0, 10))}</span>` : ''}
                    </div>

                    <div class="candidate-signal-chips">
                        <span class="badge ${confBadgeClass}">${conf.toUpperCase()} CONFIDENCE</span>
                        ${isSelected ? '<span class="badge badge-shortlisted">● Current Selection</span>' : ''}
                    </div>

                    ${cand.decision_notes ? `<div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.35rem; font-style: italic;">Note: ${escapeHtml(cand.decision_notes)}</div>` : ''}

                    <div class="candidate-score-breakdown">
                        <div class="score-chip">Title (25%)<strong>${parseFloat(cand.score_title || 0).toFixed(1)}</strong></div>
                        <div class="score-chip">Artist (25%)<strong>${parseFloat(cand.score_artist || 0).toFixed(1)}</strong></div>
                        <div class="score-chip">Version (20%)<strong>${parseFloat(cand.score_version || 0).toFixed(1)}</strong></div>
                        <div class="score-chip">Channel (10%)<strong>${parseFloat(cand.score_channel || 0).toFixed(1)}</strong></div>
                        <div class="score-chip">Date (8%)<strong>${parseFloat(cand.score_date || 0).toFixed(1)}</strong></div>
                        <div class="score-chip">Views (7%)<strong>${parseFloat(cand.score_views || 0).toFixed(1)}</strong></div>
                        <div class="score-chip">Duration (5%)<strong>${parseFloat(cand.score_duration || 0).toFixed(1)}</strong></div>
                    </div>
                </div>

                <div class="candidate-actions-col">
                    <div class="candidate-score-headline">
                        <div class="candidate-score-percent text-accent">${scoreTotal}%</div>
                        <div style="font-size: 0.7rem; color: var(--text-muted);">Match Score</div>
                    </div>
                    <button type="button" class="btn btn-secondary btn-xs btn-preview-cand" data-video-id="${escapeHtml(cand.video_id)}" data-title="${escapeHtml(cand.title || '')}">
                        ▶ Preview
                    </button>
                    ${!isSelected ? `
                        <button type="button" class="btn btn-primary btn-xs btn-select-cand" data-candidate-id="${cand.candidate_id || ''}" data-video-id="${escapeHtml(cand.video_id)}" data-song-id="${songId}">
                            ✓ Select Video
                        </button>
                    ` : `
                        <span class="text-success font-bold" style="font-size: 0.8rem;">✓ Accepted</span>
                    `}
                </div>
            `;
            candCardsList.appendChild(card);
        });

        // Attach listeners for preview and select inside modal
        candCardsList.querySelectorAll('.btn-preview-cand').forEach(btn => {
            btn.addEventListener('click', function() {
                openVideoPreview(this.getAttribute('data-video-id'), this.getAttribute('data-title'));
            });
        });

        candCardsList.querySelectorAll('.btn-select-cand').forEach(btn => {
            btn.addEventListener('click', async function() {
                const cId = this.getAttribute('data-candidate-id');
                const vId = this.getAttribute('data-video-id');
                const sId = this.getAttribute('data-song-id');

                if (!confirm(`Select YouTube video (${vId}) as the accepted recording for this track?`)) {
                    return;
                }

                try {
                    const res = await fetch('api.php?action=youtube_select_candidate', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({
                            song_id: sId,
                            candidate_id: cId,
                            video_id: vId
                        })
                    });
                    const result = await res.json();
                    if (result.success) {
                        closeModal('candidateReviewModal');
                        window.location.reload();
                    } else {
                        alert('Error selecting candidate: ' + (result.error || 'Unknown error'));
                    }
                } catch (e) {
                    alert('Server error selecting candidate');
                }
            });
        });
    }

    document.getElementById('btnSwitchToManualFromCand')?.addEventListener('click', function() {
        closeModal('candidateReviewModal');
        openManualUrlModal(activeSongIdForCand, activeSongTitleForCand, activeSongArtistForCand, '');
    });

    // ==================== Manual URL Entry Modal ====================
    let activeManualSongId = null;
    const manualModal = document.getElementById('manualUrlModal');
    const manualModalTitle = document.getElementById('manualModalTitle');
    const manualModalSubtitle = document.getElementById('manualModalSubtitle');
    const manualSongIdInput = document.getElementById('manualSongId');
    const manualInputUrl = document.getElementById('manualInputUrl');
    const manualVersionType = document.getElementById('manualVersionType');
    const manualVersionNotes = document.getElementById('manualVersionNotes');
    const manualVerificationBox = document.getElementById('manualVerificationBox');
    const manualThumbPreview = document.getElementById('manualThumbPreview');
    const manualTitlePreview = document.getElementById('manualTitlePreview');
    const manualAuthorPreview = document.getElementById('manualAuthorPreview');
    const manualEmbedNotice = document.getElementById('manualEmbedNotice');
    const manualOverwriteWarning = document.getElementById('manualOverwriteWarning');

    function openManualUrlModal(songId, title, artist, currentUrl) {
        activeManualSongId = songId;
        manualSongIdInput.value = songId;
        manualModalTitle.innerHTML = `<span>✍️</span> Edit Recording: ${escapeHtml(title)}`;
        manualModalSubtitle.textContent = `Artist: ${artist} • Track #${songId}`;
        manualInputUrl.value = currentUrl || '';
        manualVersionType.value = 'original';
        manualVersionNotes.value = '';
        manualVerificationBox.style.display = 'none';

        manualOverwriteWarning.style.display = currentUrl ? 'flex' : 'none';

        openModal('manualUrlModal');
    }

    document.querySelectorAll('.btn-open-manual').forEach(btn => {
        btn.addEventListener('click', function() {
            const sId = parseInt(this.getAttribute('data-song-id'), 10);
            const currentUrl = this.getAttribute('data-current-url') || '';
            const row = this.closest('tr');
            const title = row?.getAttribute('data-title') || '';
            const artist = row?.getAttribute('data-artist') || '';
            openManualUrlModal(sId, title, artist, currentUrl);
        });
    });

    document.getElementById('btnCloseManualModal')?.addEventListener('click', () => closeModal('manualUrlModal'));
    document.getElementById('btnCancelManualModal')?.addEventListener('click', () => closeModal('manualUrlModal'));

    // Live validation
    document.getElementById('btnValidateManualUrl')?.addEventListener('click', async function() {
        const urlVal = manualInputUrl.value.trim();
        if (!urlVal) {
            alert('Please enter a YouTube link or video ID first');
            return;
        }

        try {
            const res = await fetch(`api.php?action=youtube_verify_embed&url=${encodeURIComponent(urlVal)}`);
            const data = await res.json();

            if (!data.success) {
                alert('URL validation error: ' + (data.error || 'Invalid YouTube URL'));
                return;
            }

            manualVerificationBox.style.display = 'block';
            manualThumbPreview.src = data.thumbnail_url || 'assets/img/artwork-fallback.svg';
            manualTitlePreview.textContent = data.title || 'YouTube Video (' + data.video_id + ')';
            manualAuthorPreview.textContent = data.author_name ? 'Channel: ' + data.author_name : 'Video ID: ' + data.video_id;

            if (data.embed_status === 'allowed') {
                manualEmbedNotice.innerHTML = '<span class="text-success font-bold">✓ Embedding Permitted</span> (Playback ready)';
            } else {
                manualEmbedNotice.innerHTML = '<span class="text-danger font-bold">⚠️ Embedding Restricted or Blocked</span> (May require opening on YouTube)';
            }
        } catch (e) {
            alert('Could not verify link: ' + e.message);
        }
    });

    // Save manual URL
    document.getElementById('btnSaveManualUrl')?.addEventListener('click', async function() {
        const songId = manualSongIdInput.value;
        const urlVal = manualInputUrl.value.trim();
        const vType = manualVersionType.value;
        const vNotes = manualVersionNotes.value.trim();

        if (!urlVal) {
            alert('Please provide a YouTube URL or video ID.');
            return;
        }

        try {
            const res = await fetch('api.php?action=youtube_manual_save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    song_id: songId,
                    url: urlVal,
                    version_type: vType,
                    version_notes: vNotes
                })
            });
            const data = await res.json();

            if (data.success) {
                closeModal('manualUrlModal');
                window.location.reload();
            } else {
                alert('Save failed: ' + (data.error || 'Server error'));
            }
        } catch (e) {
            alert('Server error saving recording: ' + e.message);
        }
    });

    // ==================== Video Playback Preview Modal ====================
    const previewModal = document.getElementById('videoPreviewModal');
    const previewIframe = document.getElementById('previewIframe');
    const previewSubtitle = document.getElementById('previewModalSubtitle');
    const previewExternalLink = document.getElementById('previewExternalLink');
    const previewEmbedAlert = document.getElementById('previewEmbedAlert');

    function openVideoPreview(videoId, title) {
        if (!videoId) return;
        previewSubtitle.textContent = title ? `Now Playing: ${title}` : `Video ID: ${videoId}`;
        previewIframe.src = `https://www.youtube.com/embed/${encodeURIComponent(videoId)}?autoplay=1&enablejsapi=1`;
        previewExternalLink.href = `https://www.youtube.com/watch?v=${encodeURIComponent(videoId)}`;

        // Verify oEmbed capability
        fetch(`api.php?action=youtube_verify_embed&video_id=${encodeURIComponent(videoId)}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (data.embed_status === 'allowed') {
                        previewEmbedAlert.style.display = 'block';
                        previewEmbedAlert.className = 'alert alert-success';
                        previewEmbedAlert.textContent = '✓ Verified: YouTube embedding is fully supported for this recording.';
                    } else {
                        previewEmbedAlert.style.display = 'block';
                        previewEmbedAlert.className = 'alert alert-danger';
                        previewEmbedAlert.textContent = '⚠️ Creator embedding disabled or video unavailable. Use direct YouTube link to listen.';
                    }
                }
            })
            .catch(() => {});

        openModal('videoPreviewModal');
    }

    document.querySelectorAll('.btn-preview-video').forEach(btn => {
        btn.addEventListener('click', function() {
            openVideoPreview(this.getAttribute('data-video-id'), this.getAttribute('data-title'));
        });
    });

    function closePreview() {
        previewIframe.src = '';
        previewEmbedAlert.style.display = 'none';
        closeModal('videoPreviewModal');
    }

    document.getElementById('btnClosePreviewModal')?.addEventListener('click', closePreview);
    document.getElementById('btnClosePreviewModalBtn')?.addEventListener('click', closePreview);

    // ==================== Clear Recording Modal ====================
    let activeClearSongId = null;
    const clearModal = document.getElementById('clearRecordingModal');
    const clearSongTitle = document.getElementById('clearSongTitle');
    const clearSongArtist = document.getElementById('clearSongArtist');

    document.querySelectorAll('.btn-clear-rec').forEach(btn => {
        btn.addEventListener('click', function() {
            const sId = parseInt(this.getAttribute('data-song-id'), 10);
            const row = this.closest('tr');
            activeClearSongId = sId;
            clearSongTitle.textContent = row?.getAttribute('data-title') || `Song #${sId}`;
            clearSongArtist.textContent = row?.getAttribute('data-artist') || '';
            openModal('clearRecordingModal');
        });
    });

    document.getElementById('btnCloseClearModal')?.addEventListener('click', () => closeModal('clearRecordingModal'));
    document.getElementById('btnCancelClearModal')?.addEventListener('click', () => closeModal('clearRecordingModal'));

    document.getElementById('btnConfirmClearModal')?.addEventListener('click', async function() {
        if (!activeClearSongId) return;
        try {
            const res = await fetch('api.php?action=youtube_clear_recording', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ song_id: activeClearSongId })
            });
            const data = await res.json();
            if (data.success) {
                closeModal('clearRecordingModal');
                window.location.reload();
            } else {
                alert('Could not clear recording: ' + (data.error || 'Server error'));
            }
        } catch (e) {
            alert('Server error clearing recording');
        }
    });

    // Simple HTML escaper
    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }
});
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
