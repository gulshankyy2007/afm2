# AFM#2 — Desktop vs. Mobile Adaptive Architecture Comparison
**Project:** AFM#2 (Advanced Feature Matrix #2)  
**Task Classification:** Architecture & Interaction Design Documentation (Phase 0)  
**Status:** Approved for Prototyping  
**Author:** Antigravity AI Engineering & Design Team  
**Date:** November 2026  

---

## 1. Executive Summary & Dual-Dimension Philosophy

A common architectural failure in web applications is "collapsing responsiveness" — taking a 12-column desktop interface and blindly stacking it into an endless, exhausting vertical scroll on mobile.

AFM#2 deliberately rejects collapsing responsiveness. Instead, AFM#2 establishes an **Adaptive Product Architecture**:

```
+-----------------------------------------------------------------------------------------+
|                        AFM#2 ADAPTIVE PRODUCT ARCHITECTURE                              |
+-------------------------------------------+---------------------------------------------+
| DESKTOP PLATFORM (>= 1024px)              | MOBILE PLATFORM (320px - 430px)             |
+-------------------------------------------+---------------------------------------------+
| • High-density multi-column ledger        | • Focused, thumb-driven single-task flows   |
| • Simultaneous multi-song batch scoring   | • Immersive single-song evaluation cards    |
| • 8-to-10 column sorting and filtering    | • Dual-Mode: Pinterest Grid vs SoundCloud   |
| • Drag-and-drop reordering tables         | • 3-step sequential touch wizard            |
| • Persistent desktop sidebar navigation   | • 5-destination bottom app navigation bar   |
| • Multi-window & large monitor power      | • Safe-area insets & 44px touch targets     |
+-------------------------------------------+---------------------------------------------+
```

Desktop remains an information-dense workstation for extensive catalogue administration, batch scoring, and analytical comparisons. Mobile becomes an intimate, tactile discovery and evaluation sanctuary optimized for one-handed operation.

---

## 2. Global Navigation Architecture Comparison

```
DESKTOP NAVIGATION (Persistent Top/Sidebar Bar)
+---------------------------------------------------------------------------------------+
| [Logo AFM#2]  Journal  |  Library  |  Auditions  |  Recordings  |  Tournament  | [User] |
+---------------------------------------------------------------------------------------+

MOBILE NAVIGATION (Persistent 5-Destination Bottom Bar + Header)
+-----------------------------------------+
| [Logo] AFM#2    Top 200 ● Active   🔍 🎨| <- Compact Header (52px)
|                                         |
|         [ DYNAMIC SCREEN AREA ]         |
|                                         |
| [⌂ Home] [♫ Lib] [🎧 Aud] [🏆 Tourn] [⋯]| <- Bottom Nav Bar (56px)
+-----------------------------------------+
```

### Key Differences
1. **Desktop:** Horizontal navbar across top with direct links to all 7 primary sub-systems. Always visible.
2. **Mobile:** 5-destination bottom bar housing the 4 primary operational tasks (Home, Library, Auditions, Bracket) plus a `More` contextual bottom sheet housing secondary systems (Recordings, Archive, Champion Hall of Fame, Account Settings, Theme Switcher).
3. **Ergonomic Rationale:** Desktop users have mouse cursors that reach the top of screens in milliseconds; mobile users navigate with thumbs, where top buttons cause grip strain and bottom bars provide immediate, ergonomic reachability.

---

## 3. Screen-by-Screen Architectural Comparison

---

### Screen 1: Home / Dashboard

```
DESKTOP HOME (3-Column High Density Layout)
+---------------------------------------------------------------------------------------+
| WELCOME BANNER & 4 INLINE ACTIONS                                                     |
| [ Left Column: 180px Turntable ] [ Middle: Song Metadata & Player ] [ Right: Actions ]|
| [ 7-Stage Milestone Horizontal Progression Strip - 1200px Wide ]                      |
| [ Metric Card 1 ]   [ Metric Card 2 ]   [ Metric Card 3 ]   [ Metric Card 4 ]         |
| [ 4-Column Shortlisted Songs Grid ]                                                   |
+---------------------------------------------------------------------------------------+

MOBILE HOME (Vertical Feed with Pinterest Discovery Shelf)
+-----------------------------------------+
| [Logo] AFM#2      Top 200 ● Active  🔍 🎨|
| [Resume Audition] [Browse] [Bracket]    | <- Horizontal Scrolling Quick Pills
| [ 90px Vinyl ] Bohemian Rhapsody (50/50)| <- Compact Turntable Feature Card
| [✓ Aud] -> [● T200] -> [T100] -> [T50]  | <- Horizontal Milestone Stepper
| SHORTLISTED CONTENDERS                  |
| [ 2-Column Pinterest Artwork Grid ]     | <- Pinterest Visual Shelf
| [2x2 Compact Metric Ledger Tiles]       | <- Compact Metrics Grid
+-----------------------------------------+
```

