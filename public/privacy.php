<?php
/**
 * AFM#2 - Privacy Policy
 * Annual Musical Finale 2026
 * 
 * Factual, transparent disclosure of data practices for AFM#2.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/preferences_helper.php';

$pageTitle = 'Privacy Policy — AFM#2 Annual Musical Finale 2026';
$pageDescription = 'Privacy Policy for AFM#2 (Annual Musical Finale 2026). Explaining our strict data handling, session security, and third-party integrations.';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-container trust-page-container">
    <div class="trust-content-card">
        <header class="trust-header">
            <span class="trust-badge">DATA &amp; PRIVACY TRANSPARENCY</span>
            <h1 class="trust-title">Privacy Policy</h1>
            <p class="trust-subtitle">Effective Date: October 2026 &bull; AFM#2 Annual Musical Finale</p>
        </header>

        <section class="trust-section">
            <h2>1. Nature of the Application</h2>
            <p>
                AFM#2 (Annual Musical Finale 2026) is a personal music listening journal, audition platform, and tournament tracker designed for curated music appreciation and tournament progression. It is a non-commercial, invitation-only application. Public user registration is closed; all accounts are provisioned exclusively by administrator invitation.
            </p>
        </section>

        <section class="trust-section">
            <h2>2. Information We Collect</h2>
            <p>We collect and process only the minimal data strictly required for application functionality:</p>
            <ul>
                <li><strong>Account Credentials:</strong> Usernames and optional email addresses for account provisioning and identification. Passwords are never stored in plaintext; they are securely hashed using modern cryptographic algorithms (bcrypt / Argon2id).</li>
                <li><strong>Google Sign-In Data (Optional):</strong> If you choose to link or sign in via Google, we verify your OpenID Connect (OIDC) identity token issued by Google Accounts and store your unique Google subject ID (<code>sub</code>), verified email address, and display name. We do not access your Google Drive, Gmail, or contacts.</li>
                <li><strong>Passkeys / WebAuthn (Optional):</strong> If you register a FIDO2 / WebAuthn passkey (e.g. fingerprint, facial recognition, or hardware security key), we store the public key credential and credential ID. Your private biometric data never leaves your personal device and is never transmitted to or stored on our servers.</li>
                <li><strong>Session &amp; Security Cookies:</strong> We issue a single session cookie (<code>AFM2_SESSID</code>) configured with <code>HttpOnly</code>, <code>SameSite=Lax</code>, and <code>Secure</code> flags to preserve your authenticated state. We do not use advertising, marketing, or cross-site tracking cookies.</li>
                <li><strong>Catalogue Evaluations &amp; Tournament Data:</strong> Audition scores (evaluated across 5 criteria: Vibe, Lyrics, Vocals, Production, and Replayability), shortlisted songs, tournament votes, and custom appearance preferences.</li>
                <li><strong>Security &amp; Rate-Limiting Logs:</strong> IP addresses and timestamps recorded during failed login attempts to protect against brute-force attacks. These records are retained temporarily in <code>login_rate_limits</code> and automatically expire after 15 minutes.</li>
            </ul>
        </section>

        <section class="trust-section">
            <h2>3. Third-Party Integrations &amp; Embedded Media</h2>
            <p>AFM#2 does not store or host copyrighted audio or video recordings on its servers. Instead, it interfaces with authorized third-party services:</p>
            <ul>
                <li><strong>YouTube (Google LLC):</strong> Audio and video playback is delivered via YouTube's official embedded player (<code>https://www.youtube-nocookie.com</code> or <code>https://www.youtube.com</code>). When you play a video, YouTube may collect device and usage data in accordance with <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Google's Privacy Policy</a>.</li>
                <li><strong>LRCLIB:</strong> Synchronized and plain song lyrics are queried on demand from the community-driven LRCLIB API. Queries include only artist name, track title, and duration; no personal user identifiers or IP addresses are passed to LRCLIB.</li>
            </ul>
        </section>

        <section class="trust-section">
            <h2>4. Data Retention and Account Deletion</h2>
            <p>
                Your account data, audition ratings, and tournament progress remain stored until your account is removed. Account holders may request complete deletion of their account, credentials, and scores by contacting the administrator.
            </p>
        </section>

        <section class="trust-section">
            <h2>5. Data Security Safeguards</h2>
            <p>
                We implement strict security practices:
            </p>
            <ul>
                <li>Defensive HTTP security headers including <code>Content-Security-Policy</code>, <code>X-Content-Type-Options: nosniff</code>, <code>X-Frame-Options: SAMEORIGIN</code>, and <code>Referrer-Policy: strict-origin-when-cross-origin</code>.</li>
                <li>Strict parameterized SQL queries across all database operations to eliminate SQL injection risks.</li>
                <li>Mandatory cryptographic CSRF token validation on all state-changing requests.</li>
                <li>Rate-limiting on authentication endpoints to defend against automated brute-force attacks.</li>
                <li>Protection against automated test suites mutating or deleting persistent application data via enforced database isolation guards.</li>
            </ul>
        </section>

        <section class="trust-section">
            <h2>6. Contact for Privacy Inquiries</h2>
            <p>
                If you have questions regarding this Privacy Policy or wish to request data deletion, please contact the project maintainer via the <a href="contact.php">Contact Page</a> or submit an inquiry on the official GitHub repository at <a href="https://github.com/gulshankyy2007/afm2" target="_blank" rel="noopener noreferrer">github.com/gulshankyy2007/afm2</a>.
            </p>
        </section>

        <div class="trust-footer-nav">
            <a href="index.php" class="btn btn-secondary">&larr; Back to AFM#2 Home</a>
            <a href="terms.php" class="btn btn-secondary">Terms of Use &rarr;</a>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
