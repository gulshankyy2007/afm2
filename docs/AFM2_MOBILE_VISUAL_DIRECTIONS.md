# AFM#2 — Mobile Visual Direction Comparison & Theme Harmonization

**Document Classification:** Visual Design Exploration & Theme System Harmonization  
**Project:** AFM#2 — Annual Musical Finale 2026  
**Status:** Design Prototyping — *Production Implementation NOT GRANTED*  
**Branch:** `design/mobile-prototypes`  
**Date:** November 2, 2026  

---

## 1. Executive Summary & Visual Direction Philosophy

AFM#2 requires a visual identity that simultaneously honors its identity as a personal music sanctuary, an artwork-forward discovery engine, and a tournament platform.

Rather than implementing three disconnected visual styles, we explored three cohesive **Visual Directions** applied to the exact same underlying information architecture, interaction flows, and data models.

```
+---------------------------------------------------------------------------------------------------------+
|                                    VISUAL DIRECTIONS COMPARATIVE MATRIX                                 |
+---------------------+----------------------------+---------------------------+--------------------------+
| Attribute           | Direction A: Midnight Muse | Direction B: Soft Frequency| Direction C: Chromatic   |
+---------------------+----------------------------+---------------------------+--------------------------+
| Dominant Surface    | Deep Charcoal (#0c0b0f)    | Aged Parchment (#f5ede0)  | Obsidian Cyan (#06080d)  |
| Primary Accent      | Warm Amber (#f59e0b)       | Antique Amber (#8c3b06)   | Radiant Sky Blue (#38bdf8)|
| Secondary Accent    | Royal Violet (#a855f7)     | Deep Purple (#6b21a8)     | Acid Lime (#a3e635)      |
| Typographic Mood    | Atmospheric & Reverent     | Editorial & Literary      | Experimental & Dynamic   |
| Primary Header Font | Cormorant Garamond / Serif | Playfair Display / Serif  | Outfit / Geometric Sans  |
| Artwork Treatment   | Tactile Frame + Soft Vignette| White Mat Border + Drop  | Full-Bleed High Contrast |
| Recommended For     | Primary AFM#2 Mobile UI    | Daytime Reading Mode      | Festival & Finals Event  |
+---------------------+----------------------------+---------------------------+--------------------------+
```

---

## 2. Deep-Dive Exploration of the Three Directions

### Direction A — Midnight Muse (Recommended Foundation)
* **Emotional Tone:** Late-night listening session, warm amber tube-amplifier glow, cosmic reverence.
* **Palette:**
  * Background Main: `#0c0b0f` (Starry Charcoal)
  * Surface Glass: `rgba(20, 18, 24, 0.82)`
  * Card Background: `rgba(26, 23, 33, 0.85)`
  * Primary Accent: `#f59e0b` (Warm Amber Glow)
  * Secondary Accent: `#a855f7` (Electric Lavender / Violet)
  * Text Primary: `#f8fafc` (Glacial White, 15.8:1 contrast on `#0c0b0f`)
  * Text Muted: `#94a3b8` (Muted Slate, 5.2:1 contrast)
* **Typographic Hierarchy:**
  * Headers: Cormorant Garamond & Playfair Display
  * UI Labels & Buttons: Outfit & Plus Jakarta Sans
  * Numeric Data & Scores: JetBrains Mono
* **Why it Wins for AFM#2:** It seamlessly harmonizes with AFM#2’s default `midnight_ember` theme and provides the dark-mode luxury pioneered by TIDAL and Spotify. It reduces eye fatigue during long 50-song evaluation sessions.

---

### Direction B — Soft Frequency (Editorial Alternative)
* **Emotional Tone:** Physical vinyl liner notes, aged manuscript, literary music journalism (Bandcamp Daily).
* **Palette:**
  * Background Main: `#f5ede0` (Tactile Aged Parchment)
  * Surface Glass: `rgba(245, 237, 224, 0.85)`
  * Card Background: `rgba(255, 252, 246, 0.95)`
  * Primary Accent: `#8c3b06` (Antique Amber / Roasted Sienna)
  * Secondary Accent: `#6b21a8` (Deep Plum Ink)
  * Text Primary: `#190f07` (Deep Walnut Ink, 14.2:1 contrast)
  * Text Muted: `#786452` (Warm Earth, 4.6:1 contrast)
* **Evaluation:** An exceptional daytime reading experience. Directly aligns with the `vintage_paper`, `solar_terracotta`, and `quiet_minimal` themes.

---

### Direction C — Chromatic Sound (Experimental Alternative)
* **Emotional Tone:** Modern electronic festival, neon club flyer, dynamic sonic vibrancy.
* **Palette:**
  * Background Main: `#06080d` (Nocturnal Depth)
  * Surface Glass: `rgba(12, 16, 23, 0.85)`
  * Card Background: `rgba(18, 24, 38, 0.88)`
  * Primary Accent: `#38bdf8` (Neon Cyan Spark)
  * Secondary Accent: `#a3e635` (Acid Lime Highlight)
  * Text Primary: `#f0fdf4` (Crisp Mint White, 16.5:1 contrast)
  * Text Muted: `#64748b` (Steel Blue)
* **Evaluation:** High-energy and visually arresting. Best suited for high-stakes competition rounds like the Finals.

---

## 3. Seven-Theme vs. Eight-Theme Source Resolution

### The Investigation Finding
Historical documentation and previous planning briefs referred to AFM#2 having **"Seven existing themes"**. 

Direct source-code inspection of [`public/assets/css/style.css`](file:///mnt/data/GY/Music/AFM%232/public/assets/css/style.css#L69-L350) and [`includes/footer.php`](file:///mnt/data/GY/Music/AFM%232/includes/footer.php#L58-L216) reveals that there are **8 fully implemented atmospheric themes** in the production codebase:

```
+----+-------------------+-------------------------------+------------------------+
| #  | Theme Identifier  | User-Facing Mood Name         | Visual Classification  |
+----+-------------------+-------------------------------+------------------------+
| 01 | midnight_ember    | Midnight Ember (Default)      | Dark Warm Amber        |
| 02 | velvet_amethyst   | Velvet Amethyst               | Dark Cosmic Violet     |
| 03 | emerald_forest    | Emerald Forest                | Dark Botanical Pine    |
| 04 | vintage_paper     | Vintage Parchment             | Warm Antique Light     |
| 05 | nordic_frost      | Nordic Frost                  | Cool Glacial Light     |
| 06 | solar_terracotta  | Solar Terracotta              | Tuscan Sunset Light    |
| 07 | oceanic           | Oceanic Dusk                  | Dark Marine Horizon    |
| 08 | quiet_minimal     | Quiet Minimal                 | Warm Alabaster Studio  |
+----+-------------------+-------------------------------+------------------------+
```

### Resolution Strategy
The discrepancy arose because `oceanic` and `quiet_minimal` were introduced in later styling commits as curated refinements, while older documentation summaries remained at 7. 

**Architectural Decision:** The mobile UI prototypes strictly support **all 8 themes**. In the runnable prototype:
1. Default theme demonstrated: `midnight_ember`
2. Dark alternative demonstrated: `velvet_amethyst` / `oceanic`
3. Light alternative demonstrated: `vintage_paper` / `nordic_frost`

All reusable mobile CSS components bind exclusively to semantic tokens (`--bg-surface`, `--bg-card`, `--primary`, `--border-color`), guaranteeing 100% plug-and-play compatibility across all 8 themes without overriding CSS files.

---
*End of Visual Direction Document.*
