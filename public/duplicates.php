<?php
/**
 * AFM#2 - Safe Duplicate Review, Side-by-Side Comparison & Catalogue Integrity
 * 
 * Core Principle: Detect automatically. Decide manually. Preserve history.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/duplicate_helper.php';

require_auth();

// The duplicates section has been removed entirely - redirect to library.php
header('Location: library.php');
exit;

$query        = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? 'unresolved';
$catFilter    = $_GET['category'] ?? 'all';
$confFilter   = $_GET['confidence'] ?? 'all';
$page         = max(1, (int)($_GET['page'] ?? 1));

// Records per page: 20 (default), 25, 50, 75, 100 (at most 100 as requested)
$rawPerPage   = (int)($_GET['per_page'] ?? 20);
$perPage      = in_array($rawPerPage, [20, 25, 50, 75, 100], true) ? $rawPerPage : max(10, min(100, $rawPerPage > 0 ? $rawPerPage : 20));

// Execute read-only candidate detection
$scanResult = detect_duplicate_candidates([
    'search'     => $query,
    'status'     => $statusFilter,
    'category'   => $catFilter,
    'confidence' => $confFilter,
    'page'       => $page,
    'per_page'   => $perPage
]);

$total      = $scanResult['total'];
$totalPages = $scanResult['total_pages'];

// Snap if page exceeds totalPages due to per_page change or filter
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
    $scanResult = detect_duplicate_candidates([
        'search'     => $query,
        'status'     => $statusFilter,
        'category'   => $catFilter,
        'confidence' => $confFilter,
        'page'       => $page,
        'per_page'   => $perPage
    ]);
}

$candidates = $scanResult['candidates'];
$stats      = $scanResult['stats'];

$pageTitle = 'Duplicate Review & Catalogue Integrity — AFM#2';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header duplicates-header">
    <div class="page-title-group">
        <div class="header-badge-row">
            <span class="badge-tag badge-gold">Catalogue Integrity</span>
            <span class="badge-tag badge-dark"><?= number_format($stats['total_catalogue'] ?? 2461) ?> Songs Preserved</span>
        </div>
        <h1>Safe Duplicate Review &amp; Consolidation</h1>
        <p class="page-subtitle">
            <strong>Detect automatically. Decide manually. Preserve history.</strong> 
            Compare candidate recordings side-by-side, keep distinct versions, exclude unwanted songs, and safely consolidate confirmed duplicates without losing audition scores or tournament history.
        </p>
    </div>
    <div class="header-actions">
        <button type="button" class="btn btn-secondary" id="btnOpenAuditTrail" title="View durable audit log of consolidated records and perform 1-click recovery">
            📜 Audit &amp; Recovery Trail
        </button>
        <a href="library.php" class="btn btn-secondary">
            &larr; Back to Library
        </a>
    </div>
</div>

<!-- Duplicate Review Stats Grid -->
<div class="dup-stats-grid" id="dupStatsGrid">
    <div class="dup-stat-card total">
        <span class="dup-stat-label">Candidate Groups</span>
        <span class="dup-stat-val" id="statTotalCandidates"><?= number_format($stats['total_candidates']) ?></span>
        <span class="dup-stat-sub">Algorithmic scan results</span>
    </div>
    <div class="dup-stat-card awaiting">
        <span class="dup-stat-label">Awaiting Review</span>
        <span class="dup-stat-val" id="statAwaitingReview"><?= number_format($stats['awaiting_review']) ?></span>
        <span class="dup-stat-sub">Pending manual decision</span>
    </div>
    <div class="dup-stat-card resolved">
        <span class="dup-stat-label">Resolved Decisions</span>
        <span class="dup-stat-val" id="statResolved"><?= number_format($stats['resolved_count']) ?></span>
        <span class="dup-stat-sub">
            <?= $stats['keep_both_count'] ?> Kept &bull; 
            <?= $stats['excluded_count'] ?> Excluded &bull; 
            <?= $stats['consolidated_count'] ?> Consolidated
        </span>
    </div>
    <div class="dup-stat-card deferred">
        <span class="dup-stat-label">Deferred</span>
        <span class="dup-stat-val" id="statDeferred"><?= number_format($stats['deferred_count']) ?></span>
        <span class="dup-stat-sub">Saved for later review</span>
    </div>
</div>

<!-- Search & Filtering Bar -->
<div class="dup-controls-card">
    <form method="GET" action="duplicates.php" class="dup-filter-form" id="dupFilterForm">
        <div class="search-input-wrapper dup-search">
            <span class="search-icon">🔍</span>
            <input 
                type="text" 
                name="q" 
                class="search-input" 
                placeholder="Search candidate titles, artists, or #IDs..." 
                value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>"
                id="dupSearchInput"
            >
            <?php if ($query !== ''): ?>
                <a href="duplicates.php?status=<?= urlencode($statusFilter) ?>&category=<?= urlencode($catFilter) ?>&confidence=<?= urlencode($confFilter) ?>" class="search-clear-btn" title="Clear search">&times;</a>
            <?php endif; ?>
        </div>

        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>" id="dupStatusInput">

        <!-- Status Filter Tabs -->
        <div class="dup-status-pills">
            <a href="duplicates.php?q=<?= urlencode($query) ?>&status=unresolved&category=<?= urlencode($catFilter) ?>&confidence=<?= urlencode($confFilter) ?>" 
               class="filter-pill <?= $statusFilter === 'unresolved' ? 'active' : '' ?>">
                Awaiting Review (<?= number_format($stats['awaiting_review']) ?>)
            </a>
            <a href="duplicates.php?q=<?= urlencode($query) ?>&status=all&category=<?= urlencode($catFilter) ?>&confidence=<?= urlencode($confFilter) ?>" 
               class="filter-pill <?= $statusFilter === 'all' ? 'active' : '' ?>">
                All (<?= number_format($stats['total_candidates']) ?>)
            </a>
            <a href="duplicates.php?q=<?= urlencode($query) ?>&status=deferred&category=<?= urlencode($catFilter) ?>&confidence=<?= urlencode($confFilter) ?>" 
               class="filter-pill <?= $statusFilter === 'deferred' ? 'active' : '' ?>">
                Deferred (<?= number_format($stats['deferred_count']) ?>)
            </a>
            <a href="duplicates.php?q=<?= urlencode($query) ?>&status=resolved&category=<?= urlencode($catFilter) ?>&confidence=<?= urlencode($confFilter) ?>" 
               class="filter-pill <?= $statusFilter === 'resolved' ? 'active' : '' ?>">
                Resolved (<?= number_format($stats['resolved_count']) ?>)
            </a>
        </div>

        <!-- Category & Confidence Dropdowns -->
        <div class="dup-dropdown-group">
            <div class="select-wrapper">
                <label for="categorySelect" class="sr-only">Match Category</label>
                <select name="category" id="categorySelect" class="form-select-sm" onchange="this.form.submit()">
                    <option value="all" <?= $catFilter === 'all' ? 'selected' : '' ?>>All Categories</option>
                    <option value="formatting_difference" <?= $catFilter === 'formatting_difference' ? 'selected' : '' ?>>Formatting / Punctuation (<?= $stats['categories']['formatting_difference'] ?? 0 ?>)</option>
                    <option value="alternate_version" <?= $catFilter === 'alternate_version' ? 'selected' : '' ?>>Alternate Versions (<?= $stats['categories']['alternate_version'] ?? 0 ?>)</option>
                    <option value="same_title_uncertain_artist" <?= $catFilter === 'same_title_uncertain_artist' ? 'selected' : '' ?>>Uncertain / Missing Artist (<?= $stats['categories']['same_title_uncertain_artist'] ?? 0 ?>)</option>
                    <option value="same_title_diff_artist" <?= $catFilter === 'same_title_diff_artist' ? 'selected' : '' ?>>Same Title, Diff Artist (<?= $stats['categories']['same_title_diff_artist'] ?? 0 ?>)</option>
                    <option value="exact_normalized" <?= $catFilter === 'exact_normalized' ? 'selected' : '' ?>>Exact Matches (<?= $stats['categories']['exact_normalized'] ?? 0 ?>)</option>
                    <option value="shared_youtube_id" <?= $catFilter === 'shared_youtube_id' ? 'selected' : '' ?>>Shared YouTube Video (<?= $stats['categories']['shared_youtube_id'] ?? 0 ?>)</option>
                </select>
            </div>

            <div class="select-wrapper">
                <label for="confidenceSelect" class="sr-only">Confidence Level</label>
                <select name="confidence" id="confidenceSelect" class="form-select-sm" onchange="this.form.submit()">
                    <option value="all" <?= $confFilter === 'all' ? 'selected' : '' ?>>All Confidences</option>
                    <option value="high" <?= $confFilter === 'high' ? 'selected' : '' ?>>High Confidence (<?= $stats['confidences']['high'] ?? 0 ?>)</option>
                    <option value="medium" <?= $confFilter === 'medium' ? 'selected' : '' ?>>Medium Confidence (<?= $stats['confidences']['medium'] ?? 0 ?>)</option>
                    <option value="low" <?= $confFilter === 'low' ? 'selected' : '' ?>>Low Confidence (<?= $stats['confidences']['low'] ?? 0 ?>)</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($query !== '' || $statusFilter !== 'unresolved' || $catFilter !== 'all' || $confFilter !== 'all'): ?>
                <a href="duplicates.php" class="btn btn-secondary btn-sm" title="Reset all filters">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Candidate Pairs Listing -->
<?php if (empty($candidates)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">✨</div>
        <h3>No Duplicate Candidates Found</h3>
        <p>No candidates match the active filters. All duplicate records in this view are either resolved or clear.</p>
        <a href="duplicates.php" class="btn btn-secondary btn-sm">View Awaiting Review</a>
    </div>
<?php else: ?>
    <div class="dup-list-header">
        <span class="dup-results-count">Showing <strong><?= number_format(count($candidates)) ?></strong> of <strong><?= number_format($total) ?></strong> candidate pairs</span>
        <span class="dup-safety-notice">🔒 All scans are strictly read-only. Database records are only modified upon explicit decision confirmation.</span>
    </div>

    <div class="dup-pairs-container" id="dupPairsContainer">
        <?php foreach ($candidates as $cand): ?>
            <?php 
                $songA = $cand['song_a'];
                $songB = $cand['song_b'];
                $confClass = 'conf-' . htmlspecialchars($cand['confidence'], ENT_QUOTES, 'UTF-8');
                $matchLabel = match($cand['match_type']) {
                    'exact_normalized'            => 'Exact Normalized',
                    'formatting_difference'       => 'Formatting / Punctuation',
                    'alternate_version'           => 'Alternate Version / Recording',
                    'same_title_uncertain_artist' => 'Uncertain / Missing Artist',
                    'same_title_diff_artist'      => 'Same Title, Different Artist',
                    'shared_youtube_id'           => 'Shared YouTube Reference',
                    default                       => ucfirst(str_replace('_', ' ', $cand['match_type']))
                };
            ?>
            <div class="dup-pair-card <?= $cand['status'] ?>" 
                 id="pairCard_<?= $cand['pair_id'] ?>"
                 data-pair-id="<?= $cand['pair_id'] ?>"
                 data-song-a="<?= $songA['song_id'] ?>"
                 data-song-b="<?= $songB['song_id'] ?>"
                 data-status="<?= $cand['status'] ?>"
                 data-confidence="<?= $cand['confidence'] ?>"
                 data-category="<?= $cand['match_type'] ?>">
                
                <!-- Pair Top Meta Bar -->
                <div class="pair-meta-bar">
                    <div class="pair-badges">
                        <span class="match-category-badge cat-<?= $cand['match_type'] ?>">
                            <?= htmlspecialchars($matchLabel, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <span class="confidence-pill <?= $confClass ?>">
                            <?= ucfirst($cand['confidence']) ?> Confidence
                        </span>
                        <?php if ($cand['status'] === 'resolved'): ?>
                            <span class="status-badge status-resolved">
                                Resolved: <?= ucfirst(str_replace('_', ' ', $cand['decision'])) ?>
                            </span>
                        <?php elseif ($cand['status'] === 'deferred'): ?>
                            <span class="status-badge status-deferred">Deferred</span>
                        <?php else: ?>
                            <span class="status-badge status-awaiting">Awaiting Review</span>
                        <?php endif; ?>
                    </div>
                    <div class="pair-id-tag">Pair #<?= htmlspecialchars($cand['pair_id'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>

                <!-- Match Reason Inferred Explanation -->
                <div class="pair-reason-banner">
                    <span class="reason-icon">💡</span>
                    <span class="reason-text"><?= htmlspecialchars($cand['reason'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <!-- Side-by-Side Songs Quick Cards -->
                <div class="pair-songs-grid">
                    <!-- Song A -->
                    <div class="pair-song-box <?= $songA['is_consolidated'] ? 'is-consolidated' : '' ?>">
                        <div class="song-box-header">
                            <span class="song-num">#<?= $songA['song_id'] ?></span>
                            <?php if ($songA['is_consolidated']): ?>
                                <span class="badge-tag badge-gold">Consolidated</span>
                            <?php endif; ?>
                        </div>
                        <h3 class="song-title" title="<?= htmlspecialchars($songA['title'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($songA['title'], ENT_QUOTES, 'UTF-8') ?>
                        </h3>
                        <div class="song-artist <?= $songA['is_missing_artist'] ? 'artist-missing' : '' ?>">
                            <?= $songA['is_missing_artist'] ? '⚠️ Missing Artist (NULL)' : htmlspecialchars($songA['artist'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="song-quick-tags">
                            <?php if (!empty($songA['video_id'])): ?>
                                <span class="quick-tag yt-tag" title="YouTube video attached">▶ Stored Video</span>
                            <?php endif; ?>
                            <?php if (!empty($songA['version'])): ?>
                                <span class="quick-tag ver-tag"><?= htmlspecialchars($songA['version'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Comparison Divider -->
                    <div class="pair-vs-badge" aria-hidden="true">
                        <span>VS</span>
                    </div>

                    <!-- Song B -->
                    <div class="pair-song-box <?= $songB['is_consolidated'] ? 'is-consolidated' : '' ?>">
                        <div class="song-box-header">
                            <span class="song-num">#<?= $songB['song_id'] ?></span>
                            <?php if ($songB['is_consolidated']): ?>
                                <span class="badge-tag badge-gold">Consolidated</span>
                            <?php endif; ?>
                        </div>
                        <h3 class="song-title" title="<?= htmlspecialchars($songB['title'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($songB['title'], ENT_QUOTES, 'UTF-8') ?>
                        </h3>
                        <div class="song-artist <?= $songB['is_missing_artist'] ? 'artist-missing' : '' ?>">
                            <?= $songB['is_missing_artist'] ? '⚠️ Missing Artist (NULL)' : htmlspecialchars($songB['artist'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="song-quick-tags">
                            <?php if (!empty($songB['video_id'])): ?>
                                <span class="quick-tag yt-tag" title="YouTube video attached">▶ Stored Video</span>
                            <?php endif; ?>
                            <?php if (!empty($songB['version'])): ?>
                                <span class="quick-tag ver-tag"><?= htmlspecialchars($songB['version'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Pair Bottom Action Bar -->
                <div class="pair-action-bar">
                    <div class="action-hints">
                        <small class="text-muted">Compare metadata, scores, and tournament history before deciding.</small>
                    </div>
                    <div class="action-buttons">
                        <button type="button" 
                                class="btn btn-secondary btn-sm btn-quick-keep" 
                                onclick="handleQuickDecision(<?= $songA['song_id'] ?>, <?= $songB['song_id'] ?>, 'keep_both', '<?= $cand['pair_id'] ?>')"
                                title="Preserve both songs and all history">
                            Keep Both
                        </button>
                        <button type="button" 
                                class="btn btn-secondary btn-sm btn-quick-defer" 
                                onclick="handleQuickDecision(<?= $songA['song_id'] ?>, <?= $songB['song_id'] ?>, 'deferred', '<?= $cand['pair_id'] ?>')"
                                title="Mark as deferred and return later">
                            Defer
                        </button>
                        <button type="button" 
                                class="btn btn-primary btn-sm btn-compare-open" 
                                onclick="openComparisonModal(<?= $songA['song_id'] ?>, <?= $songB['song_id'] ?>)">
                            🔍 Compare &amp; Decide &rarr;
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?= render_accessible_pagination(
        $page, 
        $totalPages, 
        $total, 
        $perPage, 
        'duplicates.php', 
        [
            'q'          => $query,
            'status'     => $statusFilter,
            'category'   => $catFilter,
            'confidence' => $confFilter
        ], 
        'candidate pairs',
        10
    ); ?>
<?php endif; ?>

<!-- Comprehensive Side-by-Side Comparison & Decision Modal -->
<div class="modal-overlay" id="compareModalOverlay" aria-hidden="true">
    <div class="modal-content dup-modal-content" role="dialog" aria-labelledby="compareModalTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="compareModalTitle">Side-by-Side Duplicate Comparison</h2>
                <p class="modal-subtitle">Review confirmed database facts vs. inferred matching evidence before making a decision.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeComparisonModal()" aria-label="Close modal">&times;</button>
        </div>

        <div class="modal-body" id="compareModalBody">
            <div class="modal-loading-state" id="compareModalLoading">
                <div class="spinner"></div>
                <p>Loading confirmed records, audition scores, and tournament history...</p>
            </div>

            <!-- Dynamic Comparison Content Loaded via JS -->
            <div id="compareDynamicContent" style="display: none;"></div>
        </div>
    </div>
</div>

<!-- Consolidation Audit & Recovery Trail Modal -->
<div class="modal-overlay" id="auditModalOverlay" aria-hidden="true">
    <div class="modal-content audit-modal-content" role="dialog" aria-labelledby="auditModalTitle">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="auditModalTitle">Durable Consolidation Audit &amp; Recovery Trail</h2>
                <p class="modal-subtitle">Every consolidation operation creates a durable JSON snapshot supporting verified recovery.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeAuditModal()" aria-label="Close modal">&times;</button>
        </div>

        <div class="modal-body" id="auditModalBody">
            <div class="modal-loading-state" id="auditModalLoading">
                <div class="spinner"></div>
                <p>Loading audit trail...</p>
            </div>
            <div id="auditDynamicContent" style="display: none;"></div>
        </div>
    </div>
</div>

<script>
/**
 * AFM#2 Duplicate Review Client Controller
 */
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

