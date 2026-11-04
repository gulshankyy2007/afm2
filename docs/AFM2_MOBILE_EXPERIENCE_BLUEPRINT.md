# AFM#2 — Mobile Experience Redesign: Experience Blueprint & Architectural Specification

**Document Classification:** Comprehensive Mobile UX/UI Blueprint & Design Specification  
**Project:** AFM#2 — Annual Musical Finale 2026  
**Status:** Design Planning & Architectural Blueprint — *Implementation Authorization NOT GRANTED*  
**Repository Branch / Commit:** `perf/multiuser-concurrency` / `97fe317`  
**Date:** November 2, 2026  

---

## 1. Executive Summary & Design Vision

AFM#2 is a personal music sanctuary and rigorous competition platform spanning 1,400+ songs, 5 scoring criteria, and 7 tournament rounds. While the current desktop experience excels in data-dense multi-column tables, its current mobile layout relies on collapsing tables into vertically stacked blocks.

**The Design Principle:**  
*AFM#2 should feel like a thoughtfully designed music-discovery and tournament application on mobile, while remaining a powerful, information-rich management platform on desktop.*

```
+---------------------------------------------------------------------------------------------------------+
|                                    DESKTOP VS. MOBILE DUAL ARCHITECTURE                                 |
+------------------------------------+--------------------------------------------------------------------+
| Desktop Dimension (Power & Density)| Mobile Dimension (Focus & Portability)                             |
+------------------------------------+--------------------------------------------------------------------+
| • Wide 3-column layouts            | • Single-column vertical stream + 2-column visual grid             |
| • 8–10 column tabular ledgers      | • Compact track rows with contextual bottom sheets                 |
| • Multi-song simultaneous scoring  | • Focused single-song evaluation mode                              |
| • Drag-and-drop table reordering   | • Sequential 3-step wizard with discrete touch controls            |
| • Persistent desktop header        | • Compact mobile header + persistent 5-tab bottom navigation       |
| • Hover previews and tooltips      | • 44×44px tactile touch targets with explicit touch feedback       |
+------------------------------------+--------------------------------------------------------------------+
```

---

## 2. Visual Identity & Theme Compatibility

### 2.1 Exploration of Three Visual Directions

#### Direction A — Midnight Muse (Recommended Foundation)
* **Visual Characteristics:** Deep charcoal and obsidian surfaces (`#0c0b0f`, `#141218`), warm amber highlights (`#f59e0b`), royal violet accents (`#a855f7`), vibrant 1:1 album artwork, subtle atmospheric background grain, and elegant serifs (`Cormorant Garamond`, `Outfit`).
* **Competitive Inspiration:** TIDAL’s luxury surfaces combined with Spotify’s structural clarity.
* **Fit for AFM#2:** Perfectly matches AFM#2’s default `midnight_ember` and `velvet_amethyst` themes. Conveys late-night listening reverence and tournament prestige.

#### Direction B — Soft Frequency (Editorial Alternative)
* **Visual Characteristics:** Warm parchment and alabaster backgrounds (`#f5ede0`, `#faf9f6`), muted slate blue, burnt sienna accents, tactile borders, and literary serif typography.
* **Competitive Inspiration:** Bandcamp Daily editorial magazine and Pinterest lifestyle boards.
* **Fit for AFM#2:** Perfectly matches `vintage_paper`, `solar_terracotta`, and `quiet_minimal` themes.

#### Direction C — Chromatic Sound (Experimental Alternative)
* **Visual Characteristics:** Stark dark neutral foundations, electric neon accents (acid lime, radiant cyan), dynamic asymmetry, and oversized typography.
* **Competitive Inspiration:** Independent music festival and progressive web apps.
* **Fit for AFM#2:** While visually striking, it can fatigue judges during extended 50-song scoring sessions.

### 2.2 Recommendation & Multi-Theme Harmonization
Rather than forcing a single rigid color palette that breaks AFM#2's existing theme system, the **Midnight Muse** architectural structure is recommended as the layout foundation, powered 100% by AFM#2’s semantic CSS custom properties.

