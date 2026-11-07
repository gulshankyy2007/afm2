# AFM#2 — Mobile Experience Redesign: Design Research & Competitive Inspiration

**Document Classification:** Design Research, Competitive Benchmarking & Reference Collection  
**Project:** AFM#2 — Annual Musical Finale 2026 (Personal Music Catalogue & Tournament)  
**Status:** Research & Design Planning Only — *Implementation Authorization NOT GRANTED*  
**Repository Branch / Commit:** `perf/multiuser-concurrency` / `97fe317`  
**Date:** November 2, 2026  

---

## 1. Executive Research Overview & Methodology

### 1.1 Objective
This research establishes the visual and functional benchmarks for transforming AFM#2’s mobile experience from a responsive desktop collapse into a purpose-built, intuitive, and visually arresting music discovery and tournament platform.

Rather than merely hiding columns or shrinking desktop elements with media queries, this study investigates how industry-leading applications deliver distinct mobile-first experiences while retaining desktop power and data density.

### 1.2 Research Methodology
Our evaluation combined three distinct research vectors:
1. **Production Applications Audit:** Hands-on review of mobile and desktop user flows across Pinterest, Spotify, Bandcamp, SoundCloud, TIDAL, Notion, Apple Music, and YouTube Music.
2. **Official Engineering & Design Retrospectives:** Architecture analysis from Pinterest PWA engineering, Spotify design system releases, SoundCloud track interaction architectures, and Notion workspace mobile documentation.
3. **Independent UI/UX Concepts & Patterns:** Scrutiny of curated design explorations on Dribbble, Behance, and Pinterest, strictly distinguishing between speculative aesthetic concepts and verified production-grade usability.

---

## 2. Deep-Dive Study of Primary Inspiration Products

```
+---------------------------------------------------------------------------------------------------------+
|                                    COMPETITIVE BENCHMARKING MATRIX                                      |
+---------------------+-------------------------------+-----------------------------------+---------------+
| Product             | Desktop Interaction Pattern   | Mobile Interaction Pattern        | AFM#2 Fit     |
+---------------------+-------------------------------+-----------------------------------+---------------+
| Pinterest           | Multi-column infinite masonry | 2-column visual grid + pill nav   | High (Visual) |
| Spotify             | 3-column hub (Nav, Feed, Now) | 4-tab bottom bar + mini-player    | High (Arch)   |
| Bandcamp            | Editorial magazine layout     | Large artwork cards + bio pills   | High (Mood)   |
| SoundCloud          | Waveform feed + compact meta  | High-density track rows + sheets  | High (Tracks) |
| TIDAL               | Dark luxury grids & credits   | Immersive artwork + clean type    | High (Hero)   |
| Notion              | Multi-column relational table | Progressive disclosure & wizards  | High (Data)   |
+---------------------+-------------------------------+-----------------------------------+---------------+
```

---

