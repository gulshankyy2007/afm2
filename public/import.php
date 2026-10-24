<?php
/**
 * AFM#2 - Website-Based CSV Bulk Import
 *
 * Implements end-to-end bulk song import:
 * Upload -> Column Mapping -> Preview & Duplicate Review -> Atomic Transaction -> Results
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/import_helper.php';

require_auth();

$error = null;
$notice = null;
$currentStep = 'upload'; // 'upload', 'review', 'results'

// Handle reset
if (isset($_GET['step']) && $_GET['step'] === 'reset') {
    if (!empty($_SESSION['csv_import_file']) && file_exists($_SESSION['csv_import_file'])) {
        @unlink($_SESSION['csv_import_file']);
    }
    unset($_SESSION['csv_import_file'], $_SESSION['csv_raw_content'], $_SESSION['csv_import_data'], $_SESSION['import_token']);
    header('Location: import.php');
    exit;
}

// Handle Results display
if (isset($_GET['step']) && $_GET['step'] === 'results' && !empty($_SESSION['last_import_results'])) {
    $currentStep = 'results';
    $results = $_SESSION['last_import_results'];
    $rejections = $_SESSION['last_import_rejections'] ?? [];
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($token)) {
        $error = 'Security validation (CSRF) token expired or invalid. Please try again.';
    } else {
        $postAction = $_POST['import_action'] ?? '';

        // -------------------------------------------------------------
        // ACTION 1: Process Uploaded CSV
        // -------------------------------------------------------------
        if ($postAction === 'upload_csv') {
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrCode = $_FILES['csv_file']['error'] ?? -1;
                $errMap = [
                    UPLOAD_ERR_INI_SIZE   => 'Uploaded file exceeds the PHP server upload size limit.',
                    UPLOAD_ERR_FORM_SIZE  => 'Uploaded file exceeds form maximum size.',
                    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE    => 'No file was selected for upload.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk.'
                ];
                $error = $errMap[$uploadErrCode] ?? 'File upload error occurred. Please try again.';
            } else {
                $file = $_FILES['csv_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                // Validate file extension
                if (!in_array($ext, ['csv', 'txt'], true)) {
                    $error = 'Only .csv or .txt files are supported for catalogue import.';
                } elseif ($file['size'] > 4 * 1024 * 1024) {
                    $error = 'File exceeds maximum allowed upload size of 4 MB.';
                } else {
                    $tmpDest = sys_get_temp_dir() . '/afm2_import_' . preg_replace('/[^a-zA-Z0-9]/', '', session_id()) . '_' . time() . '.csv';
                    if (!move_uploaded_file($file['tmp_name'], $tmpDest)) {
                        $error = 'Failed to stage uploaded CSV file for analysis.';
                    } else {
                        $analysis = parse_and_validate_csv($tmpDest);
                        if (!$analysis['success']) {
                            @unlink($tmpDest);
                            $error = $analysis['error'];
                        } else {
                            $_SESSION['csv_import_file'] = $tmpDest;
                            $_SESSION['csv_raw_content'] = function_exists('gzencode') ? gzencode((string)file_get_contents($tmpDest), 6) : file_get_contents($tmpDest);
                            $_SESSION['csv_import_data'] = $analysis;
                            $_SESSION['import_token'] = bin2hex(random_bytes(16));
                            $currentStep = 'review';
                        }
                    }
                }
            }
        }

        // -------------------------------------------------------------
        // ACTION 2: Remap Columns
        // -------------------------------------------------------------
        elseif ($postAction === 'remap_columns' && !empty($_SESSION['csv_import_file'])) {
            $tmpPath = $_SESSION['csv_import_file'];
            if (!file_exists($tmpPath)) {
                // If running in a multi-instance serverless environment (e.g. Vercel),
                // reconstruct the temporary file on the active container from the session payload
                if (!empty($_SESSION['csv_raw_content'])) {
                    $raw = $_SESSION['csv_raw_content'];
                    $content = (str_starts_with($raw, "\x1f\x8b") && function_exists('gzdecode')) ? @gzdecode($raw) : $raw;
                    if ($content !== false && $content !== '') {
                        $reconstructedPath = sys_get_temp_dir() . '/afm2_import_' . preg_replace('/[^a-zA-Z0-9]/', '', session_id()) . '_' . time() . '.csv';
                        if (file_put_contents($reconstructedPath, $content) !== false) {
                            $tmpPath = $reconstructedPath;
                            $_SESSION['csv_import_file'] = $reconstructedPath;
                        }
                    }
                }
            }

            if (!file_exists($tmpPath)) {
                $error = 'Staged CSV session expired. Please re-upload your file.';
                $currentStep = 'upload';
            } else {
                $titleCol = isset($_POST['title_col']) ? (int)$_POST['title_col'] : null;
                $artistCol = isset($_POST['artist_col']) && $_POST['artist_col'] !== '' ? (int)$_POST['artist_col'] : null;

                $analysis = parse_and_validate_csv($tmpPath, $titleCol, $artistCol);
                if (!$analysis['success']) {
                    $error = $analysis['error'];
                    $currentStep = 'upload';
                } else {
                    $_SESSION['csv_import_data'] = $analysis;
                    $notice = 'Column mapping updated and rows re-evaluated.';
                    $currentStep = 'review';
                }
            }
        }

        // -------------------------------------------------------------
        // ACTION 3: Confirm & Execute Import
        // -------------------------------------------------------------
        elseif ($postAction === 'confirm_import') {
            $submittedToken = $_POST['import_token'] ?? '';
            $sessionToken = $_SESSION['import_token'] ?? '';

            // Prevent duplicate submissions on page refresh / re-post
            if (empty($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
                if (!empty($_SESSION['last_import_results'])) {
                    header('Location: import.php?step=results');
                    exit;
                }
                $error = 'This import was already submitted or the session expired. Please upload the file again.';
                $currentStep = 'upload';
            } elseif (empty($_SESSION['csv_import_data'])) {
                $error = 'Import session data not found. Please upload the file again.';
                $currentStep = 'upload';
            } else {
                // Consume token immediately for idempotency
                unset($_SESSION['import_token']);

                $analysis = $_SESSION['csv_import_data'];
                $allRows = $analysis['rows'];
                $approvedIndices = $_POST['approved_lines'] ?? [];
                if (!is_array($approvedIndices)) {
                    $approvedIndices = [];
                }
                // Convert to hash map for fast lookup: line_num => bool
                $approvedMap = array_fill_keys(array_map('intval', $approvedIndices), true);

                $rowsToInsert = [];
                $rejectedRows = [];

                foreach ($allRows as $r) {
                    $lineNum = (int)$r['line_num'];
                    $isApproved = isset($approvedMap[$lineNum]);

                    if ($isApproved && $r['status'] !== 'invalid') {
                        $rowsToInsert[] = $r;
                    } else {
                        $rejectedReason = $r['status'] === 'invalid' 
                            ? $r['reason'] 
                            : 'Skipped by user during duplicate and version review';
                        $r['reason'] = $rejectedReason;
                        $rejectedRows[] = $r;
                    }
                }

                $initialStatus = in_array($_POST['initial_status'] ?? '', ['unreviewed', 'shortlisted', 'passed'], true)
                    ? $_POST['initial_status']
                    : 'unreviewed';

                $execResult = execute_csv_import($rowsToInsert, $initialStatus);

                if (!$execResult['success']) {
                    $error = $execResult['error'];
                    $currentStep = 'review';
                } else {
                    // Compute complete breakdown
                    $resultsSummary = [
                        'total_processed'   => count($allRows),
                        'songs_added'       => $execResult['songs_added'],
                        'existing_skipped'  => 0,
                        'duplicates_skipped'=> 0,
                        'invalid_skipped'   => 0,
                        'review_skipped'    => 0,
                        'new_song_ids'      => $execResult['new_song_ids'],
                        'initial_status'    => $initialStatus
                    ];

                    foreach ($rejectedRows as $rej) {
                        if ($rej['status'] === 'existing_in_db') $resultsSummary['existing_skipped']++;
                        elseif ($rej['status'] === 'duplicate_in_file') $resultsSummary['duplicates_skipped']++;
                        elseif ($rej['status'] === 'invalid') $resultsSummary['invalid_skipped']++;
                        else $resultsSummary['review_skipped']++;
                    }

                    $_SESSION['last_import_results'] = $resultsSummary;
                    $_SESSION['last_import_rejections'] = $rejectedRows;

                    // Clean up temporary file
                    if (!empty($_SESSION['csv_import_file']) && file_exists($_SESSION['csv_import_file'])) {
                        @unlink($_SESSION['csv_import_file']);
                    }
                    unset($_SESSION['csv_import_file'], $_SESSION['csv_raw_content'], $_SESSION['csv_import_data']);

                    header('Location: import.php?step=results');
                    exit;
                }
            }
        }
    }
}

// If in review step, load session data
if ($currentStep === 'review' && !empty($_SESSION['csv_import_data'])) {
    $analysis = $_SESSION['csv_import_data'];
}

$pageTitle = 'CSV Bulk Import — AFM#2';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="import-container">
    <!-- Breadcrumb & Header -->
    <div class="page-header" style="margin-bottom: 1.5rem;">
        <div class="page-title-group">
            <div style="margin-bottom: 0.35rem;">
                <a href="library.php" class="link-muted" style="font-size: 0.85rem;">&larr; Return to Library</a>
            </div>
            <h1>CSV Bulk Import</h1>
            <p class="page-subtitle">Add songs in bulk to your AFM#2 catalogue with automatic column detection, UTF-8 non-English support, and duplicate review.</p>
        </div>
        <div class="header-actions">
            <a href="library.php" class="btn btn-secondary">Catalogue Library</a>
        </div>
    </div>

    <!-- 3-Stage Progress Pipeline -->
    <div class="import-steps" aria-label="Import Workflow Stages">
        <div class="import-step-node <?= $currentStep === 'upload' ? 'active' : ($currentStep !== 'upload' ? 'completed' : '') ?>">
            <div class="import-step-icon">
                <?= $currentStep !== 'upload' ? '✓' : '1' ?>
            </div>
            <span>1. Upload CSV</span>
        </div>
        <div class="import-step-node <?= $currentStep === 'review' ? 'active' : ($currentStep === 'results' ? 'completed' : '') ?>">
            <div class="import-step-icon">
                <?= $currentStep === 'results' ? '✓' : '2' ?>
            </div>
            <span>2. Preview &amp; Review</span>
        </div>
        <div class="import-step-node <?= $currentStep === 'results' ? 'active' : '' ?>">
            <div class="import-step-icon">3</div>
            <span>3. Import Results</span>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error mb-4" role="alert">
            <span class="alert-icon">⚠️</span>
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <?php if ($notice): ?>
        <div class="alert alert-info mb-4" role="status">
            <span class="alert-icon">ℹ️</span>
            <span><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <!-- ================================================================= -->
    <!-- STAGE 1: UPLOAD CSV                                              -->
    <!-- ================================================================= -->
    <?php if ($currentStep === 'upload'): ?>
        <div class="import-card">
            <form method="POST" action="import.php" enctype="multipart/form-data" id="csvUploadForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="import_action" value="upload_csv">

                <div class="csv-dropzone" id="csvDropzone">
                    <input type="file" name="csv_file" id="csvFileInput" accept=".csv, text/csv, text/plain" required>
                    <div class="dropzone-icon">📁</div>
                    <div class="dropzone-title" id="dropzoneTitle">Select or drop a CSV file here</div>
                    <p class="dropzone-desc" id="dropzoneDesc">Supports standard CSV exports from Spotify, Apple Music, YouTube playlists, or custom spreadsheets.</p>
                    <div class="dropzone-specs">
                        <span>Max 5 MB</span> &bull; 
                        <span>UTF-8 &amp; Non-English</span> &bull; 
                        <span>Quoted Commas &amp; Escaped Quotes</span>
                    </div>
                </div>

                <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="submit" class="btn btn-primary" id="btnUploadSubmit">
                        Upload &amp; Analyze CSV &rarr;
                    </button>
                </div>
            </form>

            <div class="csv-specs-card">
                <div class="specs-title">
                    <span>💡</span> CSV Format &amp; Header Guidelines
                </div>
                <ul class="specs-list">
                    <li><strong>Supported Title Headers:</strong> <code>Track name</code>, <code>Title</code>, <code>Song</code>, <code>Track</code>, <code>Song Title</code>, or <code>Name</code>.</li>
                    <li><strong>Supported Artist Headers:</strong> <code>Artist name</code>, <code>Artist</code>, <code>Artists</code>, <code>Performer</code>, <code>Band</code>, or <code>Author</code>.</li>
                    <li><strong>Missing Artists:</strong> If an artist column is omitted or empty, the song is safely recorded with <em>Unknown Artist (NULL)</em>.</li>
                    <li><strong>International Text:</strong> Fully supports Japanese, Korean, Hindi, Chinese, Cyrillic, accented characters, and UTF-8 Byte Order Marks (BOM).</li>
                    <li><strong>Safe Preview:</strong> No database records are created or modified during upload analysis. You will review all rows before confirming.</li>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <!-- ================================================================= -->
    <!-- STAGE 2: PREVIEW & DUPLICATE REVIEW                              -->
    <!-- ================================================================= -->
    <?php if ($currentStep === 'review' && !empty($analysis)): 
        $counts = $analysis['counts'];
        $headers = $analysis['headers'];
        $detTitle = $analysis['detected_title_col'];
        $detArtist = $analysis['detected_artist_col'];
        $rows = $analysis['rows'];
        $importToken = $_SESSION['import_token'] ?? '';
    ?>
        <!-- Summary Metric Cards -->
        <div class="import-summary-grid">
            <div class="import-stat-card">
                <div class="import-stat-num"><?= number_format($counts['total']) ?></div>
                <div class="import-stat-label">Total Rows</div>
            </div>
            <div class="import-stat-card stat-valid">
                <div class="import-stat-num" style="color: #4ade80;"><?= number_format($counts['valid_new']) ?></div>
                <div class="import-stat-label">Valid New Songs</div>
            </div>
            <div class="import-stat-card stat-similar">
                <div class="import-stat-num" style="color: #facc15;"><?= number_format($counts['similar_in_db']) ?></div>
                <div class="import-stat-label">Similar / Versions</div>
            </div>
            <div class="import-stat-card stat-existing">
                <div class="import-stat-num" style="color: #60a5fa;"><?= number_format($counts['existing_in_db']) ?></div>
                <div class="import-stat-label">Already in Catalogue</div>
            </div>
            <div class="import-stat-card stat-duplicate">
                <div class="import-stat-num" style="color: #fb923c;"><?= number_format($counts['duplicate_in_file']) ?></div>
                <div class="import-stat-label">Duplicate in File</div>
            </div>
            <div class="import-stat-card stat-invalid">
                <div class="import-stat-num" style="color: #f87171;"><?= number_format($counts['invalid']) ?></div>
                <div class="import-stat-label">Invalid Rows</div>
            </div>
        </div>

        <!-- Column Mapping Override Card -->
        <div class="mapping-controls-card">
            <form method="POST" action="import.php" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="import_action" value="remap_columns">

                <div class="specs-title" style="margin-bottom: 0.75rem;">
                    <span>⚙️</span> Detected Column Mapping
                </div>
                <div class="mapping-controls-grid">
                    <div class="form-group" style="margin: 0;">
                        <label for="titleColSelect" class="form-label font-bold">Song Title Column <span class="required">*</span></label>
                        <select name="title_col" id="titleColSelect" class="form-input">
                            <?php foreach ($headers as $idx => $h): ?>
                                <option value="<?= $idx ?>" <?= $detTitle === $idx ? 'selected' : '' ?>>
                                    Col <?= $idx + 1 ?>: "<?= htmlspecialchars(clean_csv_header_cell((string)$h), ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $detTitle === $idx ? ' (Auto-Detected)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin: 0;">
                        <label for="artistColSelect" class="form-label font-bold">Artist Column</label>
                        <select name="artist_col" id="artistColSelect" class="form-input">
                            <option value="">-- No Artist Column (Store as Unknown Artist) --</option>
                            <?php foreach ($headers as $idx => $h): ?>
                                <option value="<?= $idx ?>" <?= $detArtist === $idx ? 'selected' : '' ?>>
                                    Col <?= $idx + 1 ?>: "<?= htmlspecialchars(clean_csv_header_cell((string)$h), ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $detArtist === $idx ? ' (Auto-Detected)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: flex; align-items: flex-end;">
                        <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%;">
                            Re-Analyze Mapping
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Review & Approval Form -->
        <form method="POST" action="import.php" id="confirmImportForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="import_action" value="confirm_import">
            <input type="hidden" name="import_token" value="<?= htmlspecialchars($importToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="import-card" style="padding: 1.5rem;">
                <div class="review-filter-bar">
                    <div class="review-filter-pills">
                        <button type="button" class="review-filter-pill active" data-filter="all">
                            All Rows (<?= count($rows) ?>)
                        </button>
                        <button type="button" class="review-filter-pill" data-filter="valid_new">
                            New Songs (<?= $counts['valid_new'] ?>)
                        </button>
                        <button type="button" class="review-filter-pill" data-filter="similar_in_db">
                            Similar Versions (<?= $counts['similar_in_db'] ?>)
                        </button>
                        <button type="button" class="review-filter-pill" data-filter="existing_in_db">
                            Already in Catalogue (<?= $counts['existing_in_db'] ?>)
                        </button>
                        <button type="button" class="review-filter-pill" data-filter="duplicate_in_file">
                            File Duplicates (<?= $counts['duplicate_in_file'] ?>)
                        </button>
                        <button type="button" class="review-filter-pill" data-filter="invalid">
                            Invalid (<?= $counts['invalid'] ?>)
                        </button>
                    </div>

                    <div class="review-selection-actions">
                        <button type="button" class="btn btn-secondary btn-xs" id="btnSelectAllVisible">Select All</button>
                        <button type="button" class="btn btn-secondary btn-xs" id="btnDeselectAllVisible">Deselect All</button>
                    </div>
                </div>

                <!-- Review Table -->
                <div class="table-container" style="max-height: 520px; overflow-y: auto;">
                    <table class="data-table" id="importReviewTable">
                        <thead>
                            <tr>
                                <th class="col-select" style="width: 44px;">
                                    <input type="checkbox" id="selectAllReviewRows" title="Toggle selection for visible rows">
                                </th>
                                <th style="width: 65px;">Line</th>
                                <th>Song Title</th>
                                <th>Artist</th>
                                <th style="width: 140px;">Classification</th>
                                <th>Reason / Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): 
                                $isInvalid = $r['status'] === 'invalid';
                                $badgeClass = 'badge-status-new';
                                $badgeLabel = 'New Track';

                                if ($r['status'] === 'similar_in_db') {
                                    $badgeClass = 'badge-status-similar';
                                    $badgeLabel = 'Similar / Version';
                                } elseif ($r['status'] === 'existing_in_db') {
                                    $badgeClass = 'badge-status-existing';
                                    $badgeLabel = 'In Catalogue';
                                } elseif ($r['status'] === 'duplicate_in_file') {
                                    $badgeClass = 'badge-status-duplicate';
                                    $badgeLabel = 'Duplicate in File';
                                } elseif ($r['status'] === 'invalid') {
                                    $badgeClass = 'badge-status-invalid';
                                    $badgeLabel = 'Invalid';
                                }
                            ?>
                                <tr class="review-row" data-status="<?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8') ?>" <?= $isInvalid ? 'style="opacity: 0.65;"' : '' ?>>
                                    <td class="col-select">
                                        <?php if ($isInvalid): ?>
                                            <input type="checkbox" disabled title="Invalid rows cannot be imported">
                                        <?php else: ?>
                                            <input type="checkbox" 
                                                   name="approved_lines[]" 
                                                   value="<?= (int)$r['line_num'] ?>" 
                                                   class="review-checkbox" 
                                                   <?= $r['default_approved'] ? 'checked' : '' ?>
                                                   aria-label="Approve row <?= (int)$r['line_num'] ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-id"><?= (int)$r['line_num'] ?></td>
                                    <td>
                                        <strong class="font-bold"><?= htmlspecialchars($r['raw_title'] !== '' ? $r['raw_title'] : '(Empty Title)', ENT_QUOTES, 'UTF-8') ?></strong>
                                    </td>
                                    <td>
                                        <?= !empty($r['raw_artist']) ? htmlspecialchars($r['raw_artist'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">&mdash; Unknown &mdash;</span>' ?>
                                    </td>
                                    <td>
                                        <span class="badge-status <?= $badgeClass ?>">
                                            <?= $badgeLabel ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                                        <?= htmlspecialchars($r['reason'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Initial Review Status Option & Execution Row -->
                <div style="margin-top: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <label for="initialStatusSelect" class="form-label font-bold" style="margin: 0; white-space: nowrap;">Initial Status:</label>
                        <select name="initial_status" id="initialStatusSelect" class="form-input" style="width: auto;">
                            <option value="unreviewed">Not Reviewed (Default)</option>
                            <option value="shortlisted">Shortlist for Auditions</option>
                            <option value="passed">Mark as Passed</option>
                        </select>
                    </div>

                    <div style="display: flex; gap: 0.75rem;">
                        <a href="import.php?step=reset" class="btn btn-secondary btn-sm" onclick="return confirm('Discard this uploaded CSV and start over?')">
                            Cancel &amp; Upload Different File
                        </a>
                        <button type="submit" class="btn btn-primary btn-sm" id="btnConfirmImport">
                            Confirm &amp; Import <span id="approvedCounterBadge">(<?= number_format($counts['valid_new'] + $counts['similar_in_db']) ?>)</span> Songs
                        </button>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <!-- ================================================================= -->
    <!-- STAGE 3: IMPORT RESULTS                                          -->
    <!-- ================================================================= -->
    <?php if ($currentStep === 'results' && !empty($results)): ?>
        <div class="import-card import-results-card">
            <div class="results-success-icon">🎉</div>
            <h2 class="font-brand" style="font-size: 1.85rem; margin-bottom: 0.4rem;">Import Completed Successfully</h2>
            <p class="text-muted" style="max-width: 520px; margin: 0 auto;">Your approved songs have been safely added to the AFM#2 catalogue via database transaction.</p>

            <div class="results-metrics-grid">
                <div class="results-metric-box">
                    <div class="results-metric-val" style="color: #4ade80;">+<?= number_format($results['songs_added']) ?></div>
                    <div class="results-metric-label">Songs Added to Catalogue</div>
                </div>
                <div class="results-metric-box">
                    <div class="results-metric-val"><?= number_format($results['total_processed']) ?></div>
                    <div class="results-metric-label">Total CSV Rows Processed</div>
                </div>
                <div class="results-metric-box">
                    <div class="results-metric-val" style="color: #60a5fa;"><?= number_format($results['existing_skipped']) ?></div>
                    <div class="results-metric-label">Existing Catalogue Skipped</div>
                </div>
                <div class="results-metric-box">
                    <div class="results-metric-val" style="color: #fb923c;"><?= number_format($results['duplicates_skipped']) ?></div>
                    <div class="results-metric-label">File Duplicates Skipped</div>
                </div>
                <div class="results-metric-box">
                    <div class="results-metric-val" style="color: #f87171;"><?= number_format($results['invalid_skipped']) ?></div>
                    <div class="results-metric-label">Invalid Rows Skipped</div>
                </div>
                <div class="results-metric-box">
                    <div class="results-metric-val" style="color: #facc15;"><?= number_format($results['review_skipped']) ?></div>
                    <div class="results-metric-label">Version Review Skipped</div>
                </div>
            </div>

            <div class="results-actions-row">
                <a href="library.php?sort=id_desc" class="btn btn-primary">
                    View in Catalogue Library &rarr;
                </a>
                <?php if ($results['initial_status'] === 'shortlisted'): ?>
                    <a href="auditions.php" class="btn btn-secondary">
                        Open Audition Console &rarr;
                    </a>
                <?php else: ?>
                    <a href="discovery.php" class="btn btn-secondary">
                        Review Shortlist &rarr;
                    </a>
                <?php endif; ?>

                <?php if (!empty($rejections)): ?>
                    <a href="api.php?action=export_rejection_report" class="btn btn-secondary" title="Export CSV report of rejected and skipped records with reasons">
                        <span>📥</span> Download Rejection Report (CSV)
                    </a>
                <?php endif; ?>

                <a href="import.php?step=reset" class="btn btn-secondary">
                    Import Another CSV
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Drag & Drop Handling
    const dropzone = document.getElementById('csvDropzone');
    const fileInput = document.getElementById('csvFileInput');
    const dropzoneTitle = document.getElementById('dropzoneTitle');
    const dropzoneDesc = document.getElementById('dropzoneDesc');

    if (dropzone && fileInput) {
        ['dragenter', 'dragover'].forEach(evt => {
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(evt => {
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            });
        });

        dropzone.addEventListener('drop', (e) => {
            if (e.dataTransfer && e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                updateFileDisplay(fileInput.files[0]);
            }
        });

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                updateFileDisplay(fileInput.files[0]);
            }
        });

        function updateFileDisplay(file) {
            if (dropzoneTitle) dropzoneTitle.textContent = file.name;
            if (dropzoneDesc) dropzoneDesc.textContent = `Selected: ${(file.size / 1024).toFixed(1)} KB — Ready to analyze`;
        }
    }

    // 2. Review Table Filter Pills
    const filterPills = document.querySelectorAll('.review-filter-pill');
    const reviewRows = document.querySelectorAll('.review-row');
    const selectAllReviewRows = document.getElementById('selectAllReviewRows');
    const approvedCounterBadge = document.getElementById('approvedCounterBadge');
    const reviewCheckboxes = document.querySelectorAll('.review-checkbox');

    function updateApprovedCount() {
        const checkedCount = document.querySelectorAll('.review-checkbox:checked').length;
        if (approvedCounterBadge) {
            approvedCounterBadge.textContent = `(${checkedCount})`;
        }
    }

    reviewCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateApprovedCount);
    });

    filterPills.forEach(pill => {
        pill.addEventListener('click', () => {
            filterPills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');

            const filter = pill.getAttribute('data-filter');
            reviewRows.forEach(row => {
                if (filter === 'all' || row.getAttribute('data-status') === filter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });

    // 3. Selection Actions
    const btnSelectAllVisible = document.getElementById('btnSelectAllVisible');
    const btnDeselectAllVisible = document.getElementById('btnDeselectAllVisible');

    if (btnSelectAllVisible) {
        btnSelectAllVisible.addEventListener('click', () => {
            reviewRows.forEach(row => {
                if (row.style.display !== 'none') {
                    const cb = row.querySelector('.review-checkbox');
                    if (cb && !cb.disabled) cb.checked = true;
                }
            });
            updateApprovedCount();
        });
    }

    if (btnDeselectAllVisible) {
        btnDeselectAllVisible.addEventListener('click', () => {
            reviewRows.forEach(row => {
                if (row.style.display !== 'none') {
                    const cb = row.querySelector('.review-checkbox');
                    if (cb && !cb.disabled) cb.checked = false;
                }
            });
            updateApprovedCount();
        });
    }

    if (selectAllReviewRows) {
        selectAllReviewRows.addEventListener('change', () => {
            const isChecked = selectAllReviewRows.checked;
            reviewRows.forEach(row => {
                if (row.style.display !== 'none') {
                    const cb = row.querySelector('.review-checkbox');
                    if (cb && !cb.disabled) cb.checked = isChecked;
                }
            });
            updateApprovedCount();
        });
    }
});
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