```css
/* Semantic Token Binding for Mobile Components */
:root {
    --mobile-nav-bg:       var(--bg-surface);
    --mobile-nav-border:   var(--border-color);
    --mobile-nav-active:   var(--primary);
    --mobile-card-bg:      var(--bg-card);
    --mobile-sheet-bg:     var(--bg-surface);
    --mobile-slider-track: var(--border-color);
    --mobile-slider-thumb: var(--primary);
}
```
All 8 existing themes (`midnight_ember`, `velvet_amethyst`, `emerald_forest`, `vintage_paper`, `nordic_frost`, `solar_terracotta`, `oceanic`, `quiet_minimal`) retain their distinctive personalities without code changes.

---

## 3. Mobile Navigation Architecture

### 3.1 Persistent 5-Destination Bottom App Bar
The clumsy top-right hamburger drawer is replaced on mobile viewports ($\le 768\text{px}$) with an ergonomic, thumb-accessible bottom app bar docked to the safe-area boundary:

```
+--------------------------------------------------------------------+
| [Home]       [Library]     [Auditions]    [Tournament]      [More] |
|   ⌂             ♫              🎧              🏆             ⋯    |
|  Home        Library        Auditions      Tournament        More  |
+--------------------------------------------------------------------+
```

1. **Home (`index.php`):** Dashboard, daily journal, featured contender turntable card, milestones stepper, and quick action shortcuts.
2. **Library (`library.php`):** Full catalogue browser with instant toggle between "Discover" (artwork cards) and "Manage" (compact rows).
3. **Auditions (`auditions.php`):** Formal audition console with focused single-song scorecards, integrated playback, and lyrics.
4. **Tournament (`tournament.php`):** Stage leaderboards, live tie-broken rankings, cutoff indicators, and Manual Qualification trigger.
5. **More (Modal Bottom Sheet):** Clean sheet exposing secondary destinations:
   * 🎬 Recordings & YouTube Matcher (`recordings.php`)
   * 📜 Tournament Archive & Champion (`results.php`)
   * ★ Shortlisted Contenders (`discovery.php`)
   * ⚙️ Account Security & Passkeys (`account.php`)
   * 🎨 Atmospheric Theme Customizer (`#appearanceModal`)
   * 👥 User Management (`users.php` — admin only)
   * 🚪 Sign Out (`logout.php`)

### 3.2 Compact Mobile Header
The top mobile header is streamlined to 52px height:
* Left: Compact AFM#2 brand monogram with animated 4-bar sound equalizer.
* Center: Active Stage Badge (e.g., `Top 200 ● Active`).
* Right: Global Search Trigger (`🔍`) + Quick Theme Swatch (`🎨`).

---

## 4. Complete Functionality-to-Mobile Mapping Matrix

