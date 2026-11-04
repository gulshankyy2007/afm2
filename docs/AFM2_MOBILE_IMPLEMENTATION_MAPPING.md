# AFM#2 — Mobile Prototype Implementation Mapping Matrix
**Project:** AFM#2 (Advanced Feature Matrix #2)  
**Task Classification:** Technical Architecture & Implementation Roadmap (Phase 0)  
**Status:** Approved for Prototyping  
**Author:** Antigravity AI Engineering & Design Team  
**Date:** November 2026  

---

## 1. Purpose & Implementation Scope

This document establishes the exact mapping between the **AFM#2 Mobile UI Prototypes** (located in `/prototype`) and the **Actual Production Application Codebase** (located in `/public`, `/includes`, and MySQL schema).

> [!IMPORTANT]
> **Production Implementation Authorization is NOT GRANTED.**  
> This mapping serves as the authoritative blueprint for when production engineering authorization is formally granted in Phase 1. Zero modifications to production files are permitted at this time.

---

## 2. Global Shell & Navigation Mapping

| Prototype Component | Prototype Source | Production Target File(s) | Implementation Specifics & Hooks |
|---|---|---|---|
| **Compact Header (52px)** | `index.html` (`.mobile-app-header`) | `includes/header.php` | Insert `<header class="mobile-app-header">` conditionally on mobile viewports (`@media (max-width: 768px)`). Embed brand logo, dynamic stage pill derived from `get_current_tournament_stage()`, and mobile search trigger. |
| **Animated Equalizer** | `.sound-equalizer` in `prototype.css` | `public/assets/css/mobile.css` | Pure CSS keyframe animation (`equalizer-bars`). Connect playback active state to global audio/YouTube state in `app.js`. |
| **5-Tab Bottom Navigation** | `index.html` (`.mobile-bottom-nav`) | `includes/footer.php` | Insert `<nav class="mobile-bottom-nav">` before `</body>`. Determine active tab server-side via `basename($_SERVER['PHP_SELF'])` or client-side router in `app.js`. |
| **"More" Modal Bottom Sheet** | `index.html` (`#moreModalSheet`) | `includes/footer.php`, `public/assets/js/app.js` | Rendered as reusable dialog markup in `footer.php`. Opened via `#tabMore` or header palette icon. Styled with backdrop-filter blur and sliding translateY transform. |
| **Theme System Integration** | `#themeSelect` in `prototype.js` | `includes/preferences_helper.php`, `public/account.php`, `style.css` | Production persists theme via `$_SESSION['theme']` and `user_preferences` database table. Prototypes use exact theme identifiers: `midnight_ember`, `velvet_amethyst`, `emerald_forest`, `vintage_paper`, `nordic_frost`, `solar_terracotta`, `oceanic`, `quiet_minimal`. |
| **Toast Notification System** | `#toastContainer` in `prototype.js` | `public/assets/js/app.js` (`showToast()`) | Replaces native browser `alert()` with AFM#2 floating pill toasts. |

---

## 3. Screen-by-Screen Implementation Mapping Matrix

---

### Screen 1: Home / Dashboard

- **Prototype Screen ID:** `screen-home`
- **Production Target View:** `public/index.php`
- **Production Helpers:** `includes/db_helper.php`, `includes/media_helper.php`

| Prototype Element | Production Backend Hook / DB Source | Production CSS / JS Hook |
|---|---|---|
| **Welcome & Stage Stepper** | `get_current_tournament_stage()` returning active round string (`Top 200`, `Active`). | `.mobile-hero-progress`, `public/assets/css/mobile.css` |
| **Horizontal Action Pills** | Quick links to `auditions.php`, `library.php`, `tournament.php`. | `.quick-actions-row` with horizontal scroll snap. |
| **Featured Turntable Card** | `get_random_shortlisted_song()` or latest added track from `songs` table (`song_id`, `title`, `artist`, `media_video_id`). | `.turntable-card`, vinyl rotation animation when video active. |
| **Pinterest Shortlist Shelf** | `get_songs(['status' => 'shortlisted', 'limit' => 4])`. Columns: `title`, `artist`, `audition_total`. | `.pinterest-grid`, `.art-card` in `mobile.css`. |
| **Metric Summary Grid** | `get_catalogue_metrics()`: total songs count, evaluated count, shortlisted count, remaining quota. | `.metric-grid-2x2`, `.metric-tile`. |

---

### Screen 2: Library Discover

- **Prototype Screen ID:** `screen-library-discover`
- **Production Target View:** `public/library.php` (Discover Mode)
- **Production Helpers:** `includes/db_helper.php`, `includes/artwork_helper.php`

