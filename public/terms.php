<?php
/**
 * AFM#2 - Terms of Use
 * Annual Musical Finale 2026
 * 
 * Clear, respectful Terms of Use for a personal non-commercial music tournament journal.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/preferences_helper.php';

$pageTitle = 'Terms of Use — AFM#2 Annual Musical Finale 2026';
$pageDescription = 'Terms of Use for AFM#2 (Annual Musical Finale 2026). Explaining intended personal use, intellectual property attribution, and user responsibilities.';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-container trust-page-container">
    <div class="trust-content-card">
        <header class="trust-header">
            <span class="trust-badge">TERMS &amp; USAGE GUIDELINES</span>
            <h1 class="trust-title">Terms of Use</h1>
            <p class="trust-subtitle">Effective Date: October 2026 &bull; AFM#2 Annual Musical Finale</p>
        </header>

        <section class="trust-section">
            <h2>1. Agreement to Terms</h2>
            <p>
                By accessing or using AFM#2 (Annual Musical Finale 2026), available at <code>afm2.infinityfreeapp.com</code>, you agree to these Terms of Use. If you do not agree, you should refrain from using the platform.
            </p>
        </section>

        <section class="trust-section">
            <h2>2. Purpose &amp; Non-Commercial Nature</h2>
            <p>
                AFM#2 is an independent, non-commercial personal project developed for private music curation, structured track auditions, and tournament progression. It is not a commercial music service, subscription streaming product, or distributor of audio recordings. Access is provided on an invitation-only basis free of charge.
            </p>
        </section>

        <section class="trust-section">
            <h2>3. Intellectual Property &amp; Music Rights</h2>
            <p>
                <strong>AFM#2 does not host, store, broadcast, or claim ownership over any musical compositions, sound recordings, or cover artworks.</strong>
            </p>
            <ul>
                <li>All song titles, artist names, album titles, and trademarks are the property of their respective copyright owners and recording labels.</li>
                <li>Audio and video streams are served directly from YouTube via standard YouTube embedded playback pursuant to YouTube Terms of Service.</li>
                <li>Song lyrics are retrieved on-demand via the community-maintained LRCLIB database strictly for personal study, appreciation, and reference.</li>
            </ul>
        </section>

        <section class="trust-section">
            <h2>4. User Responsibilities &amp; Acceptable Use</h2>
            <p>As an authorized user of AFM#2, you agree to:</p>
            <ul>
                <li>Maintain the confidentiality of your login credentials and passkeys.</li>
                <li>Refrain from attempting to bypass application authentication, rate-limiting, or security boundaries.</li>
                <li>Refrain from executing automated scraping scripts, denial-of-service attempts, or malicious payloads against the hosting server.</li>
                <li>Use the platform exclusively for its intended purpose: personal track discovery, 5-criterion auditions, and tournament brackets.</li>
            </ul>
        </section>

        <section class="trust-section">
            <h2>5. Service Availability &amp; Disclaimer</h2>
            <p>
                AFM#2 is provided on an "as-is" and "as-available" basis without warranties of any kind. As a personal project hosted under ₹0/month shared infrastructure, uptime is subject to provider limits and maintenance windows. The maintainer reserves the right to suspend or modify the service at any time without liability.
            </p>
        </section>

        <section class="trust-section">
            <h2>6. Governing Inquiry &amp; Contact</h2>
            <p>
                Questions or notices regarding these Terms may be directed to the project maintainer via the <a href="contact.php">Contact Page</a> or submitted to the repository issue tracker at <a href="https://github.com/gulshankyy2007/afm2" target="_blank" rel="noopener noreferrer">github.com/gulshankyy2007/afm2</a>.
            </p>
        </section>

        <div class="trust-footer-nav">
            <a href="index.php" class="btn btn-secondary">&larr; Back to AFM#2 Home</a>
            <a href="privacy.php" class="btn btn-secondary">Privacy Policy &rarr;</a>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
