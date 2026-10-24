# AFM#2 — Annual Musical Finale 2026

Personal Music Catalogue, Audition, and Tournament Web Application.

AFM#2 is a personal music management and competition system designed for the 2026 Annual Musical Finale. It enables comprehensive library exploration, fast one-tap shortlist/pass discovery, multi-criteria audition scorecards, and automated tournament progression across 7 stages with strict 5-tier criteria tie-breaking.

---

## 1. System Architecture & Tech Stack

AFM#2 is built strictly with lightweight, native technologies without framework bloat:

- **Backend:** Native PHP 8.5+ (Strict typing, PDO prepared statements, UTF-8mb4, secure session cookies).
- **Database:** MySQL 8.4 (`afm_db`) with `utf8mb4_unicode_ci` and stored generated columns.
- **Frontend:** Semantic HTML5, Vanilla CSS3 (Sleek dark theme, responsive grid/table views, mobile cards), Vanilla ES6+ JavaScript.
- **Zero Framework Bloat:** No React, Vue, Node.js, Express, MongoDB, or Laravel.

---

## 2. Competition Stages & Tournament Rules

The competition progresses through 7 consecutive stages:

| Stage # | Stage Name | Target Count | Description |
|---|---|---|---|
| 1 | **Formal Auditions** | Unlimited | All shortlisted songs from the library are scored across 5 criteria. |
| 2 | **Top 200** | 200 songs | Top 200 highest scoring songs advance to round 2. |
| 3 | **Top 100** | 100 songs | Cutoff to the top 100 songs. |
| 4 | **Top 50** | 50 songs | Cutoff to the top 50 songs. |
| 5 | **Top 20** | 20 songs | Elite semi-final tier. |
| 6 | **Top 10** | 10 songs | The 10 finalists. |
| 7 | **Finals** | 1 winner | Crowning the AFM#2 2026 Champion Song. |

### Evaluation Criteria (0–10 each, /50 Total)

Every audition entry is evaluated across 5 fundamental dimensions:
1. **Melody** (0 to 10)
2. **Lyrics** (0 to 10)
3. **Vocals** (0 to 10)
4. **Emotion** (0 to 10)
5. **Replayability** (0 to 10)

The total score is automatically computed in the database using a `STORED GENERATED` column:
$$\text{Total} = \text{Melody} + \text{Lyrics} + \text{Vocals} + \text{Emotion} + \text{Replayability}$$

### Incomplete vs. Complete Scorecards
- If any criterion is left blank, the database stores `total` as `NULL`.
- The application flags the scorecard as **Incomplete** with a partial indicator (e.g. `28* / 50`).
- You can resume incomplete scorecards at any time.
- Only **Complete** scorecards (all 5 criteria evaluated) appear on official tournament stage leaderboards.

### Official Tie-Breaking Hierarchy
When songs have identical total scores, ties are deterministically resolved using secondary criteria in exact descending priority:
1. **Total Score** (/50)
2. **Emotion** (0–10)
3. **Replayability** (0–10)
4. **Melody** (0–10)
5. **Vocals** (0–10)
6. **Lyrics** (0–10)
7. **Entry ID ASC** (Stable secondary fallback)

---

## 3. Directory Structure

```text
/mnt/data/GY/Music/AFM#2/
├── .env                          # Local environment secrets (chmod 600)
├── .env.example                  # Template configuration
├── config/
│   ├── config.php                # App config, .env parser, session security
│   └── db.php                    # PDO connection handler (UTF-8mb4, emulated prepares off)
├── includes/
│   ├── auth.php                  # Authentication, password hashing, IP rate limiting
│   ├── csrf.php                  # Timing-safe CSRF token generation & validation
│   ├── db_helper.php             # Core queries: catalogue, auditions, rankings, advancement
│   ├── header.php                # Main layout header, navigation, and user pill
│   └── footer.php                # Layout footer, scripts, and toast container
├── migrations/
│   ├── migration_runner.php      # Migration tracking runner (schema_migrations)
│   └── 001_init_app_tables.php   # Additive schema setup (app_users, song_discovery, etc.)
├── public/                       # Web server document root
│   ├── assets/
│   │   ├── css/style.css         # Dark theme design system & responsive styling
│   │   └── js/app.js             # Client interactivity, AJAX scorecards, toasts, modals
│   ├── api.php                   # Secure CSRF-protected JSON API endpoint
│   ├── auditions.php             # Formal audition multi-criteria scoring interface
│   ├── discovery.php             # Dedicated shortlist management view
│   ├── index.php                 # Application dashboard & progression overview
│   ├── library.php               # 50/page catalogue browser & search
│   ├── login.php                 # Authentication screen
│   ├── logout.php                # Session termination
│   ├── results.php               # Historical stage archive, CSV export, winner podium
│   ├── setup.php                 # One-time initial account setup (locked once created)
│   └── tournament.php            # Stage leaderboards, cutoff lines & advancement
├── tests/
│   └── test_suite.php            # Comprehensive automated CLI test suite (61 assertions)
└── README.md                     # System documentation & operating guide
```

