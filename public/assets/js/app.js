/**
 * AFM#2 - Client-Side Interactivity & Dynamic Controls
 * Personal Music Journal & Tournament 2026
 */

document.addEventListener('DOMContentLoaded', () => {
    initNavToggle();
    initDiscoveryActions();
    initScorecards();
    initModals();
    initTournamentAdvancement();
    initAuditionMediaAndLyrics();
    initGlobalQuickLyricsModal();
    initAppearanceModal();
    initArtworkPickerModal();
    initSongManagement();
    initAccessiblePagination();
});

/**
 * Mobile Navigation Toggle
 */

function initNavToggle() {
    const toggle = document.getElementById('navToggle');
    const nav = document.getElementById('appNav');
    if (!toggle || !nav) return;

    toggle.addEventListener('click', () => {
        const isOpen = nav.classList.toggle('open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
}

/**
 * Toast Notification System
 */
function showToast(message, type = 'info', duration = 3500) {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    // Icon based on type
    let icon = 'ℹ️';
    if (type === 'success') icon = '✓';
    if (type === 'danger')  icon = '✕';

    toast.innerHTML = `
        <span class="toast-icon">${icon}</span>
        <span class="toast-msg">${escapeHtml(message)}</span>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px) scale(0.95)';
        setTimeout(() => toast.remove(), 250);
    }, duration);
}

/**
 * Retrieve CSRF token from meta tag
 */
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

/**
 * Safe HTML escaping
 */
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Global Image Error Fallback Handler (captures non-bubbling error events on images)
window.addEventListener('error', (e) => {
    if (e.target && e.target.tagName === 'IMG' && !e.target.dataset.fallbackApplied) {
        e.target.dataset.fallbackApplied = 'true';
        e.target.src = 'assets/img/artwork-fallback.svg';
    }
}, true);

/**
 * One-Tap Discovery Actions (Shortlist & Pass)
 */
function initDiscoveryActions() {
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.btn-action-shortlist, .btn-action-pass');
        if (!btn) return;

        e.preventDefault();
        const row = btn.closest('[data-song-id]');
        if (!row) return;

        const songId = row.getAttribute('data-song-id');
        const isShortlist = btn.classList.contains('btn-action-shortlist');
        
        // Determine intended status
        // If already active, clicking again resets to unreviewed
        let newStatus = isShortlist ? 'shortlisted' : 'passed';
        if (btn.classList.contains('active')) {
            newStatus = 'unreviewed';
        }

        // Optimistic UI state
        btn.disabled = true;

        try {
            const res = await fetch('api.php?action=update_discovery', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({
                    song_id: parseInt(songId, 10),
                    status: newStatus
                })
            });

            const data = await res.json();
            btn.disabled = false;

            if (!data.success) {
                showToast(data.error || 'Failed to update discovery status.', 'danger');
                return;
            }

            // Update UI row elements
            updateRowDiscoveryState(row, newStatus);

            // Update dashboard counters if on page
            updateDiscoveryCounters(data);

            const songTitle = row.getAttribute('data-song-title') || 'Song';
            if (newStatus === 'shortlisted') {
                showToast(`"${songTitle}" shortlisted for auditions!`, 'success');
            } else if (newStatus === 'passed') {
                showToast(`"${songTitle}" marked as passed.`, 'info');
            } else {
                showToast(`"${songTitle}" review reset to unreviewed.`, 'info');
            }

        } catch (err) {
            btn.disabled = false;
            console.error('AFM2 Discovery Action Error:', err);
            showToast('Network error while saving discovery decision.', 'danger');
        }
    });
}

function updateRowDiscoveryState(row, status) {
    const sBtn = row.querySelector('.btn-action-shortlist');
    const pBtn = row.querySelector('.btn-action-pass');
    const badge = row.querySelector('.status-badge-container');

    if (sBtn) sBtn.classList.toggle('active', status === 'shortlisted');
    if (pBtn) pBtn.classList.toggle('active', status === 'passed');

    if (badge) {
        if (status === 'shortlisted') {
            badge.innerHTML = '<span class="badge badge-shortlisted">Shortlisted</span>';
        } else if (status === 'passed') {
            badge.innerHTML = '<span class="badge badge-passed">Passed</span>';
        } else {
            badge.innerHTML = '<span class="badge badge-unreviewed">Not Reviewed</span>';
        }
    }
}

function updateDiscoveryCounters(data) {
    const elShort = document.getElementById('statShortlisted');
    const elPassed = document.getElementById('statPassed');
    const elUnrev = document.getElementById('statUnreviewed');

    if (elShort && data.shortlisted_count !== undefined) elShort.textContent = data.shortlisted_count;
    if (elPassed && data.passed_count !== undefined) elPassed.textContent = data.passed_count;
    if (elUnrev && data.unreviewed_count !== undefined) elUnrev.textContent = data.unreviewed_count;
}

/**
 * Scorecard Inputs & Live Calculation (Formal Auditions)
 */
function initScorecards() {
    const cards = document.querySelectorAll('.scorecard-card');
    cards.forEach(card => {
        const inputs = card.querySelectorAll('.criterion-input');
        // Synchronize inputs and range sliders
        const fields = card.querySelectorAll('.criterion-field');
        fields.forEach(field => {
            const input = field.querySelector('.criterion-input');
            const range = field.querySelector('.criterion-range');

            if (input && range) {
                // If input has initial value, sync range
                if (input.value.trim() !== '') {
                    range.value = input.value.trim();
                }

                // Range slider input -> update number input & total
                range.addEventListener('input', () => {
                    input.value = range.value;
                    calculateCardTotal(card);
                });

                // Number input -> update range slider & total
                input.addEventListener('input', () => {
                    let val = input.value.trim();
                    if (val !== '') {
                        let num = parseInt(val, 10);
                        if (isNaN(num)) num = 0;
                        if (num < 0) num = 0;
                        if (num > 10) num = 10;
                        input.value = num;
                        range.value = num;
                    }
                    calculateCardTotal(card);
                });

                // Pressing Enter saves scorecard
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        saveScorecard(card);
                    }
                });
            }
        });

        // Save on button click
        const saveBtn = card.querySelector('.btn-save-scorecard');
        if (saveBtn) {
            saveBtn.addEventListener('click', (e) => {
                e.preventDefault();
                saveScorecard(card);
            });
        }
    });
}

function calculateCardTotal(card) {
    const inputs = card.querySelectorAll('.criterion-input');
    const totalBadge = card.querySelector('.total-score-badge');
    const meterFill = card.querySelector('.total-score-fill');
    if (!totalBadge) return;

    let sum = 0;
    let complete = true;
    let anyFilled = false;

    inputs.forEach(inp => {
        const val = inp.value.trim();
        if (val === '') {
            complete = false;
        } else {
            anyFilled = true;
            sum += parseInt(val, 10);
        }
    });

    // Update gauge meter
    if (meterFill) {
        const pct = Math.min(100, Math.round((sum / 50) * 100));
        meterFill.style.width = `${pct}%`;
        if (complete) {
            meterFill.style.background = 'linear-gradient(90deg, #6366f1, #10b981)';
        } else {
            meterFill.style.background = 'linear-gradient(90deg, #6366f1, #f59e0b)';
        }
    }

    if (complete) {
        totalBadge.textContent = `${sum} / 50`;
        totalBadge.classList.add('complete');
        card.classList.add('complete');
        card.classList.remove('incomplete');
    } else {
        totalBadge.textContent = anyFilled ? `${sum}* / 50` : '— / 50';
        totalBadge.classList.remove('complete');
        card.classList.remove('complete');
        if (anyFilled) {
            card.classList.add('incomplete');
        } else {
            card.classList.remove('incomplete');
        }
    }
}


const RECORDING_VERSION_LABELS = {
    original: '💿 Studio Original',
    live: '🎤 Live Performance',
    acoustic: '🎸 Acoustic Version',
    cover: '👥 Cover Recording',
    remaster: '✨ Remastered',
    other: '🏷️ Other'
};

function applyConfirmedMediaToPanel(panel, m) {
    if (!panel || !m || !m.video_id) return;

    const songId = parseInt(panel.dataset.songId, 10);
    const songTitle = panel.dataset.songTitle || '';
    const songArtist = panel.dataset.songArtist || '';
    const card = panel.closest('.scorecard-card') || document.getElementById(`entry-${panel.dataset.entryId}`);

    panel.dataset.videoId = m.video_id;
    panel.dataset.userUrl = m.user_url || '';
    panel.dataset.versionType = m.version_type || 'original';
    panel.dataset.versionNotes = m.version_notes || '';
    panel.dataset.embedStatus = m.embed_status || 'allowed';

    // Update UI indicator in toolbar
    const indicator = panel.querySelector('.audio-match-indicator');
    const vLabel = RECORDING_VERSION_LABELS[m.version_type] || '💿 Recording';
    if (indicator) {
        indicator.innerHTML = `
            <span class="media-badge media-badge-confirmed" title="${escapeHtml(m.version_notes ? 'Notes: ' + m.version_notes : vLabel)}">
                ${escapeHtml(vLabel)}
            </span>
            <button type="button" class="btn-xs btn-primary-subtle btn-toggle-player active" title="Play or hide embedded player">
                <span class="btn-icon">▶</span> <span class="player-toggle-label">Hide Player</span>
            </button>
            <button type="button" class="btn-xs btn-secondary btn-edit-media" title="Change recording version or video ID">
                <span class="btn-icon">🔁</span> Change Recording
            </button>
        `;
    }

    // Update direct external links in toolbar (no search redirects)
    const extToolbar = panel.querySelector('.media-toolbar-left');
    if (extToolbar) {
        extToolbar.querySelectorAll('.link-external').forEach(el => el.remove());
        const ytLink = document.createElement('a');
        ytLink.href = `https://www.youtube.com/watch?v=${encodeURIComponent(m.video_id)}`;
        ytLink.target = '_blank';
        ytLink.rel = 'noopener noreferrer';
        ytLink.className = 'link-external';
        ytLink.title = 'Direct link to confirmed recording on YouTube';
        ytLink.textContent = 'YouTube ↗';
        extToolbar.appendChild(ytLink);

        const ytmLink = document.createElement('a');
        ytmLink.href = `https://music.youtube.com/watch?v=${encodeURIComponent(m.video_id)}`;
        ytmLink.target = '_blank';
        ytmLink.rel = 'noopener noreferrer';
        ytmLink.className = 'link-external';
        ytmLink.title = 'Direct link to confirmed recording on YouTube Music';
        ytmLink.textContent = 'YT Music ↗';
        extToolbar.appendChild(ytmLink);
    }

    // Update Player Container with strict origin and enablejsapi=1
    const playerContainer = panel.querySelector('.player-container');
    if (playerContainer) {
        const embedSrc = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(m.video_id)}?enablejsapi=1&origin=${encodeURIComponent(window.location.origin)}&rel=0&autoplay=0&playsinline=1`;
        playerContainer.innerHTML = `
            <div class="player-wrapper">
                <iframe 
                    class="yt-embed-frame" 
                    id="yt-player-${songId}" 
                    data-video-id="${escapeHtml(m.video_id)}"
                    data-src="${embedSrc}" 
                    src="${embedSrc}" 
                    referrerpolicy="strict-origin-when-cross-origin"
                    loading="lazy" 
                    title="AFM#2 Audio Player: ${escapeHtml(songTitle)}" 
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" 
                    allowfullscreen
                ></iframe>
                <div class="player-footer">
                    <div class="player-footer-left">
                        <a href="https://www.youtube.com/watch?v=${encodeURIComponent(m.video_id)}" target="_blank" rel="noopener noreferrer" class="link-external" title="Direct link to confirmed recording on YouTube">
                            YouTube ↗
                        </a>
                        <a href="https://music.youtube.com/watch?v=${encodeURIComponent(m.video_id)}" target="_blank" rel="noopener noreferrer" class="link-external" title="Direct link to confirmed recording on YouTube Music">
                            YT Music ↗
                        </a>
                    </div>
                    <div class="player-footer-right">
                        <button type="button" class="btn-xs btn-secondary btn-edit-media" title="Change recording version or select different link">
                            <span class="btn-icon">🔁</span> Change Recording
                        </button>
                        <button type="button" class="btn-xs btn-danger-subtle btn-mark-embed-blocked" title="Mark if YouTube player displays an embedding error">
                            Embedding Blocked?
                        </button>
                    </div>
                </div>
            </div>
        `;
        playerContainer.style.display = 'block';
    }

    // Close matcher box & unconfirmed box
    const matcherBox = panel.querySelector('.media-matcher-box');
    if (matcherBox) {
        matcherBox.style.display = 'none';
        const prevBox = matcherBox.querySelector('.candidate-preview-container');
        if (prevBox) prevBox.innerHTML = '';
        const curRef = matcherBox.querySelector('.matcher-current-ref');
        if (curRef) {
            curRef.innerHTML = `Currently: <strong>${escapeHtml(m.video_id)}</strong> (${escapeHtml(vLabel)})`;
        }
        const inp = matcherBox.querySelector('.input-video-url');
        if (inp) inp.value = m.user_url || m.video_id;
    }
    const unconfBox = panel.querySelector('.player-unconfirmed-box');
    if (unconfBox) {
        unconfBox.style.display = 'none';
    }

    // Update artwork thumbnail if present on the card
    if (card) {
        const artImg = card.querySelector('.tactile-artwork-img');
        if (artImg && (!artImg.src || artImg.src.includes('artwork-fallback.svg'))) {
            artImg.src = `https://img.youtube.com/vi/${encodeURIComponent(m.video_id)}/hqdefault.jpg`;
        }
    }

    // Ensure drawer is open
    const drawer = panel.querySelector('.audition-drawer');
    if (drawer) {
        drawer.style.display = 'grid';
    }

    // Re-initialize YouTube Player API instance with forceReinit = true
    if (typeof attachYouTubePlayerToPanel === 'function') {
        attachYouTubePlayerToPanel(panel, songId, true);
    }

    // Automatically refresh lyrics for confirmed recording
    if (typeof loadLyricsForCard === 'function') {
        loadLyricsForCard(panel, songId, songTitle, songArtist, true);
    }
}

