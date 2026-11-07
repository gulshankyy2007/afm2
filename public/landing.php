<?php
/**
 * AFM#2 - Public Landing Page
 * Annual Musical Finale 2026
 * 
 * Accessible to anonymous visitors without requiring login.
 * Communicates project purpose, five-criterion audition system, and tournament progression.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/preferences_helper.php';

$pageTitle = 'AFM#2 — Annual Musical Finale 2026 | Every Song Has a Stage';
$pageDescription = 'AFM#2 (Annual Musical Finale 2026) is an intimate music listening sanctuary and rigorous five-criterion tournament across an extensive curated music catalogue.';
$isLandingPage = true;

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="landing-page-wrapper">
    <!-- Hero Section -->
    <section class="landing-hero-section">
        <div class="landing-hero-content">
            <div class="landing-hero-badge">
                <span class="badge-dot"></span>
                <span class="badge-text">ANNUAL MUSICAL FINALE 2026</span>
                <span class="badge-divider">&bull;</span>
                <span class="badge-sub">CURATED MUSIC SANCTUARY</span>
            </div>

            <h1 class="landing-hero-title">
                Every song<br>
                <span class="text-gradient-amber">has a stage.</span>
            </h1>

            <p class="landing-hero-lead">
                An intimate music listening sanctuary and rigorous tournament across a private curated music catalogue. 
                Experience intentional discovery, deep five-criterion auditions, and tournament bracket progression.
            </p>

            <div class="landing-hero-actions">
                <a href="login.php" class="btn btn-primary btn-lg btn-glow" id="heroBtnEnter">
                    <span class="btn-icon">🎧</span> Enter AFM#2
                </a>
                <a href="#how-it-works" class="btn btn-secondary btn-lg" id="heroBtnExplore">
                    Explore the Experience &darr;
                </a>
            </div>

            <div class="landing-hero-trust-row">
                <span class="trust-item"><span class="trust-icon">✓</span> Curated Multi-Era Catalogue</span>
                <span class="trust-item"><span class="trust-icon">✓</span> 5-Criterion Auditions</span>
                <span class="trust-item"><span class="trust-icon">✓</span> 7 Championship Stages</span>
                <span class="trust-item"><span class="trust-icon">✓</span> Passkey &amp; Google Sign-In</span>
            </div>
        </div>

        <div class="landing-hero-visual" aria-hidden="true">
            <div class="vinyl-display-card">
                <div class="vinyl-record">
                    <div class="vinyl-grooves"></div>
                    <div class="vinyl-center-label">
                        <span class="vinyl-label-title">AFM#2</span>
                        <span class="vinyl-label-sub">2026</span>
                    </div>
                </div>
                <div class="audition-preview-card">
                    <div class="preview-header">
                        <span class="preview-status">STAGE: FORMAL AUDITIONS</span>
                        <span class="preview-score-badge">42 / 50</span>
                    </div>
                    <div class="preview-track-info">
                        <div class="preview-track-title">Bohemian Rhapsody</div>
                        <div class="preview-track-artist">Queen &bull; A Night at the Opera</div>
                    </div>
                    <div class="preview-criteria-bars">
                        <div class="preview-bar-row">
                            <span class="bar-name">Vibe</span>
                            <div class="bar-track"><div class="bar-fill" style="width: 90%;"></div></div>
                            <span class="bar-val">9</span>
                        </div>
                        <div class="preview-bar-row">
                            <span class="bar-name">Lyrics</span>
                            <div class="bar-track"><div class="bar-fill" style="width: 80%;"></div></div>
                            <span class="bar-val">8</span>
                        </div>
                        <div class="preview-bar-row">
                            <span class="bar-name">Vocals</span>
                            <div class="bar-track"><div class="bar-fill" style="width: 100%;"></div></div>
                            <span class="bar-val">10</span>
                        </div>
                        <div class="preview-bar-row">
                            <span class="bar-name">Production</span>
                            <div class="bar-track"><div class="bar-fill" style="width: 90%;"></div></div>
                            <span class="bar-val">9</span>
                        </div>
                        <div class="preview-bar-row">
                            <span class="bar-name">Replay</span>
                            <div class="bar-track"><div class="bar-fill" style="width: 60%;"></div></div>
                            <span class="bar-val">6</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="landing-section" id="how-it-works">
        <div class="section-container">
            <div class="section-header">
                <span class="section-tag">THE WORKFLOW</span>
                <h2 class="section-title">How AFM#2 Works</h2>
                <p class="section-lead">A thoughtful sequence from initial catalogue triage to the championship crowning.</p>
            </div>

            <div class="workflow-steps-grid">
                <div class="workflow-step-card">
                    <div class="step-number">01</div>
                    <div class="step-icon">⚡</div>
                    <h3 class="step-title">Explore Catalogue</h3>
                    <p class="step-desc">
                        Browse through an extensive catalogue across eras and moods with instant search, artist filtering, and sorting.
                    </p>
                </div>

                <div class="workflow-step-card">
                    <div class="step-number">02</div>
                    <div class="step-icon">🎯</div>
                    <h3 class="step-title">Triage &amp; Shortlist</h3>
                    <p class="step-desc">
                        One-tap decision making: mark standout contenders as <strong>Shortlisted</strong> or pass to narrow the competition pool.
                    </p>
                </div>

                <div class="workflow-step-card">
                    <div class="step-number">03</div>
                    <div class="step-icon">🎧</div>
                    <h3 class="step-title">5-Criterion Audition</h3>
                    <p class="step-desc">
                        Listen to the confirmed YouTube performance with synchronized LRCLIB lyrics and score on 5 musical dimensions.
                    </p>
                </div>

                <div class="workflow-step-card">
                    <div class="step-number">04</div>
                    <div class="step-icon">🏆</div>
                    <h3 class="step-title">Tournament Knockout</h3>
                    <p class="step-desc">
                        Progress contenders across seven rounds with mathematical qualification cutlines and deterministic tie-breaking.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Audition Criteria Section -->
    <section class="landing-section bg-surface-subtle" id="criteria">
        <div class="section-container">
            <div class="section-header">
                <span class="section-tag">EVALUATION RUBRIC</span>
                <h2 class="section-title">The Five Audition Criteria</h2>
                <p class="section-lead">Every shortlisted song is evaluated across 5 balanced dimensions for a total score out of 50.</p>
            </div>

            <div class="criteria-detail-grid">
                <div class="criteria-detail-card">
                    <div class="criteria-icon-wrap">✨</div>
                    <div class="criteria-content">
                        <h3>Vibe (0–10)</h3>
                        <p>The emotional aura, kinetic energy, atmospheric presence, and distinct sonic mood of the recording.</p>
                        <span class="criteria-weight">Weight: 20% &bull; Primary Tie-Breaker</span>
                    </div>
                </div>

                <div class="criteria-detail-card">
                    <div class="criteria-icon-wrap">✍️</div>
                    <div class="criteria-content">
                        <h3>Lyrics (0–10)</h3>
                        <p>Poetic depth, evocative metaphors, thematic cohesion, storytelling, and memorability of phrasing.</p>
                        <span class="criteria-weight">Weight: 20% &bull; Secondary Tie-Breaker</span>
                    </div>
                </div>

                <div class="criteria-detail-card">
                    <div class="criteria-icon-wrap">🎙️</div>
                    <div class="criteria-content">
                        <h3>Vocals (0–10)</h3>
                        <p>Technical performance, vocal dynamics, emotional sincerity, delivery nuance, and melodic character.</p>
                        <span class="criteria-weight">Weight: 20% &bull; Tertiary Tie-Breaker</span>
                    </div>
                </div>

                <div class="criteria-detail-card">
                    <div class="criteria-icon-wrap">🎛️</div>
                    <div class="criteria-content">
                        <h3>Production (0–10)</h3>
                        <p>Audio engineering, arrangement complexity, instrument separation, mix clarity, and spatial acoustics.</p>
                        <span class="criteria-weight">Weight: 20% &bull; Quaternary Tie-Breaker</span>
                    </div>
                </div>

                <div class="criteria-detail-card">
                    <div class="criteria-icon-wrap">🔁</div>
                    <div class="criteria-content">
                        <h3>Replayability (0–10)</h3>
                        <p>Timeless longevity, compulsive repeat listening desire, and freshness across repeated playbacks.</p>
                        <span class="criteria-weight">Weight: 20% &bull; Quinary Tie-Breaker</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Tournament Stages Section -->
    <section class="landing-section" id="tournament-stages">
        <div class="section-container">
            <div class="section-header">
                <span class="section-tag">CHAMPIONSHIP BRACKET</span>
                <h2 class="section-title">The Seven Tournament Stages</h2>
                <p class="section-lead">From the extensive catalogue pool to the single crowning champion of 2026.</p>
            </div>

            <div class="stages-timeline landing-stages-grid">
                <div class="stage-step">
                    <div class="stage-marker">1</div>
                    <div class="stage-card">
                        <span class="stage-name">Stage 1: Formal Auditions</span>
                        <p class="stage-desc">All shortlisted tracks undergo rigorous individual scoring across the 5 criteria.</p>
                    </div>
                </div>
                <div class="stage-step">
                    <div class="stage-marker">2</div>
                    <div class="stage-card">
                        <span class="stage-name">Stage 2: Top 200</span>
                        <p class="stage-desc">The highest-ranking 200 contenders advance to the tournament bracket.</p>
                    </div>
                </div>
                <div class="stage-step">
                    <div class="stage-marker">3</div>
                    <div class="stage-card">
                        <span class="stage-name">Stage 3: Top 100</span>
                        <p class="stage-desc">The field narrows to the top 100 standout contenders under deeper scrutiny.</p>
                    </div>
                </div>
                <div class="stage-step">
                    <div class="stage-marker">4</div>
                    <div class="stage-card">
                        <span class="stage-name">Stage 4: Top 50</span>
                        <p class="stage-desc">High-stakes contention as the top 50 selections vie for elite placement.</p>
                    </div>
                </div>
                <div class="stage-step">
                    <div class="stage-marker">5</div>
                    <div class="stage-card">
                        <span class="stage-name">Stage 5: Top 20</span>
                        <p class="stage-desc">The top 20 elite tracks face intense evaluation and deterministic tie-breaking.</p>
                    </div>
                </div>
                <div class="stage-step">
                    <div class="stage-marker">6</div>
                    <div class="stage-card">
                        <span class="stage-name">Stage 6: Top 10</span>
                        <p class="stage-desc">The final 10 medal-round masterworks compete for podium positions.</p>
                    </div>
                </div>
                <div class="stage-step">
                    <div class="stage-marker">7</div>
                    <div class="stage-card highlight">
                        <span class="stage-name">Stage 7: Finals</span>
                        <p class="stage-desc">The ultimate showdown crowning the single Annual Musical Finale 2026 Champion.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Project Identity & Clarification Callout -->
    <section class="landing-section bg-surface-subtle" id="project-identity">
        <div class="section-container">
            <div class="identity-card">
                <div class="identity-card-header">
                    <span class="identity-badge">PROJECT IDENTITY &amp; TRANSPARENCY</span>
                    <h2>What AFM#2 Is &amp; Isn't</h2>
                </div>
                <div class="identity-grid">
                    <div class="identity-item">
                        <span class="identity-check">✔</span>
                        <div>
                            <h4>A Personal Music Journal</h4>
                            <p>An intentional, private listening sanctuary engineered for deep music appreciation and tournament ranking.</p>
                        </div>
                    </div>
                    <div class="identity-item">
                        <span class="identity-check">✔</span>
                        <div>
                            <h4>Authorized YouTube &amp; LRCLIB Interop</h4>
                            <p>Plays streams through YouTube's embedded iframe player and renders synchronized lyrics via the community LRCLIB database.</p>
                        </div>
                    </div>
                    <div class="identity-item">
                        <span class="identity-cross">✘</span>
                        <div>
                            <h4>Not a Commercial Streaming Service</h4>
                            <p>AFM#2 does not charge fees, run advertisements, sell subscriptions, or compete with commercial music platforms.</p>
                        </div>
                    </div>
                    <div class="identity-item">
                        <span class="identity-cross">✘</span>
                        <div>
                            <h4>No Hosted Audio or Video Files</h4>
                            <p>AFM#2 does not store or host sound recordings on its servers. All intellectual property remains with original artists and labels.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact & Project Repository Section -->
    <section class="landing-section" id="contact-repo">
        <div class="section-container">
            <div class="repo-callout-card">
                <div class="repo-callout-header">
                    <span class="section-tag">OPEN ARCHITECTURE &amp; SOURCE</span>
                    <h2 class="section-title">Project Repository &amp; Contact</h2>
                    <p class="section-lead">AFM#2 is an open-source, non-commercial music tournament platform.</p>
                </div>
                <div class="repo-callout-body">
                    <p>
                        Technical documentation, issue tracking, and source code are maintained publicly on GitHub. Please note that the public repository is a code host and issue tracker, not a private messaging channel or guaranteed support service.
                    </p>
                    <div class="repo-callout-actions">
                        <a href="https://github.com/gulshankyy2007/afm2" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" id="btnLandingRepo">
                            <span class="btn-icon">📁</span> Project Repository ↗
                        </a>
                        <a href="contact.php" class="btn btn-ghost" id="btnLandingContact">
                            Contact Information &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Ready to Enter Call to Action -->
    <section class="landing-cta-section">
        <div class="cta-inner">
            <h2 class="cta-title">Ready to Experience AFM#2?</h2>
            <p class="cta-lead">Sign in with your authorized personal account, Google account, or passkey.</p>
            <div class="cta-buttons">
                <a href="login.php" class="btn btn-primary btn-lg btn-glow">
                    Sign In to AFM#2 &rarr;
                </a>
                <a href="about.php" class="btn btn-secondary btn-lg">
                    Read the Full Story
                </a>
            </div>
        </div>
    </section>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