---

## 4. Getting Started & Running Locally

### Step 1: Verify Environment Configuration
Ensure `.env` exists in the project root:
```ini
APP_ENV=development
APP_SECRET=your_secure_generated_64_character_hex_secret
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=afm_db
DB_USER=gulshan
DB_PASS=your_database_password
SESSION_LIFETIME=86400
```

### Step 2: Run Database Migrations
Verify or run migrations to ensure all additive tables and seeded tournament rounds are in place:
```bash
php migrations/migration_runner.php
```

### Step 3: Start the Built-in PHP Web Server
Start the local server pointing to the `public/` directory:
```bash
php -S 127.0.0.1:8080 -t public/
```

### Step 4: Access the Web Application
Open your browser and navigate to:
**`http://127.0.0.1:8080/`**

---

## 5. User Authentication & Security

The application uses secure session authentication with IP-based rate limiting (5 failed attempts locks the IP for 15 minutes).

- **Administrator Username:** `gulshan`
- **Password:** Configured during initial setup or managed securely via the database.

> **Note on Initial Setup:** If no users exist in `app_users`, visiting `http://127.0.0.1:8080/setup.php` provides a one-time setup form to register an administrator account. Once an account exists, `setup.php` is permanently locked.

---

## 6. Running the Automated Test Suite

A complete test suite is provided in `tests/test_suite.php`. It performs 61 automated assertions across:
- **Baseline Catalogue Integrity:** Strictly verifies 2,475 songs intact and checks NULL artist handling.
- **Security & CSRF:** Timing-safe token generation and validation.
- **Authentication & Rate Limiting:** Login validation, setup locking, and IP lockout enforcement.
- **Song Search & Unicode Safety:** Prepared statement safety against SQL injection and Unicode text.
- **Discovery Flow:** Shortlisting and passing persistence.
- **Audition Scorecard Validation:** Bounds checking (0–10), generated column calculation, and incomplete scorecard handling.
- **Leaderboard Tie-Breaking:** Emotion > Replayability > Melody > Vocals > Lyrics sorting.
- **Stage Advancement:** Transactional commit, results audit logging, and double-advancement prevention.

To execute the test suite:
```bash
php tests/test_suite.php
```

Expected output:
```text
======================================================================
 TEST EXECUTION SUMMARY
======================================================================
Total Tests Executed: 97
Passed: 97
Failed: 0

🎉 ALL TESTS PASSED SUCCESSFULLY! (100% PASS RATE)
```

---

## 7. Audio & Synchronized Lyrics Experience

AFM#2 integrates a zero-cost, legal-compliance audio playback and lyrics experience:
- **Zero-Cost YouTube Search & Playback:** Generates direct YouTube and YouTube Music search queries without API keys. Confirmed tracks play in privacy-enhanced YouTube embeds (`youtube-nocookie.com/embed/{id}?autoplay=0&enablejsapi=1`) with external fallback links.
- **On-Demand LRCLIB Lyrics:** Free lyrics lookups via LRCLIB public API with in-memory and MySQL database caching (`song_lyrics_ref`).
- **Real-Time Synced Lyrics Follower:** Integrates with YouTube IFrame API to poll playback position, automatically highlighting the active lyric line in real time and smoothly auto-scrolling. Clicking any timestamp jumps the player immediately.
- **Smart Metadata Cleaning:** Strips video noise (`[Official Video]`, `[MV]`, `(Visualizer)`), remaster tags (`(Remastered 2011)`), and featured artists to ensure high LRCLIB hit rates.
- **Duration-Tolerant Candidate Ranking:** When multiple lyric versions exist, candidates are scored using $\pm 2$s and $\pm 5$s duration proximity heuristics.

### Attribution & Open-Source Provenance
- **LRCLIB:** Lyrics provided on-demand via the free public [LRCLIB](https://lrclib.net) community database.
- **Echo Music:** Conceptual inspiration for regex-based metadata sanitization rules, duration proximity tolerance thresholds ($\pm 2$s and $\pm 5$s), and player timeline lyrics synchronization derived from the open-source [Echo Music](https://github.com/EchoMusicApp/Echo-Music) project (GPL-3.0, EchoMusicApp/Echo-Music). Implemented independently in clean-room PHP 8.2 and vanilla JavaScript for AFM#2.

---

## 8. Database Backup & Catalogue Preservation

The application strictly maintains the invariant that the baseline catalogue of 2,475 songs is never modified, dropped, or overwritten.

To create a full backup of the database:
```bash
mysqldump -h 127.0.0.1 -u gulshan -p afm_db > afm_db_backup_$(date +%Y%m%d).sql
```

To restore from a backup:
```bash
mysql -h 127.0.0.1 -u gulshan -p afm_db < afm_db_backup_YYYYMMDD.sql
```

