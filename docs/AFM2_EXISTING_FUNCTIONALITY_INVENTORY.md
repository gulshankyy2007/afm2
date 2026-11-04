# AFM#2 — Existing Application & Functionality Inventory

**Document Classification:** Technical Audit & Complete Functionality Inventory  
**Project:** AFM#2 — Annual Musical Finale 2026  
**Status:** Audit & Preservation Baseline — *Implementation Authorization NOT GRANTED*  
**Repository Branch / Commit:** `perf/multiuser-concurrency` / `97fe317`  
**Date:** November 2, 2026  

---

## 1. Audit Baseline & Repository Overview

```
+---------------------------------------------------------------------------------------------------------+
|                                      APPLICATION ARCHITECTURE AUDIT                                     |
+---------------------+-----------------------------------------------------------------------------------+
| Parameter           | Inspected Repository Value                                                        |
+---------------------+-----------------------------------------------------------------------------------+
| Active Git Branch   | perf/multiuser-concurrency                                                        |
| HEAD Commit Hash    | 97fe317 (feat(a11y): modal dialogs, forms, touch targets, and accessibility...)   |
| Core Backend Stack  | PHP 8.1+ / MySQL (PDO with prepared statements & multi-user data isolation)       |
| Frontend Assets     | Vanilla CSS (style.css 295 KB, mobile.css 33 KB) + Vanilla JS (app.js 223 KB)      |
| Authentication      | Session-based + WebAuthn Passkeys + Google OAuth + Multi-User Role Isolation       |
| External APIs       | YouTube Data API / NoCookie Embeds + LRCLIB Synced Lyrics + Cover Art Archive     |
| Hosting Constraint  | Free Shared Hosting (₹0/mo, strict CPU/execution time limits, no Node.js runtime)  |
+---------------------+-----------------------------------------------------------------------------------+
```

---

## 2. Complete Page-by-Page Application Inventory

### A. Dashboard & Journal (`public/index.php`)
* **Core Purpose:** The central listening sanctuary, daily musical journal, and high-level tournament tracker.
* **Key Functionality & Components:**
  1. *Welcome Hero Banner:* Displays current competition stage tag, philosophical listening quote, and 4 immediate action buttons (`Resume Auditions`, `Discover Catalogue`, `Tournament Stage`, `Contender Shortlist`).
  2. *Featured Listening Card:* "Currently on the turntable" tactile showcase card featuring 1:1 vinyl cover art frame, score badge (`Total / 50` or `Awaiting Score`), track title, artist, catalogue track ID, and 3 actions (`Open Audition Scorecard`, `Read Lyrics`, `YouTube ↗`).
  3. *Tournament Milestones Stepper:* Visual timeline of all 7 tournament rounds with sequence numbers, stage names, qualification target badges (e.g., `Top 200`, `Top 100`, `All`), and dynamic state indicators (`completed`, `active`, `upcoming`).
  4. *Shortlisted Contenders Shelf:* Grid/shelf of up to 6 recently shortlisted tracks with album cover thumbnails, score pills, quick scorecard links, and one-tap lyrics modal triggers.
  5. *Catalogue & Audition Ledger:* 4 real-time stat cards with progress meters:
     * Full Catalogue count (`1,400+` preserved database tracks).
     * Shortlisted Contenders count & percentage.
     * Discovery Review progress count, unreviewed count, and percentage bar.
     * Auditions Completed count, incomplete scorecards count, and completion percentage bar.
* **Dependencies:** `get_dashboard_stats()`, `get_default_atmospheric_preset()`, `includes/header.php`, `includes/footer.php`.
* **Current Mobile Behaviour:** Media queries in `mobile.css` stack the turntable card and compress the stats grid into a 2x2 grid. The hero action buttons stack vertically.
* **Critical Preservation Rules:** No statistics, progress meters, or direct entry points to Auditions/Shortlist may be removed.

---