- **Desktop:** Employs wide multi-column cards, full horizontal milestone bars, and expansive summary tables.
- **Mobile:** Converts the landing experience into an inviting personal feed with horizontal thumb-flickable quick actions, a compact 90px turntable card with lyrics/scorecard triggers, and a visual 2-column Pinterest shelf for shortlisted contenders.

---

### Screen 2: Library Discover vs. Desktop Catalogue

```
DESKTOP CATALOGUE (8-Column Dense Data Ledger)
+---------------------------------------------------------------------------------------+
| [Search Input...] [Genre ▾] [Status ▾] [Sort ▾] [Items: 50 ▾]       [＋ Add New Song] |
| [All (1,400)] [Unreviewed (250)] [Shortlisted (240)] [Rejected (800)]                 |
| [ ] ID  | Cover | Title              | Artist       | Status      | Score | Actions   |
| [ ] #101| [Img] | Bohemian Rhapsody  | Queen        | Shortlisted | 50/50 | [Edit][Del]|
| [ ] #102| [Img] | Comfortably Numb   | Pink Floyd   | Shortlisted | 49/50 | [Edit][Del]|
+---------------------------------------------------------------------------------------+

MOBILE LIBRARY DISCOVER (Pinterest-Inspired Visual Masonry)
+-----------------------------------------+
| [ 🔍 Search title, artist, genre...  ✕ ]|
| [ ★ Discover View  |  ☰ Manage View   ] | <- Segmented View Switcher
| ( All ) ( Rock ) ( Synth ) ( Classic )  | <- Horizontal Filter Chips
| +-------------------+ +----------------+|
| | [ 1:1 Art ]    ★ || | [ 1:1 Art ]  ★ ||
| | Bohemian Rhaps.   || | Comfortably N. ||
| | Queen    50/50    || | Pink Floyd 49  ||
| +-------------------+ +----------------+|
| +-------------------+ +----------------+|
| | [ 1:1 Art ]    ★ || | [ 1:1 Art ]  ★ ||
| | Stairway to Heav. || | STARGAZING     ||
| | Led Zeppelin 49   || | Travis Scott 47||
| +-------------------+ +----------------+|
+-----------------------------------------+
```

- **Desktop:** Focuses on tabular data manipulation, bulk selection checkboxes, inline sorting headers, and column density.
- **Mobile Discover:** Transforms browsing into a visual discovery experience inspired by Pinterest and Bandcamp. Large artwork cards with 1:1 ratios, floating quick-star bookmark buttons, and instant touch access to dedicated detail modals.

---

### Screen 3: Library Manage vs. Desktop Management

```
DESKTOP CATALOGUE MANAGE
+---------------------------------------------------------------------------------------+
| Multi-column table with floating bottom bulk-actions bar (Tag, Move, Delete, Export) |
+---------------------------------------------------------------------------------------+

MOBILE LIBRARY MANAGE (SoundCloud-Inspired 52px Compact Rows)
+-----------------------------------------+
| [ ★ Discover View  |  [☰ Manage View] ] |
| [ 14 Songs Found ]          [Select All]|
| [ ] [38px Art] Bohemian Rhapsody Queen  | <- 52px Compact Track Row
|     #101 ● Shortlisted        [50/50] ⋮ |
| [ ] [38px Art] Comfortably Numb  Pink F |
|     #102 ● Shortlisted        [49/50] ⋮ |
| [ ] [38px Art] Stairway to Heaven Led Z |
|     #103 ● Shortlisted        [49/50] ⋮ |
|                                         |
| [ ＋ Add ] [ ⇊ Import ] [ ⇈ Export ]     | <- Fixed Action Toolbar
+-----------------------------------------+
```

- **Desktop:** Uses horizontal table width with hover states revealing edit/delete buttons.
- **Mobile Manage:** Provides a dedicated management mode with 52px tactile rows inspired by SoundCloud and Spotify. Includes checkboxes, entry IDs, audition badges, and 3-dot menus (`⋮`) for full catalogue operations (Add, Edit, Delete subject to safeguards, Import, Export) without any horizontal table overflow.

---

### Screen 4: Song Detail Modal vs. Desktop Inspector

