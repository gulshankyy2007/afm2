# AFM#2 — Mobile Experience Redesign: Design Decisions & Implementation Strategy

**Document Classification:** Design Decisions, Architectural Trade-offs & Implementation Roadmap  
**Project:** AFM#2 — Annual Musical Finale 2026  
**Status:** Design Planning & Recommendations — *Implementation Authorization NOT GRANTED*  
**Repository Branch / Commit:** `perf/multiuser-concurrency` / `97fe317`  
**Date:** November 2, 2026  

---

## 1. Primary Design Decisions & Strategic Rationale

```
+---------------------------------------------------------------------------------------------------------+
|                                        CORE DESIGN DECISIONS RECORD                                     |
+----+----------------------------+-------------------------------------+---------------------------------+
| #  | Decision Area              | Approved Proposal                   | Primary Justification           |
+----+----------------------------+-------------------------------------+---------------------------------+
| 01 | Visual Identity Foundation | Midnight Muse + Multi-Theme Binding | Preserves 8 atmospheric themes  |
| 02 | Primary Navigation         | 5-Tab Persistent Bottom App Bar     | Eliminates 2-tap drawer friction|
| 03 | Secondary Navigation       | "More" Contextual Bottom Sheet      | Clean access without tab bloat  |
| 04 | Library Architecture       | Dual-Mode: Discover vs. Manage      | Solves visual vs. admin tension |
| 05 | Auditions Interaction      | Focused 1-Song Evaluation Flow      | Eliminates 800px card scrolling |
| 06 | Manual Qualification       | Sequential 3-Step Touch Wizard      | Preserves manual judge control  |
| 07 | Technical Architecture     | Vanilla CSS + Vanilla JavaScript    | ₹0 Shared Hosting compatibility |
| 08 | Desktop Experience         | Strictly Preserved & Information-Rich| No loss of desktop capabilities|
+----+----------------------------+-------------------------------------+---------------------------------+
```

---

## 2. Alternatives Considered & Evaluated Trade-offs

### Alternative 1: Pure Media Query Responsive Collapse (Status Quo)
* **Description:** Continue relying on `mobile.css` to collapse desktop tables into stacked card blocks and hiding navigation in a top hamburger drawer.
* **Why Rejected:** It treats mobile as an afterthought. Users on smartphones are forced to scroll past hundreds of tall cards, interact with crowded checkboxes, and open a 2-tap hamburger menu for every page change.

### Alternative 2: Full Single-Page Application (SPA) Rebuild (React / Next.js / Vue)
* **Description:** Rewrite AFM#2 as a client-side JavaScript application with a decoupled REST API.
* **Why Rejected:** Strictly violates project constraints:
  * AFM#2 operates on InfinityFree free shared hosting with a strict ₹0/month budget.
  * SPAs introduce large JavaScript bundles, slower initial page loads over mobile cellular connections, and risk hitting free hosting CPU limits.
  * Rebuilding in a framework risks breaking session-based authentication, WebAuthn ceremonies, and database isolation.

### Alternative 3: Infinite Scrolling Without Structured Milestones
* **Description:** Replacing pagination with infinite scroll on the Library and Auditions feeds.
* **Why Rejected:** AFM#2 is an accountable tournament and cataloguing platform. Infinite scrolling makes it impossible to bookmark a specific page, causes performance degradation as DOM nodes accumulate, and prevents reaching the footer.

### Alternative 4: Algorithmic / Automatic Tournament Qualification
* **Description:** Automatically advancing the top $N$ ranked songs without user intervention.
* **Why Strictly Rejected:** Violates the foundational philosophy of AFM#2. The manual qualification workflow allows the judge to review ties, replace anomalous candidates, verify recordings, and deliberately confirm advancement.

---

## 3. Four-Phase Prioritized Implementation Roadmap

When implementation authorization is granted, the mobile redesign should follow this risk-mitigated, four-phase sequence:

