/**
 * AFM#2 — Interactive High-Fidelity Mobile UI Prototype Controller
 * Powers 10 Mobile Screens, 3 Visual Directions, 8 Themes, and Responsive Viewports
 * Phase 0.5: Canonical Tournament Rules, Per-Song Evaluations & 200-Candidate Top 200 Stage
 */

(function () {
    'use strict';

    // ==========================================================================
    // 1. SYNTHETIC SONG CATALOGUE & 200-PARTICIPANT TOURNAMENT DATASET
    // ==========================================================================

    // 14 rich anchor songs with verified artwork, video IDs and lyrics
    const BASE_SONGS = [
        {
            id: 1,
            title: "Bohemian Rhapsody",
            artist: "Queen",
            discovery_status: "shortlisted",
            audition_total: 50,
            audition_incomplete: false,
            emotion: 10,
            replayability: 10,
            melody: 10,
            vocals: 10,
            lyrics: 10,
            genre: "Classic Rock / Opera",
            artwork_url: "https://images.unsplash.com/photo-1519681393784-d120267933ba?auto=format&fit=crop&w=400&q=80",
            video_id: "fJ9rUzIMcZQ",
            version: "Original Studio 1975",
            rank: 1
        },
        {
            id: 2,
            title: "Comfortably Numb",
            artist: "Pink Floyd",
            discovery_status: "shortlisted",
            audition_total: 49,
            audition_incomplete: false,
            emotion: 10,
            replayability: 10,
            melody: 10,
            vocals: 9,
            lyrics: 10,
            genre: "Progressive Rock",
            artwork_url: "https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=400&q=80",
            video_id: "_FrOQC-zSog",
            version: "Original Studio 1979",
            rank: 2
        },
        {
            id: 3,
            title: "Stairway to Heaven",
            artist: "Led Zeppelin",
            discovery_status: "shortlisted",
            audition_total: 49,
            audition_incomplete: false,
            emotion: 10,
            replayability: 9,
            melody: 10,
            vocals: 10,
            lyrics: 10,
            genre: "Hard Rock",
            artwork_url: "https://images.unsplash.com/photo-1470246973918-29a93221c455?auto=format&fit=crop&w=400&q=80",
            video_id: "QkF3oxziUI4",
            version: "Remastered 2012",
            rank: 3
        },
        {
            id: 4,
            title: "STARGAZING",
            artist: "Travis Scott",
            discovery_status: "shortlisted",
            audition_total: 47,
            audition_incomplete: false,
            emotion: 9,
            replayability: 10,
            melody: 9,
            vocals: 9,
            lyrics: 10,
            genre: "Psychedelic Trap",
            artwork_url: "https://images.unsplash.com/photo-1518709268805-4e9042af9f23?auto=format&fit=crop&w=400&q=80",
            video_id: "2a8PgqWrc_4",
            version: "Astroworld Edition",
            rank: 4
        },
        {
            id: 5,
            title: "Blinding Lights",
            artist: "The Weeknd",
            discovery_status: "shortlisted",
            audition_total: 46,
            audition_incomplete: false,
            emotion: 9,
            replayability: 10,
            melody: 10,
            vocals: 9,
            lyrics: 8,
            genre: "Modern Pop / Synth",
            artwork_url: "https://images.unsplash.com/photo-1508700115892-45ecd05ae2ad?auto=format&fit=crop&w=400&q=80",
            video_id: "dX3k_QDnzHE",
            version: "After Hours Cut",
            rank: 5
        },
        {
            id: 6,
            title: "Redbone",
            artist: "Childish Gambino",
            discovery_status: "shortlisted",
            audition_total: 45,
            audition_incomplete: false,
            emotion: 9,
            replayability: 9,
            melody: 9,
            vocals: 10,
            lyrics: 8,
            genre: "R&B / Soul",
            artwork_url: "https://images.unsplash.com/photo-1465847899084-d164df4dedc6?auto=format&fit=crop&w=400&q=80",
            video_id: "09839DpTctU",
            version: "Awaken, My Love! Mix",
            rank: 6
        },
        {
            id: 7,
            title: "Clair de Lune",
            artist: "Claude Debussy",
            discovery_status: "unreviewed",
            audition_total: 45,
            audition_incomplete: false,
            emotion: 10,
            replayability: 9,
            melody: 10,
            vocals: 7,
            lyrics: 9,
            genre: "Art Pop / Ambient",
            artwork_url: "https://images.unsplash.com/photo-1511671782779-c97d3d27a1d4?auto=format&fit=crop&w=400&q=80",
            video_id: "34Na4j8AVgA",
            version: "Suite Bergamasque",
            rank: 7
        },
        {
            id: 8,
            title: "Paranoid Android",
            artist: "Radiohead",
            discovery_status: "unreviewed",
            audition_total: 44,
            audition_incomplete: false,
            emotion: 9,
            replayability: 8,
            melody: 9,
            vocals: 9,
            lyrics: 9,
            genre: "Alternative Rock",
            artwork_url: "https://images.unsplash.com/photo-1498038432885-c6f3f1b912ee?auto=format&fit=crop&w=400&q=80",
            video_id: "1uYWYWPc9HU",
            version: "OK Computer Master",
            rank: 8
        },
        {
            id: 9,
            title: "Midnight City",
            artist: "M83",
            discovery_status: "shortlisted",
            audition_total: 44,
            audition_incomplete: false,
            emotion: 8,
            replayability: 10,
            melody: 9,
            vocals: 8,
            lyrics: 9,
            genre: "Electro Funk",
            artwork_url: "https://images.unsplash.com/photo-1514525253161-7a46d19cd819?auto=format&fit=crop&w=400&q=80",
            video_id: "mrZRURcb1cM",
            version: "Hurry Up, We're Dreaming",
            rank: 9
        },
        {
            id: 10,
            title: "Heroes",
            artist: "David Bowie",
            discovery_status: "shortlisted",
            audition_total: 44,
            audition_incomplete: false,
            emotion: 10,
            replayability: 8,
            melody: 8,
            vocals: 9,
            lyrics: 9,
            genre: "Art Pop / Ambient",
            artwork_url: "https://images.unsplash.com/photo-1526478806334-5fd488fcaabc?auto=format&fit=crop&w=400&q=80",
            video_id: "bsYp9q3QNaQ",
            version: "1977 Single Cut",
            rank: 10
        },
        {
            id: 11,
            title: "Mystery of Love",
            artist: "Sufjan Stevens",
            discovery_status: "unreviewed",
            audition_total: 34,
            audition_incomplete: true,
            emotion: 9,
            replayability: 8,
            melody: 9,
            vocals: 8,
            lyrics: 0,
            genre: "Indie Folk",
            artwork_url: "https://images.unsplash.com/photo-1447752875215-b2761acb3c5d?auto=format&fit=crop&w=400&q=80",
            video_id: "rL3AgkwbYgo",
            version: "Call Me By Your Name OST",
            rank: 11
        },
        {
            id: 12,
            title: "Subwoofer Lullaby",
            artist: "C418",
            discovery_status: "unreviewed",
            audition_total: 42,
            audition_incomplete: false,
            emotion: 9,
            replayability: 9,
            melody: 9,
            vocals: 7,
            lyrics: 8,
            genre: "Electronic / Ambient",
            artwork_url: "https://images.unsplash.com/photo-1509198397868-475647b2a1e5?auto=format&fit=crop&w=400&q=80",
            video_id: "NrgmdOz227I",
            version: "Minecraft Volume Alpha",
            rank: 12
        },
        {
            id: 13,
            title: "Symphony No. 5",
            artist: "Ludwig van Beethoven",
            discovery_status: "unreviewed",
            audition_total: 43,
            audition_incomplete: false,
            emotion: 10,
            replayability: 8,
            melody: 9,
            vocals: 7,
            lyrics: 9,
            genre: "Chamber Pop",
            artwork_url: "https://images.unsplash.com/photo-1511671782779-c97d3d27a1d4?auto=format&fit=crop&w=400&q=80",
            video_id: "jv2W2cZQ9CU",
            version: "Vienna Philharmonic",
            rank: 13
        },
        {
            id: 14,
            title: "Get Lucky",
            artist: "Daft Punk ft. Pharrell",
            discovery_status: "shortlisted",
            audition_total: 43,
            audition_incomplete: false,
            emotion: 8,
            replayability: 10,
            melody: 9,
            vocals: 8,
            lyrics: 8,
            genre: "Electro Funk",
            artwork_url: "https://images.unsplash.com/photo-1514525253161-7a46d19cd819?auto=format&fit=crop&w=400&q=80",
            video_id: "5NV6Rdv1a3I",
            version: "Random Access Memories",
            rank: 14
        }
    ];

    // Procedural generation to produce exactly 200 synthetic contenders for Top 200 Stage
    const GENRES_POOL = [
        "Classic Rock / Opera", "Progressive Rock", "Hard Rock", "Psychedelic Trap",
        "Modern Pop / Synth", "R&B / Soul", "Art Pop / Ambient", "Alternative Rock",
        "Chamber Pop", "Indie Folk", "Electro Funk", "Electronic / Ambient"
    ];
    const ARTISTS_POOL = [
        "The Midnight Echo", "Solaris Collective", "Aura Nova", "Velvet Frequency",
        "Horizon Blue", "Lunar Tide", "Neon Symphony", "Echo Canyon",
        "Starlight Theory", "Crimson Wave", "Silver Strand", "Golden Age",
        "Pulse District", "Wanderlust", "Astral Plane", "Mirage City",
        "Parallel Skies", "North Wind", "Subtle Rhythm", "Arcadia"
    ];
    const TITLES_POOL = [
        "Daybreak", "Supernova", "Weightless", "Gravity Well", "Neon Sunset",
        "Golden Hour", "Velvet Sky", "Silent Echo", "Midnight Run", "Electric Dreams",
        "Deep Water", "Paper Cranes", "Cascades", "Solitude", "Afterglow",
        "Resonance", "Chasing Shadows", "Labyrinth", "Firefly", "Horizon Line"
    ];
    const ARTWORKS_POOL = [
        "https://images.unsplash.com/photo-1519681393784-d120267933ba?auto=format&fit=crop&w=400&q=80",
        "https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=400&q=80",
        "https://images.unsplash.com/photo-1470246973918-29a93221c455?auto=format&fit=crop&w=400&q=80",
        "https://images.unsplash.com/photo-1518709268805-4e9042af9f23?auto=format&fit=crop&w=400&q=80",
        "https://images.unsplash.com/photo-1508700115892-45ecd05ae2ad?auto=format&fit=crop&w=400&q=80",
        "https://images.unsplash.com/photo-1465847899084-d164df4dedc6?auto=format&fit=crop&w=400&q=80"
    ];

    const SONGS_DATA = [...BASE_SONGS];
    for (let i = 15; i <= 200; i++) {
        const e = 5 + ((i * 3 + 1) % 6);
        const r = 5 + ((i * 5 + 2) % 6);
        const m = 5 + ((i * 2 + 3) % 6);
        const v = 5 + ((i * 7 + 1) % 6);
        const l = 5 + ((i * 4 + 2) % 6);
        const total = e + r + m + v + l;
        SONGS_DATA.push({
            id: i,
            title: `${TITLES_POOL[(i * 3) % TITLES_POOL.length]} (Pt. ${(i % 5) + 1})`,
            artist: ARTISTS_POOL[(i * 7) % ARTISTS_POOL.length],
            discovery_status: i % 4 === 0 ? "shortlisted" : "unreviewed",
            audition_total: total,
            audition_incomplete: i === 198,
            emotion: e,
            replayability: r,
            melody: m,
            vocals: v,
            lyrics: l,
            genre: GENRES_POOL[i % GENRES_POOL.length],
            artwork_url: ARTWORKS_POOL[i % ARTWORKS_POOL.length],
            video_id: "fJ9rUzIMcZQ",
            version: "Official Tournament Master",
            rank: i
        });
    }

    // Sort by canonical 7-field ranking sequence:
    // 1. Total (desc) -> 2. Emotion (desc) -> 3. Replay (desc) -> 4. Melody (desc) -> 5. Vocals (desc) -> 6. Lyrics (desc) -> 7. ID (asc)
    SONGS_DATA.sort((a, b) => {
        if (b.audition_total !== a.audition_total) return b.audition_total - a.audition_total;
        if (b.emotion !== a.emotion) return b.emotion - a.emotion;
        if (b.replayability !== a.replayability) return b.replayability - a.replayability;
        if (b.melody !== a.melody) return b.melody - a.melody;
        if (b.vocals !== a.vocals) return b.vocals - a.vocals;
        if (b.lyrics !== a.lyrics) return b.lyrics - a.lyrics;
        return a.id - b.id;
    });

    // Assign deterministic advisory ranks #1 to #200
    SONGS_DATA.forEach((s, idx) => {
        s.rank = idx + 1;
    });

    // 2. Independent Per-Song Evaluation State Store
    const initialEvaluations = {};
    SONGS_DATA.forEach(s => {
        let status = 'completed';
        if (s.id === 1) status = 'finalized';
        else if (s.id === 11 || s.audition_incomplete) status = 'incomplete';
        else if (s.id >= 195) status = 'untouched';

        initialEvaluations[s.id] = {
            emotion: status === 'untouched' ? 0 : s.emotion,
            replayability: status === 'untouched' ? 0 : s.replayability,
            melody: status === 'untouched' ? 0 : s.melody,
            vocals: status === 'untouched' ? 0 : s.vocals,
            lyrics: status === 'untouched' ? 0 : s.lyrics,
            status: status,
            finalizedAt: status === 'finalized' ? 'Finals Stage • Nov 2, 2026' : null
        };
    });

    // Canonical initial selection: Exactly 100 participants selected for Top 200 -> Top 100
    const initialTop100SelectedIds = new Set(SONGS_DATA.slice(0, 100).map(s => s.id));
    const initialOrderedQualifiers = SONGS_DATA.slice(0, 100);

    // Main Prototype State Container
    const state = {
        activeScreen: 'screen-home',
        visualDirection: 'midnight_muse',
        theme: 'midnight_ember',
        viewportWidth: '390',
        activeAuditionIdx: 0,
        evaluations: initialEvaluations,
        selectedSongForDetail: SONGS_DATA[0],
        manageSelectedIds: new Set(),

        // Manual Qualification Wizard State (Top 200 -> Top 100: target 100)
        wizardStep: 1,
        wizardSelectedIds: initialTop100SelectedIds,
        wizardOrderedQualifiers: initialOrderedQualifiers,
        wizardFilter: 'all', // 'all', 'selected', 'unselected'
        wizardSearch: '',
        wizardReplaceTargetIdx: null,
        wizardReplaceSearch: '',

        // Stage Lock & Historical Ledger
        isTop200Finalized: false,
        finalizedTop100Roster: [],

        // Tournament Board Controls
        tournamentSearch: '',
        tournamentFilter: 'all',

        // Library Controls
        libraryMode: 'discover',
        searchQuery: '',
        activeGenreFilter: 'all'
    };

    // DOM Elements Cache
    const elements = {
        devicePhone: document.getElementById('devicePhone'),
        screenContent: document.getElementById('screenContent'),
        bottomNav: document.getElementById('mobileBottomNav'),
        moreModalSheet: document.getElementById('moreModalSheet'),
        songDetailModal: document.getElementById('songDetailModal'),
        toastContainer: document.getElementById('toastContainer'),
        screenJumpSelect: document.getElementById('screenJumpSelect'),
        directionSelect: document.getElementById('directionSelect'),
        themeSelect: document.getElementById('themeSelect')
    };

    // ==========================================================================
    // 2. INITIALIZATION & WORKBENCH CONTROLS
    // ==========================================================================

    function init() {
        // Parse URL Parameters for Automated Review, Screenshots & Deep-Linking
        try {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('theme')) state.theme = urlParams.get('theme');
            if (urlParams.get('screen')) state.activeScreen = urlParams.get('screen');
            if (urlParams.get('width')) state.viewportWidth = urlParams.get('width');
            if (urlParams.get('direction')) state.visualDirection = urlParams.get('direction');
        } catch (e) {
            // Graceful fallback for non-browser environments
        }

        if (elements.devicePhone) {
            elements.devicePhone.setAttribute('data-theme', state.theme);
            elements.devicePhone.setAttribute('data-visual-direction', state.visualDirection);
            elements.devicePhone.setAttribute('data-width', state.viewportWidth);
        }
        if (elements.themeSelect) elements.themeSelect.value = state.theme;
        if (elements.directionSelect) elements.directionSelect.value = state.visualDirection;

        bindWorkbenchControls();
        bindBottomNav();
        renderActiveScreen();
        initClock();
    }

    function initClock() {
        const timeEl = document.getElementById('statusTime');
        function updateTime() {
            const now = new Date();
            const h = String(now.getHours()).padStart(2, '0');
            const m = String(now.getMinutes()).padStart(2, '0');
            if (timeEl) timeEl.textContent = `${h}:${m}`;
        }
        updateTime();
        setInterval(updateTime, 10000);
    }

    function bindWorkbenchControls() {
        // Breakpoint Continuum Buttons
        const widthButtons = document.querySelectorAll('.width-toggle-btn');
        widthButtons.forEach(btn => {
            btn.onclick = () => {
                widthButtons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const w = btn.getAttribute('data-width');
                state.viewportWidth = w;
                if (elements.devicePhone) {
                    elements.devicePhone.setAttribute('data-width', w);
                }
            };
        });

        // Visual Direction Select
        if (elements.directionSelect) {
            elements.directionSelect.onchange = (e) => {
                state.visualDirection = e.target.value;
                if (elements.devicePhone) {
                    elements.devicePhone.setAttribute('data-visual-direction', state.visualDirection);
                }
            };
        }

        // Theme Select (All 8 Themes)
        if (elements.themeSelect) {
            elements.themeSelect.onchange = (e) => {
                state.theme = e.target.value;
                if (elements.devicePhone) {
                    elements.devicePhone.setAttribute('data-theme', state.theme);
                }
            };
        }

        // Screen Jump Select
        if (elements.screenJumpSelect) {
            elements.screenJumpSelect.onchange = (e) => {
                switchScreen(e.target.value);
            };
        }
    }

    function bindBottomNav() {
        if (!elements.bottomNav) return;
        const navItems = elements.bottomNav.querySelectorAll('.nav-item');
        navItems.forEach(item => {
            item.onclick = (e) => {
                e.preventDefault();
                const target = item.getAttribute('data-screen');
                if (target === 'modal-more') {
                    toggleMoreSheet(true);
                } else if (target) {
                    switchScreen(target);
                }
            };
        });

        const closeBtn = document.getElementById('btnCloseMoreSheet');
        if (closeBtn) closeBtn.onclick = () => toggleMoreSheet(false);

        const sheetNavItems = document.querySelectorAll('[data-jump-screen]');
        sheetNavItems.forEach(link => {
            link.onclick = (e) => {
                e.preventDefault();
                toggleMoreSheet(false);
                const s = link.getAttribute('data-jump-screen');
                if (s) switchScreen(s);
            };
        });
    }

    function toggleMoreSheet(open) {
        if (!elements.moreModalSheet) return;
        if (open) {
            elements.moreModalSheet.classList.add('active');
        } else {
            elements.moreModalSheet.classList.remove('active');
        }
    }

    function switchScreen(screenId) {
        state.activeScreen = screenId;
        if (elements.screenJumpSelect) {
            elements.screenJumpSelect.value = screenId;
        }

        // Update Bottom Nav active state
        if (elements.bottomNav) {
            const navItems = elements.bottomNav.querySelectorAll('.nav-item');
            navItems.forEach(item => {
                const s = item.getAttribute('data-screen');
                if (s === screenId) item.classList.add('active');
                else item.classList.remove('active');
            });
        }

        renderActiveScreen();
        // Scroll emulator body to top
        const scrollArea = document.querySelector('.phone-screen-scrollable');
        if (scrollArea) scrollArea.scrollTop = 0;
    }

    function renderActiveScreen() {
        if (!elements.screenContent) return;

        switch (state.activeScreen) {
            case 'screen-home':
                renderScreenHome();
                break;
            case 'screen-library-discover':
                state.libraryMode = 'discover';
                renderScreenLibrary();
                break;
            case 'screen-library-manage':
                state.libraryMode = 'manage';
                renderScreenLibrary();
                break;
            case 'screen-song-detail':
                renderScreenSongDetail();
                break;
            case 'screen-auditions':
                renderScreenAuditions();
                break;
            case 'screen-recordings':
                renderScreenRecordings();
                break;
            case 'screen-tournament':
                renderScreenTournament();
                break;
            case 'screen-qualification':
                renderScreenQualification();
                break;
            case 'screen-archive':
                renderScreenArchive();
                break;
            case 'screen-champion':
                renderScreenChampion();
                break;
            default:
                renderScreenHome();
        }
    }

    // ==========================================================================
    // 3. SCREEN 1: HOME / DASHBOARD
    // ==========================================================================

    function renderScreenHome() {
        const feat = SONGS_DATA[0];
        const shortlisted = SONGS_DATA.filter(s => s.discovery_status === 'shortlisted');

        elements.screenContent.innerHTML = `
            <div>
                <!-- Hero Audition Turntable Card -->
                <div class="home-hero-card">
                    <div class="turntable-visual">
                        <div class="vinyl-disc">
                            <div class="vinyl-groove"></div>
                            <div class="vinyl-center"></div>
                        </div>
                    </div>
                    <div style="font-size: 0.7rem; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem;">
                        Featured Contender &bull; Finals Anchor
                    </div>
                    <h2 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 0.2rem;">${escapeHtml(feat.title)}</h2>
                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.75rem;">${escapeHtml(feat.artist)} &bull; ${escapeHtml(feat.genre)}</div>
                    
                    <div style="display: flex; gap: 0.5rem; justify-content: center;">
                        <button type="button" class="btn btn-primary btn-sm" id="btnHomeResumeAud">
                            <span>▶</span> Evaluate Contender
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" id="btnHomeDiscover">
                            <span>★</span> Browse 200 Top Tracks
                        </button>
                    </div>
                </div>

                <!-- Tournament Stage Milestone Banner (7 Canonical Stages) -->
                <div class="home-section-header">
                    <h3 class="home-section-title">Tournament Milestones</h3>
                    <a href="#tournament" class="home-section-link" id="linkHomeViewAllStages">View Board &rarr;</a>
                </div>
                <div class="milestone-trail-card">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.5rem;">
                        <span style="font-size: 0.8rem; font-weight: 700;">Stage 2: Top 200 Stage</span>
                        <span style="font-size: 0.7rem; color: var(--primary); font-family: var(--font-mono);">200 Available &bull; Target: 100</span>
                    </div>
                    <div style="width: 100%; height: 6px; background: rgba(255,255,255,0.1); border-radius: var(--radius-full); overflow: hidden; margin-bottom: 0.5rem;">
                        <div style="width: 50%; height: 100%; background: var(--primary);"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.7rem; color: var(--text-muted);">
                        <span>Advancement: 100 to Top 100</span>
                        <span>Stage 2 of 7</span>
                    </div>
                </div>

                <!-- Quick Action Tiles -->
                <div class="home-section-header">
                    <h3 class="home-section-title">Quick Actions</h3>
                </div>
                <div class="quick-actions-grid">
                    <div class="quick-action-tile" id="tileQuickAudition">
                        <span class="quick-action-icon">🎧</span>
                        <span class="quick-action-label">Audition Room</span>
                    </div>
                    <div class="quick-action-tile" id="tileQuickDiscover">
                        <span class="quick-action-icon">✨</span>
                        <span class="quick-action-label">Discovery Grid</span>
                    </div>
                    <div class="quick-action-tile" id="tileQuickTournament">
                        <span class="quick-action-icon">🏆</span>
                        <span class="quick-action-label">Top 200 Board</span>
                    </div>
                    <div class="quick-action-tile" id="tileQuickArchive">
                        <span class="quick-action-icon">📜</span>
                        <span class="quick-action-label">Hall of Fame</span>
                    </div>
                </div>

                <!-- Shortlisted Preview Carousel -->
                <div class="home-section-header">
                    <h3 class="home-section-title">Shortlist (${shortlisted.length})</h3>
                    <span style="font-size: 0.72rem; color: var(--text-muted);">Curated Contenders</span>
                </div>
                <div class="shortlist-strip">
                    ${shortlisted.slice(0, 6).map(s => `
                        <div class="shortlist-card" data-song-id="${s.id}">
                            <img src="${s.artwork_url}" alt="${escapeHtml(s.title)}" class="shortlist-card-img">
                            <div class="shortlist-card-title">${escapeHtml(s.title)}</div>
                            <div class="shortlist-card-artist">${escapeHtml(s.artist)}</div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;

        document.getElementById('btnHomeResumeAud').onclick = () => switchScreen('screen-auditions');
        document.getElementById('btnHomeDiscover').onclick = () => switchScreen('screen-library-discover');
        document.getElementById('linkHomeViewAllStages').onclick = () => switchScreen('screen-tournament');
        document.getElementById('tileQuickAudition').onclick = () => switchScreen('screen-auditions');
        document.getElementById('tileQuickDiscover').onclick = () => switchScreen('screen-library-discover');
        document.getElementById('tileQuickTournament').onclick = () => switchScreen('screen-tournament');
        document.getElementById('tileQuickArchive').onclick = () => switchScreen('screen-archive');

        elements.screenContent.querySelectorAll('.shortlist-card').forEach(c => {
            c.onclick = () => {
                const id = parseInt(c.getAttribute('data-song-id'), 10);
                const s = SONGS_DATA.find(x => x.id === id);
                if (s) openSongDetail(s);
            };
        });
    }

    // ==========================================================================
    // 4. SCREEN 2 & 3: LIBRARY (Discover vs Manage)
    // ==========================================================================

    function renderScreenLibrary() {
        const isDiscover = state.libraryMode === 'discover';

        let filtered = SONGS_DATA.slice();
        if (state.searchQuery.trim() !== '') {
            const q = state.searchQuery.toLowerCase();
            filtered = filtered.filter(s => s.title.toLowerCase().includes(q) || s.artist.toLowerCase().includes(q));
        }

        elements.screenContent.innerHTML = `
            <div>
                <!-- Search Bar -->
                <div class="mobile-search-bar">
                    <div class="search-field-wrapper">
                        <span class="search-field-icon">🔍</span>
                        <input type="text" 
                               id="libSearchInput" 
                               class="search-field-input" 
                               placeholder="Search title, artist, or track ID..." 
                               value="${escapeHtml(state.searchQuery)}">
                        ${state.searchQuery ? '<button type="button" id="libSearchClear" class="search-field-clear">&times;</button>' : ''}
                    </div>
                </div>

                <!-- Segmented Switcher -->
                <div class="segmented-control">
                    <button type="button" class="segmented-btn ${isDiscover ? 'active' : ''}" id="btnModeDiscover">
                        <span>★</span> Discover View
                    </button>
                    <button type="button" class="segmented-btn ${!isDiscover ? 'active' : ''}" id="btnModeManage">
                        <span>☰</span> Manage View
                    </button>
                </div>

                <!-- Filter Chips -->
                <div class="filter-chips-scroller">
                    <button type="button" class="filter-chip ${state.activeGenreFilter === 'all' ? 'active' : ''}" data-filter="all">All (200)</button>
                    <button type="button" class="filter-chip ${state.activeGenreFilter === 'rock' ? 'active' : ''}" data-filter="rock">Classic Rock</button>
                    <button type="button" class="filter-chip ${state.activeGenreFilter === 'synth' ? 'active' : ''}" data-filter="synth">Synthwave</button>
                    <button type="button" class="filter-chip ${state.activeGenreFilter === 'trap' ? 'active' : ''}" data-filter="trap">Psychedelic Trap</button>
                    <button type="button" class="filter-chip ${state.activeGenreFilter === 'ambient' ? 'active' : ''}" data-filter="ambient">Ambient</button>
                </div>

                <!-- View Container -->
                ${isDiscover ? renderLibraryDiscoverView(filtered) : renderLibraryManageView(filtered)}
            </div>
        `;

        const searchInput = document.getElementById('libSearchInput');
        searchInput.oninput = (e) => {
            state.searchQuery = e.target.value;
            renderScreenLibrary();
        };

        const clearBtn = document.getElementById('libSearchClear');
        if (clearBtn) {
            clearBtn.onclick = () => {
                state.searchQuery = '';
                renderScreenLibrary();
            };
        }

        document.getElementById('btnModeDiscover').onclick = () => {
            state.libraryMode = 'discover';
            renderScreenLibrary();
        };
        document.getElementById('btnModeManage').onclick = () => {
            state.libraryMode = 'manage';
            renderScreenLibrary();
        };

        elements.screenContent.querySelectorAll('.filter-chip').forEach(chip => {
            chip.onclick = () => {
                state.activeGenreFilter = chip.getAttribute('data-filter');
                renderScreenLibrary();
            };
        });

        if (isDiscover) {
            elements.screenContent.querySelectorAll('.discover-card').forEach(card => {
                card.onclick = (e) => {
                    if (e.target.closest('.btn-shortlist-star')) return;
                    const sId = parseInt(card.getAttribute('data-song-id'), 10);
                    const s = SONGS_DATA.find(x => x.id === sId);
                    if (s) openSongDetail(s);
                };
            });
            elements.screenContent.querySelectorAll('.btn-shortlist-star').forEach(star => {
                star.onclick = (e) => {
                    e.stopPropagation();
                    const sId = parseInt(star.getAttribute('data-song-id'), 10);
                    const s = SONGS_DATA.find(x => x.id === sId);
                    if (s) {
                        s.discovery_status = s.discovery_status === 'shortlisted' ? 'unreviewed' : 'shortlisted';
                        showToast(`${s.title}: ${s.discovery_status === 'shortlisted' ? 'Shortlisted ★' : 'Removed from Shortlist'}`);
                        renderScreenLibrary();
                    }
                };
            });
        } else {
            elements.screenContent.querySelectorAll('.manage-checkbox').forEach(cb => {
                cb.onchange = () => {
                    const id = parseInt(cb.value, 10);
                    if (cb.checked) state.manageSelectedIds.add(id);
                    else state.manageSelectedIds.delete(id);
                    renderScreenLibrary();
                };
            });
            const btnBulkDelete = document.getElementById('btnBulkDelete');
            if (btnBulkDelete) {
                btnBulkDelete.onclick = () => {
                    showToast(`Tournament Safeguard: ${state.manageSelectedIds.size} unrated tracks deleted. Rated tracks protected.`);
                    state.manageSelectedIds.clear();
                    renderScreenLibrary();
                };
            }
            const btnBulkClear = document.getElementById('btnBulkClear');
            if (btnBulkClear) {
                btnBulkClear.onclick = () => {
                    state.manageSelectedIds.clear();
                    renderScreenLibrary();
                };
            }
            elements.screenContent.querySelectorAll('.btn-manage-row-click').forEach(el => {
                el.onclick = () => {
                    const sId = parseInt(el.getAttribute('data-song-id'), 10);
                    const s = SONGS_DATA.find(x => x.id === sId);
                    if (s) openSongDetail(s);
                };
            });
        }
    }

    function renderLibraryDiscoverView(songs) {
        return `
            <div class="discover-grid">
                ${songs.slice(0, 60).map(s => `
                    <div class="discover-card" data-song-id="${s.id}">
                        <div class="discover-art-wrap">
                            <img src="${s.artwork_url}" alt="${escapeHtml(s.title)}" class="discover-art-img" loading="lazy">
                            <div class="discover-badge-overlay">
                                ${s.audition_total !== null ? `
                                    <span class="score-badge score-badge-complete">${s.audition_total}/50</span>
                                ` : s.discovery_status === 'shortlisted' ? `
                                    <span class="status-tag status-tag-shortlisted">Shortlist</span>
                                ` : ''}
                            </div>
                        </div>
                        <div class="discover-card-body">
                            <div class="discover-card-title">${escapeHtml(s.title)}</div>
                            <div class="discover-card-artist">${escapeHtml(s.artist)}</div>
                            <div class="discover-card-footer">
                                <span style="font-family: var(--font-mono); font-size: 0.65rem; color: var(--text-muted);">#${s.id}</span>
                                <button type="button" class="btn-shortlist-star ${s.discovery_status === 'shortlisted' ? 'active' : ''}" data-song-id="${s.id}" title="Shortlist track">
                                    ${s.discovery_status === 'shortlisted' ? '★' : '☆'}
                                </button>
                            </div>
                        </div>
                    </div>
                `).join('')}
            </div>
            ${songs.length > 60 ? `
                <div style="text-align: center; padding: 1rem; font-size: 0.72rem; color: var(--text-muted);">
                    Displaying 60 of ${songs.length} contenders &bull; Filter or search to narrow
                </div>
            ` : ''}
        `;
    }

    function renderLibraryManageView(songs) {
        const hasSelection = state.manageSelectedIds.size > 0;
        return `
            <div class="manage-list">
                ${songs.slice(0, 60).map(s => {
                    const isSelected = state.manageSelectedIds.has(s.id);
                    return `
                        <div class="manage-row ${isSelected ? 'selected' : ''}">
                            <input type="checkbox" class="manage-checkbox" value="${s.id}" ${isSelected ? 'checked' : ''}>
                            <span class="manage-track-id">#${s.id}</span>
                            <img src="${s.artwork_url}" alt="" class="manage-thumb btn-manage-row-click" data-song-id="${s.id}">
                            <div class="manage-meta btn-manage-row-click" data-song-id="${s.id}">
                                <div class="manage-title">${escapeHtml(s.title)}</div>
                                <div class="manage-artist">${escapeHtml(s.artist)}</div>
                            </div>
                            <div class="manage-actions">
                                ${s.audition_total !== null ? `
                                    <span class="score-badge score-badge-complete">${s.audition_total}</span>
                                ` : s.discovery_status === 'shortlisted' ? `
                                    <span class="status-tag status-tag-shortlisted">★</span>
                                ` : `
                                    <span class="status-tag status-tag-unreviewed">○</span>
                                `}
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
            ${songs.length > 60 ? `
                <div style="text-align: center; padding: 1rem; font-size: 0.72rem; color: var(--text-muted);">
                    Displaying 60 of ${songs.length} rows &bull; Use search to jump to specific ID
                </div>
            ` : ''}

            <!-- Floating Bulk Actions Bar -->
            ${hasSelection ? `
                <div class="floating-bulk-bar">
                    <span style="font-size: 0.8rem; font-weight: 700; color: var(--primary);">
                        ${state.manageSelectedIds.size} Selected
                    </span>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" class="btn btn-xs btn-secondary" id="btnBulkClear">Clear</button>
                        <button type="button" class="btn btn-xs btn-primary" id="btnBulkDelete" style="background: var(--danger); color: white;">
                            <span>🗑️</span> Delete Selected
                        </button>
                    </div>
                </div>
            ` : ''}
        `;
    }

    // ==========================================================================
    // 5. SCREEN 4: SONG DETAIL MODAL/VIEW
    // ==========================================================================

    function openSongDetail(song) {
        state.selectedSongForDetail = song;
        renderScreenSongDetail();
    }

    function renderScreenSongDetail() {
        const song = state.selectedSongForDetail || SONGS_DATA[0];
        const evalData = state.evaluations[song.id] || {
            emotion: song.emotion, replayability: song.replayability,
            melody: song.melody, vocals: song.vocals, lyrics: song.lyrics,
            status: 'completed'
        };
        const total = (evalData.emotion || 0) + (evalData.replayability || 0) + (evalData.melody || 0) + (evalData.vocals || 0) + (evalData.lyrics || 0);

        elements.screenContent.innerHTML = `
            <div class="detail-screen">
                <div class="detail-hero-frame">
                    <img src="${song.artwork_url}" alt="${escapeHtml(song.title)}" class="detail-hero-img">
                    <button type="button" class="btn btn-secondary btn-xs" id="btnDetailBack" style="position: absolute; top: 12px; left: 12px; background: rgba(0,0,0,0.65); border: none; color: white;">
                        &larr; Back
                    </button>
                    <div style="position: absolute; bottom: 12px; right: 12px;">
                        <span class="score-badge score-badge-complete font-mono" style="font-size: 0.85rem; padding: 0.35rem 0.65rem;">
                            ${total}/50
                        </span>
                    </div>
                </div>

                <div style="padding: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                        <div>
                            <h2 style="font-size: 1.35rem; font-weight: 800; line-height: 1.2;">${escapeHtml(song.title)}</h2>
                            <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 2px;">${escapeHtml(song.artist)} &bull; ${escapeHtml(song.genre)}</div>
                        </div>
                    </div>

                    <!-- 5 Criteria Scorecard Strip (Emotion, Replay, Melody, Vocals, Lyrics) -->
                    <div class="detail-criteria-grid">
                        <div class="detail-criterion-box">
                            <span class="detail-crit-label">Emotion</span>
                            <span class="detail-crit-val">${evalData.emotion}</span>
                        </div>
                        <div class="detail-criterion-box">
                            <span class="detail-crit-label">Replay</span>
                            <span class="detail-crit-val">${evalData.replayability}</span>
                        </div>
                        <div class="detail-criterion-box">
                            <span class="detail-crit-label">Melody</span>
                            <span class="detail-crit-val">${evalData.melody}</span>
                        </div>
                        <div class="detail-criterion-box">
                            <span class="detail-crit-label">Vocals</span>
                            <span class="detail-crit-val">${evalData.vocals}</span>
                        </div>
                        <div class="detail-criterion-box">
                            <span class="detail-crit-label">Lyrics</span>
                            <span class="detail-crit-val">${evalData.lyrics}</span>
                        </div>
                    </div>

                    <!-- Action Trigger -->
                    <button type="button" class="btn btn-primary" id="btnDetailEnterAudition" style="width: 100%; margin-top: 1rem;">
                        <span>🎧</span> Open in Audition Workbench &rarr;
                    </button>
                </div>
            </div>
        `;

        document.getElementById('btnDetailBack').onclick = () => switchScreen('screen-library-discover');
        document.getElementById('btnDetailEnterAudition').onclick = () => {
            const idx = SONGS_DATA.findIndex(s => s.id === song.id);
            if (idx >= 0) state.activeAuditionIdx = idx;
            switchScreen('screen-auditions');
        };
    }

    // ==========================================================================
    // 6. SCREEN 5: AUDITION WORKBENCH (PER-SONG EVALUATION STATE)
    // ==========================================================================

    function renderScreenAuditions() {
        const song = SONGS_DATA[state.activeAuditionIdx] || SONGS_DATA[0];
        const evalData = state.evaluations[song.id];
        const total = (evalData.emotion || 0) + (evalData.replayability || 0) + (evalData.melody || 0) + (evalData.vocals || 0) + (evalData.lyrics || 0);
        const isFinalized = evalData.status === 'finalized';
        const isUntouched = evalData.status === 'untouched';
        const isIncomplete = evalData.status === 'incomplete';

        elements.screenContent.innerHTML = `
            <div class="audition-focused-screen">
                <!-- Navigation strip -->
                <div class="audition-nav-strip">
                    <button type="button" class="btn btn-secondary btn-xs" id="btnAudPrev" ${state.activeAuditionIdx === 0 ? 'disabled style="opacity:0.4;"' : ''}>
                        &larr; Prev
                    </button>
                    <span class="audition-counter">
                        Contender ${state.activeAuditionIdx + 1} of ${SONGS_DATA.length}
                    </span>
                    <button type="button" class="btn btn-secondary btn-xs" id="btnAudNext" ${state.activeAuditionIdx === SONGS_DATA.length - 1 ? 'disabled style="opacity:0.4;"' : ''}>
                        Next &rarr;
                    </button>
                </div>

                <!-- Main Evaluation Card -->
                <div class="audition-card">
                    <div class="audition-hero-area">
                        <div class="audition-artwork-frame">
                            <img src="${song.artwork_url}" alt="${escapeHtml(song.title)}" class="audition-artwork-img">
                        </div>
                        <h2 class="audition-title-display">${escapeHtml(song.title)}</h2>
                        <div class="audition-artist-display">${escapeHtml(song.artist)}</div>
                        
                        <div class="audition-total-display">
                            <span id="audTotalVal">${total}</span>
                            <span class="audition-total-max">/ 50</span>
                        </div>

                        <!-- Dynamic Evaluation Status Tag -->
                        <div style="margin-top: 4px;" id="audCompleteTag">
                            ${isFinalized ? `
                                <span class="score-badge" style="background: rgba(168, 85, 247, 0.2); color: #c084fc; border: 1px solid #c084fc;">
                                    🔒 Finalized &amp; Locked (${evalData.finalizedAt || 'Official Record'})
                                </span>
                            ` : isIncomplete ? `
                                <span class="score-badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid #f59e0b;">
                                    ● Incomplete Evaluation (${total}/50)
                                </span>
                            ` : isUntouched ? `
                                <span class="score-badge" style="background: rgba(255, 255, 255, 0.08); color: var(--text-muted);">
                                    ○ Untouched Scorecard (0/50)
                                </span>
                            ` : `
                                <span class="score-badge score-badge-complete">
                                    ✓ Complete Scorecard (${total}/50)
                                </span>
                            `}
                        </div>

                        <!-- Audio and Lyrics Triggers -->
                        <div style="display: flex; gap: 0.5rem; justify-content: center; margin-top: 0.85rem;">
                            <button type="button" class="btn btn-xs btn-secondary" id="btnTogglePlayer">
                                <span>▶</span> YouTube Player
                            </button>
                            <button type="button" class="btn btn-xs btn-secondary" id="btnToggleLyrics">
                                <span>📜</span> Lyrics (LRCLIB)
                            </button>
                        </div>
                    </div>

                    <!-- Finalized Immutable Notice (If Locked) -->
                    ${isFinalized ? `
                        <div class="scorecard-locked-notice">
                            <strong>🔒 Immutable Stage Record:</strong> This evaluation was permanently locked during stage finalization. Under AFM#2 tournament rules, criteria sliders and scorecard resets are disabled for finalized entries.
                        </div>
                    ` : ''}

                    <!-- Canonical 5 Scoring Criteria Range Sliders (0 - 10) -->
                    <div class="criteria-container">
                        <!-- 1. Emotion -->
                        <div class="criterion-row">
                            <div class="criterion-header">
                                <span><span>❤️</span> Emotion</span>
                                <span class="criterion-val-pill" id="valEmo">${evalData.emotion}</span>
                            </div>
                            <input type="range" min="0" max="10" step="1" value="${evalData.emotion}" class="criterion-slider" id="sliderEmo" ${isFinalized ? 'disabled' : ''}>
                        </div>

                        <!-- 2. Replayability -->
                        <div class="criterion-row">
                            <div class="criterion-header">
                                <span><span>🔁</span> Replayability</span>
                                <span class="criterion-val-pill" id="valRep">${evalData.replayability}</span>
                            </div>
                            <input type="range" min="0" max="10" step="1" value="${evalData.replayability}" class="criterion-slider" id="sliderRep" ${isFinalized ? 'disabled' : ''}>
                        </div>

                        <!-- 3. Melody -->
                        <div class="criterion-row">
                            <div class="criterion-header">
                                <span><span>🎵</span> Melody</span>
                                <span class="criterion-val-pill" id="valMel">${evalData.melody}</span>
                            </div>
                            <input type="range" min="0" max="10" step="1" value="${evalData.melody}" class="criterion-slider" id="sliderMel" ${isFinalized ? 'disabled' : ''}>
                        </div>

                        <!-- 4. Vocals -->
                        <div class="criterion-row">
                            <div class="criterion-header">
                                <span><span>🎤</span> Vocals</span>
                                <span class="criterion-val-pill" id="valVoc">${evalData.vocals}</span>
                            </div>
                            <input type="range" min="0" max="10" step="1" value="${evalData.vocals}" class="criterion-slider" id="sliderVoc" ${isFinalized ? 'disabled' : ''}>
                        </div>

                        <!-- 5. Lyrics -->
                        <div class="criterion-row">
                            <div class="criterion-header">
                                <span><span>✍️</span> Lyrics</span>
                                <span class="criterion-val-pill" id="valLyr">${evalData.lyrics}</span>
                            </div>
                            <input type="range" min="0" max="10" step="1" value="${evalData.lyrics}" class="criterion-slider" id="sliderLyr" ${isFinalized ? 'disabled' : ''}>
                        </div>
                    </div>

                    <!-- Evaluation Action Controls -->
                    <div class="audition-actions-row">
                        <button type="button" class="btn btn-secondary btn-sm" id="btnAudReset" ${isFinalized ? 'disabled title="Finalized scorecards cannot be reset"' : ''} style="flex: 1;">
                            ✕ Reset Scorecard
                        </button>
                        ${!isFinalized ? `
                            <button type="button" class="btn btn-secondary btn-sm" id="btnAudLock" style="flex: 1; border-color: #c084fc; color: #c084fc;">
                                🔒 Lock Scorecard
                            </button>
                        ` : ''}
                        <button type="button" class="btn btn-primary btn-sm" id="btnAudSave" ${isFinalized ? 'disabled' : ''} style="flex: 1.5;">
                            ✓ Save Evaluation
                        </button>
                    </div>
                </div>
            </div>
        `;

        // Navigation
        document.getElementById('btnAudPrev').onclick = () => {
            if (state.activeAuditionIdx > 0) {
                state.activeAuditionIdx--;
                renderScreenAuditions();
            }
        };
        document.getElementById('btnAudNext').onclick = () => {
            if (state.activeAuditionIdx < SONGS_DATA.length - 1) {
                state.activeAuditionIdx++;
                renderScreenAuditions();
            }
        };

        // Live math & slider binding for active song
        if (!isFinalized) {
            const sliderEmo = document.getElementById('sliderEmo');
            const sliderRep = document.getElementById('sliderRep');
            const sliderMel = document.getElementById('sliderMel');
            const sliderVoc = document.getElementById('sliderVoc');
            const sliderLyr = document.getElementById('sliderLyr');

            const valEmo = document.getElementById('valEmo');
            const valRep = document.getElementById('valRep');
            const valMel = document.getElementById('valMel');
            const valVoc = document.getElementById('valVoc');
            const valLyr = document.getElementById('valLyr');
            const audTotalVal = document.getElementById('audTotalVal');

            function updateActiveScore() {
                const e = parseInt(sliderEmo.value, 10);
                const r = parseInt(sliderRep.value, 10);
                const m = parseInt(sliderMel.value, 10);
                const v = parseInt(sliderVoc.value, 10);
                const l = parseInt(sliderLyr.value, 10);

                evalData.emotion = e;
                evalData.replayability = r;
                evalData.melody = m;
                evalData.vocals = v;
                evalData.lyrics = l;

                const curTotal = e + r + m + v + l;
                song.audition_total = curTotal;
                evalData.status = (e > 0 && r > 0 && m > 0 && v > 0 && l > 0) ? 'completed' : 'incomplete';

                valEmo.textContent = e;
                valRep.textContent = r;
                valMel.textContent = m;
                valVoc.textContent = v;
                valLyr.textContent = l;
                audTotalVal.textContent = curTotal;
            }

            [sliderEmo, sliderRep, sliderMel, sliderVoc, sliderLyr].forEach(s => {
                s.oninput = updateActiveScore;
            });

            document.getElementById('btnAudSave').onclick = () => {
                showToast(`Scorecard saved for "${song.title}"! Total: ${song.audition_total}/50`);
                renderScreenAuditions();
            };

            const lockBtn = document.getElementById('btnAudLock');
            if (lockBtn) {
                lockBtn.onclick = () => {
                    evalData.status = 'finalized';
                    evalData.finalizedAt = 'Audit Room • In Session';
                    showToast(`🔒 Scorecard locked for "${song.title}". Now immutable.`);
                    renderScreenAuditions();
                };
            }

            document.getElementById('btnAudReset').onclick = () => {
                evalData.emotion = 0;
                evalData.replayability = 0;
                evalData.melody = 0;
                evalData.vocals = 0;
                evalData.lyrics = 0;
                evalData.status = 'untouched';
                song.audition_total = 0;
                showToast(`Scorecard reset to untouched for "${song.title}".`);
                renderScreenAuditions();
            };
        }

        document.getElementById('btnTogglePlayer').onclick = () => showToast(`Playing verified tournament audio: ${song.title}`);
        document.getElementById('btnToggleLyrics').onclick = () => showToast(`LRCLIB synced lyrics loaded for: ${song.title}`);
    }

    // ==========================================================================
    // 7. SCREEN 6: RECORDINGS (YouTube Matcher)
    // ==========================================================================

    function renderScreenRecordings() {
        elements.screenContent.innerHTML = `
            <div style="padding: 1rem;">
                <h2 style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.35rem;">YouTube Recording Matcher</h2>
                <p style="font-size: 0.78rem; color: var(--text-muted); margin-bottom: 1rem;">Automated 7-signal candidate ranking &amp; persistent link management.</p>

                <!-- Match Stats Strip -->
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 1rem;">
                    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.5rem; text-align: center;">
                        <span style="font-size: 0.65rem; color: var(--text-muted);">Total</span>
                        <div style="font-weight: 800; font-size: 1rem; color: var(--text-primary);">1,400</div>
                    </div>
                    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.5rem; text-align: center;">
                        <span style="font-size: 0.65rem; color: var(--success);">Matched</span>
                        <div style="font-weight: 800; font-size: 1rem; color: var(--success);">1,310</div>
                    </div>
                    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.5rem; text-align: center;">
                        <span style="font-size: 0.65rem; color: var(--gold);">Review</span>
                        <div style="font-weight: 800; font-size: 1rem; color: var(--gold);">42</div>
                    </div>
                </div>

                <!-- Resumable Batch Runner Simulation -->
                <div style="background: var(--bg-card); border: 1px solid var(--primary); border-radius: var(--radius-md); padding: 0.85rem; margin-bottom: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                        <span style="font-size: 0.8rem; font-weight: 700;">⚡ Resumable Bulk Matcher</span>
                        <span style="font-size: 0.7rem; color: var(--primary); font-family: var(--font-mono);">Active</span>
                    </div>
                    <div style="width: 100%; height: 6px; background: rgba(255,255,255,0.1); border-radius: var(--radius-full); overflow: hidden; margin-bottom: 0.5rem;">
                        <div style="width: 94%; height: 100%; background: var(--primary);"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.7rem; color: var(--text-muted);">
                        <span>Evaluating 7 weighted signals</span>
                        <span>1,310 / 1,400</span>
                    </div>
                </div>

                <!-- Candidates List -->
                <h3 style="font-size: 0.85rem; font-weight: 700; margin-bottom: 0.5rem;">Recent Discovered Candidates</h3>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    ${SONGS_DATA.slice(0, 5).map(s => `
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.65rem; display: flex; gap: 0.65rem; align-items: center;">
                            <img src="${s.artwork_url}" alt="" style="width: 48px; height: 48px; border-radius: var(--radius-xs); object-fit: cover;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 0.8rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(s.title)}</div>
                                <div style="font-size: 0.7rem; color: var(--text-muted);">${escapeHtml(s.version)}</div>
                                <div style="margin-top: 3px;">
                                    <span class="score-badge score-badge-complete" style="font-size: 0.65rem;">92% Match</span>
                                </div>
                            </div>
                            <button type="button" class="btn btn-xs btn-primary btn-link-yt" data-title="${escapeHtml(s.title)}">
                                ✓ Link
                            </button>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;

        elements.screenContent.querySelectorAll('.btn-link-yt').forEach(btn => {
            btn.onclick = () => showToast(`Confirmed link for ${btn.getAttribute('data-title')}`);
        });
    }

    // ==========================================================================
    // 8. SCREEN 7: TOURNAMENT (Top 200 Stage Board, 200 Contenders, Target 100)
    // ==========================================================================

    function renderScreenTournament() {
        const isFinalized = state.isTop200Finalized;
        const selectedCount = state.wizardSelectedIds.size;

        elements.screenContent.innerHTML = `
            <div class="tournament-board">
                <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.75rem;">
                    <div>
                        <span style="font-size: 0.68rem; font-weight: 800; color: var(--primary); text-transform: uppercase;">
                            STAGE 2 OF 7
                        </span>
                        <h2 style="font-size: 1.25rem; font-weight: 700;">Tournament Stage: Top 200</h2>
                    </div>
                    <span class="score-badge ${isFinalized ? '' : 'score-badge-complete'}" style="${isFinalized ? 'background:rgba(168,85,247,0.2);color:#c084fc;border:1px solid #c084fc;' : ''}">
                        ${isFinalized ? '🔒 Finalized &amp; Locked' : '● In Progress'}
                    </span>
                </div>

                <!-- Stage Target Statistics Summary -->
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 0.85rem;">
                    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.45rem; text-align: center;">
                        <span style="font-size: 0.65rem; color: var(--text-muted);">Available</span>
                        <div style="font-weight: 800; font-size: 0.95rem;">200</div>
                    </div>
                    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.45rem; text-align: center;">
                        <span style="font-size: 0.65rem; color: var(--primary);">Advancement Target</span>
                        <div style="font-weight: 800; font-size: 0.95rem; color: var(--primary);">100</div>
                    </div>
                    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.45rem; text-align: center;">
                        <span style="font-size: 0.65rem; color: var(--success);">Selected</span>
                        <div style="font-weight: 800; font-size: 0.95rem; color: var(--success);">${selectedCount}</div>
                    </div>
                </div>

                <!-- Launch Qualification Wizard Trigger -->
                ${!isFinalized ? `
                    <button type="button" class="btn btn-primary" id="btnLaunchWizard" style="width: 100%; margin-bottom: 0.85rem;">
                        <span>⚡</span> Launch Qualification Wizard (Top 200 &rarr; Top 100) &rarr;
                    </button>
                ` : `
                    <div style="background: rgba(168, 85, 247, 0.12); border: 1px solid rgba(168, 85, 247, 0.35); border-radius: var(--radius-sm); padding: 0.75rem; margin-bottom: 0.85rem; text-align: center;">
                        <div style="font-size: 0.8rem; font-weight: 800; color: #c084fc;">
                            🔒 Stage Top 200 Finalized &amp; Locked
                        </div>
                        <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 2px;">
                            Top 100 Stage initialized with 100 verified qualifiers. Historical ledger is immutable.
                        </div>
                        <button type="button" class="btn btn-secondary btn-xs" id="btnViewFinalizedRoster" style="margin-top: 0.5rem; width: 100%;">
                            View Locked 100-Qualifier Roster &rarr;
                        </button>
                    </div>
                `}

                <!-- Advisory Notice on 7-Field Ranking Sequence -->
                <div style="background: rgba(255, 255, 255, 0.04); border-left: 3px solid var(--primary); padding: 0.55rem 0.7rem; margin-bottom: 0.85rem; font-size: 0.72rem; line-height: 1.45; color: var(--text-secondary);">
                    <strong>Advisory Ranking Notice:</strong> Ranking sequence (Total /50 &rarr; Emotion &rarr; Replayability &rarr; Melody &rarr; Vocals &rarr; Lyrics &rarr; ID) is advisory. Advancement to Top 100 is manually selected by judges, not automatic.
                </div>

                <!-- Contenders Filter Strip -->
                <div style="display: flex; gap: 0.5rem; margin-bottom: 0.65rem;">
                    <input type="text" id="tournamentSearchInput" placeholder="Filter 200 contenders by title or artist..." value="${escapeHtml(state.tournamentSearch)}" style="flex: 1; background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: var(--radius-sm); padding: 0.4rem 0.65rem; font-size: 0.75rem;">
                </div>

                <!-- Leaderboard Rows -->
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    ${(() => {
                        let list = SONGS_DATA;
                        if (state.tournamentSearch.trim()) {
                            const q = state.tournamentSearch.toLowerCase();
                            list = list.filter(s => s.title.toLowerCase().includes(q) || s.artist.toLowerCase().includes(q));
                        }
                        return list.slice(0, 50).map(s => {
                            const isSelected = state.wizardSelectedIds.has(s.id);
                            return `
                                <div class="rank-card ${s.rank <= 3 ? 'rank-podium-1' : ''}" style="border-left: 3px solid ${isSelected ? 'var(--success)' : 'transparent'};">
                                    <div class="rank-num">
                                        ${s.rank === 1 ? '🥇 #1' : s.rank === 2 ? '🥈 #2' : s.rank === 3 ? '🥉 #3' : '#' + s.rank}
                                    </div>
                                    <div class="rank-song-info">
                                        <div class="rank-song-title">${escapeHtml(s.title)}</div>
                                        <div style="font-size: 0.7rem; color: var(--text-muted);">${escapeHtml(s.artist)} &bull; ${escapeHtml(s.genre)}</div>
                                        <div class="rank-criteria-strip">
                                            <span>E:${s.emotion}</span>
                                            <span>R:${s.replayability}</span>
                                            <span>M:${s.melody}</span>
                                            <span>V:${s.vocals}</span>
                                            <span>L:${s.lyrics}</span>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <div class="score-badge score-badge-complete font-mono">${s.audition_total}/50</div>
                                        <div style="margin-top: 3px;">
                                            ${isSelected ? `
                                                <span class="score-badge score-badge-complete" style="font-size: 0.6rem; padding: 2px 5px;">
                                                    ✓ Selected
                                                </span>
                                            ` : `
                                                <span class="score-badge" style="font-size: 0.6rem; padding: 2px 5px; background: rgba(255,255,255,0.06); color: var(--text-muted);">
                                                    Reserve
                                                </span>
                                            `}
                                        </div>
                                    </div>
                                </div>
                            `;
                        }).join('');
                    })()}
                </div>
                <div style="text-align: center; padding: 0.85rem; font-size: 0.72rem; color: var(--text-muted);">
                    Showing Top 50 of 200 Contenders &bull; Use search to filter full roster
                </div>
            </div>
        `;

        const tSearch = document.getElementById('tournamentSearchInput');
        if (tSearch) {
            tSearch.oninput = (e) => {
                state.tournamentSearch = e.target.value;
                renderScreenTournament();
            };
        }

        const launchBtn = document.getElementById('btnLaunchWizard');
        if (launchBtn) {
            launchBtn.onclick = () => {
                state.wizardStep = 1;
                switchScreen('screen-qualification');
            };
        }

        const viewRosterBtn = document.getElementById('btnViewFinalizedRoster');
        if (viewRosterBtn) {
            viewRosterBtn.onclick = () => {
                state.wizardStep = 3;
                switchScreen('screen-qualification');
            };
        }
    }

    // ==========================================================================
    // 9. SCREEN 8: QUALIFICATION WIZARD (Top 200 -> Top 100: 100 Target)
    // ==========================================================================

    function renderScreenQualification() {
        const step = state.wizardStep;
        const selectedCount = state.wizardSelectedIds.size;
        const targetMet = selectedCount === 100;
        const isFinalized = state.isTop200Finalized;

        elements.screenContent.innerHTML = `
            <div style="padding: 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <div>
                        <span style="font-size: 0.68rem; font-weight: 800; color: var(--primary);">MANUAL ADVANCEMENT WIZARD</span>
                        <h2 style="font-size: 1.15rem; font-weight: 700;">Top 200 &rarr; Top 100</h2>
                    </div>
                    <button type="button" class="btn btn-secondary btn-xs" id="btnCancelWizard">✕ Close</button>
                </div>

                <!-- Step Navigator -->
                <div class="wizard-step-indicator">
                    <div class="wizard-step-node ${step >= 1 ? 'active' : ''} ${step > 1 ? 'completed' : ''}">
                        1. Select (${selectedCount}/100)
                    </div>
                    <div class="wizard-step-node ${step >= 2 ? 'active' : ''} ${step > 2 ? 'completed' : ''}">
                        2. Arrange &amp; Replace
                    </div>
                    <div class="wizard-step-node ${step === 3 ? 'active' : ''}">
                        3. Review &amp; Lock
                    </div>
                </div>

                <!-- STEP 1: SELECT CANDIDATES (Target: Exactly 100 from 200) -->
                ${step === 1 ? `
                    <div>
                        <!-- Dynamic Target & Quota Banner -->
                        <div style="background: ${targetMet ? 'rgba(16, 185, 129, 0.12)' : 'rgba(245, 158, 11, 0.12)'}; border: 1px solid ${targetMet ? 'rgba(16, 185, 129, 0.35)' : 'rgba(245, 158, 11, 0.35)'}; border-radius: var(--radius-sm); padding: 0.65rem 0.8rem; margin-bottom: 0.85rem;">
                            <div style="font-size: 0.78rem; font-weight: 800; color: ${targetMet ? 'var(--success)' : 'var(--gold)'};">
                                ${targetMet ? '✓ Advancement Target Satisfied (100 of 100 Selected)' : `⚠️ Target Mismatch: ${selectedCount} of 100 Selected (${selectedCount < 100 ? `${100 - selectedCount} more required` : `${selectedCount - 100} excess must be removed`})`}
                            </div>
                            <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 2px;">
                                200 available contenders in Top 200 pool &bull; Exactly 100 must be designated for Top 100 progression.
                            </div>
                        </div>

                        <!-- Advisory Notice -->
                        <div style="font-size: 0.72rem; color: var(--text-muted); margin-bottom: 0.65rem;">
                            Advisory ranking shown for guidance. All 200 candidates are available for selection.
                        </div>

                        <!-- Quick Actions Bar -->
                        <div style="display: flex; gap: 0.4rem; margin-bottom: 0.65rem;">
                            <button type="button" class="btn btn-xs btn-secondary" id="btnSelectTop100Advisory" style="flex: 1;">
                                ⚡ Select Top 100 by Advisory Rank
                            </button>
                            <button type="button" class="btn btn-xs btn-secondary" id="btnClearSelection" style="flex: 0.6;">
                                ✕ Clear
                            </button>
                        </div>

                        <!-- Search & Filter Controls -->
                        <div style="display: flex; gap: 0.4rem; margin-bottom: 0.75rem;">
                            <input type="text" id="wizardSearchInput" placeholder="Filter candidates by name or artist..." value="${escapeHtml(state.wizardSearch)}" style="flex: 1; background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: var(--radius-sm); padding: 0.35rem 0.6rem; font-size: 0.75rem;">
                        </div>

                        <div style="display: flex; gap: 0.4rem; margin-bottom: 0.85rem;">
                            <button type="button" class="btn btn-xs ${state.wizardFilter === 'all' ? 'btn-primary' : 'btn-secondary'} btn-wiz-filter" data-filter="all">All (200)</button>
                            <button type="button" class="btn btn-xs ${state.wizardFilter === 'selected' ? 'btn-primary' : 'btn-secondary'} btn-wiz-filter" data-filter="selected">Selected (${selectedCount})</button>
                            <button type="button" class="btn btn-xs ${state.wizardFilter === 'unselected' ? 'btn-primary' : 'btn-secondary'} btn-wiz-filter" data-filter="unselected">Unselected (${200 - selectedCount})</button>
                        </div>

                        <!-- Candidate Checkbox List -->
                        <div style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 1rem; max-height: 48vh; overflow-y: auto;">
                            ${(() => {
                                let list = SONGS_DATA;
                                if (state.wizardFilter === 'selected') {
                                    list = list.filter(s => state.wizardSelectedIds.has(s.id));
                                } else if (state.wizardFilter === 'unselected') {
                                    list = list.filter(s => !state.wizardSelectedIds.has(s.id));
                                }
                                if (state.wizardSearch.trim()) {
                                    const q = state.wizardSearch.toLowerCase();
                                    list = list.filter(s => s.title.toLowerCase().includes(q) || s.artist.toLowerCase().includes(q));
                                }
                                return list.map(s => {
                                    const checked = state.wizardSelectedIds.has(s.id);
                                    const evalData = state.evaluations[s.id] || { status: 'completed' };
                                    return `
                                        <div style="background: var(--bg-card); border: 1px solid ${checked ? 'var(--primary)' : 'var(--border-color)'}; border-radius: var(--radius-sm); padding: 0.45rem 0.6rem; display: flex; align-items: center; gap: 0.65rem;">
                                            <input type="checkbox" class="wizard-checkbox" value="${s.id}" ${checked ? 'checked' : ''} ${isFinalized ? 'disabled' : ''} style="width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer;">
                                            <div style="flex: 1; min-width: 0;">
                                                <div style="font-size: 0.78rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    ${escapeHtml(s.title)}
                                                </div>
                                                <div style="font-size: 0.68rem; color: var(--text-muted);">
                                                    ${escapeHtml(s.artist)} &bull; ${s.audition_total}/50 &bull; ${escapeHtml(s.genre)}
                                                </div>
                                            </div>
                                            <div style="text-align: right;">
                                                <span class="score-badge score-badge-complete" style="font-size: 0.62rem; padding: 2px 5px;">
                                                    Rank #${s.rank}
                                                </span>
                                                <div style="font-size: 0.6rem; color: ${evalData.status === 'incomplete' ? 'var(--gold)' : 'var(--success)'}; margin-top: 2px;">
                                                    ${evalData.status === 'incomplete' ? '● Incomplete' : '✓ Evaluated'}
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                }).join('');
                            })()}
                        </div>

                        <!-- Step 1 Advance Button -->
                        <button type="button" class="btn btn-primary" id="btnWizardNextStep" ${!targetMet || isFinalized ? 'disabled style="opacity: 0.45; cursor: not-allowed;"' : ''} style="width: 100%;">
                            ${targetMet ? 'Proceed to Step 2: Arrange &amp; Replace (100 Selected) &rarr;' : `Select Exactly 100 Candidates to Proceed (${selectedCount}/100)`}
                        </button>
                    </div>
                ` : ''}

                <!-- STEP 2: ARRANGE & REPLACE -->
                ${step === 2 ? `
                    <div>
                        <div style="background: rgba(255, 255, 255, 0.04); border-left: 3px solid var(--primary); padding: 0.55rem 0.75rem; margin-bottom: 0.75rem; font-size: 0.72rem; line-height: 1.45;">
                            <strong>Manual Seed Arrangement:</strong> Move candidates up or down to set official seeds. Click ⇄ to swap in an eligible alternate from the 100 unselected pool.
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; font-size: 0.75rem; color: var(--text-muted);">
                            <span>Total Qualifiers: <strong>${state.wizardOrderedQualifiers.length}</strong></span>
                            <span>Advancement: Top 100 Stage</span>
                        </div>

                        <!-- Arranged Qualifiers List -->
                        <div style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 1rem; max-height: 50vh; overflow-y: auto;">
                            ${state.wizardOrderedQualifiers.map((s, idx) => `
                                <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.45rem 0.6rem; display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="font-family: var(--font-mono); font-size: 0.75rem; font-weight: 800; color: var(--primary); width: 28px;">
                                        #${idx + 1}
                                    </span>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-size: 0.78rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            ${escapeHtml(s.title)}
                                        </div>
                                        <div style="font-size: 0.68rem; color: var(--text-muted);">
                                            ${escapeHtml(s.artist)} &bull; ${s.audition_total}/50
                                        </div>
                                    </div>
                                    <div style="display: flex; gap: 3px;">
                                        <button type="button" class="btn btn-xs btn-secondary btn-order-up" data-idx="${idx}" ${idx === 0 || isFinalized ? 'disabled style="opacity:0.3;"' : ''} title="Move Up">▲</button>
                                        <button type="button" class="btn btn-xs btn-secondary btn-order-down" data-idx="${idx}" ${idx === state.wizardOrderedQualifiers.length - 1 || isFinalized ? 'disabled style="opacity:0.3;"' : ''} title="Move Down">▼</button>
                                        <button type="button" class="btn btn-xs btn-secondary btn-swap-candidate" data-idx="${idx}" ${isFinalized ? 'disabled style="opacity:0.3;"' : ''} style="color: var(--primary);" title="Replace Candidate">⇄</button>
                                    </div>
                                </div>
                            `).join('')}
                        </div>

                        <div style="display: flex; gap: 0.5rem;">
                            <button type="button" class="btn btn-secondary" id="btnWizardBackStep" style="flex: 1;">&larr; Back to Selection</button>
                            <button type="button" class="btn btn-primary" id="btnWizardToReview" style="flex: 1.5;">Review Checklist &rarr;</button>
                        </div>
                    </div>
                ` : ''}

                <!-- STEP 3: REVIEW & LOCK (IRREVERSIBLE COMMIT) -->
                ${step === 3 ? `
                    <div>
                        <!-- Irreversible Stage Finalization Warning -->
                        <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); border-radius: var(--radius-sm); padding: 0.75rem 0.85rem; margin-bottom: 0.85rem;">
                            <div style="font-size: 0.8rem; font-weight: 800; color: var(--danger);">⚠️ Irreversible Stage Finalization</div>
                            <div style="font-size: 0.72rem; color: var(--text-secondary); margin-top: 3px; line-height: 1.45;">
                                Committing will permanently lock the <strong>Top 200 stage</strong>. Evaluations and qualifier selections become immutable historical records. Top 100 stage will be initialized with these 100 qualifiers.
                            </div>
                        </div>

                        <!-- Verification Checklist -->
                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.75rem; margin-bottom: 0.85rem; font-size: 0.75rem; line-height: 1.65;">
                            <div style="font-weight: 700; margin-bottom: 0.35rem; color: var(--primary);">Roster Readiness Checklist:</div>
                            <div style="color: var(--success);">✓ Advancement Quota: Exactly 100 Qualifiers Selected (from 200 available)</div>
                            <div style="color: var(--success);">✓ Evaluation Integrity: All 100 Candidates Scored across 5 Criteria</div>
                            <div style="color: var(--success);">✓ Manual Seed Order: Seed #1 through Seed #100 Established</div>
                            <div style="color: var(--success);">✓ Advisory Tie-Breaking Record Prepared for Archive</div>
                            <div style="color: var(--success);">✓ Transition Audit Log #2026-T100-FINAL Generated</div>
                        </div>

                        <!-- Scrollable Preview of 100 Qualifiers -->
                        <div style="font-size: 0.75rem; font-weight: 700; margin-bottom: 0.35rem;">
                            Finalized Top 100 Roster Preview (${state.wizardOrderedQualifiers.length} entries):
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 4px; margin-bottom: 1rem; max-height: 28vh; overflow-y: auto; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.5rem;">
                            ${state.wizardOrderedQualifiers.map((s, idx) => `
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.72rem; padding: 2px 0; border-bottom: 1px solid rgba(255,255,255,0.04);">
                                    <span>
                                        <strong style="color: var(--primary); font-family: var(--font-mono); width: 25px; display: inline-block;">#${idx + 1}</strong>
                                        ${escapeHtml(s.title)} (${escapeHtml(s.artist)})
                                    </span>
                                    <span class="font-mono" style="color: var(--text-muted);">${s.audition_total}/50</span>
                                </div>
                            `).join('')}
                        </div>

                        <!-- Explicit Final Confirmation Checkbox -->
                        ${!isFinalized ? `
                            <div style="background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.65rem 0.75rem; margin-bottom: 1rem; display: flex; align-items: flex-start; gap: 0.5rem;">
                                <input type="checkbox" id="chkFinalLockConfirm" style="width: 18px; height: 18px; accent-color: var(--danger); cursor: pointer; margin-top: 2px;">
                                <label for="chkFinalLockConfirm" style="font-size: 0.72rem; color: var(--text-primary); cursor: pointer; line-height: 1.4;">
                                    I confirm that this 100-qualifier roster has been independently verified by tournament judges and is ready for permanent, immutable stage lock.
                                </label>
                            </div>

                            <div style="display: flex; gap: 0.5rem;">
                                <button type="button" class="btn btn-secondary" id="btnWizardBackToOrder" style="flex: 1;">&larr; Back to Order</button>
                                <button type="button" class="btn btn-primary" id="btnWizardCommit" disabled style="flex: 1.8; background: var(--danger); border-color: var(--danger); opacity: 0.45; cursor: not-allowed;">
                                    🔒 Commit &amp; Finalize Top 200
                                </button>
                            </div>
                        ` : `
                            <div style="background: rgba(168, 85, 247, 0.15); border: 1px solid rgba(168, 85, 247, 0.4); border-radius: var(--radius-sm); padding: 0.75rem; text-align: center; margin-bottom: 0.5rem;">
                                <div style="font-size: 0.85rem; font-weight: 800; color: #c084fc;">
                                    🔒 Stage Top 200 Finalized &amp; Locked
                                </div>
                                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 2px;">
                                    Official transition record archived. Ordinary editing is disabled.
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary" id="btnReturnToBoard" style="width: 100%;">
                                &larr; Return to Tournament Board
                            </button>
                        `}
                    </div>
                ` : ''}

                <!-- CANDIDATE REPLACEMENT MODAL (STEP 2) -->
                ${state.wizardReplaceTargetIdx !== null ? `
                    <div class="modal-backdrop-sheet" id="modalReplaceCandidate">
                        <div class="modal-sheet-inner">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem;">
                                <div>
                                    <span style="font-size: 0.65rem; font-weight: 800; color: var(--primary);">SWAP CANDIDATE</span>
                                    <h3 style="font-size: 0.95rem; font-weight: 700;">Replace Seed #${state.wizardReplaceTargetIdx + 1}</h3>
                                </div>
                                <button type="button" class="btn btn-secondary btn-xs" id="btnCloseReplaceModal">✕</button>
                            </div>

                            <div style="font-size: 0.72rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                                Select an eligible alternate from the 100 unselected contenders in the Top 200 pool:
                            </div>

                            <input type="text" id="inputReplaceSearch" placeholder="Filter available alternates..." value="${escapeHtml(state.wizardReplaceSearch)}" style="background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: var(--radius-sm); padding: 0.4rem 0.65rem; font-size: 0.75rem; margin-bottom: 0.65rem;">

                            <div style="display: flex; flex-direction: column; gap: 6px; overflow-y: auto; max-height: 45vh; margin-bottom: 0.5rem;">
                                ${(() => {
                                    let alternates = SONGS_DATA.filter(s => !state.wizardSelectedIds.has(s.id));
                                    if (state.wizardReplaceSearch.trim()) {
                                        const q = state.wizardReplaceSearch.toLowerCase();
                                        alternates = alternates.filter(s => s.title.toLowerCase().includes(q) || s.artist.toLowerCase().includes(q));
                                    }
                                    return alternates.map(alt => `
                                        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.5rem 0.65rem; display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <div style="font-size: 0.78rem; font-weight: 700;">${escapeHtml(alt.title)}</div>
                                                <div style="font-size: 0.68rem; color: var(--text-muted);">${escapeHtml(alt.artist)} &bull; Rank #${alt.rank} &bull; ${alt.audition_total}/50</div>
                                            </div>
                                            <button type="button" class="btn btn-xs btn-primary btn-confirm-swap" data-alt-id="${alt.id}">
                                                ✓ Swap In
                                            </button>
                                        </div>
                                    `).join('');
                                })()}
                            </div>
                        </div>
                    </div>
                ` : ''}
            </div>
        `;

        // Event bindings
        document.getElementById('btnCancelWizard').onclick = () => {
            state.wizardStep = 1;
            switchScreen('screen-tournament');
        };

        // Step 1 Events
        if (step === 1 && !isFinalized) {
            elements.screenContent.querySelectorAll('.wizard-checkbox').forEach(cb => {
                cb.onchange = () => {
                    const id = parseInt(cb.value, 10);
                    if (cb.checked) state.wizardSelectedIds.add(id);
                    else state.wizardSelectedIds.delete(id);
                    renderScreenQualification();
                };
            });

            elements.screenContent.querySelectorAll('.btn-wiz-filter').forEach(btn => {
                btn.onclick = () => {
                    state.wizardFilter = btn.getAttribute('data-filter');
                    renderScreenQualification();
                };
            });

            const searchInput = document.getElementById('wizardSearchInput');
            if (searchInput) {
                searchInput.oninput = (e) => {
                    state.wizardSearch = e.target.value;
                    renderScreenQualification();
                };
            }

            document.getElementById('btnSelectTop100Advisory').onclick = () => {
                state.wizardSelectedIds = new Set(SONGS_DATA.slice(0, 100).map(s => s.id));
                showToast(`Selected Top 100 contenders by advisory 7-field ranking`);
                renderScreenQualification();
            };

            document.getElementById('btnClearSelection').onclick = () => {
                state.wizardSelectedIds.clear();
                renderScreenQualification();
            };

            const nextBtn = document.getElementById('btnWizardNextStep');
            if (nextBtn && targetMet) {
                nextBtn.onclick = () => {
                    // Populate ordered qualifiers strictly from the selected set
                    state.wizardOrderedQualifiers = SONGS_DATA.filter(s => state.wizardSelectedIds.has(s.id));
                    state.wizardStep = 2;
                    renderScreenQualification();
                };
            }
        }

        // Step 2 Events
        if (step === 2 && !isFinalized) {
            elements.screenContent.querySelectorAll('.btn-order-up').forEach(btn => {
                btn.onclick = () => {
                    const idx = parseInt(btn.getAttribute('data-idx'), 10);
                    if (idx > 0) {
                        const temp = state.wizardOrderedQualifiers[idx];
                        state.wizardOrderedQualifiers[idx] = state.wizardOrderedQualifiers[idx - 1];
                        state.wizardOrderedQualifiers[idx - 1] = temp;
                        showToast(`Moved "${temp.title}" up to Seed #${idx}`);
                        renderScreenQualification();
                    }
                };
            });

            elements.screenContent.querySelectorAll('.btn-order-down').forEach(btn => {
                btn.onclick = () => {
                    const idx = parseInt(btn.getAttribute('data-idx'), 10);
                    if (idx < state.wizardOrderedQualifiers.length - 1) {
                        const temp = state.wizardOrderedQualifiers[idx];
                        state.wizardOrderedQualifiers[idx] = state.wizardOrderedQualifiers[idx + 1];
                        state.wizardOrderedQualifiers[idx + 1] = temp;
                        showToast(`Moved "${temp.title}" down to Seed #${idx + 2}`);
                        renderScreenQualification();
                    }
                };
            });

            elements.screenContent.querySelectorAll('.btn-swap-candidate').forEach(btn => {
                btn.onclick = () => {
                    const idx = parseInt(btn.getAttribute('data-idx'), 10);
                    state.wizardReplaceTargetIdx = idx;
                    state.wizardReplaceSearch = '';
                    renderScreenQualification();
                };
            });

            document.getElementById('btnWizardBackStep').onclick = () => {
                state.wizardStep = 1;
                renderScreenQualification();
            };

            document.getElementById('btnWizardToReview').onclick = () => {
                state.wizardStep = 3;
                renderScreenQualification();
            };
        }

        // Replacement Modal Events
        if (state.wizardReplaceTargetIdx !== null) {
            const closeM = document.getElementById('btnCloseReplaceModal');
            if (closeM) {
                closeM.onclick = () => {
                    state.wizardReplaceTargetIdx = null;
                    renderScreenQualification();
                };
            }
            const sInput = document.getElementById('inputReplaceSearch');
            if (sInput) {
                sInput.oninput = (e) => {
                    state.wizardReplaceSearch = e.target.value;
                    renderScreenQualification();
                };
            }
            elements.screenContent.querySelectorAll('.btn-confirm-swap').forEach(btn => {
                btn.onclick = () => {
                    const altId = parseInt(btn.getAttribute('data-alt-id'), 10);
                    const newSong = SONGS_DATA.find(x => x.id === altId);
                    const targetIdx = state.wizardReplaceTargetIdx;
                    const oldSong = state.wizardOrderedQualifiers[targetIdx];

                    if (newSong && oldSong) {
                        state.wizardSelectedIds.delete(oldSong.id);
                        state.wizardSelectedIds.add(newSong.id);
                        state.wizardOrderedQualifiers[targetIdx] = newSong;

                        showToast(`Replaced "${oldSong.title}" with "${newSong.title}" at Seed #${targetIdx + 1}`);
                    }
                    state.wizardReplaceTargetIdx = null;
                    renderScreenQualification();
                };
            });
        }

        // Step 3 Events
        if (step === 3) {
            if (!isFinalized) {
                document.getElementById('btnWizardBackToOrder').onclick = () => {
                    state.wizardStep = 2;
                    renderScreenQualification();
                };

                const chkConfirm = document.getElementById('chkFinalLockConfirm');
                const btnCommit = document.getElementById('btnWizardCommit');
                if (chkConfirm && btnCommit) {
                    chkConfirm.onchange = () => {
                        btnCommit.disabled = !chkConfirm.checked;
                        btnCommit.style.opacity = chkConfirm.checked ? '1' : '0.45';
                        btnCommit.style.cursor = chkConfirm.checked ? 'pointer' : 'not-allowed';
                    };
                    btnCommit.onclick = () => {
                        state.isTop200Finalized = true;
                        state.finalizedTop100Roster = [...state.wizardOrderedQualifiers];
                        showToast(`🎉 Top 200 Finalized! 100 Qualifiers advanced to Top 100.`);
                        switchScreen('screen-tournament');
                    };
                }
            } else {
                const retBtn = document.getElementById('btnReturnToBoard');
                if (retBtn) {
                    retBtn.onclick = () => switchScreen('screen-tournament');
                }
            }
        }
    }

    // ==========================================================================
    // 10. SCREEN 9: ARCHIVE (CANONICAL 7 TOURNAMENT STAGES & READ-ONLY RECORDS)
    // ==========================================================================

    function renderScreenArchive() {
        elements.screenContent.innerHTML = `
            <div style="padding: 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.75rem;">
                    <div>
                        <span style="font-size: 0.68rem; font-weight: 800; color: var(--primary);">CANONICAL 7-STAGE TOURNAMENT PROGRESSION</span>
                        <h2 style="font-size: 1.25rem; font-weight: 700;">Tournament Archive</h2>
                    </div>
                    <span class="score-badge font-mono" style="font-size: 0.65rem;">Historical</span>
                </div>

                <p style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Immutable historical records. Each stage's evaluations and qualification decisions are permanently locked.
                </p>

                <!-- Champion Banner Shortcut -->
                <div class="champion-banner-shortcut" id="cardArchiveChampion">
                    <span style="font-size: 1.5rem;">👑</span>
                    <div style="flex: 1; min-width: 0;">
                        <span style="font-size: 0.68rem; font-weight: 800; color: #fbbf24; text-transform: uppercase;">
                            Reigning Champion &bull; Finals Stage Result
                        </span>
                        <div style="font-size: 0.88rem; font-weight: 800; color: white;">Bohemian Rhapsody</div>
                        <div style="font-size: 0.7rem; color: #e2e8f0;">Queen &bull; Final Score: 50/50</div>
                    </div>
                    <span style="font-size: 0.75rem; color: #fbbf24;">Inspect &rarr;</span>
                </div>

                <!-- 7 Canonical Stages Historical Ledger -->
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <!-- Stage 7: Finals -->
                    <div class="archive-stage-row">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span class="archive-stage-badge">Stage 7: Finals</span>
                                <h4 style="font-size: 0.85rem; font-weight: 700; margin-top: 2px;">10 Contenders &rarr; 1 Champion</h4>
                            </div>
                            <span class="score-badge font-mono" id="chipArchChamp" style="cursor: pointer;">👑 Bohemian Rhapsody</span>
                        </div>
                        <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 4px;">
                            Authoritative final score 50/50 &bull; Read-only historical ledger
                        </div>
                    </div>

                    <!-- Stage 6: Top 10 -->
                    <div class="archive-stage-row">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span class="archive-stage-badge">Stage 6: Top 10</span>
                                <h4 style="font-size: 0.85rem; font-weight: 700; margin-top: 2px;">20 Contenders &rarr; 10 Qualifiers</h4>
                            </div>
                            <span class="score-badge score-badge-complete">Completed</span>
                        </div>
                        <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 4px;">
                            10 finalists selected &bull; Read-only tie-breaker audit
                        </div>
                    </div>

                    <!-- Stage 5: Top 20 -->
                    <div class="archive-stage-row">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span class="archive-stage-badge">Stage 5: Top 20</span>
                                <h4 style="font-size: 0.85rem; font-weight: 700; margin-top: 2px;">50 Contenders &rarr; 20 Qualifiers</h4>
                            </div>
                            <span class="score-badge score-badge-complete">Completed</span>
                        </div>
                    </div>

                    <!-- Stage 4: Top 50 -->
                    <div class="archive-stage-row">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span class="archive-stage-badge">Stage 4: Top 50</span>
                                <h4 style="font-size: 0.85rem; font-weight: 700; margin-top: 2px;">100 Contenders &rarr; 50 Qualifiers</h4>
                            </div>
                            <span class="score-badge score-badge-complete">Completed</span>
                        </div>
                    </div>

                    <!-- Stage 3: Top 100 -->
                    <div class="archive-stage-row">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span class="archive-stage-badge">Stage 3: Top 100</span>
                                <h4 style="font-size: 0.85rem; font-weight: 700; margin-top: 2px;">200 Contenders &rarr; 100 Qualifiers</h4>
                            </div>
                            <span class="score-badge score-badge-complete">Completed</span>
                        </div>
                    </div>

                    <!-- Stage 2: Top 200 -->
                    <div class="archive-stage-row">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span class="archive-stage-badge">Stage 2: Top 200</span>
                                <h4 style="font-size: 0.85rem; font-weight: 700; margin-top: 2px;">1,400 Auditions &rarr; 200 Qualifiers</h4>
                            </div>
                            <span class="score-badge score-badge-complete">Completed</span>
                        </div>
                    </div>

                    <!-- Stage 1: Formal Auditions -->
                    <div class="archive-stage-row">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span class="archive-stage-badge">Stage 1: Formal Auditions</span>
                                <h4 style="font-size: 0.85rem; font-weight: 700; margin-top: 2px;">1,400 Total Track Evaluations</h4>
                            </div>
                            <span class="score-badge score-badge-complete">Completed</span>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('cardArchiveChampion').onclick = () => switchScreen('screen-champion');
        document.getElementById('chipArchChamp').onclick = () => switchScreen('screen-champion');
    }

    // ==========================================================================
    // 11. SCREEN 10: CHAMPION PRESENTATION (Crown Awarded in Finals Stage)
    // ==========================================================================

    function renderScreenChampion() {
        const champ = SONGS_DATA[0];

        elements.screenContent.innerHTML = `
            <div style="padding: 1.25rem; text-align: center;">
                <div style="margin-bottom: 0.75rem;">
                    <span style="font-size: 2.25rem;">👑</span>
                </div>
                <span style="font-size: 0.7rem; font-weight: 800; color: #fbbf24; text-transform: uppercase; letter-spacing: 0.08em;">
                    TOURNAMENT CHAMPION
                </span>
                <h1 style="font-size: 1.55rem; font-weight: 800; margin: 0.25rem 0 0.15rem 0;">
                    ${escapeHtml(champ.title)}
                </h1>
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.25rem;">
                    ${escapeHtml(champ.artist)} &bull; ${escapeHtml(champ.genre)}
                </div>

                <!-- Halo Album Frame -->
                <div class="champion-halo-frame">
                    <img src="${champ.artwork_url}" alt="${escapeHtml(champ.title)}" class="champion-halo-img">
                </div>

                <!-- Finals Stage Official Scorecard -->
                <div class="champion-scorecard-card">
                    <div style="font-size: 0.72rem; color: #fbbf24; font-weight: 800; text-transform: uppercase; margin-bottom: 0.25rem;">
                        Official Finals Round Scorecard
                    </div>
                    <div style="font-size: 2rem; font-weight: 900; font-family: var(--font-mono); color: white;">
                        50 <span style="font-size: 1rem; color: rgba(255,255,255,0.5);">/ 50</span>
                    </div>

                    <div style="display: flex; justify-content: center; gap: 0.35rem; margin-top: 0.75rem; flex-wrap: wrap;">
                        <span class="champion-tag">Emotion: 10</span>
                        <span class="champion-tag">Replayability: 10</span>
                        <span class="champion-tag">Melody: 10</span>
                        <span class="champion-tag">Vocals: 10</span>
                        <span class="champion-tag">Lyrics: 10</span>
                    </div>

                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 1.25rem; line-height: 1.45;">
                        Crown decided in <strong>Finals Stage</strong> (1 Champion selected from 10 Contenders) &bull; Verified Canonical 7-Stage Progression
                    </div>
                </div>

                <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                    <button type="button" class="btn btn-secondary btn-sm" id="btnChampBack" style="flex: 1;">
                        &larr; Back to Archive
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" id="btnChampPlay" style="flex: 1;">
                        <span>▶</span> Play Winner
                    </button>
                </div>
            </div>
        `;

        document.getElementById('btnChampBack').onclick = () => switchScreen('screen-archive');
        document.getElementById('btnChampPlay').onclick = () => showToast(`Playing 2026 Champion: ${champ.title} (${champ.artist})`);
    }

    // ==========================================================================
    // 12. UTILITY HELPERS & DOM BOOTSTRAP
    // ==========================================================================

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showToast(message) {
        if (!elements.toastContainer) return;
        const toast = document.createElement('div');
        toast.className = 'prototype-toast';
        toast.textContent = message;
        elements.toastContainer.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-10px)';
            toast.style.transition = 'all 0.25s ease';
            setTimeout(() => toast.remove(), 250);
        }, 2200);
    }

    // Run initialization on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