async function saveScorecard(card) {
    const entryId = card.getAttribute('data-entry-id');
    const songId = card.getAttribute('data-song-id');
    const roundId = card.getAttribute('data-round-id') || 1;
    const saveBtn = card.querySelector('.btn-save-scorecard');
    const feedback = card.querySelector('.save-feedback');

    if (!entryId) return;

    // Check if user entered or modified a video recording link in the card's media panel
    const panel = card.querySelector('.scorecard-media-panel');
    let videoVal = '';
    let versionTypeVal = 'original';
    let versionNotesVal = '';

    if (panel) {
        const activeInput = panel.querySelector('.player-unconfirmed-box .input-video-url, .media-matcher-box .input-video-url');
        if (activeInput && activeInput.value.trim()) {
            videoVal = (activeInput.dataset.resolvedVideoId) ? activeInput.dataset.resolvedVideoId : activeInput.value.trim();
            const activeSelect = panel.querySelector('.select-version-type');
            if (activeSelect) versionTypeVal = activeSelect.value;
            const activeNotes = panel.querySelector('.input-version-notes');
            if (activeNotes) versionNotesVal = activeNotes.value.trim();
        }
    }

    const payload = {
        entry_id: parseInt(entryId, 10),
        song_id: songId ? parseInt(songId, 10) : undefined,
        round_id: parseInt(roundId, 10),
        melody: card.querySelector('[data-criterion="melody"]')?.value.trim() ?? '',
        lyrics: card.querySelector('[data-criterion="lyrics"]')?.value.trim() ?? '',
        vocals: card.querySelector('[data-criterion="vocals"]')?.value.trim() ?? '',
        emotion: card.querySelector('[data-criterion="emotion"]')?.value.trim() ?? '',
        replayability: card.querySelector('[data-criterion="replayability"]')?.value.trim() ?? '',
        video_id_or_url: videoVal,
        user_url: videoVal,
        version_type: versionTypeVal,
        version_notes: versionNotesVal
    };

    if (saveBtn) saveBtn.disabled = true;
    if (feedback) feedback.textContent = 'Saving...';

    try {
        const res = await fetch('api.php?action=save_rating', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (saveBtn) saveBtn.disabled = false;

        if (!data.success) {
            if (feedback) feedback.textContent = 'Save failed.';
            showToast(data.error || 'Failed to save scorecard.', 'danger');
            return;
        }

        // If recording was saved/confirmed as part of scorecard, apply immediately to media panel
        if (data.media && panel) {
            applyConfirmedMediaToPanel(panel, data.media);
        }

        // Update card total from server database generated column
        const totalBadge = card.querySelector('.total-score-badge');
        if (totalBadge && data.total !== null) {
            totalBadge.textContent = `${data.total} / 50`;
            totalBadge.classList.add('complete');
            card.classList.add('complete');
            card.classList.remove('incomplete');
        }

        if (feedback) {
            feedback.textContent = data.is_complete ? '✓ Saved (Complete)' : '✓ Saved (Incomplete)';
            feedback.style.color = data.is_complete ? 'var(--success)' : 'var(--gold)';
        }

        showToast(data.message, data.is_complete ? 'success' : 'info');

    } catch (err) {
        if (saveBtn) saveBtn.disabled = false;
        if (feedback) feedback.textContent = 'Save error.';
        console.error('AFM2 Save Scorecard Error:', err);
        showToast('Network error while saving scorecard.', 'danger');
    }
}

/**
 * Generic Modal Dialogs (Confirmation for Tournament Advancement)
 */
function initModals() {
    document.querySelectorAll('[data-modal-open]').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = trigger.getAttribute('data-modal-open');
            const modal = document.getElementById(targetId);
            if (modal) modal.classList.add('active');
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach(closer => {
        closer.addEventListener('click', (e) => {
            e.preventDefault();
            const modal = closer.closest('.modal-overlay');
            if (modal) modal.classList.remove('active');
        });
    });
}

/**
 * Tournament Stage Advancement Modal & Execution
 */
function initTournamentAdvancement() {
    const advBtn = document.getElementById('btnPreviewAdvancement');
    const modal = document.getElementById('advancementModal');
    const confirmBtn = document.getElementById('btnConfirmAdvancement');
    const modalBody = document.getElementById('advancementModalBody');

    if (!advBtn || !modal || !confirmBtn) return;

    let pendingPayload = null;

    advBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        const fromRoundId = advBtn.getAttribute('data-from-round-id');
        if (!fromRoundId) return;

        advBtn.disabled = true;
        if (modalBody) {
            modalBody.innerHTML = '<div class="text-center p-4">Loading stage advancement preview...</div>';
        }
        modal.classList.add('active');

        try {
            const res = await fetch(`api.php?action=preview_advancement&from_round_id=${fromRoundId}`);
            const data = await res.json();
            advBtn.disabled = false;

            if (!data.success) {
                modalBody.innerHTML = `<div class="alert alert-danger">${escapeHtml(data.error || 'Failed to preview advancement.')}</div>`;
                confirmBtn.style.display = 'none';
                return;
            }

            confirmBtn.style.display = 'inline-block';
            confirmBtn.disabled = false;

            const fromRound = data.from_round;
            const nextRound = data.next_round;
            const qualifiers = data.qualifiers || [];
            const eliminated = data.eliminated || [];

            pendingPayload = {
                from_round_id: parseInt(fromRound.round_id, 10),
                qualifier_ids: qualifiers.map(q => parseInt(q.entry_id, 10))
            };

            let tieWarningHtml = '';
            if (data.has_boundary_tie && data.boundary_tie) {
                tieWarningHtml = `
                    <div class="alert alert-warning mb-3">
                        <strong>⚠️ Cutoff Boundary Tie Detected</strong>
                        <p class="text-sm mt-1">${escapeHtml(data.boundary_tie.criteria_tie_break)}</p>
                    </div>
                `;
            }

            let html = `
                <div class="advancement-summary mb-3">
                    <p>Advancing from <strong>${escapeHtml(fromRound.round_name)}</strong> to <strong>${escapeHtml(nextRound.round_name)}</strong>.</p>
                    <div class="stats-pills-row mt-2" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <span class="badge badge-success">Qualifiers: ${qualifiers.length}</span>
                        <span class="badge badge-danger">Eliminated: ${eliminated.length}</span>
                        <span class="badge badge-neutral">Target Cutoff: ${data.target_cutoff}</span>
                    </div>
                </div>
                ${tieWarningHtml}
                <div class="qualifiers-preview-list">
                    <h4 class="text-sm font-bold text-muted uppercase mb-2">Qualifying Songs (${qualifiers.length})</h4>
                    <div class="preview-scroll-box" style="max-height: 220px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.5rem;">
                        <table class="table-compact w-100" style="width: 100%; font-size: 0.85rem;">
                            <tbody>
                                ${qualifiers.map(q => `
                                    <tr style="border-bottom: 1px solid var(--border-subtle);">
                                        <td style="padding: 4px 8px; width: 35px; font-weight: 700; color: var(--gold);">#${q.calculated_rank}</td>
                                        <td style="padding: 4px 8px;"><strong>${escapeHtml(q.title)}</strong> <span class="text-muted">— ${escapeHtml(q.artist)}</span></td>
                                        <td style="padding: 4px 8px; text-align: right; font-weight: 700; color: var(--accent);">${q.total}/50</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
                <p class="text-xs text-muted mt-3">
                    Confirming will mark <strong>${escapeHtml(fromRound.round_name)}</strong> as completed, record official rank positions in the archive, and activate <strong>${escapeHtml(nextRound.round_name)}</strong>.
                </p>
            `;

            modalBody.innerHTML = html;

        } catch (err) {
            advBtn.disabled = false;
            console.error('Advancement Preview Error:', err);
            modalBody.innerHTML = '<div class="alert alert-danger">Network error loading stage advancement preview.</div>';
            confirmBtn.style.display = 'none';
        }
    });

    confirmBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        if (!pendingPayload) return;

        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Advancing Stage...';

        try {
            const res = await fetch('api.php?action=commit_advancement', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify(pendingPayload)
            });

            const data = await res.json();
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Confirm & Advance';

            if (!data.success) {
                showToast(data.error || 'Failed to advance tournament stage.', 'danger');
                return;
            }

            modal.classList.remove('active');
            showToast(data.message || 'Tournament stage advanced successfully!', 'success', 4000);

            // Reload page to reflect new stage state
            setTimeout(() => {
                window.location.reload();
            }, 1200);

        } catch (err) {
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Confirm & Advance';
            console.error('Commit Advancement Error:', err);
            showToast('Network error while committing tournament advancement.', 'danger');
        }
    });
}

/**
 * ==============================================================================
 * Audio Playback & LRCLIB Lyrics Experience Integration
 * ==============================================================================
 */

// In-memory cache for loaded lyrics per song to avoid duplicate API calls
const cardLyricsCache = new Map();

// Map of songId -> YT.Player instance
const ytPlayerMap = new Map();
// Map of songId -> active interval tracking playback position
const activePlaybackIntervals = new Map();

/**
 * Dynamically load YouTube IFrame API if not already present
 */
function ensureYouTubeIframeApi() {
    if (window.YT && window.YT.Player) return;
    if (document.getElementById('yt-iframe-api-tag')) return;
    const tag = document.createElement('script');
    tag.id = 'yt-iframe-api-tag';
    tag.src = 'https://www.youtube.com/iframe_api';
    document.head.appendChild(tag);
}

// Playback status updater
function setPlaybackStatus(songId, stateClass, stateText) {
    const pill = document.getElementById(`status-pill-${songId}`);
    if (!pill) return;
    pill.className = `playback-status-pill ${stateClass}`;
    const textEl = pill.querySelector('.status-text');
    if (textEl) textEl.textContent = stateText;
}

// Global callback fired when YouTube IFrame API finishes loading
window.onYouTubeIframeAPIReady = function() {
    document.querySelectorAll('.scorecard-media-panel').forEach(panel => {
        const songId = parseInt(panel.dataset.songId, 10);
        const iframe = panel.querySelector('.yt-embed-frame');
        if (iframe && iframe.getAttribute('src')) {
            attachYouTubePlayerToPanel(panel, songId);
        }
    });
};

/**
 * Attach YT.Player to the iframe of an audition panel to track playback & sync lyrics
 */
function attachYouTubePlayerToPanel(panel, songId, forceReinit = false) {
    const iframe = panel.querySelector('.yt-embed-frame');
    if (!iframe) return;
    if (!iframe.id) iframe.id = `yt-player-${songId}`;

    if (!window.YT || !window.YT.Player) {
        ensureYouTubeIframeApi();
        setTimeout(() => attachYouTubePlayerToPanel(panel, songId, forceReinit), 500);
        return;
    }

    if (ytPlayerMap.has(songId)) {
        if (forceReinit) {
            try {
                const old = ytPlayerMap.get(songId);
                if (old && typeof old.destroy === 'function') {
                    old.destroy();
                }
            } catch (e) {}
            ytPlayerMap.delete(songId);
        } else {
            return;
        }
    }

    try {
        const player = new YT.Player(iframe.id, {
            events: {
                'onReady': () => {
                    setPlaybackStatus(songId, 'status-ready', 'Ready to Play');
                },
                'onStateChange': (event) => {
                    // 1: PLAYING, 2: PAUSED, 3: BUFFERING, 0: ENDED, 5: CUED
                    if (event.data === 1) {
                        setPlaybackStatus(songId, 'status-playing', 'Playing');
                        startSyncedLyricsTracker(panel, songId, player);
                    } else if (event.data === 2) {
                        setPlaybackStatus(songId, 'status-paused', 'Paused');
                        stopSyncedLyricsTracker(songId);
                    } else if (event.data === 3) {
                        setPlaybackStatus(songId, 'status-buffering', 'Buffering...');
                    } else if (event.data === 0) {
                        setPlaybackStatus(songId, 'status-ended', 'Ended');
                        stopSyncedLyricsTracker(songId);
                    } else if (event.data === 5) {
                        setPlaybackStatus(songId, 'status-ready', 'Ready to Play');
                        stopSyncedLyricsTracker(songId);
                    }
                },
                'onError': (event) => {
                    // 101/150: The owner does not allow embedding
                    // 100: Video not found/removed, 2: Invalid video ID
                    console.warn(`YouTube Player error (${event.data}) on song ${songId}`);
                    setPlaybackStatus(songId, 'status-restricted', 'Restricted');
                    handleEmbedErrorFallback(panel, songId, event.data);
                }
            }
        });
        ytPlayerMap.set(songId, player);
    } catch (e) {
        console.warn('YT Player init fallback:', e);
    }
}

/**
 * Handle YouTube embed restriction or playback errors gracefully
 */
function handleEmbedErrorFallback(panel, songId, errorCode = 150) {
    const playerContainer = panel.querySelector('.player-container');
    if (!playerContainer) return;
    const vId = panel.dataset.videoId || '';

    let reason = 'The copyright owner or uploader does not permit third-party embedded playback outside YouTube.';
    if (errorCode === 100) {
        reason = 'The selected YouTube recording could not be found or has been removed.';
    } else if (errorCode === 2) {
        reason = 'The YouTube recording identifier or parameters are invalid.';
    }

    playerContainer.innerHTML = `
        <div class="embed-blocked-alert">
            <div class="embed-blocked-title">⚠️ Video Embedding Restricted</div>
            <div class="embed-blocked-desc">
                ${escapeHtml(reason)} You can listen directly in an official player tab, or change to another official or live recording:
            </div>
            <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <a href="https://www.youtube.com/watch?v=${encodeURIComponent(vId)}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-accent">
                    Open on YouTube ↗
                </a>
                <a href="https://music.youtube.com/watch?v=${encodeURIComponent(vId)}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-secondary">
                    YT Music ↗
                </a>
                <button type="button" class="btn btn-sm btn-primary-subtle btn-edit-media">
                    🔁 Change Recording
                </button>
                <button type="button" class="btn btn-sm btn-secondary btn-retest-embed">
                    Retry Embed
                </button>
            </div>
        </div>
    `;

    // Persist blocked status to backend
    try {
        fetch('api.php?action=update_embed_status', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify({ song_id: songId, embed_status: 'blocked' })
        });
    } catch (e) {}
}

/**
 * Start 250ms polling interval to track current playback seconds and update active lyric line
 */
function startSyncedLyricsTracker(panel, songId, player) {
    stopSyncedLyricsTracker(songId);

    const intervalId = setInterval(() => {
        if (!player || typeof player.getCurrentTime !== 'function') return;
        try {
            const currentTime = player.getCurrentTime();
            updateActiveSyncedLyricLine(panel, currentTime);
        } catch (e) {}
    }, 250);

    activePlaybackIntervals.set(songId, intervalId);
}

function stopSyncedLyricsTracker(songId) {
    if (activePlaybackIntervals.has(songId)) {
        clearInterval(activePlaybackIntervals.get(songId));
        activePlaybackIntervals.delete(songId);
    }
}

/**
 * Highlight active lyric line and smoothly auto-scroll (Echo Music inspired follower)
 */
function updateActiveSyncedLyricLine(panel, currentTime) {
    const box = panel.querySelector('.lyrics-box');
    if (!box) return;

    // Check if synced tab is currently visible
    const syncedTab = box.querySelector('.lyrics-tab[data-view="synced"]');
    if (!syncedTab || !syncedTab.classList.contains('active')) return;

    const list = box.querySelector('.lyrics-synced-list');
    if (!list) return;

    const lines = Array.from(list.querySelectorAll('.lyrics-synced-line'));
    if (!lines.length) return;

    // Active line is the last line whose seconds <= currentTime
    let activeIndex = -1;
    for (let i = 0; i < lines.length; i++) {
        const sec = parseFloat(lines[i].dataset.seconds);
        if (currentTime >= sec) {
            activeIndex = i;
        } else {
            break;
        }
    }

    if (activeIndex === -1) return;

    const activeLine = lines[activeIndex];
    if (activeLine.classList.contains('active')) return;

    lines.forEach(l => l.classList.remove('active'));
    activeLine.classList.add('active');

    // Auto-scroll if enabled
    const autoScrollBtn = box.querySelector('.btn-autoscroll-toggle');
    const isAutoScroll = !autoScrollBtn || autoScrollBtn.classList.contains('active');
    if (isAutoScroll) {
        const body = box.querySelector('.lyrics-body');
        if (body) {
            const bodyRect = body.getBoundingClientRect();
            const lineRect = activeLine.getBoundingClientRect();
            const offset = (lineRect.top - bodyRect.top) - (body.clientHeight / 2) + (lineRect.height / 2);
            body.scrollBy({ top: offset, behavior: 'smooth' });
        }
    }
}

/**
 * Seek YouTube player to exact seconds when user clicks a timestamped line
 */
function seekYouTubePlayer(panel, songId, seconds) {
    const player = ytPlayerMap.get(songId);
    if (player && typeof player.seekTo === 'function') {
        player.seekTo(seconds, true);
        if (typeof player.playVideo === 'function') {
            player.playVideo();
        }
    } else {
        // Fallback using HTML5 postMessage
        const iframe = panel.querySelector('.yt-embed-frame');
        if (iframe && iframe.contentWindow) {
            iframe.contentWindow.postMessage(JSON.stringify({
                event: 'command',
                func: 'seekTo',
                args: [seconds, true]
            }), '*');
        }
    }
}

function initAuditionMediaAndLyrics() {
    const container = document.getElementById('scorecardsContainer');
    if (!container) return;

    // Ensure YouTube API is preloaded for interactive sync
    ensureYouTubeIframeApi();

    // Version label mapping
    const versionLabels = {
        original: '💿 Studio Original',
        live: '🎤 Live Performance',
        acoustic: '🎸 Acoustic Version',
        cover: '👥 Cover Recording',
        remaster: '✨ Remastered',
        other: '🏷️ Other'
    };

    // Auto-initialize players and lyrics for cards that have confirmed recordings
    container.querySelectorAll('.scorecard-media-panel').forEach(panel => {
        const songId = parseInt(panel.dataset.songId, 10);
        const songTitle = panel.dataset.songTitle || '';
        const songArtist = panel.dataset.songArtist || '';
        const drawer = panel.querySelector('.audition-drawer');
        const iframe = panel.querySelector('.yt-embed-frame');

        if (iframe && iframe.getAttribute('src')) {
            attachYouTubePlayerToPanel(panel, songId);
        }

        if (drawer && drawer.style.display !== 'none') {
            loadLyricsForCard(panel, songId, songTitle, songArtist, false);
        }
    });

    container.addEventListener('click', async (e) => {
        const panel = e.target.closest('.scorecard-media-panel');
        if (!panel) return;

        const songId = parseInt(panel.dataset.songId, 10);
        const songTitle = panel.dataset.songTitle || '';
        const songArtist = panel.dataset.songArtist || '';
        const drawer = panel.querySelector('.audition-drawer');
        const playerContainer = panel.querySelector('.player-container');
        const matcherBox = panel.querySelector('.media-matcher-box');
        const iframe = panel.querySelector('.yt-embed-frame');

        // 1. Toggle Audio Player Visibility
        const togglePlayerBtn = e.target.closest('.btn-toggle-player');
        if (togglePlayerBtn) {
            e.preventDefault();
            const isOpen = playerContainer && playerContainer.style.display !== 'none';

            if (isOpen) {
                if (playerContainer) playerContainer.style.display = 'none';
                if (matcherBox) matcherBox.style.display = 'none';
                togglePlayerBtn.classList.remove('active');
                togglePlayerBtn.querySelector('.player-toggle-label').textContent = 'Show Player';
            } else {
                if (playerContainer) playerContainer.style.display = 'block';
                if (matcherBox) matcherBox.style.display = 'none';
                drawer.style.display = 'grid';

                // Lazy load iframe src if needed
                if (iframe && !iframe.getAttribute('src') && iframe.dataset.src) {
                    iframe.setAttribute('src', iframe.dataset.src);
                }

                // Attach YouTube IFrame API player instance
                attachYouTubePlayerToPanel(panel, songId);

                togglePlayerBtn.classList.add('active');
                togglePlayerBtn.querySelector('.player-toggle-label').textContent = 'Hide Player';
            }
            return;
        }

        // 2. Open / Toggle Audio Matcher (Change Recording)
        const openMatcherBtn = e.target.closest('.btn-match-media, .btn-edit-media');
        if (openMatcherBtn) {
            e.preventDefault();
            drawer.style.display = 'grid';
            if (matcherBox) {
                const isCurrentlyOpen = matcherBox.style.display === 'block';
                matcherBox.style.display = isCurrentlyOpen ? 'none' : 'block';
                if (!isCurrentlyOpen) {
                    const input = matcherBox.querySelector('.input-video-url');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                }
            }
            return;
        }

        // 3. Cancel Matcher
        const cancelMatcherBtn = e.target.closest('.btn-cancel-matcher');
        if (cancelMatcherBtn) {
            e.preventDefault();
            if (matcherBox) {
                matcherBox.style.display = 'none';
                const prevBox = matcherBox.querySelector('.candidate-preview-container');
                if (prevBox) prevBox.innerHTML = '';
            }
            return;
        }

        // 4. Preview Candidate Recording (Before Saving)
        const previewBtn = e.target.closest('.btn-preview-candidate');
        if (previewBtn) {
            e.preventDefault();
            const box = e.target.closest('.player-unconfirmed-box, .media-matcher-box');
            if (!box) return;

            const input = box.querySelector('.input-video-url');
            const previewContainer = box.querySelector('.candidate-preview-container');
            const saveBtn = box.querySelector('.btn-save-match');
            if (!input || !previewContainer) return;

            const rawInput = input.value.trim();
            if (!rawInput) {
                showToast('Please paste a YouTube or YouTube Music link or video ID.', 'danger');
                input.focus();
                return;
            }

            const origText = previewBtn.innerHTML;
            previewBtn.disabled = true;
            previewBtn.innerHTML = '<span class="lyrics-spinner"></span> Resolving...';
            previewContainer.style.display = 'block';
            previewContainer.innerHTML = `
                <div class="candidate-preview-box">
                    <div class="preview-header">
                        <span class="lyrics-spinner"></span>
                        <span class="text-xs text-muted">Validating recording link...</span>
                    </div>
                </div>
            `;

            try {
                const res = await fetch(`api.php?action=resolve_recording&url=${encodeURIComponent(rawInput)}&url_or_video_id=${encodeURIComponent(rawInput)}&song_id=${songId}`);
                const data = await res.json();
                previewBtn.disabled = false;
                previewBtn.innerHTML = origText;

                if (!data.success) {
                    previewContainer.innerHTML = `
                        <div class="candidate-preview-box" style="border-color: rgba(239, 68, 68, 0.5);">
                            <div class="preview-header">
                                <span class="badge" style="background: rgba(239, 68, 68, 0.2); color: #f87171;">✕ Invalid Link</span>
                            </div>
                            <p style="font-size: 0.8rem; color: #fca5a5; margin: 0.4rem 0;">
                                ${escapeHtml(data.error || 'Could not resolve a playable video from this link.')}
                            </p>
                            <p style="font-size: 0.75rem; color: var(--text-muted); margin: 0;">
                                Please provide a YouTube watch link (youtube.com/watch?v=...), short link (youtu.be/...), or YouTube Music link.
                            </p>
                        </div>
                    `;
                    showToast(data.error || 'Invalid link or video ID.', 'danger');
                    return;
                }

                const r = data.recording || data;
                input.dataset.resolvedVideoId = r.video_id;

                const previewSrc = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(r.video_id)}?enablejsapi=1&origin=${encodeURIComponent(window.location.origin)}&rel=0&autoplay=1`;
                previewContainer.innerHTML = `
                    <div class="candidate-preview-box">
                        <div class="preview-header">
                            <span class="preview-badge">● Candidate Preview (Unconfirmed)</span>
                            <span class="text-xs text-muted">ID: <code>${escapeHtml(r.video_id)}</code></span>
                        </div>
                        <div class="player-wrapper">
                            <iframe 
                                class="yt-embed-frame yt-preview-frame" 
                                id="yt-preview-${songId}" 
                                src="${previewSrc}" 
                                referrerpolicy="strict-origin-when-cross-origin"
                                title="Candidate Preview" 
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" 
                                allowfullscreen
                            ></iframe>
                        </div>
                        <div class="preview-meta">
                            <div><strong>Title:</strong> ${escapeHtml(r.title || 'YouTube Recording')}</div>
                            ${r.author_name ? `<div><strong>Channel / Artist:</strong> ${escapeHtml(r.author_name)}</div>` : ''}
                            <div style="color: #fbbf24; font-size: 0.75rem; margin-top: 0.35rem;">
                                ℹ️ Listen to verify this is the intended recording. Click <strong>"Confirm & Save Recording"</strong> to store it permanently.
                            </div>
                        </div>
                    </div>
                `;

                if (saveBtn) {
                    saveBtn.classList.remove('btn-secondary');
                    saveBtn.classList.add('btn-primary');
                }

                showToast('Preview loaded! Listen and confirm your recording.', 'info');

            } catch (err) {
                previewBtn.disabled = false;
                previewBtn.innerHTML = origText;
                console.error('Resolve Recording Error:', err);
                previewContainer.innerHTML = `
                    <div class="candidate-preview-box" style="border-color: rgba(239, 68, 68, 0.5);">
                        <p style="font-size: 0.8rem; color: #fca5a5; margin: 0;">Network error resolving recording preview.</p>
                    </div>
                `;
                showToast('Network error while resolving video candidate.', 'danger');
            }
            return;
        }

        // 5. Save Confirmed Video Match
        const saveMatchBtn = e.target.closest('.btn-save-match');
        if (saveMatchBtn) {
            e.preventDefault();
            const box = e.target.closest('.player-unconfirmed-box, .media-matcher-box');
            if (!box) return;

            const input = box.querySelector('.input-video-url');
            const versionSelect = box.querySelector('.select-version-type') ? box.querySelector('.select-version-type').value : 'original';
            const notesInput = box.querySelector('.input-version-notes') ? box.querySelector('.input-version-notes').value.trim() : '';

            const rawInput = input ? input.value.trim() : '';
            const resolvedId = (input && input.dataset.resolvedVideoId) ? input.dataset.resolvedVideoId : rawInput;

            if (!rawInput && !resolvedId) {
                showToast('Please enter a YouTube video ID or link.', 'danger');
                if (input) input.focus();
                return;
            }

            saveMatchBtn.disabled = true;
            saveMatchBtn.textContent = 'Saving...';

            try {
                const res = await fetch('api.php?action=save_media', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({
                        song_id: songId,
                        video_id_or_url: resolvedId,
                        user_url: rawInput,
                        version_type: versionSelect,
                        version_notes: notesInput,
                        embed_status: 'allowed'
                    })
                });

                const data = await res.json();
                saveMatchBtn.disabled = false;
                saveMatchBtn.textContent = 'Confirm & Save Recording';

                if (!data.success) {
                    showToast(data.error || 'Failed to save recording reference.', 'danger');
                    return;
                }

                applyConfirmedMediaToPanel(panel, data.media);
                showToast('Confirmed recording reference saved!', 'success');

            } catch (err) {
                saveMatchBtn.disabled = false;
                saveMatchBtn.textContent = 'Confirm & Save Recording';
                console.error('Save Media Error:', err);
                showToast('Network error while saving video match.', 'danger');
            }
            return;
        }

        // 6. Delete Confirmed Video Match
        const deleteMatchBtn = e.target.closest('.btn-delete-match');
        if (deleteMatchBtn) {
            e.preventDefault();
            if (!confirm('Are you sure you want to remove the confirmed recording reference for this song?')) {
                return;
            }

            deleteMatchBtn.disabled = true;

            try {
                const res = await fetch('api.php?action=remove_media', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({ song_id: songId })
                });

                const data = await res.json();
                deleteMatchBtn.disabled = false;

                if (!data.success) {
                    showToast(data.error || 'Failed to remove media match.', 'danger');
                    return;
                }

                // Clean up player instance
                if (ytPlayerMap.has(songId)) {
                    try {
                        const old = ytPlayerMap.get(songId);
                        if (old && typeof old.destroy === 'function') old.destroy();
                    } catch (e) {}
                    ytPlayerMap.delete(songId);
                }
                stopSyncedLyricsTracker(songId);
                setPlaybackStatus(songId, 'status-ready', 'Ready to Play');

                // Reset panel data
                delete panel.dataset.videoId;
                delete panel.dataset.userUrl;
                panel.dataset.versionType = 'original';
                panel.dataset.versionNotes = '';
                panel.dataset.embedStatus = 'allowed';

                // Reset Toolbar
                const indicator = panel.querySelector('.audio-match-indicator');
                indicator.innerHTML = `
                    <span class="media-badge media-badge-unmatched">○ No recording selected</span>
                    <button type="button" class="btn-xs btn-primary-subtle btn-match-media" title="Select and confirm recording">
                        <span class="btn-icon">🎧</span> Select Recording
                    </button>
                `;

                // Render unconfirmed selection box in playerContainer
                playerContainer.innerHTML = `
                    <div class="player-unconfirmed-box">
                        <div class="unconfirmed-header">
                            <div class="unconfirmed-icon">🎧</div>
                            <div class="unconfirmed-content">
                                <h4 class="unconfirmed-title">Select Intended Recording</h4>
                                <p class="unconfirmed-desc">Paste a YouTube or YouTube Music link below to preview and listen directly inside AFM#2.</p>
                            </div>
                        </div>
                        <div class="matcher-grid">
                            <input 
                                type="text" 
                                class="matcher-input input-video-url" 
                                placeholder="Paste YouTube or YouTube Music link (e.g. youtube.com/watch?v=... or youtu.be/...)"
                                value=""
                                aria-label="YouTube recording link or video ID"
                            >
                            <select class="matcher-select select-version-type" aria-label="Recording version type">
                                <option value="original" selected>💿 Original Studio</option>
                                <option value="live">🎤 Live Performance</option>
                                <option value="acoustic">🎸 Acoustic Version</option>
                                <option value="cover">👥 Cover Recording</option>
                                <option value="remaster">✨ Remastered</option>
                                <option value="other">🏷️ Other</option>
                            </select>
                        </div>
                        <input 
                            type="text" 
                            class="matcher-input input-version-notes" 
                            placeholder="Optional version notes (e.g. 1975 Wembley Live, Album Cut)"
                            value=""
                            style="margin-bottom: 0.6rem;"
                            aria-label="Optional version notes"
                        >
                        <div class="matcher-actions">
                            <button type="button" class="btn-xs btn-secondary btn-preview-candidate" title="Test and listen to this recording before saving">
                                <span class="btn-icon">▶</span> Preview Candidate
                            </button>
                            <button type="button" class="btn-xs btn-primary-subtle btn-save-match" title="Save this recording to song library">
                                <span class="btn-icon">✓</span> Confirm & Save Recording
                            </button>
                        </div>
                        <div class="candidate-preview-container" style="display: none; margin-top: 0.75rem;"></div>
                    </div>
                `;

                if (matcherBox) matcherBox.style.display = 'none';

                showToast('Confirmed recording removed.', 'info');

            } catch (err) {
                deleteMatchBtn.disabled = false;
                console.error('Delete Media Error:', err);
                showToast('Network error while removing media match.', 'danger');
            }
            return;
        }

        // 7. Report / Toggle Embed Blocked
        const markBlockedBtn = e.target.closest('.btn-mark-embed-blocked');
        if (markBlockedBtn) {
            e.preventDefault();
            setPlaybackStatus(songId, 'status-restricted', 'Restricted');
            handleEmbedErrorFallback(panel, songId, 150);
            showToast('Marked as restricted playback.', 'info');
            return;
        }

        // 8. Retest / Restore Embed
        const retestEmbedBtn = e.target.closest('.btn-retest-embed');
        if (retestEmbedBtn) {
            e.preventDefault();
            try {
                await fetch('api.php?action=update_embed_status', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({ song_id: songId, embed_status: 'allowed' })
                });

                panel.dataset.embedStatus = 'allowed';
                const vId = panel.dataset.videoId;
                const embedSrc = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(vId)}?enablejsapi=1&origin=${encodeURIComponent(window.location.origin)}&rel=0&autoplay=0&playsinline=1`;

                playerContainer.innerHTML = `
                    <div class="player-wrapper">
                        <iframe 
                            class="yt-embed-frame" 
                            id="yt-player-${songId}" 
                            data-video-id="${escapeHtml(vId)}"
                            data-src="${embedSrc}" 
                            src="${embedSrc}" 
                            referrerpolicy="strict-origin-when-cross-origin"
                            loading="lazy" 
                            title="AFM#2 Audio Player: ${escapeHtml(songTitle)}" 
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" 
                            allowfullscreen
                        ></iframe>
                        <div class="player-footer">
                            <div class="player-footer-left">
                                <a href="https://www.youtube.com/watch?v=${encodeURIComponent(vId)}" target="_blank" rel="noopener noreferrer" class="link-external">
                                    YouTube ↗
                                </a>
                                <a href="https://music.youtube.com/watch?v=${encodeURIComponent(vId)}" target="_blank" rel="noopener noreferrer" class="link-external">
                                    YT Music ↗
                                </a>
                            </div>
                            <div class="player-footer-right">
                                <button type="button" class="btn-xs btn-secondary btn-edit-media" title="Change recording version or select different link">
                                    <span class="btn-icon">🔁</span> Change Recording
                                </button>
                                <button type="button" class="btn-xs btn-danger-subtle btn-mark-embed-blocked" title="Mark if YouTube player displays an embedding error">
                                    Embedding Blocked?
                                </button>
                            </div>
                        </div>
                    </div>
                `;

                attachYouTubePlayerToPanel(panel, songId, true);
                showToast('Embed player restored.', 'success');
            } catch (err) {
                console.error(err);
            }
            return;
        }

        // 8. Toggle Lyrics Drawer & On-Demand Fetch
        const toggleLyricsBtn = e.target.closest('.btn-toggle-lyrics');
        if (toggleLyricsBtn) {
            e.preventDefault();
            const isOpen = drawer.style.display !== 'none' && drawer.classList.contains('lyrics-active');

            if (isOpen) {
                drawer.classList.remove('lyrics-active');
                if (!drawer.classList.contains('audio-active')) {
                    drawer.style.display = 'none';
                }
                toggleLyricsBtn.classList.remove('active');
            } else {
                drawer.style.display = 'grid';
                drawer.classList.add('lyrics-active');
                toggleLyricsBtn.classList.add('active');

                // Load lyrics if not loaded yet
                loadLyricsForCard(panel, songId, songTitle, songArtist, false);
            }
            return;
        }

        // 9. Lyrics View Tabs (Plain vs Synced)
        const tabBtn = e.target.closest('.lyrics-tab[data-view]');
        if (tabBtn) {
            e.preventDefault();
            const view = tabBtn.dataset.view;
            const box = panel.querySelector('.lyrics-box');
            const data = cardLyricsCache.get(songId);

            if (view === 'synced' && (!data || !data.synced_lyrics)) {
                return;
            }

            box.querySelectorAll('.lyrics-tab[data-view]').forEach(t => t.classList.remove('active'));
            tabBtn.classList.add('active');

            const body = box.querySelector('.lyrics-body');
            if (data) {
                renderLyricsContent(body, data, view);
            }
            return;
        }

        // 10. Find Alternative Lyrics Candidates
        const altBtn = e.target.closest('.btn-lyrics-find-alt');
        if (altBtn) {
            e.preventDefault();
            const box = panel.querySelector('.lyrics-box');
            const body = box.querySelector('.lyrics-body');

            body.innerHTML = `
                <div class="lyrics-state-msg">
                    <span class="lyrics-spinner"></span>
                    <span>Searching LRCLIB candidates...</span>
                </div>
            `;

            try {
                const res = await fetch(`api.php?action=search_lyrics_candidates&title=${encodeURIComponent(songTitle)}&artist=${encodeURIComponent(songArtist)}`);
                const data = await res.json();

                if (!data.success || !data.candidates || data.candidates.length === 0) {
                    body.innerHTML = `
                        <div class="lyrics-state-msg">
                            <span>No alternative candidates found on LRCLIB.</span>
                            <button type="button" class="btn btn-xs btn-secondary btn-retry-lyrics" style="margin-top:0.4rem;">
                                Back to matched lyrics
                            </button>
                        </div>
                    `;
                    return;
                }

                renderCandidatesList(body, songId, data.candidates);

            } catch (err) {
                console.error(err);
                body.innerHTML = `<div class="lyrics-state-msg">Network error searching candidates.</div>`;
            }
            return;
        }

        // 11. Select Specific Candidate
        const candItem = e.target.closest('.candidate-item');
        if (candItem) {
            e.preventDefault();
            const lrclibId = parseInt(candItem.dataset.lrclibId, 10);
            const box = panel.querySelector('.lyrics-box');
            const body = box.querySelector('.lyrics-body');

            body.innerHTML = `
                <div class="lyrics-state-msg">
                    <span class="lyrics-spinner"></span>
                    <span>Loading selected recording lyrics...</span>
                </div>
            `;

            try {
                const res = await fetch('api.php?action=select_lyrics_candidate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({ song_id: songId, lrclib_id: lrclibId })
                });

                const data = await res.json();
                if (!data.success) {
                    showToast(data.error || 'Failed to retrieve candidate.', 'danger');
                    return;
                }

                cardLyricsCache.set(songId, data);
                renderLyricsHeader(box, data);
                renderLyricsContent(body, data, 'plain');
                showToast('Lyrics candidate selected & saved!', 'success');

            } catch (err) {
                console.error(err);
                body.innerHTML = `<div class="lyrics-state-msg">Error loading candidate lyrics.</div>`;
            }
            return;
        }

        // 12. Retry Lyrics
        const retryBtn = e.target.closest('.btn-retry-lyrics');
        if (retryBtn) {
            e.preventDefault();
            loadLyricsForCard(panel, songId, songTitle, songArtist, true);
            return;
        }

        // 13. Jump Player to Timestamped Lyric Line (Bi-directional seeking)
        const syncedLine = e.target.closest('.lyrics-synced-line');
        if (syncedLine) {
            const sec = parseFloat(syncedLine.dataset.seconds);
            if (!isNaN(sec)) {
                seekYouTubePlayer(panel, songId, sec);
                const list = syncedLine.closest('.lyrics-synced-list');
                if (list) {
                    list.querySelectorAll('.lyrics-synced-line').forEach(l => l.classList.remove('active'));
                    syncedLine.classList.add('active');
                }
            }
            return;
        }

        // 14. Auto-Scroll Toggle Button
        const autoScrollBtn = e.target.closest('.btn-autoscroll-toggle');
        if (autoScrollBtn) {
            e.preventDefault();
            autoScrollBtn.classList.toggle('active');
            const isActive = autoScrollBtn.classList.contains('active');
            autoScrollBtn.innerHTML = `<span class="dot">●</span> Auto-scroll: ${isActive ? 'ON' : 'OFF'}`;
            return;
        }
    });
}

/**
 * Load lyrics on-demand via API
 */
async function loadLyricsForCard(panel, songId, title, artist, forceRefresh = false) {
    const box = panel.querySelector('.lyrics-box');
    const body = box.querySelector('.lyrics-body');

    // Check in-memory cache
    if (!forceRefresh && cardLyricsCache.has(songId)) {
        const cached = cardLyricsCache.get(songId);
        renderLyricsHeader(box, cached);
        renderLyricsContent(body, cached, 'plain');
        return;
    }

    body.innerHTML = `
        <div class="lyrics-state-msg">
            <span class="lyrics-spinner"></span>
            <span>Fetching lyrics from LRCLIB...</span>
        </div>
    `;

    try {
        const refreshParam = forceRefresh ? '&refresh=1' : '';
        const res = await fetch(`api.php?action=get_lyrics&song_id=${songId}&title=${encodeURIComponent(title)}&artist=${encodeURIComponent(artist)}${refreshParam}`);
        const data = await res.json();

        // 1. Success Exact or Cached Match
        if (data.success && (data.plain_lyrics || data.synced_lyrics || data.instrumental)) {
            cardLyricsCache.set(songId, data);
            renderLyricsHeader(box, data);
            renderLyricsContent(body, data, 'plain');
            return;
        }

        // 2. Ambiguous Candidates Found
        if (data.match_type === 'candidates' && Array.isArray(data.candidates)) {
            renderCandidatesList(body, songId, data.candidates);
            return;
        }

        // 3. Rate Limited / Overloaded / Not Found
        const googleUrl = 'https://www.google.com/search?q=' + encodeURIComponent(`${title} ${artist} lyrics`);
        const geniusUrl = 'https://genius.com/search?q=' + encodeURIComponent(`${title} ${artist}`);

        if (data.status === 'overloaded' || data.status === 'rate_limited') {
            body.innerHTML = `
                <div class="lyrics-state-msg">
                    <span>⏳ ${escapeHtml(data.message || 'Lyrics service is busy.')}</span>
                    <div style="display:flex; gap:0.4rem; margin-top:0.4rem;">
                        <button type="button" class="btn btn-xs btn-primary-subtle btn-retry-lyrics">Retry LRCLIB</button>
                        <a href="${googleUrl}" target="_blank" rel="noopener noreferrer" class="link-external">Search Google ↗</a>
                    </div>
                </div>
            `;
            return;
        }

        // Not Found
        body.innerHTML = `
            <div class="lyrics-state-msg">
                <span>🔍 No lyrics match found on LRCLIB.</span>
                <span class="text-xs text-muted">Audition scoring remains fully accessible.</span>
                <div style="display:flex; gap:0.5rem; margin-top:0.5rem;">
                    <a href="${googleUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-xs">Google Lyrics ↗</a>
                    <a href="${geniusUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-xs">Genius ↗</a>
                    <button type="button" class="btn btn-xs btn-lyrics-find-alt">Search Variants</button>
                </div>
            </div>
        `;

    } catch (err) {
        console.error('Fetch Lyrics Error:', err);
        const googleUrl = 'https://www.google.com/search?q=' + encodeURIComponent(`${title} ${artist} lyrics`);
        body.innerHTML = `
            <div class="lyrics-state-msg">
                <span>Network error loading lyrics.</span>
                <div style="display:flex; gap:0.4rem; margin-top:0.4rem;">
                    <button type="button" class="btn btn-xs btn-retry-lyrics">Retry</button>
                    <a href="${googleUrl}" target="_blank" class="link-external">Google ↗</a>
                </div>
            </div>
        `;
    }
}

/**
 * Render Header with Attribution and Tabs
 */
function renderLyricsHeader(box, data) {
    const attr = box.querySelector('.lyrics-attribution');
    if (!attr) return;

    let text = `Lyrics via <a href="https://lrclib.net" target="_blank" rel="noopener noreferrer">LRCLIB</a>`;
    if (data.album_name) {
        text += ` • <em>${escapeHtml(data.album_name)}</em>`;
    }
    if (data.duration) {
        const mins = Math.floor(data.duration / 60);
        const secs = Math.floor(data.duration % 60);
        text += ` • ${mins}:${secs < 10 ? '0' : ''}${secs}`;
    }
    if (data.synced_lyrics) {
        text += ` • <button type="button" class="btn-autoscroll-toggle active" title="Toggle automatic lyrics scrolling as music plays"><span class="dot">●</span> Auto-scroll: ON</button>`;
    }

    attr.innerHTML = text;

    // Enable/disable synced tab
    const syncedTab = box.querySelector('.lyrics-tab[data-view="synced"]');
    if (syncedTab) {
        const hasSynced = Boolean(data.synced_lyrics && data.synced_lyrics.trim().length > 0);
        syncedTab.disabled = !hasSynced;
        syncedTab.classList.toggle('disabled', !hasSynced);
        syncedTab.style.opacity = hasSynced ? '1' : '0.4';
        syncedTab.style.cursor = hasSynced ? 'pointer' : 'not-allowed';
        syncedTab.title = hasSynced ? 'View timestamped lyrics' : 'Timed lyrics not available for this recording';
    }
}

/**
 * Render Lyrics Content (Plain vs Synced vs Instrumental)
 */
function renderLyricsContent(body, data, view = 'plain') {
    if (data.instrumental) {
        body.className = 'lyrics-body';
        body.innerHTML = `
            <div class="lyrics-state-msg">
                <span style="font-size: 1.5rem;">🎼</span>
                <strong style="color: var(--text-primary);">Instrumental Recording</strong>
                <span class="text-xs text-muted">This piece has been identified as an instrumental performance with no lyrical vocals.</span>
            </div>
        `;
        return;
    }

    if (view === 'synced' && data.synced_lyrics) {
        const lines = parseSyncedLyrics(data.synced_lyrics);
        body.className = 'lyrics-body';
        let html = '<div class="lyrics-synced-list">';
        lines.forEach(l => {
            html += `
                <div class="lyrics-synced-line" data-seconds="${l.seconds}" title="Click timestamp to jump player">
                    <span class="lyrics-synced-time">[${escapeHtml(l.timeStr)}]</span>
                    <span class="lyrics-synced-text">${escapeHtml(l.text)}</span>
                </div>
            `;
        });
        html += '</div>';
        body.innerHTML = html;
        return;
    }

    // Default: Plain lyrics
    body.className = 'lyrics-body plain';
    let plainText = data.plain_lyrics;
    if (!plainText && data.synced_lyrics) {
        // Cleanly strip timestamp brackets [mm:ss.xx] so plain text view is readable
        plainText = data.synced_lyrics.replace(/^\[\d{2}:\d{2}(?:\.\d+)?\]\s*/gm, '');
    }
    body.textContent = plainText || 'No plain lyrics text available.';
}

/**
 * Render Ambiguous Candidate List with Echo Music Ranking Heuristics
 */
function renderCandidatesList(body, songId, candidates) {
    let html = `
        <div style="font-size:0.775rem; color:#818cf8; font-weight:700; margin-bottom:0.5rem; display:flex; justify-content:space-between; align-items:center;">
            <span>Multiple recordings found. Ranked by match accuracy:</span>
        </div>
        <div class="lyrics-candidates-list">
    `;

    candidates.forEach((c, idx) => {
        let durStr = '';
        if (c.duration) {
            const m = Math.floor(c.duration / 60);
            const s = Math.floor(c.duration % 60);
            durStr = ` (${m}:${s < 10 ? '0' : ''}${s})`;
        }
        if (c.duration_delta !== null && c.duration_delta !== undefined) {
            durStr += ` [Δ ${c.duration_delta}s]`;
        }

        const isTop = idx === 0;
        const syncedBadge = c.has_synced ? '⏱ Synced' : '📄 Plain';
        const topBadge = isTop ? '<span class="badge" style="background:rgba(99,102,241,0.25); color:#a5b4fc; font-size:0.65rem; margin-right:4px;">★ Best Match</span>' : '';

        html += `
            <div class="candidate-item" data-lrclib-id="${c.id}" title="Use this lyrics version" style="${isTop ? 'border-color:rgba(129,140,248,0.5);' : ''}">
                <div class="candidate-meta">
                    <div class="candidate-title">${topBadge}${escapeHtml(c.track_name)}</div>
                    <div class="candidate-sub">
                        ${escapeHtml(c.artist_name)} • ${escapeHtml(c.album_name || 'Single')}${durStr}
                    </div>
                </div>
                <div style="font-size:0.7rem; color:var(--text-muted); text-align:right;">
                    <span class="badge" style="font-size:0.68rem; padding:0.15rem 0.4rem;">${syncedBadge}</span>
                    <div style="color:var(--primary); font-weight:700; margin-top:2px;">Select &rarr;</div>
                </div>
            </div>
        `;
    });

    html += '</div>';
    body.className = 'lyrics-body';
    body.innerHTML = html;
}

/**
 * Parse LRCLIB Synced Lyrics Format [mm:ss.xx] Lyrics
 */
function parseSyncedLyrics(lrcText) {
    if (!lrcText) return [];
    const lines = lrcText.split('\n');
    const result = [];
    const regex = /^\[(\d{2}):(\d{2}(?:\.\d+)?)\](.*)$/;

    lines.forEach(line => {
        const match = regex.exec(line.trim());
        if (match) {
            const minutes = parseInt(match[1], 10);
            const seconds = parseFloat(match[2]);
            const totalSeconds = minutes * 60 + seconds;
            const text = match[3].trim();
            if (text) {
                result.push({
                    timeStr: `${match[1]}:${match[2].substring(0, 2)}`,
                    seconds: totalSeconds,
                    text: text
                });
            }
        }
    });

    return result;
}

/**
 * Global Quick Lyrics Modal (Accessible across Library, Discovery & All Pages)
 */
function initGlobalQuickLyricsModal() {
    const modal = document.getElementById('globalLyricsModal');
    if (!modal) return;

    const titleEl = document.getElementById('globalLyricsTitle');
    const artistEl = document.getElementById('globalLyricsArtist');
    const bodyEl = document.getElementById('globalLyricsBody');
    const ytLink = document.getElementById('globalLyricsYtLink');
    const ytmLink = document.getElementById('globalLyricsYtmLink');
    const plainTab = document.getElementById('globalLyricsPlainTab');
    const syncedTab = document.getElementById('globalLyricsSyncedTab');
    const closeBtn = document.getElementById('closeGlobalLyricsModal');
    const dismissBtn = document.getElementById('btnDismissGlobalLyrics');

    let currentLyricsData = null;
    let currentSongId = 0;

    function closeModal() {
        modal.classList.remove('active');
        modal.style.display = 'none';
        currentLyricsData = null;
        currentSongId = 0;
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (dismissBtn) dismissBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    if (plainTab) {
        plainTab.addEventListener('click', () => {
            plainTab.classList.add('active');
            if (syncedTab) syncedTab.classList.remove('active');
            if (currentLyricsData) {
                renderLyricsContent(bodyEl, currentLyricsData, 'plain');
            }
        });
    }

    if (syncedTab) {
        syncedTab.addEventListener('click', () => {
            if (syncedTab.disabled || !currentLyricsData || !currentLyricsData.synced_lyrics) {
                return;
            }
            syncedTab.classList.add('active');
            if (plainTab) plainTab.classList.remove('active');
            renderLyricsContent(bodyEl, currentLyricsData, 'synced');
        });
    }

    // Candidate selection inside quick lyrics modal
    bodyEl.addEventListener('click', async (e) => {
        const candItem = e.target.closest('.candidate-item');
        if (!candItem) return;

        e.preventDefault();
        const lrclibId = parseInt(candItem.dataset.lrclibId, 10);
        if (!lrclibId || !currentSongId) return;

        bodyEl.innerHTML = `
            <div class="lyrics-state-msg">
                <span class="lyrics-spinner"></span>
                <span>Loading selected recording lyrics...</span>
            </div>
        `;

        try {
            const res = await fetch('api.php?action=select_lyrics_candidate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({ song_id: currentSongId, lrclib_id: lrclibId })
            });

            const data = await res.json();
            if (!data.success) {
                showToast(data.error || 'Failed to retrieve candidate.', 'danger');
                return;
            }

            currentLyricsData = data;
            cardLyricsCache.set(currentSongId, data);

            renderLyricsContent(bodyEl, data, 'plain');
            if (plainTab) plainTab.classList.add('active');
            if (syncedTab) {
                syncedTab.classList.remove('active');
                const hasSynced = Boolean(data.synced_lyrics && data.synced_lyrics.trim().length > 0);
                syncedTab.disabled = !hasSynced;
                syncedTab.classList.toggle('disabled', !hasSynced);
                syncedTab.style.opacity = hasSynced ? '1' : '0.4';
                syncedTab.style.cursor = hasSynced ? 'pointer' : 'not-allowed';
                syncedTab.title = hasSynced ? 'View timestamped lyrics' : 'No timestamped lyrics available';
            }
            showToast('Lyrics candidate selected & saved!', 'success');
        } catch (err) {
            console.error('Candidate selection error:', err);
            bodyEl.innerHTML = `<div class="lyrics-state-msg">Error loading candidate lyrics.</div>`;
        }
    });

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.btn-lyrics-quick-view, .btn-quick-lyrics, .btn-shelf-lyrics');
        if (!btn) return;

        e.preventDefault();
        const row = btn.closest('tr');
        const songId = parseInt(btn.dataset.songId || row?.dataset?.songId, 10);
        const title = btn.dataset.songTitle || row?.dataset?.songTitle || 'Song';
        const artist = btn.dataset.songArtist || row?.dataset?.songArtist || '';
        currentSongId = songId;

        titleEl.textContent = title;
        artistEl.textContent = artist;

        const cleanArtist = (artist !== 'Unknown Artist') ? artist : '';
        const q = encodeURIComponent(`${title} ${cleanArtist}`);
        if (ytLink) ytLink.href = `https://www.youtube.com/results?search_query=${q}+official+audio`;
        if (ytmLink) ytmLink.href = `https://music.youtube.com/search?q=${q}`;

        if (plainTab) plainTab.classList.add('active');
        if (syncedTab) {
            syncedTab.classList.remove('active');
            syncedTab.disabled = true;
            syncedTab.classList.add('disabled');
            syncedTab.style.opacity = '0.4';
            syncedTab.style.cursor = 'not-allowed';
            syncedTab.title = 'Checking timestamped lyrics...';
        }

        bodyEl.className = 'lyrics-body plain';
        bodyEl.innerHTML = `
            <div class="lyrics-state-msg">
                <span class="lyrics-spinner"></span>
                <span>Fetching lyrics from LRCLIB...</span>
            </div>
        `;

        modal.style.display = 'flex';
        modal.classList.add('active');

        try {
            const res = await fetch(`api.php?action=get_lyrics&song_id=${songId}&title=${encodeURIComponent(title)}&artist=${encodeURIComponent(artist)}`);
            const data = await res.json();
            currentLyricsData = data;

            if (data.success && (data.plain_lyrics || data.synced_lyrics || data.instrumental)) {
                cardLyricsCache.set(songId, data);
                renderLyricsContent(bodyEl, data, 'plain');
                if (syncedTab) {
                    const hasSynced = Boolean(data.synced_lyrics && data.synced_lyrics.trim().length > 0);
                    syncedTab.disabled = !hasSynced;
                    syncedTab.classList.toggle('disabled', !hasSynced);
                    syncedTab.style.opacity = hasSynced ? '1' : '0.4';
                    syncedTab.style.cursor = hasSynced ? 'pointer' : 'not-allowed';
                    syncedTab.title = hasSynced ? 'View timestamped lyrics' : 'No timestamped lyrics available';
                }
            } else if (data.match_type === 'candidates' && Array.isArray(data.candidates) && data.candidates.length > 0) {
                renderCandidatesList(bodyEl, songId, data.candidates);
                if (syncedTab) {
                    syncedTab.disabled = true;
                    syncedTab.classList.add('disabled');
                    syncedTab.style.opacity = '0.4';
                    syncedTab.style.cursor = 'not-allowed';
                    syncedTab.title = 'Select a lyrics recording below';
                }
            } else if (data.status === 'overloaded' || data.status === 'rate_limited') {
                bodyEl.innerHTML = `
                    <div class="lyrics-state-msg">
                        <span>⏳ ${escapeHtml(data.message || 'Lyrics service is busy. Please try again in a moment.')}</span>
                    </div>
                `;
            } else {
                const googleSearch = `https://www.google.com/search?q=${encodeURIComponent(title + ' ' + artist + ' lyrics')}`;
                bodyEl.innerHTML = `
                    <div class="lyrics-state-msg">
                        <span>🔍 No lyrics match found on LRCLIB.</span>
                        <div style="margin-top:0.5rem;">
                            <a href="${googleSearch}" target="_blank" rel="noopener noreferrer" class="btn btn-xs btn-primary-subtle">
                                Search Google Lyrics ↗
                            </a>
                        </div>
                    </div>
                `;
            }
        } catch (err) {
            console.error('Quick lyrics error:', err);
            bodyEl.innerHTML = `<div class="lyrics-state-msg">Error connecting to lyrics service.</div>`;
        }
    });
}

