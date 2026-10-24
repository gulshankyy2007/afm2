<?php
/**
 * AFM#2 - Secure RESTful API Endpoint
 * Handles asynchronous operations: discovery updates, scorecard ratings, tournament stage previews & commits.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/preferences_helper.php';
require_once dirname(__DIR__) . '/includes/artwork_helper.php';
require_once dirname(__DIR__) . '/includes/duplicate_helper.php';
require_once dirname(__DIR__) . '/includes/import_helper.php';
require_once dirname(__DIR__) . '/includes/youtube_matcher.php';

header('Content-Type: application/json; charset=utf-8');

// Require authentication for all API operations
require_auth(true);

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Helper to get JSON input
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Verify CSRF for state-changing POST requests
if ($method === 'POST') {
    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
    if (!validate_csrf_token($submittedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Security token (CSRF) validation failed. Please refresh the page.']);
        exit;
    }
}

switch ($action) {
    case 'update_discovery':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        $status = isset($input['status']) ? trim($input['status']) : '';

        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid song ID.']);
            exit;
        }

        $result = update_song_discovery_status($songId, $status);
        echo json_encode($result);
        break;

    case 'save_rating':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $entryId = isset($input['entry_id']) ? (int)$input['entry_id'] : 0;
        $roundId = isset($input['round_id']) ? (int)$input['round_id'] : 1;

        if ($entryId <= 0 || $roundId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid entry or tournament round ID.']);
            exit;
        }

        $melody        = $input['melody'] ?? null;
        $lyrics        = $input['lyrics'] ?? null;
        $vocals        = $input['vocals'] ?? null;
        $emotion       = $input['emotion'] ?? null;
        $replayability = $input['replayability'] ?? null;

        $result = save_scorecard_rating(
            $entryId, 
            $roundId, 
            $melody, 
            $lyrics, 
            $vocals, 
            $emotion, 
            $replayability
        );

        // Also persist recording media if provided with scorecard
        $videoInput = trim($input['video_id_or_url'] ?? ($input['video_url'] ?? ($input['youtube_url'] ?? '')));
        if ($result['success'] && $videoInput !== '') {
            $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
            if ($songId <= 0) {
                try {
                    $pdo = get_db_connection();
                    $stmt = $pdo->prepare("SELECT `song_id` FROM `afm_entries` WHERE `entry_id` = :eid LIMIT 1");
                    $stmt->execute([':eid' => $entryId]);
                    $songId = (int)$stmt->fetchColumn();
                } catch (\Throwable $e) {}
            }
            if ($songId > 0) {
                $versionType  = trim($input['version_type'] ?? 'original');
                $versionNotes = isset($input['version_notes']) ? trim($input['version_notes']) : null;
                $userUrl      = isset($input['user_url']) ? trim($input['user_url']) : $videoInput;
                $mediaRes = save_song_media($songId, $videoInput, $versionType, $versionNotes, 'allowed', null, null, $userUrl);
                if ($mediaRes['success']) {
                    $result['media'] = $mediaRes['media'];
                }
            }
        }

        echo json_encode($result);
        break;

    case 'preview_advancement':
        $fromRoundId = isset($_GET['from_round_id']) ? (int)$_GET['from_round_id'] : 0;
        if ($fromRoundId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid round ID.']);
            exit;
        }

        $preview = preview_stage_advancement($fromRoundId);
        echo json_encode($preview);
        break;

    case 'commit_advancement':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $fromRoundId = isset($input['from_round_id']) ? (int)$input['from_round_id'] : 0;
        $qualifiers  = isset($input['qualifier_ids']) && is_array($input['qualifier_ids']) 
            ? array_map('intval', $input['qualifier_ids']) 
            : [];

        if ($fromRoundId <= 0 || empty($qualifiers)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid round or empty qualifier list.']);
            exit;
        }

        $res = commit_stage_advancement($fromRoundId, $qualifiers);
        echo json_encode($res);
        break;

    // --- Audio Playback & Media Endpoints ---

    case 'resolve_recording':
        $url = trim($_GET['url'] ?? ($_GET['url_or_video_id'] ?? ($input['url'] ?? ($input['video_id_or_url'] ?? ''))));
        $songId = isset($_GET['song_id']) ? (int)$_GET['song_id'] : (isset($input['song_id']) ? (int)$input['song_id'] : null);
        if ($url === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'YouTube URL or Video ID is required for resolution.']);
            exit;
        }

        $res = resolve_youtube_recording($url, $songId);
        if (!$res['success']) {
            http_response_code(400);
        } else {
            $res['recording'] = $res;
        }
        echo json_encode($res);
        break;

    case 'save_media':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId       = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        if ($songId <= 0 && !empty($input['entry_id'])) {
            try {
                $pdo = get_db_connection();
                $stmt = $pdo->prepare("SELECT `song_id` FROM `afm_entries` WHERE `entry_id` = :eid LIMIT 1");
                $stmt->execute([':eid' => (int)$input['entry_id']]);
                $songId = (int)$stmt->fetchColumn();
            } catch (\Throwable $e) {}
        }
        $videoInput   = trim($input['video_id_or_url'] ?? ($input['video_id'] ?? ($input['url'] ?? ($input['youtube_url'] ?? ''))));
        $versionType  = trim($input['version_type'] ?? 'original');
        $versionNotes = isset($input['version_notes']) ? trim($input['version_notes']) : null;
        $embedStatus  = trim($input['embed_status'] ?? 'allowed');
        $title        = isset($input['title']) ? trim($input['title']) : null;
        $channelTitle = isset($input['channel_title']) ? trim($input['channel_title']) : null;
        $userUrl      = isset($input['user_url']) ? trim($input['user_url']) : (isset($input['url']) ? trim($input['url']) : (isset($input['video_id_or_url']) ? trim($input['video_id_or_url']) : null));

        if ($songId <= 0 || $videoInput === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Song ID and YouTube Video ID / Link are required.']);
            exit;
        }

        $res = save_song_media($songId, $videoInput, $versionType, $versionNotes, $embedStatus, $title, $channelTitle, $userUrl);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'remove_media':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid song ID.']);
            exit;
        }

        $res = remove_song_media($songId);
        echo json_encode($res);
        break;

    case 'update_embed_status':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        $status = trim($input['embed_status'] ?? 'allowed');
        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid song ID.']);
            exit;
        }

        $res = update_song_media_embed_status($songId, $status);
        echo json_encode($res);
        break;

    // --- LRCLIB Lyrics Endpoints ---

    case 'get_lyrics':
        $songId       = isset($_GET['song_id']) ? (int)$_GET['song_id'] : (int)($input['song_id'] ?? 0);
        $title        = trim($_GET['title'] ?? ($input['title'] ?? ''));
        $artist       = trim($_GET['artist'] ?? ($input['artist'] ?? ''));
        $forceRefresh = !empty($_GET['refresh']) || !empty($input['refresh']);

        if ($songId <= 0 || empty($title)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Song ID and Title are required.']);
            exit;
        }

        $res = fetch_lrclib_lyrics($songId, $title, $artist, $forceRefresh);
        echo json_encode($res);
        break;

    case 'search_lyrics_candidates':
        $title    = trim($_GET['title'] ?? ($input['title'] ?? ''));
        $artist   = trim($_GET['artist'] ?? ($input['artist'] ?? ''));
        $duration = isset($_GET['duration']) ? (float)$_GET['duration'] : (isset($input['duration']) ? (float)$input['duration'] : null);

        if (empty($title)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Song title is required for searching candidates.']);
            exit;
        }

        $res = search_lrclib_candidates($title, $artist, $duration);
        echo json_encode($res);
        break;

    case 'select_lyrics_candidate':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId   = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        $lrclibId = isset($input['lrclib_id']) ? (int)$input['lrclib_id'] : 0;

        if ($songId <= 0 || $lrclibId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid Song ID and LRCLIB ID are required.']);
            exit;
        }

        $res = get_lrclib_lyrics_by_id($songId, $lrclibId);
        echo json_encode($res);
        break;

    case 'clear_lyrics':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid song ID.']);
            exit;
        }

        $res = clear_song_lyrics_ref($songId);
        echo json_encode($res);
        break;

    // --- UI Preferences Endpoints ---

    case 'get_preferences':
        $userId = get_current_user_id();
        $prefs = get_user_preferences($userId);
        echo json_encode([
            'success'     => true,
            'preferences' => $prefs,
            'valid_themes' => AFM_VALID_THEMES,
            'valid_bg_styles' => AFM_VALID_BG_STYLES,
            'valid_layouts' => AFM_VALID_LAYOUTS
        ]);
        break;

    case 'save_preferences':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $userId = get_current_user_id();
        $res = save_user_preferences($userId, $input);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'reset_preferences':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $userId = get_current_user_id();
        $res = reset_user_preferences($userId);
        echo json_encode($res);
        break;

    // --- Artwork Discovery & Fallback Endpoints ---

    case 'get_artwork':
        $songId       = isset($_GET['song_id']) ? (int)$_GET['song_id'] : (int)($input['song_id'] ?? 0);
        $title        = trim($_GET['title'] ?? ($input['title'] ?? ''));
        $artist       = trim($_GET['artist'] ?? ($input['artist'] ?? ''));
        $forceRefresh = !empty($_GET['refresh']) || !empty($input['refresh']);

        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid song ID is required.']);
            exit;
        }

        $res = fetch_song_artwork($songId, $title, $artist, $forceRefresh);
        echo json_encode($res);
        break;

    case 'search_artwork_candidates':
        $title  = trim($_GET['title'] ?? ($input['title'] ?? ''));
        $artist = trim($_GET['artist'] ?? ($input['artist'] ?? ''));

        if (empty($title)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Song title is required.']);
            exit;
        }

        $res = search_artwork_candidates($title, $artist);
        echo json_encode($res);
        break;

    case 'confirm_artwork':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId  = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        $artwork = isset($input['artwork']) && is_array($input['artwork']) ? $input['artwork'] : [];

        if ($songId <= 0 || empty($artwork['image_url'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Song ID and artwork image URL are required.']);
            exit;
        }

        $res = confirm_song_artwork($songId, $artwork);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'remove_artwork':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid song ID.']);
            exit;
        }

        $res = remove_song_artwork($songId);
        echo json_encode($res);
        break;

    // --- Safe Duplicate Review, Comparison & Consolidation Endpoints ---

    case 'get_duplicate_candidates':
        $filters = [
            'search'     => trim($_GET['search'] ?? ''),
            'status'     => trim($_GET['status'] ?? 'all'),
            'category'   => trim($_GET['category'] ?? 'all'),
            'confidence' => trim($_GET['confidence'] ?? 'all'),
            'page'       => max(1, (int)($_GET['page'] ?? 1)),
            'per_page'   => max(5, min(100, (int)($_GET['per_page'] ?? 20)))
        ];

        try {
            $data = detect_duplicate_candidates($filters);
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Unable to scan duplicate candidates.']);
        }
        break;

    case 'get_duplicate_comparison':
        $songIdA = isset($_GET['song_id_a']) ? (int)$_GET['song_id_a'] : 0;
        $songIdB = isset($_GET['song_id_b']) ? (int)$_GET['song_id_b'] : 0;

        if ($songIdA <= 0 || $songIdB <= 0 || $songIdA === $songIdB) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Two distinct, valid song IDs are required for comparison.']);
            exit;
        }

        try {
            $comp = get_song_comparison_data($songIdA, $songIdB);
            if (!$comp) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'One or both songs could not be found.']);
                exit;
            }
            echo json_encode(['success' => true, 'data' => $comp]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load comparison data.']);
        }
        break;

    case 'save_duplicate_decision':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songIdA       = isset($input['song_id_a']) ? (int)$input['song_id_a'] : 0;
        $songIdB       = isset($input['song_id_b']) ? (int)$input['song_id_b'] : 0;
        $decision      = trim($input['decision'] ?? '');
        $excludeSongId = isset($input['exclude_song_id']) ? (int)$input['exclude_song_id'] : null;
        $notes         = isset($input['notes']) ? trim($input['notes']) : null;
        $userId        = get_current_user_id();

        if ($songIdA <= 0 || $songIdB <= 0 || $songIdA === $songIdB) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid candidate song IDs.']);
            exit;
        }

        $allowed = ['keep_both', 'excluded', 'deferred'];
        if (!in_array($decision, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid review decision.']);
            exit;
        }

        try {
            $res = save_duplicate_decision($songIdA, $songIdB, $decision, $excludeSongId, $notes, $userId);
            echo json_encode($res);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to record decision due to a server error.']);
        }
        break;

    case 'consolidate_duplicate':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $canonicalId    = isset($input['canonical_id']) ? (int)$input['canonical_id'] : 0;
        $consolidatedId = isset($input['consolidated_id']) ? (int)$input['consolidated_id'] : 0;
        $notes          = isset($input['notes']) ? trim($input['notes']) : null;
        $userId         = get_current_user_id();

        if ($canonicalId <= 0 || $consolidatedId <= 0 || $canonicalId === $consolidatedId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Two distinct, valid song IDs are required for consolidation.']);
            exit;
        }

        try {
            $res = execute_consolidation($canonicalId, $consolidatedId, $notes, $userId);
            echo json_encode($res);
        } catch (RuntimeException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Consolidation failed. All changes have been safely rolled back.']);
        }
        break;

    case 'recover_consolidation':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $auditId = isset($input['audit_id']) ? (int)$input['audit_id'] : 0;
        $userId  = get_current_user_id();

        if ($auditId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid consolidation audit ID.']);
            exit;
        }

        try {
            $res = execute_recovery($auditId, $userId);
            echo json_encode($res);
        } catch (RuntimeException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Recovery failed. Transaction safely rolled back.']);
        }
        break;

    case 'get_consolidation_audit':
        try {
            $trail = get_consolidation_audit_trail(50);
            echo json_encode(['success' => true, 'data' => $trail]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to load consolidation audit trail.']);
        }
        break;

    case 'extract_youtube_info':
        $url = trim($_GET['url'] ?? ($input['url'] ?? ''));
        if ($url === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'YouTube URL or Video ID is required.']);
            exit;
        }
        $data = extract_youtube_metadata($url);
        if (!$data['success']) {
            http_response_code(400);
        } else {
            // Check if extracted track already exists in catalogue
            $existing = find_existing_catalogue_song($data['title'], $data['artist']);
            if ($existing) {
                $data['existing_song'] = [
                    'song_id' => (int)$existing['song_id'],
                    'title'   => $existing['title'],
                    'artist'  => $existing['artist'] ?? 'Unknown Artist'
                ];
            }
        }
        echo json_encode($data);
        break;

    case 'find_existing_song':
        $title = trim($_GET['title'] ?? ($input['title'] ?? ''));
        $artist = isset($_GET['artist']) ? trim($_GET['artist']) : ($input['artist'] ?? null);
        if ($title === '') {
            echo json_encode(['success' => true, 'match' => null]);
            exit;
        }
        $existing = find_existing_catalogue_song($title, $artist);
        if ($existing) {
            echo json_encode([
                'success' => true,
                'match'   => [
                    'song_id' => (int)$existing['song_id'],
                    'title'   => $existing['title'],
                    'artist'  => $existing['artist'] ?? 'Unknown Artist'
                ]
            ]);
        } else {
            echo json_encode(['success' => true, 'match' => null]);
        }
        break;

    case 'create_song':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }
        $title = trim($input['title'] ?? '');
        $artist = isset($input['artist']) ? trim($input['artist']) : null;
        $youtubeUrl = trim($input['youtube_url'] ?? '');
        $discoveryStatus = trim($input['discovery_status'] ?? 'unreviewed');

        if ($title === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Song title cannot be empty.']);
            exit;
        }

        $res = create_catalogue_song($title, $artist, $youtubeUrl, $discoveryStatus);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'update_song':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }
        $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        $title = trim($input['title'] ?? '');
        $artist = isset($input['artist']) ? trim($input['artist']) : null;
        $youtubeUrl = trim($input['youtube_url'] ?? '');

        if ($songId <= 0 || $title === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Song ID and Title are required.']);
            exit;
        }

        $res = update_catalogue_song($songId, $title, $artist, $youtubeUrl);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'delete_song':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }
        $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid song ID.']);
            exit;
        }

        $res = delete_catalogue_song($songId);
        if (!$res['success']) {
            http_response_code(!empty($res['blocked']) ? 409 : 400);
        }
        echo json_encode($res);
        break;

    case 'delete_multiple_songs':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }
        $rawSongIds = $input['song_ids'] ?? [];
        if (!is_array($rawSongIds) || empty($rawSongIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No song IDs provided for deletion.']);
            exit;
        }

        $res = delete_multiple_catalogue_songs($rawSongIds);
        if (!$res['success']) {
            http_response_code(!empty($res['blocked']) ? 409 : 400);
        }
        echo json_encode($res);
        break;

    case 'export_rejection_report':
        $rejected = $_SESSION['last_import_rejections'] ?? [];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="afm2_import_rejections_report_' . date('Ymd_His') . '.csv"');
        echo generate_rejection_csv_report($rejected);
        exit;

    // --- Bulk YouTube Discovery, Candidate Ranking & Link Management ---

    case 'youtube_discover_candidates':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid song ID.']);
            exit;
        }

        $pdo = get_db_connection();
        $songStmt = $pdo->prepare("SELECT `song_id`, `title`, `artist` FROM `songs` WHERE `song_id` = :id");
        $songStmt->execute([':id' => $songId]);
        $song = $songStmt->fetch();
        if (!$song) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Song not found in catalogue.']);
            exit;
        }

        $lyricsStmt = $pdo->prepare("SELECT `duration` FROM `song_lyrics_ref` WHERE `song_id` = :id");
        $lyricsStmt->execute([':id' => $songId]);
        $lyricsRow = $lyricsStmt->fetch();
        $expectedDuration = ($lyricsRow && !empty($lyricsRow['duration'])) ? (int)round((float)$lyricsRow['duration']) : null;

        $forceRefresh = !empty($input['force_refresh']);
        $candidates = [];
        if (!$forceRefresh) {
            $existing = YouTubeMatcher::get_candidates_for_song($songId);
            if (!empty($existing)) {
                $candidates = $existing;
            }
        }

        if (empty($candidates)) {
            $rawCandidates = YouTubeMatcher::discover_candidates($song['title'], $song['artist']);
            $candidates = YouTubeMatcher::score_and_rank_candidates($song['title'], $song['artist'], $rawCandidates, $expectedDuration);
            YouTubeMatcher::save_candidates_for_song($songId, $candidates);
        }

        $savedMedia = get_song_media($songId);

        echo json_encode([
            'success'     => true,
            'song_id'     => $songId,
            'song_title'  => $song['title'],
            'song_artist' => $song['artist'],
            'candidates'  => $candidates,
            'saved_media' => $savedMedia
        ]);
        break;

    case 'youtube_get_candidates':
        $songId = isset($_GET['song_id']) ? (int)$_GET['song_id'] : 0;
        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid song ID.']);
            exit;
        }

        $pdo = get_db_connection();
        $songStmt = $pdo->prepare("SELECT `song_id`, `title`, `artist` FROM `songs` WHERE `song_id` = :id");
        $songStmt->execute([':id' => $songId]);
        $song = $songStmt->fetch();
        if (!$song) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Song not found in catalogue.']);
            exit;
        }

        $candidates = YouTubeMatcher::get_candidates_for_song($songId);
        if (empty($candidates)) {
            $lyricsStmt = $pdo->prepare("SELECT `duration` FROM `song_lyrics_ref` WHERE `song_id` = :id");
            $lyricsStmt->execute([':id' => $songId]);
            $lyricsRow = $lyricsStmt->fetch();
            $expectedDuration = ($lyricsRow && !empty($lyricsRow['duration'])) ? (int)round((float)$lyricsRow['duration']) : null;

            $rawCandidates = YouTubeMatcher::discover_candidates($song['title'], $song['artist']);
            $candidates = YouTubeMatcher::score_and_rank_candidates($song['title'], $song['artist'], $rawCandidates, $expectedDuration);
            YouTubeMatcher::save_candidates_for_song($songId, $candidates);
        }

        $savedMedia = get_song_media($songId);

        echo json_encode([
            'success'     => true,
            'song_id'     => $songId,
            'song_title'  => $song['title'],
            'song_artist' => $song['artist'],
            'candidates'  => $candidates,
            'saved_media' => $savedMedia
        ]);
        break;

    case 'youtube_select_candidate':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        $videoId = trim($input['video_id'] ?? '');
        $versionType = trim($input['version_type'] ?? 'original');
        $versionNotes = isset($input['version_notes']) ? trim($input['version_notes']) : null;

        if ($songId <= 0 || $videoId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Song ID and Video ID are required.']);
            exit;
        }

        $res = YouTubeMatcher::manual_select_candidate($songId, $videoId, $versionType, $versionNotes);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'youtube_manual_save':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        $urlOrId = trim($input['url_or_video_id'] ?? ($input['video_url'] ?? ($input['youtube_url'] ?? '')));
        $versionType = trim($input['version_type'] ?? 'original');
        $versionNotes = isset($input['version_notes']) ? trim($input['version_notes']) : null;

        if ($songId <= 0 || $urlOrId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Song ID and YouTube URL or Video ID are required.']);
            exit;
        }

        $res = YouTubeMatcher::manual_save_url($songId, $urlOrId, $versionType, $versionNotes);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'youtube_clear_recording':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid song ID.']);
            exit;
        }

        $res = YouTubeMatcher::clear_recording($songId);
        echo json_encode($res);
        break;

    case 'youtube_verify_embed':
        $videoId = trim($_GET['video_id'] ?? ($input['video_id'] ?? ''));
        $songId = isset($_GET['song_id']) ? (int)$_GET['song_id'] : (isset($input['song_id']) ? (int)$input['song_id'] : null);
        if ($videoId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Video ID is required.']);
            exit;
        }

        $res = YouTubeMatcher::verify_playback_and_embed($videoId, $songId);
        echo json_encode($res);
        break;

    case 'youtube_batch_process':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $batchSize = isset($input['batch_size']) ? max(1, min(50, (int)$input['batch_size'])) : 10;
        $force = !empty($input['force']);

        $res = YouTubeMatcher::process_batch($batchSize, ['force' => $force]);
        $stats = YouTubeMatcher::get_matching_stats();
        $res['stats'] = $stats;
        echo json_encode($res);
        break;

    case 'youtube_job_status':
        $job = YouTubeMatcher::get_or_create_active_job();
        $stats = YouTubeMatcher::get_matching_stats();
        echo json_encode([
            'success' => true,
            'job'     => $job,
            'stats'   => $stats
        ]);
        break;

    case 'youtube_job_reset':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        YouTubeMatcher::reset_job();
        $job = YouTubeMatcher::get_or_create_active_job();
        $stats = YouTubeMatcher::get_matching_stats();
        echo json_encode([
            'success' => true,
            'message' => 'Matching job reset successfully.',
            'job'     => $job,
            'stats'   => $stats
        ]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Unknown API action.']);
        break;
}
