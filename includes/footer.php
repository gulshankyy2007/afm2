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
                <button type="button" class="modal-close" id="closeAppearanceModal" aria-label="Close">&times;</button>
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
                    <button type="button" class="btn btn-secondary btn-sm" id="btnCancelAppearance">Cancel</button>
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
                         height="38">
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
                    if (function_exists('get_dashboard_stats')) {
                        try {
                            $footerStats = get_dashboard_stats();
                            $footerTrackCount = $footerStats['total_songs'] ?? null;
                        } catch (\Throwable $e) {}
                    }
                    if ($footerTrackCount === null && function_exists('get_db_connection')) {
                        try {
                            $footerTrackCount = (int)get_db_connection()->query("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0")->fetchColumn();
                        } catch (\Throwable $e) {}
                    }
                    $footerTrackCount = $footerTrackCount ?? 2461;
                    ?>
                    <span class="dot-live"></span> <?= number_format($footerTrackCount) ?> Songs Preserved
                </span>
            </div>
        </div>
    </footer>

    <script src="assets/js/app.js?v=3.2"></script>
</body>
</html>