// Toast notification helper
function showToast(message, type = 'success') {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<span class="toast-icon">${type === 'success' ? '✔' : '⚠️'}</span><span>${message}</span>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.classList.add('fade-out');
        setTimeout(() => toast.remove(), 400);
    }, 4000);
}

// Quick decision handler (Keep Both or Defer from the listing)
async function handleQuickDecision(songIdA, songIdB, decision, pairId) {
    try {
        const res = await fetch('api.php?action=save_duplicate_decision', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify({
                song_id_a: songIdA,
                song_id_b: songIdB,
                decision: decision
            })
        });

        const data = await res.json();
        if (data.success) {
            showToast(data.message, 'success');
            // Update card visually
            const card = document.getElementById(`pairCard_${pairId}`);
            if (card) {
                card.className = `dup-pair-card ${decision === 'deferred' ? 'deferred' : 'resolved'}`;
                const badges = card.querySelector('.pair-badges');
                if (badges) {
                    const statusBadge = badges.querySelector('.status-badge');
                    if (statusBadge) {
                        statusBadge.className = `status-badge ${decision === 'deferred' ? 'status-deferred' : 'status-resolved'}`;
                        statusBadge.textContent = decision === 'deferred' ? 'Deferred' : 'Resolved: Keep Both';
                    }
                }
            }
        } else {
            showToast(data.error || 'Failed to save decision.', 'error');
        }
    } catch (err) {
        showToast('Network error while saving decision.', 'error');
    }
}