```
+---------------------------------------------------------------------------------------------------------------------------------------------+
|                                                   FUNCTIONALITY-TO-MOBILE MAPPING MATRIX                                                    |
+----------------------+-----------------------------+-------------------------------+------------------------------+-------------------------+
| Feature              | Current Desktop Interaction | Current Mobile Behaviour      | Proposed Mobile Interaction  | Preservation Requirement|
+----------------------+-----------------------------+-------------------------------+------------------------------+-------------------------+
| Global Navigation    | Top bar horizontal links    | Hidden in top-right drawer    | Persistent 5-tab bottom bar  | Zero link loss; More tab|
| Home Quick Actions   | 4 inline buttons in hero    | Stacked vertical buttons      | Horizontal scrolling pills   | 100% action parity      |
| Turntable Contender  | 2-column wide card          | Tall stacked blocks           | Compact hero + audio drawer  | Artwork, scores, links  |
| Dashboard Milestones | Horizontal 7-node timeline  | Wrapped nodes (awkward wrap)  | Horizontal scroll stepper    | All 7 stages accessible |
| Library Browsing     | 8-column data table         | Stacked pseudo-cards          | "Discover" grid vs "Manage"  | Sorting, search, paging |
| Library Search       | Inline text input + apply   | Full-width input              | Sticky top search + clear ✕  | Instant search submit   |
| Status Filters       | 4 inline counter pills      | Wrapped pill buttons          | Horizontal scrolling chips   | Exact counts maintained |
| Bulk Selection       | Table row checkboxes        | Card checkboxes (hard to tap) | "Selection Mode" toggle bar  | Bulk delete safeguards  |
| Add / Edit Song      | Desktop modal dialog        | Responsive modal (tall)       | Full-height mobile sheet     | YT extract + manual     |
| Audition Scorecards  | Wide hero + 5 sliders       | Tall 800px card per song      | Focused 1-song card flow     | Exact 5-criteria math   |
| Audio Playback       | Inline YouTube iframe       | Full-width iframe (chunky)    | Docked player or audio card  | Strict origin nocookie  |
| Synchronized Lyrics  | Side-by-side lyrics panel   | Stacked drawer                | Tabbed bottom drawer (LRCLIB)| Plain, synced, manual   |
| Leaderboard Table    | 9-column tabular grid       | Horizontal table overflow     | Rank cards with metric pills | 5-tier tie-breakers     |
| Stage Cutoff Line    | Full table divider row      | Divider row across table      | Sticky cutoff status banner  | Clear Top N threshold   |
| Manual Qualification | Large multi-column modal    | Overflowing desktop dialog    | Sequential 3-Step Wizard     | NO auto-advance; manual |
| Candidate Reordering | Table Drag & Drop           | Broken touch drag             | Touch Move Up/Down + handles | Manual rank adjustment  |
| Candidate Replace    | Inline modal dropdown       | Unusable on small screens     | Dedicated replacement sheet  | Swap with eligible pool |
| Archive Stage Review | Sticky bar + tables         | Multi-line wrapped pills      | Sticky stage pills + cards   | Stage-by-stage audit    |
| Champion Hall of Fame| Large centered hero card    | Stacked badge card            | Immersive TIDAL gold podium  | Finals score derivation |
| Passkey Registration | WebAuthn ceremony buttons   | Standard button row           | Touch-friendly biometric row | WebAuthn spec compliance|
+----------------------+-----------------------------+-------------------------------+------------------------------+-------------------------+
```

---

## 5. Page-by-Page Mobile Layout Proposals & Research Wireframes

### 5.1 Mobile Home (Journal & Dashboard)
Combines Pinterest-inspired visual discovery with a compact tournament ledger.

```
+------------------------------------------+
|  [AFM#2 ||||]   Stage: Top 200    🔍  🎨 |  <- 52px Compact Header
+------------------------------------------+
|  LET'S GET LOST IN THE SOUND             |
|  1,400-track personal listening sanctuary|
|                                          |
|  (Resume Auditions) (Discover) (Bracket) |  <- Horizontal Action Pills
+------------------------------------------+
|  CURRENTLY ON THE TURNTABLE           ♥  |
|  +------------------------------------+  |
|  | [ Album Artwork ]  Bohemian Rhaps. |  |  <- Tactile Contender Card
|  | [   1:1 Frame   ]  Queen           |  |
|  |                    48/50  Complete |  |
|  +------------------------------------+  |
|  [▶ Play Audio]  [📜 Lyrics]  [Scorecard]|
+------------------------------------------+
|  TOURNAMENT MILESTONES                   |
|  [✓ Aud] -> [● T200] -> [T100] -> [Finals|  <- Horizontal Scroll Stepper
+------------------------------------------+
|  SHORTLISTED CONTENDERS                  |
|  +------------------+ +------------------+
|  | [ Cover Art 1 ]  | | [ Cover Art 2 ]  |
|  | Song Title One   | | Song Title Two   |
|  | Artist Name      | | Artist Name      |
|  | ★ 46/50          | | ★ Awaiting Score |
|  +------------------+ +------------------+
|  +------------------+ +------------------+
|  | [ Cover Art 3 ]  | | [ Cover Art 4 ]  |  <- 2-Column Pinterest Grid
|  | Song Title Three | | Song Title Four  |
|  +------------------+ +------------------+
+------------------------------------------+
|  CATALOGUE LEDGER                        |
|  [Total: 1,400]        [Shortlist: 240]  |
|  [Reviewed: 82%]       [Scored: 198/200] |
+------------------------------------------+
| [⌂ Home] [♫ Lib] [🎧 Aud] [🏆 Tourn] [⋯] |  <- 5-Tab Persistent Bottom Nav
+------------------------------------------+
```