### B. Song Library & Catalogue Management (`public/library.php`)
* **Core Purpose:** Authoritative catalogue database management, search, multi-field sorting, filtering, and CRUD operations.
* **Key Functionality & Components:**
  1. *Search Bar:* Instant title and artist substring search with clear button (`✕`) and enter/click submit.
  2. *Status Filter Pills:* Real-time counter pills for `All`, `Unreviewed`, `Shortlisted`, and `Passed`.
  3. *Sorting Engine:* 6 valid sort modes: `Catalogue ID (Asc/Desc)`, `Title (A→Z / Z→A)`, `Artist (A→Z / Z→A)`, `Newest Added First`.
  4. *Per-Page Selector:* 25, 50 (default), 75, 100 records per page.
  5. *Catalogue Data Table:*
     * Row checkbox for bulk selection.
     * Catalogue ID with sort direction arrow.
     * Title with album thumbnail, AFM#2 player link, direct YouTube link, and quick lyrics modal button.
     * Artist name with fallback for "Unknown Artist".
     * Review status badge (`Shortlisted`, `Passed`, `Not Reviewed`).
     * Audition score badge (`XX/50`, `Incomplete`, or `Score →`).
     * One-tap Discovery buttons (`★ Shortlist`, `✕ Pass`).
     * Row Management buttons (`✏️ Edit`, `🗑️ Delete`).
  6. *Bulk Actions Floating Bar:* Appears when checkboxes are selected; displays count of selected tracks, `Delete Selected` trigger, and `Clear Selection`.
  7. *Add Song Modal (`#addSongModal`):* Dual-tab modal:
     * *Tab 1 (YouTube Extract):* Pastes URL/ID, auto-fetches title/artist/thumbnail via `api.php`, detects existing catalogue matches to prevent duplicates, and allows setting initial review status.
     * *Tab 2 (Manual Entry):* Manual inputs for Title, Artist, YouTube link, and initial status.
  8. *Edit Song Modal (`#editSongModal`):* Updates title, artist, and YouTube URL with auto-fill helper.
  9. *Delete Song Safeguard Modal (`#deleteSongModal`):* Prevents deletion if track has completed scorecards or tournament history.
  10. *Bulk Delete Safeguard Modal (`#bulkDeleteModal`):* Automatically filters out rated/tournament tracks and only deletes unrated tracks.
  11. *CSV Import & Export:* Links to `import.php` and export endpoints.
* **Dependencies:** `search_songs()`, `api.php`, `includes/media_helper.php`, `includes/artwork_helper.php`.
* **Current Mobile Behaviour:** Table rows convert into stacked card blocks via `mobile.css` `data-label` attributes. While functional, cards are tall and require heavy scrolling.
* **Critical Preservation Rules:** Zero loss of bulk selection, duplicate detection, tournament deletion safeguards, or sort/filter options.

---

### C. Discovery Shortlist & Formal Auditions (`public/discovery.php` & `public/auditions.php`)

#### `public/discovery.php` (Contender Shortlist)
* **Core Purpose:** Dedicated staging ground for tracks earmarked for the competition before or during scoring.
* **Key Functionality:**
  * 4 summary stat cards (`Total Shortlisted`, `Audition Completed`, `Incomplete Scorecards`, `Pending Scoring`).
  * Search by title/artist within shortlisted tracks.
  * Status filtering (`All`, `Completed`, `Incomplete`, `Pending`).
  * One-tap removal from shortlist (with confirmation safeguard).
  * Direct link to launch formal audition scorecard.

