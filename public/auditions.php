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

$pageTitle = 'Formal Auditions — AFM#2 2026';

// Get all rounds to allow round selection (defaults to active round)
$allRounds = get_tournament_rounds();
$activeRound = get_active_round();

$selectedRoundId = isset($_GET['round_id']) ? (int)$_GET['round_id'] : (int)($activeRound['round_id'] ?? 1);
$currentRound = get_round_by_id($selectedRoundId) ?? $activeRound;

$filter = $_GET['filter'] ?? 'all';
$searchQuery = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

// Records per page: 25 (default), 50, 75, 100 (at most 100 as requested)
$rawPerPage = (int)($_GET['per_page'] ?? 25);
$perPage = in_array($rawPerPage, [25, 50, 75, 100], true) ? $rawPerPage : max(10, min(100, $rawPerPage > 0 ? $rawPerPage : 25));

// Handle direct song audition navigation (?song_id=...)
$targetSongId = isset($_GET['song_id']) ? (int)$_GET['song_id'] : 0;
if ($targetSongId > 0) {
    $targetSong = get_song_by_id($targetSongId);
    if ($targetSong) {
        // Ensure song is shortlisted and enrolled in auditions
        $pdo = get_db_connection();
        $shortlistStmt = $pdo->prepare("
            INSERT INTO `song_discovery` (`song_id`, `status`, `reviewed_at`)
            VALUES (:id, 'shortlisted', NOW())
            ON DUPLICATE KEY UPDATE `status` = 'shortlisted', `reviewed_at` = NOW()
        ");
        $shortlistStmt->execute([':id' => $targetSongId]);

        $ensureEntry = $pdo->prepare("
            INSERT INTO `afm_entries` (`song_id`, `status`)
            VALUES (:id, 'auditioning')
            ON DUPLICATE KEY UPDATE `status` = CASE WHEN `status` = 'excluded' THEN 'auditioning' ELSE `status` END
        ");
        $ensureEntry->execute([':id' => $targetSongId]);

        if (empty($searchQuery)) {
            $searchQuery = $targetSong['title'];
            $filter = 'all';
        }
    }
}

// Origin for YouTube postMessage API handshake
$appOrigin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// Fetch eligible entries for this round
$result = get_audition_entries($selectedRoundId, $filter, $searchQuery, $page, $perPage);
$totalCount = $result['total'];
$totalPages = $result['total_pages'];
$counts = $result['counts'];

// Snap if page exceeds totalPages due to per_page change or filter
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
    $result = get_audition_entries($selectedRoundId, $filter, $searchQuery, $page, $perPage);
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
                $totalScore = $item['total'];

                $cardClass = '';
                if ($isComplete) {
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

                $versionIcons = [
                    'original' => '💿 Studio Original',
                    'live'     => '🎤 Live Performance',
                    'acoustic' => '🎸 Acoustic Version',
                    'cover'    => '👥 Cover Recording',
                    'remaster' => '✨ Remastered',
                    'other'    => '🏷️ Other'
                ];
                $versionLabel = $versionIcons[$mediaVersion] ?? '💿 Recording';
            ?>
            <div class="scorecard-wrapper mb-5">
                <!-- Poetic Overhead Quote -->
                <div class="poetic-banner">
                    <span class="script-quote">Let's get lost somewhere.</span>
                    <span class="heart-dot">♥</span>
                </div>

                <div 
                    class="scorecard-card tactile-player-card <?= $cardClass ?>" 
                    id="entry-<?= $entryId ?>"
                    data-entry-id="<?= $entryId ?>"
                    data-song-id="<?= $songId ?>"
                    data-round-id="<?= (int)$selectedRoundId ?>"
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
                                </div>
                                <div class="scorecard-total-box">
                                    <div class="total-score-badge <?= $isComplete ? 'complete' : '' ?>">
                                        <?php if ($isComplete): ?>
                                            <?= (int)$totalScore ?> / 50
                                        <?php elseif ($isIncomplete): ?>
                                            <?= (int)$totalScore ?>* / 50
                                        <?php else: ?>
                                            — / 50
                                        <?php endif; ?>
                                    </div>
                                    <div class="total-score-label">
                                        <?= $isComplete ? 'Complete' : ($isIncomplete ? 'Incomplete' : 'Unscored') ?>
                                    </div>
                                    <div class="total-score-meter" title="Total score progress">
                                        <div class="total-score-fill" style="width: <?= $totalScore !== null ? min(100, round(($totalScore / 50) * 100)) : 0 ?>%;"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="tactile-song-info">
                                <h2 class="tactile-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                                <div class="tactile-artist"><?= htmlspecialchars($item['artist'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="tactile-details">Tournament Stage: <?= htmlspecialchars($currentRound['round_name'], ENT_QUOTES, 'UTF-8') ?> &bull; Catalogue Track #<?= (int)$songId ?></div>
                            </div>

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
                                    <span class="media-badge media-badge-confirmed" title="<?= htmlspecialchars($mediaNotes ? "Notes: {$mediaNotes}" : $versionLabel) ?>">
                                        <?= htmlspecialchars($versionLabel) ?>
                                    </span>
                                    <button type="button" class="btn-xs btn-primary-subtle btn-toggle-player active" title="Play or hide embedded player">
                                        <span class="btn-icon">▶</span> <span class="player-toggle-label">Hide Player</span>
                                    </button>
                                    <button type="button" class="btn-xs btn-secondary btn-edit-media" title="Change recording version or video ID">
                                        <span class="btn-icon">🔁</span> Change Recording
                                    </button>
                                <?php else: ?>
                                    <span class="media-badge media-badge-unmatched">○ No recording selected</span>
                                    <button type="button" class="btn-xs btn-primary-subtle btn-match-media" title="Select and confirm recording">
                                        <span class="btn-icon">🎧</span> Select Recording
                                    </button>
                                <?php endif; ?>
                            </div>

                            <!-- Direct Recording Links (Secondary Reference) -->
                            <?php if ($hasMedia): ?>
                                <a href="https://www.youtube.com/watch?v=<?= htmlspecialchars($mediaVideoId, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="link-external" title="Direct link to confirmed recording on YouTube">
                                    YouTube ↗
                                </a>
                                <a href="https://music.youtube.com/watch?v=<?= htmlspecialchars($mediaVideoId, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="link-external" title="Direct link to confirmed recording on YouTube Music">
                                    YT Music ↗
                                </a>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($ytSearch) ?>" target="_blank" rel="noopener noreferrer" class="link-external text-muted" title="Search YouTube to locate an official recording link">
                                    Find Link ↗
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="media-toolbar-right">
                            <button type="button" class="btn-xs btn-toggle-lyrics active" title="View lyrics from LRCLIB">
                                <span class="btn-icon">📜</span> <span class="lyrics-toggle-label">Lyrics</span>
                            </button>
                        </div>
                    </div>

                    <!-- Integrated Player & Lyrics Experience Container (Always Mounted & Visible) -->
                    <div class="audition-drawer audio-active lyrics-active" style="display: grid;">
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
                                            <!-- Embedded official YouTube IFrame with strict origin & enablejsapi=1 -->
                                            <iframe 
                                                class="yt-embed-frame" 
                                                id="yt-player-<?= (int)$songId ?>" 
                                                data-video-id="<?= htmlspecialchars($mediaVideoId, ENT_QUOTES, 'UTF-8') ?>"
                                                data-src="https://www.youtube-nocookie.com/embed/<?= htmlspecialchars($mediaVideoId, ENT_QUOTES, 'UTF-8') ?>?enablejsapi=1&origin=<?= urlencode($appOrigin) ?>&rel=0&autoplay=0" 
                                                src="https://www.youtube-nocookie.com/embed/<?= htmlspecialchars($mediaVideoId, ENT_QUOTES, 'UTF-8') ?>?enablejsapi=1&origin=<?= urlencode($appOrigin) ?>&rel=0&autoplay=0" 
                                                referrerpolicy="strict-origin-when-cross-origin"
                                                loading="lazy" 
                                                title="AFM#2 Audio Player: <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" 
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
                            <div class="lyrics-box">
                                <div class="lyrics-box-header">
                                    <div class="lyrics-attribution">
                                        <span class="attr-text">Lyrics via <a href="https://lrclib.net" target="_blank" rel="noopener noreferrer">LRCLIB</a></span>
                                    </div>
                                    <div class="lyrics-tab-group">
                                        <button type="button" class="lyrics-tab active" data-view="plain">Plain</button>
                                        <button type="button" class="lyrics-tab" data-view="synced">Synced (Timed)</button>
                                        <button type="button" class="lyrics-tab btn-lyrics-find-alt" title="Find another version">Alternatives ▾</button>
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
            </div><!-- /.tactile-meta-col -->
        </div><!-- /.tactile-hero-row -->

                <!-- 5 Criteria Inputs Grid with Range Sliders & Presets -->
                <div class="scorecard-grid">
                    <!-- 1. Melody -->
                    <div class="criterion-field">
                        <div class="criterion-header">
                            <label for="mel-<?= $entryId ?>"><span class="criterion-icon">🎵</span> Melody</label>
                            <input 
                                type="number" 
                                id="mel-<?= $entryId ?>" 
                                class="criterion-input" 
                                data-criterion="melody"
                                min="0" 
                                max="10" 
                                step="1" 
                                placeholder="0-10" 
                                value="<?= $item['melody'] !== null ? (int)$item['melody'] : '' ?>"
                            >
                        </div>
                        <input 
                            type="range" 
                            class="criterion-range" 
                            min="0" 
                            max="10" 
                            step="1" 
                            value="<?= $item['melody'] !== null ? (int)$item['melody'] : 5 ?>"
                            aria-label="Melody slider"
                        >
                    </div>

                    <!-- 2. Lyrics -->
                    <div class="criterion-field">
                        <div class="criterion-header">
                            <label for="lyr-<?= $entryId ?>"><span class="criterion-icon">✍️</span> Lyrics</label>
                            <input 
                                type="number" 
                                id="lyr-<?= $entryId ?>" 
                                class="criterion-input" 
                                data-criterion="lyrics"
                                min="0" 
                                max="10" 
                                step="1" 
                                placeholder="0-10" 
                                value="<?= $item['lyrics'] !== null ? (int)$item['lyrics'] : '' ?>"
                            >
                        </div>
                        <input 
                            type="range" 
                            class="criterion-range" 
                            min="0" 
                            max="10" 
                            step="1" 
                            value="<?= $item['lyrics'] !== null ? (int)$item['lyrics'] : 5 ?>"
                            aria-label="Lyrics slider"
                        >
                    </div>

                    <!-- 3. Vocals -->
                    <div class="criterion-field">
                        <div class="criterion-header">
                            <label for="voc-<?= $entryId ?>"><span class="criterion-icon">🎤</span> Vocals</label>
                            <input 
                                type="number" 
                                id="voc-<?= $entryId ?>" 
                                class="criterion-input" 
                                data-criterion="vocals"
                                min="0" 
                                max="10" 
                                step="1" 
                                placeholder="0-10" 
                                value="<?= $item['vocals'] !== null ? (int)$item['vocals'] : '' ?>"
                            >
                        </div>
                        <input 
                            type="range" 
                            class="criterion-range" 
                            min="0" 
                            max="10" 
                            step="1" 
                            value="<?= $item['vocals'] !== null ? (int)$item['vocals'] : 5 ?>"
                            aria-label="Vocals slider"
                        >
                    </div>

                    <!-- 4. Emotion -->
                    <div class="criterion-field">
                        <div class="criterion-header">
                            <label for="emo-<?= $entryId ?>"><span class="criterion-icon">❤️</span> Emotion</label>
                            <input 
                                type="number" 
                                id="emo-<?= $entryId ?>" 
                                class="criterion-input" 
                                data-criterion="emotion"
                                min="0" 
                                max="10" 
                                step="1" 
                                placeholder="0-10" 
                                value="<?= $item['emotion'] !== null ? (int)$item['emotion'] : '' ?>"
                            >
                        </div>
                        <input 
                            type="range" 
                            class="criterion-range" 
                            min="0" 
                            max="10" 
                            step="1" 
                            value="<?= $item['emotion'] !== null ? (int)$item['emotion'] : 5 ?>"
                            aria-label="Emotion slider"
                        >
                    </div>

                    <!-- 5. Replayability -->
                    <div class="criterion-field">
                        <div class="criterion-header">
                            <label for="rep-<?= $entryId ?>"><span class="criterion-icon">🔁</span> Replay</label>
                            <input 
                                type="number" 
                                id="rep-<?= $entryId ?>" 
                                class="criterion-input" 
                                data-criterion="replayability"
                                min="0" 
                                max="10" 
                                step="1" 
                                placeholder="0-10" 
                                value="<?= $item['replayability'] !== null ? (int)$item['replayability'] : '' ?>"
                            >
                        </div>
                        <input 
                            type="range" 
                            class="criterion-range" 
                            min="0" 
                            max="10" 
                            step="1" 
                            value="<?= $item['replayability'] !== null ? (int)$item['replayability'] : 5 ?>"
                            aria-label="Replayability slider"
                        >
                    </div>
                </div>

                <!-- Footer / Save Actions -->
                <div class="scorecard-footer">
                    <div class="save-feedback">
                        <?php if ($isComplete): ?>
                            <span class="text-success">✓ Complete (<?= (int)$totalScore ?>/50)</span>
                        <?php elseif ($isIncomplete): ?>
                            <span class="text-gold">⏳ Incomplete Scorecard (Resume anytime)</span>
                        <?php else: ?>
                            <span class="text-muted">Fill all 5 fields for total score</span>
                        <?php endif; ?>
                    </div>
                    <div class="scorecard-actions">
                        <button type="button" class="btn btn-primary btn-sm btn-save-scorecard">
                            <span class="btn-icon">💾</span> Save Scorecard
                        </button>
                    </div><!-- /.scorecard-actions -->
                </div><!-- /.scorecard-footer -->
            </div><!-- /.scorecard-card -->

            <!-- Poetic Under-Card Epigraph -->
            <div class="poetic-footer">
                <div class="poetic-song-caps"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="poetic-quote">some songs take you away to a place you love.</div>
                <div class="botanical-sprig" aria-hidden="true">🌿</div>
            </div>
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