// Open Side-by-Side Comparison Modal
async function openComparisonModal(songIdA, songIdB) {
    const overlay = document.getElementById('compareModalOverlay');
    const loading = document.getElementById('compareModalLoading');
    const content = document.getElementById('compareDynamicContent');

    overlay.classList.add('active');
    overlay.setAttribute('aria-hidden', 'false');
    loading.style.display = 'block';
    content.style.display = 'none';
    content.innerHTML = '';

    try {
        const res = await fetch(`api.php?action=get_duplicate_comparison&song_id_a=${songIdA}&song_id_b=${songIdB}`);
        const json = await res.json();

        if (!json.success || !json.data) {
            loading.style.display = 'none';
            content.style.display = 'block';
            content.innerHTML = `<div class="alert alert-danger">${json.error || 'Failed to load comparison records.'}</div>`;
            return;
        }

        renderComparisonModal(json.data);
    } catch (err) {
        loading.style.display = 'none';
        content.style.display = 'block';
        content.innerHTML = `<div class="alert alert-danger">An unexpected network error occurred.</div>`;
    }
}

function closeComparisonModal() {
    const overlay = document.getElementById('compareModalOverlay');
    overlay.classList.remove('active');
    overlay.setAttribute('aria-hidden', 'true');
}

// Render dynamic comparison payload inside modal
function renderComparisonModal(data) {
    const loading = document.getElementById('compareModalLoading');
    const content = document.getElementById('compareDynamicContent');

    const songA = data.song_a;
    const songB = data.song_b;
    const matchType = data.match_type;
    const confidence = data.confidence;
    const reasons = data.reasons || [];
    const safetyA = data.safety_a_as_canonical;
    const safetyB = data.safety_b_as_canonical;

    // Check if consolidation is safe in either direction
    const isSafe = (safetyA.safe || safetyB.safe);

    function formatScores(ratings) {
        if (!ratings || ratings.length === 0) return '<em class="text-muted">No audition ratings</em>';
        return ratings.map(r => `
            <div class="score-line">
                <span class="round-badge">${escapeHtml(r.round_name)}:</span>
                <strong>${r.total !== null ? r.total + '/50' : 'Incomplete'}</strong>
                <span class="sub-criteria">
                    (M:${r.melody ?? '-'}, L:${r.lyrics ?? '-'}, V:${r.vocals ?? '-'}, E:${r.emotion ?? '-'}, R:${r.replayability ?? '-'})
                </span>
            </div>
        `).join('');
    }

    function formatResults(results) {
        if (!results || results.length === 0) return '<em class="text-muted">No tournament results</em>';
        return results.map(res => `
            <div class="result-line">
                <span class="round-badge">${escapeHtml(res.round_name)}:</span>
                <span class="result-tag result-${escapeHtml(res.result)}">${escapeHtml(res.result).toUpperCase()}</span>
                ${res.rank_position ? `<span>Rank #${res.rank_position}</span>` : ''}
                ${res.reason ? `<small class="text-muted">(${escapeHtml(res.reason)})</small>` : ''}
            </div>
        `).join('');
    }

    function renderSongCol(sData, colLabel, otherId) {
        const s = sData.song;
        const m = sData.media;
        const l = sData.lyrics;
        const art = sData.artwork;
        const e = sData.entry;
        const r = sData.ratings;
        const res = sData.results;
        const isMissingArt = (s.raw_artist === null || s.raw_artist.trim() === '');

        return `
            <div class="compare-col" id="compareCol_${s.song_id}">
                <div class="compare-col-header">
                    <span class="col-pill">${colLabel}</span>
                    <span class="col-song-id">Song #${s.song_id}</span>
                    ${s.is_consolidated ? '<span class="badge-tag badge-gold">Consolidated</span>' : ''}
                </div>

                <div class="compare-section metadata-section">
                    <h3 class="col-song-title">${escapeHtml(s.title)}</h3>
                    <div class="col-song-artist ${isMissingArt ? 'artist-missing' : ''}">
                        ${isMissingArt ? '⚠️ Missing Artist (NULL in Database)' : escapeHtml(s.artist)}
                    </div>
                    <div class="col-links-row">
                        <a href="library.php?q=${encodeURIComponent(s.title)}" target="_blank" class="text-link">Inspect in Library &nearr;</a>
                        ${e ? `<a href="auditions.php" target="_blank" class="text-link">View in Auditions &nearr;</a>` : ''}
                    </div>
                </div>

                <!-- Media Details -->
                <div class="compare-section">
                    <div class="section-title">Stored Audio / YouTube Recording</div>
                    ${m ? `
                        <div class="media-preview-box">
                            <div class="media-title"><strong>${escapeHtml(m.title || 'YouTube Track')}</strong></div>
                            <div class="media-channel text-muted">${escapeHtml(m.channel_title || 'Official Channel')} &bull; Type: <em>${escapeHtml(m.version_type)}</em></div>
                            <div class="media-links">
                                <a href="https://www.youtube.com/watch?v=${escapeHtml(m.video_id)}" target="_blank" class="btn btn-secondary btn-xs">▶ Watch on YouTube</a>
                                <span class="badge-tag ${m.embed_status === 'allowed' ? 'badge-dark' : 'badge-gold'}">${escapeHtml(m.embed_status)}</span>
                            </div>
                        </div>
                    ` : '<p class="text-muted">No YouTube recording stored.</p>'}
                </div>

                <!-- Lyrics & Album -->
                <div class="compare-section">
                    <div class="section-title">Lyrics &amp; Album Reference</div>
                    ${l ? `
                        <div class="lyrics-preview-box">
                            <div><strong>${escapeHtml(l.track_name || s.title)}</strong></div>
                            <div class="text-muted">Album: ${escapeHtml(l.album_name || 'Single / Unknown')}</div>
                            <div>Synced Lyrics: ${l.has_synced ? '✔ Available' : 'Plain Text Only'} &bull; Duration: ${l.duration ? l.duration + 's' : 'N/A'}</div>
                        </div>
                    ` : '<p class="text-muted">No external lyrics record cached.</p>'}
                </div>

                <!-- Competition Status & History -->
                <div class="compare-section history-section">
                    <div class="section-title">AFM Competition Entry &amp; History</div>
                    <div class="entry-status-row">
                        Status: <strong>${e ? escapeHtml(e.status) : 'Not in auditions'}</strong>
                    </div>

                    <div class="ratings-block">
                        <div class="sub-label">Audition Scorecard:</div>
                        ${formatScores(r)}
                    </div>

                    <div class="results-block">
                        <div class="sub-label">Tournament Milestones:</div>
                        ${formatResults(res)}
                    </div>
                </div>
            </div>
        `;
    }

    let safetyHtml = '';
    if (!isSafe) {
        const allConflicts = [...(safetyA.conflicts || []), ...(safetyB.conflicts || [])];
        const uniqueConflicts = [...new Set(allConflicts)];
        safetyHtml = `
            <div class="conflict-alert-box">
                <div class="conflict-alert-header">
                    <span class="conflict-icon">⛔</span>
                    <strong>Consolidation Blocked by Competition Safeguards</strong>
                </div>
                <p>Under AFM#2 integrity rules, historical competition scorecards and tournament rankings cannot be merged, averaged, or overwritten:</p>
                <ul class="conflict-list">
                    ${uniqueConflicts.map(c => `<li>${escapeHtml(c)}</li>`).join('')}
                </ul>
                <div class="conflict-guide">
                    <strong>Recommended Safe Actions:</strong>
                    Choose <strong>Keep Both</strong> to preserve both distinct recordings in the catalogue, or <strong>Exclude from Competition</strong> to keep the song record intact while withdrawing it from future tournament stages.
                </div>
            </div>
        `;
    } else {
        const preferredCanon = safetyA.safe ? songA.song.song_id : songB.song.song_id;
        const activeSafety = safetyA.safe ? safetyA : safetyB;

        safetyHtml = `
            <div class="safe-consolidation-box">
                <div class="safe-header">
                    <span class="safe-icon">✅</span>
                    <strong>Pre-Flight Safety Check Passed — Lossless Consolidation Possible</strong>
                </div>
                <p>No conflicting audition scores or completed tournament results detected. Select which record to retain as the canonical catalogue entry:</p>

                <div class="canonical-selector-group">
                    <label class="canonical-radio-card">
                        <input type="radio" name="canonical_choice" value="${songA.song.song_id}" ${preferredCanon === songA.song.song_id ? 'checked' : ''} onchange="updateProposedActions(${songA.song.song_id}, ${songB.song.song_id})">
                        <div class="radio-card-content">
                            <strong>Keep #${songA.song.song_id}: ${escapeHtml(songA.song.title)}</strong>
                            <small class="text-muted">Artist: ${escapeHtml(songA.song.artist)} &bull; Canonical Record</small>
                        </div>
                    </label>

                    <label class="canonical-radio-card">
                        <input type="radio" name="canonical_choice" value="${songB.song.song_id}" ${preferredCanon === songB.song.song_id ? 'checked' : ''} onchange="updateProposedActions(${songB.song.song_id}, ${songA.song.song_id})">
                        <div class="radio-card-content">
                            <strong>Keep #${songB.song.song_id}: ${escapeHtml(songB.song.title)}</strong>
                            <small class="text-muted">Artist: ${escapeHtml(songB.song.artist)} &bull; Canonical Record</small>
                        </div>
                    </label>
                </div>

                <div class="proposed-actions-container">
                    <strong>Proposed Changes:</strong>
                    <ul class="proposed-actions-list" id="proposedActionsList">
                        ${(activeSafety.proposed_actions || []).map(a => `<li>${escapeHtml(a)}</li>`).join('')}
                    </ul>
                </div>
            </div>
        `;
    }

    content.innerHTML = `
        <!-- Match Analysis Header -->
        <div class="modal-match-summary">
            <div class="summary-tags">
                <span class="match-category-badge cat-${matchType}">${escapeHtml(matchType.replace('_', ' '))}</span>
                <span class="confidence-pill conf-${confidence}">${escapeHtml(confidence)} Confidence</span>
            </div>
            <div class="summary-reasons">
                <strong>Inferred Matching Evidence:</strong>
                ${reasons.map(r => `<span>&bull; ${escapeHtml(r)}</span>`).join(' ')}
            </div>
        </div>

        <!-- Two Column Comparison -->
        <div class="compare-two-cols">
            ${renderSongCol(songA, 'Song Record A', songB.song.song_id)}
            ${renderSongCol(songB, 'Song Record B', songA.song.song_id)}
        </div>

        <!-- Safeguard Assessment Box -->
        ${safetyHtml}

        <!-- Decision Action Bar -->
        <div class="modal-decision-bar">
            <div class="decision-left">
                <button type="button" class="btn btn-secondary" onclick="closeComparisonModal()">Cancel</button>
            </div>
            <div class="decision-right">
                <button type="button" class="btn btn-secondary" onclick="submitModalDecision(${songA.song.song_id}, ${songB.song.song_id}, 'deferred')">
                    Defer
                </button>
                <button type="button" class="btn btn-secondary" onclick="openExcludePicker(${songA.song.song_id}, ${songB.song.song_id})">
                    Exclude from Competition
                </button>
                <button type="button" class="btn btn-secondary" onclick="submitModalDecision(${songA.song.song_id}, ${songB.song.song_id}, 'keep_both')">
                    Keep Both
                </button>
                <button type="button" 
                        class="btn btn-primary btn-consolidate" 
                        id="btnModalConsolidate"
                        ${!isSafe ? 'disabled title="Blocked by competition safeguards"' : ''}
                        onclick="submitModalConsolidation(${songA.song.song_id}, ${songB.song.song_id})">
                    Consolidate Duplicates
                </button>
            </div>
        </div>
    `;

    loading.style.display = 'none';
    content.style.display = 'block';
}

// Update proposed actions when user changes radio selection
function updateProposedActions(canonicalId, duplicateId) {
    // Dynamically toggle proposed actions
    const list = document.getElementById('proposedActionsList');
    if (list) {
        list.innerHTML = `
            <li>Transfer any missing media/lyrics from song #${duplicateId} to canonical song #${canonicalId}.</li>
            <li>Soft-consolidate song #${duplicateId} into canonical song #${canonicalId}.</li>
            <li>Preserve all catalogue records and create full durable audit snapshot.</li>
        `;
    }
}

// Submit Keep Both or Defer from Modal
async function submitModalDecision(songIdA, songIdB, decision) {
    try {
        const res = await fetch('api.php?action=save_duplicate_decision', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify({
                song_id_a: songIdA,
                song_id_b: songIdB,
                decision: decision
            })
        });

        const data = await res.json();
        if (data.success) {
            showToast(data.message, 'success');
            closeComparisonModal();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(data.error || 'Failed to save decision.', 'error');
        }
    } catch (err) {
        showToast('Network error while saving decision.', 'error');
    }
}

// Open Exclude from Competition Picker
function openExcludePicker(songIdA, songIdB) {
    const choice = prompt(`Which song should be excluded from future competition?\n\nEnter 1 for Song #${songIdA}\nEnter 2 for Song #${songIdB}`);
    if (choice === '1') {
        submitExcludeDecision(songIdA, songIdB, songIdA);
    } else if (choice === '2') {
        submitExcludeDecision(songIdA, songIdB, songIdB);
    }
}

async function submitExcludeDecision(songIdA, songIdB, excludeId) {
    try {
        const res = await fetch('api.php?action=save_duplicate_decision', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify({
                song_id_a: songIdA,
                song_id_b: songIdB,
                decision: 'excluded',
                exclude_song_id: excludeId
            })
        });

        const data = await res.json();
        if (data.success) {
            showToast(data.message, 'success');
            closeComparisonModal();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(data.error || 'Failed to record exclusion.', 'error');
        }
    } catch (err) {
        showToast('Network error while recording exclusion.', 'error');
    }
}

// Submit Consolidation
async function submitModalConsolidation(songIdA, songIdB) {
    const radio = document.querySelector('input[name="canonical_choice"]:checked');
    if (!radio) {
        alert('Please select which song should be the canonical record.');
        return;
    }

    const canonicalId = parseInt(radio.value, 10);
    const consolidatedId = (canonicalId === songIdA) ? songIdB : songIdA;

    if (!confirm(`Are you sure you want to consolidate Song #${consolidatedId} into canonical Song #${canonicalId}?\n\nThis will soft-consolidate the duplicate record while preserving all catalogue entries and creating a durable audit snapshot.`)) {
        return;
    }

    const btn = document.getElementById('btnModalConsolidate');
    if (btn) btn.disabled = true;

    try {
        const res = await fetch('api.php?action=consolidate_duplicate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify({
                canonical_id: canonicalId,
                consolidated_id: consolidatedId
            })
        });

        const data = await res.json();
        if (data.success) {
            showToast(data.message, 'success');
            closeComparisonModal();
            setTimeout(() => window.location.reload(), 1200);
        } else {
            if (btn) btn.disabled = false;
            alert(data.error || 'Consolidation failed.');
        }
    } catch (err) {
        if (btn) btn.disabled = false;
        showToast('Network error during consolidation.', 'error');
    }
}