#### `public/auditions.php` (Formal Auditions Scorecards)
* **Core Purpose:** The rigorous musical evaluation engine of AFM#2.
* **Key Functionality & Scoring Mathematics:**
  1. *Stage Navigation Stepper:* Select between active tournament rounds (`Formal Auditions`, `Top 200`, `Top 100`, etc.).
  2. *Single-Song Scorecard Card:*
     * Prominent album cover art frame with artwork discovery button (`🖼️ Artwork`) and source attribution link.
     * Stream beacon & live playback status pill (`Ready to Play`, `Playing`, `Paused`).
     * Authoritative Total Score Display:
       * Complete state: `XX / 50` in success green with completion badge.
       * Incomplete state: `XX* / 50` in gold with partial sum calculation and "Incomplete Scorecard" badge.
       * Unscored state: `— / 50` in muted gray.
     * Integrated Embedded YouTube NoCookie Player (strict origin, JS API enabled, embed-blocked fallback handling).
     * Dual-Mode Lyrics Panel: Plain lyrics, Synced (LRC) lyrics, Manual private lyrics editor, provider switcher (`Auto`, `LRCLIB`, `Lyrics.ovh`, `Manual`), and search override tool.
     * 5 Scoring Criteria (0–10 integer inputs + synchronised range sliders):
       * **Melody** (0–10)
       * **Lyrics** (0–10)
       * **Vocals** (0–10)
       * **Emotion** (0–10)
       * **Replayability** (0–10)
     * Auto-Total Score Calculation: Calculated strictly on the client and server as $\text{Total} = \text{Melody} + \text{Lyrics} + \text{Vocals} + \text{Emotion} + \text{Replayability}$.
     * Action Buttons: `Save Scorecard` (AJAX submit), `Reset Score` (clears scores back to null), `Remove Shortlist` (Round 1 only), and `Change Recording` matcher form.
* **Dependencies:** `get_audition_entries()`, `save_audition_score()`, `LRCLIB API`, `api.php`.
* **Current Mobile Behaviour:** Hero row stacks; player iframe, lyrics drawer, and 5 criteria sliders collapse vertically. Takes up significant screen real estate per song.
* **Critical Preservation Rules:** Exact 5-criteria math, partial-sum indicator (`*`), incomplete resumption state, and YouTube/Lyrics drawer capabilities must remain 100% intact.

---

### D. YouTube Recordings Discovery & Matching (`public/recordings.php`)
* **Core Purpose:** Automated and manual linkage between catalogue songs and official YouTube recordings.
* **Key Functionality:**
  1. *Catalogue Match Statistics:* Total songs, matched total, review needed (50–78% confidence), unresolved (<50%), manual selections (protected from overwrites), and embed blocked.
  2. *Automated Resumable Bulk Matcher:* Background/chunked batch runner that evaluates 7 weighted signals to auto-link high-confidence tracks ($\ge 78\%$).
  3. *Candidate Evaluation Strip:* List of songs with candidate cards showing video thumbnail, video title, channel name, match confidence score, duration, and view count.
  4. *Manual Recording Matcher:* Text input for YouTube URL or 11-character video ID, version type selector (`Original Studio`, `Live Performance`, `Acoustic`, `Cover`, `Remaster`, `Other`), version notes, preview player, and confirm/delete buttons.
* **Dependencies:** `YouTubeMatcher` class, `includes/media_helper.php`, `public/assets/js/recordings.js`.
* **Current Mobile Behaviour:** Batch runner panel and candidate cards compress into single-column layout; action buttons wrap.
* **Critical Preservation Rules:** 7-signal candidate ranking display, version tagging, manual lock protection, and embed re-testing controls must be preserved.

---

### E. Tournament Progression & Leaderboards (`public/tournament.php`)
* **Core Purpose:** Real-time leaderboard tracking and official stage advancement.
* **Key Functionality & Tie-Breaking Rules:**
  1. *Stage Stepper:* Shows all 7 stages with active/completed status badges.
  2. *Official Ranking Table:*
     * Calculated Rank (`🥇 #1`, `🥈 #2`, `🥉 #3`, `#4...`).
     * Song Title and Artist.
     * Equal Criteria Tie Badge (triggers when two entries are identical across all 5 criteria).
     * Total Score (`XX/50`).
     * Individual Criteria Columns: **Emotion**, **Replayability**, **Melody**, **Vocals**, **Lyrics**.
     * Qualification Status Badge: `🏆 Champion`, `✓ Advanced`, `● Qualifying`, `Below Cutoff`, `Eliminated`.
  3. *Visual Target Cutoff Line:* Distinct divider line marking the target qualification threshold (e.g., between Rank 200 and Rank 201).
  4. *Strict 5-Tier Tie-Breaking Hierarchy:*
     $$\text{Total Score} \longrightarrow \text{Emotion} \longrightarrow \text{Replayability} \longrightarrow \text{Melody} \longrightarrow \text{Vocals} \longrightarrow \text{Lyrics} \longrightarrow \text{Entry ID}$$
  5. *Stage Status Controls:*
     * If In Progress: `Manual Qualification & Advance to Next Stage` button.
     * If Completed: `🔒 Stage Finalized & Immutable` badge + Admin Exceptional Correction tool.
