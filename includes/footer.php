<?php
/**
 * AFM#2 - Main Layout Footer
 */
?>
    </main>

    <!-- Global Quick Lyrics & Playback Modal -->
    <div class="modal-overlay" id="globalLyricsModal">
        <div class="modal-content" style="max-width: 650px;">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" id="globalLyricsTitle">Song Lyrics</h3>
                    <div id="globalLyricsArtist" style="font-size: 0.85rem; color: var(--text-muted); margin-top: 2px;"></div>
                </div>
                <button type="button" class="modal-close" id="closeGlobalLyricsModal" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body" style="margin-bottom: 1rem;">
                <div style="display: flex; gap: 0.5rem; margin-bottom: 0.85rem; align-items: center; flex-wrap: wrap;">
                    <a href="#" id="globalLyricsYtLink" target="_blank" rel="noopener noreferrer" class="btn btn-xs btn-primary-subtle">
                        ▶ Open in YouTube ↗
                    </a>
                    <a href="#" id="globalLyricsYtmLink" target="_blank" rel="noopener noreferrer" class="btn btn-xs btn-secondary">
                        YouTube Music ↗
                    </a>
                    <div style="margin-left: auto; display: flex; gap: 0.35rem;">
                        <button type="button" class="lyrics-tab active" id="globalLyricsPlainTab">Plain</button>
                        <button type="button" class="lyrics-tab" id="globalLyricsSyncedTab">Synced</button>
                    </div>
                </div>
                <div id="globalLyricsBody" class="lyrics-body plain" style="max-height: 50vh; overflow-y: auto; background: rgba(0,0,0,0.3); border-radius: 6px; padding: 1rem;">
                    <div class="lyrics-state-msg">
                        <span class="lyrics-spinner"></span>
                        <span>Loading lyrics from LRCLIB...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: space-between; align-items: center;">
                <span style="font-size: 0.75rem; color: var(--text-muted);">Attribution: Lyrics retrieved on demand via LRCLIB</span>
                <button type="button" class="btn btn-secondary btn-sm" id="btnDismissGlobalLyrics">Close</button>
            </div>
        </div>
    </div>

    <!-- Appearance & Theme Customization Modal -->
    <div class="modal-overlay" id="appearanceModal" role="dialog" aria-modal="true" aria-labelledby="appearanceModalTitle">
        <div class="modal-content appearance-modal-content">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" id="appearanceModalTitle">Atmospheric Themes</h3>
                    <p class="modal-subtitle">Select a curated mood — harmonizing colors, background, and typography</p>
                </div>
                <button type="button" class="modal-close" id="closeAppearanceModal" data-modal-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body appearance-modal-body">
                <!-- Curated Distinctive Themes Grid -->
                <div class="curated-themes-grid" id="curatedThemesGrid">
                    <!-- Theme 1: Midnight Ember (Dark Warm) -->
                    <button type="button" class="curated-theme-card active" data-theme-val="midnight_ember" data-bg-val="atmospheric" data-opacity-val="70">
                        <div class="theme-card-banner banner-midnight">
                            <span class="theme-mood-tag">Reference Mood</span>
                            <div class="theme-swatch-pills">
                                <span style="background: #0c0b0f;" title="Charcoal Night"></span>
                                <span style="background: #f59e0b;" title="Warm Amber"></span>
                                <span style="background: #fbbf24;" title="Golden Sepia"></span>
                            </div>
                        </div>
                        <div class="theme-card-body">
                            <div class="theme-card-header">
                                <h4 class="theme-title-display font-serif">Midnight Ember</h4>
                                <span class="theme-active-badge">Active ✓</span>
                            </div>
                            <span class="theme-subtitle">Warm Amber &amp; Charcoal</span>
                            <p class="theme-narrative">Starry charcoal night, warm amber headlights, golden sepia dusk, and editorial serifs.</p>
                        </div>
                    </button>

                    <!-- Theme 2: Velvet Amethyst (Dark Cosmic Violet) -->
                    <button type="button" class="curated-theme-card" data-theme-val="velvet_amethyst" data-bg-val="atmospheric" data-opacity-val="78">
                        <div class="theme-card-banner banner-velvet">
                            <span class="theme-mood-tag">Cosmic Twilight</span>
                            <div class="theme-swatch-pills">
                                <span style="background: #0a0614;" title="Velvet Obsidian"></span>
                                <span style="background: #c084fc;" title="Electric Lavender"></span>
                                <span style="background: #38bdf8;" title="Neon Cyan"></span>
                            </div>
                        </div>
                        <div class="theme-card-body">
                            <div class="theme-card-header">
                                <h4 class="theme-title-display font-brand">Velvet Amethyst</h4>
                                <span class="theme-active-badge">Active ✓</span>
                            </div>
                            <span class="theme-subtitle">Nocturnal Violet &amp; Neon</span>
                            <p class="theme-narrative">Deep obsidian plum, royal violet cards, radiant electric lavender, and futuristic cyan sparks.</p>
                        </div>
                    </button>

                    <!-- Theme 3: Emerald Forest (Dark Botanical) -->
                    <button type="button" class="curated-theme-card" data-theme-val="emerald_forest" data-bg-val="atmospheric" data-opacity-val="76">
                        <div class="theme-card-banner banner-emerald">
                            <span class="theme-mood-tag">Organic Nocturne</span>
                            <div class="theme-swatch-pills">
                                <span style="background: #04120e;" title="Mountain Spruce"></span>
                                <span style="background: #34d399;" title="Luminous Jade"></span>
                                <span style="background: #fbbf24;" title="Warm Brass"></span>
                            </div>
                        </div>
                        <div class="theme-card-body">
                            <div class="theme-card-header">
                                <h4 class="theme-title-display font-poetic">Emerald Forest</h4>
                                <span class="theme-active-badge">Active ✓</span>
                            </div>
                            <span class="theme-subtitle">Alpine Pine &amp; Luminous Mint</span>
                            <p class="theme-narrative">Deep mountain spruce, mossy shadow depths, radiant jade highlights, and brass accents.</p>
                        </div>
                    </button>

                    <!-- Theme 4: Vintage Parchment (Warm Antique Light) -->
                    <button type="button" class="curated-theme-card" data-theme-val="vintage_paper" data-bg-val="atmospheric" data-opacity-val="22">
                        <div class="theme-card-banner banner-vintage">
                            <span class="theme-mood-tag">Literary Journal</span>
                            <div class="theme-swatch-pills">
                                <span style="background: #f5ede0;" title="Aged Parchment"></span>
                                <span style="background: #8c3b06;" title="Antique Amber"></span>
                                <span style="background: #190f07;" title="Deep Walnut Ink"></span>
                            </div>
                        </div>
                        <div class="theme-card-body">
                            <div class="theme-card-header">
                                <h4 class="theme-title-display font-poetic">Vintage Parchment</h4>
                                <span class="theme-active-badge">Active ✓</span>
                            </div>
                            <span class="theme-subtitle">Aged Manuscript &amp; Walnut Ink</span>
                            <p class="theme-narrative">Tactile aged parchment, ultra-readable deep walnut ink, warm linen, and timeless serifs.</p>
                        </div>
                    </button>

                    <!-- Theme 5: Nordic Frost (High-Contrast Cool Light) -->
                    <button type="button" class="curated-theme-card" data-theme-val="nordic_frost" data-bg-val="atmospheric" data-opacity-val="12">
                        <div class="theme-card-banner banner-nordic">
                            <span class="theme-mood-tag">Crisp Modernism</span>
                            <div class="theme-swatch-pills">
                                <span style="background: #f8fafc;" title="Glacial Snow"></span>
                                <span style="background: #1d4ed8;" title="Cobalt Sapphire"></span>
                                <span style="background: #09090b;" title="Obsidian Black"></span>
                            </div>
                        </div>
                        <div class="theme-card-body">
                            <div class="theme-card-header">
                                <h4 class="theme-title-display font-main">Nordic Frost</h4>
                                <span class="theme-active-badge">Active ✓</span>
                            </div>
                            <span class="theme-subtitle">High-Contrast Monolith</span>
                            <p class="theme-narrative">Glacial ice snow, precision obsidian typography, crisp graphite borders, and sapphire blue.</p>
                        </div>
                    </button>

                    <!-- Theme 6: Solar Terracotta (Warm Earth Sunset Light) -->
                    <button type="button" class="curated-theme-card" data-theme-val="solar_terracotta" data-bg-val="atmospheric" data-opacity-val="25">
                        <div class="theme-card-banner banner-solar">
                            <span class="theme-mood-tag">Golden Hour</span>
                            <div class="theme-swatch-pills">
                                <span style="background: #fff7ed;" title="Terracotta Dawn"></span>
                                <span style="background: #c2410c;" title="Burnt Sienna"></span>
                                <span style="background: #1c1917;" title="Espresso Dark"></span>
                            </div>
                        </div>
                        <div class="theme-card-body">
                            <div class="theme-card-header">
                                <h4 class="theme-title-display font-brand">Solar Terracotta</h4>
                                <span class="theme-active-badge">Active ✓</span>
                            </div>
                            <span class="theme-subtitle">Tuscan Sunset &amp; Clay</span>
                            <p class="theme-narrative">Sun-warmed terracotta dawn, roasted sienna, golden hour sunlight, and deep espresso text.</p>
                        </div>
                    </button>
                    <!-- Theme 7: Oceanic Dusk (Dark Marine & Tranquil Teal) -->
                    <button type="button" class="curated-theme-card" data-theme-val="oceanic" data-bg-val="atmospheric" data-opacity-val="75">
                        <div class="theme-card-banner banner-oceanic">
                            <span class="theme-mood-tag">Nocturnal Sea</span>
                            <div class="theme-swatch-pills">
                                <span style="background: #061219;" title="Midnight Marine"></span>
                                <span style="background: #0d9488;" title="Tranquil Teal"></span>
                                <span style="background: #38bdf8;" title="Cool Cyan"></span>
                            </div>
                        </div>
                        <div class="theme-card-body">
                            <div class="theme-card-header">
                                <h4 class="theme-title-display font-brand">Oceanic Dusk</h4>
                                <span class="theme-active-badge">Active ✓</span>
                            </div>
                            <span class="theme-subtitle">Deep Marine &amp; Twilight Coast</span>
                            <p class="theme-narrative">Midnight ocean horizons, tranquil deep teal, cool cyan highlights, and serene spacing.</p>
                        </div>
                    </button>

                    <!-- Theme 8: Quiet Minimal (Warm Alabaster Studio Light) -->
                    <button type="button" class="curated-theme-card" data-theme-val="quiet_minimal" data-bg-val="atmospheric" data-opacity-val="18">
                        <div class="theme-card-banner banner-minimal">
                            <span class="theme-mood-tag">Focused Studio</span>
                            <div class="theme-swatch-pills">
                                <span style="background: #faf9f6;" title="Warm Alabaster"></span>
                                <span style="background: #374151;" title="Graphite Slate"></span>
                                <span style="background: #111827;" title="Charcoal Ink"></span>
                            </div>
                        </div>
                        <div class="theme-card-body">
                            <div class="theme-card-header">
                                <h4 class="theme-title-display font-main">Quiet Minimal</h4>
                                <span class="theme-active-badge">Active ✓</span>
                            </div>
                            <span class="theme-subtitle">Pure Studio &amp; High Contrast</span>
                            <p class="theme-narrative">Warm alabaster, crisp neutral borders, charcoal typography, and distraction-free focus.</p>
                        </div>
                    </button>
                </div>

                <!-- Streamlined Layout Option -->
                <div class="layout-quick-bar">
                    <label class="compact-toggle-label" for="compactLayoutToggle">
                        <input type="checkbox" id="compactLayoutToggle" class="compact-toggle-checkbox">
                        <span class="compact-toggle-switch"></span>
                        <div class="compact-toggle-text">
                            <span class="toggle-title">Compact Player Bar</span>
                            <span class="toggle-sub">Streamlined horizontal playback layout instead of expanded showcase</span>
                        </div>
                    </label>
                </div>
            </div>
            <div class="modal-footer appearance-modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" id="btnResetAppearance">
                    ↺ Reset to Default
                </button>
                <div class="appearance-save-actions">
                    <button type="button" class="btn btn-secondary btn-sm" id="btnCancelAppearance" data-modal-close>Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnSaveAppearance">Save Theme</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Artwork Selection & Confirmation Modal -->
    <div class="modal-overlay" id="artworkModal" role="dialog" aria-modal="true" aria-labelledby="artworkModalTitle">
        <div class="modal-content artwork-modal-content">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" id="artworkModalTitle">Album Artwork Discovery</h3>
                    <p class="modal-subtitle" id="artworkModalSongDesc">Find and confirm album cover art</p>
                </div>
                <button type="button" class="modal-close" id="closeArtworkModal" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body artwork-modal-body">
                <div class="artwork-search-status" id="artworkSearchStatus">
                    <span class="lyrics-spinner"></span>
                    <span>Searching Cover Art Archive &amp; Openverse...</span>
                </div>

                <!-- Candidates Grid -->
                <div class="artwork-candidates-grid" id="artworkCandidatesGrid" style="display: none;"></div>

                <!-- Custom URL fallback -->
                <div class="artwork-custom-section">
                    <label for="customArtworkUrlInput" class="pref-label">Or enter direct image URL:</label>
                    <div class="custom-url-row">
                        <input type="url" 
                               id="customArtworkUrlInput" 
                               class="matcher-input" 
                               placeholder="https://example.com/cover.jpg">
                        <button type="button" class="btn btn-secondary btn-sm" id="btnApplyCustomArtwork">
                            Use Custom URL
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: space-between;">
                <button type="button" class="btn btn-danger-subtle btn-sm" id="btnRemoveArtwork" style="display: none;">
                    Remove Artwork
                </button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnCloseArtworkModal">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Global Toast Notification Container -->
    <div class="toast-container" id="toastContainer" aria-live="polite" aria-atomic="true"></div>

    <footer class="app-footer" id="appFooter">
        <div class="footer-inner">
            <div class="footer-left">
                <a href="index.php" class="footer-logo-link" aria-label="AFM#2 — Annual Musical Finale 2026">
                    <img src="assets/img/afm2-logo.png" 
                         alt="AFM#2 — Annual Musical Finale" 
                         class="footer-logo-img" 
                         width="70" 
                         height="38"
                         loading="lazy"
                         decoding="async">
                </a>
                <div class="footer-text-block">
                    <span class="footer-brand">AFM#2 &bull; 2026 Annual Musical Finale</span>
                    <span class="footer-sub">Personal Music Catalogue &amp; Tournament</span>
                </div>
            </div>
            <div class="footer-right">
                <span class="db-status-pill">
                    <?php
                    $footerTrackCount = null;
                    if (isset($stats['total_songs']) && is_numeric($stats['total_songs'])) {
                        $footerTrackCount = (int)$stats['total_songs'];
                    } elseif (function_exists('get_cached_dashboard_stats') && ($cachedFooterStats = get_cached_dashboard_stats()) !== null) {
                        $footerTrackCount = $cachedFooterStats['total_songs'] ?? null;
                    } elseif (function_exists('get_catalogue_song_count')) {
                        try {
                            $footerTrackCount = get_catalogue_song_count();
                        } catch (\Throwable $e) {}
                    }
                    ?>
                    <?php if (is_authenticated() && $footerTrackCount !== null && $footerTrackCount > 0): ?>
                        <span class="dot-live"></span> <?= number_format($footerTrackCount) ?> Songs Preserved
                    <?php elseif (is_authenticated()): ?>
                        <span class="dot-live"></span> Personal Music Sanctuary
                    <?php else: ?>
                        <span class="dot-live"></span> Curated Music Sanctuary
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <div class="footer-bottom-row">
            <nav class="footer-trust-nav" aria-label="Project Policies and Documentation">
                <a href="about.php">About AFM#2</a>
                <span class="footer-sep">&bull;</span>
                <a href="privacy.php">Privacy Policy</a>
                <span class="footer-sep">&bull;</span>
                <a href="terms.php">Terms of Use</a>
                <span class="footer-sep">&bull;</span>
                <a href="contact.php">Contact</a>
                <span class="footer-sep">&bull;</span>
                <a href="https://github.com/gulshankyy2007/afm2" target="_blank" rel="noopener noreferrer">GitHub Repository ↗</a>
            </nav>
            <p class="footer-attribution">
                Playback via YouTube embedded player &bull; Synchronized lyrics via LRCLIB &bull; Personal non-commercial music listening project &bull; All copyrights belong to original artists and labels.
            </p>
        </div>
    </footer>

    <?php if (is_authenticated()): 
        $isMoreActive = in_array($currentPage, ['recordings.php', 'discovery.php', 'results.php', 'import.php', 'account.php', 'users.php'], true);
    ?>
    <!-- Persistent Mobile 5-Destination Bottom Navigation Dock (<= 768px Viewports) -->
    <nav class="mobile-bottom-dock" id="mobileBottomDock" aria-label="Mobile Navigation">
        <a href="index.php" class="mobile-dock-item <?= $currentPage === 'index.php' ? 'active' : '' ?>" id="dockHome" aria-label="Journal Home" <?= $currentPage === 'index.php' ? 'aria-current="page"' : '' ?>>
            <span class="dock-icon" aria-hidden="true">⌂</span>
            <span class="dock-label">Journal</span>
        </a>
        <a href="library.php" class="mobile-dock-item <?= $currentPage === 'library.php' ? 'active' : '' ?>" id="dockLibrary" aria-label="Music Library" <?= $currentPage === 'library.php' ? 'aria-current="page"' : '' ?>>
            <span class="dock-icon" aria-hidden="true">♫</span>
            <span class="dock-label">Library</span>
        </a>
        <a href="auditions.php" class="mobile-dock-item <?= $currentPage === 'auditions.php' ? 'active' : '' ?>" id="dockAuditions" aria-label="Formal Auditions" <?= $currentPage === 'auditions.php' ? 'aria-current="page"' : '' ?>>
            <span class="dock-icon" aria-hidden="true">🎧</span>
            <span class="dock-label">Auditions</span>
        </a>
        <a href="tournament.php" class="mobile-dock-item <?= $currentPage === 'tournament.php' ? 'active' : '' ?>" id="dockTournament" aria-label="Tournament Progression" <?= $currentPage === 'tournament.php' ? 'aria-current="page"' : '' ?>>
            <span class="dock-icon" aria-hidden="true">🏆</span>
            <span class="dock-label">Tournament</span>
        </a>
        <button type="button" class="mobile-dock-item <?= $isMoreActive ? 'active' : '' ?>" id="btnOpenMobileMore" aria-label="More destinations and preferences" aria-haspopup="dialog" aria-expanded="false" <?= $isMoreActive ? 'aria-current="page"' : '' ?>>
            <span class="dock-icon" aria-hidden="true">⋯</span>
            <span class="dock-label">More</span>
        </button>
    </nav>

    <!-- Accessible Mobile Slide-up "More" Sheet -->
    <div class="modal-overlay mobile-more-modal" id="mobileMoreModal" role="dialog" aria-modal="true" aria-labelledby="moreSheetTitle">
        <div class="modal-content mobile-more-content">
            <div class="modal-header">
                <div class="mobile-more-title-wrap">
                    <span class="eyebrow-tag"><span class="eyebrow-dot"></span> Navigation</span>
                    <h3 class="modal-title" id="moreSheetTitle">More Destinations</h3>
                </div>
                <button type="button" class="modal-close" id="btnMoreClose" data-modal-close aria-label="Close navigation sheet">&times;</button>
            </div>
            <div class="modal-body mobile-more-body">
                <div class="mobile-more-grid">
                    <a href="recordings.php" class="more-nav-tile <?= $currentPage === 'recordings.php' ? 'active' : '' ?>">
                        <span class="tile-icon">🎬</span>
                        <div class="tile-text">
                            <strong>Recordings</strong>
                            <span>YouTube link matching</span>
                        </div>
                    </a>
                    <a href="discovery.php" class="more-nav-tile <?= $currentPage === 'discovery.php' ? 'active' : '' ?>">
                        <span class="tile-icon">★</span>
                        <div class="tile-text">
                            <strong>Shortlist</strong>
                            <span>Audition candidates</span>
                        </div>
                    </a>
                    <a href="results.php" class="more-nav-tile <?= $currentPage === 'results.php' ? 'active' : '' ?>">
                        <span class="tile-icon">📜</span>
                        <div class="tile-text">
                            <strong>Archive</strong>
                            <span>Hall of fame & history</span>
                        </div>
                    </a>
                    <a href="import.php" class="more-nav-tile <?= $currentPage === 'import.php' ? 'active' : '' ?>">
                        <span class="tile-icon">📁</span>
                        <div class="tile-text">
                            <strong>Import / Export</strong>
                            <span>CSV data transfers</span>
                        </div>
                    </a>
                    <button type="button" class="more-nav-tile" id="btnMoreOpenAppearance">
                        <span class="tile-icon">🎨</span>
                        <div class="tile-text">
                            <strong>Appearance</strong>
                            <span>8 atmospheric themes</span>
                        </div>
                    </button>
                    <a href="account.php" class="more-nav-tile <?= $currentPage === 'account.php' ? 'active' : '' ?>">
                        <span class="tile-icon">🛡️</span>
                        <div class="tile-text">
                            <strong>Account</strong>
                            <span>Passkeys & security</span>
                        </div>
                    </a>
                    <?php if (is_admin()): ?>
                    <a href="users.php" class="more-nav-tile <?= $currentPage === 'users.php' ? 'active' : '' ?>">
                        <span class="tile-icon">👥</span>
                        <div class="tile-text">
                            <strong>Users</strong>
                            <span>User administration</span>
                        </div>
                    </a>
                    <?php endif; ?>
                </div>

                <div class="mobile-more-user-strip">
                    <div class="more-user-badge">
                        <span class="status-indicator online"></span>
                        <span class="more-user-name"><?= htmlspecialchars(get_current_username() ?? 'User', ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="role-pill"><?= is_admin() ? 'Admin' : 'Judge' ?></span>
                    </div>
                    <form action="logout.php" method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-secondary btn-sm" id="btnMobileMoreSignout">
                            Sign Out
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $appJsFile = dirname(__DIR__) . '/public/assets/js/app.js';
    $appJsVer = '4.1.' . (file_exists($appJsFile) ? filemtime($appJsFile) : '20261029');
    ?>
    <script src="assets/js/app.js?v=<?= $appJsVer ?>"></script>
</body>
</html>