/**
 * ==========================================================================
 * AFM#2 — Appearance & Theme Customization Modal
 * Controls Midnight Ember, Vintage Paper, Oceanic, Quiet Minimal themes,
 * atmospheric photography, artwork blur, gradients, opacity, and layouts.
 * ==========================================================================
 */
function initAppearanceModal() {
    const modal = document.getElementById('appearanceModal');
    const openBtn = document.getElementById('btnOpenAppearance');
    const closeBtn = document.getElementById('closeAppearanceModal');
    const cancelBtn = document.getElementById('btnCancelAppearance');
    const saveBtn = document.getElementById('btnSaveAppearance');
    const resetBtn = document.getElementById('btnResetAppearance');
    const compactToggle = document.getElementById('compactLayoutToggle');

    if (!modal || !openBtn) return;

    let initialPrefs = {
        theme: 'midnight_ember',
        bg_style: 'atmospheric',
        player_layout: 'expanded',
        bg_overlay_opacity: 70
    };

    function readDomPrefs() {
        const theme = document.documentElement.getAttribute('data-theme') || 'midnight_ember';
        const bgStyle = document.documentElement.getAttribute('data-bg-style') || 'atmospheric';
        const layout = document.documentElement.getAttribute('data-player-layout') || 'expanded';
        const rawOpacity = document.documentElement.style.getPropertyValue('--bg-overlay-opacity');
        const opacity = rawOpacity ? Math.round(parseFloat(rawOpacity) * 100) : 70;
        return { theme, bg_style: bgStyle, player_layout: layout, bg_overlay_opacity: opacity };
    }

    function syncModalUI(prefs) {
        document.querySelectorAll('.curated-theme-card').forEach(card => {
            card.classList.toggle('active', card.getAttribute('data-theme-val') === prefs.theme);
        });

        if (compactToggle) {
            compactToggle.checked = (prefs.player_layout === 'compact');
        }
    }

    function applyDomPrefs(prefs) {
        document.documentElement.setAttribute('data-theme', prefs.theme);
        document.documentElement.setAttribute('data-bg-style', prefs.bg_style);
        document.documentElement.setAttribute('data-player-layout', prefs.player_layout);
        const dec = (prefs.bg_overlay_opacity / 100).toFixed(2);
        document.documentElement.style.setProperty('--bg-overlay-opacity', dec);
    }

    // Open modal
    openBtn.addEventListener('click', (e) => {
        e.preventDefault();
        initialPrefs = readDomPrefs();
        syncModalUI(initialPrefs);
        modal.classList.add('active');
    });

    // Close / Cancel modal
    function closeModal(revert = false) {
        if (revert) {
            applyDomPrefs(initialPrefs);
        }
        modal.classList.remove('active');
    }

    if (closeBtn) closeBtn.addEventListener('click', () => closeModal(true));
    if (cancelBtn) cancelBtn.addEventListener('click', () => closeModal(true));

    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal(true);
    });

    // Live theme card selection with instant live preview of colors, background, and opacity
    document.querySelectorAll('.curated-theme-card').forEach(card => {
        card.addEventListener('click', () => {
            const theme = card.getAttribute('data-theme-val');
            const bgStyle = card.getAttribute('data-bg-val') || 'atmospheric';
            const opacity = parseInt(card.getAttribute('data-opacity-val') || '70', 10);

            document.querySelectorAll('.curated-theme-card').forEach(c => c.classList.remove('active'));
            card.classList.add('active');

            const currentLayout = compactToggle && compactToggle.checked ? 'compact' : 'expanded';
            applyDomPrefs({
                theme: theme,
                bg_style: bgStyle,
                player_layout: currentLayout,
                bg_overlay_opacity: opacity
            });
        });
    });

    // Compact layout toggle
    if (compactToggle) {
        compactToggle.addEventListener('change', () => {
            const layout = compactToggle.checked ? 'compact' : 'expanded';
            document.documentElement.setAttribute('data-player-layout', layout);
        });
    }

    // Save preferences
    if (saveBtn) {
        saveBtn.addEventListener('click', async () => {
            const current = readDomPrefs();
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';

            try {
                const res = await fetch('api.php?action=save_preferences', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify(current)
                });
                const data = await res.json();
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save Theme';

                if (data.success) {
                    initialPrefs = current;
                    showToast('Theme updated successfully!', 'success');
                    closeModal(false);
                } else {
                    showToast(data.error || 'Failed to save theme.', 'danger');
                }
            } catch (err) {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save Theme';
                console.error('Save appearance error:', err);
                showToast('Network error while saving theme.', 'danger');
            }
        });
    }

    // Reset preferences to default
    if (resetBtn) {
        resetBtn.addEventListener('click', async () => {
            if (!confirm('Reset to default Midnight Ember theme?')) return;

            resetBtn.disabled = true;
            try {
                const res = await fetch('api.php?action=reset_preferences', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    }
                });
                const data = await res.json();
                resetBtn.disabled = false;

                if (data.success) {
                    const defs = data.preferences || {
                        theme: 'midnight_ember',
                        bg_style: 'atmospheric',
                        player_layout: 'expanded',
                        bg_overlay_opacity: 70
                    };
                    applyDomPrefs(defs);
                    initialPrefs = defs;
                    syncModalUI(defs);
                    showToast('Restored default Midnight Ember theme.', 'success');
                    closeModal(false);
                } else {
                    showToast(data.error || 'Failed to reset theme.', 'danger');
                }
            } catch (err) {
                resetBtn.disabled = false;
                console.error('Reset appearance error:', err);
                showToast('Network error while resetting theme.', 'danger');
            }
        });
    }
}

