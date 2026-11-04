# AFM#2 — Annual Musical Finale 2026

**Personal Music Catalogue, Audition, and Tournament Web Application**

AFM#2 is a personal music management, audition, and tournament competition web application designed for the 2026 Annual Musical Finale. It provides high-performance music catalogue exploration, rapid one-tap shortlist/pass discovery, multi-criteria audition scoring, automated tournament progression across 7 stages with deterministic 5-tier tie-breaking, real-time synchronized lyrics, and responsive layouts across desktop, tablet, and mobile devices.

---

## 1. System Architecture & Technology Stack

AFM#2 is built strictly with lightweight, native technologies without framework overhead or external build steps:

- **Backend:** Native PHP 8.2+ (Strict typing, PDO prepared statements, UTF-8mb4, secure session cookies, defense-in-depth security headers, and rate limiting).
- **Database:** MySQL 8.0+ / MariaDB 10.4+ / 11.4 (`afm_db`) with `utf8mb4_unicode_ci` and stored generated columns.
- **Frontend Architecture:** Semantic HTML5, Vanilla CSS3 (Custom properties design system, 8 themes, responsive flex/grid layouts, mobile-first card transformations), and Vanilla ES6+ JavaScript.
- **External Integrations:**
  - **YouTube:** Privacy-enhanced embedded video playback (`youtube-nocookie.com`) and zero-cost search integration without API keys.
  - **LRCLIB:** Free community-driven synchronized and plain lyrics lookups with multi-level database caching (`song_lyrics_ref`).
  - **Lyrics.ovh:** Secondary automated lyrics fallback provider.
  - **Google OAuth 2.0 / OpenID Connect:** Optional federated identity authentication.
  - **WebAuthn / FIDO2:** Hardware passkey authentication.
- **Zero Framework Bloat:** Zero Node.js runtime dependencies, zero npm packages required in production, zero frontend framework baggage.

---

## 2. Main Application Features

### Responsive UI & Theming System
- **Comprehensive Viewport Support:** Optimized for mobile phones (320px, 360px, 390px, 430px), tablets (600px, 768px, 1024px), and desktops (1280px, 1440px, 1920px).
- **Eight Distinct Visual Themes:**
  1. *Midnight Muse* (Default deep obsidian with cyan/magenta neon accents)
  2. *Oceanic* (Deep marine cyan)
  3. *Nordic Frost* (Crisp sub-zero slate)
  4. *Emerald Forest* (Lush emerald woodland)
  5. *Midnight Ember* (Warm amber glow)
  6. *Velvet Amethyst* (Royal twilight purple)
  7. *Vintage Parchment* (Warm classic paper)
  8. *Solar Terracotta* (Earthy dusk tones)
- **Accessible Interactions:** WCAG 2.1 AA compliant color contrast, 44px+ touch targets, mobile navigation drawer, and keyboard-trapped modal dialogs.

### Catalogue & Library Management
- **2,411 Starter Catalogue Tracks:** Complete baseline music collection with persistent metadata, album artwork, and YouTube links.
- **Instant Filtering & Sorting:** Fast catalogue search with multi-field sorting by ID, Title (A-Z/Z-A), and Artist (A-Z/Z-A).
- **Direct CRUD Operations:** Modal track creation with auto-populated YouTube metadata, title/artist editing, and safe deletion controls.
- **Bulk Selection & Safety Safeguards:** Multi-track selection bar with pre-action eligibility evaluation protecting shortlisted, scored, and tournament-active songs from accidental deletion.

### Discovery Shortlist
- **Rapid Evaluation:** Quick audition intake with one-tap Shortlist, Pass, and Scorecard shortcuts.
- **Responsive Presentation:** Compact single-line numeric badges on desktop (`✓ SCORED: 40`) and spacious structured cards on mobile with dedicated timestamps and action buttons.

### Formal Auditions & Scoring
- **5-Dimension Scoring (/50 Total):**
  1. *Melody* (0–10)
  2. *Lyrics* (0–10)
  3. *Vocals* (0–10)
  4. *Emotion* (0–10)
  5. *Replayability* (0–10)