* **Dependencies:** `get_round_rankings()`, `get_tournament_rounds()`, `api.php`.
* **Current Mobile Behaviour:** The 9-column leaderboard table triggers horizontal overflow scrolling. Rank badges and criteria pills become cramped.
* **Critical Preservation Rules:** Exact tie-breaker hierarchy order, cutoff line visibility, and immutability locks must remain completely authoritative.

---

### F. Manual Qualification & Advancement Wizard
* **Core Purpose:** The multi-step modal dialog where judges manually curate, verify, reorder, and commit qualifiers advancing to the next tournament stage.
* **Detailed Step-by-Step Architecture:**
  1. *Step 1 — Candidate Selection & Rule Validation:*
     * Loads eligible candidates from current round via `api.php?action=get_qualification_data`.
     * Pre-selects top $N$ candidates based on official tie-broken rankings.
     * Rule Validation Badges:
       * **Target Count Check:** Current selections vs. target cutoff (e.g., `200 / 200 Selected`).
       * **Minimum Eligible Check:** Verifies sufficient candidates exist (e.g., at least 201 eligible).
       * **Evaluation Completeness Check:** Blocks advancement if any candidate has incomplete scorecards.
     * Candidate Table: Checkbox, rank, title, artist, total score, criteria breakdown, and evaluation status.
     * Search and Filter Controls: Filter by `All`, `Selected`, `Unselected`, `Completed`, `Incomplete`; search by keyword.
  2. *Step 2 — Selection Preview, Reordering & Replacement:*
     * Shows strictly the selected $N$ qualifiers in their seeded rank order.
     * Manual Reordering Controls: Drag-and-drop handles and discrete `Move Up` / `Move Down` buttons.
     * Participant Replacement: Ability to swap a selected qualifier with an alternate candidate from the unselected pool.
     * Real-Time Tie-Breaker Visibility: Displays all 5 criteria to guide manual judgment.
  3. *Step 3 — Final Audit Checklist & Stage Lock:*
     * Final confirmation checklist: exact count verified, complete evaluations verified, irreversible lock warning acknowledged.
     * Back navigation allows returning to Step 1 or Step 2 without losing custom selections or ordering.
     * Authoritative Commit Button: Sends ordered candidate IDs via `api.php?action=execute_advancement`.
* **Dependencies:** `app.js` (`initTournamentAdvancement()`), `api.php`, `db_helper.php` (`execute_stage_advancement()`).
* **Current Mobile Behaviour:** The modal opens as a large overlay that scrolls awkwardly on mobile devices; dragging rows is difficult on touchscreens; multi-column headers cause horizontal clipping.
* **Critical Preservation Rules:** **STRICT PRESERVATION MANDATE.** Automatic qualification is forbidden. The manual selection, reordering (Move Up/Down), replacement, multi-step validation, and final lock workflow must remain 100% operational.

---

