# AFM#2 — Whole-Product Responsive UI/UX Specification (Phase 0.1 Refinement)
## Desktop Preservation, Comprehensive Interface Refinement, Tournament Integrity & Performance Architecture

**Project:** AFM#2 (Advanced Feature Matrix #2)  
**Task Classification:** Whole-Product Responsive UI/UX Specification (Phase 0.1 Refinement)  
**Production Implementation Authorization:** **NOT GRANTED (Evaluation & Planning Only)**  
**Author:** Antigravity AI Engineering & Design Team  
**Date:** November 2026  

---

## 1. Executive Principles & Architecture

AFM#2 is undergoing a **complete, whole-product responsive UI/UX refinement across all device form factors—not a mobile-only redesign**.

We reject "collapsing responsiveness" (taking a 12-column desktop interface and blindly stacking it into an endless, exhausting mobile scroll) and equally reject "mobile cardification" (forcing oversized mobile cards or bottom navigation bars onto desktop monitors).

```
+---------------------------------------------------------------------------------------------------+
|                        AFM#2 WHOLE-PRODUCT RESPONSIVE CONTINUUM                                   |
+--------------------------+----------------------------+-------------------------------------------+
| MOBILE (320px - 599px)   | TABLET (600px - 1023px)    | DESKTOP & WORKSTATION (1024px - 1920px+)  |
+--------------------------+----------------------------+-------------------------------------------+
| • Thumb-zone ergonomics  | • Hybrid touch/pointer     | • Maximum information density             |
| • 5-tab bottom app nav   | • Collapsible top drawer   | • Persistent top header navigation        |
| • Single-song focus card | • 2-column scorecard cards | • Continuous multi-card audition sheet    |
| • Pinterest visual grid  | • Responsive data tables   | • 8-to-10 column sorting data tables      |
| • 52px SoundCloud rows   | • Floating bulk toolbars   | • Persistent floating bulk selection dock |
| • 3-step touch wizard    | • 2-step compact wizard    | • HTML5 multi-column qualification modal  |
+--------------------------+----------------------------+-------------------------------------------+
```

### Core Invariants Guaranteed:
1. **Desktop Remains First-Class:** Desktop is the primary workstation for catalogue administration, CSV ingestion, multi-song batch scoring, and deep analytical tournament decisions. Its multi-column tables, persistent sorting headers, dense tabular rows, and multi-field filters remain intact and elevated.
2. **Intentional Breakpoint Continuum:** Responsive layouts adapt purposefully across 8 continuous, non-overlapping transition ranges (from 320px up to 1920px+).
3. **Tournament & Historical Integrity:** Strict enforcement of immutable historical stages, manual qualification advancement, advisory scores, and the exact 7-field ranking hierarchy.
4. **Zero Production Risk:** Work is strictly confined to `docs/` and `prototype/`. Zero production PHP, JS, CSS, or MySQL database files have been altered.

---

## 2. Breakpoint Specification & Continuous Transition Ranges

To eliminate discrepancies between test widths and layout transitions, AFM#2 establishes **eight continuous, non-overlapping layout transition ranges**. Every intermediate viewport width is explicitly handled to eliminate awkward wrapping, clipped controls, or horizontal overflow.

```
+------------------------------------------------------------------------------------------------------------------+
|                                    8 CONTINUOUS RESPONSIVE TRANSITION RANGES                                     |
+------+----------------------+---------------------------+-----------------------+--------------------------------+
| Tier | Viewport Range       | Target Archetypes         | Navigation Pattern    | Primary Layout Behavior        |
+------+----------------------+---------------------------+-----------------------+--------------------------------+
| **1**| **320px – 359px**    | iPhone SE (1st gen), 340px| 5-Tab Bottom Nav      | Single-col / tight 8px 2-col   |
| **2**| **360px – 389px**    | Galaxy S/A, iPhone 8/SE2  | 5-Tab Bottom Nav      | 2-Col Pinterest (10px gap)     |
| **3**| **390px – 429px**    | iPhone 13/14/15, Pixel 7  | 5-Tab Bottom Nav      | 2-Col Pinterest (12px gap)     |
| **4**| **430px – 599px**    | iPhone Pro Max, Phablets  | 5-Tab Bottom Nav      | 2-Col Pinterest (16px gap)     |
| **5**| **600px – 767px**    | Small Tablet, Foldables   | Hybrid Drawer / Bottom| 2-3 Col hybrid grid            |
| **6**| **768px – 1023px**   | iPad Portrait, Compact Tab| Collapsible Top Drawer| Frozen-col table / 2x3 cards   |
| **7**| **1024px – 1439px**  | Laptop, Standard Desktop  | Full Top Desktop Nav  | Full 8-10 col tables, 25 cards |
| **8**| **1440px – 1920px+** | QHD, 4K, Ultrawide        | Desktop Nav + Jumper  | Constrained 1440px + Quick Jump|
+------+----------------------+---------------------------+-----------------------+--------------------------------+
```