- **Automatic Score Generation:** Database-computed stored generated column:
  $$\text{Total} = \text{Melody} + \text{Lyrics} + \text{Vocals} + \text{Emotion} + \text{Replayability}$$
- **Incomplete vs. Complete Tracking:** Partial scorecards tracked as incomplete (`28* / 50`); only complete scorecards advance to official leaderboards.
- **Stage Finalization & Immutability:** Completed tournament scorecards and advancement decisions are permanently frozen and tamper-proof against unverified modifications or deletions.

### Tournament Progression & History
- **7-Stage Tournament Ladder:**
  1. *Formal Auditions* (Candidate intake & qualification)
  2. *Top 200* (Top 200 participants)
  3. *Top 100* (Top 100 participants)
  4. *Top 50* (Top 50 participants)
  5. *Top 20* (Semi-finalists)
  6. *Top 10* (Finalists)
  7. *Finals* (Crowning the 2026 Champion)
- **Deterministic 5-Tier Tie-Breaking:** Emotion > Replayability > Melody > Vocals > Lyrics > Entry ID ASC.
- **Interactive Manual Qualification Wizard:** Multi-step wizard supporting advisory cutoffs, custom drag-and-drop / arrow re-ordering, candidate replacements, and transactional confirmation.
- **Archival & CSV Export:** Historical stage standings, podium visualizations, and formula-injection-safe CSV results export.

### Recordings & Audio Experience
- **Smart Candidate Discovery:** Multi-source YouTube matching engine with thumbnail previews, duration heuristics, and debounce protections.
- **Real-Time Synced Lyrics:** YouTube IFrame API integration with line-by-line active lyric tracking, smooth auto-scrolling, and interactive seek-to-lyric timestamps.
- **Personal Manual Lyrics:** Secure user overrides for missing or synchronized lyrics stored per-user.

### Multiuser Isolation & Modular Authentication
- **Multiuser Architecture:** Independent user starter catalogues (`master_starter_songs` cloning), private user tracks, private scorecards, and isolated stage progression.
- **Authentication Providers:** Password (argon2id/bcrypt), Google OAuth 2.0 / OpenID Connect, and WebAuthn / FIDO2 Passkeys.
- **Administrative Provisioning:** Tokenized 64-hex invitations, account activation, and role-based access.

---

## 3. Directory Structure