// Audit & Recovery Modal Logic
document.getElementById('btnOpenAuditTrail')?.addEventListener('click', openAuditModal);

async function openAuditModal() {
    const overlay = document.getElementById('auditModalOverlay');
    const loading = document.getElementById('auditModalLoading');
    const content = document.getElementById('auditDynamicContent');

    overlay.classList.add('active');
    overlay.setAttribute('aria-hidden', 'false');
    loading.style.display = 'block';
    content.style.display = 'none';

    try {
        const res = await fetch('api.php?action=get_consolidation_audit');
        const json = await res.json();

        loading.style.display = 'none';
        content.style.display = 'block';

        if (!json.success || !json.data || json.data.length === 0) {
            content.innerHTML = `
                <div class="empty-state">
                    <p class="text-muted">No consolidation audit records found. No songs have been consolidated yet.</p>
                </div>
            `;
            return;
        }

        renderAuditTrail(json.data);
    } catch (err) {
        loading.style.display = 'none';
        content.style.display = 'block';
        content.innerHTML = `<div class="alert alert-danger">Failed to load audit records.</div>`;
    }
}

function closeAuditModal() {
    const overlay = document.getElementById('auditModalOverlay');
    overlay.classList.remove('active');
    overlay.setAttribute('aria-hidden', 'true');
}

