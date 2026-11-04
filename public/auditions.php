<?php
/**
 * AFM#2 - Formal Auditions Scorecards Interface
 * Complete multi-criteria evaluation (0-10 each, /50 auto-total) with incomplete scorecard resumption.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/artwork_helper.php';

require_auth();

$currentUserId = get_current_user_id();

$pageTitle = 'Formal Auditions — AFM#2 2026';

// Get all rounds to allow round selection (scoped to current user's journey)
$allRounds = get_tournament_rounds($currentUserId);
$activeRound = get_active_round($currentUserId);

$selectedRoundId = isset($_GET['round_id']) ? (int)$_GET['round_id'] : (int)($activeRound['round_id'] ?? 1);
$currentRound = get_round_by_id($selectedRoundId, $currentUserId) ?? $activeRound;

$filter = $_GET['filter'] ?? 'all';
$searchQuery = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

// Records per page: 25 (default), 50, 75, 100 (at most 100 as requested)
$rawPerPage = (int)($_GET['per_page'] ?? 25);
$perPage = in_array($rawPerPage, [25, 50, 75, 100], true) ? $rawPerPage : max(10, min(100, $rawPerPage > 0 ? $rawPerPage : 25));

// Handle direct song audition navigation (?song_id=...)
$targetSongId = isset($_GET['song_id']) ? (int)$_GET['song_id'] : 0;
if ($targetSongId > 0) {
    $targetSong = get_song_by_id($targetSongId, $currentUserId);
    if ($targetSong) {
        // Ensure song is shortlisted and enrolled in auditions for current user
        $pdo = get_db_connection();
        $shortlistStmt = $pdo->prepare("
            INSERT INTO `song_discovery` (`song_id`, `user_id`, `status`, `reviewed_at`)
            VALUES (:id, :uid, 'shortlisted', NOW())
            ON DUPLICATE KEY UPDATE `status` = 'shortlisted', `reviewed_at` = NOW()
        ");
        $shortlistStmt->execute([':id' => $targetSongId, ':uid' => $currentUserId]);

        $ensureEntry = $pdo->prepare("
            INSERT INTO `afm_entries` (`song_id`, `user_id`, `status`)
            VALUES (:id, :uid, 'auditioning')
            ON DUPLICATE KEY UPDATE `status` = CASE WHEN `status` = 'excluded' THEN 'auditioning' ELSE `status` END
        ");
        $ensureEntry->execute([':id' => $targetSongId, ':uid' => $currentUserId]);

        if (empty($searchQuery)) {
            $searchQuery = $targetSong['title'];
            $filter = 'all';
        }
    }
}

// Origin for YouTube postMessage API handshake
$appOrigin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// Fetch eligible entries for this round scoped to current user
$result = get_audition_entries($selectedRoundId, $filter, $searchQuery, $page, $perPage, $currentUserId);
$totalCount = $result['total'];
$totalPages = $result['total_pages'];
$counts = $result['counts'];

// Snap if page exceeds totalPages due to per_page change or filter
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
    $result = get_audition_entries($selectedRoundId, $filter, $searchQuery, $page, $perPage, $currentUserId);
}
$entries = $result['entries'];

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-container">
    <!-- Breadcrumb & Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Formal Auditions Scorecards</h1>
            <p class="page-subtitle">
                Evaluate each performance across 5 core criteria (0–10 each). Scores auto-total out of 50.
            </p>
        </div>
        <div class="header-actions">
            <a href="discovery.php" class="btn btn-secondary">
                <span class="btn-icon">★</span> View Shortlist
            </a>
            <a href="tournament.php?round_id=<?= (int)$selectedRoundId ?>" class="btn btn-primary">
                <span class="btn-icon">🏆</span> Stage Leaderboard
            </a>
        </div>
    </div>

    <!-- Tournament Stage Progression Navigator -->
    <div class="stage-nav-card">
        <div class="stage-nav-header">
            <div class="stage-nav-title-group">
                <span class="stage-nav-eyebrow">
                    <span class="eyebrow-icon">⚡</span> Tournament Progression
                </span>
                <h2 class="stage-nav-title">Select Competition Stage</h2>
            </div>
            <div class="stage-nav-badge-group">
                <span class="stage-nav-hint">Current Stage: <strong><?= htmlspecialchars($currentRound['round_name'] ?? 'Stage', ENT_QUOTES, 'UTF-8') ?></strong></span>
                <span class="stage-nav-indicator <?= htmlspecialchars($currentRound['status'] ?? 'upcoming', ENT_QUOTES, 'UTF-8') ?>">
                    <?= ($currentRound['status'] ?? '') === 'in_progress' ? '● Stage Active' : (($currentRound['status'] ?? '') === 'completed' ? '✓ Stage Finished' : '○ Upcoming Stage') ?>
                </span>
            </div>
        </div>
        <div class="stage-stepper">
            <?php foreach ($allRounds as $r): 
                $isSel = ((int)$r['round_id'] === $selectedRoundId);
                $rStatus = $r['status'];
                $rSeq = (int)$r['sequence_no'];
            ?>
            <a 
                href="auditions.php?round_id=<?= (int)$r['round_id'] ?><?= $filter !== 'all' ? '&filter=' . urlencode($filter) : '' ?><?= $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '' ?><?= $perPage !== 25 ? '&per_page=' . (int)$perPage : '' ?>" 
                class="step-item <?= $isSel ? 'active' : '' ?> <?= $rStatus ?>"
                aria-current="<?= $isSel ? 'step' : 'false' ?>"
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

    <!-- Stats Summary Strip -->
    <div class="stats-grid mb-4" style="margin-bottom: 2.25rem;">
        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Total in Stage</span>
                <span class="stat-icon icon-library">♪</span>
            </div>
            <div class="stat-value text-accent"><?= number_format($counts['total']) ?></div>
            <div class="stat-desc"><?= htmlspecialchars($currentRound['round_name'] ?? 'Stage', ENT_QUOTES, 'UTF-8') ?> participants</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Complete Scores</span>
                <span class="stat-icon icon-completed">✓</span>
            </div>
            <div class="stat-value text-success" id="statRoundComplete"><?= number_format($counts['completed']) ?></div>
            <div class="stat-desc">All 5 criteria evaluated (/50)</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Incomplete</span>
                <span class="stat-icon icon-in-progress">⏳</span>
            </div>
            <div class="stat-value text-gold" id="statRoundIncomplete"><?= number_format($counts['incomplete']) ?></div>
            <div class="stat-desc">Partially scored, pending finish</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-label">Unscored</span>
                <span class="stat-icon icon-unreviewed">○</span>
            </div>
            <div class="stat-value text-muted" id="statRoundUnscored"><?= number_format($counts['unscored']) ?></div>
            <div class="stat-desc">Awaiting scorecard entry</div>
        </div>
    </div>

    <!-- Filter & Search Toolbar (Symmetrical 2-Row Design) -->
    <form method="GET" action="auditions.php" class="filter-bar" id="auditionsFilterForm" style="margin-bottom: 2.25rem;">
        <input type="hidden" name="round_id" value="<?= (int)$selectedRoundId ?>">

        <div class="filter-bar-row filter-bar-row-top">
            <div class="search-input-wrapper">
                <span class="search-icon" aria-hidden="true">🔍</span>
                <input 
                    type="search" 
                    name="q" 
                    class="search-input" 
                    placeholder="Search song title or artist..." 
                    value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                    aria-label="Search audition entries"
                >
                <?php if ($searchQuery !== ''): ?>
                    <a href="auditions.php?round_id=<?= (int)$selectedRoundId ?><?= $filter !== 'all' ? '&filter=' . urlencode($filter) : '' ?><?= $perPage !== 25 ? '&per_page=' . (int)$perPage : '' ?>" class="btn-search-clear" title="Clear search">✕</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-search-apply">Filter</button>
            </div>

            <div class="filter-selectors-cluster">
                <div class="sort-input-group">
                    <label for="perPageAuditions" class="filter-label"><span aria-hidden="true">📄</span> Per Page:</label>
                    <select name="per_page" id="perPageAuditions" class="sort-select" onchange="document.getElementById('auditionsFilterForm').submit()" aria-label="Records per page (at most 100)">
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
                <a href="auditions.php?round_id=<?= (int)$selectedRoundId ?>&filter=all<?= $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '' ?>&per_page=<?= (int)$perPage ?>" 
                   class="filter-pill <?= $filter === 'all' ? 'active' : '' ?>">
                    All (<?= number_format($counts['total']) ?>)
                </a>
                <a href="auditions.php?round_id=<?= (int)$selectedRoundId ?>&filter=incomplete<?= $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '' ?>&per_page=<?= (int)$perPage ?>" 
                   class="filter-pill <?= $filter === 'incomplete' ? 'active' : '' ?>">
                    Incomplete (<?= number_format($counts['incomplete']) ?>)
                </a>
                <a href="auditions.php?round_id=<?= (int)$selectedRoundId ?>&filter=unscored<?= $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '' ?>&per_page=<?= (int)$perPage ?>" 
                   class="filter-pill <?= $filter === 'unscored' ? 'active' : '' ?>">
                    Unscored (<?= number_format($counts['unscored']) ?>)
                </a>
                <a href="auditions.php?round_id=<?= (int)$selectedRoundId ?>&filter=completed<?= $searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '' ?>&per_page=<?= (int)$perPage ?>" 
                   class="filter-pill <?= $filter === 'completed' ? 'active' : '' ?>">
                    Completed (<?= number_format($counts['completed']) ?>)
                </a>
            </div>

            <?php if ($searchQuery !== '' || $filter !== 'all' || $perPage !== 25): ?>
                <div class="filter-reset-wrap">
                    <a href="auditions.php?round_id=<?= (int)$selectedRoundId ?>" class="btn btn-secondary btn-sm" title="Clear search, filters and custom per-page">
                        <span>↺</span> Reset Filters
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <!-- Audition Scorecards List -->
    <?php if (empty($entries)): ?>
        <div class="empty-state">
            <div class="empty-icon">🎧</div>
            <h2 class="empty-title">No Audition Entries Found</h2>
            <p class="empty-desc">
                <?php if ($counts['total'] === 0 && (int)$selectedRoundId === 1): ?>
                    No songs have been shortlisted for auditions yet. Start by reviewing songs in your library or discovery list.
                <?php elseif ($counts['total'] === 0): ?>
                    No songs have qualified for <?= htmlspecialchars($currentRound['round_name'], ENT_QUOTES, 'UTF-8') ?> yet. Previous stage must advance first.
                <?php else: ?>
                    No entries match your active search or filter. Try adjusting your search query.
                <?php endif; ?>
            </p>
            <div class="empty-actions">
                <?php if ($counts['total'] === 0 && (int)$selectedRoundId === 1): ?>
                    <a href="library.php" class="btn btn-accent">Explore Song Library</a>
                    <a href="discovery.php" class="btn btn-secondary">Check Shortlist</a>
                <?php else: ?>
                    <a href="auditions.php?round_id=<?= (int)$selectedRoundId ?>" class="btn btn-secondary">Clear Filters</a>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="scorecards-container" id="scorecardsContainer">
            <?php foreach ($entries as $index => $item): 
                $entryId = (int)$item['entry_id'];
                $isComplete = !empty($item['is_complete']);
                $isIncomplete = !empty($item['is_incomplete']);
                $isFinalized = !empty($item['is_finalized']);
                $finalizedResult = $item['finalized_result'] ?? null;
                $finalizedRank = $item['finalized_rank'] ?? null;
                $totalScore = $item['total'];

                $cardClass = '';
                if ($isFinalized) {
                    $cardClass = 'complete is-finalized is-frozen';
                } elseif ($isComplete) {
                    $cardClass = 'complete';
                } elseif ($isIncomplete) {
                    $cardClass = 'incomplete';
                }
                $songId = (int)$item['song_id'];
                $hasMedia = !empty($item['media_video_id']);
                $mediaVideoId = $item['media_video_id'] ?? '';
                $mediaVersion = $item['media_version_type'] ?? 'original';
                $mediaNotes = $item['media_version_notes'] ?? '';
                $mediaEmbedStatus = $item['media_embed_status'] ?? 'allowed';
                $ytSearch = get_youtube_search_url($item['title'], $item['artist']);
                $ytMusicSearch = get_youtube_music_search_url($item['title'], $item['artist']);
                $spotifySearch = get_spotify_search_url($item['title'], $item['artist']);
                $scSearch = get_soundcloud_search_url($item['title'], $item['artist']);

                $artworkThumb = $item['artwork_thumbnail_url'] ?? $item['artwork_image_url'] ?? null;
                if (empty($artworkThumb)) {
                    $preset = get_default_atmospheric_preset($item['title'], $item['artist'] ?? '');
                    $artworkThumb = $preset['thumbnail_url'];
                    $artworkSource = 'preset';
                    $artworkAttribution = $preset['attribution_url'];
                } else {
                    $artworkSource = $item['artwork_source'] ?? 'custom';
                    $artworkAttribution = $item['artwork_attribution_url'] ?? '';
                }

                $versionLabels = [
                    'original' => 'Studio Original',
                    'live'     => 'Live Performance',
                    'acoustic' => 'Acoustic Version',
                    'cover'    => 'Cover Recording',
                    'remaster' => 'Remastered',
                    'other'    => 'Other Recording'
                ];
                $versionLabel = $versionLabels[$mediaVersion] ?? 'Confirmed Recording';
            ?>
            <div class="scorecard-wrapper mb-5">
                <div 
                    class="scorecard-card tactile-player-card <?= $cardClass ?>" 
                    id="entry-<?= $entryId ?>"
                    data-entry-id="<?= $entryId ?>"
                    data-song-id="<?= $songId ?>"
                    data-round-id="<?= (int)$selectedRoundId ?>"
                    data-finalized="<?= $isFinalized ? '1' : '0' ?>"
                    data-song-title="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>"
                    data-song-artist="<?= htmlspecialchars($item['artist'], ENT_QUOTES, 'UTF-8') ?>"
                >
                    <div class="tactile-hero-row">
                        <!-- Left: Artwork Showcase Frame -->
                        <div class="tactile-artwork-col">
                            <div class="tactile-artwork-frame">
                                <img src="<?= htmlspecialchars($artworkThumb, ENT_QUOTES, 'UTF-8') ?>" 
                                     alt="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?> Cover Art" 
                                     class="tactile-artwork-img" 
                                     loading="lazy"
                                     onerror="this.onerror=null; this.src='assets/img/artwork-fallback.svg';">
                                <div class="artwork-overlay-script"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                <button type="button" 
                                        class="btn-trigger-artwork-picker" 
                                        data-song-id="<?= $songId ?>" 
                                        data-song-title="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                        data-song-artist="<?= htmlspecialchars($item['artist'], ENT_QUOTES, 'UTF-8') ?>" 
                                        title="Discover or change album cover artwork">
                                    <span>🖼️ Artwork</span>
                                </button>
                            </div>
                            <?php if (!empty($artworkAttribution)): ?>
                                <div class="tactile-artwork-credit">
                                    <a href="<?= htmlspecialchars($artworkAttribution, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                                        Source: <?= htmlspecialchars(ucfirst($artworkSource)) ?> ↗
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Right: Metadata & Player Controls -->
                        <div class="tactile-meta-col">
                            <div class="tactile-header-bar">
                                <div class="tactile-stream-badge">
                                    <span class="stream-beacon">((●))</span>
                                    <span class="stream-title">AFM#2 Audition</span>
                                    <?php if ($isFinalized): ?>
                                        <span class="badge-finalized-pill" title="Finalized scorecard" aria-label="Finalized scorecard">
                                            <span class="lock-icon" aria-hidden="true">🔒</span> <span class="badge-finalized-text">FINALIZED</span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="scorecard-total-box">
                                    <?php
                                    $partialSum = 0;
                                    if ($isIncomplete) {
                                        if ($item['melody'] !== null) $partialSum += (int)$item['melody'];
                                        if ($item['lyrics'] !== null) $partialSum += (int)$item['lyrics'];
                                        if ($item['vocals'] !== null) $partialSum += (int)$item['vocals'];
                                        if ($item['emotion'] !== null) $partialSum += (int)$item['emotion'];
                                        if ($item['replayability'] !== null) $partialSum += (int)$item['replayability'];
                                    }
                                    ?>
                                    <div class="total-score-badge <?= ($isComplete || $isFinalized) ? 'complete' : '' ?> <?= $isFinalized ? 'is-finalized' : '' ?>">
                                        <?php if ($isFinalized || $isComplete): ?>
                                            <?= (int)$totalScore ?> / 50
                                        <?php elseif ($isIncomplete): ?>
                                            <?= $partialSum ?>* / 50
                                        <?php else: ?>
                                            — / 50
                                        <?php endif; ?>
                                    </div>
                                    <div class="total-score-label <?= $isFinalized ? 'text-finalized' : '' ?>">
                                        <?php if ($isFinalized): ?>
                                            <span class="lock-icon" aria-hidden="true">🔒</span> Finalized
                                        <?php elseif ($isComplete): ?>
                                            Complete
                                        <?php elseif ($isIncomplete): ?>
                                            Incomplete
                                        <?php else: ?>
                                            Unscored
                                        <?php endif; ?>
                                    </div>
                                    <div class="total-score-meter" title="Total score progress">
                                        <div class="total-score-fill <?= $isFinalized ? 'fill-finalized' : '' ?>" style="width: <?= ($isComplete || $isFinalized) ? min(100, round(($totalScore / 50) * 100)) : ($isIncomplete ? min(100, round(($partialSum / 50) * 100)) : 0) ?>%;"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="tactile-song-info">
                                <h2 class="tactile-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                                <div class="tactile-artist"><?= htmlspecialchars($item['artist'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="tactile-details">Tournament Stage: <?= htmlspecialchars($currentRound['round_name'], ENT_QUOTES, 'UTF-8') ?> &bull; Catalogue Track #<?= (int)$songId ?></div>
                            </div>
                        </div><!-- /.tactile-meta-col -->
                    </div><!-- /.tactile-hero-row -->

                    <!-- Audio Playback & Lyrics Experience Panel -->
                    <div class="scorecard-media-panel" 
                         data-song-id="<?= $songId ?>" 
                         data-entry-id="<?= $entryId ?>"
                         data-song-title="<?= htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                         data-song-artist="<?= htmlspecialchars($item['artist'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                         data-video-id="<?= htmlspecialchars($mediaVideoId, ENT_QUOTES, 'UTF-8') ?>"
                         data-user-url="<?= htmlspecialchars($item['media_user_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                         data-version-type="<?= htmlspecialchars($mediaVersion, ENT_QUOTES, 'UTF-8') ?>"
                         data-version-notes="<?= htmlspecialchars($mediaNotes, ENT_QUOTES, 'UTF-8') ?>"
                         data-embed-status="<?= htmlspecialchars($mediaEmbedStatus, ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <div class="media-toolbar">
                        <div class="media-toolbar-left">
                            <!-- Live Playback Status Pill -->
                            <div class="playback-status-pill status-ready" id="status-pill-<?= $songId ?>" title="Live playback state">
                                <span class="status-dot"></span>
                                <span class="status-text">Ready to Play</span>
                            </div>

                            <div class="audio-match-indicator">
                                <?php if ($hasMedia): ?>
                                    <span class="media-badge media-badge-confirmed" title="<?= htmlspecialchars($mediaNotes ? "Notes: {$mediaNotes}" : "Confirmed {$versionLabel}") ?>" aria-label="<?= htmlspecialchars($versionLabel) ?>" data-tooltip="<?= htmlspecialchars($versionLabel) ?>">
                                        <svg class="btn-svg-icon icon-disc" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg> <span class="version-name"><?= htmlspecialchars($versionLabel) ?></span>
                                    </span>
                                    <button type="button" class="btn-xs btn-primary-subtle btn-toggle-player active" title="Play or hide embedded player" aria-label="Toggle audio player" aria-pressed="true" data-tooltip="Toggle Player">
                                        <svg class="btn-svg-icon icon-play" width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg> <span class="btn-text player-toggle-label">Hide Player</span>
                                    </button>
                                    <button type="button" class="btn-xs btn-secondary btn-edit-media" title="Change recording version or video ID" aria-label="Change recording link" data-tooltip="Change Recording">
                                        <svg class="btn-svg-icon icon-edit" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> <span class="btn-text">Change Recording</span>
                                    </button>
                                <?php else: ?>
                                    <span class="media-badge media-badge-unmatched">○ No recording selected</span>
                                    <button type="button" class="btn-xs btn-primary-subtle btn-match-media" title="Select and confirm recording" aria-label="Select and confirm recording" data-tooltip="Select Recording">
                                        <svg class="btn-svg-icon icon-audio" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg> <span class="btn-text">Select Recording</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="media-toolbar-right">
                            <button type="button" class="btn-xs btn-toggle-lyrics active" title="View lyrics from LRCLIB" aria-label="Toggle lyrics panel" aria-pressed="true" data-tooltip="Toggle Lyrics">
                                <svg class="btn-svg-icon icon-lyrics" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> <span class="btn-text lyrics-toggle-label">Lyrics</span>
                            </button>
                        </div>
                    </div>

                    <!-- Integrated Player & Lyrics Experience Container (Always Mounted & Visible) -->
                    <div class="audition-drawer audio-active lyrics-active">
                        <!-- Left Column: Official Embedded Player / Matcher -->
                        <div class="drawer-col-audio">
                            <!-- Player Box -->
                            <div class="player-container">
                                <?php if ($hasMedia): ?>
                                    <?php if ($mediaEmbedStatus === 'blocked'): ?>
                                        <div class="embed-blocked-alert">
                                            <div class="embed-blocked-title">⚠️ Video Embedding Restricted</div>
                                            <div class="embed-blocked-desc">
                                                The uploader or artist has disabled third-party embedding for this YouTube recording. You can listen directly in an official player tab, or change to another official or live recording:
                                            </div>
                                            <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <a href="https://www.youtube.com/watch?v=<?= htmlspecialchars($mediaVideoId) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-accent">
                                                    Open on YouTube ↗
                                                </a>
                                                <a href="https://music.youtube.com/watch?v=<?= htmlspecialchars($mediaVideoId) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-secondary">
                                                    YT Music ↗
                                                </a>
                                                <button type="button" class="btn btn-sm btn-primary-subtle btn-edit-media">
                                                    🔁 Change Recording
                                                </button>
                                                <button type="button" class="btn btn-sm btn-secondary btn-retest-embed">
                                                    Retry Embed
                                                </button>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="player-wrapper">
                                            <!-- Embedded official YouTube IFrame with enablejsapi=1 -->
                                            <?php
                                                $appOrigin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
                                                $embedSrc = "https://www.youtube.com/embed/" . rawurlencode($mediaVideoId) . "?enablejsapi=1&amp;playsinline=1&amp;rel=0&amp;origin=" . rawurlencode($appOrigin);
                                            ?>
                                            <iframe 
                                                class="yt-embed-frame" 
                                                id="yt-player-<?= (int)$songId ?>" 
                                                data-video-id="<?= htmlspecialchars($mediaVideoId, ENT_QUOTES, 'UTF-8') ?>"
                                                data-src="<?= $embedSrc ?>" 
                                                src="<?= $embedSrc ?>" 
                                                referrerpolicy="strict-origin-when-cross-origin"
                                                loading="lazy" 
                                                title="AFM#2 Audio Player: <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen" 
                                                allowfullscreen
                                            ></iframe>
                                            <div class="player-footer">
                                                <div class="player-footer-left">
                                                    <a href="https://www.youtube.com/watch?v=<?= htmlspecialchars($mediaVideoId, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="link-external" title="Direct link to confirmed recording on YouTube">
                                                        YouTube ↗
                                                    </a>
                                                    <a href="https://music.youtube.com/watch?v=<?= htmlspecialchars($mediaVideoId, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="link-external" title="Direct link to confirmed recording on YouTube Music">
                                                        YT Music ↗
                                                    </a>
                                                </div>
                                                <div class="player-footer-right">
                                                    <button type="button" class="btn-xs btn-secondary btn-edit-media" title="Change recording version or select different link">
                                                        <span class="btn-icon">🔁</span> Change Recording
                                                    </button>
                                                    <button type="button" class="btn-xs btn-danger-subtle btn-mark-embed-blocked" title="Mark if YouTube player displays an embedding error">
                                                        Embedding Blocked?
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- Unconfirmed Selection Box: Prominently displayed right in the player spot -->
                                    <div class="player-unconfirmed-box">
                                        <div class="unconfirmed-header">
                                            <div class="unconfirmed-icon">🎧</div>
                                            <div class="unconfirmed-content">
                                                <h4 class="unconfirmed-title">Select Intended Recording</h4>
                                                <p class="unconfirmed-desc">Paste a YouTube or YouTube Music link below to preview and listen directly inside AFM#2.</p>
                                            </div>
                                        </div>
                                        <div class="matcher-grid">
                                            <input 
                                                type="text" 
                                                class="matcher-input input-video-url" 
                                                placeholder="Paste YouTube or YouTube Music link (e.g. youtube.com/watch?v=... or youtu.be/...)"
                                                value=""
                                                aria-label="YouTube recording link or video ID"
                                            >
                                            <select class="matcher-select select-version-type" aria-label="Recording version type">
                                                <option value="original" selected>💿 Original Studio</option>
                                                <option value="live">🎤 Live Performance</option>
                                                <option value="acoustic">🎸 Acoustic Version</option>
                                                <option value="cover">👥 Cover Recording</option>
                                                <option value="remaster">✨ Remastered</option>
                                                <option value="other">🏷️ Other</option>
                                            </select>
                                        </div>
                                        <input 
                                            type="text" 
                                            class="matcher-input input-version-notes" 
                                            placeholder="Optional version notes (e.g. 1975 Wembley Live, Album Cut)"
                                            value=""
                                            style="margin-bottom: 0.6rem;"
                                            aria-label="Optional version notes"
                                        >
                                        <div class="matcher-actions">
                                            <button type="button" class="btn-xs btn-secondary btn-preview-candidate" title="Test and listen to this recording before saving">
                                                <span class="btn-icon">▶</span> Preview Candidate
                                            </button>
                                            <button type="button" class="btn-xs btn-primary-subtle btn-save-match" title="Save this recording to song library">
                                                <span class="btn-icon">✓</span> Confirm &amp; Save Recording
                                            </button>
                                            <a href="recordings.php?q=<?= urlencode($item['title']) ?>" class="btn-xs btn-secondary" title="View discovered candidates in Recordings Manager">
                                                <span class="btn-icon">🎯</span> Find Candidates ↗
                                            </a>
                                        </div>
                                        <div class="candidate-preview-container" style="display: none; margin-top: 0.75rem;"></div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Matcher/Correction Form: Available when editing existing saved recording -->
                            <div class="media-matcher-box" style="display: none;">
                                <div class="matcher-header">
                                    <span class="matcher-title">🔁 Change Selected Recording</span>
                                    <div class="matcher-current-ref">
                                        Currently: <strong><?= htmlspecialchars($mediaVideoId) ?></strong> (<?= htmlspecialchars($versionLabel) ?>)
                                    </div>
                                </div>
                                <div class="matcher-grid">
                                    <input 
                                        type="text" 
                                        class="matcher-input input-video-url" 
                                        placeholder="Paste new YouTube or YouTube Music link (e.g. youtube.com/watch?v=... or youtu.be/...)"
                                        value="<?= htmlspecialchars($item['media_user_url'] ?: $mediaVideoId) ?>"
                                        aria-label="New YouTube recording link or video ID"
                                    >
                                    <select class="matcher-select select-version-type" aria-label="Recording version type">
                                        <option value="original" <?= $mediaVersion === 'original' ? 'selected' : '' ?>>💿 Original Studio</option>
                                        <option value="live" <?= $mediaVersion === 'live' ? 'selected' : '' ?>>🎤 Live Performance</option>
                                        <option value="acoustic" <?= $mediaVersion === 'acoustic' ? 'selected' : '' ?>>🎸 Acoustic Version</option>
                                        <option value="cover" <?= $mediaVersion === 'cover' ? 'selected' : '' ?>>👥 Cover Recording</option>
                                        <option value="remaster" <?= $mediaVersion === 'remaster' ? 'selected' : '' ?>>✨ Remastered</option>
                                        <option value="other" <?= $mediaVersion === 'other' ? 'selected' : '' ?>>🏷️ Other</option>
                                    </select>
                                </div>
                                <input 
                                    type="text" 
                                    class="matcher-input input-version-notes" 
                                    placeholder="Optional version notes (e.g. 1975 Wembley Live, Remastered 2011)"
                                    value="<?= htmlspecialchars($mediaNotes) ?>"
                                    style="margin-bottom: 0.6rem;"
                                    aria-label="Optional version notes"
                                >
                                <div class="matcher-actions">
                                    <button type="button" class="btn-xs btn-secondary btn-preview-candidate" title="Listen to candidate before saving">
                                        <span class="btn-icon">▶</span> Preview Candidate
                                    </button>
                                    <button type="button" class="btn-xs btn-primary-subtle btn-save-match" title="Save new recording selection">
                                        <span class="btn-icon">✓</span> Confirm & Save Recording
                                    </button>
                                    <button type="button" class="btn-xs btn-secondary btn-cancel-matcher">
                                        Cancel
                                    </button>
                                    <?php if ($hasMedia): ?>
                                        <button type="button" class="btn-xs btn-danger-subtle btn-delete-match" title="Remove recording reference">
                                            Remove Match
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="candidate-preview-container" style="display: none; margin-top: 0.75rem;"></div>
                            </div>
                        </div>

                        <!-- Right Column: Lyrics Box -->
                        <div class="drawer-col-lyrics">
                            <div class="lyrics-box" data-song-id="<?= $songId ?>">
                                <div class="lyrics-box-header">
                                    <div class="lyrics-attribution">
                                        <span class="attr-text">Lyrics via <a href="https://lrclib.net" target="_blank" rel="noopener noreferrer">LRCLIB</a></span>
                                    </div>
                                    <div class="lyrics-controls-group">
                                        <select class="lyrics-provider-select" title="Lyrics Provider" aria-label="Lyrics Provider">
                                            <option value="auto">Auto (Best Match)</option>
                                            <option value="lrclib">LRCLIB (Synced)</option>
                                            <option value="lyrics_ovh">Lyrics.ovh (Plain)</option>
                                            <option value="manual">My Manual Lyrics</option>
                                        </select>
                                        <div class="lyrics-tab-group" role="tablist" aria-label="Lyrics display modes">
                                            <div class="lyrics-tab-row lyrics-tab-row-primary">
                                                <button type="button" class="lyrics-tab active" data-view="plain" aria-label="Plain text lyrics" aria-pressed="true" role="tab" data-tooltip="Plain lyrics">Plain</button>
                                                <button type="button" class="lyrics-tab" data-view="synced" aria-label="Synchronized lyrics" aria-pressed="false" role="tab" data-tooltip="Synced lyrics">Synced</button>
                                                <button type="button" class="lyrics-tab" data-view="manual" title="Write or paste your own lyrics" aria-label="Manual personal lyrics editor" aria-pressed="false" role="tab" data-tooltip="Personal editor"><svg class="btn-svg-icon icon-edit" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> <span class="btn-text">Manual</span></button>
                                            </div>
                                            <div class="lyrics-tab-row lyrics-tab-row-secondary">
                                                <button type="button" class="lyrics-tab btn-lyrics-find-alt" title="Find another version" aria-label="Find alternative lyrics versions" data-tooltip="Alternate versions"><span class="btn-text">Alts</span> ▾</button>
                                                <button type="button" class="lyrics-tab btn-lyrics-toggle-override" title="Search with corrected song or artist name" aria-label="Edit song or artist name for lyrics search" data-tooltip="Edit search query"><svg class="btn-svg-icon icon-search" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> <span class="btn-text">Edit Search</span></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Search Override Form (Hidden by default) -->
                                <div class="lyrics-override-panel" style="display: none;">
                                    <div class="lyrics-override-inputs">
                                        <input type="text" class="input-xs lyrics-custom-title" placeholder="Corrected Title" value="<?= htmlspecialchars($songTitle) ?>">
                                        <input type="text" class="input-xs lyrics-custom-artist" placeholder="Corrected Artist" value="<?= htmlspecialchars($songArtist) ?>">
                                    </div>
                                    <div class="lyrics-override-actions">
                                        <button type="button" class="btn btn-xs btn-secondary btn-cancel-override">Cancel</button>
                                        <button type="button" class="btn btn-xs btn-primary btn-apply-lyrics-search">Search Again</button>
                                    </div>
                                </div>

                                <!-- Manual Lyrics Editor Panel (Hidden by default) -->
                                <div class="manual-lyrics-panel" style="display: none;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                                        <span class="text-xs text-muted">🔒 Personal private lyrics (only visible to your account)</span>
                                        <span class="manual-lyrics-status text-xs" style="color: #10b981; font-weight: 600;"></span>
                                    </div>
                                    <textarea class="manual-lyrics-textarea" placeholder="Paste or type personal lyrics notes here..."></textarea>
                                    <div style="display: flex; gap: 0.4rem; justify-content: flex-end; margin-top: 0.4rem;">
                                        <button type="button" class="btn btn-xs btn-danger-subtle btn-clear-manual-lyrics" title="Clear your saved manual lyrics">Clear</button>
                                        <button type="button" class="btn btn-xs btn-primary btn-save-manual-lyrics">Save My Lyrics</button>
                                    </div>
                                </div>

                                <div class="lyrics-body plain">
                                    <div class="lyrics-state-msg">
                                        <span class="lyrics-spinner"></span>
                                        <span>Click "Lyrics" above to load lyrics on demand</span>
                                    </div>
                                </div>
                            </div><!-- /.lyrics-box -->
                        </div><!-- /.drawer-col-lyrics -->
                    </div><!-- /.audition-drawer -->
                </div><!-- /.scorecard-media-panel -->

                <!-- 5 Criteria Scoring Matrix (Compact Horizontal Instrument) -->
                <div class="scorecard-grid scorecard-matrix <?= $isFinalized ? 'is-matrix-finalized' : '' ?>">
                    <!-- 1. Melody -->
                    <div class="criterion-field" data-criterion="melody">
                        <div class="criterion-header">
                            <label for="mel-<?= $entryId ?>"><span class="criterion-icon">🎵</span> <span class="criterion-name">Melody</span></label>
                            <div class="criterion-stepper">
                                <?php if (!$isFinalized): ?>
                                    <button type="button" class="btn-step-btn btn-step-down" aria-label="Decrease Melody score" title="Decrease score">&minus;</button>
                                <?php endif; ?>
                                <input 
                                    type="number" 
                                    id="mel-<?= $entryId ?>" 
                                    class="criterion-input <?= $isFinalized ? 'is-readonly' : '' ?>" 
                                    data-criterion="melody"
                                    min="0" 
                                    max="10" 
                                    step="1" 
                                    <?= $isFinalized ? 'readonly disabled aria-disabled="true"' : 'placeholder="0-10"' ?>
                                    value="<?= $item['melody'] !== null ? (int)$item['melody'] : '' ?>"
                                    aria-label="Melody score out of 10<?= $isFinalized ? ' (Finalized)' : '' ?>"
                                >
                                <span class="criterion-max">/10</span>
                                <?php if (!$isFinalized): ?>
                                    <button type="button" class="btn-step-btn btn-step-up" aria-label="Increase Melody score" title="Increase score">&plus;</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$isFinalized): ?>
                            <input 
                                type="range" 
                                class="criterion-range" 
                                min="0" 
                                max="10" 
                                step="1" 
                                value="<?= $item['melody'] !== null ? (int)$item['melody'] : 5 ?>"
                                aria-label="Melody slider"
                            >
                        <?php endif; ?>
                    </div>

                    <!-- 2. Lyrics -->
                    <div class="criterion-field" data-criterion="lyrics">
                        <div class="criterion-header">
                            <label for="lyr-<?= $entryId ?>"><span class="criterion-icon">✍️</span> <span class="criterion-name">Lyrics</span></label>
                            <div class="criterion-stepper">
                                <?php if (!$isFinalized): ?>
                                    <button type="button" class="btn-step-btn btn-step-down" aria-label="Decrease Lyrics score" title="Decrease score">&minus;</button>
                                <?php endif; ?>
                                <input 
                                    type="number" 
                                    id="lyr-<?= $entryId ?>" 
                                    class="criterion-input <?= $isFinalized ? 'is-readonly' : '' ?>" 
                                    data-criterion="lyrics"
                                    min="0" 
                                    max="10" 
                                    step="1" 
                                    <?= $isFinalized ? 'readonly disabled aria-disabled="true"' : 'placeholder="0-10"' ?>
                                    value="<?= $item['lyrics'] !== null ? (int)$item['lyrics'] : '' ?>"
                                    aria-label="Lyrics score out of 10<?= $isFinalized ? ' (Finalized)' : '' ?>"
                                >
                                <span class="criterion-max">/10</span>
                                <?php if (!$isFinalized): ?>
                                    <button type="button" class="btn-step-btn btn-step-up" aria-label="Increase Lyrics score" title="Increase score">&plus;</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$isFinalized): ?>
                            <input 
                                type="range" 
                                class="criterion-range" 
                                min="0" 
                                max="10" 
                                step="1" 
                                value="<?= $item['lyrics'] !== null ? (int)$item['lyrics'] : 5 ?>"
                                aria-label="Lyrics slider"
                            >
                        <?php endif; ?>
                    </div>

                    <!-- 3. Vocals -->
                    <div class="criterion-field" data-criterion="vocals">
                        <div class="criterion-header">
                            <label for="voc-<?= $entryId ?>"><span class="criterion-icon">🎤</span> <span class="criterion-name">Vocals</span></label>
                            <div class="criterion-stepper">
                                <?php if (!$isFinalized): ?>
                                    <button type="button" class="btn-step-btn btn-step-down" aria-label="Decrease Vocals score" title="Decrease score">&minus;</button>
                                <?php endif; ?>
                                <input 
                                    type="number" 
                                    id="voc-<?= $entryId ?>" 
                                    class="criterion-input <?= $isFinalized ? 'is-readonly' : '' ?>" 
                                    data-criterion="vocals"
                                    min="0" 
                                    max="10" 
                                    step="1" 
                                    <?= $isFinalized ? 'readonly disabled aria-disabled="true"' : 'placeholder="0-10"' ?>
                                    value="<?= $item['vocals'] !== null ? (int)$item['vocals'] : '' ?>"
                                    aria-label="Vocals score out of 10<?= $isFinalized ? ' (Finalized)' : '' ?>"
                                >
                                <span class="criterion-max">/10</span>
                                <?php if (!$isFinalized): ?>
                                    <button type="button" class="btn-step-btn btn-step-up" aria-label="Increase Vocals score" title="Increase score">&plus;</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$isFinalized): ?>
                            <input 
                                type="range" 
                                class="criterion-range" 
                                min="0" 
                                max="10" 
                                step="1" 
                                value="<?= $item['vocals'] !== null ? (int)$item['vocals'] : 5 ?>"
                                aria-label="Vocals slider"
                            >
                        <?php endif; ?>
                    </div>

                    <!-- 4. Emotion -->
                    <div class="criterion-field" data-criterion="emotion">
                        <div class="criterion-header">
                            <label for="emo-<?= $entryId ?>"><span class="criterion-icon">❤️</span> <span class="criterion-name">Emotion</span></label>
                            <div class="criterion-stepper">
                                <?php if (!$isFinalized): ?>
                                    <button type="button" class="btn-step-btn btn-step-down" aria-label="Decrease Emotion score" title="Decrease score">&minus;</button>
                                <?php endif; ?>
                                <input 
                                    type="number" 
                                    id="emo-<?= $entryId ?>" 
                                    class="criterion-input <?= $isFinalized ? 'is-readonly' : '' ?>" 
                                    data-criterion="emotion"
                                    min="0" 
                                    max="10" 
                                    step="1" 
                                    <?= $isFinalized ? 'readonly disabled aria-disabled="true"' : 'placeholder="0-10"' ?>
                                    value="<?= $item['emotion'] !== null ? (int)$item['emotion'] : '' ?>"
                                    aria-label="Emotion score out of 10<?= $isFinalized ? ' (Finalized)' : '' ?>"
                                >
                                <span class="criterion-max">/10</span>
                                <?php if (!$isFinalized): ?>
                                    <button type="button" class="btn-step-btn btn-step-up" aria-label="Increase Emotion score" title="Increase score">&plus;</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$isFinalized): ?>
                            <input 
                                type="range" 
                                class="criterion-range" 
                                min="0" 
                                max="10" 
                                step="1" 
                                value="<?= $item['emotion'] !== null ? (int)$item['emotion'] : 5 ?>"
                                aria-label="Emotion slider"
                            >
                        <?php endif; ?>
                    </div>

                    <!-- 5. Replayability -->
                    <div class="criterion-field" data-criterion="replayability">
                        <div class="criterion-header">
                            <label for="rep-<?= $entryId ?>"><span class="criterion-icon">🔁</span> <span class="criterion-name">Replay</span></label>
                            <div class="criterion-stepper">
                                <?php if (!$isFinalized): ?>
                                    <button type="button" class="btn-step-btn btn-step-down" aria-label="Decrease Replay score" title="Decrease score">&minus;</button>
                                <?php endif; ?>
                                <input 
                                    type="number" 
                                    id="rep-<?= $entryId ?>" 
                                    class="criterion-input <?= $isFinalized ? 'is-readonly' : '' ?>" 
                                    data-criterion="replayability"
                                    min="0" 
                                    max="10" 
                                    step="1" 
                                    <?= $isFinalized ? 'readonly disabled aria-disabled="true"' : 'placeholder="0-10"' ?>
                                    value="<?= $item['replayability'] !== null ? (int)$item['replayability'] : '' ?>"
                                    aria-label="Replayability score out of 10<?= $isFinalized ? ' (Finalized)' : '' ?>"
                                >
                                <span class="criterion-max">/10</span>
                                <?php if (!$isFinalized): ?>
                                    <button type="button" class="btn-step-btn btn-step-up" aria-label="Increase Replay score" title="Increase score">&plus;</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$isFinalized): ?>
                            <input 
                                type="range" 
                                class="criterion-range" 
                                min="0" 
                                max="10" 
                                step="1" 
                                value="<?= $item['replayability'] !== null ? (int)$item['replayability'] : 5 ?>"
                                aria-label="Replayability slider"
                            >
                        <?php endif; ?>
                    </div>

                    <!-- 6. Aggregate Total Instrument (Adjacent to Criteria) -->
                    <div class="criterion-field criterion-aggregate-box <?= ($isComplete || $isFinalized) ? 'complete' : ($isIncomplete ? 'incomplete' : '') ?> <?= $isFinalized ? 'is-finalized' : '' ?>" title="Aggregate score of all 5 criteria">
                        <div class="criterion-header">
                            <label><span class="criterion-icon" <?= $isFinalized ? 'title="Finalized scorecard" aria-label="Finalized scorecard"' : '' ?>><?= $isFinalized ? '🔒' : '⚖️' ?></span> <span class="criterion-name"><?= $isFinalized ? 'Finalized' : 'Total Score' ?></span></label>
                        </div>
                        <div class="aggregate-score-display font-mono font-bold <?= $isFinalized ? 'text-finalized' : '' ?>">
                            <span class="aggregate-score-val"><?= ($isFinalized || $isComplete) ? ((int)$totalScore . ' / 50') : ($isIncomplete ? ($partialSum . '* / 50') : '— / 50') ?></span>
                        </div>
                        <div class="aggregate-score-status text-xs <?= $isFinalized ? 'text-finalized font-bold' : ($isComplete ? 'text-success font-bold' : ($isIncomplete ? 'text-gold' : 'text-muted')) ?>">
                            <?= $isFinalized ? '<span class="status-lock-icon" aria-hidden="true">🔒 </span>Score Locked' : ($isComplete ? '✓ Complete' : ($isIncomplete ? '⏳ Incomplete' : '○ Unscored')) ?>
                        </div>
                    </div>
                </div>

                <!-- Footer / Save Actions -->
                <div class="scorecard-footer">
                    <div class="save-feedback">
                        <?php if ($isFinalized): ?>
                            <span class="text-finalized font-bold">
                                <span class="lock-icon" aria-hidden="true">🔒</span> Finalized (<?= (int)$totalScore ?>/50) &bull; Recorded in Tournament History<?= !empty($finalizedResult) ? ' (' . ucfirst($finalizedResult) . ')' : '' ?>
                            </span>
                        <?php elseif ($isComplete): ?>
                            <span class="text-success">✓ Complete (<?= (int)$totalScore ?>/50)</span>
                        <?php elseif ($isIncomplete): ?>
                            <span class="text-gold">⏳ Incomplete Scorecard (Resume anytime)</span>
                        <?php else: ?>
                            <span class="text-muted">Fill all 5 fields for total score</span>
                        <?php endif; ?>
                    </div>
                    <div class="scorecard-actions">
                        <?php if ($isFinalized): ?>
                            <span class="badge badge-finalized-action" title="This scorecard is finalized and immutable">
                                <span class="lock-icon" aria-hidden="true">🔒</span> Scorecard Locked
                            </span>
                        <?php else: ?>
                            <button type="button" class="btn btn-primary btn-sm btn-save-scorecard" aria-label="Save scorecard" data-tooltip="Save scorecard">
                                <svg class="btn-svg-icon icon-save" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> <span class="btn-text">Save Scorecard</span>
                            </button>
                            <?php if ((int)$selectedRoundId === 1): ?>
                                <button type="button" 
                                        class="btn btn-secondary-subtle btn-sm btn-remove-shortlist" 
                                        data-song-id="<?= $songId ?>" 
                                        data-entry-id="<?= $entryId ?>"
                                        title="Remove <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?> from shortlist"
                                        aria-label="Remove from shortlist"
                                        data-tooltip="Remove from shortlist">
                                    <svg class="btn-svg-icon icon-cross" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> <span class="btn-text">Remove Shortlist</span>
                                </button>
                            <?php endif; ?>
                            <button type="button" 
                                    class="btn btn-outline-danger btn-sm btn-reset-scorecard" 
                                    data-entry-id="<?= $entryId ?>" 
                                    data-round-id="<?= (int)$selectedRoundId ?>" 
                                    title="Reset <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?> scorecard to unscored state"
                                    aria-label="Reset score"
                                    data-tooltip="Reset score"
                                    style="<?= ($isComplete || $isIncomplete) ? '' : 'display: none;' ?>">
                                <svg class="btn-svg-icon icon-reset" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg> <span class="btn-text">Reset Score</span>
                            </button>
                        <?php endif; ?>
                    </div><!-- /.scorecard-actions -->
                </div><!-- /.scorecard-footer -->
            </div><!-- /.scorecard-card -->
        </div><!-- /.scorecard-wrapper -->
        <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?= render_accessible_pagination(
            $page, 
            $totalPages, 
            $totalCount, 
            $perPage, 
            'auditions.php', 
            [
                'round_id' => $selectedRoundId,
                'filter'   => $filter,
                'q'        => $searchQuery
            ], 
            'audition entries',
            10
        ); ?>

    <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__) . '/includes/footer.php';