```text
/mnt/data/GY/Music/AFM#2/
├── .htaccess                     # Root Apache rewrite rules (routes to public/) & security headers
├── .env.example                  # Environment configuration template
├── index.php                     # Root entry point forwarding to public/index.php
├── robots.txt                    # Search engine crawling rules
├── sitemap.xml                   # Public sitemap
├── bin/                          # Build & packaging helper scripts
│   └── build-infinityfree-release.sh
├── config/                       # Configuration & connection infrastructure
│   ├── config.php                # Environment loader, security headers, HTTPS detection
│   └── db.php                    # PDO connection factory (UTF-8mb4, emulated prepares disabled)
├── includes/                     # Application logic, helpers, and services
│   ├── auth.php                  # Authentication lifecycle, rate limiting, and session security
│   ├── csrf.php                  # Timing-safe CSRF token generation and validation
│   ├── db_helper.php             # Core database queries, tournament logic, and safeguards
│   ├── duplicate_helper.php      # Duplicate candidate detection and safe consolidation
│   ├── footer.php                # Layout footer, scripts, toasts, and media player
│   ├── google_auth_service.php   # Google OIDC authentication provider
│   ├── header.php                # Layout header, navigation drawer, theme selector, user pill
│   ├── import_helper.php         # CSV import processing and formula injection sanitization
│   ├── lyrics_helper.php         # LRCLIB & Lyrics.ovh multi-source lyrics fetcher
│   ├── webauthn_service.php      # FIDO2 / WebAuthn passkey registration & verification
│   └── youtube_matcher.php       # YouTube search and metadata extraction engine
├── migrations/                   # Versioned additive database schema migrations
│   ├── 001_init_app_tables.php through 015_align_tournament_collations.php
│   └── migration_runner.php      # Additive migration runner CLI & web handler
├── public/                       # Web server document root
│   ├── .htaccess                 # Direct public access security rules
│   ├── assets/
│   │   ├── css/                  # style.css (Midnight Muse) & mobile.css (Responsive)
│   │   ├── js/                   # app.js, auth.js, recordings.js (Vanilla ES6+)
│   │   └── img/                  # Brand logos, favicons, artwork fallbacks
│   ├── auditions.php             # Multi-criteria scoring interface
│   ├── discovery.php             # Shortlist management view
│   ├── index.php                 # Application dashboard & progression overview
│   ├── library.php               # Catalogue browser, search, sorting & modal CRUD
│   ├── recordings.php            # YouTube matching and recording management
│   ├── tournament.php            # Stage leaderboards & manual qualification wizard
│   ├── results.php               # Historical stage archive, CSV export, winner podium
│   ├── import.php                # CSV catalogue import interface
│   ├── login.php                 # Multi-method login screen
│   ├── logout.php                # Secure CSRF-protected session termination
│   ├── account.php               # User profile, theme settings, passkeys & auth management
│   ├── setup.php                 # One-time administrator provisioning (locks upon use)
│   ├── api.php                   # Secure CSRF-protected JSON API endpoint
│   └── archive.php               # Permanent redirect to results.php
├── deployment/                   # Deployment guides, backups, SQL migrations, release bundles
│   ├── afm2-infinityfree-release.zip # Validated production release archive
│   ├── afm2_full_backup.sql      # Canonical database backup
│   ├── DEPLOYMENT_GUIDE.md       # Generic deployment instructions
│   └── INFINITYFREE_SETUP.md     # Detailed InfinityFree step-by-step setup guide
├── tests/                        # Automated regression and security test suites
│   ├── test_db_guard.php         # Test database isolation guard (protects afm_db)
│   ├── test_bulk_action_safety.php
│   ├── test_production_deletion_safeguards.php
│   ├── test_stage_finalization_and_immutability.php
│   ├── test_song_management_and_sorting.php
│   ├── test_tournament_progression_and_qualification.php
│   ├── test_batch_a_auth_integrity.php through test_batch_e_rate_limiting_and_headers.php
│   └── test_multiuser_isolation.php
└── README.md                     # System documentation & operating guide
```

---

## 4. System Requirements

### Production Environment (e.g. InfinityFree / Shared Hosting / VPS)
- **PHP Version:** PHP 8.2 or later (PHP 8.2, 8.3, 8.4 supported).
- **PHP Extensions:** `pdo_mysql`, `curl`, `json`, `mbstring`, `openssl`, `session`.
- **Web Server:** Apache 2.2 / 2.4+ with modules:
  - `mod_rewrite` (URL routing and `.htaccess` rules)
  - `mod_headers` (Defensive security headers and cache control)
  - `mod_deflate` (Gzip compression)
  - `mod_expires` (Browser caching)
- **Database:** MySQL 8.0+ or MariaDB 10.4+ / 11.4 with `utf8mb4_unicode_ci` support.

### Local Development Environment
- PHP 8.2+ CLI built-in web server (`php -S`).
- MySQL 8.0+ or MariaDB running locally on port 3306.
- cURL utility and OpenSSL for testing.

---

## 5. Local Installation & Setup

### Step 1: Clone the Repository
```bash
git clone https://github.com/gulshankyy2007/afm2.git
cd afm2
git checkout impl/responsive-redesign
```

### Step 2: Environment Configuration
Copy the template configuration file:
```bash
cp .env.example .env
```

Edit `.env` with your local database credentials:
```ini
APP_ENV=development
APP_SECRET=a_strong_random_64_hex_character_secret_here
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=afm_db
DB_USER=your_local_db_user
DB_PASS=your_local_db_password
SESSION_LIFETIME=86400
```