function renderAuditTrail(records) {
    const content = document.getElementById('auditDynamicContent');
    content.innerHTML = `
        <table class="data-table audit-table">
            <thead>
                <tr>
                    <th>Audit #</th>
                    <th>Consolidated Song</th>
                    <th>Canonical Song</th>
                    <th>Timestamp</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                ${records.map(r => `
                    <tr>
                        <td>#${r.audit_id}</td>
                        <td>
                            <strong>#${r.consolidated_song_id}</strong> &bull; ${escapeHtml(r.consolidated_title)}
                            <br><small class="text-muted">${escapeHtml(r.consolidated_artist)}</small>
                        </td>
                        <td>
                            <strong>#${r.canonical_song_id}</strong> &bull; ${escapeHtml(r.canonical_title)}
                            <br><small class="text-muted">${escapeHtml(r.canonical_artist)}</small>
                        </td>
                        <td><small>${escapeHtml(r.consolidated_at)}</small></td>
                        <td>
                            ${parseInt(r.is_recovered, 10) === 1 
                                ? '<span class="status-badge status-resolved">Recovered</span>' 
                                : '<span class="status-badge status-awaiting">Consolidated</span>'}
                        </td>
                        <td>
                            ${parseInt(r.is_recovered, 10) === 0 ? `
                                <button type="button" class="btn btn-secondary btn-xs" onclick="recoverConsolidation(${r.audit_id})">
                                    Undo / Recover
                                </button>
                            ` : '<span class="text-muted">&mdash;</span>'}
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
}

async function recoverConsolidation(auditId) {
    if (!confirm(`Are you sure you want to recover and restore this consolidated song?\n\nThis will restore the original catalogue record and revert any transferred dependencies.`)) {
        return;
    }

    try {
        const res = await fetch('api.php?action=recover_consolidation', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify({ audit_id: auditId })
        });

        const data = await res.json();
        if (data.success) {
            showToast(data.message, 'success');
            openAuditModal(); // Reload audit trail
        } else {
            showToast(data.error || 'Recovery failed.', 'error');
        }
    } catch (err) {
        showToast('Network error while recovering consolidation.', 'error');
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Close modals on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeComparisonModal();
        closeAuditModal();
    }
});
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