### G. Archive & Champion Hall of Fame (`public/results.php`)
* **Core Purpose:** Permanent historical record and audit trail of all completed tournament stages.
* **Key Functionality:**
  1. *2026 Champion Podium:* Authoritative Hall of Fame card showing the #1 winning song, artist, total score, all 5 criteria scores, winning stage (`Finals`), and decision date.
  2. *Authoritative Finals Derivation:* The champion score derives strictly from the finalized Finals stage (`get_tournament_champion()`), never from an intermediate round.
  3. *Sticky Stage Navigation Bar:* Horizontal pill bar anchoring links to `#stage-champion` and each completed stage (`Formal Auditions`, `Top 200`, `Top 100`, `Top 50`, `Top 20`, `Top 10`, `Finals`).
  4. *Stage-Wise Expandable Cards:* Collapsible accordion cards for each stage showing completion timestamp, qualifier count, eliminated count, and full participant table.
  5. *Expand All / Collapse All Controls:* Instant state toggling for all stage accordions.
  6. *Live Archive Search:* Real-time filtering across all archived songs by title or artist.
  7. *CSV Export:* One-click download of `AFM2_2026_Tournament_Results_*.csv` with UTF-8 BOM.
* **Dependencies:** `get_historical_results()`, `get_tournament_champion()`, `includes/import_helper.php`.
* **Current Mobile Behaviour:** Sticky stage bar wraps onto multiple rows; stage tables collapse into card blocks.
* **Critical Preservation Rules:** Champion finals derivation, stage-by-stage audit trail, CSV export, and stage search must be preserved.

---

### H. Account Security & Authentication Management (`public/account.php`, `public/users.php`, `login.php`)
* **Core Purpose:** Multi-method authentication, identity security, and administrative user controls.
* **Key Functionality:**
  1. *Identity Profile:* Displays username, email, account role (`Admin` / `Judge`), and count of active sign-in methods.
  2. *Password Management:* Change password form with current password validation and mismatch safeguards.
  3. *Google Identity Linking:* Link/unlink Google OAuth identity via `GoogleAuthService`.
  4. *WebAuthn Passkeys:* Register biometric passkeys (TouchID, FaceID, Windows Hello, YubiKey), list registered credentials, rename device names, and revoke passkeys.
  5. *User Management (`public/users.php` — Admin only):* Create user, invite user via token link (`invite.php`), assign roles (`admin` or `judge`), reset passwords, and toggle user active status.
* **Dependencies:** `includes/auth.php`, `includes/webauthn_service.php`, `includes/google_auth_service.php`.
* **Current Mobile Behaviour:** Account cards stack cleanly; passkey action buttons wrap on small viewports.
* **Critical Preservation Rules:** All authentication methods, WebAuthn ceremonies, and admin role restrictions must be preserved without compromise.

---

### I. Appearance & Atmospheric Themes (`includes/footer.php`, `public/assets/css/style.css`)
* **Core Purpose:** Multi-theme atmospheric personalization and visual accessibility.
* **Available Themes:**
  1. `midnight_ember` (Dark Warm Amber & Charcoal) — *Default*
  2. `velvet_amethyst` (Dark Cosmic Twilight Violet & Neon Cyan)
  3. `emerald_forest` (Dark Botanical Alpine Pine & Jade)
  4. `vintage_paper` (Warm Antique Light Manuscript & Walnut Ink)
  5. `nordic_frost` (High-Contrast Glacial Snow & Cobalt Sapphire Light)
  6. `solar_terracotta` (Warm Tuscan Sunset & Burnt Sienna Light)
  7. `oceanic` (Dark Marine Horizon & Tranquil Teal)
  8. `quiet_minimal` (Warm Alabaster Studio & Charcoal Neutral Light)
* **Customization Controls:**
  * Background style selector (`atmospheric`, `gradient`, `minimal`).
  * Background overlay opacity slider (`0%` to `100%`).
  * Compact Player Bar toggle (switches between expanded turntable card and streamlined bar).
* **CSS Variable Architecture:**
  * Root design tokens: `--bg-main`, `--bg-surface`, `--bg-card`, `--primary`, `--accent`, `--text-primary`, `--text-secondary`, `--text-muted`, `--border-color`, `--shadow-color`, `--font-main`, `--font-serif`, `--font-brand`, `--font-mono`.
* **Critical Preservation Rules:** No hardcoded CSS color values in mobile layout additions. All new mobile components must bind directly to these semantic custom properties to support all 8 themes instantly.

---
*End of Functionality Inventory Document.*
