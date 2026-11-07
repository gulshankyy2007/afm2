<?php
/**
 * AFM#2 - Main Layout Header
 */

if (!defined('AFM_APP')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/preferences_helper.php';

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isLoggedIn = is_authenticated();
$currentUsername = get_current_username();
$csrfToken = get_csrf_token();
$pageTitle = $pageTitle ?? 'AFM#2 — Personal Music Journal & Tournament 2026';

// Load user preferences for theme, background, and player layout
$userPrefs = get_user_preferences();
$currentTheme = htmlspecialchars($userPrefs['theme'] ?? 'midnight_ember', ENT_QUOTES, 'UTF-8');
$currentBgStyle = htmlspecialchars($userPrefs['bg_style'] ?? 'atmospheric', ENT_QUOTES, 'UTF-8');
$currentPlayerLayout = htmlspecialchars($userPrefs['player_layout'] ?? 'expanded', ENT_QUOTES, 'UTF-8');
$currentBgOpacity = (int)($userPrefs['bg_overlay_opacity'] ?? 70);
$bgOverlayDecimal = number_format($currentBgOpacity / 100, 2, '.', '');
?>
<!DOCTYPE html>
<html lang="en" 
      data-theme="<?= $currentTheme ?>" 
      data-bg-style="<?= $currentBgStyle ?>" 
      data-player-layout="<?= $currentPlayerLayout ?>"
      style="--bg-overlay-opacity: <?= $bgOverlayDecimal ?>;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($pageDescription ?? 'AFM#2 Personal Music Catalogue, Audition, and Tournament Web Application for Annual Musical Finale 2026.', ENT_QUOTES, 'UTF-8') ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <?php if (defined('GOOGLE_SITE_VERIFICATION') && GOOGLE_SITE_VERIFICATION): ?>
    <meta name="google-site-verification" content="<?= htmlspecialchars(GOOGLE_SITE_VERIFICATION, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>

    <!-- Canonical URL & Search Metadata -->
    <?php $canonicalUrl = get_base_url() . '/' . ($currentPage === 'index.php' ? '' : htmlspecialchars($currentPage, ENT_QUOTES, 'UTF-8')); ?>
    <link rel="canonical" href="<?= $canonicalUrl ?>">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= $canonicalUrl ?>">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDescription ?? 'AFM#2 — Personal Music Journal and Audition Tournament for Annual Musical Finale 2026.', ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image" content="<?= get_base_url() ?>/assets/img/afm2-logo.png">
    <meta property="og:site_name" content="AFM#2 — Annual Musical Finale 2026">

    <!-- Twitter Cards -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDescription ?? 'AFM#2 — Personal Music Journal and Audition Tournament for Annual Musical Finale 2026.', ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:image" content="<?= get_base_url() ?>/assets/img/afm2-logo.png">

    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <!-- Favicon & Touch Icons (Approved Compact AFM#2 Symbol) -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/afm2-favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/afm2-favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="48x48" href="assets/img/afm2-favicon-48x48.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/img/afm2-favicon-192x192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png">

    <!-- PWA & Standalone Mobile Web App Capabilities -->
    <link rel="manifest" href="manifest.json" crossorigin="use-credentials">
    <meta name="theme-color" content="#0c0e14">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="AFM#2">

    <?php
    $styleFile = dirname(__DIR__) . '/public/assets/css/style.css';
    $styleVer = '4.1.' . (file_exists($styleFile) ? filemtime($styleFile) : '20261029');
    $mobileFile = dirname(__DIR__) . '/public/assets/css/mobile.css';
    $mobileVer = '4.1.' . (file_exists($mobileFile) ? filemtime($mobileFile) : '20261029');
    ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $styleVer ?>">
    <link rel="stylesheet" href="assets/css/mobile.css?v=<?= $mobileVer ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@400;600&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Outfit:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
</head>
<body class="<?= $isLoggedIn ? 'has-mobile-dock' : '' ?>">
    <!-- Cinematic Atmosphere & Mood Layers -->
    <div class="cinematic-backdrop" id="cinematicBackdrop" aria-hidden="true">
        <div class="cinematic-bg-image" id="cinematicBgImage"></div>
        <div class="cinematic-vignette"></div>
        <div class="cinematic-grain"></div>
    </div>

    <header class="app-header" id="appHeader">
        <div class="header-inner">
            <div class="brand-area">
                <a href="index.php" class="brand-logo" id="navLogo" aria-label="AFM#2 — Annual Musical Finale 2026">
                    <img src="assets/img/afm2-logo.png" 
                         alt="AFM#2 — Annual Musical Finale" 
                         class="brand-logo-img" 
                         width="81" 
                         height="44"
                         decoding="async">
                    <span class="badge-year">2026</span>
                </a>
            </div>

            <!-- One Borderless Animated Grand Piano Composition (Frequency Waves, Keypresses & Emerging Notes) -->
            <div class="header-music-strip header-piano-stage state-idle" id="headerMusicStrip" aria-hidden="true" title="AFM#2 Grand Piano (Live)">
                <div class="piano-stage-inner">
                    <svg class="piano-live-svg" viewBox="0 0 228 44" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
                        <defs>
                            <!-- Piano Ivory Key Gradient -->
                            <linearGradient id="pianoIvory" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#fdfdfd"/>
                                <stop offset="85%" stop-color="#f3f4f6"/>
                                <stop offset="100%" stop-color="#e5e7eb"/>
                            </linearGradient>
                            <!-- Piano Ivory Key Pressed Gradient -->
                            <linearGradient id="pianoIvoryPressed" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#e5e7eb"/>
                                <stop offset="80%" stop-color="#d1d5db"/>
                                <stop offset="100%" stop-color="#9ca3af"/>
                            </linearGradient>
                            <!-- Piano Ebony Key Gradient -->
                            <linearGradient id="pianoEbony" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#2d3748"/>
                                <stop offset="25%" stop-color="#1a202c"/>
                                <stop offset="85%" stop-color="#0f172a"/>
                                <stop offset="100%" stop-color="#020617"/>
                            </linearGradient>
                            <!-- Fallboard Mahogany Finish -->
                            <linearGradient id="pianoFallboard" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#18121f"/>
                                <stop offset="35%" stop-color="#2a1810"/>
                                <stop offset="65%" stop-color="#3b1d11"/>
                                <stop offset="100%" stop-color="#18121f"/>
                            </linearGradient>
                            <!-- Frequency Wave Gradients -->
                            <linearGradient id="freqWaveGold" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#f59e0b" stop-opacity="0.8"/>
                                <stop offset="50%" stop-color="#fbbf24" stop-opacity="0.95"/>
                                <stop offset="100%" stop-color="#e0a96d" stop-opacity="0.1"/>
                            </linearGradient>
                            <linearGradient id="freqWaveCyan" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#06b6d4" stop-opacity="0.7"/>
                                <stop offset="50%" stop-color="#38bdf8" stop-opacity="0.9"/>
                                <stop offset="100%" stop-color="#818cf8" stop-opacity="0.1"/>
                            </linearGradient>
                            <filter id="keyGlow" x="-20%" y="-20%" width="140%" height="140%">
                                <feGaussianBlur stdDeviation="2" result="blur"/>
                                <feComposite in="SourceGraphic" in2="blur" operator="over"/>
                            </filter>
                        </defs>

                        <!-- Frequency Wave Undulations (Above and extending across keys) -->
                        <g class="piano-freq-group">
                            <path class="piano-freq-wave wave-1" d="M 6,10 Q 55,2 105,9 T 165,8 T 226,9" fill="none" stroke="url(#freqWaveGold)" stroke-width="1.6" stroke-linecap="round"/>
                            <path class="piano-freq-wave wave-2" d="M 6,12 Q 60,16 115,8 T 175,13 T 226,10" fill="none" stroke="url(#freqWaveCyan)" stroke-width="1.2" stroke-linecap="round"/>
                            <path class="piano-freq-wave wave-3" d="M 8,7 Q 70,3 130,8 T 224,6" fill="none" stroke="rgba(245,158,11,0.4)" stroke-width="0.8" stroke-dasharray="2 3"/>
                        </g>

                        <!-- Piano Keyboard Assembly -->
                        <g class="piano-keyboard-group">
                            <!-- Background Keybed Base Shadow -->
                            <rect class="piano-keybed" x="6" y="12" width="213" height="30" rx="2" fill="#09070d" stroke="rgba(245,158,11,0.25)" stroke-width="0.75"/>
                            
                            <!-- Piano Fallboard with Gold Lip Accent -->
                            <rect class="piano-fallboard" x="6" y="10" width="213" height="4" rx="1" fill="url(#pianoFallboard)"/>
                            <line class="piano-gold-lip" x1="6" y1="14" x2="219" y2="14" stroke="#f59e0b" stroke-width="0.6" stroke-opacity="0.7"/>

                            <!-- 14 White Ivory Keys (2 Octaves: C3 through B4) -->
                            <!-- Octave 1 -->
                            <rect class="piano-key-white key-c1" x="8" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>
                            <rect class="piano-key-white key-d1" x="23" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>
                            <rect class="piano-key-white key-e1" x="38" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>
                            <rect class="piano-key-white key-f1" x="53" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>
                            <rect class="piano-key-white key-g1" x="68" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>
                            <rect class="piano-key-white key-a1" x="83" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>
                            <rect class="piano-key-white key-b1" x="98" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>

                            <!-- Octave 2 -->
                            <rect class="piano-key-white key-c2" x="113" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>
                            <rect class="piano-key-white key-d2" x="128" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>
                            <rect class="piano-key-white key-e2" x="143" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>
                            <rect class="piano-key-white key-f2" x="158" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>
                            <rect class="piano-key-white key-g2" x="173" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>
                            <rect class="piano-key-white key-a2" x="188" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>
                            <rect class="piano-key-white key-b2" x="203" y="14" width="14.5" height="26" rx="1.5" fill="url(#pianoIvory)" stroke="#9ca3af" stroke-width="0.4"/>

                            <!-- 10 Black Ebony Keys (Groups of 2 and 3) -->
                            <!-- Octave 1: Group of 2 (C#3, D#3) -->
                            <rect class="piano-key-black key-cs1" x="18" y="14" width="9" height="16" rx="1" fill="url(#pianoEbony)" stroke="#0f172a" stroke-width="0.4"/>
                            <rect class="piano-key-black key-ds1" x="33" y="14" width="9" height="16" rx="1" fill="url(#pianoEbony)" stroke="#0f172a" stroke-width="0.4"/>

                            <!-- Octave 1: Group of 3 (F#3, G#3, A#3) -->
                            <rect class="piano-key-black key-fs1" x="63" y="14" width="9" height="16" rx="1" fill="url(#pianoEbony)" stroke="#0f172a" stroke-width="0.4"/>
                            <rect class="piano-key-black key-gs1" x="78" y="14" width="9" height="16" rx="1" fill="url(#pianoEbony)" stroke="#0f172a" stroke-width="0.4"/>
                            <rect class="piano-key-black key-as1" x="93" y="14" width="9" height="16" rx="1" fill="url(#pianoEbony)" stroke="#0f172a" stroke-width="0.4"/>

                            <!-- Octave 2: Group of 2 (C#4, D#4) -->
                            <rect class="piano-key-black key-cs2" x="123" y="14" width="9" height="16" rx="1" fill="url(#pianoEbony)" stroke="#0f172a" stroke-width="0.4"/>
                            <rect class="piano-key-black key-ds2" x="138" y="14" width="9" height="16" rx="1" fill="url(#pianoEbony)" stroke="#0f172a" stroke-width="0.4"/>

                            <!-- Octave 2: Group of 3 (F#4, G#4, A#4) -->
                            <rect class="piano-key-black key-fs2" x="168" y="14" width="9" height="16" rx="1" fill="url(#pianoEbony)" stroke="#0f172a" stroke-width="0.4"/>
                            <rect class="piano-key-black key-gs2" x="183" y="14" width="9" height="16" rx="1" fill="url(#pianoEbony)" stroke="#0f172a" stroke-width="0.4"/>
                            <rect class="piano-key-black key-as2" x="198" y="14" width="9" height="16" rx="1" fill="url(#pianoEbony)" stroke="#0f172a" stroke-width="0.4"/>
                        </g>

                        <!-- Musical Notes Emerging from Played Keys (Traveling gracefully into header) -->
                        <g class="piano-notes-group">
                            <text class="piano-emerging-note pnote-1" x="25" y="16">♪</text>
                            <text class="piano-emerging-note pnote-2" x="72" y="14">♫</text>
                            <text class="piano-emerging-note pnote-3" x="118" y="18">♬</text>
                            <text class="piano-emerging-note pnote-4" x="148" y="15">♩</text>
                            <text class="piano-emerging-note pnote-5" x="175" y="16">𝄞</text>
                            <text class="piano-emerging-note pnote-6" x="205" y="14">♪</text>
                        </g>
                    </svg>
                </div>
            </div>

            <?php if ($isLoggedIn): ?>
            <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation menu" aria-controls="appNav" aria-expanded="false">
                <span class="bar"></span>
                <span class="bar"></span>
                <span class="bar"></span>
            </button>

            <nav class="app-nav" id="appNav">
                <div class="mobile-user-card" id="mobileUserCard">
                    <a href="account.php" class="mobile-user-info" title="Signed in as <?= htmlspecialchars($currentUsername ?? '', ENT_QUOTES, 'UTF-8') ?> — Account Security" style="display:flex;align-items:center;gap:0.5rem;text-decoration:none;">
                        <span class="status-indicator online"></span>
                        <span class="mobile-user-name"><?= htmlspecialchars($currentUsername ?? 'User', ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="mobile-role-badge <?= is_admin() ? 'badge-admin' : 'badge-judge' ?>" style="display:inline-flex;align-items:center;">
                            <?= is_admin() ? 'Admin' : 'Judge' ?>
                        </span>
                    </a>
                    <form action="logout.php" method="POST" class="mobile-logout-form" style="margin:0;padding:0;display:flex;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn-mobile-signout btn-logout" id="btnMobileSignout" title="Sign out of AFM#2">Sign Out</button>
                    </form>
                </div>

                <ul class="nav-links">
                    <li><a href="index.php" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>" id="navDashboard">Journal</a></li>
                    <li><a href="library.php" class="<?= $currentPage === 'library.php' ? 'active' : '' ?>" id="navLibrary">Library</a></li>
                    <li><a href="recordings.php" class="<?= $currentPage === 'recordings.php' ? 'active' : '' ?>" id="navRecordings">Recordings</a></li>
                    <li><a href="discovery.php" class="<?= $currentPage === 'discovery.php' ? 'active' : '' ?>" id="navDiscovery">Shortlist</a></li>
                    <li><a href="auditions.php" class="<?= $currentPage === 'auditions.php' ? 'active' : '' ?>" id="navAuditions">Auditions</a></li>
                    <li><a href="tournament.php" class="<?= $currentPage === 'tournament.php' ? 'active' : '' ?>" id="navTournament">Tournament</a></li>
                    <li><a href="results.php" class="<?= $currentPage === 'results.php' ? 'active' : '' ?>" id="navResults">Archive</a></li>
                    <?php if (is_admin()): ?>
                    <li><a href="users.php" class="<?= $currentPage === 'users.php' ? 'active' : '' ?>" id="navUsers">Users</a></li>
                    <?php endif; ?>
                </ul>

                <div class="user-meta">
                    <button type="button" class="btn-nav-appearance" id="btnOpenAppearance" title="Appearance & Themes (Midnight Ember, Vintage Paper, Oceanic, Quiet Minimal)">
                        <span class="appearance-icon">🎨</span>
                        <span class="appearance-text">Appearance</span>
                    </button>
                    <a href="account.php" class="user-pill desktop-user-pill" title="Signed in as <?= htmlspecialchars($currentUsername ?? '', ENT_QUOTES, 'UTF-8') ?> — Account Security" style="text-decoration: none;">
                        <span class="status-indicator online"></span>
                        <span class="user-name"><?= htmlspecialchars($currentUsername ?? 'User', ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                    <form action="logout.php" method="POST" class="logout-form desktop-logout-form" style="display:inline;margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn-logout" id="btnLogout" title="Log out of application">Sign Out</button>
                    </form>
                </div>
            </nav>
            <?php else: ?>
            <button class="nav-toggle" id="navToggleGuest" aria-label="Toggle navigation menu" aria-controls="guestNav" aria-expanded="false">
                <span class="bar"></span>
                <span class="bar"></span>
                <span class="bar"></span>
            </button>

            <nav class="app-nav guest-nav" id="guestNav">
                <ul class="nav-links">
                    <li><a href="index.php" class="<?= ($currentPage === 'index.php' || $currentPage === 'landing.php') ? 'active' : '' ?>">Home</a></li>
                    <li><a href="<?= ($currentPage === 'index.php' || $currentPage === 'landing.php') ? '#how-it-works' : 'index.php#how-it-works' ?>">How It Works</a></li>
                    <li><a href="<?= ($currentPage === 'index.php' || $currentPage === 'landing.php') ? '#criteria' : 'index.php#criteria' ?>">Criteria</a></li>
                    <li><a href="<?= ($currentPage === 'index.php' || $currentPage === 'landing.php') ? '#tournament-stages' : 'index.php#tournament-stages' ?>">Tournament</a></li>
                    <li><a href="about.php" class="<?= $currentPage === 'about.php' ? 'active' : '' ?>">About</a></li>
                    <li><a href="contact.php" class="<?= $currentPage === 'contact.php' ? 'active' : '' ?>">Contact</a></li>
                </ul>

                <div class="user-meta guest-user-meta">
                    <?php if (has_users()): ?>
                        <a href="login.php" class="btn btn-primary btn-sm btn-glow" id="navLogin">Sign In</a>
                    <?php else: ?>
                        <a href="setup.php" class="btn btn-accent btn-sm" id="navSetup">Setup Account</a>
                    <?php endif; ?>
                </div>
            </nav>
            <?php endif; ?>
        </div>
    </header>

    <main class="main-content" id="mainContent">
