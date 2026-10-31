<?php
/**
 * AFM#2 - About Page
 * Annual Musical Finale 2026
 * 
 * Genuine explanation of AFM#2, its philosophy, curation framework, and tournament rules.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/preferences_helper.php';

$pageTitle = 'About AFM#2 — Annual Musical Finale 2026';
$pageDescription = 'Learn about AFM#2 (Annual Musical Finale 2026): an intimate music-listening sanctuary and rigorous five-criterion tournament across a curated music catalogue.';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-container trust-page-container">
    <div class="trust-content-card">
        <header class="trust-header">
            <span class="trust-badge">ANNUAL MUSICAL FINALE 2026</span>
            <h1 class="trust-title">About AFM#2</h1>
            <p class="trust-subtitle">Every song has a stage &bull; A personal music journal and audition championship</p>
        </header>

        <section class="trust-section">
            <h2>The Vision</h2>
            <p>
                In an era of algorithmic streaming playlists and fleeting attention spans, <strong>AFM#2 (Annual Musical Finale 2026)</strong> was created as an intimate, intentional listening sanctuary. Built around an extensive, curated catalogue spanning genres, languages, and decades, AFM#2 transforms passive background playback into an immersive, analytical musical journey.
            </p>
        </section>

        <section class="trust-section">
            <h2>The Five-Criterion Audition System</h2>
            <p>
                Instead of simple binary likes or arbitrary star ratings, every shortlisted contender is evaluated through a structured, multi-dimensional audition scorecard. Each criterion is scored on a scale from <strong>0 to 10</strong>, yielding a comprehensive score out of <strong>50 points</strong>:
            </p>
            <div class="criteria-cards-grid">
                <div class="criteria-card">
                    <div class="criteria-card-icon">✨</div>
                    <h3 class="criteria-card-title">Vibe (0–10)</h3>
                    <p class="criteria-card-desc">Atmospheric resonance, texture, emotional gravity, and mood creation.</p>
                </div>
                <div class="criteria-card">
                    <div class="criteria-card-icon">✍️</div>
                    <h3 class="criteria-card-title">Lyrics (0–10)</h3>
                    <p class="criteria-card-desc">Poetic craftsmanship, lyrical depth, storytelling, and evocative resonance.</p>
                </div>
                <div class="criteria-card">
                    <div class="criteria-card-icon">🎙️</div>
                    <h3 class="criteria-card-title">Vocals (0–10)</h3>
                    <p class="criteria-card-desc">Expressive delivery, vocal technique, timbre, and emotive power.</p>
                </div>
                <div class="criteria-card">
                    <div class="criteria-card-icon">🎛️</div>
                    <h3 class="criteria-card-title">Production (0–10)</h3>
                    <p class="criteria-card-desc">Mix balance, spatial staging, dynamic range, arrangement, and acoustic finesse.</p>
                </div>
                <div class="criteria-card">
                    <div class="criteria-card-icon">🔁</div>
                    <h3 class="criteria-card-title">Replayability (0–10)</h3>
                    <p class="criteria-card-desc">Enduring longevity, timeless replay value, and magnetic appeal across repeated sessions.</p>
                </div>
            </div>
            <p style="margin-top: 1rem; font-size: 0.9rem; color: var(--text-muted);">
                <strong>Tie-Breaking Protocol:</strong> In the event of equal total scores, ties are resolved deterministically using a strict hierarchical cascade: <em>Vibe &rarr; Lyrics &rarr; Vocals &rarr; Production &rarr; Replayability</em>.
            </p>
        </section>

        <section class="trust-section">
            <h2>The Tournament Championship Progression</h2>
            <p>
                Contenders that score above the qualification threshold advance through seven progressive tournament stages toward the 2026 Championship Finale:
            </p>
            <ol class="tournament-stages-list">
                <li><strong>Formal Auditions:</strong> Initial evaluation across the shortlisted catalogue.</li>
                <li><strong>Top 200:</strong> The upper tier of qualifying tracks competing for tournament placement.</li>
                <li><strong>Top 100:</strong> The top 100 contenders advancing to deeper tournament scrutiny.</li>
                <li><strong>Top 50:</strong> High-stakes contention between standout selections.</li>
                <li><strong>Top 20:</strong> The elite top 20 contenders.</li>
                <li><strong>Top 10:</strong> The definitive finalist contenders.</li>
                <li><strong>Finals:</strong> The single crowning champion of the Annual Musical Finale 2026.</li>
            </ol>
        </section>

        <section class="trust-section">
            <h2>Craftsmanship &amp; Technical Philosophy</h2>
            <p>
                AFM#2 is engineered with pride for music lovers and analytical listeners:
            </p>
            <ul>
                <li><strong>Zero-Cost &amp; Lightweight:</strong> Designed to run flawlessly on ₹0/month shared infrastructure without heavy JavaScript frameworks, commercial node dependencies, or paid background workers.</li>
                <li><strong>Modern Security Architecture:</strong> Multi-method authentication supporting WebAuthn Passkeys, Google Sign-In, and cryptographically hashed passwords.</li>
                <li><strong>Atmospheric Theming:</strong> Hand-crafted dark palettes (Midnight Ember, Vintage Paper, Oceanic, Quiet Minimal) designed for night listening.</li>
                <li><strong>Data Integrity Invariant:</strong> The music catalogue is protected by strict foreign-key nullification rules and test database isolation.</li>
            </ul>
        </section>

        <div class="trust-footer-nav">
            <a href="index.php" class="btn btn-primary">Explore the Experience &rarr;</a>
            <a href="contact.php" class="btn btn-secondary">Get in Touch</a>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