```
DESKTOP SONG INSPECTOR (Right-Side Drawer or Modal)
+---------------------------------------------------------------------------------------+
| Wide dialog with side-by-side tabs: Details, Media Links, Scoring History, Lyrics     |
+---------------------------------------------------------------------------------------+

MOBILE SONG DETAIL (Immersive Full-Screen Touch Sheet)
+-----------------------------------------+
| [ < Back to Library ]           [ ★ ] [⋮]|
| [      260px Large Immersive Artwork   ]|
| Bohemian Rhapsody                       |
| Queen — A Night at the Opera (1975)     |
| [ Classic Rock ] [ English ] [ Grandiose]|
| +-------------------------------------+ |
| | Audition Score: 50 / 50 (● Complete)| |
| | Emotion: 10 | Replay: 10 | Mel: 10  | |
| | Vocals: 10  | Lyrics: 10            | |
| +-------------------------------------+ |
| [▶ Open YouTube Video]  [📜 Lyrics ▾]   |
| [ 🎧 Score Song ]  [ ✏️ Edit Metadata ] |
+-----------------------------------------+
```

- **Desktop:** Displays metadata in a dense multi-column side pane alongside the active catalogue.
- **Mobile:** Renders an immersive, focused screen dedicated to a single song. Artwork is prominent, tags are cleanly arranged, and clear action buttons provide one-tap access to scoring, lyrics, and recording playback.

---

### Screen 5: Formal Auditions Evaluation Engine

```
DESKTOP AUDITIONS (25-Card Continuous Scoring Sheet)
+---------------------------------------------------------------------------------------+
| Renders 25 wide scorecards per page.                                                  |
| Side-by-side YouTube NoCookie player and dual-mode lyrics drawer.                     |
| 5 criteria inputs arranged in horizontal grid: [Mel] [Lyr] [Voc] [Emo] [Rep]          |
| Total calculates live. [Save Scorecard] on every card.                                |
+---------------------------------------------------------------------------------------+

MOBILE FORMAL AUDITIONS (Single-Song Focused Card + Tactile Sliders)
+-----------------------------------------+
| Entry 14 of 200         < Prev | Next > | <- Quick Single-Song Pagination
| [ 130px Centered Artwork ]              |
| Bohemian Rhapsody — Queen               |
| TOTAL SCORE: 50 / 50  (● Complete)      |
| [▶ YouTube Audio Player] [📜 Lyrics ▾]  |
|                                         |
| ❤️ Emotion: 10       [-------------●] 10 |
| 🔁 Replayability: 10  [-------------●] 10 |
| 🎵 Melody: 10        [-------------●] 10 | <- 44px Tactile Range Sliders
| 🎤 Vocals: 10        [-------------●] 10 |
| ✍️ Lyrics: 10        [-------------●] 10 |
|                                         |
| [ ↺ Reset ]        [ 💾 Save Scorecard ]| <- Sticky Action Footer
+-----------------------------------------+
```

- **Desktop:** Evaluates 25 songs continuously on a single scrollable page with side-by-side video and lyrics panels.
- **Mobile:** Limits evaluation to **1 song at a time** to eliminate cognitive overload. Integrates large 44px touch sliders with instant real-time `/50` arithmetic calculation, collapsible lyrics/audio drawer, and discrete `< Prev` / `Next >` navigation.

---

### Screen 6: Recordings Management vs. Desktop Matcher

```
DESKTOP RECORDINGS (Multi-Column YouTube Embed Table)
+---------------------------------------------------------------------------------------+
| Table of songs with embedded YouTube players, candidate match tables, and duration    |
+---------------------------------------------------------------------------------------+

MOBILE RECORDINGS (SoundCloud-Style Candidate Ranking Cards)
+-----------------------------------------+
| [ 🔍 Search recordings or video ID... ] |
| [ Filter: Matched (12) | Unmatched (2) ]|
| +-------------------------------------+ |
| | Bohemian Rhapsody — Queen           | |
| | [ Responsive YouTube Video Player ] | |
| | Candidate 1: Official Remaster 1975 | |
| | ID: fJ9rUzIMcZQ ● Duration: 5:55    | |
| | [✓ Primary Match] [🔄 Re-Match] [📜] | |
| +-------------------------------------+ |
+-----------------------------------------+
```

- **Desktop:** Displays wide tables with side-by-side candidate options and technical duration columns.
- **Mobile:** Prioritizes the active candidate with a responsive 16:9 embedded preview, quick lyrics drawer toggle, and clean matching actions.

---

### Screen 7: Tournament Progression vs. Desktop Bracket