/**
 * ==========================================================================
 * AFM#2 — Album Artwork Discovery & Fallback Picker Modal
 * Discovers Cover Art Archive (MusicBrainz), Openverse CC images, and presets.
 * Remembers confirmed user selection.
 * ==========================================================================
 */
function initArtworkPickerModal() {
    const modal = document.getElementById('artworkModal');
    const closeBtn = document.getElementById('closeArtworkModal');
    const dismissBtn = document.getElementById('btnCloseArtworkModal');
    const statusEl = document.getElementById('artworkSearchStatus');
    const gridEl = document.getElementById('artworkCandidatesGrid');
    const customInput = document.getElementById('customArtworkUrlInput');
    const applyCustomBtn = document.getElementById('btnApplyCustomArtwork');
    const removeBtn = document.getElementById('btnRemoveArtwork');

    if (!modal) return;

    let activeSongId = 0;
    let activeCardEl = null;

    // Trigger buttons on scorecards or cards
    document.addEventListener('click', async (e) => {
        const trigger = e.target.closest('.btn-trigger-artwork-picker');
        if (!trigger) return;

        e.preventDefault();
        const songId = parseInt(trigger.getAttribute('data-song-id'), 10);
        const title = trigger.getAttribute('data-song-title') || '';
        const artist = trigger.getAttribute('data-song-artist') || '';

        activeSongId = songId;
        activeCardEl = trigger.closest('.scorecard-card') || trigger.closest('.scorecard-wrapper');

        const titleEl = document.getElementById('artworkModalTitle');
        const descEl = document.getElementById('artworkModalSongDesc');
        if (titleEl) titleEl.textContent = `Artwork for "${title}"`;
        if (descEl) descEl.textContent = artist ? `By ${artist}` : 'AFM#2 Contender';

        if (statusEl) {
            statusEl.style.display = 'flex';
            statusEl.innerHTML = `
                <span class="lyrics-spinner"></span>
                <span>Searching Cover Art Archive &amp; Openverse...</span>
            `;
        }
        if (gridEl) {
            gridEl.style.display = 'none';
            gridEl.innerHTML = '';
        }
        if (customInput) customInput.value = '';
        if (removeBtn) removeBtn.style.display = 'inline-block';

        modal.classList.add('active');

        try {
            const res = await fetch(`api.php?action=search_artwork_candidates&title=${encodeURIComponent(title)}&artist=${encodeURIComponent(artist)}`);
            const data = await res.json();

            if (statusEl) statusEl.style.display = 'none';

            if (data.success && data.candidates && data.candidates.length > 0) {
                renderArtworkCandidates(data.candidates, title, artist);
            } else {
                if (statusEl) {
                    statusEl.style.display = 'flex';
                    statusEl.innerHTML = `<span>No candidate covers found from external archives. You can enter a custom image URL below.</span>`;
                }
            }
        } catch (err) {
            console.error('Artwork search error:', err);
            if (statusEl) {
                statusEl.style.display = 'flex';
                statusEl.innerHTML = `<span>Error retrieving candidates. You can enter a custom image URL below.</span>`;
            }
        }
    });

    function renderArtworkCandidates(candidates, title, artist) {
        if (!gridEl) return;
        gridEl.innerHTML = '';
        gridEl.style.display = 'grid';

        candidates.forEach(cand => {
            const card = document.createElement('div');
            card.className = 'candidate-artwork-card';

            const sourceLabel = cand.source === 'musicbrainz' 
                ? 'Cover Art Archive' 
                : (cand.source === 'openverse' ? 'Openverse CC' : 'Atmospheric Preset');

            const licenseLabel = cand.license || 'License Attached';
            const thumbUrl = cand.thumbnail_url || cand.image_url;

            card.innerHTML = `
                <img src="${escapeHtml(thumbUrl)}" 
                     alt="${escapeHtml(cand.title || title)}" 
                     class="candidate-thumb" 
                     loading="lazy"
                     onerror="this.onerror=null; this.src='assets/img/artwork-fallback.svg';">
                <div class="candidate-card-body">
                    <div class="candidate-card-source">
                        <span>${escapeHtml(sourceLabel)}</span>
                        <span>${escapeHtml(licenseLabel)}</span>
                    </div>
                    <div class="candidate-card-title" title="${escapeHtml(cand.title || title)}">
                        ${escapeHtml(cand.title || title)}
                    </div>
                    <div class="candidate-card-creator">
                        ${escapeHtml(cand.creator || artist || 'Music Archive')}
                    </div>
                    <button type="button" class="btn-select-artwork">
                        Confirm This Artwork
                    </button>
                </div>
            `;

            const selectBtn = card.querySelector('.btn-select-artwork');
            selectBtn.addEventListener('click', () => confirmArtworkSelection(cand));

            gridEl.appendChild(card);
        });
    }

    async function confirmArtworkSelection(artworkData) {
        if (!activeSongId) return;

        try {
            const res = await fetch('api.php?action=confirm_artwork', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({
                    song_id: activeSongId,
                    artwork: artworkData
                })
            });
            const data = await res.json();

            if (data.success) {
                showToast('Album artwork confirmed and saved!', 'success');
                // Update live card on page
                if (activeCardEl) {
                    const img = activeCardEl.querySelector('.tactile-artwork-img');
                    if (img) img.src = artworkData.image_url || artworkData.thumbnail_url;
                }
                modal.classList.remove('active');
            } else {
                showToast(data.error || 'Failed to confirm artwork.', 'danger');
            }
        } catch (err) {
            console.error('Confirm artwork error:', err);
            showToast('Network error while saving confirmed artwork.', 'danger');
        }
    }

    // Custom URL confirmation
    if (applyCustomBtn && customInput) {
        applyCustomBtn.addEventListener('click', () => {
            const url = customInput.value.trim();
            if (!url) {
                showToast('Please enter a valid image URL.', 'danger');
                return;
            }
            confirmArtworkSelection({
                source: 'custom',
                image_url: url,
                thumbnail_url: url,
                title: 'Custom Artwork',
                creator: 'User Specified',
                license: 'Personal Use'
            });
        });
    }

    // Remove artwork
    if (removeBtn) {
        removeBtn.addEventListener('click', async () => {
            if (!activeSongId) return;
            try {
                const res = await fetch('api.php?action=remove_artwork', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({ song_id: activeSongId })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Artwork reset to theme fallback.', 'info');
                    if (activeCardEl) {
                        const img = activeCardEl.querySelector('.tactile-artwork-img');
                        if (img) img.src = 'assets/img/artwork-fallback.svg';
                    }
                    modal.classList.remove('active');
                } else {
                    showToast(data.error || 'Failed to remove artwork.', 'danger');
                }
            } catch (err) {
                console.error('Remove artwork error:', err);
            }
        });
    }

    // Modal closing
    if (closeBtn) closeBtn.addEventListener('click', () => modal.classList.remove('active'));
    if (dismissBtn) dismissBtn.addEventListener('click', () => modal.classList.remove('active'));
    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('active');
    });
}