### Step 3: Database Initialization & Migrations
1. Create the MySQL database:
   ```bash
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS afm_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```
2. Import the canonical starter database or execute migrations:
   ```bash
   # Option A: Import full baseline backup (contains all starter tracks & tables)
   mysql -u root -p afm_db < deployment/afm2_full_backup.sql

   # Option B: Run additive schema migrations
   php migrations/migration_runner.php
   ```

### Step 4: Launch the Local Development Server
Start PHP's built-in server targeting the public document root:
```bash
php -S 127.0.0.1:8080 -t public/
```

Access the application in your browser at: **`http://127.0.0.1:8080/`**

---

## 6. Production Deployment on InfinityFree

AFM#2 is engineered to run on InfinityFree's zero-cost PHP 8.2 + MySQL hosting environment.

### Deployment Prerequisites & Safety Policy
> [!IMPORTANT]
> - Never run destructive test scripts against the production database.
> - Never execute schema reset or reimport operations on an existing live database.
> - Always perform full file and database backups before updating production files.

### Step-by-Step Deployment Instructions

#### 1. Backup the Existing Live Website & Database
1. **Database Backup:** Log in to InfinityFree Control Panel $\rightarrow$ **phpMyAdmin** $\rightarrow$ select your database (`if0_xxxxxxxx_afm_db`) $\rightarrow$ click **Export** $\rightarrow$ format **SQL** $\rightarrow$ click **Export**. Save this file securely.
2. **File Backup:** In Control Panel $\rightarrow$ **File Manager** (or FileZilla FTP) $\rightarrow$ download or compress the existing `htdocs/` directory to your local workstation.

#### 2. Obtain the Release Archive
Use the pre-validated release package:
- **Archive Path:** `deployment/afm2-infinityfree-release.zip`
- **Archive Size:** ~915 KB (Minified CSS/JS, complete PHP runtime, zero dev clutter)
- **SHA-256 Checksum:** `1cd8a0eb9e09a28be706d2bc4169e3c123af5807ca9b1b23a8fe9a947b8e4dc2`

#### 3. Upload and Extract into `htdocs/`
1. Open the InfinityFree **File Manager** (Monsta FTP) and navigate into the **`htdocs/`** directory.
2. Upload `afm2-infinityfree-release.zip`.
3. Extract the archive **directly into `htdocs/`**.
4. Verify the resulting directory layout inside `htdocs/`:
   ```text
   htdocs/
   ├── .htaccess
   ├── .env.example
   ├── index.php
   ├── robots.txt
   ├── sitemap.xml
   ├── config/
   ├── includes/
   ├── migrations/
   └── public/
   ```
   *Notice: There must NOT be any enclosing `afm2-infinityfree-release/` wrapper folder.*

#### 4. Configure Production Environment (`.env`)
1. If updating an existing installation, your live `.env` inside `htdocs/` is preserved and untouched.
2. If setting up a fresh instance, copy `.env.example` to `.env` in `htdocs/` and configure:
   ```ini
   APP_ENV=production
   APP_SECRET=your_generated_32_to_64_character_hex_secret
   DB_HOST=sqlxxx.infinityfree.com
   DB_PORT=3306
   DB_NAME=if0_xxxxxxxx_afm_db
   DB_USER=if0_xxxxxxxx
   DB_PASS=YourHostingPassword
   TRUST_PROXY_HEADERS=false
   FORCE_HTTPS=false
   SESSION_LIFETIME=86400
   ```
3. Set `.env` permissions to read-only for the web server (`chmod 600` or `chmod 644`). Note that root `.htaccess` already forbids web access to `.env*` files.

#### 5. Database Migration Assessment for this Release
> [!NOTE]
> **Database migration required: NO.**
> The responsive redesign and UI polish release operates entirely within the existing database schema established in migrations 001–014. No new tables, columns, indexes, triggers, or stored procedures were introduced. Existing production data remains 100% compatible.

