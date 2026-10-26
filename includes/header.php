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
    <meta name="description" content="AFM#2 Personal Music Catalogue, Audition, and Tournament Web Application for Annual Musical Finale 2026.">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <?php if (defined('GOOGLE_SITE_VERIFICATION') && GOOGLE_SITE_VERIFICATION): ?>
    <meta name="google-site-verification" content="<?= htmlspecialchars(GOOGLE_SITE_VERIFICATION, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <!-- Favicon & Touch Icons (Approved Compact AFM#2 Symbol) -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/afm2-favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/afm2-favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="48x48" href="assets/img/afm2-favicon-48x48.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/img/afm2-favicon-192x192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png">

    <link rel="stylesheet" href="assets/css/style.css?v=3.6">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@400;600&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Outfit:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
</head>
<body>
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
                    <div class="sound-equalizer" aria-hidden="true" title="AFM 2026 Active">
                        <span class="eq-bar eq-1"></span>
                        <span class="eq-bar eq-2"></span>
                        <span class="eq-bar eq-3"></span>
                        <span class="eq-bar eq-4"></span>
                    </div>
                </a>
            </div>

            <?php if ($isLoggedIn): ?>
            <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation menu" aria-expanded="false">
                <span class="bar"></span>
                <span class="bar"></span>
                <span class="bar"></span>
            </button>

            <nav class="app-nav" id="appNav">
                <ul class="nav-links">
                    <li><a href="index.php" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>" id="navDashboard">Journal</a></li>
                    <li><a href="library.php" class="<?= $currentPage === 'library.php' ? 'active' : '' ?>" id="navLibrary">Library</a></li>
                    <li><a href="recordings.php" class="<?= $currentPage === 'recordings.php' ? 'active' : '' ?>" id="navRecordings">Recordings</a></li>
                    <li><a href="discovery.php" class="<?= $currentPage === 'discovery.php' ? 'active' : '' ?>" id="navDiscovery">Shortlist</a></li>
                    <li><a href="auditions.php" class="<?= $currentPage === 'auditions.php' ? 'active' : '' ?>" id="navAuditions">Auditions</a></li>
                    <li><a href="tournament.php" class="<?= $currentPage === 'tournament.php' ? 'active' : '' ?>" id="navTournament">Tournament</a></li>
                    <li><a href="results.php" class="<?= $currentPage === 'results.php' ? 'active' : '' ?>" id="navResults">Archive</a></li>
                </ul>

                <div class="user-meta">
                    <button type="button" class="btn-nav-appearance" id="btnOpenAppearance" title="Appearance & Themes (Midnight Ember, Vintage Paper, Oceanic, Quiet Minimal)">
                        <span class="appearance-icon">🎨</span>
                        <span class="appearance-text">Appearance</span>
                    </button>
                    <span class="user-pill" title="Signed in as <?= htmlspecialchars($currentUsername ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <span class="status-indicator online"></span>
                        <span class="user-name"><?= htmlspecialchars($currentUsername ?? 'User', ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                    <form action="logout.php" method="POST" class="logout-form" style="display:inline;margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn-logout" id="btnLogout" title="Log out of application">Sign Out</button>
                    </form>
                </div>
            </nav>
            <?php else: ?>
            <div class="nav-auth-guest">
                <?php if (has_users()): ?>
                    <a href="login.php" class="btn btn-primary btn-sm" id="navLogin">Sign In</a>
                <?php else: ?>
                    <a href="setup.php" class="btn btn-accent btn-sm" id="navSetup">Setup Account</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="main-content" id="mainContent">