/**
 * Library Song Management: Add (Manual or YouTube), Edit, and Delete
 */
function initSongManagement() {
    // 1. ADD SONG MODAL
    const addModal = document.getElementById('addSongModal');
    const openAddBtn = document.getElementById('btnOpenAddSongModal');
    const closeAddBtn = document.getElementById('btnCloseAddSongModal');
    const cancelAddBtn = document.getElementById('btnCancelAddSong');
    const submitAddBtn = document.getElementById('btnSubmitAddSong');

    const tabBtnYt = document.getElementById('tabBtnYt');
    const tabBtnManual = document.getElementById('tabBtnManual');
    const paneYt = document.getElementById('paneYt');
    const paneManual = document.getElementById('paneManual');

    const ytExtractUrl = document.getElementById('ytExtractUrl');
    const btnExtractYt = document.getElementById('btnExtractYt');
    const ytExtractLoading = document.getElementById('ytExtractLoading');
    const ytExtractPreview = document.getElementById('ytExtractPreview');
    const ytPreviewThumb = document.getElementById('ytPreviewThumb');
    const ytExtractedTitle = document.getElementById('ytExtractedTitle');
    const ytExtractedArtist = document.getElementById('ytExtractedArtist');
    const ytPreviewChannel = document.getElementById('ytPreviewChannel');
    const ytPreviewVid = document.getElementById('ytPreviewVid');
    const ytDiscoveryStatus = document.getElementById('ytDiscoveryStatus');

    const manualTitle = document.getElementById('manualTitle');
    const manualArtist = document.getElementById('manualArtist');
    const manualYtUrl = document.getElementById('manualYtUrl');
    const manualDiscoveryStatus = document.getElementById('manualDiscoveryStatus');
    const addSongError = document.getElementById('addSongError');

    let activeAddTab = 'yt';

    const ytExistingSongNotice = document.getElementById('ytExistingSongNotice');
    const ytExistingSongTitle = document.getElementById('ytExistingSongTitle');
    const ytExistingSongDesc = document.getElementById('ytExistingSongDesc');

    const manualExistingSongNotice = document.getElementById('manualExistingSongNotice');
    const manualExistingSongTitle = document.getElementById('manualExistingSongTitle');
    const manualExistingSongDesc = document.getElementById('manualExistingSongDesc');

    let currentMatchedSongId = null;

    function resetAddModal() {
        if (ytExtractUrl) ytExtractUrl.value = '';
        if (ytExtractPreview) ytExtractPreview.style.display = 'none';
        if (ytExtractLoading) ytExtractLoading.style.display = 'none';
        if (ytExtractedTitle) ytExtractedTitle.value = '';
        if (ytExtractedArtist) ytExtractedArtist.value = '';
        if (manualTitle) manualTitle.value = '';
        if (manualArtist) manualArtist.value = '';
        if (manualYtUrl) manualYtUrl.value = '';
        if (addSongError) {
            addSongError.style.display = 'none';
            addSongError.textContent = '';
        }
        hideExistingNotice();
        switchAddTab('yt');
    }

    function hideExistingNotice() {
        currentMatchedSongId = null;
        if (ytExistingSongNotice) ytExistingSongNotice.style.display = 'none';
        if (manualExistingSongNotice) manualExistingSongNotice.style.display = 'none';
        if (submitAddBtn) submitAddBtn.textContent = 'Save to Catalogue';
    }

    function showExistingNotice(match, mode = 'yt') {
        currentMatchedSongId = match.song_id;
        const targetNotice = mode === 'yt' ? ytExistingSongNotice : manualExistingSongNotice;
        const targetTitle = mode === 'yt' ? ytExistingSongTitle : manualExistingSongTitle;
        const targetDesc = mode === 'yt' ? ytExistingSongDesc : manualExistingSongDesc;

        if (targetTitle) {
            targetTitle.textContent = `Matches Existing Catalogue Track #${match.song_id}:`;
        }
        if (targetDesc) {
            const artistPart = match.artist && match.artist !== 'Unknown Artist' ? ` by ${match.artist}` : '';
            targetDesc.innerHTML = `<strong>"${match.title}"</strong>${artistPart}. Saving will update this record with your link and review status instead of creating a duplicate.`;
        }
        if (targetNotice) {
            targetNotice.style.display = 'flex';
        }
        if (submitAddBtn) {
            submitAddBtn.textContent = `Save & Update Track #${match.song_id}`;
        }
    }

    let matchCheckTimeout = null;
    function checkExistingTrackMatch(title, artist, mode = 'yt') {
        clearTimeout(matchCheckTimeout);
        const cleanTitle = (title || '').trim();
        if (!cleanTitle) {
            hideExistingNotice();
            return;
        }

        matchCheckTimeout = setTimeout(async () => {
            try {
                const res = await fetch(`api.php?action=find_existing_song&title=${encodeURIComponent(cleanTitle)}&artist=${encodeURIComponent((artist || '').trim())}`);
                const data = await res.json();
                if (data.success && data.match) {
                    showExistingNotice(data.match, mode);
                } else {
                    hideExistingNotice();
                }
            } catch (e) {
                // Ignore background check failure
            }
        }, 250);
    }

    function switchAddTab(tab) {
        activeAddTab = tab;
        if (tabBtnYt && tabBtnManual && paneYt && paneManual) {
            tabBtnYt.classList.toggle('active', tab === 'yt');
            tabBtnManual.classList.toggle('active', tab === 'manual');
            paneYt.style.display = tab === 'yt' ? 'block' : 'none';
            paneManual.style.display = tab === 'manual' ? 'block' : 'none';
        }
        // Re-check duplicate notice for active tab
        if (tab === 'yt') {
            const title = ytExtractedTitle ? ytExtractedTitle.value : '';
            const artist = ytExtractedArtist ? ytExtractedArtist.value : '';
            if (title) checkExistingTrackMatch(title, artist, 'yt');
            else hideExistingNotice();
        } else {
            const title = manualTitle ? manualTitle.value : '';
            const artist = manualArtist ? manualArtist.value : '';
            if (title) checkExistingTrackMatch(title, artist, 'manual');
            else hideExistingNotice();
        }
    }

    if (tabBtnYt) tabBtnYt.addEventListener('click', () => switchAddTab('yt'));
    if (tabBtnManual) tabBtnManual.addEventListener('click', () => switchAddTab('manual'));

    if (openAddBtn && addModal) {
        openAddBtn.addEventListener('click', () => {
            resetAddModal();
            addModal.classList.add('active');
            setTimeout(() => {
                if (ytExtractUrl) ytExtractUrl.focus();
            }, 100);
        });
    }

    if (closeAddBtn && addModal) closeAddBtn.addEventListener('click', () => addModal.classList.remove('active'));
    if (cancelAddBtn && addModal) cancelAddBtn.addEventListener('click', () => addModal.classList.remove('active'));

    // Real-time duplicate checking listeners for user edits
    if (ytExtractedTitle) {
        ytExtractedTitle.addEventListener('input', () => {
            checkExistingTrackMatch(ytExtractedTitle.value, ytExtractedArtist ? ytExtractedArtist.value : '', 'yt');
        });
    }
    if (ytExtractedArtist) {
        ytExtractedArtist.addEventListener('input', () => {
            checkExistingTrackMatch(ytExtractedTitle ? ytExtractedTitle.value : '', ytExtractedArtist.value, 'yt');
        });
    }
    if (manualTitle) {
        manualTitle.addEventListener('input', () => {
            checkExistingTrackMatch(manualTitle.value, manualArtist ? manualArtist.value : '', 'manual');
        });
    }
    if (manualArtist) {
        manualArtist.addEventListener('input', () => {
            checkExistingTrackMatch(manualTitle ? manualTitle.value : '', manualArtist.value, 'manual');
        });
    }

    // Extract details from YouTube link
    async function performYoutubeExtraction(urlOrId) {
        const query = (urlOrId || '').trim();
        if (!query) {
            if (addSongError) {
                addSongError.textContent = 'Please enter a valid YouTube URL or Video ID.';
                addSongError.style.display = 'block';
            }
            return;
        }

        if (addSongError) addSongError.style.display = 'none';
        if (ytExtractLoading) ytExtractLoading.style.display = 'flex';
        if (ytExtractPreview) ytExtractPreview.style.display = 'none';
        hideExistingNotice();
        if (btnExtractYt) btnExtractYt.disabled = true;

        try {
            const res = await fetch(`api.php?action=extract_youtube_info&url=${encodeURIComponent(query)}`);
            const data = await res.json();

            if (ytExtractLoading) ytExtractLoading.style.display = 'none';
            if (btnExtractYt) btnExtractYt.disabled = false;

            if (!data.success) {
                if (addSongError) {
                    addSongError.textContent = data.error || 'Failed to extract YouTube details.';
                    addSongError.style.display = 'block';
                }
                return;
            }

            if (ytExtractedTitle) ytExtractedTitle.value = data.title || '';
            if (ytExtractedArtist) ytExtractedArtist.value = data.artist || '';
            if (ytPreviewChannel) ytPreviewChannel.textContent = `Channel: ${data.channel_title || 'N/A'}`;
            if (ytPreviewVid) ytPreviewVid.textContent = `ID: ${data.video_id}`;
            if (ytPreviewThumb) ytPreviewThumb.src = data.thumbnail_url;

            if (ytExtractPreview) ytExtractPreview.style.display = 'flex';

            // Check if extracted track matches an existing song
            if (data.existing_song) {
                showExistingNotice(data.existing_song, 'yt');
            } else {
                checkExistingTrackMatch(data.title, data.artist, 'yt');
            }

        } catch (err) {
            if (ytExtractLoading) ytExtractLoading.style.display = 'none';
            if (btnExtractYt) btnExtractYt.disabled = false;
            console.error('YouTube extraction error:', err);
            if (addSongError) {
                addSongError.textContent = 'Network error while contacting YouTube.';
                addSongError.style.display = 'block';
            }
        }
    }

    if (btnExtractYt && ytExtractUrl) {
        btnExtractYt.addEventListener('click', () => performYoutubeExtraction(ytExtractUrl.value));
        ytExtractUrl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                performYoutubeExtraction(ytExtractUrl.value);
            }
        });
        ytExtractUrl.addEventListener('paste', () => {
            setTimeout(() => {
                if (ytExtractUrl.value.trim().length > 10) {
                    performYoutubeExtraction(ytExtractUrl.value);
                }
            }, 60);
        });
    }

    // Submit Add Song
    if (submitAddBtn && addModal) {
        submitAddBtn.addEventListener('click', async () => {
            let title = '';
            let artist = '';
            let ytUrl = '';
            let discovery = 'unreviewed';

            if (activeAddTab === 'yt') {
                title = ytExtractedTitle ? ytExtractedTitle.value.trim() : '';
                artist = ytExtractedArtist ? ytExtractedArtist.value.trim() : '';
                ytUrl = ytExtractUrl ? ytExtractUrl.value.trim() : '';
                discovery = ytDiscoveryStatus ? ytDiscoveryStatus.value : 'unreviewed';
            } else {
                title = manualTitle ? manualTitle.value.trim() : '';
                artist = manualArtist ? manualArtist.value.trim() : '';
                ytUrl = manualYtUrl ? manualYtUrl.value.trim() : '';
                discovery = manualDiscoveryStatus ? manualDiscoveryStatus.value : 'unreviewed';
            }

            if (!title) {
                if (addSongError) {
                    addSongError.textContent = 'Song title is required.';
                    addSongError.style.display = 'block';
                }
                return;
            }

            const isUpdate = Boolean(currentMatchedSongId);
            submitAddBtn.disabled = true;
            submitAddBtn.textContent = isUpdate ? 'Updating...' : 'Saving...';

            try {
                const res = await fetch('api.php?action=create_song', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({
                        title: title,
                        artist: artist,
                        youtube_url: ytUrl,
                        discovery_status: discovery
                    })
                });

                const data = await res.json();
                submitAddBtn.disabled = false;
                submitAddBtn.textContent = 'Save to Catalogue';

                if (!data.success) {
                    if (addSongError) {
                        addSongError.textContent = data.error || 'Failed to create song.';
                        addSongError.style.display = 'block';
                    }
                    return;
                }

                showToast(data.message || `Song "${title}" added successfully!`, 'success');
                addModal.classList.remove('active');
                setTimeout(() => {
                    window.location.reload();
                }, 500);

            } catch (err) {
                submitAddBtn.disabled = false;
                submitAddBtn.textContent = 'Save to Catalogue';
                console.error('Add song error:', err);
                if (addSongError) {
                    addSongError.textContent = 'Network error saving song.';
                    addSongError.style.display = 'block';
                }
            }
        });
    }

    // 2. EDIT SONG MODAL
    const editModal = document.getElementById('editSongModal');
    const closeEditBtn = document.getElementById('btnCloseEditSongModal');
    const cancelEditBtn = document.getElementById('btnCancelEditSong');
    const submitEditBtn = document.getElementById('btnSubmitEditSong');
    const editSongId = document.getElementById('editSongId');
    const editSongTitle = document.getElementById('editSongTitle');
    const editSongArtist = document.getElementById('editSongArtist');
    const editSongVideo = document.getElementById('editSongVideo');
    const btnEditFetchYt = document.getElementById('btnEditFetchYt');
    const editSongError = document.getElementById('editSongError');

    document.addEventListener('click', (e) => {
        const editBtn = e.target.closest('.btn-manage-edit');
        if (!editBtn || !editModal) return;

        e.preventDefault();
        const row = editBtn.closest('tr');
        const songId = editBtn.getAttribute('data-song-id') || row?.getAttribute('data-song-id');
        const songTitle = editBtn.getAttribute('data-song-title') || row?.getAttribute('data-song-title') || '';
        const songArtist = editBtn.getAttribute('data-song-artist') || row?.getAttribute('data-song-artist') || '';
        const songVideo = editBtn.getAttribute('data-song-video') || row?.getAttribute('data-song-video') || '';

        if (editSongId) editSongId.value = songId;
        if (editSongTitle) editSongTitle.value = songTitle;
        if (editSongArtist) editSongArtist.value = songArtist;
        if (editSongVideo) editSongVideo.value = songVideo;
        if (editSongError) {
            editSongError.style.display = 'none';
            editSongError.textContent = '';
        }

        editModal.classList.add('active');
        setTimeout(() => {
            if (editSongTitle) editSongTitle.focus();
        }, 100);
    });

    if (closeEditBtn && editModal) closeEditBtn.addEventListener('click', () => editModal.classList.remove('active'));
    if (cancelEditBtn && editModal) cancelEditBtn.addEventListener('click', () => editModal.classList.remove('active'));

    // Auto-fill in edit modal from YouTube link
    if (btnEditFetchYt && editSongVideo) {
        btnEditFetchYt.addEventListener('click', async () => {
            const vid = editSongVideo.value.trim();
            if (!vid) {
                if (editSongError) {
                    editSongError.textContent = 'Please enter a YouTube video URL or ID to auto-fill.';
                    editSongError.style.display = 'block';
                }
                return;
            }

            btnEditFetchYt.disabled = true;
            btnEditFetchYt.textContent = 'Fetching...';

            try {
                const res = await fetch(`api.php?action=extract_youtube_info&url=${encodeURIComponent(vid)}`);
                const data = await res.json();
                btnEditFetchYt.disabled = false;
                btnEditFetchYt.textContent = 'Auto-Fill';

                if (data.success) {
                    if (data.title && editSongTitle) editSongTitle.value = data.title;
                    if (data.artist && editSongArtist) editSongArtist.value = data.artist;
                    showToast('Title and artist auto-filled from YouTube video.', 'info');
                } else if (editSongError) {
                    editSongError.textContent = data.error || 'Failed to fetch details from YouTube.';
                    editSongError.style.display = 'block';
                }
            } catch (err) {
                btnEditFetchYt.disabled = false;
                btnEditFetchYt.textContent = 'Auto-Fill';
                console.error('Edit Auto-Fill error:', err);
            }
        });
    }

    // Submit Edit Song
    if (submitEditBtn && editModal) {
        submitEditBtn.addEventListener('click', async () => {
            const songId = editSongId ? parseInt(editSongId.value, 10) : 0;
            const title = editSongTitle ? editSongTitle.value.trim() : '';
            const artist = editSongArtist ? editSongArtist.value.trim() : '';
            const video = editSongVideo ? editSongVideo.value.trim() : '';

            if (songId <= 0 || !title) {
                if (editSongError) {
                    editSongError.textContent = 'Song title cannot be empty.';
                    editSongError.style.display = 'block';
                }
                return;
            }

            submitEditBtn.disabled = true;
            submitEditBtn.textContent = 'Saving...';

            try {
                const res = await fetch('api.php?action=update_song', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({
                        song_id: songId,
                        title: title,
                        artist: artist,
                        youtube_url: video
                    })
                });

                const data = await res.json();
                submitEditBtn.disabled = false;
                submitEditBtn.textContent = 'Save Changes';

                if (!data.success) {
                    if (editSongError) {
                        editSongError.textContent = data.error || 'Failed to update song.';
                        editSongError.style.display = 'block';
                    }
                    return;
                }

                showToast(data.message || `Song #${songId} updated successfully!`, 'success');
                editModal.classList.remove('active');

                // Invalidate lyrics cache for this updated song
                cardLyricsCache.delete(songId);

                // Update row in DOM
                const row = document.querySelector(`tr[data-song-id="${songId}"]`);
                if (row) {
                    row.setAttribute('data-song-title', title);
                    row.setAttribute('data-song-artist', artist);
                    if (video) row.setAttribute('data-song-video', video);

                    const titleEl = row.querySelector('.song-title-text');
                    if (titleEl) {
                        titleEl.textContent = title;
                        titleEl.title = title;
                    }

                    const artistEl = row.querySelector('.song-artist-cell');
                    if (artistEl) {
                        artistEl.innerHTML = artist ? escapeHtml(artist) : '<span class="artist-empty">&mdash; Unknown Artist &mdash;</span>';
                    }

                    const editBtn = row.querySelector('.btn-manage-edit');
                    if (editBtn) {
                        editBtn.setAttribute('data-song-title', title);
                        editBtn.setAttribute('data-song-artist', artist);
                        if (video) editBtn.setAttribute('data-song-video', video);
                    }

                    // Update all lyrics trigger buttons in the row with fresh metadata
                    row.querySelectorAll('.btn-lyrics-quick-view, .btn-quick-lyrics, .btn-shelf-lyrics').forEach(btn => {
                        btn.setAttribute('data-song-title', title);
                        btn.setAttribute('data-song-artist', artist || '');
                        btn.dataset.songTitle = title;
                        btn.dataset.songArtist = artist || '';
                    });
                } else {
                    window.location.reload();
                }

            } catch (err) {
                submitEditBtn.disabled = false;
                submitEditBtn.textContent = 'Save Changes';
                console.error('Update song error:', err);
                if (editSongError) {
                    editSongError.textContent = 'Network error updating song.';
                    editSongError.style.display = 'block';
                }
            }
        });
    }

    // 3. DELETE SONG MODAL
    const deleteModal = document.getElementById('deleteSongModal');
    const closeDeleteBtn = document.getElementById('btnCloseDeleteSongModal');
    const cancelDeleteBtn = document.getElementById('btnCancelDeleteSong');
    const confirmDeleteBtn = document.getElementById('btnConfirmDeleteSong');
    const deleteSongId = document.getElementById('deleteSongId');
    const deleteDisplayId = document.getElementById('deleteDisplayId');
    const deleteDisplayTitle = document.getElementById('deleteDisplayTitle');
    const deleteDisplayArtist = document.getElementById('deleteDisplayArtist');
    const deleteSongError = document.getElementById('deleteSongError');

    document.addEventListener('click', (e) => {
        const delBtn = e.target.closest('.btn-manage-delete');
        if (!delBtn || !deleteModal) return;

        e.preventDefault();
        const row = delBtn.closest('tr');
        const songId = delBtn.getAttribute('data-song-id') || row?.getAttribute('data-song-id');
        const songTitle = delBtn.getAttribute('data-song-title') || row?.getAttribute('data-song-title') || 'Song';
        const songArtist = delBtn.getAttribute('data-song-artist') || row?.getAttribute('data-song-artist') || 'Unknown Artist';

        if (deleteSongId) deleteSongId.value = songId;
        if (deleteDisplayId) deleteDisplayId.textContent = `#${songId}`;
        if (deleteDisplayTitle) deleteDisplayTitle.textContent = songTitle;
        if (deleteDisplayArtist) deleteDisplayArtist.textContent = songArtist || 'Unknown Artist';
        if (deleteSongError) {
            deleteSongError.style.display = 'none';
            deleteSongError.textContent = '';
        }

        deleteModal.classList.add('active');
    });

    if (closeDeleteBtn && deleteModal) closeDeleteBtn.addEventListener('click', () => deleteModal.classList.remove('active'));
    if (cancelDeleteBtn && deleteModal) cancelDeleteBtn.addEventListener('click', () => deleteModal.classList.remove('active'));

    // Confirm Delete
    if (confirmDeleteBtn && deleteModal) {
        confirmDeleteBtn.addEventListener('click', async () => {
            const songId = deleteSongId ? parseInt(deleteSongId.value, 10) : 0;
            if (songId <= 0) return;

            confirmDeleteBtn.disabled = true;
            confirmDeleteBtn.textContent = 'Deleting...';

            try {
                const res = await fetch('api.php?action=delete_song', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({ song_id: songId })
                });

                const data = await res.json();
                confirmDeleteBtn.disabled = false;
                confirmDeleteBtn.textContent = 'Permanently Delete';

                if (!data.success) {
                    if (deleteSongError) {
                        deleteSongError.textContent = data.error || 'Failed to delete song.';
                        deleteSongError.style.display = 'block';
                    }
                    return;
                }

                showToast(data.message || `Song #${songId} was permanently deleted.`, 'success');
                deleteModal.classList.remove('active');

                // Animate row removal from table
                const row = document.querySelector(`tr[data-song-id="${songId}"]`);
                if (row) {
                    row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                    row.style.opacity = '0';
                    row.style.transform = 'scaleY(0.5)';
                    setTimeout(() => {
                        row.remove();
                    }, 300);
                }

            } catch (err) {
                confirmDeleteBtn.disabled = false;
                confirmDeleteBtn.textContent = 'Permanently Delete';
                console.error('Delete song error:', err);
                if (deleteSongError) {
                    deleteSongError.textContent = 'Network error deleting song.';
                    deleteSongError.style.display = 'block';
                }
            }
        });
    }

    // 4. MULTI-SELECT & BULK DELETION IN LIBRARY
    const selectAllSongs = document.getElementById('selectAllSongs');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const bulkSelectedCount = document.getElementById('bulkSelectedCount');
    const btnBulkDelete = document.getElementById('btnBulkDelete');
    const btnBulkClear = document.getElementById('btnBulkClear');
    const bulkDeleteModal = document.getElementById('bulkDeleteModal');
    const closeBulkDeleteBtn = document.getElementById('btnCloseBulkDeleteModal');
    const cancelBulkDeleteBtn = document.getElementById('btnCancelBulkDelete');
    const confirmBulkDeleteBtn = document.getElementById('btnConfirmBulkDelete');
    const bulkDeleteTotalCount = document.getElementById('bulkDeleteTotalCount');
    const bulkDeleteListPreview = document.getElementById('bulkDeleteListPreview');
    const bulkDeleteError = document.getElementById('bulkDeleteError');

    function updateBulkSelectionState() {
        const checkboxes = document.querySelectorAll('.song-select-checkbox');
        const checked = document.querySelectorAll('.song-select-checkbox:checked');
        const count = checked.length;

        // Update rows selected visual style
        checkboxes.forEach(cb => {
            const tr = cb.closest('tr');
            if (tr) {
                tr.classList.toggle('row-selected', cb.checked);
            }
        });

        if (bulkSelectedCount) {
            bulkSelectedCount.textContent = count;
        }

        if (bulkActionsBar) {
            bulkActionsBar.style.display = count > 0 ? 'block' : 'none';
        }

        if (selectAllSongs) {
            selectAllSongs.checked = count > 0 && count === checkboxes.length;
            selectAllSongs.indeterminate = count > 0 && count < checkboxes.length;
        }
    }

    if (selectAllSongs) {
        selectAllSongs.addEventListener('change', () => {
            const isChecked = selectAllSongs.checked;
            document.querySelectorAll('.song-select-checkbox').forEach(cb => {
                cb.checked = isChecked;
            });
            updateBulkSelectionState();
        });
    }

    document.addEventListener('change', (e) => {
        if (e.target && e.target.classList.contains('song-select-checkbox')) {
            updateBulkSelectionState();
        }
    });

    if (btnBulkClear) {
        btnBulkClear.addEventListener('click', () => {
            document.querySelectorAll('.song-select-checkbox').forEach(cb => {
                cb.checked = false;
            });
            updateBulkSelectionState();
        });
    }

    // Open Bulk Delete Modal
    if (btnBulkDelete && bulkDeleteModal) {
        btnBulkDelete.addEventListener('click', () => {
            const checkedBoxes = Array.from(document.querySelectorAll('.song-select-checkbox:checked'));
            if (checkedBoxes.length === 0) return;

            if (bulkDeleteTotalCount) {
                bulkDeleteTotalCount.textContent = checkedBoxes.length;
            }

            if (bulkDeleteListPreview) {
                bulkDeleteListPreview.innerHTML = '';
                checkedBoxes.forEach(cb => {
                    const row = cb.closest('tr');
                    const songId = cb.getAttribute('data-song-id') || row?.getAttribute('data-song-id') || cb.value;
                    const title = cb.getAttribute('data-song-title') || row?.getAttribute('data-song-title') || 'Song';
                    const artist = cb.getAttribute('data-song-artist') || row?.getAttribute('data-song-artist') || 'Unknown Artist';

                    const item = document.createElement('div');
                    item.className = 'bulk-delete-item';
                    item.innerHTML = `
                        <span class="bulk-delete-item-id">#${escapeHtml(songId)}</span>
                        <span class="bulk-delete-item-title">${escapeHtml(title)}</span>
                        <span class="bulk-delete-item-artist">${escapeHtml(artist)}</span>
                    `;
                    bulkDeleteListPreview.appendChild(item);
                });
            }

            if (bulkDeleteError) {
                bulkDeleteError.style.display = 'none';
                bulkDeleteError.textContent = '';
            }

            bulkDeleteModal.classList.add('active');
        });
    }

    if (closeBulkDeleteBtn && bulkDeleteModal) closeBulkDeleteBtn.addEventListener('click', () => bulkDeleteModal.classList.remove('active'));
    if (cancelBulkDeleteBtn && bulkDeleteModal) cancelBulkDeleteBtn.addEventListener('click', () => bulkDeleteModal.classList.remove('active'));

    // Execute Bulk Deletion
    if (confirmBulkDeleteBtn && bulkDeleteModal) {
        confirmBulkDeleteBtn.addEventListener('click', async () => {
            const checkedBoxes = Array.from(document.querySelectorAll('.song-select-checkbox:checked'));
            const songIds = checkedBoxes.map(cb => {
                const row = cb.closest('tr');
                return parseInt(cb.getAttribute('data-song-id') || row?.getAttribute('data-song-id') || cb.value, 10);
            }).filter(id => id > 0);

            if (songIds.length === 0) return;

            confirmBulkDeleteBtn.disabled = true;
            confirmBulkDeleteBtn.textContent = 'Deleting...';

            try {
                const res = await fetch('api.php?action=delete_multiple_songs', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({ song_ids: songIds })
                });

                const data = await res.json();
                confirmBulkDeleteBtn.disabled = false;
                confirmBulkDeleteBtn.textContent = 'Delete Eligible Tracks';

                if (!data.success) {
                    if (bulkDeleteError) {
                        bulkDeleteError.textContent = data.error || 'Failed to delete selected songs.';
                        bulkDeleteError.style.display = 'block';
                    }
                    return;
                }

                showToast(data.message || `${data.deleted_count} tracks deleted successfully.`, 'success');
                bulkDeleteModal.classList.remove('active');

                // Animate removal of deleted rows from DOM
                const deletedIds = data.deleted_ids || [];
                deletedIds.forEach(id => {
                    const row = document.querySelector(`tr[data-song-id="${id}"]`);
                    if (row) {
                        row.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'scaleY(0.5)';
                        setTimeout(() => row.remove(), 250);
                    }
                });

                // Clear selection
                setTimeout(() => {
                    updateBulkSelectionState();
                    // If table is now empty, reload to show next page or empty state
                    const remainingRows = document.querySelectorAll('#songsTable tbody tr');
                    if (remainingRows.length === 0) {
                        window.location.reload();
                    }
                }, 300);

            } catch (err) {
                confirmBulkDeleteBtn.disabled = false;
                confirmBulkDeleteBtn.textContent = 'Delete Eligible Tracks';
                console.error('Bulk delete error:', err);
                if (bulkDeleteError) {
                    bulkDeleteError.textContent = 'Network error during bulk delete.';
                    bulkDeleteError.style.display = 'block';
                }
            }
        });
    }

    // Modal background click handling
    [addModal, editModal, deleteModal, bulkDeleteModal].forEach(modal => {
        if (!modal) return;
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });
}