```
DESKTOP TOURNAMENT (Wide Multi-Column Stage Ladder)
+---------------------------------------------------------------------------------------+
| [Auditions (200)] --> [Top 100] --> [Top 50] --> [Top 20] --> [Top 10] --> [Finals]  |
| Full 100-row candidate leaderboard with 5 tie-breaker sub-columns and manual controls |
+---------------------------------------------------------------------------------------+

MOBILE TOURNAMENT (Vertical Leaderboard with Cutoff Indicator)
+-----------------------------------------+
| TOURNAMENT PROGRESSION                  |
| Active Stage: Top 200                   |
| 142 / 200 Evaluated (71%)               |
| [ ⚡ Launch Manual Qualification Wizard ]|
|                                         |
| STAGE LEADERBOARD (Top 100 Cutoff)      |
| Rank 1  Bohemian Rhapsody     50 / 50   |
| Rank 2  Comfortably Numb      49 / 50   |
| Rank 3  Stairway to Heaven    49 / 50   |
| --------------------------------------- | <- Visual Advancement Cutoff
| Rank 101 Starboy (Eliminated) 41 / 50   |
+-----------------------------------------+
```

- **Desktop:** Stretches all stages across a 1400px horizontal flow diagram with deep multi-column tables.
- **Mobile:** Summarizes the active stage with a progress bar, provides a high-contrast qualification cutoff line on the leaderboard, and features a prominent button to trigger the manual qualification workflow.

---

### Screen 8: Manual Qualification Wizard vs. Desktop Modal

```
DESKTOP QUALIFICATION (Multi-Column Drag-and-Drop Modal)
+---------------------------------------------------------------------------------------+
| Wide 1000px dialog with HTML5 table drag handles to reorder candidates.               |
| Side-by-side rule validation summary and single-scroll authorization button.          |
+---------------------------------------------------------------------------------------+

MOBILE QUALIFICATION WIZARD (Sequential 3-Step Touch Sheet)
+-----------------------------------------+
| MANUAL ADVANCEMENT WIZARD               |
| [ (1) Select ] -> [ (2) Arrange ] -> [ (3) Lock ]
|                                         |
| STEP 1: Select Candidates               |
| Quota: 100 / 100 Selected ✓ Met         |
| [✓] #1 Bohemian Rhapsody (50/50)        |
| [✓] #2 Comfortably Numb (49/50)         |
| [ Proceed to Step 2: Arrange > ]        |
|                                         |
| STEP 2: Reorder with Touch ▲ / ▼        |
| Rank #1: Bohemian Rhapsody  [▲] [▼]     |
|                                         |
| STEP 3: Audit & Irreversible Stage Lock |
| [✓] Exact Quota Met: 100 songs          |
| [✓] Incomplete Evaluations: 0           |
| [ < Back ]   [ 🔒 COMMIT STAGE LOCK ]   |
+-----------------------------------------+
```

- **Desktop:** Relies on mouse drag-and-drop handles (`cursor: grab`) in a wide tabular modal.
- **Mobile:** Replaces error-prone mobile touch-dragging with a bulletproof **3-Step Touch Wizard**:
  - *Step 1:* Clear quota indicators, search, candidate checkboxes.
  - *Step 2:* Explicit 44px `Move Up` (`▲`) and `Move Down` (`▼`) touch buttons for precise rank adjustment.
  - *Step 3:* Pre-flight validation audit checklist, irreversible stage lock authorization warning, and explicit confirmation.

---

### Screen 9: Historical Archive vs. Desktop Archive

```
DESKTOP ARCHIVE (Massive Multi-Tab Data Ledger)
+---------------------------------------------------------------------------------------+
| 7 horizontal tabs for every stage; 200-row historical tables with CSV export buttons  |
+---------------------------------------------------------------------------------------+

MOBILE ARCHIVE (Accordion Stage Cards + Champion Banner)
+-----------------------------------------+
| HISTORICAL ARCHIVE                      |
| [ 👑 2025 Champion: Bohemian Rhapsody ] |
|                                         |
| STAGE ARCHIVES                          |
| [▸ Finals (Top 10) — Completed       ▾] |
|   • Rank 1: Bohemian Rhapsody (50/50)   |
|   • Rank 2: Comfortably Numb (49/50)    |
|   [ ⇈ Export Stage CSV ]                |
| [▸ Top 20 — Completed                ▸] |
| [▸ Top 50 — Completed                ▸] |
| [▸ Top 100 — Completed               ▸] |
+-----------------------------------------+
```

- **Desktop:** Expands all historical rounds into full-width multi-tab tables.
- **Mobile:** Organizes historical stages into expandable accordion sections with quick CSV download triggers, preventing endless page scrolling.

