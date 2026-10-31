<?php
/**
 * AFM#2 - Discovery Shortlist View
 * Review and manage songs earmarked for the 2026 Annual Musical Finale auditions.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

require_auth();

$pageTitle = 'Discovery Shortlist — AFM#2 2026';
$searchQuery = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));

// Records per page: 25, 50 (default), 75, 100 (at most 100 as requested)
$rawPerPage = (int)($_GET['per_page'] ?? 50);
$perPage = in_array($rawPerPage, [25, 50, 75, 100], true) ? $rawPerPage : max(10, min(100, $rawPerPage > 0 ? $rawPerPage : 50));

// Fetch shortlisted songs
$results = get_shortlisted_songs($page, $perPage, $searchQuery, $statusFilter);
$totalMatches = $results['total'];
$totalPages = $results['total_pages'];

// Snap if page exceeds totalPages due to per_page change or filter
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
    $results = get_shortlisted_songs($page, $perPage, $searchQuery, $statusFilter);
}
$songs = $results['songs'];

// Aggregated counts for badges
$stats = get_dashboard_stats();

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-container">
    <!-- Breadcrumb & Title Area -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Audition Shortlist</h1>
            <p class="page-subtitle">
                Songs curated from your personal library, prepared for formal tournament scoring.
            </p>
        </div>
        <div class="header-actions">
            <a href="library.php" class="btn btn-secondary">
                <span class="btn-icon">🔍</span> Explore Library
            </a>
            <a href="auditions.php" class="btn btn-accent">
                <span class="btn-icon">⚡</span> Proceed to Auditions
            </a>
        </div>
    </div>

    <!-- Stats Summary Strip -->
    <div class="stats-grid mb-4">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Total Shortlisted</span>
                <span class="stat-icon icon-shortlisted">★</span>
            </div>
            <div class="stat-value text-accent" id="statShortlistedTotal"><?= number_format($stats['shortlisted_count']) ?></div>
            <div class="stat-desc">Songs advancing to formal auditions</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Audition Completed</span>
                <span class="stat-icon icon-completed">✓</span>
            </div>
            <div class="stat-value text-success"><?= number_format($stats['auditions_completed']) ?></div>
            <div class="stat-desc">Scored across all 5 criteria (/50)</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Incomplete Scorecards</span>
                <span class="stat-icon icon-in-progress">⏳</span>
            </div>
            <div class="stat-value text-gold"><?= number_format($stats['auditions_incomplete']) ?></div>
            <div class="stat-desc">Partially saved, ready to resume</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Pending Scoring</span>
                <span class="stat-icon icon-unreviewed">○</span>
            </div>
            <div class="stat-value text-muted">
                <?= number_format(max(0, $stats['shortlisted_count'] - ($stats['auditions_completed'] + $stats['auditions_incomplete']))) ?>
            </div>
            <div class="stat-desc">Awaiting first audition score</div>
        </div>
    </div>

    <!-- Unified Symmetrical Filter & Search Bar -->
    <form method="GET" action="discovery.php" class="filter-bar" id="discoveryFilterForm">
        <!-- Hidden filter preserve -->
        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">

        <!-- Top Row: Search on Left, Per-Page on Right -->
        <div class="filter-bar-row filter-bar-row-top">
            <div class="search-input-wrapper">
                <span class="search-icon" aria-hidden="true">🔍</span>
                <input 
                    type="search" 
                    name="q" 
                    id="discoverySearchInput"
                    class="search-input" 
                    placeholder="Search shortlisted song title or artist..." 
                    value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="Search shortlisted songs"
                >
                <?php if ($searchQuery !== ''): ?>
                    <a href="discovery.php?status=<?= urlencode($statusFilter) ?>&per_page=<?= (int)$perPage ?>" class="btn-search-clear" title="Clear search query" aria-label="Clear search">✕</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-search-apply" title="Apply search">Apply</button>
            </div>

            <div class="filter-selectors-cluster">
                <div class="sort-input-group">
                    <label for="perPageDiscovery" class="filter-label"><span aria-hidden="true">📄</span> PER PAGE:</label>
                    <select name="per_page" id="perPageDiscovery" class="sort-select" onchange="document.getElementById('discoveryFilterForm').submit()" aria-label="Records per page">
                        <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25 per page</option>
                        <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50 per page</option>
                        <option value="75" <?= $perPage === 75 ? 'selected' : '' ?>>75 per page</option>
                        <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100 per page (Max)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Bottom Row: Status Filter Tabs on Left, Clear Filter on Right -->
        <div class="filter-bar-row filter-bar-row-bottom">
            <div class="filter-pills">
                <a href="discovery.php?<?= http_build_query(array_merge($_GET, ['status' => 'all', 'page' => 1])) ?>" 
                   class="filter-pill <?= $statusFilter === 'all' ? 'active' : '' ?>">
                    All (<?= number_format($stats['shortlisted_count']) ?>)
                </a>
                <a href="discovery.php?<?= http_build_query(array_merge($_GET, ['status' => 'unscored', 'page' => 1])) ?>" 
                   class="filter-pill <?= $statusFilter === 'unscored' ? 'active' : '' ?>">
                    Unscored
                </a>
                <a href="discovery.php?<?= http_build_query(array_merge($_GET, ['status' => 'incomplete', 'page' => 1])) ?>" 
                   class="filter-pill <?= $statusFilter === 'incomplete' ? 'active' : '' ?>">
                    Incomplete
                </a>
                <a href="discovery.php?<?= http_build_query(array_merge($_GET, ['status' => 'completed', 'page' => 1])) ?>" 
                   class="filter-pill <?= $statusFilter === 'completed' ? 'active' : '' ?>">
                    Completed
                </a>
            </div>

            <?php if ($searchQuery !== '' || $statusFilter !== 'all'): ?>
                <div class="filter-reset-wrap">
                    <a href="discovery.php" class="btn btn-secondary btn-sm" title="Reset all filters">
                        <span>↺</span> Reset Filters
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <!-- Content Table -->
    <?php if (empty($songs)): ?>
        <div class="empty-state">
            <div class="empty-icon">★</div>
            <h2 class="empty-title">No Shortlisted Songs Found</h2>
            <p class="empty-desc">
                <?php if ($searchQuery !== '' || $statusFilter !== 'all'): ?>
                    No shortlisted songs match your active search or filter criteria. Try clearing filters.
                <?php else: ?>
                    You haven't shortlisted any songs yet. Head over to the Song Library to discover and shortlist songs for AFM 2026.
                <?php endif; ?>
            </p>
            <div class="empty-actions">
                <?php if ($searchQuery !== '' || $statusFilter !== 'all'): ?>
                    <a href="discovery.php" class="btn btn-secondary">Clear Filters</a>
                <?php endif; ?>
                <a href="library.php" class="btn btn-accent">Go to Song Library</a>
            </div>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="data-table" id="shortlistTable">
                <thead>
                    <tr>
                        <th class="col-id">#</th>
                        <th class="col-song">Song Title & Artist</th>
                        <th class="col-status">Audition Status</th>
                        <th class="col-date">Shortlisted On</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($songs as $index => $song): 
                        $rowNum = ($page - 1) * $perPage + $index + 1;
                        $entryId = $song['entry_id'] ?? null;
                        $isComplete = !empty($song['is_complete']);
                        $isIncomplete = !empty($song['is_incomplete']);
                        $totalScore = $song['total'] ?? null;
                    ?>
                    <tr 
                        data-song-id="<?= (int)$song['song_id'] ?>" 
                        data-entry-id="<?= (int)$entryId ?>" 
                        data-song-title="<?= htmlspecialchars($song['title'], ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <td class="col-id">
                            <span class="row-num"><?= $rowNum ?></span>
                        </td>
                        <td class="col-song">
                            <div class="song-meta-wrap">
                                <?php 
                                    $dThumb = $song['artwork_thumbnail_url'] ?? $song['artwork_image_url'] ?? null;
                                    if (empty($dThumb)) {
                                        $dThumb = 'assets/img/artwork-fallback.svg';
                                    }
                                ?>
                                <img src="<?= htmlspecialchars($dThumb, ENT_QUOTES, 'UTF-8') ?>" 
                                     alt="" 
                                     class="song-row-thumb" 
                                     loading="lazy"
                                     onerror="this.onerror=null; this.src='assets/img/artwork-fallback.svg';">
                                <div class="song-meta">
                                    <span class="song-title"><?= htmlspecialchars($song['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="song-artist"><?= htmlspecialchars($song['artist'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                            <div class="song-quick-media">
                                <a href="auditions.php?song_id=<?= (int)$song['song_id'] ?>" class="btn-xs btn-primary-subtle" title="Play recording directly inside AFM#2 integrated audition console">
                                    ▶ Play in AFM#2
                                </a>
                                <?php if (!empty($song['media_video_id'])): ?>
                                    <a href="https://www.youtube.com/watch?v=<?= htmlspecialchars($song['media_video_id'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="link-external" title="Direct link to confirmed recording on YouTube">
                                        YouTube ↗
                                    </a>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars(get_youtube_search_url($song['title'], $song['artist'])) ?>" target="_blank" rel="noopener noreferrer" class="link-external text-muted" title="Search YouTube in new tab">
                                        Search ↗
                                    </a>
                                <?php endif; ?>
                                <button type="button" class="btn-xs btn-lyrics-quick-view" 
                                    data-song-id="<?= (int)$song['song_id'] ?>" 
                                    data-song-title="<?= htmlspecialchars($song['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                    data-song-artist="<?= htmlspecialchars($song['artist'], ENT_QUOTES, 'UTF-8') ?>"
                                    title="View lyrics from LRCLIB">
                                    📜 Lyrics
                                </button>
                            </div>
                        </td>
                        <td class="col-status">
                            <div class="status-badge-container">
                                <?php if ($isComplete): ?>
                                    <span class="badge badge-success" title="Formal audition score complete">
                                        ✓ Scored: <?= (int)$totalScore ?> / 50
                                    </span>
                                <?php elseif ($isIncomplete): ?>
                                    <span class="badge badge-warning" title="Scorecard partially filled">
                                        ⏳ Incomplete (<?= (int)$totalScore ?>* / 50)
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-neutral" title="Awaiting formal audition score">
                                        ○ Unscored
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="col-date">
                            <span class="date-text">
                                <?= $song['reviewed_at'] ? htmlspecialchars(format_local_timestamp($song['reviewed_at'])) : '—' ?>
                            </span>
                        </td>
                        <td class="col-actions">
                            <div class="action-btn-group">
                                <?php if ($entryId): ?>
                                    <a 
                                        href="auditions.php?q=<?= urlencode($song['title']) ?>" 
                                        class="btn btn-sm <?= $isComplete ? 'btn-secondary' : 'btn-accent' ?>"
                                        title="<?= $isComplete ? 'Review Scorecard' : 'Score this Song' ?>"
                                    >
                                        <?= $isComplete ? 'Scorecard' : 'Score Now' ?>
                                    </a>
                                <?php endif; ?>

                                <button 
                                    type="button" 
                                    class="btn-action-pass" 
                                    title="Remove from shortlist (mark as passed)"
                                    aria-label="Pass song"
                                >
                                    ✕ Pass
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?= render_accessible_pagination(
            $page, 
            $totalPages, 
            $totalMatches, 
            $perPage, 
            'discovery.php', 
            [
                'q' => $searchQuery,
                'status' => $statusFilter
            ], 
            'shortlisted songs',
            10
        ); ?>

    <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__) . '/includes/footer.php';