/**
 * Accessible Pagination Interactivity & Keyboard Shortcuts
 */
function initAccessiblePagination() {
    // 1. Keyboard navigation within pagination toolbars (ArrowLeft, ArrowRight, Home, End)
    document.querySelectorAll('.pagination-links').forEach(linksGroup => {
        linksGroup.addEventListener('keydown', (e) => {
            const focusables = Array.from(linksGroup.querySelectorAll('a.page-btn:not(.disabled)'));
            if (focusables.length === 0) return;

            const currentIndex = focusables.indexOf(document.activeElement);

            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                e.preventDefault();
                const nextIndex = (currentIndex + 1) % focusables.length;
                focusables[nextIndex].focus();
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                e.preventDefault();
                const prevIndex = (currentIndex - 1 + focusables.length) % focusables.length;
                focusables[prevIndex].focus();
            } else if (e.key === 'Home') {
                e.preventDefault();
                focusables[0].focus();
            } else if (e.key === 'End') {
                e.preventDefault();
                focusables[focusables.length - 1].focus();
            }
        });
    });

    // 2. Global Alt + Left / Right shortcuts for fast page navigation when not typing in inputs
    document.addEventListener('keydown', (e) => {
        const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        const isEditing = activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select' || (document.activeElement && document.activeElement.isContentEditable);
        if (isEditing) return;

        // Alt + ArrowLeft or Alt + PageUp => Previous page
        if ((e.altKey && e.key === 'ArrowLeft') || (e.altKey && e.key === 'PageUp')) {
            const prevBtn = document.querySelector('.page-btn-prev:not(.disabled)');
            if (prevBtn && prevBtn.href) {
                e.preventDefault();
                window.location.href = prevBtn.href;
            }
        }
        // Alt + ArrowRight or Alt + PageDown => Next page
        else if ((e.altKey && e.key === 'ArrowRight') || (e.altKey && e.key === 'PageDown')) {
            const nextBtn = document.querySelector('.page-btn-next:not(.disabled)');
            if (nextBtn && nextBtn.href) {
                e.preventDefault();
                window.location.href = nextBtn.href;
            }
        }
        // Alt + Home => First page
        else if (e.altKey && e.key === 'Home') {
            const firstBtn = document.querySelector('.page-btn-first:not(.disabled)');
            if (firstBtn && firstBtn.href) {
                e.preventDefault();
                window.location.href = firstBtn.href;
            }
        }
        // Alt + End => Last page
        else if (e.altKey && e.key === 'End') {
            const lastBtn = document.querySelector('.page-btn-last:not(.disabled)');
            if (lastBtn && lastBtn.href) {
                e.preventDefault();
                window.location.href = lastBtn.href;
            }
        }
    });

    // 3. Jump to page input bounds validation
    document.querySelectorAll('.pagination-jump-form').forEach(form => {
        form.addEventListener('submit', (e) => {
            const input = form.querySelector('.pagination-jump-input');
            if (!input) return;
            const val = parseInt(input.value, 10);
            const min = parseInt(input.min || '1', 10);
            const max = parseInt(input.max || '1', 10);

            if (isNaN(val) || val < min || val > max) {
                e.preventDefault();
                input.focus();
                input.setCustomValidity(`Please enter a page number between ${min} and ${max}.`);
                input.reportValidity();
            } else {
                input.setCustomValidity('');
            }
        });
    });
}


