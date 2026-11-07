<?php
/**
 * AFM#2 - Contact Page
 * Annual Musical Finale 2026
 * 
 * Genuine contact and repository details for AFM#2.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/preferences_helper.php';

$pageTitle = 'Contact — AFM#2 Annual Musical Finale 2026';
$pageDescription = 'Get in touch with the AFM#2 project maintainer. Access GitHub repository, report issues, and explore project documentation.';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-container trust-page-container">
    <div class="trust-content-card">
        <header class="trust-header">
            <span class="trust-badge">PROJECT &amp; DEVELOPER INQUIRY</span>
            <h1 class="trust-title">Contact AFM#2</h1>
            <p class="trust-subtitle">Annual Musical Finale 2026 &bull; Open-Source Music Tournament &amp; Listening Sanctuary</p>
        </header>

        <section class="trust-section">
            <h2>Get in Touch</h2>
            <p>
                AFM#2 is an open-source, non-commercial project. If you have technical feedback, bug reports, or feature suggestions, the best channels to connect are:
            </p>
            <div class="contact-channels-grid">
                <div class="contact-channel-card">
                    <div class="contact-channel-icon">📁</div>
                    <div class="contact-channel-info">
                        <h3>Project Repository</h3>
                        <p>View the source code, inspect technical documentation, and track ongoing updates on GitHub. Note: The repository serves as a public code host and issue tracker, not a private messaging channel or guaranteed support service.</p>
                        <a href="https://github.com/gulshankyy2007/afm2" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm" id="btnContactRepo">
                            Project Repository ↗
                        </a>
                    </div>
                </div>

                <div class="contact-channel-card">
                    <div class="contact-channel-icon">💬</div>
                    <div class="contact-channel-info">
                        <h3>Issue Tracker &amp; Feedback</h3>
                        <p>Submit bug reports, responsive layout suggestions, or security vulnerability notices.</p>
                        <a href="https://github.com/gulshankyy2007/afm2/issues" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">
                            Open an Issue on GitHub ↗
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <section class="trust-section">
            <h2>Account &amp; Access Inquiries</h2>
            <p>
                AFM#2 operates on a closed, invitation-only model for personal curation and evaluation. Public self-registration is closed. If you have been invited by an administrator, please use the private invitation link provided to you, or log in using your registered credentials.
            </p>
            <p>
                To request account deletion or data removal in accordance with our <a href="privacy.php">Privacy Policy</a>, please open a confidential request via GitHub.
            </p>
        </section>

        <div class="trust-footer-nav">
            <a href="index.php" class="btn btn-secondary">&larr; Back to AFM#2 Home</a>
            <a href="about.php" class="btn btn-secondary">About AFM#2 &rarr;</a>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