| Prototype Element | Production Backend Hook / DB Source | Production CSS / JS Hook |
|---|---|---|
| **2-Column Pinterest Grid** | `get_songs(['page' => $p, 'limit' => 20, 'filter' => $f])`. Fetches `artwork_url`, `title`, `artist`, `audition_total`. | `display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;` in `mobile.css`. |
| **Genre / Mood Filter Chips** | `get_unique_genres()` from `songs` table. Horizontal scrolling pill container. | `.filter-chip-row`, `.filter-chip.active`. |
| **Quick Star Toggle (`★`)** | `POST api.php?action=toggle_shortlist` with CSRF token and `song_id`. Updates `discovery_status`. | Event listener in `app.js` triggering optimistic UI star fill. |
| **Procedural Vinyl Fallback** | `artwork_helper.php` placeholder generator when `artwork_url` is null/empty. | `.vinyl-disc-fallback` CSS procedural grooved vinyl. |

---

### Screen 3: Library Manage

- **Prototype Screen ID:** `screen-library-manage`
- **Production Target View:** `public/library.php` (Manage Mode)
- **Production Helpers:** `includes/db_helper.php`

| Prototype Element | Production Backend Hook / DB Source | Production CSS / JS Hook |
|---|---|---|
| **Segmented Switcher** | Client-side tab state persisted in `localStorage('afm2_lib_mode')` or query parameter `?mode=manage`. | `.segmented-view-switcher` in `mobile.css`. |
| **52px Compact Track Rows** | `songs` table: `song_id`, `title`, `artist`, `discovery_status`, `audition_total`, `audition_incomplete`. | `.track-manage-row` (fixed min-height 52px for touch). |
| **Multi-Selection Checkbox** | Form input `name="selected_songs[]"` for batch actions. | 44px tap zone container around `<input type="checkbox">`. |
| **3-Dot Contextual Action Menu** | Triggers bottom action sheet with: Edit (`library.php?action=edit&id=X`), Delete (subject to safeguards), Audition (`auditions.php?id=X`). | Dynamic bottom sheet handler in `app.js`. |
| **Catalogue Operations** | Add Song modal, CSV Import (`import.php`), CSV Export (`api.php?action=export_library`). | Action footer buttons mapped to existing PHP scripts. |

---

### Screen 4: Song Detail

- **Prototype Screen ID:** `screen-song-detail`
- **Production Target View:** `public/library.php?action=view&id=X` (or mobile slide-over sheet)
- **Production Helpers:** `includes/media_helper.php`, `includes/artwork_helper.php`

| Prototype Element | Production Backend Hook / DB Source | Production CSS / JS Hook |
|---|---|---|
| **Large Album Artwork Frame** | `artwork_url` from `songs` table, rendered at 260px with rounded corners and ambient box shadow. | `.song-detail-art-frame` in `mobile.css`. |
| **Metadata Tag Cluster** | `genre`, `language`, `year`, `tempo`, `mood` from `songs` table. | `.detail-tag-cluster`, `.badge-pill`. |
| **Scorecard Overview Card** | `audition_total`, `emotion`, `replayability`, `melody`, `vocals`, `lyrics` from `songs` table. | Monospaced scorecard badge `.score-pill-lg`. |
| **Embedded YouTube Player** | `media_video_id` via `media_helper.php` rendering YouTube NoCookie responsive iframe. | `.video-embed-container` (16:9 aspect ratio). |
| **Expandable Lyrics Panel** | LRCLIB synchronized or plain lyrics fetched via `db_helper.php` (`lyrics` text field). | `.lyrics-accordion-panel`, animated max-height. |

---

### Screen 5: Formal Auditions

- **Prototype Screen ID:** `screen-auditions`
- **Production Target View:** `public/auditions.php`
- **Production Helpers:** `includes/db_helper.php`, `includes/media_helper.php`

| Prototype Element | Production Backend Hook / DB Source | Production CSS / JS Hook |
|---|---|---|
| **Single-Song Entry Flow** | `auditions.php?entry=X`. Fetches current contender in stage. | `.audition-single-card-container` replacing desktop 25-card sheet. |
| **Pagination Navigation** | `< Prev` and `Next >` buttons passing `entry_index` query parameters. | Touch-friendly 44px pagination header `.entry-nav-strip`. |
| **5 Scoring Sliders** | Canonical 5 criteria: `emotion`, `replayability`, `melody`, `vocals`, `lyrics` (0–10 scale). | `<input type="range" min="0" max="10" step="1">` with custom thumb styling in `prototype.css`. |
| **Real-Time Score Arithmetic** | JavaScript real-time calculation: $\sum (\text{criteria}) \in [0, 50]$. | `prototype.js` listener updating `#auditionTotalDisplay` and status badge (`Complete` vs `In-Progress`). |
| **Save Scorecard Action** | `POST auditions.php?action=save_score` with CSRF token, `song_id`, 5 criteria values, and `total`. | Fixed sticky bottom bar with `btn-primary`. |
| **Historical Immutability Rule** | Check `is_stage_locked($stage_id)`. If locked, disable all inputs and display padlock banner. | `disabled` attribute on sliders; banner `.stage-locked-alert`. |