---

### 5.2 Mobile Library: Dual Mode ("Discover" vs. "Manage")
A top segmented pill toggle lets users seamlessly switch between visual inspiration and catalogue administration.

```
+------------------------------------------+
|  SONG LIBRARY & CATALOGUE        ＋ Add  |
|  [ 🔍 Search title or artist...     ✕ ]  |  <- Search Bar
|                                          |
|  [  ★ Discover View  |  ☰ Manage View  ] |  <- Segmented Mode Switcher
|                                          |
|  ( All 1,400 ) ( Unreviewed 250 ) (★ 240)|  <- Filter Chips
+------------------------------------------+

=== MODE A: DISCOVER (Pinterest 2-Column Grid) ===
+--------------------+ +--------------------+
| +----------------+ | | +----------------+ |
| |   Cover Art    | | | |   Cover Art    | |
| |      1:1       | | | |      1:1       | |
| +----------------+ | | +----------------+ |
| Midnight City      | | Starboy            |
| M83                | | The Weeknd         |
| [★ Shortlist] [⋯]  | | [★ Shortlist] [⋯]  |
+--------------------+ +--------------------+

=== MODE B: MANAGE (SoundCloud-Style Compact Rows) ===
+------------------------------------------+
| [ ] #101  [Thumb]  Bohemian Rhapsody     |
|     Queen • Shortlisted • 48/50          |
|     [▶ Play] [📜] [★] [✕] [✏️] [🗑️]      |
+------------------------------------------+
| [ ] #102  [Thumb]  Hotel California      |
|     Eagles • Shortlisted • Incomplete    |
|     [▶ Play] [📜] [★] [✕] [✏️] [🗑️]      |
+------------------------------------------+
| ⚡ [Selection Mode: 2 Selected] [Delete] |  <- Floating Bulk Bar
+------------------------------------------+
| [⌂ Home] [♫ Lib] [🎧 Aud] [🏆 Tourn] [⋯] |
+------------------------------------------+
```

---

### 5.3 Mobile Formal Auditions: Focused Single-Song Scorecard
Rather than displaying 25 tall scorecards per page, mobile provides a focused, swipeable or sequentially paginated single-song evaluation card.

```
+------------------------------------------+
|  FORMAL AUDITIONS         Stage: Top 200 |
|  Entry 14 of 200          < Prev | Next >|  <- Step Navigator
+------------------------------------------+
|  +------------------------------------+  |
|  | [ Large Album Art ]  TOTAL SCORE   |  |
|  | [   with Subtle   ]  47 / 50       |  |  <- Immediate Total Score
|  | [ Backdrop Blur   ]  ● Complete    |  |
|  +------------------------------------+  |
|  STARGAZING                              |
|  Travis Scott • Track #842               |
|                                          |
|  [▶ Play YouTube Audio]   [📜 Lyrics ▾]  |  <- Expandable Player/Lyrics
+------------------------------------------+
|  FIVE SCORING CRITERIA (0 - 10 EACH)     |
|                                          |
|  🎵 Melody: 9                            |
|  [--------------------------●--] (9)     |
|                                          |
|  ✍️ Lyrics: 8                             |
|  [-----------------------●-----] (8)     |
|                                          |
|  🎤 Vocals: 10                           |
|  [-----------------------------●] (10)   |
|                                          |
|  ❤️ Emotion: 10                           |
|  [-----------------------------●] (10)   |
|                                          |
|  🔁 Replayability: 10                    |
|  [-----------------------------●] (10)   |
+------------------------------------------+
|  [↺ Reset]  [✕ Remove]  [💾 SAVE SCORECARD]| <- Sticky Bottom Action Bar
+------------------------------------------+
| [⌂ Home] [♫ Lib] [🎧 Aud] [🏆 Tourn] [⋯] |
+------------------------------------------+
```

---

### 5.4 Mobile Tournament: Leaderboard & Cutoff Presentation
Combines rank hierarchy with instant tie-breaker visibility.