```
+---------------------------------------------------------------------------------------------------------+
|                                    FOUR-PHASE IMPLEMENTATION ROADMAP                                    |
+---------------------+-------------------------------------+-------------------------------+-------------+
| Phase               | Focus Area                          | Key Deliverables              | Complexity  |
+---------------------+-------------------------------------+-------------------------------+-------------+
| Phase 1: Core Shell | App Navigation & Mobile Header      | 5-tab bottom bar, More sheet  | Low-Medium  |
| Phase 2: Browsing   | Home Journal & Library Dual-Mode    | Pinterest grid, Manage rows   | Medium      |
| Phase 3: Evaluation | Focused Auditions & Recordings Match| 1-song scorecard, audio drawer| Medium-High |
| Phase 4: Tournament | Mobile Qualification & Archive      | 3-step wizard, sticky archive | High        |
+---------------------+-------------------------------------+-------------------------------+-------------+
```

### Phase 1: Mobile Shell & Core Navigation Framework
* Implement the 5-tab persistent bottom navigation bar (`Home`, `Library`, `Auditions`, `Tournament`, `More`) in `includes/footer.php` and `mobile.css`.
* Implement the "More" modal bottom sheet housing secondary destinations (`Recordings`, `Archive`, `Shortlist`, `Account`, `Appearance`, `Users`, `Sign Out`).
* Streamline the top mobile header to 52px height with logo, active stage badge, and search trigger.
* Verify 44×44px touch targets and safe-area padding across all mobile viewports.

### Phase 2: Home Journal & Library Dual-Mode Browsing
* Redesign `index.php` on mobile: horizontal action pills, compact turntable card, horizontal milestones stepper, and 2-column shortlisted contenders grid.
* Introduce the segmented mode switcher in `library.php` (`★ Discover` vs. `☰ Manage`).
* Build the 2-column Pinterest-inspired artwork card grid for Discover mode.
* Build the SoundCloud-inspired compact track row layout with contextual actions for Manage mode.

### Phase 3: Focused Formal Auditions & Recordings Management
* Implement the focused single-song evaluation card in `auditions.php` on mobile ($\le 768\text{px}$) with sequential `< Prev | Next >` controls.
* Streamline the 5 scoring criteria range sliders with real-time `/50` total updates and incomplete indicator (`*`).
* Dock YouTube audio playback and LRCLIB lyrics into a collapsible bottom drawer.
* Refactor `recordings.php` candidate rows for touch screens with one-tap match confirmation.

### Phase 4: Mobile Manual Qualification Wizard & Archive
* Re-engineer the Manual Qualification modal in `tournament.php` into a sequential 3-step mobile sheet:
  * *Step 1:* Selection table with filter pills, quota meters, and completeness checks.
  * *Step 2:* Touch-friendly reordering using discrete `Move Up` / `Move Down` buttons and candidate replacement.
  * *Step 3:* Audit confirmation checklist and authoritative stage lock.
* Refactor `results.php` into clean stage-wise accordions with sticky stage navigation chips and immersive Champion podium card.

---

## 4. Unresolved Questions & Technical Edge Cases

1. **iOS Safari YouTube Autoplay Constraints:** Mobile Safari blocks automatic media playback without direct user gestures. The mobile mini-player must gracefully handle touch-to-play initialization rather than assuming auto-play.
2. **Offline Synced Lyrics Caching:** If a user is on an intermittent mobile connection, should plain lyrics fetched from LRCLIB be cached in localStorage? (Recommended: Yes, with a 7-day TTL).
3. **Landscape Mobile Orientation Handling:** On viewports $<600\text{px}$ in landscape mode, the bottom navigation bar consumes vertical space. Should it automatically collapse into a compact left rail or hide on scroll? (Recommended: Hide on downward scroll, reveal on upward scroll).
4. **Touch Reordering vs. Drag-and-Drop:** Native HTML5 drag-and-drop is notoriously brittle on mobile touchscreens. We recommend utilizing discrete `Move Up` and `Move Down` buttons alongside drag handles to guarantee accessibility.

---

## 5. Strict Execution Boundaries Confirmation

* **Implementation Authorization:** NOT GRANTED.
* **Code Modification Confirmation:**
  * Zero application PHP files modified.
  * Zero JavaScript files modified.
  * Zero CSS stylesheets modified.
  * Zero HTML templates modified.
  * Zero SQL migrations or database schemas modified.
  * Zero production data altered.
* **Scope Adherence:** All activities were strictly confined to application inspection, competitive research, and documentation authoring.

---
*End of Design Decisions Document.*