### Detailed Range Behaviors & Intermediate Widths:

* **Range 1: Compact Mobile (`320px` to `359px`)**
  - *Representative Widths:* 320px (iPhone SE 1st gen), 340px, 350px.
  - *Layout Adaptations:* Screen padding locked to `10px`–`12px`. Grid gap shrinks to `8px`. Top brand equalizer hides 2 bars to prevent title truncation. Range sliders span full container width.
  - *Intermediate Safety:* At 340px, track title text wraps gracefully with `line-clamp: 2`; no horizontal scrolling.
* **Range 2: Standard Smartphone (`360px` to `389px`)**
  - *Representative Widths:* 360px (Samsung Galaxy A/S), 375px (iPhone 7/8/SE 2nd gen).
  - *Layout Adaptations:* Standard 2-column Pinterest grid with `10px` gap. Manage rows operate at 48px height. Screen padding is `14px`.
* **Range 3: Modern Smartphone (`390px` to `429px`) — *Mobile Baseline***
  - *Representative Widths:* 390px (iPhone 12/13/14/15 baseline), 412px (Pixel 7/8), 414px (iPhone 11/XR).
  - *Layout Adaptations:* Standard mobile reference layout. `16px` horizontal padding. 52px manage rows. 44px minimum touch targets across all buttons and inputs.
* **Range 4: Large Smartphone & Phablet (`430px` to `599px`)**
  - *Representative Widths:* 430px (iPhone 14/15 Pro Max), 480px, 540px.
  - *Layout Adaptations:* 2-column grid expands breathing room with `16px` gap. Artwork cards retain 1:1 ratio. Text maintains crisp proportional hierarchy without awkward stretching.
