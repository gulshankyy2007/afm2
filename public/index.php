<?php
/**
 * AFM#2 - Personal Music Journal & Home Space
 * Inspired by Minimal Music aesthetic: cinematic landscape photography, warm sepia/amber tones,
 * elegant serif typography, generous spacing, and tactile player presentation.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/artwork_helper.php';

// If visitor is unauthenticated, serve the public landing page!
if (!is_authenticated()) {
    require __DIR__ . '/landing.php';
    exit;
}

$stats = get_dashboard_stats();
$pageTitle = 'Journal — AFM#2 Annual Musical Finale 2026';

$discoveryPercent = $stats['total_songs'] > 0 
    ? min(100, round(($stats['reviewed_count'] / $stats['total_songs']) * 100, 1)) 
    : 0;

$auditionPercent = $stats['shortlisted_count'] > 0 
    ? min(100, round(($stats['auditions_completed'] / $stats['shortlisted_count']) * 100, 1)) 
    : 0;

$recentShortlisted = $stats['recent_shortlisted'] ?? [];
$featuredSong = !empty($recentShortlisted) ? $recentShortlisted[0] : null;

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-container home-journal-container">
    <!-- Tasteful Journal Welcome Hero -->
    <section class="journal-hero-section">
        <div class="journal-hero-inner">
            <div class="journal-overhead">
                <span class="journal-tag">ANNUAL MUSICAL FINALE 2026</span>
                <span class="journal-dot">&bull;</span>
                <span class="journal-stage-name">Stage: <?= htmlspecialchars($stats['active_round']['round_name'] ?? 'Formal Auditions', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            
            <h1 class="journal-hero-title">
                Let's get lost in the sound.
            </h1>
            
            <p class="journal-hero-quote">
                A personal listening sanctuary and rigorous five-criterion tournament across your <?= number_format($stats['total_songs']) ?>-track catalogue.
            </p>

            <div class="journal-hero-nav">
                <a href="auditions.php" class="btn btn-primary btn-glow" id="btnResumeAuditions">
                    <span class="btn-icon">🎧</span> Resume Auditions (<?= number_format($stats['shortlisted_count']) ?>)
                </a>
                <a href="library.php?filter=unreviewed" class="btn btn-secondary" id="btnResumeDiscovery">
                    <span class="btn-icon">⚡</span> Discover Catalogue
                </a>
                <a href="tournament.php" class="btn btn-secondary">
                    <span class="btn-icon">🏆</span> Tournament Stage
                </a>
                <a href="discovery.php" class="btn btn-secondary">
                    <span class="btn-icon">★</span> Contender Shortlist
                </a>
            </div>
        </div>
    </section>

    <!-- Featured Audition Contender / Resume Listening Card -->
    <?php if ($featuredSong): 
        $featThumb = $featuredSong['artwork_thumbnail_url'] ?? $featuredSong['artwork_image_url'] ?? null;
        if (empty($featThumb)) {
            $preset = get_default_atmospheric_preset($featuredSong['title'], $featuredSong['artist'] ?? '');
            $featThumb = $preset['thumbnail_url'];
        }
        $featHasMedia = !empty($featuredSong['media_video_id']);
        $featYtSearch = get_youtube_search_url($featuredSong['title'], $featuredSong['artist']);
    ?>
    <section class="featured-listening-section mb-5">
        <div class="featured-card-wrapper">
            <div class="poetic-banner">
                <span class="script-quote">Currently on the turntable</span>
                <span class="heart-dot">♥</span>
            </div>

            <div class="tactile-player-card featured-hero-card">
                <div class="tactile-hero-row">
                    <!-- Left: Prominent Album Artwork Frame -->
                    <div class="tactile-artwork-col">
                        <div class="tactile-artwork-frame">
                            <img src="<?= htmlspecialchars($featThumb, ENT_QUOTES, 'UTF-8') ?>" 
                                 alt="<?= htmlspecialchars($featuredSong['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                 class="tactile-artwork-img"
                                 loading="lazy"
                                 onerror="this.onerror=null; this.src='assets/img/artwork-fallback.svg';">
                            <div class="artwork-overlay-script"><?= htmlspecialchars($featuredSong['title'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>

                    <!-- Right: Metadata & Direct Action -->
                    <div class="tactile-meta-col">
                        <div class="tactile-info-group">
                            <div class="tactile-header-bar">
                                <?php if (isset($featuredSong['total']) && $featuredSong['total'] !== null): ?>
                                    <span class="total-score-badge complete">
                                        <?= (int)$featuredSong['total'] ?> / 50
                                    </span>
                                <?php else: ?>
                                    <span class="total-score-badge">
                                        Awaiting Score
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="tactile-song-info">
                                <h2 class="tactile-title"><?= htmlspecialchars($featuredSong['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                                <div class="tactile-artist"><?= htmlspecialchars($featuredSong['artist'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="tactile-details">Catalogue Track #<?= (int)$featuredSong['song_id'] ?> &bull; Shortlisted for Auditions</div>
                            </div>
                        </div>

                        <div class="featured-actions-row">
                            <a href="auditions.php?q=<?= urlencode($featuredSong['title']) ?>" class="btn btn-primary btn-sm btn-featured-scoreboard" aria-label="Open Audition Scorecard for <?= htmlspecialchars($featuredSong['title'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="btn-icon">🎧</span>
                                <span class="btn-text-desktop">Open Audition Scorecard</span>
                                <span class="btn-text-mobile">Scoreboard</span>
                            </a>
                            <button type="button" 
                                    class="btn btn-secondary btn-sm btn-quick-lyrics btn-lyrics-quick-view" 
                                    data-song-id="<?= (int)$featuredSong['song_id'] ?>" 
                                    data-song-title="<?= htmlspecialchars($featuredSong['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                    data-song-artist="<?= htmlspecialchars($featuredSong['artist'], ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="Read Lyrics for <?= htmlspecialchars($featuredSong['title'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="btn-icon">📜</span> Read Lyrics
                            </button>
                            <a href="<?= htmlspecialchars($featYtSearch, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="link-external btn-featured-yt" aria-label="Search and play on YouTube">
                                YouTube ↗
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="poetic-footer">
                <div class="poetic-song-caps"><?= htmlspecialchars($featuredSong['title'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="poetic-quote">some songs take you away to a place you love.</div>
                <div class="botanical-sprig" aria-hidden="true">🌿</div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Compact Tournament Milestones Tracker -->
    <section class="journal-section mb-5">
        <div class="journal-section-header">
            <div>
                <h2 class="journal-section-title">Tournament Milestones</h2>
                <p class="journal-section-subtitle">Seven-stage progression from initial library discovery to the 2026 Finale champion</p>
            </div>
            <a href="tournament.php" class="journal-section-link">View Full Board &rarr;</a>
        </div>

        <div class="stages-timeline milestones-timeline">
            <?php foreach ($stats['rounds'] as $r): 
                $nodeClass = '';
                if ($r['status'] === 'completed') {
                    $nodeClass = 'completed';
                } elseif ($r['status'] === 'in_progress') {
                    $nodeClass = 'active';
                }
            ?>
                <a href="tournament.php?round_id=<?= (int)$r['round_id'] ?>" class="stage-node <?= $nodeClass ?>" title="View <?= htmlspecialchars($r['round_name'], ENT_QUOTES, 'UTF-8') ?> Leaderboard">
                    <div class="node-icon">
                        <?php if ($r['status'] === 'completed'): ?>
                            ✓
                        <?php else: ?>
                            <?= (int)$r['sequence_no'] ?>
                        <?php endif; ?>
                    </div>
                    <div class="node-name"><?= htmlspecialchars($r['round_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="node-target">
                        <?php if ($r['target_count'] > 0): ?>
                            Top <?= (int)$r['target_count'] ?>
                        <?php else: ?>
                            All
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Recent Shortlist Shelf -->
    <?php if (!empty($recentShortlisted)): ?>
    <section class="journal-section mb-5">
        <div class="journal-section-header">
            <div>
                <h2 class="journal-section-title">Shortlisted Contenders</h2>
                <p class="journal-section-subtitle">Curated tracks selected for multi-criteria audition evaluation</p>
            </div>
            <a href="discovery.php" class="journal-section-link">View All Shortlisted (<?= number_format($stats['shortlisted_count']) ?>) &rarr;</a>
        </div>

        <div class="shortlist-shelf-grid">
            <?php foreach (array_slice($recentShortlisted, 0, 6) as $item): 
                $itemThumb = $item['artwork_thumbnail_url'] ?? $item['artwork_image_url'] ?? null;
                if (empty($itemThumb)) {
                    $itemPreset = get_default_atmospheric_preset($item['title'], $item['artist'] ?? '');
                    $itemThumb = $itemPreset['thumbnail_url'];
                }
            ?>
            <div class="shelf-card">
                <div class="shelf-thumb-wrap">
                    <img src="<?= htmlspecialchars($itemThumb, ENT_QUOTES, 'UTF-8') ?>" 
                         alt="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>" 
                         class="shelf-thumb" 
                         loading="lazy"
                         onerror="this.onerror=null; this.src='assets/img/artwork-fallback.svg';">
                    <?php if (isset($item['total']) && $item['total'] !== null): ?>
                        <span class="shelf-badge badge-score"><?= (int)$item['total'] ?>/50</span>
                    <?php else: ?>
                        <span class="shelf-badge badge-pending">Audition</span>
                    <?php endif; ?>
                </div>
                <div class="shelf-info">
                    <h3 class="shelf-title" title="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                    <div class="shelf-artist"><?= htmlspecialchars($item['artist'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="shelf-actions">
                        <a href="auditions.php?q=<?= urlencode($item['title']) ?>" class="btn-shelf-action" title="Open Audition Scorecard">
                            Scorecard &rarr;
                        </a>
                        <button type="button" 
                                class="btn-shelf-lyrics btn-quick-lyrics btn-lyrics-quick-view" 
                                data-song-id="<?= (int)$item['song_id'] ?>" 
                                data-song-title="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                data-song-artist="<?= htmlspecialchars($item['artist'], ENT_QUOTES, 'UTF-8') ?>"
                                title="Quick lyrics">
                            📜
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Real Application Progress Metrics -->
    <section class="journal-section mb-4">
        <div class="journal-section-header">
            <div>
                <h2 class="journal-section-title">Catalogue &amp; Audition Ledger</h2>
                <p class="journal-section-subtitle">Real-time status of your music collection and tournament advancement</p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Full Catalogue</span>
                    <span class="stat-icon icon-library">♪</span>
                </div>
                <div class="stat-value"><?= number_format($stats['total_songs']) ?></div>
                <div class="stat-desc">Strictly preserved database tracks</div>
                <div class="stat-meter">
                    <div class="stat-meter-fill bg-primary" style="width: 100%;"></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Shortlisted Contenders</span>
                    <span class="stat-icon icon-shortlisted">★</span>
                </div>
                <div class="stat-value text-accent" id="statShortlisted"><?= number_format($stats['shortlisted_count']) ?></div>
                <div class="stat-desc">Selected for multi-criteria audition</div>
                <div class="stat-meter">
                    <div class="stat-meter-fill bg-accent" style="width: <?= min(100, round(($stats['shortlisted_count'] / max(1, $stats['total_songs'])) * 100 * 5)) ?>%;"></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Discovery Review</span>
                    <span class="stat-icon icon-reviewed">👁</span>
                </div>
                <div class="stat-value text-gold"><?= number_format($stats['reviewed_count']) ?> <span class="text-xs text-muted font-normal">(<?= $discoveryPercent ?>%)</span></div>
                <div class="stat-desc"><?= number_format($stats['unreviewed_count']) ?> tracks awaiting review</div>
                <div class="stat-meter">
                    <div class="stat-meter-fill bg-gold" style="width: <?= $discoveryPercent ?>%;"></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Auditions Completed</span>
                    <span class="stat-icon icon-completed">✓</span>
                </div>
                <div class="stat-value text-success"><?= number_format($stats['auditions_completed']) ?> <span class="text-xs text-muted font-normal">(<?= $auditionPercent ?>%)</span></div>
                <div class="stat-desc"><?= number_format($stats['auditions_incomplete']) ?> incomplete scorecards to finish</div>
                <div class="stat-meter">
                    <div class="stat-meter-fill bg-success" style="width: <?= $auditionPercent ?>%;"></div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