```
+------------------------------------------+
|  TOURNAMENT LEADERBOARD   Stage: Top 200 |
|  Completed: 198/200 • Cutoff: Top 100    |
|                                          |
|  [ ⚡ Manual Qualification & Advance > ] |  <- Wizard Trigger
+------------------------------------------+
|  🥇 #1  Bohemian Rhapsody                |
|  Queen • 50/50 Total                     |
|  Emo: 10 | Rep: 10 | Mel: 10 | Voc: 10   |  <- Tie-Breakers Visible
|  [● Qualifying for Top 100]              |
+------------------------------------------+
|  🥈 #2  Comfortably Numb                 |
|  Pink Floyd • 49/50 Total                |
|  Emo: 10 | Rep: 10 | Mel: 10 | Voc: 9    |
|  [● Qualifying for Top 100]              |
+------------------------------------------+
|  ======================================  |
|  ⚡ TARGET CUTOFF: TOP 100 ADVANCE       |  <- Distinct Cutoff Divider
|  ======================================  |
+------------------------------------------+
|  #101  Yesterday                         |
|  The Beatles • 42/50 Total               |
|  [○ Below Cutoff — Will Be Eliminated]   |
+------------------------------------------+
| [⌂ Home] [♫ Lib] [🎧 Aud] [🏆 Tourn] [⋯] |
+------------------------------------------+
```

---

### 5.5 Mobile Manual Qualification: Sequential 3-Step Wizard
The desktop qualification modal is transformed into a clean, 3-step full-height mobile sheet.

```
+------------------------------------------+
|  MANUAL QUALIFICATION WIZARD             |
|  Step 1 of 3: Select Candidates          |
|  [● Select] ---- [○ Reorder] ---- [○ Lock|  <- Progress Indicator
+------------------------------------------+
|  TARGET: 100 QUALIFIERS                  |
|  Selected: 100 / 100  ✓ Exact Quota Met  |
|  Eligible: 200        ✓ Min Requirement  |
|  Incomplete: 0        ✓ All Scored       |
|                                          |
|  [ 🔍 Search candidate...          ]     |
|  ( All 200 ) ( Selected 100 ) ( Other 100)
+------------------------------------------+
|  [✓] #1  Bohemian Rhapsody (50/50)       |
|  [✓] #2  Comfortably Numb (49/50)        |
|  [✓] #3  Stairway to Heaven (49/50)      |
|  [ ] #101 Yesterday (42/50)              |
+------------------------------------------+
|  [ Cancel ]        [ Proceed to Step 2 > ]|
+------------------------------------------+

=== STEP 2: TOUCH-FRIENDLY REORDER & REPLACE ===
+------------------------------------------+
|  Step 2 of 3: Order & Replace            |
|  Review seeded ranking & adjust order    |
+------------------------------------------+
|  Rank #1: Bohemian Rhapsody (50/50)      |
|  [▲ Move Up]  [▼ Move Down]  [Swap...]   |
+------------------------------------------+
|  Rank #2: Comfortably Numb (49/50)       |
|  [▲ Move Up]  [▼ Move Down]  [Swap...]   |
+------------------------------------------+
|  < Back to Step 1   |   Review Checklist >
+------------------------------------------+

=== STEP 3: AUDIT CHECKLIST & STAGE LOCK ===
+------------------------------------------+
|  Step 3 of 3: Confirm & Finalize         |
|  ⚠️ THIS ACTION IS PERMANENT & IMMUTABLE |
+------------------------------------------+
|  [✓] 100 of 100 Qualifiers Selected      |
|  [✓] 0 Incomplete Scorecards             |
|  [✓] Top 200 stage will be finalized     |
|  [✓] Top 100 stage will be initialized   |
+------------------------------------------+
|  [ < Back ]  [ 🔒 COMMIT & LOCK STAGE ]  |
+------------------------------------------+
```

---