---

### Screen 10: Champion Presentation vs. Desktop Hall of Fame

```
DESKTOP CHAMPION (Full-Width Editorial Banner)
+---------------------------------------------------------------------------------------+
| 1200px hero banner with gold leaf accents, Finals round scorecard, and tournament log  |
+---------------------------------------------------------------------------------------+

MOBILE CHAMPION (TIDAL-Inspired Luxury Gold Hall of Fame Card)
+-----------------------------------------+
| [ < Return to Archive ]                 |
| [      👑 TOURNAMENT CHAMPION 👑       ]|
|                                         |
| [ 220px Gold-Framed Album Artwork ]     |
|                                         |
| BOHEMIAN RHAPSODY                       |
| Queen — A Night at the Opera (1975)     |
|                                         |
| FINALIZED FINALS SCORE: 50 / 50         |
| Emo: 10 | Rep: 10 | Mel: 10 | Voc: 10   |
|                                         |
| TOURNAMENT JOURNEY                      |
| Auditions: 50  ●  Top 100: 50           |
| Top 50: 50     ●  Top 20: 50            |
| Top 10: 50     ●  Finals: 50 (Champion) |
+-----------------------------------------+
```

- **Desktop:** Large horizontal spread with extensive statistical charts and round histories.
- **Mobile:** Immersive vertical tribute card with glowing gold border framing, high-contrast typography, authoritative finalized Finals round score (never substituting earlier round scores), and vertical milestone progression trail.

---

## 4. Summary of Architectural Invariants & Competition Integrity

Throughout all 10 responsive adaptations, the following core invariants remain 100% identical between desktop and mobile:
1. **5 Canonical Scoring Criteria:** Emotion, Replayability, Melody, Vocals, Lyrics (each discrete 0–10 integer, Total 0–50).
2. **Exact 7-Field Deterministic Ranking Hierarchy:**
   - Field 1: Total Audition Score DESC (`total` /50, Primary Sort)
   - Field 2: Emotion Score DESC (`emotion` /10, Tie-Breaker 1)
   - Field 3: Replayability Score DESC (`replayability` /10, Tie-Breaker 2)
   - Field 4: Melody Score DESC (`melody` /10, Tie-Breaker 3)
   - Field 5: Vocals Score DESC (`vocals` /10, Tie-Breaker 4)
   - Field 6: Lyrics Score DESC (`lyrics` /10, Tie-Breaker 5)
   - Field 7: Entry ID ASC (`entry_id`, Stable Deterministic Tie-Breaker for absolute draws)
3. **Formal Auditions Sole Shortlisting Gateway:** Formal Auditions is the exclusive entry point into the tournament competition; once finalized, the shortlist is permanently locked and immutable.
4. **Manual Tournament Advancement:** Scores are strictly advisory. Automatic qualification never occurs. Advancement is explicitly selected and authorized by an authorized judge/admin via the Qualification Wizard.
5. **Stage Transition Target Derivation & Finals Format:** Advancement target quotas are derived strictly from the active stage transition:
   - Formal Auditions (1) $\rightarrow$ Top 200 (2): Target Cutoff = 200 qualifiers.
   - Top 200 (2) $\rightarrow$ Top 100 (3): Target Cutoff = 100 qualifiers.
   - Top 100 (3) $\rightarrow$ Top 50 (4): Target Cutoff = 50 qualifiers.
   - Top 50 (4) $\rightarrow$ Top 20 (5): Target Cutoff = 20 qualifiers.
   - Top 20 (5) $\rightarrow$ Top 10 (6): Target Cutoff = 10 qualifiers.
   - Top 10 (6) $\rightarrow$ Finals (7): Target Cutoff = **1 Champion** (selected from 10 available contenders; Stage 7 Finals has `target_count = 1` and `result = 'winner'`).
6. **Exact Selected Ordering Preserved:** In Step 2 of the Qualification Wizard, the exact manually curated order (adjusted via touch `Move Up`/`Move Down` or desktop table drag) is recorded directly into `round_results.rank_position` and `round_results.manual_order`.
7. **Historical Immutability:** Completed tournament rounds cannot be routinely reopened, reset, or rewritten.
8. **Authoritative Champion Derivation:** The champion score is derived strictly and exclusively from the finalized Finals round scorecard.
9. **Semantic Token Consistency:** Shared component styles bind strictly to `--bg-card` (canonical token) and existing semantic CSS variables across all 8 production themes.
10. **Zero New External Dependencies:** Redesign relies strictly on existing infrastructure with zero new external CDNs, JS libraries, or third-party APIs.

