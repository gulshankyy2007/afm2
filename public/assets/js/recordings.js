/**
 * AFM#2 - Recordings Matching & Candidate Management
 * Extracted from recordings.php for HTTP cacheability and HTML payload optimization.
 */

document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // ==================== Modal Helpers ====================
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    // Close on backdrop click
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
        });
    });

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                closeModal(modal.id);
            });
        }
    });

    // ==================== Batch Runner Engine ====================
    let isBatchRunning = false;
    let shouldPauseBatch = false;
    const btnStartBatch = document.getElementById('btnStartBatch');
    const btnPauseBatch = document.getElementById('btnPauseBatch');
    const btnResetJob = document.getElementById('btnResetJob');
    const batchProgressFill = document.getElementById('batchProgressFill');
    const batchPercentLabel = document.getElementById('batchPercentLabel');
    const jobStatusBadge = document.getElementById('jobStatusBadge');
    const jobProcessedCount = document.getElementById('jobProcessedCount');
    const logMessage = document.getElementById('logMessage');
    const logTimer = document.getElementById('logTimer');

    function updateBatchUI(job) {
        if (!job) return;
        const totalEl = document.getElementById('statTotalSongs');
        const total = totalEl ? parseInt(totalEl.textContent.replace(/,/g, '') || 0, 10) : 0;
        const processed = parseInt(job.processed_songs || 0, 10);
        const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 1000) / 10) : 0;

        if (batchProgressFill) batchProgressFill.style.width = pct + '%';
        if (batchPercentLabel) batchPercentLabel.textContent = pct + '%';
        if (jobProcessedCount) jobProcessedCount.textContent = Number(processed).toLocaleString();
        if (jobStatusBadge) jobStatusBadge.textContent = (job.status || 'running').toUpperCase();
        if (logTimer) logTimer.textContent = 'Last Song ID: #' + (job.last_processed_song_id || 0);

        const pill = document.getElementById('jobStatusPill');
        if (pill) {
            pill.className = 'batch-status-pill status-' + (job.status || 'running').toLowerCase();
        }
    }

    async function runBatchStep() {
        if (shouldPauseBatch) {
            isBatchRunning = false;
            shouldPauseBatch = false;
            if (btnStartBatch) btnStartBatch.style.display = 'inline-flex';
            if (btnPauseBatch) btnPauseBatch.style.display = 'none';
            if (batchProgressFill) batchProgressFill.classList.remove('animating');
            if (logMessage) logMessage.textContent = 'Batch runner paused by operator.';
            if (jobStatusBadge) jobStatusBadge.textContent = 'PAUSED';
            const pill = document.getElementById('jobStatusPill');
            if (pill) pill.className = 'batch-status-pill status-paused';
            return;
        }

        try {
            const res = await fetch('api.php?action=youtube_batch_process', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ batch_size: 10 })
            });
            const data = await res.json();

            if (!data.success) {
                if (logMessage) logMessage.textContent = 'Error: ' + (data.error || 'Batch request failed');
                isBatchRunning = false;
                if (btnStartBatch) btnStartBatch.style.display = 'inline-flex';
                if (btnPauseBatch) btnPauseBatch.style.display = 'none';
                if (batchProgressFill) batchProgressFill.classList.remove('animating');
                const pill = document.getElementById('jobStatusPill');
                if (pill) pill.className = 'batch-status-pill status-error';
                return;
            }

            const job = data.job;
            updateBatchUI(job);

            if (data.completed || (job && job.status === 'completed')) {
                isBatchRunning = false;
                if (btnStartBatch) btnStartBatch.style.display = 'inline-flex';
                if (btnPauseBatch) btnPauseBatch.style.display = 'none';
                if (batchProgressFill) batchProgressFill.classList.remove('animating');
                const pill = document.getElementById('jobStatusPill');
                if (pill) pill.className = 'batch-status-pill status-completed';
                if (logMessage) logMessage.textContent = 'All catalogue songs have been processed! Reloading table in 2s...';
                setTimeout(() => window.location.reload(), 2000);
                return;
            }

            const results = data.results || [];
            const highCount = results.filter(r => r.status === 'auto_high').length;
            const reviewCount = results.filter(r => r.status === 'review_needed').length;
            const lowCount = results.filter(r => r.status === 'unresolved').length;

            if (logMessage) logMessage.textContent = `Batch of ${data.processed || 0} songs (Auto High: ${highCount}, Review Needed: ${reviewCount}, Low: ${lowCount})`;

            // Continue next batch step asynchronously
            setTimeout(runBatchStep, 600);
        } catch (err) {
            if (logMessage) logMessage.textContent = 'Network or server error during batch processing.';
            isBatchRunning = false;
            if (btnStartBatch) btnStartBatch.style.display = 'inline-flex';
            if (btnPauseBatch) btnPauseBatch.style.display = 'none';
            if (batchProgressFill) batchProgressFill.classList.remove('animating');
            const pill = document.getElementById('jobStatusPill');
            if (pill) pill.className = 'batch-status-pill status-error';
        }
    }

    btnStartBatch?.addEventListener('click', function() {
        if (isBatchRunning) return;
        isBatchRunning = true;
        shouldPauseBatch = false;
        btnStartBatch.style.display = 'none';
        btnPauseBatch.style.display = 'inline-flex';
        batchProgressFill.classList.add('animating');
        logMessage.textContent = 'Batch processing active...';
        jobStatusBadge.textContent = 'RUNNING';
        const pill = document.getElementById('jobStatusPill');
        if (pill) pill.className = 'batch-status-pill status-running';
        runBatchStep();
    });

    btnPauseBatch?.addEventListener('click', function() {
        if (!isBatchRunning) return;
        shouldPauseBatch = true;
        logMessage.textContent = 'Pausing after current batch completes...';
    });

    btnResetJob?.addEventListener('click', async function() {
        if (!confirm('Reset matching job progress back to Song #1? Existing accepted recordings will not be lost.')) {
            return;
        }
        try {
            const res = await fetch('api.php?action=youtube_job_reset', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                }
            });
            const data = await res.json();
            if (data.success) {
                window.location.reload();
            } else {
                alert('Failed to reset job: ' + (data.error || 'Unknown error'));
            }
        } catch (err) {
            alert('Server error resetting job');
        }
    });

    // Refresh stats button
    document.getElementById('btnRefreshStats')?.addEventListener('click', async function() {
        try {
            const res = await fetch('api.php?action=youtube_job_status');
            const data = await res.json();
            if (data.success && data.stats) {
                const totalEl = document.getElementById('statTotalSongs');
                if (totalEl) totalEl.textContent = Number(data.stats.total_songs).toLocaleString();
                document.getElementById('statMatchedTotal').textContent = Number(data.stats.matched_total).toLocaleString();
                document.getElementById('statReviewNeeded').textContent = Number(data.stats.review_needed).toLocaleString();
                document.getElementById('statLowUnresolved').textContent = Number(data.stats.low_unresolved).toLocaleString();
                document.getElementById('statManualCount').textContent = Number(data.stats.manual_count).toLocaleString();
                document.getElementById('statBlockedCount').textContent = Number(data.stats.blocked_count).toLocaleString();
            }
        } catch (e) {
            console.error(e);
        }
    });

    // ==================== Candidate Review Modal ====================
    let activeSongIdForCand = null;
    let activeSongTitleForCand = '';
    let activeSongArtistForCand = '';

    const candModal = document.getElementById('candidateReviewModal');
    const candModalTitle = document.getElementById('candModalTitle');
    const candModalSubtitle = document.getElementById('candModalSubtitle');
    const candCardsList = document.getElementById('candCardsList');
    const candLoadingState = document.getElementById('candLoadingState');
    const candEmptyState = document.getElementById('candEmptyState');

    document.querySelectorAll('.btn-open-candidates').forEach(btn => {
        btn.addEventListener('click', function() {
            const sId = parseInt(this.getAttribute('data-song-id'), 10);
            const row = document.getElementById('row-song-any') || this.closest('tr');
            activeSongIdForCand = sId;
            activeSongTitleForCand = row?.getAttribute('data-title') || '';
            activeSongArtistForCand = row?.getAttribute('data-artist') || '';

            if (candModalTitle) candModalTitle.innerHTML = `<span>🎯</span> Candidates: ${escapeHtml(activeSongTitleForCand)}`;
            if (candModalSubtitle) candModalSubtitle.textContent = `Artist: ${activeSongArtistForCand} • Track #${sId}`;

            openModal('candidateReviewModal');
            loadCandidates(sId, false);
        });
    });

    document.getElementById('btnRescanCandidates')?.addEventListener('click', function() {
        if (activeSongIdForCand) {
            loadCandidates(activeSongIdForCand, true);
        }
    });

    document.getElementById('btnCloseCandModal')?.addEventListener('click', () => closeModal('candidateReviewModal'));
    document.getElementById('btnCancelCandModal')?.addEventListener('click', () => closeModal('candidateReviewModal'));

    async function loadCandidates(songId, forceDiscover = false) {
        if (candLoadingState) candLoadingState.style.display = 'block';
        if (candEmptyState) candEmptyState.style.display = 'none';
        if (candCardsList) candCardsList.innerHTML = '';

        try {
            const action = forceDiscover ? 'youtube_discover_candidates' : 'youtube_get_candidates';
            const res = await fetch(`api.php?action=${action}&song_id=${songId}`);
            const data = await res.json();

            if (candLoadingState) candLoadingState.style.display = 'none';

            if (!data.success || !data.candidates || data.candidates.length === 0) {
                if (candEmptyState) candEmptyState.style.display = 'block';
                return;
            }

            renderCandidates(data.candidates, songId);
        } catch (err) {
            if (candLoadingState) candLoadingState.style.display = 'none';
            if (candCardsList) candCardsList.innerHTML = `<div class="alert alert-danger">Failed to load candidates: ${err.message}</div>`;
        }
    }

    function renderCandidates(candidates, songId) {
        if (!candCardsList) return;
        candCardsList.innerHTML = '';
        candidates.forEach(cand => {
            const scoreTotal = parseFloat(cand.score_total || 0).toFixed(1);
            const conf = cand.confidence || 'low';
            const isSelected = parseInt(cand.is_selected || 0, 10) === 1;

            let confBadgeClass = 'badge-confidence-low';
            if (conf === 'high') confBadgeClass = 'badge-confidence-high';
            else if (conf === 'medium') confBadgeClass = 'badge-confidence-medium';

            const card = document.createElement('div');
            card.className = `candidate-card-item ${isSelected ? 'is-current-selection' : ''}`;
            card.innerHTML = `
                <div class="candidate-thumb-box">
                    <img src="${escapeHtml(cand.thumbnail_url || 'assets/img/artwork-fallback.svg')}" alt="Thumb" class="candidate-thumb-img" onerror="this.onerror=null; this.src='assets/img/artwork-fallback.svg';">
                    ${cand.duration ? `<span class="candidate-duration-pill">${escapeHtml(cand.duration)}</span>` : ''}
                </div>
                <div class="candidate-info-col">
                    <div class="candidate-video-title">${escapeHtml(cand.title || '')}</div>
                    <div class="candidate-channel-row">
                        <span>${escapeHtml(cand.channel_title || 'Unknown Channel')}</span>
                        ${cand.channel_is_artist ? '<span class="verified-channel-badge">★ Official Artist</span>' : (cand.channel_is_verified ? '<span class="verified-channel-badge">✓ Verified</span>' : '')}
                        &bull; <span>${cand.view_count ? Number(cand.view_count).toLocaleString() + ' views' : 'Views unavailable'}</span>
                        ${cand.published_at ? `&bull; <span>${escapeHtml(cand.published_at.substring(0, 10))}</span>` : ''}
                    </div>

                    <div class="candidate-signal-chips">
                        <span class="badge ${confBadgeClass}">${conf.toUpperCase()} CONFIDENCE</span>
                        ${isSelected ? '<span class="badge badge-shortlisted">● Current Selection</span>' : ''}
                    </div>

                    ${cand.decision_notes ? `<div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.35rem; font-style: italic;">Note: ${escapeHtml(cand.decision_notes)}</div>` : ''}

                    <div class="candidate-score-breakdown">
                        <div class="score-chip">Title (25%)<strong>${parseFloat(cand.score_title || 0).toFixed(1)}</strong></div>
                        <div class="score-chip">Artist (25%)<strong>${parseFloat(cand.score_artist || 0).toFixed(1)}</strong></div>
                        <div class="score-chip">Version (20%)<strong>${parseFloat(cand.score_version || 0).toFixed(1)}</strong></div>
                        <div class="score-chip">Channel (10%)<strong>${parseFloat(cand.score_channel || 0).toFixed(1)}</strong></div>
                        <div class="score-chip">Date (8%)<strong>${parseFloat(cand.score_date || 0).toFixed(1)}</strong></div>
                        <div class="score-chip">Views (7%)<strong>${parseFloat(cand.score_views || 0).toFixed(1)}</strong></div>
                        <div class="score-chip">Duration (5%)<strong>${parseFloat(cand.score_duration || 0).toFixed(1)}</strong></div>
                    </div>
                </div>

                <div class="candidate-actions-col">
                    <div class="candidate-score-headline">
                        <div class="candidate-score-percent text-accent">${scoreTotal}%</div>
                        <div style="font-size: 0.7rem; color: var(--text-muted);">Match Score</div>
                    </div>
                    <button type="button" class="btn btn-secondary btn-xs btn-preview-cand" data-video-id="${escapeHtml(cand.video_id)}" data-title="${escapeHtml(cand.title || '')}">
                        ▶ Preview
                    </button>
                    ${!isSelected ? `
                        <button type="button" class="btn btn-primary btn-xs btn-select-cand" data-candidate-id="${cand.candidate_id || ''}" data-video-id="${escapeHtml(cand.video_id)}" data-song-id="${songId}">
                            ✓ Select Video
                        </button>
                    ` : `
                        <span class="text-success font-bold" style="font-size: 0.8rem;">✓ Accepted</span>
                    `}
                </div>
            `;
            candCardsList.appendChild(card);
        });

        // Attach listeners for preview and select inside modal
        candCardsList.querySelectorAll('.btn-preview-cand').forEach(btn => {
            btn.addEventListener('click', function() {
                openVideoPreview(this.getAttribute('data-video-id'), this.getAttribute('data-title'));
            });
        });

        candCardsList.querySelectorAll('.btn-select-cand').forEach(btn => {
            btn.addEventListener('click', async function() {
                const cId = this.getAttribute('data-candidate-id');
                const vId = this.getAttribute('data-video-id');
                const sId = this.getAttribute('data-song-id');

                if (!confirm(`Select YouTube video (${vId}) as the accepted recording for this track?`)) {
                    return;
                }

                try {
                    const res = await fetch('api.php?action=youtube_select_candidate', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({
                            song_id: sId,
                            candidate_id: cId,
                            video_id: vId
                        })
                    });
                    const result = await res.json();
                    if (result.success) {
                        closeModal('candidateReviewModal');
                        window.location.reload();
                    } else {
                        alert('Error selecting candidate: ' + (result.error || 'Unknown error'));
                    }
                } catch (e) {
                    alert('Server error selecting candidate');
                }
            });
        });
    }

    document.getElementById('btnSwitchToManualFromCand')?.addEventListener('click', function() {
        closeModal('candidateReviewModal');
        openManualUrlModal(activeSongIdForCand, activeSongTitleForCand, activeSongArtistForCand, '');
    });

    // ==================== Manual URL Entry Modal ====================
    let activeManualSongId = null;
    const manualModal = document.getElementById('manualUrlModal');
    const manualModalTitle = document.getElementById('manualModalTitle');
    const manualModalSubtitle = document.getElementById('manualModalSubtitle');
    const manualSongIdInput = document.getElementById('manualSongId');
    const manualInputUrl = document.getElementById('manualInputUrl');
    const manualVersionType = document.getElementById('manualVersionType');
    const manualVersionNotes = document.getElementById('manualVersionNotes');
    const manualVerificationBox = document.getElementById('manualVerificationBox');
    const manualThumbPreview = document.getElementById('manualThumbPreview');
    const manualTitlePreview = document.getElementById('manualTitlePreview');
    const manualAuthorPreview = document.getElementById('manualAuthorPreview');
    const manualEmbedNotice = document.getElementById('manualEmbedNotice');
    const manualOverwriteWarning = document.getElementById('manualOverwriteWarning');

    function openManualUrlModal(songId, title, artist, currentUrl) {
        activeManualSongId = songId;
        if (manualSongIdInput) manualSongIdInput.value = songId;
        if (manualModalTitle) manualModalTitle.innerHTML = `<span>✍️</span> Edit Recording: ${escapeHtml(title)}`;
        if (manualModalSubtitle) manualModalSubtitle.textContent = `Artist: ${artist} • Track #${songId}`;
        if (manualInputUrl) manualInputUrl.value = currentUrl || '';
        if (manualVersionType) manualVersionType.value = 'original';
        if (manualVersionNotes) manualVersionNotes.value = '';
        if (manualVerificationBox) manualVerificationBox.style.display = 'none';

        if (manualOverwriteWarning) manualOverwriteWarning.style.display = currentUrl ? 'flex' : 'none';

        openModal('manualUrlModal');
    }

    document.querySelectorAll('.btn-open-manual').forEach(btn => {
        btn.addEventListener('click', function() {
            const sId = parseInt(this.getAttribute('data-song-id'), 10);
            const currentUrl = this.getAttribute('data-current-url') || '';
            const row = this.closest('tr');
            const title = row?.getAttribute('data-title') || '';
            const artist = row?.getAttribute('data-artist') || '';
            openManualUrlModal(sId, title, artist, currentUrl);
        });
    });

    document.getElementById('btnCloseManualModal')?.addEventListener('click', () => closeModal('manualUrlModal'));
    document.getElementById('btnCancelManualModal')?.addEventListener('click', () => closeModal('manualUrlModal'));

    // Live validation
    document.getElementById('btnValidateManualUrl')?.addEventListener('click', async function() {
        if (!manualInputUrl) return;
        const urlVal = manualInputUrl.value.trim();
        if (!urlVal) {
            alert('Please enter a YouTube link or video ID first');
            return;
        }

        try {
            const res = await fetch(`api.php?action=youtube_verify_embed&url=${encodeURIComponent(urlVal)}`);
            const data = await res.json();

            if (!data.success) {
                alert('URL validation error: ' + (data.error || 'Invalid YouTube URL'));
                return;
            }

            if (manualVerificationBox) manualVerificationBox.style.display = 'block';
            if (manualThumbPreview) manualThumbPreview.src = data.thumbnail_url || 'assets/img/artwork-fallback.svg';
            if (manualTitlePreview) manualTitlePreview.textContent = data.title || 'YouTube Video (' + data.video_id + ')';
            if (manualAuthorPreview) manualAuthorPreview.textContent = data.author_name ? 'Channel: ' + data.author_name : 'Video ID: ' + data.video_id;

            if (manualEmbedNotice) {
                if (data.embed_status === 'allowed') {
                    manualEmbedNotice.innerHTML = '<span class="text-success font-bold">✓ Embedding Permitted</span> (Playback ready)';
                } else {
                    manualEmbedNotice.innerHTML = '<span class="text-danger font-bold">⚠️ Embedding Restricted or Blocked</span> (May require opening on YouTube)';
                }
            }
        } catch (e) {
            alert('Could not verify link: ' + e.message);
        }
    });

    // Save manual URL
    document.getElementById('btnSaveManualUrl')?.addEventListener('click', async function() {
        if (!manualSongIdInput || !manualInputUrl || !manualVersionType || !manualVersionNotes) return;
        const songId = manualSongIdInput.value;
        const urlVal = manualInputUrl.value.trim();
        const vType = manualVersionType.value;
        const vNotes = manualVersionNotes.value.trim();

        if (!urlVal) {
            alert('Please provide a YouTube URL or video ID.');
            return;
        }

        try {
            const res = await fetch('api.php?action=youtube_manual_save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    song_id: songId,
                    url: urlVal,
                    version_type: vType,
                    version_notes: vNotes
                })
            });
            const data = await res.json();

            if (data.success) {
                closeModal('manualUrlModal');
                window.location.reload();
            } else {
                alert('Save failed: ' + (data.error || 'Server error'));
            }
        } catch (e) {
            alert('Server error saving recording: ' + e.message);
        }
    });

    // ==================== Video Playback Preview Modal ====================
    const previewModal = document.getElementById('videoPreviewModal');
    const previewIframe = document.getElementById('previewIframe');
    const previewSubtitle = document.getElementById('previewModalSubtitle');
    const previewExternalLink = document.getElementById('previewExternalLink');
    const previewEmbedAlert = document.getElementById('previewEmbedAlert');

    function openVideoPreview(videoId, title) {
        if (!videoId) return;
        if (previewSubtitle) previewSubtitle.textContent = title ? `Now Playing: ${title}` : `Video ID: ${videoId}`;
        if (previewIframe) previewIframe.src = `https://www.youtube.com/embed/${encodeURIComponent(videoId)}?autoplay=1&enablejsapi=1`;
        if (previewExternalLink) previewExternalLink.href = `https://www.youtube.com/watch?v=${encodeURIComponent(videoId)}`;

        // Verify oEmbed capability
        fetch(`api.php?action=youtube_verify_embed&video_id=${encodeURIComponent(videoId)}`)
            .then(r => r.json())
            .then(data => {
                if (data.success && previewEmbedAlert) {
                    if (data.embed_status === 'allowed') {
                        previewEmbedAlert.style.display = 'block';
                        previewEmbedAlert.className = 'alert alert-success';
                        previewEmbedAlert.textContent = '✓ Verified: YouTube embedding is fully supported for this recording.';
                    } else {
                        previewEmbedAlert.style.display = 'block';
                        previewEmbedAlert.className = 'alert alert-danger';
                        previewEmbedAlert.textContent = '⚠️ Creator embedding disabled or video unavailable. Use direct YouTube link to listen.';
                    }
                }
            })
            .catch(() => {});

        openModal('videoPreviewModal');
    }

    document.querySelectorAll('.btn-preview-video').forEach(btn => {
        btn.addEventListener('click', function() {
            openVideoPreview(this.getAttribute('data-video-id'), this.getAttribute('data-title'));
        });
    });

    function closePreview() {
        if (previewIframe) previewIframe.src = '';
        if (previewEmbedAlert) previewEmbedAlert.style.display = 'none';
        closeModal('videoPreviewModal');
    }

    document.getElementById('btnClosePreviewModal')?.addEventListener('click', closePreview);
    document.getElementById('btnClosePreviewModalBtn')?.addEventListener('click', closePreview);

    // ==================== Clear Recording Modal ====================
    let activeClearSongId = null;
    const clearModal = document.getElementById('clearRecordingModal');
    const clearSongTitle = document.getElementById('clearSongTitle');
    const clearSongArtist = document.getElementById('clearSongArtist');

    document.querySelectorAll('.btn-clear-rec').forEach(btn => {
        btn.addEventListener('click', function() {
            const sId = parseInt(this.getAttribute('data-song-id'), 10);
            const row = this.closest('tr');
            activeClearSongId = sId;
            if (clearSongTitle) clearSongTitle.textContent = row?.getAttribute('data-title') || `Song #${sId}`;
            if (clearSongArtist) clearSongArtist.textContent = row?.getAttribute('data-artist') || '';
            openModal('clearRecordingModal');
        });
    });

    document.getElementById('btnCloseClearModal')?.addEventListener('click', () => closeModal('clearRecordingModal'));
    document.getElementById('btnCancelClearModal')?.addEventListener('click', () => closeModal('clearRecordingModal'));

    document.getElementById('btnConfirmClearModal')?.addEventListener('click', async function() {
        if (!activeClearSongId) return;
        try {
            const res = await fetch('api.php?action=youtube_clear_recording', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ song_id: activeClearSongId })
            });
            const data = await res.json();
            if (data.success) {
                closeModal('clearRecordingModal');
                window.location.reload();
            } else {
                alert('Could not clear recording: ' + (data.error || 'Server error'));
            }
        } catch (e) {
            alert('Server error clearing recording');
        }
    });

    // Simple HTML escaper
    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }
});
