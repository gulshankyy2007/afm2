# AFM#2 — Mobile Design System & Token Specifications
**Project:** AFM#2 (Advanced Feature Matrix #2)  
**Task Classification:** Design System Documentation (Phase 0)  
**Status:** Approved for Prototyping  
**Author:** Antigravity AI Engineering & Design Team  
**Date:** November 2026  

---

## 1. Executive Overview & Core Philosophy

The AFM#2 Mobile Design System establishes a unified, tactile, and expressive component architecture specifically tailored for mobile smartphones (320px–430px viewport widths). 

AFM#2 is not an ordinary utility app or an enterprise CRUD dashboard; it is simultaneously:
1. **A Personal Music Vault & Discovery Sanctuary** (evoking Bandcamp & Pinterest).
2. **A Structured Audition & Scoring Engine** (evoking Notion's structured workflows).
3. **A Multi-Stage Competitive Tournament Platform** (with strict rules, manual qualification, and historical immutability).
4. **An Archival Hall of Fame** (evoking TIDAL's editorial typography and luxury gold accents).

The design system is engineered around **Semantic CSS Custom Properties**, ensuring seamless adaptability across **3 Visual Directions** and all **8 Production Themes** without touching a single line of backend PHP or modifying production assets.

---

## 2. Typography Hierarchy & Tokens

The AFM#2 typography system combines expressive editorial serif display typography with ultra-legible modern sans-serif interface text and precision monospaced data figures.

### 2.1 Typeface Families

| Variable | Font Family | Fallbacks | Intent & Usage |
|---|---|---|---|
| `--font-brand` | **Outfit** | system-ui, sans-serif | Primary brand headers, section titles, tournament milestone banners. Bold, geometric, modern. |
| `--font-main` | **Plus Jakarta Sans** | -apple-system, BlinkMacSystemFont, sans-serif | Body text, interactive buttons, form labels, compact metadata, touch pills. High x-height for mobile legibility. |
| `--font-serif` | **Playfair Display** | Georgia, serif | Editorial headlines, featured song cards, historical champion callouts. |
| `--font-poetic` | **Cormorant Garamond** | Times New Roman, serif | Direction A & B editorial poetry, lyrics drawer, quotes, and Hall of Fame titles. |
| `--font-mono` | **JetBrains Mono** | Menlo, Monaco, monospace | Entry IDs (`#101`), numeric scorecards (`47 / 50`), timestamps, quota badges (`100 / 100`). |
| `--font-script` | **Caveat** | cursive | Ambient personal annotations and hand-written style curation tags. |

### 2.2 Typographic Scale & Rhythm (Mobile Viewport)

All typographic scales are locked to a modular scale optimized for small screens:

```css
:root {
    --text-2xs: 0.65rem;  /* 10.4px — Micro tags, timestamps, song ID badges */
    --text-xs:  0.75rem;  /* 12.0px — Secondary labels, helper text, captions */
    --text-sm:  0.85rem;  /* 13.6px — Metadata, artist names, compact rows */
    --text-base: 0.95rem; /* 15.2px — Primary body text, button labels */
    --text-md:  1.05rem;  /* 16.8px — Card headings, dialog titles */
    --text-lg:  1.25rem;  /* 20.0px — Section titles, modal headers */
    --text-xl:  1.50rem;  /* 24.0px — Screen hero titles */
    --text-2xl: 1.85rem;  /* 29.6px — Big numbers, champion titles */
    --text-3xl: 2.25rem;  /* 36.0px — Hall of Fame scores (50/50) */
}
```

---

## 3. Spatial System & Touch Ergonomics

Mobile interaction requires strict adherence to physical touch target thresholds and thumb-reach zones.

### 3.1 4px / 8px Spacing Matrix

```css
:root {
    --space-0-5: 0.125rem; /* 2px */
    --space-1:   0.25rem;  /* 4px */
    --space-2:   0.50rem;  /* 8px */
    --space-3:   0.75rem;  /* 12px */
    --space-4:   1.00rem;  /* 16px — Standard mobile screen horizontal padding */
    --space-5:   1.25rem;  /* 20px */
    --space-6:   1.50rem;  /* 24px — Section spacing */
    --space-8:   2.00rem;  /* 32px — Major block division */
    --space-10:  2.50rem;  /* 40px */
}
```

### 3.2 Touch Target Ergonomics (Apple HIG & WCAG 2.1 AA)

- **Minimum Interactive Dimension:** Every interactive element (buttons, chip pills, navigation tabs, slider thumbs, action triggers) MUST have a bounding box of at least **`44px × 44px`**.
- **Visual vs. Hit Target:** Where a button appears visually compact (e.g. `btn-xs` or icon glyphs), pseudo-elements (`::after`) expand the hit area to 44px.
- **Thumb Zone Optimization:** Primary irreversible actions (Commit Stage, Save Scorecard, Wizard Advance) are pinned to the bottom 25% of the viewport.
- **Safe Area Insets:**
  ```css
  padding-top: max(var(--space-2), env(safe-area-inset-top));
  padding-bottom: max(var(--space-2), env(safe-area-inset-bottom));
  ```

### 3.3 Border Radii & Elevation Surfaces

```css
:root {
    --radius-xs: 4px;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --radius-xl: 20px;
    --radius-full: 9999px;

    /* Shadow Elevations */
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.2);
    --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.35);
    --shadow-lg: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
    --shadow-sheet: 0 -10px 30px rgba(0, 0, 0, 0.6);
}
```

---

## 4. Visual Direction Specifications

The prototype defines three distinct visual identities sharing identical structural semantics and markup.

```
+-------------------------------------------------------------------------------+
|                      AFM#2 VISUAL IDENTITY DIRECTIONS                         |
+-----------------------+-------------------------------+-----------------------+
| Direction A:          | Direction B:                  | Direction C:          |
| Midnight Muse         | Soft Frequency                | Chromatic Sound       |
| (Warm Amber / Luxury) | (Warm Editorial Parchment)    | (Electric Cyan/Acid)  |
| Deep Charcoal #0c0b0f | Warm Alabaster #fbf9f5        | Obsidian #0a0e14      |
| Amber #f59e0b         | Ink Blue #1e293b              | Cyan #06b6d4          |
| Lavender #a5b4fc      | Soft Lavender #6366f1         | Acid Lime #a3e635     |
+-----------------------+-------------------------------+-----------------------+
```

### 4.1 Direction A — Midnight Muse (Preferred Baseline)
- **Concept:** Intimate nighttime vinyl listening lounge. Deep charcoal matte backgrounds, glowing warm amber and gold highlights, subtle lavender metadata, serif titles.
- **Token Overrides:**
  ```css
  [data-visual-direction="midnight_muse"] {
      --bg-root: #0c0b0f;
      --bg-surface: #141218;
      --bg-surface-elevated: #1e1b24;
      --primary: #f59e0b;
      --primary-hover: #d97706;
      --primary-subtle: rgba(245, 158, 11, 0.12);
      --accent: #a5b4fc;
      --text-main: #f8fafc;
      --text-muted: #94a3b8;
      --border-subtle: rgba(255, 255, 255, 0.08);
      --border-focus: rgba(245, 158, 11, 0.5);
  }
  ```

### 4.2 Direction B — Soft Frequency
- **Concept:** High-end boutique music journal. Warm parchment off-white paper surfaces, deep slate typography, soft indigo accents, quiet editorial spaciousness.
- **Token Overrides:**
  ```css
  [data-visual-direction="soft_frequency"] {
      --bg-root: #fbf9f5;
      --bg-surface: #f1ede4;
      --bg-surface-elevated: #e8e2d5;
      --primary: #4338ca;
      --primary-hover: #3730a3;
      --primary-subtle: rgba(67, 56, 202, 0.08);
      --accent: #0284c7;
      --text-main: #1e293b;
      --text-muted: #64748b;
      --border-subtle: rgba(0, 0, 0, 0.08);
      --border-focus: rgba(67, 56, 202, 0.4);
  }
  ```

### 4.3 Direction C — Chromatic Sound
- **Concept:** Underground electronic tape lab. Ultra-dark obsidian foundation, hyper-vibrant electric cyan, laser acid lime, magenta punch accents, technical monospaced details.
- **Token Overrides:**
  ```css
  [data-visual-direction="chromatic_sound"] {
      --bg-root: #0a0e14;
      --bg-surface: #111822;
      --bg-surface-elevated: #182230;
      --primary: #06b6d4;
      --primary-hover: #0891b2;
      --primary-subtle: rgba(6, 182, 212, 0.15);
      --accent: #a3e635;
      --text-main: #f0fdfa;
      --text-muted: #94a3b8;
      --border-subtle: rgba(6, 182, 212, 0.2);
      --border-focus: rgba(6, 182, 212, 0.6);
  }
  ```

---

## 5. Multi-Theme Preservation Architecture (All 8 Themes)

The AFM#2 codebase defines **8 distinct production themes**. The mobile design system binds strictly to the existing semantic variable slots:

```
+-------------------------------------------------------------------------------+
|                       8 PRODUCTION THEME PRESETS                              |
+---+----------------------+-------------------+--------------------------------+
| # | Theme Identifier     | Tone              | Dominant Atmosphere            |
+---+----------------------+-------------------+--------------------------------+
| 1 | midnight_ember       | Dark (Default)    | Amber Gold on Obsidian Charcoal|
| 2 | velvet_amethyst      | Dark Alternative  | Cosmic Purple & Lavender Smoke |
| 3 | emerald_forest       | Dark Alternative  | Deep Botanical Jade & Mint     |
| 4 | vintage_paper        | Light Alternative | Antique Cream, Sepia & Ink     |
| 5 | nordic_frost         | Light Alternative | Clean Polar Ice, Slate & Blue  |
| 6 | solar_terracotta     | Warm Neutral      | Tuscan Clay, Burnt Orange & Sand|
| 7 | oceanic              | Dark Alternative  | Deep Sea Abyss & Aquamarine    |
| 8 | quiet_minimal        | Neutral Studio    | Monochrome Slate & Pure Smoke  |
+---+----------------------+-------------------+--------------------------------+
```

### 5.1 Zero-Hardcoding Enforcement Rule

Component styles **NEVER hardcode raw hex values**. Every background, border, glow, and font color uses semantic CSS variable references:
- Surface backgrounds: `var(--bg-surface)`, `var(--bg-surface-elevated)`
- Primary branding: `var(--primary)`, `var(--primary-subtle)`
- Text hierarchy: `var(--text-main)`, `var(--text-muted)`
- Accent callouts: `var(--accent)`, `var(--color-gold)`

When switching from `midnight_ember` to `vintage_paper`, the entire UI transforms immediately into a high-contrast warm paper journal without any style collisions.

---

## 6. Core Component Library Specifications

### 6.1 Header & Status Bar (`.mobile-app-header`)
- **Height:** 52px (excluding device status bar).
- **Anatomy:**
  - Left: Brand Monogram logo + `AFM#2` wordmark in Outfit 800 + animated 4-bar CSS sound equalizer.
  - Center: Monospaced tournament stage badge (`Top 200 ● Active`).
  - Right: Quick Search trigger (`🔍`) + Theme / More trigger (`🎨`).
- **Touch Targets:** 44px round icon buttons.

### 6.2 5-Destination Bottom Navigation (`.mobile-bottom-nav`)
- **Height:** 56px + safe-area-inset-bottom.
- **Destinations:**
  1. `Home` (Dashboard, Journal, Turntable, Shortlist)
  2. `Library` (Discover 2-col grid & Manage 52px list)
  3. `Auditions` (1-song scoring card with 5 criteria)
  4. `Bracket` (Tournament leaderboard & manual qualification)
  5. `More` (Trigger for modal bottom sheet)
- **Active State:** Glowing primary pill indicator with vertical subtle bounce.

### 6.3 Library Card Formats
- **Pinterest Discover Card (`.art-card`):**
  - Proportions: Responsive 2-column grid (`grid-template-columns: repeat(2, 1fr)`).
  - Artwork: 1:1 square ratio with subtle gradient overlay.
  - Floating Quick-Star button (`★`) on top-right corner (44px hit target).
  - Title in Outfit font, Artist in Plus Jakarta Sans muted.
  - Fallback: CSS procedural disc vinyl rendering when album artwork is missing.
- **SoundCloud Manage Row (`.track-manage-row`):**
  - Height: 52px compact row.
  - Left: Selection checkbox + 38px artwork thumbnail.
  - Center: Title, Artist, Song ID (`#101`), Status badge (`Shortlisted`).
  - Right: Numeric score badge (`50/50`) + 3-dot contextual action menu.

### 6.4 Audition Scoring Component (`.scoring-criterion-card`)
- **Criteria:** 5 canonical dimensions (Emotion, Replayability, Melody, Vocals, Lyrics).
- **Scale:** Discrete integer 0 to 10 (slider step = 1).
- **Controls:**
  - Tactile range input with 24px circular grab thumb (44px vertical touch padding).
  - Instant live numeric value pill with active glow.
  - Real-time sum calculation updating the `/50` master scorecard meter.

### 6.5 Qualification 3-Step Wizard Component
- **Step 1 (Select):** Quota meter (`100 / 100 Selected`), batch search, tap-to-select checkboxes.
- **Step 2 (Arrange):** Discrete touch reordering via `Move Up` (`▲`) and `Move Down` (`▼`) 44px buttons; swap contender tool.
- **Step 3 (Confirm):** Pre-flight validation checklist, audit summary, irreversible stage lock authorization warning, and explicit double-confirmation commit button.

### 6.6 Bottom Sheet Modal (`.modal-bottom-sheet`)
- **Placement:** Anchored to viewport bottom with 40% dark glass backdrop.
- **Handle:** 40px rounded top pill handle for visual affordance.
- **Grid:** 2-column or 3-column tactile icon grid with large tap zones.

---

## 7. Responsive Breakpoint Continuum (8 Continuous Transition Ranges)

The design system transitions seamlessly across eight continuous, non-overlapping layout ranges without dead zones or awkward intermediate wraps:

| Tier | Transition Range | Target Device Archetypes | Key Layout Behaviors & Intermediate Safety |
|---|---|---|---|
| **1** | **320px – 359px** | iPhone SE (1st gen), 340px | Single-col or tight 8px 2-col; 10px–12px padding; brand equalizer drops 2 bars; text wraps cleanly without overflow at 340px. |
| **2** | **360px – 389px** | Galaxy S/A, iPhone 8/SE2 (375px) | 2-Col Pinterest (10px gap); compact 48px track rows; 14px horizontal padding. |
| **3** | **390px – 429px** | **iPhone 13/14/15 Baseline**, Pixel 7 (412px) | Standard reference layout; 2-Col Pinterest (12px gap); 52px track rows; 16px screen padding; 44px min touch targets. |
| **4** | **430px – 599px** | iPhone Pro Max, Large Phablets (480px, 540px) | 2-Col Pinterest with expanded 16px gap; artwork cards retain 1:1 square ratio; proportional typography scaling. |
| **5** | **600px – 767px** | Small Tablets, Foldables (600px, 720px) | Hybrid 2-to-3 column grid; Auditions single-card max-width bounded at 540px centered; hybrid drawer/nav. |
| **6** | **768px – 1023px** | iPad Portrait, Tablet & Compact Laptop (834px, 960px) | Collapsible top drawer; 8-col data table with frozen ID/Title columns and horizontal scroll; 2x3 scorecards; dual touch/pointer support. |
| **7** | **1024px – 1439px** | Laptop, Standard Desktop (1280px, 1366px) | Full horizontal top navbar; sticky table headers (`top: var(--header-height)`); row hover actions; 25-card continuous sheet; bulk dock. |
| **8** | **1440px – 1920px+** | QHD, 4K, Ultrawide Displays (1600px, 1920px) | Max-width bounded at 1440px centered (`margin: 0 auto;`); Sticky Quick-Jumper Sidebar in Auditions margins; full tie-breaker breakdown. |

---

## 8. Performance Acceptance Framework & Governance

### 8.1 Performance Framework Structure
1. **Hard Architectural Constraints:**
   - Must execute within InfinityFree zero-cost PHP 8.x / MySQL 5.7+ environment with $\le 10\text{MB}$ memory ceiling.
   - ZERO new external CDN dependencies, external libraries, or JavaScript frameworks.
   - 100% preservation of authentication session guards, CSRF tokens, and database immutability.
2. **Recommended Optimization Targets (Maintainability-First):**
   - Maintainability first: Prune dead overrides and consolidate media queries without cryptic minification that damages readability.
   - Consolidated CSS wire transfer target ~220–240 KB unminified (~35–45 KB minified/gzipped).
   - Cold-start initial HTTP requests $\le 12$ requests.
   - Script parse and compile execution $\le 100\text{ms}$ on mid-tier mobile hardware.
3. **Measured Performance Acceptance Criteria (Field vs Lab):**
   - **Production Field Measurements (Primary Core Web Vitals at p75):**
     - **Interaction to Next Paint (INP):** **Primary responsiveness metric**. Target: **$\text{INP} \le 200\text{ms}$** at the 75th percentile across all user interactions.
     - **Largest Contentful Paint (LCP):** Target: **$\text{LCP} \le 2.5\text{s}$** at the 75th percentile.
     - **Cumulative Layout Shift (CLS):** Measured target **$\text{CLS} \le 0.1$** at the 75th percentile (strictly eliminating avoidable shifts via explicit `aspect-ratio: 1 / 1` and `16 / 9`).
     - **Legacy Diagnostic (FID):** First Input Delay ($\text{FID} \le 100\text{ms}$) retained solely for backward-looking diagnostic audits, not as a primary Core Web Vital.
   - **Local Laboratory Measurements (Lighthouse / DevTools under 4x CPU & 4G):**
     - **Total Blocking Time (TBT):** $\le 150\text{ms}$ (lab proxy for field INP).
     - **First Contentful Paint (FCP):** $\le 1.8\text{s}$.
     - **Speed Index (SI):** $\le 3.0\text{s}$.
     - **Container-Level Event Delegation:** Replace repeated individual element event listeners with a single parent listener on container nodes (`#songsTable`, `#scorecardsContainer`), using `event.target.closest()` to reduce DOM overhead and memory consumption.

### 8.2 Token Architecture & Tournament Integrity Governance
- **Canonical Card Token (`--bg-card`):** All shared component styling binds to `--bg-card` as defined in `style.css`. The `--card-bg` property is an alias defined in `:root` (`--card-bg: var(--bg-card)`).
- **WCAG 2.1 Contrast Compliance:** Verified across all 8 production themes with empirical measurements. Primary text achieves 13.62:1 to 19.90:1 (AAA) and secondary text achieves 10.44:1 to 15.65:1 (AAA) on `--bg-card`. The Oceanic primary button is adjusted to `--primary-btn-bg: #0f766e` with `#ffffff` text, achieving **5.47:1** (WCAG AA for normal text). Light theme accents (Nordic Frost `#0284c7`, Solar Terracotta `#ea580c`, and Vintage Paper `#b45309` on canvas) are strictly reserved for non-text UI components ($\ge 3.0:1$) and must never be used for normal-sized text. Overall rating is **WCAG 2.1 Level AA Compliant** across all 8 themes.
- **Stage Progression & Finals Transition Integrity:** Follows the exact 6 stage transitions. Top 10 (Stage 6) contains 10 available participants; the manual qualification wizard selects **1 Champion** (`target_count = 1`) to advance to Finals (Stage 7).
- **Zero Production Risk:** Completely isolated in `/prototype` and `/docs`; zero modifications to production code.