#### 6. Post-Deployment Verification
Visit your InfinityFree domain (`https://yourname.infinityfreeapp.com/`):
1. **Public Landing / Dashboard:** Verify responsive styling and theme rendering.
2. **Authentication:** Log in with your existing account; verify session creation.
3. **Library View:** Verify catalogue tracks (2,411 baseline), sorting, and single-line numeric audition score rendering on desktop (`42`).
4. **Discovery Shortlist:** Check that status badges display numeric scores cleanly (`✓ SCORED: 40`), and mobile cards display separated metadata with dashed date dividers.
5. **Auditions Scoring:** Open a scorecard, test scoring audio, and verify real-time lyrics synchronization.
6. **Tournament Leaderboards:** Verify stage standings, tie-breaking criteria, and manual qualification wizard.

#### 7. Rollback & Recovery Procedures
If any hosting-level anomalies occur:
- **Files Rollback:** Re-upload your backup of the previous `htdocs/` folder via FTP or File Manager.
- **Database Rollback:** If a database error occurs, import your pre-deployment SQL export into phpMyAdmin.

---

## 7. Automated Test Suite & Isolation

AFM#2 maintains a comprehensive test suite with strict database safety guards to prevent accidental modification of real application data.

### Database Safety Invariant
All test scripts enforce connection strictly to **`afm_test_db`** via [`tests/test_db_guard.php`](file:///mnt/data/GY/Music/AFM%232/tests/test_db_guard.php). If any test script attempts to run against `afm_db` or a production database name pattern, the safety guard throws an immediate exception and halts execution.

### Test Execution Commands

```bash
# 1. Database Safety Guard Verification
php tests/test_db_safety_guard.php

# 2. Bulk Action Safety & Safeguards (40 assertions)
php tests/test_bulk_action_safety.php

# 3. Production Deletion Safeguards (20 assertions)
php tests/test_production_deletion_safeguards.php

# 4. Stage Finalization & Immutability (48 assertions)
php tests/test_stage_finalization_and_immutability.php

# 5. Song Management, Sorting & Theme Styles (77 assertions)
php tests/test_song_management_and_sorting.php

# 6. Tournament Progression & Manual Qualification (33 assertions)
php tests/test_tournament_progression_and_qualification.php

# 7. Authentication & Session Integrity (Batch A, 24 assertions)
php tests/test_batch_a_auth_integrity.php

# 8. HTTPS Detection & Cookie Security (Batch B, 51 assertions)
php tests/test_batch_b_cookie_security.php

# 9. CSV Formula Injection Hardening (Batch C, 63 assertions)
php tests/test_batch_c_csv_security.php

# 10. Request Hardening & Secure Logout (Batch D, 20 assertions)
php tests/test_batch_d_request_hardening.php

# 11. Rate Limiting & Security Headers (Batch E, 47 assertions)
php tests/test_batch_e_rate_limiting_and_headers.php

# 12. Modular Multi-Method Authentication (38 assertions)
php tests/test_modular_auth.php

# 13. Two-User Isolation & Privacy (58 assertions)
php tests/test_multiuser_isolation.php

# 14. Collation Compatibility & Error 1267 Immunity (18 assertions)
php tests/test_production_collation_error_1267.php

# 15. Multi-Source Lyrics System (31 assertions)
php tests/test_multisource_lyrics.php

# 16. Manual Qualification Cancel Button Test
php tests/test_advancement_cancel_button.php
```

---

## 8. Release Information

- **Release Identifier:** `v2.4-responsive-redesign`
- **Active Git Branch:** `impl/responsive-redesign`
- **Target Remote:** `https://github.com/gulshankyy2007/afm2.git`
- **Production Package:** `deployment/afm2-infinityfree-release.zip` (SHA-256: `1cd8a0eb9e09a28be706d2bc4169e3c123af5807ca9b1b23a8fe9a947b8e4dc2`)
- **Hosting Target:** InfinityFree (Apache 2.4 / PHP 8.2+ / MySQL / `htdocs`)
- **License / Ownership:** Annual Musical Finale 2026 (AFM#2). All rights reserved.