---

### Screen 6: Recordings

- **Prototype Screen ID:** `screen-recordings`
- **Production Target View:** `public/recordings.php`
- **Production Helpers:** `includes/youtube_matcher.php`, `includes/media_helper.php`

| Prototype Element | Production Backend Hook / DB Source | Production CSS / JS Hook |
|---|---|---|
| **Candidate Search & Filter** | Searches `media_library` and `songs` by title, artist, or YouTube video ID. | `.search-input-wrapper` with live debounce filter. |
| **YouTube Matcher Cards** | `youtube_matcher.php` candidate list: video ID, title, channel, duration, match confidence. | `.recording-match-card` in `mobile.css`. |
| **Responsive Player Embed** | `media_helper.php` secure YouTube embed. | `.video-embed-frame` (touch-optimized player controls). |
| **Re-Match / Confirm Actions** | `POST recordings.php?action=set_primary_video` with CSRF token and `video_id`. | `.btn-group-responsive`, toast feedback upon update. |

---

### Screen 7: Tournament

- **Prototype Screen ID:** `screen-tournament`
- **Production Target View:** `public/tournament.php`
- **Production Helpers:** `includes/db_helper.php`

| Prototype Element | Production Backend Hook / DB Source | Production CSS / JS Hook |
|---|---|---|
| **Active Stage Header** | `get_tournament_stages()`: active stage name (`Top 200`), participant count, evaluation progress %. | `.stage-status-banner` with glowing accent border. |
| **Stage Advancement Trigger** | Button launching Manual Qualification Wizard. Active only when authorized judge/admin logged in. | `#btnLaunchWizard` mapped to modal or `tournament.php?action=qualify`. |
| **Leaderboard Ladder** | `get_round_rankings($round_id)`: ranks songs by total score using the exact 7-field sequence. | `.tournament-ladder-list`. |
| **Exact 7-Field Ranking Hierarchy** | SQL order: `r.total` DESC $\rightarrow$ `r.emotion` DESC $\rightarrow$ `r.replayability` DESC $\rightarrow$ `r.melody` DESC $\rightarrow$ `r.vocals` DESC $\rightarrow$ `r.lyrics` DESC $\rightarrow$ `e.entry_id` ASC. | Preserved identically in `get_round_rankings()` SQL query. |
| **Advancement Cutoff Line** | Visual divider inserted after target cutoff rank (e.g. rank 100 for Top 200 $\rightarrow$ Top 100). | `.advancement-cutoff-divider` with "Advancing" vs "Eliminated" zone. |

---

### Screen 8: Manual Qualification Wizard

- **Prototype Screen ID:** `screen-qualification`
- **Production Target View:** `public/tournament.php?action=qualify` (or standalone touch sheet)
- **Production Helpers:** `includes/db_helper.php`

| Prototype Element | Production Backend Hook / DB Source | Production CSS / JS Hook |
|---|---|---|
| **3-Step Stepper Header** | Wizard step state tracker: Step 1 (Select) $\rightarrow$ Step 2 (Arrange) $\rightarrow$ Step 3 (Review & Lock). | `.wizard-step-progress-strip`, step pills with completed checkmarks. |
| **Step 1: Quota Validator** | Target quota derived strictly from active stage transition (e.g. Top 200 $\rightarrow$ Top 100 requires exactly 100; Top 10 $\rightarrow$ Finals requires exactly 1 Champion). | Dynamic quota counter: `100 / 100 Selected ✓ Met`. Disables Next if quota unmet. |
| **Step 2: Touch Move Up/Down** | Discrete 44px buttons (`▲` / `▼`) triggering array swap in JavaScript state: `[arr[i], arr[i-1]] = [arr[i-1], arr[i]]`. | Eliminates mobile drag-and-drop glitches. Preserves exact human ordering into `round_results.rank_position` and `manual_order`. |
| **Step 3: Pre-Flight Audit** | Checklist validating: Exact quota met, Zero incomplete evaluations, Valid CSRF token, Admin session verified. | `.preflight-audit-card` with green checks. |
| **Stage Lock Commitment** | `POST tournament.php?action=commit_qualification` with JSON ordered IDs. Sets `is_locked = 1`. | Double-confirmation modal with red warning: "Irreversible Stage Lock". |

