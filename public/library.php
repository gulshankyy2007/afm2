<?php
/**
 * AFM#2 - Song Library & Discovery Review
 * Includes sorting by Title, Artist, ID; full Song Management (Add manual/YouTube, Edit, Delete); and Discovery Shortlisting.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/media_helper.php';

require_auth();

$query        = trim($_GET['q'] ?? '');
$statusFilter = $_GET['filter'] ?? 'all';
$sortBy       = $_GET['sort'] ?? 'id_asc';
$page         = max(1, (int)($_GET['page'] ?? 1));

// Records per page: 25, 50 (default), 75, 100 (at most 100 as requested)
$rawPerPage   = (int)($_GET['per_page'] ?? 50);
$perPage      = in_array($rawPerPage, [25, 50, 75, 100], true) ? $rawPerPage : max(10, min(100, $rawPerPage > 0 ? $rawPerPage : 50));

$validSorts = ['id_asc', 'id_desc', 'title_asc', 'title_desc', 'artist_asc', 'artist_desc'];
if (!in_array($sortBy, $validSorts, true)) {
    $sortBy = 'id_asc';
}

$result = search_songs($query, $statusFilter, $page, $perPage, $sortBy);
$total = $result['total'];
$totalPages = $result['total_pages'];

// If requested page exceeds totalPages due to per_page change or filtering, snap to last page
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
    $result = search_songs($query, $statusFilter, $page, $perPage, $sortBy);
}
$songs = $result['songs'];

// Stats for counter pills
$stats = get_dashboard_stats();

$pageTitle = 'Song Library & Management — AFM#2';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-title-group">
        <h1>Song Library &amp; Catalogue</h1>
        <p class="page-subtitle">Manage songs, extract from YouTube, sort by title or artist, and make one-tap discovery decisions.</p>
    </div>
    <div class="header-actions">
        <a href="recordings.php" class="btn btn-secondary" id="btnMatchRecordings" title="Bulk YouTube matching & link management">
            <span class="btn-icon">🎬</span> Match Recordings
        </a>
        <a href="import.php" class="btn btn-secondary" id="btnOpenImportCsv" title="Bulk import songs from a CSV file">
            <span class="btn-icon">📁</span> Import Songs
        </a>
        <button type="button" class="btn btn-primary" id="btnOpenAddSongModal" title="Add a new song manually or auto-extract from a YouTube link">
            ＋ Add Song
        </button>
        <a href="discovery.php" class="btn btn-secondary">
            View Shortlist (<?= number_format($stats['shortlisted_count']) ?>) &rarr;
        </a>
    </div>
</div>

<!-- Search, Filter & Sort Controls -->
<form method="GET" action="library.php" class="filter-bar" id="libraryFilterForm">
    <div class="filter-bar-row filter-bar-row-top">
        <div class="search-input-wrapper">
            <span class="search-icon" aria-hidden="true">🔍</span>
            <input 
                type="text" 
                name="q" 
                class="search-input" 
                placeholder="Search by title or artist..." 
                value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>"
                id="searchInput"
            >
            <button type="submit" class="btn btn-primary btn-sm btn-search-apply" id="btnFilterSubmit" title="Execute search">
                Apply
            </button>
            <?php if ($query !== ''): ?>
                <a href="library.php?filter=<?= urlencode($statusFilter) ?>&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" class="btn-search-clear" title="Clear search query" aria-label="Clear search">&times;</a>
            <?php endif; ?>
        </div>

        <input type="hidden" name="filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>" id="filterInput">

        <div class="filter-selectors-cluster">
            <div class="sort-input-group">
                <label for="sortSelect" class="filter-label"><span aria-hidden="true">⇅</span> Sort:</label>
                <select name="sort" id="sortSelect" class="sort-select" onchange="document.getElementById('libraryFilterForm').submit()">
                    <option value="id_asc" <?= $sortBy === 'id_asc' ? 'selected' : '' ?>>Catalogue ID (Default)</option>
                    <option value="title_asc" <?= $sortBy === 'title_asc' ? 'selected' : '' ?>>Title (A &rarr; Z)</option>
                    <option value="title_desc" <?= $sortBy === 'title_desc' ? 'selected' : '' ?>>Title (Z &rarr; A)</option>
                    <option value="artist_asc" <?= $sortBy === 'artist_asc' ? 'selected' : '' ?>>Artist (A &rarr; Z)</option>
                    <option value="artist_desc" <?= $sortBy === 'artist_desc' ? 'selected' : '' ?>>Artist (Z &rarr; A)</option>
                    <option value="id_desc" <?= $sortBy === 'id_desc' ? 'selected' : '' ?>>Newest Added First</option>
                </select>
            </div>

            <div class="sort-input-group">
                <label for="perPageSelectTop" class="filter-label"><span aria-hidden="true">📄</span> Per Page:</label>
                <select name="per_page" id="perPageSelectTop" class="sort-select" onchange="document.getElementById('libraryFilterForm').submit()" aria-label="Records per page (at most 100)">
                    <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25 per page</option>
                    <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50 per page</option>
                    <option value="75" <?= $perPage === 75 ? 'selected' : '' ?>>75 per page</option>
                    <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100 per page (Max)</option>
                </select>
            </div>
        </div>
    </div>

    <div class="filter-bar-row filter-bar-row-bottom">
        <div class="filter-pills">
            <a href="library.php?q=<?= urlencode($query) ?>&filter=all&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" class="filter-pill <?= $statusFilter === 'all' ? 'active' : '' ?>">
                All (<?= number_format($stats['total_songs']) ?>)
            </a>
            <a href="library.php?q=<?= urlencode($query) ?>&filter=unreviewed&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" class="filter-pill <?= $statusFilter === 'unreviewed' ? 'active' : '' ?>">
                Unreviewed (<span id="statUnreviewed"><?= number_format($stats['unreviewed_count']) ?></span>)
            </a>
            <a href="library.php?q=<?= urlencode($query) ?>&filter=shortlisted&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" class="filter-pill <?= $statusFilter === 'shortlisted' ? 'active' : '' ?>">
                Shortlisted (<span id="statShortlisted"><?= number_format($stats['shortlisted_count']) ?></span>)
            </a>
            <a href="library.php?q=<?= urlencode($query) ?>&filter=passed&sort=<?= urlencode($sortBy) ?>&per_page=<?= (int)$perPage ?>" class="filter-pill <?= $statusFilter === 'passed' ? 'active' : '' ?>">
                Passed (<span id="statPassed"><?= number_format($stats['passed_count']) ?></span>)
            </a>
        </div>

        <?php if ($query !== '' || $statusFilter !== 'all' || $sortBy !== 'id_asc' || $perPage !== 50): ?>
            <div class="filter-reset-wrap">
                <a href="library.php" class="btn btn-secondary btn-sm" title="Clear search, filters and custom sorting">
                    <span>↺</span> Reset Filters
                </a>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php if (empty($songs)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">🎵</div>
        <h3>No Songs Match Your Query or Filter</h3>
        <p>Try searching for a different song title or artist name, or clear the active review filter.</p>
        <div style="margin-top: 1rem; display: flex; gap: 0.5rem; justify-content: center;">
            <a href="library.php" class="btn btn-secondary btn-sm">Clear Filters</a>
            <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('btnOpenAddSongModal').click()">＋ Add New Song</button>
        </div>
    </div>
<?php else: ?>
    <!-- Catalogue Results Summary Bar -->
    <div class="library-view-toolbar">
        <div class="library-result-count text-muted text-sm">
            Showing <strong><?= count($songs) ?></strong> of <strong><?= number_format($total) ?></strong> tracks in catalogue
        </div>
    </div>

    <!-- Songs Table -->
    <div class="table-container" id="libraryTableView">
        <!-- Mobile Batch Selection Bar (Visible when thead is hidden on mobile) -->
        <div class="mobile-table-select-bar" id="mobileTableSelectBar">
            <label class="mobile-select-all-label" for="mobileSelectAllSongs">
                <input type="checkbox" id="mobileSelectAllSongs" class="mobile-select-all-cb" aria-label="Select all songs on this page">
                <span class="mobile-select-all-text">Select All Tracks on Page</span>
            </label>
            <span class="mobile-select-summary text-muted text-xs" id="mobileSelectSummary">0 selected</span>
        </div>

        <table class="data-table" id="songsTable">
            <thead>
                <tr>
                    <th class="col-select">
                        <input type="checkbox" id="selectAllSongs" title="Select all on this page" aria-label="Select all songs on this page">
                    </th>
                    <th class="col-id">
                        <a href="library.php?q=<?= urlencode($query) ?>&filter=<?= urlencode($statusFilter) ?>&sort=<?= $sortBy === 'id_asc' ? 'id_desc' : 'id_asc' ?>&per_page=<?= (int)$perPage ?>" class="sort-header-link" title="Toggle ID order">
                            # <?= $sortBy === 'id_asc' ? '▲' : ($sortBy === 'id_desc' ? '▼' : '') ?>
                        </a>
                    </th>
                    <th class="col-title col-sortable <?= in_array($sortBy, ['title_asc', 'title_desc']) ? 'sorted-col' : '' ?>">
                        <a href="library.php?q=<?= urlencode($query) ?>&filter=<?= urlencode($statusFilter) ?>&sort=<?= $sortBy === 'title_asc' ? 'title_desc' : 'title_asc' ?>&per_page=<?= (int)$perPage ?>" class="sort-header-link" title="Sort by Title">
                            Title <?= $sortBy === 'title_asc' ? '▲' : ($sortBy === 'title_desc' ? '▼' : '⇅') ?>
                        </a>
                    </th>
                    <th class="col-artist col-sortable <?= in_array($sortBy, ['artist_asc', 'artist_desc']) ? 'sorted-col' : '' ?>">
                        <a href="library.php?q=<?= urlencode($query) ?>&filter=<?= urlencode($statusFilter) ?>&sort=<?= $sortBy === 'artist_asc' ? 'artist_desc' : 'artist_asc' ?>&per_page=<?= (int)$perPage ?>" class="sort-header-link" title="Sort by Artist">
                            Artist <?= $sortBy === 'artist_asc' ? '▲' : ($sortBy === 'artist_desc' ? '▼' : '⇅') ?>
                        </a>
                    </th>
                    <th class="col-status">Status</th>
                    <th class="col-audition">Audition</th>
                    <th class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($songs as $s): 
                    $sId = (int)$s['song_id'];
                    $sTitle = htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8');
                    $sArtistClean = $s['artist'] === 'Unknown Artist' ? '' : $s['artist'];
                    $sArtistEsc = htmlspecialchars($sArtistClean, ENT_QUOTES, 'UTF-8');
                    $sVideo = htmlspecialchars($s['media_video_id'] ?? '', ENT_QUOTES, 'UTF-8');
                    $sThumb = $s['artwork_thumbnail_url'] ?? $s['artwork_image_url'] ?? null;
                    if (empty($sThumb)) {
                        $sThumb = 'assets/img/artwork-fallback.svg';
                    }
                    $isShortlisted = $s['discovery_status'] === 'shortlisted';
                    $isPassed = $s['discovery_status'] === 'passed';
                ?>
                <tr data-song-id="<?= $sId ?>" data-song-title="<?= $sTitle ?>" data-song-artist="<?= $sArtistEsc ?>" data-song-video="<?= $sVideo ?>">
                    <td class="col-select" data-label="Select"><input type="checkbox" class="song-select-checkbox" value="<?= $sId ?>" aria-label="Select track <?= $sId ?>"></td>
                    <td class="col-id" data-label="ID"><span class="track-id-badge">#<?= $sId ?></span></td>
                    <td class="song-title-cell col-title" data-label="Title">
                        <div class="song-title-wrap">
                            <img src="<?= htmlspecialchars($sThumb, ENT_QUOTES, 'UTF-8') ?>" alt="" class="song-row-thumb" loading="lazy">
                            <div class="song-title-meta">
                                <span class="song-title-text font-bold"><?= $sTitle ?></span>
                                <span class="song-artist-sub text-muted text-xs"><?= $sArtistClean ? $sArtistEsc : '— Unknown Artist —' ?></span>
                            </div>
                        </div>
                    </td>
                    <td class="song-artist-cell col-artist" data-label="Artist"><?php if ($s['artist'] === 'Unknown Artist'): ?><span class="artist-empty">&mdash; Unknown Artist &mdash;</span><?php else: ?><span><?= htmlspecialchars($s['artist'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></td>
                    <td data-label="Status" class="col-status status-badge-container"><span class="badge badge-<?= $isShortlisted ? 'shortlisted' : ($isPassed ? 'passed' : 'unreviewed') ?>"><?= $isShortlisted ? 'Shortlisted' : ($isPassed ? 'Passed' : 'Not Reviewed') ?></span></td>
                    <td data-label="Audition" class="col-audition"><?php if ($s['audition_total'] !== null): ?><span class="audition-score-val" style="font-family: var(--font-mono); font-weight: 700; color: var(--success);"><?= (int)$s['audition_total'] ?><span class="audition-score-max"> / 50</span></span><?php elseif (!empty($s['audition_incomplete'])): ?><span style="font-family: var(--font-mono); color: var(--gold); font-size: 0.825rem;">Incomplete</span><?php elseif ($isShortlisted): ?><a href="auditions.php?q=<?= urlencode($s['title']) ?>" style="font-size: 0.825rem;">Score &rarr;</a><?php else: ?><span style="color: var(--text-muted); font-size: 0.825rem;">&mdash;</span><?php endif; ?></td>
                    <td class="col-actions" data-label="Actions">
                        <div class="action-group action-toolbar-unified">
                            <a href="auditions.php?song_id=<?= $sId ?>" class="btn-xs btn-primary-subtle" title="Play <?= $sTitle ?> in AFM#2" aria-label="Play <?= $sTitle ?> in Auditions" data-tooltip="Play in Auditions">
                                <svg class="btn-svg-icon icon-play" width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg><span class="sr-only">Play</span>
                            </a>
                            <button type="button" class="btn-xs btn-lyrics-quick-view" title="View lyrics for <?= $sTitle ?>" aria-label="View lyrics for <?= $sTitle ?>" data-tooltip="View Lyrics">
                                <svg class="btn-svg-icon icon-lyrics" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg><span class="sr-only">Lyrics</span>
                            </button>
                            <?php if (!empty($s['media_video_id'])): ?>
                                <a href="https://www.youtube.com/watch?v=<?= $sVideo ?>" target="_blank" rel="noopener noreferrer" class="link-external btn-link-yt" title="Watch <?= $sTitle ?> on YouTube" aria-label="Watch <?= $sTitle ?> on YouTube" data-tooltip="YouTube">
                                    <svg class="btn-svg-icon icon-yt" width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg><span class="sr-only">YouTube</span>
                                </a>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars(get_youtube_search_url($s['title'], $s['artist'])) ?>" target="_blank" rel="noopener noreferrer" class="link-external text-muted btn-link-search" title="Search YouTube for <?= $sTitle ?>" aria-label="Search YouTube for <?= $sTitle ?>" data-tooltip="Search YouTube">
                                    <svg class="btn-svg-icon icon-search" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><span class="sr-only">Search</span>
                                </a>
                            <?php endif; ?>
                            <button type="button" class="btn-action-shortlist <?= $isShortlisted ? 'active' : '' ?>" title="Shortlist <?= $sTitle ?> for auditions" aria-label="Shortlist <?= $sTitle ?> for auditions" aria-pressed="<?= $isShortlisted ? 'true' : 'false' ?>" data-tooltip="Shortlist track">
                                <svg class="btn-svg-icon icon-star" width="15" height="15" viewBox="0 0 24 24" fill="<?= $isShortlisted ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg><span class="sr-only">Shortlist</span>
                            </button>
                            <button type="button" class="btn-action-pass <?= $isPassed ? 'active' : '' ?>" title="Pass <?= $sTitle ?>" aria-label="Pass <?= $sTitle ?>" aria-pressed="<?= $isPassed ? 'true' : 'false' ?>" data-tooltip="Pass track">
                                <svg class="btn-svg-icon icon-cross" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg><span class="sr-only">Pass</span>
                            </button>
                            <button type="button" class="btn-manage-edit" title="Edit <?= $sTitle ?>" aria-label="Edit <?= $sTitle ?> metadata" data-tooltip="Edit metadata">
                                <svg class="btn-svg-icon icon-edit" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><span class="sr-only">Edit</span>
                            </button>
                            <button type="button" class="btn-manage-delete" title="Delete <?= $sTitle ?>" aria-label="Delete <?= $sTitle ?> from catalogue" data-tooltip="Delete track">
                                <svg class="btn-svg-icon icon-trash" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg><span class="sr-only">Delete</span>
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
        $total, 
        $perPage, 
        'library.php', 
        [
            'q' => $query,
            'filter' => $statusFilter,
            'sort' => $sortBy
        ], 
        'songs',
        10
    ); ?>
<?php endif; ?>

<!-- =========================================================================
     Song Management Modals: Add Song, Edit Song, Delete Song
     ========================================================================= -->

<!-- Add Song Modal -->
<div class="modal-overlay" id="addSongModal" role="dialog" aria-modal="true" aria-labelledby="addSongModalTitle">
    <div class="modal-content song-management-modal-content">
        <div class="modal-header">
            <div>
                <h3 class="modal-title font-brand" id="addSongModalTitle"><span>＋</span> Add Song to Catalogue</h3>
                <p class="modal-subtitle">Auto-extract from YouTube link or enter details manually.</p>
            </div>
            <button type="button" class="modal-close" id="btnCloseAddSongModal" data-modal-close aria-label="Close dialog">&times;</button>
        </div>

        <!-- Mode Tabs -->
        <div class="modal-tab-bar">
            <button type="button" class="modal-tab-btn active" id="tabBtnYt" data-tab="tabYt">
                <span>🎬</span> Extract from YouTube
            </button>
            <button type="button" class="modal-tab-btn" id="tabBtnManual" data-tab="tabManual">
                <span>✍️</span> Manual Entry
            </button>
        </div>

        <div class="modal-body">
            <!-- Tab 1: YouTube Extraction -->
            <div class="tab-pane active" id="paneYt">
                <div class="form-group form-group-compact">
                    <label for="ytExtractUrl" class="form-label font-bold">YouTube URL or Video ID</label>
                    <div class="input-with-action">
                        <input type="text" id="ytExtractUrl" class="form-input" placeholder="e.g. https://www.youtube.com/watch?v=... or youtu.be/...">
                        <button type="button" class="btn btn-secondary" id="btnExtractYt"><span>⚡</span> Extract Info</button>
                    </div>
                    <span class="form-help">Paste any YouTube or YouTube Music watch/share link. Title, artist, and thumbnail will be extracted instantly.</span>
                </div>

                <!-- Extraction Loading Spinner -->
                <div id="ytExtractLoading" class="extract-loading-box" style="display: none;">
                    <div class="loading-spinner"></div>
                    <span>Extracting track details and thumbnail...</span>
                </div>

                <!-- Match Notice (appears when identical title+artist is detected in catalogue) -->
                <div id="ytExistingSongNotice" class="existing-song-notice" style="display: none;">
                    <div class="existing-notice-icon">💡</div>
                    <div class="existing-notice-content">
                        <strong id="ytExistingSongTitle">Matches Existing Catalogue Track:</strong>
                        <p id="ytExistingSongDesc">Saving will update this record with your link and review status instead of creating a duplicate.</p>
                    </div>
                </div>

                <!-- Preview Card (appears when extracted) -->
                <div id="ytExtractPreview" class="extract-preview-card" style="display: none;">
                    <div class="extract-preview-thumb-wrap">
                        <img id="ytPreviewThumb" src="" alt="Thumbnail" class="extract-preview-thumb">
                    </div>
                    <div class="extract-preview-fields">
                        <div class="form-group form-group-compact">
                            <label for="ytExtractedTitle" class="form-label font-bold">Song Title <span class="required">*</span></label>
                            <input type="text" id="ytExtractedTitle" class="form-input" placeholder="Song title">
                        </div>
                        <div class="form-group form-group-compact">
                            <label for="ytExtractedArtist" class="form-label font-bold">Artist Name</label>
                            <input type="text" id="ytExtractedArtist" class="form-input" placeholder="Artist name (optional)">
                        </div>
                        <div class="preview-meta-row">
                            <span class="preview-badge" id="ytPreviewChannel">Channel: --</span>
                            <span class="preview-badge" id="ytPreviewVid">ID: --</span>
                        </div>
                    </div>
                </div>

                <div class="form-group form-group-compact" style="margin-top: 0.85rem;">
                    <label for="ytDiscoveryStatus" class="form-label font-bold">Initial Review Status</label>
                    <select id="ytDiscoveryStatus" class="form-input">
                        <option value="unreviewed">Not Reviewed (Default)</option>
                        <option value="shortlisted">Shortlist for Auditions</option>
                        <option value="passed">Mark as Passed</option>
                    </select>
                </div>
            </div>

            <!-- Tab 2: Manual Entry -->
            <div class="tab-pane" id="paneManual" style="display: none;">
                <!-- Match Notice for Manual Entry -->
                <div id="manualExistingSongNotice" class="existing-song-notice" style="display: none;">
                    <div class="existing-notice-icon">💡</div>
                    <div class="existing-notice-content">
                        <strong id="manualExistingSongTitle">Matches Existing Catalogue Track:</strong>
                        <p id="manualExistingSongDesc">Saving will update this record with your link and review status instead of creating a duplicate.</p>
                    </div>
                </div>

                <div class="form-group form-group-compact">
                    <label for="manualTitle" class="form-label font-bold">Song Title <span class="required">*</span></label>
                    <input type="text" id="manualTitle" class="form-input" placeholder="e.g. Bohemian Rhapsody" required>
                </div>
                <div class="form-group form-group-compact">
                    <label for="manualArtist" class="form-label font-bold">Artist Name</label>
                    <input type="text" id="manualArtist" class="form-input" placeholder="e.g. Queen (leave blank if unknown)">
                </div>
                <div class="form-group form-group-compact">
                    <label for="manualYtUrl" class="form-label font-bold">YouTube Link or Video ID (Optional)</label>
                    <input type="text" id="manualYtUrl" class="form-input" placeholder="e.g. https://www.youtube.com/watch?v=... or 11-char ID">
                    <span class="form-help">Optional recording link for player audio and album thumbnail.</span>
                </div>
                <div class="form-group form-group-compact" style="margin-top: 0.85rem;">
                    <label for="manualDiscoveryStatus" class="form-label font-bold">Initial Review Status</label>
                    <select id="manualDiscoveryStatus" class="form-input">
                        <option value="unreviewed">Not Reviewed (Default)</option>
                        <option value="shortlisted">Shortlist for Auditions</option>
                        <option value="passed">Mark as Passed</option>
                    </select>
                </div>
            </div>

            <div id="addSongError" class="alert alert-error" style="display: none; margin-top: 0.75rem;"></div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" id="btnCancelAddSong" data-modal-close>Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="btnSubmitAddSong">Save to Catalogue</button>
        </div>
    </div>
</div>

<!-- Edit Song Modal -->
<div class="modal-overlay" id="editSongModal" role="dialog" aria-modal="true" aria-labelledby="editSongModalTitle">
    <div class="modal-content song-management-modal-content">
        <div class="modal-header">
            <div>
                <h3 class="modal-title font-brand" id="editSongModalTitle"><span>✏️</span> Edit Song Record</h3>
                <p class="modal-subtitle" id="editSongSubtitle">Update title, artist, or YouTube recording</p>
            </div>
            <button type="button" class="modal-close" id="btnCloseEditSongModal" data-modal-close aria-label="Close dialog">&times;</button>
        </div>

        <div class="modal-body">
            <input type="hidden" id="editSongId" value="">
            <div class="form-group">
                <label for="editSongTitle" class="form-label font-bold">Song Title <span class="required">*</span></label>
                <input type="text" id="editSongTitle" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="editSongArtist" class="form-label font-bold">Artist Name</label>
                <input type="text" id="editSongArtist" class="form-input" placeholder="Leave blank for Unknown Artist">
            </div>
            <div class="form-group">
                <label for="editSongVideo" class="form-label font-bold">YouTube URL or Video ID</label>
                <div class="input-with-action">
                    <input type="text" id="editSongVideo" class="form-input" placeholder="Paste YouTube link or 11-char ID">
                    <button type="button" class="btn btn-secondary" id="btnEditFetchYt" title="Auto-fill title/artist from this video"><span>⚡</span> Auto-Fill</button>
                </div>
                <span class="form-help">Updates player embed and artwork thumbnail reference.</span>
            </div>

            <div id="editSongError" class="alert alert-error" style="display: none; margin-top: 1rem;"></div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" id="btnCancelEditSong" data-modal-close>Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="btnSubmitEditSong">Save Changes</button>
        </div>
    </div>
</div>

<!-- Delete Song Confirmation Modal -->
<div class="modal-overlay" id="deleteSongModal" role="dialog" aria-modal="true" aria-labelledby="deleteSongModalTitle">
    <div class="modal-content song-management-modal-content delete-modal-content">
        <div class="modal-header">
            <div>
                <h3 class="modal-title font-brand text-danger" id="deleteSongModalTitle"><span>🗑️</span> Delete Song</h3>
                <p class="modal-subtitle">Permanently remove duplicate or unwanted song from catalogue</p>
            </div>
            <button type="button" class="modal-close" id="btnCloseDeleteSongModal" data-modal-close aria-label="Close dialog">&times;</button>
        </div>

        <div class="modal-body">
            <input type="hidden" id="deleteSongId" value="">
            <div class="delete-warning-box">
                <p>Are you sure you want to permanently delete this song from the catalogue?</p>
                <div class="delete-target-preview">
                    <span class="delete-target-id" id="deleteDisplayId">#--</span>
                    <strong class="delete-target-title" id="deleteDisplayTitle">Song Title</strong>
                    <span class="delete-target-artist" id="deleteDisplayArtist">Artist</span>
                </div>
                <p class="delete-integrity-note">
                    ℹ️ <strong>Tournament Safeguard:</strong> Songs with completed scorecards or official tournament milestones cannot be deleted to preserve tournament history. Unrated songs and redundant duplicates can be deleted cleanly.
                </p>
            </div>
            <div id="deleteSongError" class="alert alert-error" style="display: none; margin-top: 1rem;"></div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" id="btnCancelDeleteSong" data-modal-close>Cancel</button>
            <button type="button" class="btn btn-danger btn-sm" id="btnConfirmDeleteSong">Permanently Delete</button>
        </div>
    </div>
</div>

<!-- Bulk Actions Floating Bar -->
<div class="bulk-actions-bar" id="bulkActionsBar" style="display: none;">
    <div class="bulk-actions-inner">
        <div class="bulk-actions-info">
            <span class="bulk-count-badge" id="bulkSelectedCount">0</span>
            <span class="bulk-count-text">selected</span>
        </div>
        <div class="bulk-actions-buttons">
            <button type="button" class="btn btn-primary btn-sm" id="btnBulkShortlist" title="Shortlist selected tracks for auditions" aria-label="Shortlist selected tracks">
                <span>⭐</span> <span class="bulk-btn-text">Shortlist</span>
            </button>
            <button type="button" class="btn btn-secondary btn-sm" id="btnBulkPass" title="Mark selected tracks as passed" aria-label="Mark selected tracks as passed">
                <span>✕</span> <span class="bulk-btn-text">Pass</span>
            </button>
            <button type="button" class="btn btn-danger-subtle btn-sm" id="btnBulkDelete" title="Permanently delete selected unrated tracks" aria-label="Delete selected tracks">
                <span>🗑️</span> <span class="bulk-btn-text">Delete</span>
            </button>
            <button type="button" class="btn btn-secondary btn-sm" id="btnBulkClear" title="Clear selection" aria-label="Clear selection">
                <span class="bulk-btn-text">Clear</span>
            </button>
        </div>
    </div>
</div>

<!-- Unified Bulk Action Confirmation & Eligibility Modal -->
<div class="modal-overlay" id="bulkActionConfirmModal" role="dialog" aria-modal="true" aria-labelledby="bulkActionModalTitle">
    <div class="modal-content song-management-modal-content bulk-action-modal-content">
        <div class="modal-header">
            <div>
                <h3 class="modal-title font-brand" id="bulkActionModalTitle">
                    <span id="bulkActionModalIcon">⭐</span> <span id="bulkActionModalHeading">Shortlist Selected Tracks</span>
                </h3>
                <p class="modal-subtitle" id="bulkActionModalSubtitle">Pre-action eligibility breakdown and validation</p>
            </div>
            <button type="button" class="modal-close" id="btnCloseBulkActionModal" data-modal-close aria-label="Close dialog">&times;</button>
        </div>

        <div class="modal-body">
            <!-- Loading Spinner State while evaluating rules -->
            <div id="bulkActionLoading" class="bulk-action-loading" style="display: none;">
                <div class="spinner"></div>
                <span>Checking competition rules &amp; eligibility...</span>
            </div>

            <!-- Main Details View -->
            <div id="bulkActionDetails" class="bulk-action-details">
                <!-- Summary Stats Pills Grid -->
                <div class="bulk-stats-grid">
                    <div class="bulk-stat-card bulk-stat-total">
                        <span class="bulk-stat-label">Selected</span>
                        <span class="bulk-stat-val" id="bulkStatSelected">0</span>
                    </div>
                    <div class="bulk-stat-card bulk-stat-eligible">
                        <span class="bulk-stat-label">Eligible</span>
                        <span class="bulk-stat-val" id="bulkStatEligible">0</span>
                    </div>
                    <div class="bulk-stat-card bulk-stat-ineligible">
                        <span class="bulk-stat-label">Ineligible</span>
                        <span class="bulk-stat-val" id="bulkStatIneligible">0</span>
                    </div>
                </div>

                <!-- Explanation Banner -->
                <div class="bulk-explanation-banner" id="bulkExplanationBanner">
                    <p id="bulkExplanationText">Only the <strong>0</strong> eligible tracks will be affected.</p>
                </div>

                <!-- Ineligible Tracks Breakdown List -->
                <div class="bulk-ineligible-section" id="bulkIneligibleSection" style="display: none;">
                    <div class="bulk-section-header">
                        <span class="bulk-section-title text-warning">⚠️ Ineligible Tracks (<span id="bulkIneligibleCountText">0</span>)</span>
                        <span class="bulk-section-note">Protected by competition rules and safeguards</span>
                    </div>
                    <div class="bulk-ineligible-list" id="bulkIneligibleList">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>

                <!-- Eligible Tracks Preview -->
                <div class="bulk-eligible-section" id="bulkEligibleSection" style="display: none;">
                    <div class="bulk-section-header">
                        <span class="bulk-section-title text-success">✓ Eligible Tracks (<span id="bulkEligibleCountText">0</span>)</span>
                        <span class="bulk-section-note">Ready to be processed</span>
                    </div>
                    <div class="bulk-eligible-list" id="bulkEligibleList">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>

                <!-- Error Container -->
                <div id="bulkActionError" class="alert alert-error" style="display: none; margin-top: 1rem;"></div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" id="btnCancelBulkAction" data-modal-close>Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" id="btnConfirmBulkAction">Confirm</button>
        </div>
    </div>
</div>

<!-- Bulk Delete Confirmation Modal -->
<div class="modal-overlay" id="bulkDeleteModal" role="dialog" aria-modal="true" aria-labelledby="bulkDeleteModalTitle">
    <div class="modal-content song-management-modal-content delete-modal-content">
        <div class="modal-header">
            <div>
                <h3 class="modal-title font-brand text-danger" id="bulkDeleteModalTitle"><span>🗑️</span> Delete Selected Tracks</h3>
                <p class="modal-subtitle">Permanently remove selected unrated tracks from catalogue</p>
            </div>
            <button type="button" class="modal-close" id="btnCloseBulkDeleteModal" data-modal-close aria-label="Close dialog">&times;</button>
        </div>

        <div class="modal-body">
            <div class="delete-warning-box">
                <p>You have selected <strong id="bulkDeleteTotalCount">0</strong> track(s) for permanent deletion.</p>
                <div id="bulkDeleteListPreview" class="bulk-delete-list"></div>
                <p class="delete-integrity-note">
                    ℹ️ <strong>Tournament Safeguard:</strong> Any track with recorded tournament scorecards or round results cannot be deleted and will be protected automatically. Only unrated tracks without competition history will be permanently deleted.
                </p>
            </div>
            <div id="bulkDeleteError" class="alert alert-error" style="display: none; margin-top: 1rem;"></div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" id="btnCancelBulkDelete" data-modal-close>Cancel</button>
            <button type="button" class="btn btn-danger btn-sm" id="btnConfirmBulkDelete">Delete Eligible Tracks</button>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