### 5.6 Mobile Archive & Champion Hall of Fame
```
+------------------------------------------+
|  TOURNAMENT ARCHIVE & RESULTS   [📥 CSV] |
|  [ 🔍 Search all archived songs...     ] |
|                                          |
|  (👑 Champion) (T200) (T100) (T50) (Final)  <- Sticky Stage Chips
+------------------------------------------+
|  👑 2026 FINALE CHAMPION                 |
|  +------------------------------------+  |
|  |         [ 👑 GOLD CROWN ]          |  |
|  |       BOHEMIAN RHAPSODY            |  |
|  |             Queen                  |  |
|  |         50 / 50 TOTAL              |  |  <- Authoritative Finals Score
|  | Emo: 10 | Rep: 10 | Mel: 10 | Voc:10|  |
|  | Decided in Finals on Nov 2, 2026    |  |
|  +------------------------------------+  |
+------------------------------------------+
|  ▼ Stage 2: Top 200 (Completed)          |  <- Accordion Header
|  Decided Oct 28, 2026 • 100 Qual / 100 El |
|  ----------------------------------------|
|  #1  Bohemian Rhapsody   50/50  Advanced |
|  #2  Comfortably Numb    49/50  Advanced |
|  #101 Yesterday          42/50  Elimin.  |
+------------------------------------------+
|  ▶ Stage 1: Formal Auditions (Completed) |
+------------------------------------------+
| [⌂ Home] [♫ Lib] [🎧 Aud] [🏆 Tourn] [⋯] |
+------------------------------------------+
```

---

## 6. Responsive Breakpoint Specification

```
+---------------------------------------------------------------------------------------------------------+
|                                        RESPONSIVE BREAKPOINT MATRIX                                     |
+-----------+----------------------+-----------------------------+----------------------------------------+
| Viewport  | Device Archetype     | Navigation Architecture     | Primary Layout Behaviour               |
+-----------+----------------------+-----------------------------+----------------------------------------+
| 320px     | iPhone SE (1st gen)  | 5-tab compact bottom bar    | 1-col feed, compact 40px touch targets |
| 360px     | Standard Android     | 5-tab bottom bar            | 2-col discover grid (min gap 6px)      |
| 390px     | iPhone 13/14/15/16   | 5-tab bottom bar + safe-area| 2-col discover grid, 44px touch targets|
| 430px     | iPhone Plus/Pro Max  | 5-tab bottom bar + safe-area| Spacious 2-col grid, full hero cards   |
| 600px     | Small Tablets / Fold | 5-tab floating pill bar     | 3-col discover grid, dual-col forms    |
| 768px     | iPad Portrait        | Bottom bar or mini sidebar  | 3-col discover grid, hybrid tables     |
| 1024px    | iPad Landscape / Lap | Top bar + optional sidebar  | Multi-column tables, full modals       |
| 1366px+   | Standard Desktop     | Full desktop layout         | Maximum information density preserved  |
+-----------+----------------------+-----------------------------+----------------------------------------+
```

---

## 7. Accessibility, Performance & Technical Constraints

### 7.1 Accessibility (WCAG 2.1 AA Compliance)
1. **Touch Target Dimensions:** All buttons, range slider thumbs, modal dismiss triggers, and pagination links strictly maintain a minimum hit area of $44 \times 44\text{px}$.
2. **Safe-Area Inset Handling:** Bottom navigation and floating action bars respect hardware safe areas:
   ```css
   padding-bottom: max(0.75rem, env(safe-area-inset-bottom, 0.75rem));
   ```
3. **Contrast Compliance:** All text on dark or light theme cards meets a minimum contrast ratio of $4.5:1$ (normal text) and $3:1$ (large display text).
4. **Motion Sensitivity:** All transitions respect `@media (prefers-reduced-motion: reduce)`.

### 7.2 Performance on Free Shared Hosting (₹0 Budget)
1. **Vanilla JavaScript & CSS:** Zero React, Vue, Tailwind, or heavy runtime dependencies. Entire mobile layout is driven by vanilla CSS and vanilla JavaScript.
2. **Lazy Loading Assets:** All album artwork images enforce `loading="lazy"` and `decoding="async"` with WebP/SVG fallbacks to keep initial mobile payload under 400 KB.
3. **Lightweight DOM:** Single-song audition pagination avoids rendering 25 embedded iframes simultaneously, reducing mobile memory footprint by 70%.

---
*End of Experience Blueprint Document.*