---

### Screen 9: Archive

- **Prototype Screen ID:** `screen-archive`
- **Production Target View:** `public/results.php`
- **Production Helpers:** `includes/db_helper.php`

| Prototype Element | Production Backend Hook / DB Source | Production CSS / JS Hook |
|---|---|---|
| **Champion Callout Tile** | `get_tournament_champion()`: retrieves winner from finalized Finals stage. | `.archive-champion-hero-card` with golden crown emblem. |
| **Accordion Stage Sections** | `get_completed_tournament_stages()`: generates collapsible cards for each finalized round. | `.archive-stage-accordion`, tap to expand/collapse. |
| **Historical Scorecard Rows** | Read-only song rows: Rank, Title, Artist, Final Score, Tie-Breaker metrics. | `.archive-row-compact`. |
| **Stage CSV Export** | Link to `results.php?action=export_stage_csv&stage_id=X`. | Touch button `.btn-export-csv` (44px hit target). |

---

### Screen 10: Champion Presentation (Hall of Fame)

- **Prototype Screen ID:** `screen-champion`
- **Production Target View:** `public/results.php?view=champion`
- **Production Helpers:** `includes/db_helper.php`, `includes/artwork_helper.php`

| Prototype Element | Production Backend Hook / DB Source | Production CSS / JS Hook |
|---|---|---|
| **Luxury Gold Artwork Frame** | `artwork_url` of tournament champion with dual gold border and radial halo glow. | `.champion-gold-frame` in `prototype.css`. |
| **Authoritative Finals Score** | **MUST** source from the finalized Finals round (`audition_total` from Finals evaluation). | Displayed in Cormorant Garamond / Outfit at 2.5rem: `50 / 50`. |
| **Tournament Milestone Trail** | Chronological journey tracking scores across all 7 rounds: Formal Auditions $\rightarrow$ Top 200 $\rightarrow$ Top 100 $\rightarrow$ Top 50 $\rightarrow$ Top 20 $\rightarrow$ Top 10 $\rightarrow$ Finals. | `.milestone-journey-trail`, vertical dashed connecting line with glowing nodes. |


---

## 4. Security, Authentication & Safeguards Mapping

All prototype interactions are governed by existing backend security controls:

```
+-------------------------------------------------------------------------------+
|                       SECURITY & INTEGRITY CONTROLS                           |
+--------------------------+-----------------------+----------------------------+
| Action                   | Security Enforcement  | Production Implementation  |
+--------------------------+-----------------------+----------------------------+
| Scorecard Modification   | CSRF Token Check      | `csrf_verify()` in         |
|                          | + Active Session      | `includes/csrf.php`        |
+--------------------------+-----------------------+----------------------------+
| Stage Lock & Advancement | Admin Authorization   | `require_admin()` in       |
|                          | + Irreversible Flag   | `includes/auth.php`        |
+--------------------------+-----------------------+----------------------------+
| Song Deletion            | Multi-Step Safeguard  | Check for tournament stage |
|                          | + Historical Guard    | references before delete   |
+--------------------------+-----------------------+----------------------------+
| API Endpoints            | Session Validation    | `session_start()` + JSON   |
|                          | + Input Sanitization  | response error handling    |
+--------------------------+-----------------------+----------------------------+
```

---

## 5. Phased Production Migration Checklist

When implementation authorization is granted in Phase 1, engineering will follow this exact rollout sequence:

1. **Phase 1A: Mobile Stylesheet Injection:**
   - Add `<link rel="stylesheet" href="assets/css/mobile.css">` to `includes/header.php`.
   - Port prototype tokens and component classes into `public/assets/css/mobile.css`.
2. **Phase 1B: Navigation Dual-Architecture:**
   - Update `includes/header.php` to render `.mobile-app-header` on viewports $\le 768\text{px}$.
   - Update `includes/footer.php` to render `.mobile-bottom-nav` and `#moreModalSheet`.
3. **Phase 1C: Library Dual-Mode:**
   - Implement `?view=discover` vs `?view=manage` in `public/library.php`.
   - Render 2-column Pinterest grid for discover; render 52px rows for manage.
4. **Phase 1D: Formal Auditions Single-Song View:**
   - Add single-song pagination mode in `public/auditions.php` with 44px range sliders.
5. **Phase 1E: Manual Qualification Wizard Touch Controls:**
   - Port the 3-step sequential sheet and `Move Up` / `Move Down` touch handlers into `public/tournament.php`.