* **Range 5: Small Tablet & Foldable Landscape (`600px` to `767px`)**
  - *Representative Widths:* 600px (7" Android tablets, unfolded foldables), 680px, 720px.
  - *Layout Adaptations:* Transitions smoothly from 2-column to 3-column visual cards in Library Discover. In Auditions, the single-song layout widens to a comfortable max-width of 540px centered.
* **Range 6: Standard Tablet & Compact Laptop (`768px` to `1023px`)**
  - *Representative Widths:* 768px (iPad Portrait), 820px (iPad 10th gen), 834px (iPad Pro 11"), 900px, 960px.
  - *Layout Adaptations:* Header switches to a collapsible top drawer to prevent navigation links from crowding. Data tables in Library Manage and Tournament utilize an internal horizontal scroll container (`-webkit-overflow-scrolling: touch;`), freezing the `#` and `Title` columns (`position: sticky; left: 0;`). Audition scorecards arrange criteria into a 2x3 grid. Works seamlessly with both touch and mouse/trackpad pointer input.
* **Range 7: Standard Desktop Workstation (`1024px` to `1439px`) — *Desktop Baseline***
  - *Representative Widths:* 1024px (iPad Landscape / 13" laptop), 1152px, 1280px (Standard 720p monitor), 1366px (15.6" laptop), 1440px.
  - *Layout Adaptations:* Full persistent horizontal desktop top navigation bar. Mobile bottom navigation is hidden (`display: none !important`). Full 8-column tabular ledger with sticky table header (`top: var(--header-height)`), row hover micro-actions, and floating bulk dock. Auditions displays continuous multi-card layout with 25 cards per page.
* **Range 8: Large & Ultrawide Workstation (`1440px` and above, up to `1920px+`)**
  - *Representative Widths:* 1440px, 1600px, 1920px (Full HD 1080p), 2560px (4K).
  - *Layout Adaptations:* Page container constrained to `max-width: 1440px` centered (`margin: 0 auto;`), preventing over-stretched text lines, distorted aspect ratios, and awkward horizontal table drift. Auditions introduces a **Sticky Quick-Jumper Sidebar** in the left/right display margins displaying completion status dots (`● Complete`, `○ Unscored`) for rapid zero-scroll jumping across the 25 entries.

---

## 3. Performance Acceptance Criteria

Performance requirements are structured into three distinct tiers:

```
+---------------------------------------------------------------------------------------------------+
|                        PERFORMANCE FRAMEWORK: CONSTRAINTS, TARGETS & METRICS                      |
+--------------------------+-----------------------+-----------------------+------------------------+
| Category                 | Metric / Scope        | Acceptance Threshold  | Engineering Rationale  |
+--------------------------+-----------------------+-----------------------+------------------------+
| **Hard Constraints**     | Zero-Cost Hosting     | PHP 8.x / MySQL 5.7+  | InfinityFree execution;|
|                          | Memory Budget         | $\le 10\text{MB}$ peak| No background daemons. |
|                          | External CDNs         | ZERO (0) new CDNs     | Pure native assets.    |
|                          | Frameworks            | ZERO (0) JS frameworks| Vanilla JS & CSS only. |
+--------------------------+-----------------------+-----------------------+------------------------+
| **Optimization Targets** | `style.css` (raw)     | $\approx 220–240\text{ KB}$| Maintainability first; |
|                          | `style.css` (gzipped) | $\le 42\text{ KB}$    | avoid crypt-like min.  |
|                          | `mobile.css` (gzipped)| $\le 8\text{ KB}$     | Zero selector bloat.   |
|                          | Initial HTTP Requests | $\le 5$ requests      | Fast cold start.       |
|                          | DOM Nodes Per View    | $\le 1,500$ nodes     | Strict 25/100 paging.  |
+--------------------------+-----------------------+-----------------------+------------------------+
| **Measured Criteria**    | Cumulative Layout Shift| **$\text{CLS} \le 0.1$**| Realistic & measurable |
|                          | Largest Contentful Paint| $\text{LCP} \le 2.5\text{s}$| Good CWV threshold.   |
|                          | Interaction to Next Paint| $\text{INP} \le 200\text{ms}$| Responsive sliders.    |
|                          | Event Listener Pattern| **Container Delegation**| Memory & DOM hygiene.  |
+--------------------------+-----------------------+-----------------------+------------------------+
```

### 3.1 Hard Architectural Constraints
- **Zero-Cost Hosting Execution:** Must execute reliably within InfinityFree's standard Apache / PHP 8.x / MySQL environment. No Node.js runtime, Redis caching, or serverless workers.
- **Zero New External CDNs:** No external CSS frameworks (Tailwind, Bootstrap), JavaScript libraries (React, Vue, jQuery), or third-party tracking scripts.
- **Authentication & Security Invariants:** 100% preservation of PHP session authentication (`require_auth()`), CSRF verification (`validate_csrf_token()`), SQL scoping, and database transactions.

### 3.2 Recommended Optimization Targets
- **Maintainability Over Dogmatic Size Caps:** Do not compromise CSS modularity, semantic token architecture, or readable BEM class naming merely to achieve an arbitrary CSS byte count. Optimize by pruning truly dead overrides, consolidating media queries, and eliminating repeated property declarations.
- **Wire Size Efficiency:** Target compressed wire transfer sizes of $\le 42\text{KB}$ for `style.css` and $\le 8\text{KB}$ for `mobile.css` using standard `gzip` or `brotli` compression.

### 3.3 Measured Performance Acceptance Criteria
### 3.3 Measured Performance Acceptance Criteria (Field vs Lab)

Performance must be evaluated by distinguishing **Production Field Measurements** (Real User Monitoring at 75th percentile) from **Local Laboratory Measurements** (synthetic DevTools / Lighthouse audits):

#### 1. Production Field Measurements (Primary Core Web Vitals — p75)
- **Interaction to Next Paint (INP):** **Primary responsiveness metric**. Target: **$\text{INP} \le 200\text{ms}$** at the 75th percentile across all user interactions (slider dragging, tab switching, modal triggers).
- **Largest Contentful Paint (LCP):** Target: **$\text{LCP} \le 2.5\text{s}$** at the 75th percentile on mobile cellular connections.
- **Cumulative Layout Shift (CLS):** Measured target **$\text{CLS} \le 0.1$** at the 75th percentile. Eliminates avoidable layout shifts by enforcing explicit `aspect-ratio: 1 / 1` on album artwork and `16 / 9` on video player containers.
- **Legacy Diagnostic Metric (FID):** First Input Delay (**$\text{FID} \le 100\text{ms}$**) is retained solely as a legacy diagnostic metric for historical audit comparisons; it is **not** treated as a separate primary Core Web Vital.

#### 2. Local Laboratory Measurements (Lighthouse / Synthetic Web Vitals)
- **Total Blocking Time (TBT):** Target: **$\text{TBT} \le 150\text{ms}$** under 4x CPU throttling (serving as the laboratory proxy for field INP).
- **First Contentful Paint (FCP):** Target: **$\text{FCP} \le 1.8\text{s}$**.
- **Speed Index (SI):** Target: **$\text{SI} \le 3.0\text{s}$**.
- **Transferred Asset Budgets:** Consolidated CSS wire transfer target ~220–240 KB unminified (~35–45 KB minified/gzipped); initial cold HTTP requests $\le 12$; JavaScript parse/compile execution $\le 100\text{ms}$ on mid-tier mobile hardware; maximum DOM depth $\le 14$; total DOM nodes $\le 1,200$.
- **Container-Level Event Delegation:** Interactive components must utilize a **container-level event delegation pattern** (e.g. single listener on `#songsTable` or `#scorecardsContainer` utilizing `e.target.closest()`). This avoids binding individual listeners to hundreds of table cells and buttons, reducing memory allocation and DOM attachment overhead while maintaining readable, maintainable dispatch logic.

---

## 4. External Dependency Inventory

To guarantee that the redesign introduces **zero unauthorized external services**, the table below audits every external service currently utilized in the AFM#2 codebase and establishes boundaries:

```
+------------------------------------------------------------------------------------------------------------------+
|                                    AFM#2 EXTERNAL SERVICE AUDIT & BOUNDARIES                                     |
+-----------------------+-----------------------+-----------------------+------------------------------------------+
| External Service      | Current Host / Scope  | Current Usage in Code | Status & Boundary in Redesign            |
+-----------------------+-----------------------+-----------------------+------------------------------------------+
| **Google Fonts**      | `fonts.googleapis.com`| Web fonts in          | **APPROVED (Existing):** Outfit,         |
|                       | `fonts.gstatic.com`   | `includes/header.php` | Plus Jakarta Sans, Playfair, Cormorant.  |
+-----------------------+-----------------------+-----------------------+------------------------------------------+
| **YouTube Embeds**    | `youtube-nocookie.com`| Video playback &      | **APPROVED (Existing):** Privacy-enhanced|
|                       | `img.youtube.com`     | thumbnails in         | nocookie player; iframe lazy loading.    |
|                       |                       | `media_helper.php`    |                                          |
+-----------------------+-----------------------+-----------------------+------------------------------------------+
| **LRCLIB API**        | `https://lrclib.net`  | Synced/plain lyrics   | **APPROVED (Existing):** On-demand       |
|                       |                       | in `media_helper.php` | lyrics lookup; local DB caching.         |
+-----------------------+-----------------------+-----------------------+------------------------------------------+
| **Google Identity**   | `accounts.google.com` | Google sign-in / link | **APPROVED (Existing):** Optional OAuth2 |
|                       |                       | in `google_auth.php`  | identity linking.                        |
+-----------------------+-----------------------+-----------------------+------------------------------------------+
| **MusicBrainz &**     | `musicbrainz.org`     | Album art discovery   | **APPROVED (Existing Codebase):** Imple- |
| **Cover Art Archive** | `coverartarchive.org` | in `artwork_helper.php`| mented in `includes/artwork_helper.php` |
|                       |                       | (lines 131–230)       | lines 131–230; caches to `song_artwork`. |
+-----------------------+-----------------------+-----------------------+------------------------------------------+
| **Openverse API**     | `api.openverse.org`   | CC atmospheric photos | **APPROVED (Existing Codebase):** Imple- |
|                       |                       | in `artwork_helper.php`| mented in `includes/artwork_helper.php` |
|                       |                       | (lines 235–310)       | lines 235–310; caches to `song_artwork`. |
+-----------------------+-----------------------+-----------------------+------------------------------------------+
| **NEW External CDNs** | Any external domain   | None                  | **STRICTLY PROHIBITED:** Zero new        |
| **or Frameworks**     |                       |                       | external libraries, CDNs, or frameworks. |
+-----------------------+-----------------------+-----------------------+------------------------------------------+
```

> [!IMPORTANT]
> **Zero New Services Rule:** The UI/UX redesign introduces **zero (0) new external services**. Cover Art Archive and Openverse are already part of the existing backend architecture in `includes/artwork_helper.php`. All procedural fallbacks (like vinyl turntable graphics) are rendered using inline CSS and SVG, incurring 0 external network requests.

---

## 5. Tournament & Historical Integrity Invariants

The AFM#2 competition engine is governed by strict mathematical and historical integrity rules. The redesign strictly respects these invariants:

### 5.1 Exact 7-Field Deterministic Ranking Hierarchy

The tournament leaderboard sorting order is locked to the exact SQL ordering implemented in `includes/db_helper.php` (`get_round_rankings()`, lines 2710–2717):

```sql
ORDER BY 
    r.`total` DESC,          -- 1. Primary Total Audition Score (0 to 50)
    r.`emotion` DESC,        -- 2. Tie-Breaker 1: Emotion Criterion (0 to 10)
    r.`replayability` DESC,  -- 3. Tie-Breaker 2: Replayability Criterion (0 to 10)
    r.`melody` DESC,         -- 4. Tie-Breaker 3: Melody Criterion (0 to 10)
    r.`vocals` DESC,         -- 5. Tie-Breaker 4: Vocals Criterion (0 to 10)
    r.`lyrics` DESC,         -- 6. Tie-Breaker 5: Lyrics Criterion (0 to 10)
    e.`entry_id` ASC         -- 7. Stable Deterministic Tie-Breaker (Lower Entry ID wins absolute tie)
```

This **7-field ranking sequence** ensures that no two songs ever share an ambiguous rank position. The interface must display the exact decisive tie-breaking criterion badge whenever two contenders share an identical total score.

### 5.2 Shortlisting & Immutability Rules
- **Formal Auditions Is the Sole Shortlisting Gateway:** Songs can enter the tournament competition exclusively through the Formal Auditions stage.
- **Shortlist Immutability:** Once the Formal Auditions stage is finalized, the shortlist is permanently locked and immutable.
- **Completed Stages Are Immutable:** Completed tournament stages cannot be reopened, edited, or recalculated. Once authorized, results are locked to `round_results`.
- **Manual Qualification Advancement:** Evaluation scores are **strictly advisory**. The system never advances songs automatically based on score. Advancement is explicitly and manually authorized by an authorized judge/admin through the Qualification Wizard.
- **Stage Progression & Qualification Targets (The 6 Stage Transitions):**
  - *Transition 1: Formal Auditions (Stage 1) $\rightarrow$ Top 200 (Stage 2):* Target Cutoff = **200 qualifiers** (from entire shortlisted catalogue).
  - *Transition 2: Top 200 (Stage 2) $\rightarrow$ Top 100 (Stage 3):* Target Cutoff = **100 qualifiers** (from 200 available participants).
  - *Transition 3: Top 100 (Stage 3) $\rightarrow$ Top 50 (Stage 4):* Target Cutoff = **50 qualifiers** (from 100 available participants).
  - *Transition 4: Top 50 (Stage 4) $\rightarrow$ Top 20 (Stage 5):* Target Cutoff = **20 qualifiers** (from 50 available participants).
  - *Transition 5: Top 20 (Stage 5) $\rightarrow$ Top 10 (Stage 6):* Target Cutoff = **10 qualifiers** (from 20 available participants).
  - *Transition 6: Top 10 (Stage 6) $\rightarrow$ Finals (Stage 7):* Target Cutoff = **1 Champion** (from 10 available contenders).
- **The Finals Format & Championship Decision:**
  - In Stage 6 (Top 10), there are exactly **10 available participants**.
  - Advancing from Top 10 to Finals selects **1 single participant** (`target_count = 1`), who is crowned the Tournament Champion (`result = 'winner'`, `manual_order = 1`).
  - Stage 7 ("Finals") in the `rounds` table is the championship podium and destination record with `target_count = 1`.
  - Top 10 $\rightarrow$ Finals does **not** select five participants or any intermediate cohort; it is the final championship decision crowning the single winner.
- **Exact Selection Ordering Preserved:** In Step 2 of the Qualification Wizard, the exact manually curated order (adjusted via `Move Up` / `Move Down` touch buttons or desktop drag) is recorded directly into `round_results.rank_position` and `round_results.manual_order`.
- **Authoritative Champion Score:** The tournament champion score is sourced strictly and exclusively from the finalized Finals round evaluation.

---

## 6. Multi-Theme Consistency Architecture & Empirical Contrast Verification

### 6.1 Semantic Token Architecture & Name Resolution
Every theme defines its visual foundation in `public/assets/css/style.css`.
- **Canonical Card Token (`--bg-card`):** Every production theme explicitly defines `--bg-card: <hex>;` and `--bg-card-hover: <hex>;`.
- **Token Alias Resolution (`--card-bg`):** The stylesheet defines `--card-bg: var(--bg-card);` in `:root` (line 59) as a backward-compatibility alias. All shared responsive component styles must bind to **`--bg-card`** as the canonical token, or use `var(--card-glass-bg, var(--bg-card))` for frosted surfaces.
- **Zero Hardcoding:** Components never hardcode hex colors (such as Midnight Muse `#0c0b0f` or `#f59e0b`). All colors, borders, and shadows bind to `var(--bg-main)`, `var(--bg-card)`, `var(--text-primary)`, `var(--text-secondary)`, `var(--text-muted)`, `var(--border-color)`, and `var(--primary)`.

### 6.2 Empirical Contrast Measurements Across All 8 Production Themes

The table below documents the **actual measured contrast ratios** calculated according to the WCAG 2.1 relative luminance specification ($L = 0.2126 R + 0.7152 G + 0.0722 B$ after sRGB gamma expansion) across all 8 production themes, distinguishing text contrast from non-text UI component contrast:

```
+-----------------------------------------------------------------------------------------------------------------------------------------------------------------------+
|                                                  MEASURED WCAG 2.1 CONTRAST RATIOS ACROSS ALL 8 PRODUCTION THEMES                                                     |
+---+----------------------+--------------------+--------------------+--------------------+--------------------+--------------------+--------------------+--------------+
| # | Production Theme     | Primary Text on    | Primary Text on    | Secondary Text on  | Muted Text on      | Button Text on     | Focus Ring on      | Overall Theme|
|   |                      | `--bg-main` Canvas | `--bg-card` Card   | `--bg-card` Card   | `--bg-card` Card   | Primary Button [1] | `--bg-card` [2]    | Rating       |
+---+----------------------+--------------------+--------------------+--------------------+--------------------+--------------------+--------------------+--------------+
| 1 | `midnight_ember`     | 18.97:1 (AAA)      | 16.73:1 (AAA)      | 12.11:1 (AAA)      | 7.20:1 (AAA)       | 8.78:1 (AAA)       | 8.06:1 (Pass 3:1)  | WCAG 2.1 AA  |
| 2 | `velvet_amethyst`    | 19.00:1 (AAA)      | 16.88:1 (AAA)      | 12.73:1 (AAA)      | 8.01:1 (AAA)       | 7.38:1 (AAA)       | 6.73:1 (Pass 3:1)  | WCAG 2.1 AA  |
| 3 | `emerald_forest`     | 18.24:1 (AAA)      | 13.62:1 (AAA)      | 12.59:1 (AAA)      | 6.79:1 (AA)        | 9.42:1 (AAA)       | 7.43:1 (Pass 3:1)  | WCAG 2.1 AA  |
| 4 | `vintage_paper`      | 16.24:1 (AAA)      | 18.26:1 (AAA)      | 13.01:1 (AAA)      | 9.04:1 (AAA)       | 7.65:1 (AAA)       | 7.41:1 (Pass 3:1)  | WCAG 2.1 AA  |
| 5 | `nordic_frost`       | 19.02:1 (AAA)      | 19.90:1 (AAA)      | 14.63:1 (AAA)      | 7.58:1 (AAA)       | 6.70:1 (AA)        | 6.70:1 (Pass 3:1)  | WCAG 2.1 AA  |
| 6 | `solar_terracotta`   | 16.47:1 (AAA)      | 17.49:1 (AAA)      | 15.65:1 (AAA)      | 8.98:1 (AAA)       | 5.18:1 (AA)        | 5.18:1 (Pass 3:1)  | WCAG 2.1 AA  |
| 7 | `oceanic` (Adjusted) | 18.17:1 (AAA)      | 14.77:1 (AAA)      | 12.01:1 (AAA)      | 6.72:1 (AA)        | 5.47:1 (AA) [1]    | 6.19:1 (Pass 3:1)  | WCAG 2.1 AA  |
| 8 | `quiet_minimal`      | 16.83:1 (AAA)      | 17.72:1 (AAA)      | 10.44:1 (AAA)      | 7.73:1 (AAA)       | 14.89:1 (AAA)      | 14.89:1 (Pass 3:1) | WCAG 2.1 AA  |
+---+----------------------+--------------------+--------------------+--------------------+--------------------+--------------------+--------------------+--------------+
* Note on Compliance Labeling: No theme is labeled blanket "AAA". While primary text achieves AAA across all themes (13.62:1 to 19.90:1), secondary elements (muted text and button labels) meet Level AA (4.5:1 to 6.79:1). All eight themes achieve verified WCAG 2.1 Level AA compliance.
```

### 6.3 Detailed Accessibility Guardrails by Component & State

#### 1. Oceanic Primary Button Contrast Correction
- **Issue:** Under WCAG 2.1 SC 1.4.3, button labels at `0.95rem` (15.2px) bold do not qualify as "large text" (which strictly requires $\ge 18\text{pt}$ / 24px regular, or $\ge 14\text{pt}$ / ~18.66px bold). Normal text requires $\ge 4.5:1$. White text (`#ffffff`) on original `--primary: #0d9488` yielded 3.74:1, failing Level AA.
- **Correction:** In the Oceanic theme, the primary button background token is adjusted to **`#0f766e`** (Deep Marine Teal, existing `--primary-hover` in `style.css` line 312).
  - Measured Contrast: `#ffffff` on `#0f766e` = **5.47:1** (PASSES WCAG AA $\ge 4.5:1$ for normal text).
  - Visual Identity: Preserves the authentic deep marine aesthetic while elevating contrast cleanly above 4.5:1.
  - Alternative Token: If retaining `#0d9488`, dark marine text `--text-on-primary: #061219` provides **5.06:1** (PASSES WCAG AA).

#### 2. Nordic Frost & Solar Terracotta: Accent Usage Separation (Text vs Non-Text)
- **Nordic Frost:**
  - `--accent: #0284c7` on `--bg-card` (`#ffffff`) = **4.10:1** (Fails 4.5:1 for normal text).
  - `--accent: #0284c7` on `--bg-main` (`#f8fafc`) = **3.91:1** (Fails 4.5:1 for normal text).
  - **Text Constraint:** `#0284c7` **must never be used for readable text**, category labels, or small badge copy.
  - **Badge / Link Text Rule:** Category badges and interactive text must use `--primary: #1d4ed8` (**6.70:1** on card, **6.41:1** on canvas — WCAG AA) or deep sky blue `#0369a1` (**5.93:1** on card — WCAG AA) or high-contrast body text `--text-secondary: #1e293b` (**14.63:1** on card).
  - **Non-Text Compliance:** `#0284c7` achieves **4.10:1** / **3.91:1**, which exceeds the **3.0:1** non-text UI component contrast requirement (SC 1.4.11). It remains fully compliant for decorative card outlines, progress bar tracks, and non-text visual indicators.
- **Solar Terracotta:**
  - `--accent: #ea580c` on `--bg-card` (`#ffffff`) = **3.56:1** (Fails 4.5:1 for normal text).
  - `--accent: #ea580c` on `--bg-main` (`#fff7ed`) = **3.35:1** (Fails 4.5:1 for normal text).
  - **Text Constraint:** `#ea580c` **must never be used for readable text**, category labels, or small badge copy.
  - **Badge / Link Text Rule:** Category badges and interactive text must use `--primary: #c2410c` (**5.18:1** on card, **4.88:1** on canvas — WCAG AA) or deep sienna `#9a3412` (**7.31:1** on card — WCAG AAA) or high-contrast body text `--text-secondary: #431407` (**15.65:1** on card).
  - **Non-Text Compliance:** `#ea580c` achieves **3.56:1** on card and **3.35:1** on canvas, which exceeds the **3.0:1** non-text threshold (SC 1.4.11). It is strictly reserved for decorative outlines, slider thumbs, and badge container fills.

#### 3. Vintage Paper: Independent Canvas vs Card Verification
- **Card Background (`--bg-card: #fdfbf7`):**
  - `--accent: #b45309` on `#fdfbf7` = **4.86:1** (PASSES WCAG AA $\ge 4.5:1$ for normal text on cards).
  - Small category badges rendered directly inside cards may use `#b45309`.
- **Canvas Background (`--bg-main: #f5ede0`):**
  - `--accent: #b45309` on `#f5ede0` = **4.32:1** (Fails 4.5:1 for normal text on the outer canvas).
  - **Text Constraint:** `#b45309` **must never be used for normal text on the outer canvas**.
  - **Canvas Text Rule:** Any readable labels or links on the canvas must use `--primary: #8c3b06` (**6.59:1** on canvas — WCAG AA) or `--text-primary: #190f07` (**16.24:1** on canvas — WCAG AAA).
  - **Non-Text Compliance on Canvas:** `#b45309` provides **4.32:1** on canvas, which exceeds the **3.0:1** requirement for non-text UI borders and icons.

#### 4. Form Controls, Focus Indicators & Interactive States
- **Focus Indicators:** Under WCAG 2.1 SC 1.4.11, focus rings must achieve $\ge 3.0:1$ against adjacent backgrounds. Across all 8 themes, focus rings bind to `var(--primary)` or `var(--accent)`, achieving **5.18:1 to 14.89:1** contrast against card surfaces.
- **Form Input Placeholders:** Placeholder text binds to `var(--text-muted)` across all themes, achieving **6.72:1 to 9.04:1** on inputs, surpassing the 4.5:1 text requirement.
- **Hover & Active States:** Row and card hovers use subtle tinted overlays (`rgba(..., 0.04)` to `0.06`) that do not alter the underlying text contrast below 4.5:1.



### Shared Component Token Mapping Rules:
- **Surface Backgrounds:** `var(--bg-surface)`, `var(--bg-card)`, `var(--bg-card-hover)`
- **Borders & Dividers:** `var(--border-color)`, `var(--border-subtle)`
- **Brand & Accents:** `var(--primary)`, `var(--primary-hover)`, `var(--accent)`, `var(--gold)`
- **Typography:** `var(--text-primary)`, `var(--text-secondary)`, `var(--text-muted)`
- **Zero Midnight Muse Hardcoding:** Shared classes (e.g. `.art-card`, `.data-table`, `.scorecard-container`) never hardcode `#0c0b0f` or `#f59e0b`. They reference the semantic variables above, guaranteeing flawless rendering across light themes (`vintage_paper`, `nordic_frost`) and dark themes alike.

---

## 7. Desktop & Tablet UI/UX Validation & Refinements

### 7.1 Desktop Power Features
1. **Sticky Data Table Headers:** On `library.php` and `tournament.php`, headers remain docked at `top: var(--header-height)` with backdrop blur during deep 100-row scrolling.
2. **Hover Micro-Actions:** Primary table data remains clean and uncluttered; secondary actions (Play, Lyrics, YouTube link) reveal on row hover with smooth transitions.
3. **Floating Bulk Action Dock:** When checkboxes are active, a floating pill dock docks at the screen bottom with selection count, clear button (`Esc`), and batch operation triggers.
4. **Sticky Quick-Jumper Sidebar:** On $\ge 1440\text{px}$ displays in `auditions.php`, an unobtrusive sidebar displays all 25 song status dots for instant zero-scroll jumping.
5. **Side-by-Side Audio & Lyrics Drawer:** Replaces awkward vertical stacking with a 50/50 split view.

### 7.2 Tablet Touch & Pointer Hybrid Usability
- Minimum 44px hit targets on all interactive controls.
- Tables support smooth touch kinetic scrolling with frozen identification columns.
- Modals scale comfortably with generous margins, never stretching edge-to-edge.

---

## 8. Controlled Implementation Protocol & Risk Management

When production implementation authorization is granted, engineering will follow this disciplined, phased protocol:

```
+-----------------------------------------------------------------------------------------+
|                         CONTROLLED PHASED IMPLEMENTATION PROTOCOL                       |
+--------------------------+-----------------------+--------------------------------------+
| Phase                    | Target Files          | Quality & Verification Gates         |
+--------------------------+-----------------------+--------------------------------------+
| **Phase 1: Token Audit** | `style.css`           | Verify zero CSS breakage across all  |
|                          |                       | 8 themes in local test environment.  |
+--------------------------+-----------------------+--------------------------------------+
| **Phase 2: Desktop UI**  | `library.php`,        | Test sticky headers, hover actions,  |
| **Enhancements**         | `auditions.php`,      | and table sorting on 1024px–1920px.  |
|                          | `tournament.php`      |                                      |
+--------------------------+-----------------------+--------------------------------------+
| **Phase 3: Responsive**  | `mobile.css`,         | Test 320px–599px mobile, 600px–1023px|
| **Mobile Integration**   | `includes/header.php`,| tablet widths. Verify 5-tab nav.     |
|                          | `includes/footer.php` |                                      |
+--------------------------+-----------------------+--------------------------------------+
| **Phase 4: Performance** | Asset build scripts,  | Run before/after asset size audits.  |
| **Verification**         | Local test harness    | Verify `style.css` <= 240KB uncomp.  |
+--------------------------+-----------------------+--------------------------------------+
```

- **Isolated Test Database:** All validation executes against local test fixtures.
- **Strict Boundary:** No live production changes, deployments, or Git merges will occur without explicit authorization.