### A. Pinterest — Visual Discovery & Browsing Architecture
* **Primary Reference:** [https://www.pinterest.com/](https://www.pinterest.com/)  
* **Official Resources:**
  * [Pinterest Engineering: A One-Year PWA Retrospective](https://medium.com/pinterest-engineering/a-one-year-pwa-retrospective-f4a2f4129e05)
  * [Building Holiday Finds: Reimagining Gift Discovery](https://medium.com/pinterest-engineering/building-holiday-finds-how-pinterest-engineers-reimagined-gift-discovery-0a48a0c8bfd8)

#### Key Observed Patterns
1. **Staggered Two-Column Masonry:** Pinterest organizes visual cards into two fluid vertical columns on mobile (`width: calc(50% - 6px)`), allowing vertical height to adapt naturally to content aspect ratios without awkward horizontal whitespace.
2. **Floating Pill Filter Strip:** Horizontal scrolling pills at the top of feeds allow instant category switching with zero modal interruption. Active pills use strong contrasting fills with rounded borders (`border-radius: 9999px`).
3. **Persistent Floating Bottom Navigation:** Pinterest employs a sleek, floating pill-style bottom bar (`backdrop-filter: blur(20px)`) that elevates navigation above the content layer while preserving thumb access.
4. **Contextual Touch Actions:** Long-pressing or tapping thumbnail cards triggers contextual actions (Save, Share, More) without navigating away from the browsing flow.
5. **Dedicated Detail Presentation:** Tapping a card opens a dedicated sheet with large hero imagery, clear author/artist identity, and contextual commentary before related recommendations.

#### Applications to AFM#2
* **Mobile Homepage (Journal):** A curated two-column shelf of recently shortlisted contenders and featured catalogue tracks.
* **Library "Discover" Mode:** Artwork-led browsing allowing users to visually rediscover forgotten tracks in their 1,400+ library catalogue.
* **Genre and Mood Shelves:** Compact horizontal scrolling trays with album covers and subtle mood tags.

#### Pitfalls & Unsuitable Patterns for AFM#2
* **Indiscriminate Masonry:** Applying masonry to song data tables destroys vertical scanning speed. When comparing scores (/50), tie-breaker metrics, or track lengths, strictly aligned tabular rows are mandatory. Masonry must be reserved exclusively for visual discovery modes.

---

### B. Spotify — Music Application Navigation & Player Architecture
* **Primary Reference:** [https://open.spotify.com/](https://open.spotify.com/)  
* **Official Resources:**
  * [Spotify Desktop Redesign: Your Library & Now Playing Views](https://newsroom.spotify.com/2023-06-20/spotify-desktop-experience-redesign-your-library-now-playing-views-customize/)

#### Key Observed Patterns
1. **Desktop vs. Mobile Divergence:** Spotify treats desktop as an information-rich control center (3-column layout: expandable library tree on the left, primary content in the center, and contextual artist/lyrics drawer on the right). On mobile, Spotify switches to a focused 4-tab bottom navigation (`Home`, `Search`, `Your Library`, `Create/Premium`).
2. **Persistent Mini-Player Bar:** When audio is active, a compact player bar (48–56px height) docks immediately above the bottom navigation tabs. It displays a thumbnail, track title, artist, play/pause button, and a micro progress bar.
3. **Full-Screen Player Modal Sheet:** Tapping the mini-player slides up an immersive full-screen card featuring large album art, playback scrubbers, and expandable lyrics sheets.
4. **Library Mode Switching:** Mobile Library allows users to toggle between a compact list view (dense metadata scanning) and a grid view (artwork-led browsing).
5. **Contextual Bottom Sheets:** Tapping a track’s three dots (`⋮`) brings up a native-feeling bottom sheet with actions (Add to Playlist, View Album, View Credits).

#### Applications to AFM#2
* **Persistent Mobile Navigation:** Replacing the top-right hamburger drawer with a 5-item bottom bar (`Home`, `Library`, `Auditions`, `Tournament`, `More`).
* **Persistent Audio/YouTube Bar:** When an audition track or YouTube recording is playing, dock a compact playback bar above the bottom nav so the user can navigate between pages without interrupting listening.
* **Dual-Mode Library:** Providing a "Discover" mode (artwork cards) and a "Manage" mode (compact rows with checkboxes, edit, and delete controls).

#### Pitfalls & Unsuitable Patterns for AFM#2
* **Infinite Algorithmic Autoplay:** AFM#2 is a curated tournament and personal archive, not a commercial streaming service pushing algorithmic radio. Playback must be deliberate, respecting user evaluation focus.

---

### C. Bandcamp — Editorial Curation & Album Artwork Identity
* **Primary Reference:** [https://bandcamp.com/](https://bandcamp.com/)  

#### Key Observed Patterns
1. **Physical Format Pride:** Album artwork is presented with tactile reverence—square frames, vinyl sleeve drop shadows, and subtle paper texture overlays.
2. **Editorial Content Hierarchy:** Generous serif headings (`Cormorant Garamond`, `Playfair Display`), human-curated editorial quotes, and descriptive track narratives.
3. **Genre & Mood Tag Clouds:** Prominent rounded badges displaying niche genres, moods, and geographical origins.
4. **Uncluttered Tracklists:** Sequential track numbering with track titles, durations, and inline play triggers.

#### Applications to AFM#2
* **Featured Contender Hero:** The "Currently on the turntable" card in `index.php` aligns perfectly with Bandcamp’s editorial presentation.
* **Theme Styling:** Complements AFM#2’s "Vintage Parchment" and "Midnight Ember" themes with serif headers and tactile card frames.
* **Discovery Annotations:** Displaying notes, lyrics excerpts, and catalogue track numbers like physical liner notes.

---

### D. SoundCloud — Track-Oriented Scanning & High-Density Feeds
* **Primary Reference:** [https://soundcloud.com/](https://soundcloud.com/)  
* **Official Resources:**
  * [SoundCloud Engineering: Mobile Waveform & Feed Optimizations](https://developers.soundcloud.com/blog/new-with-soundcloud-october-22/)

#### Key Observed Patterns
1. **Compact Track Rows:** High information density on mobile screens (48–60px row height) featuring square thumbnail, track title, artist handle, timestamp, and quick playback button.
2. **Inline Audio Visualizers:** Audio tracks incorporate waveform or sound-wave representations directly into the card.
3. **One-Tap Contextual Actions:** Instant like/shortlist, repost, and overflow options without obscuring metadata.

#### Applications to AFM#2
* **Library Manage Rows:** High-density track rows on mobile screens that show catalogue ID, title, artist, status badge, and action trigger without requiring horizontal table scrolling.
* **Recordings Manager:** Compact candidate rows showing YouTube title, channel name, match confidence score (`88% Auto High`), and one-tap confirmation button.

---

### E. TIDAL — Luxury Dark Surfaces & Immersive Audio Presentation
* **Primary Reference:** [https://tidal.com/](https://tidal.com/)  

#### Key Observed Patterns
1. **Restrained Color Palettes:** Pitch black backgrounds (`#000000`, `#0c0b0f`), dark charcoal cards (`#16151a`), subtle border contrasts, and restrained metallic or cyan accents.
2. **Immersive Full-Bleed Artwork:** Blur-backed hero artwork behind song headers, creating depth without visual noise.
3. **High-Contrast Typographic Hierarchy:** Monospace audio format pills (`MAX`, `FLAC`, `MASTER`) paired with geometric sans-serif metadata.

#### Applications to AFM#2
* **Champion Hall of Fame:** The 2026 Champion podium card in `results.php` benefits immensely from TIDAL’s luxury visual presentation.
* **Formal Auditions Focus Mode:** Immersive single-song evaluation with atmospheric backdrop blurring behind the active song’s cover art.

---

### F. Notion — Mobile Information Architecture & Progressive Disclosure
* **Primary Reference:** [https://www.notion.com/](https://www.notion.com/)  
* **Official Resources:**
  * [Notion Mobile Workspaces & Interaction Guide](https://www.notion.com/help/workspaces-on-mobile)

#### Key Observed Patterns
1. **Progressive Disclosure:** Complex relational databases with 10+ columns collapse on mobile into clean, expandable cards where secondary attributes are tucked behind an "expand" trigger or opened in a modal sheet.
2. **Sticky Section Anchors & Sticky Filter Bars:** Long hierarchical lists feature sticky category headers that pin to the top during scrolling.
3. **Multi-Step Mobile Wizards:** Complex workflows (e.g., page setup, database filtering) are broken into sequential, full-screen mobile steps with clear back/next buttons and progress dots.

#### Applications to AFM#2
* **Manual Qualification Wizard:** Notion’s sequential wizard pattern is the ideal blueprint for AFM#2’s 3-step qualification modal on mobile screens (Step 1: Selection & Quotas, Step 2: Touch-Friendly Reordering & Replacement, Step 3: Final Confirmation & Lock).
* **Archive Stages:** Collapsible accordion sections for each completed tournament stage (Formal Auditions, Top 200, Top 100, etc.) with sticky stage navigation pills.

---

## 3. Curated Visual Reference Collection (18 Structured Examples)

Below is the curated benchmark collection across production applications, design system implementations, and verified UX concepts.

```
+----------------------------------------------------------------------------------------------------------------------------------+
|                                              CURATED VISUAL REFERENCE DIRECTORY                                                  |
+----+-----------------------+--------------------------+------------------------------+---------------------------+---------------+
| #  | Interface Category    | Product / Reference      | Source URL                   | Primary Design Pattern    | Type          |
+----+-----------------------+--------------------------+------------------------------+---------------------------+---------------+
| 01 | Visual Discovery      | Pinterest Mobile         | pinterest.com                | 2-Column Staggered Grid   | Production    |
| 02 | Visual Discovery      | Pinterest Search         | pinterest.com/search         | Floating Bottom Pill Nav  | Production    |
| 03 | Visual Discovery      | Bandcamp Daily           | bandcamp.com                 | Editorial Music Hero      | Production    |
| 04 | Library & Catalogue   | Spotify Mobile Library   | open.spotify.com             | List/Grid View Mode Switch| Production    |
| 05 | Library & Catalogue   | Apple Music Library      | music.apple.com              | Large Tile Collections    | Production    |
| 06 | Track Presentation    | SoundCloud Track Feed    | soundcloud.com               | High-Density Track Rows   | Production    |
| 07 | Track Presentation    | YouTube Music Up Next    | music.youtube.com            | Compact Match / Track Row | Production    |
| 08 | Player & Details      | TIDAL Now Playing        | tidal.com                    | Luxury Dark Atmosphere    | Production    |
| 09 | Player & Details      | Spotify Mini-Player      | open.spotify.com             | Docked Persistent Player  | Production    |
| 10 | Navigation            | Spotify Mobile Nav       | newsroom.spotify.com         | 4-Tab Bottom App Bar      | Production    |
| 11 | Navigation            | Pinterest App Bar        | medium.com/pinterest-eng     | Floating Glassmorphic Bar | Production    |
| 12 | Scorecard Evaluation  | Pitchfork / Metacritic   | metacritic.com               | Prominent Criterion Score | Production    |
| 13 | Scorecard Evaluation  | Music Review App UI      | dribbble.com/shots/music-ui  | Sliders + Auto-Total Card | Concept (UX)  |
| 14 | Complex Workflows     | Notion Mobile Database   | notion.com/help              | Progressive Disclosure    | Production    |
| 15 | Complex Workflows     | Linear Mobile Issue Flow | linear.app                   | Sequential Wizard / Sheet | Production    |
| 16 | Tournament Progression| FIFA / UEFA Bracket UI   | uefa.com                     | Milestone Stepper Nodes   | Production    |
| 17 | Tournament Progression| Esports Bracket Mobile   | behance.net/gallery/bracket  | Vertical Stage Timeline   | Concept (UX)  |
| 18 | Archive & Hall of Fame| TIDAL Artist Top Tracks  | tidal.com                    | Gold Podium & Stats Sheet | Production    |
+----+-----------------------+--------------------------+------------------------------+---------------------------+---------------+
```

### Detailed Evaluation of Key Visual References

#### Ref 01 & 02: Pinterest 2-Column Mobile Masonry & Floating Bottom Bar
* **Source:** [https://www.pinterest.com/search/pins/?q=music%20app%20mobile%20interface%20design](https://www.pinterest.com/search/pins/?q=music%20app%20mobile%20interface%20design)
* **Relevance to AFM#2:** Direct inspiration for AFM#2’s "Discover" view in the Library and the Shortlisted Contenders shelf in the Journal. Solves the issue of dead space on mobile screens while making album artwork the primary attraction.
* **Status:** Verified Production Interface (with thousands of UI explorations).

#### Ref 04 & 09: Spotify Mobile Library Toggle & Docked Mini-Player Bar
* **Source:** [https://newsroom.spotify.com/2023-06-20/spotify-desktop-experience-redesign-your-library-now-playing-views-customize/](https://newsroom.spotify.com/2023-06-20/spotify-desktop-experience-redesign-your-library-now-playing-views-customize/)
* **Relevance to AFM#2:** Essential architecture for AFM#2. Solves the dual-need of cataloguers: sometimes users want to browse visually (Discover), and sometimes they need to select 20 tracks for bulk deletion or edit metadata (Manage).
* **Status:** Official Production Architecture.

#### Ref 06: SoundCloud Compact Track Rows
* **Source:** [https://developers.soundcloud.com/blog/new-with-soundcloud-october-22/](https://developers.soundcloud.com/blog/new-with-soundcloud-october-22/)
* **Relevance to AFM#2:** In the Library "Manage" mode and Recordings manager, users need to scan dozens of tracks quickly without horizontal scrolling. SoundCloud’s 52px row height with clear metadata hierarchy is optimal.
* **Status:** Official Production Engineering.

#### Ref 14 & 15: Notion & Linear Multi-Step Mobile Wizards
* **Source:** [https://www.notion.com/help/workspaces-on-mobile](https://www.notion.com/help/workspaces-on-mobile) & [https://linear.app](https://linear.app)
* **Relevance to AFM#2:** The Manual Qualification Wizard in AFM#2 is a complex desktop workflow (multi-column candidate table with reordering, validation warnings, and confirmation). Notion and Linear demonstrate how to convert this into a 3-step mobile sheet that feels effortless on a 390px iPhone screen.
* **Status:** Verified Production Systems.

---

## 4. Synthesis: Patterns to Adopt vs. Patterns to Reject

### 4.1 Patterns Worth Adopting for AFM#2
1. **Persistent 5-Item Bottom App Bar:** Instant thumb navigation between core destinations (`Home`, `Library`, `Auditions`, `Tournament`, `More`), eliminating the 2-tap hamburger drawer friction.
2. **Dual-Mode Library Switcher (`Discover` vs. `Manage`):** A toggle pill bar at the top of the Library allowing users to switch between a Pinterest-inspired 2-column artwork grid and a SoundCloud-inspired compact data list.
3. **Focused Single-Song Audition Flow:** A card-based mobile audition interface where one song is evaluated at a time with large tactile range sliders, instant /50 math updates, and docked audio/lyrics drawers.
4. **Sequential Mobile Qualification Wizard:** Transforming the desktop advancement modal into a 3-step full-height mobile sheet (Select & Validate $\rightarrow$ Reorder & Replace $\rightarrow$ Review & Lock).
5. **Sticky Filter & Stage Navigation Chips:** Horizontal scrolling pill navigators that stick beneath the mobile header during scrolling.
6. **Tactile 44×44px Touch Targets:** Strict adherence to minimum touch dimensions across all buttons, checkboxes, range sliders, and pagination items.

### 4.2 Patterns Unsuitable for AFM#2
1. **Indiscriminate Masonry Across Data Tables:** Attempting to display tournament rankings, tie-breaker scores, or deletion checklists in masonry layouts creates visual chaos and prevents comparative analysis.
2. **Infinite Vertical Feeds Without Milestones:** Replacing structured pagination with endless scrolling harms cataloguing accountability. In AFM#2, knowing which page you are on and seeing total song counts (`1,400 Songs Preserved`) is critical to catalogue integrity.
3. **Heavy Client-Side SPA Frameworks (React/Vue/Next.js):** AFM#2 runs on free shared hosting (₹0 budget). Rebuilding the frontend in a heavy JavaScript framework adds bundle bloat, slows initial paint on mobile devices, and risks hosting CPU limit throttling. All improvements must use vanilla CSS and vanilla JavaScript.
4. **Hiding Scoring Math Behind Gestures:** Concealing criteria or tie-breaker scores behind hidden swipe gestures damages tournament transparency. All 5 criteria scores must remain legible.

---
*End of Design Research Document.*
