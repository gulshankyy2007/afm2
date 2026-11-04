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
require_once dirname(__DIR__) . '/includes/webauthn_service.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Helper to get JSON input
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Public unauthenticated actions
$publicActions = ['webauthn_auth_options', 'webauthn_auth_verify'];

if (!in_array($action, $publicActions, true)) {
    // Require authentication for all standard API operations
    require_auth(true);

    // Verify CSRF for state-changing POST requests
    if ($method === 'POST') {
        $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
        if (!validate_csrf_token($submittedToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Security token (CSRF) validation failed. Please refresh the page.']);
            exit;
        }
    }
    $currentUserId = get_current_user_id();
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

        $result = update_song_discovery_status($songId, $status, $currentUserId);
        if (!$result['success'] && strpos(strtolower($result['error'] ?? ''), 'unauthorized') !== false) {
            http_response_code(403);
        }
        echo json_encode($result);
        break;

    case 'check_bulk_eligibility':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $operation = isset($input['operation']) ? trim((string)$input['operation']) : '';
        $rawSongIds = $input['song_ids'] ?? [];

        if (!in_array($operation, ['shortlist', 'pass', 'delete'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid bulk action operation. Must be shortlist, pass, or delete.']);
            exit;
        }

        if (!is_array($rawSongIds) || empty($rawSongIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No song IDs provided for eligibility check.']);
            exit;
        }

        try {
            $eligibility = check_bulk_songs_eligibility($operation, $rawSongIds, $currentUserId);
            echo json_encode($eligibility);
        } catch (\Throwable $e) {
            error_log("AFM2 check_bulk_eligibility exception: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success'    => false,
                'error_code' => 'server_error',
                'error'      => 'A server error occurred while verifying track eligibility. Please try again later.'
            ]);
        }
        break;

    case 'bulk_discovery_update':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $status = isset($input['status']) ? trim((string)$input['status']) : (isset($input['operation']) ? trim((string)$input['operation']) : '');
        $rawSongIds = $input['song_ids'] ?? [];

        if (!is_array($rawSongIds) || empty($rawSongIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No song IDs provided for bulk status update.']);
            exit;
        }

        try {
            $res = bulk_update_song_discovery($status, $rawSongIds, $currentUserId);
            if (!$res['success']) {
                if (strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
                    http_response_code(403);
                } else {
                    http_response_code(!empty($res['blocked']) ? 409 : 400);
                }
            }
            echo json_encode($res);
        } catch (\Throwable $e) {
            error_log("AFM2 bulk_discovery_update exception: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success'    => false,
                'error_code' => 'server_error',
                'error'      => 'A server error occurred during bulk status update. The technical incident has been logged. Please try again.'
            ]);
        }
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
            $replayability,
            $currentUserId
        );

        if (!$result['success'] && (!empty($result['blocked']) || strpos(strtolower($result['error'] ?? ''), 'unauthorized') !== false)) {
            http_response_code(403);
            echo json_encode($result);
            break;
        }

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
                $mediaRes = save_song_media($songId, $videoInput, $versionType, $versionNotes, 'allowed', null, null, $userUrl, $currentUserId);
                if ($mediaRes['success']) {
                    $result['media'] = $mediaRes['media'];
                }
            }
        }

        echo json_encode($result);
        break;

    case 'reset_score':
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

        $result = reset_scorecard_rating($entryId, $roundId, $currentUserId);
        if (!$result['success']) {
            if (!empty($result['blocked']) || strpos(strtolower($result['error'] ?? ''), 'unauthorized') !== false) {
                http_response_code(403);
            } else {
                http_response_code(400);
            }
        }
        echo json_encode($result);
        break;

    case 'get_qualification_data':
        $fromRoundId = isset($_GET['from_round_id']) ? (int)$_GET['from_round_id'] : 0;
        if ($fromRoundId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid round ID.']);
            exit;
        }

        $data = get_stage_qualification_candidates($fromRoundId, $currentUserId);
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'preview_advancement':
        $fromRoundId = isset($_GET['from_round_id']) ? (int)$_GET['from_round_id'] : 0;
        if ($fromRoundId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid round ID.']);
            exit;
        }

        $preview = preview_stage_advancement($fromRoundId, $currentUserId);
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
        $manualOrderMap = isset($input['manual_order_map']) && is_array($input['manual_order_map'])
            ? $input['manual_order_map']
            : null;

        if ($fromRoundId <= 0 || empty($qualifiers)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid round or empty qualifier list.']);
            exit;
        }

        $res = commit_stage_advancement($fromRoundId, $qualifiers, $currentUserId, $manualOrderMap);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res);
        break;

    case 'get_stage_reopen_info':
        require_admin(true);
        $roundId = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;
        if ($roundId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid round ID.']);
            exit;
        }
        $info = get_stage_reopen_info($roundId, $currentUserId);
        echo json_encode($info);
        break;

    case 'admin_stage_correction':
    case 'reopen_stage':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }
        require_admin(true);
        $roundId = isset($input['round_id']) ? (int)$input['round_id'] : 0;
        $reason = trim($input['reason'] ?? '');
        if ($roundId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid round ID.']);
            exit;
        }
        if (strlen($reason) < 10) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'A documented technical reason of at least 10 characters is required for exceptional administrative corrections.'
            ]);
            exit;
        }
        $res = admin_correct_tournament_stage($roundId, $reason, $currentUserId, $currentUserId);
        if (!$res['success']) {
            http_response_code(400);
        }
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

        $res = save_song_media($songId, $videoInput, $versionType, $versionNotes, $embedStatus, $title, $channelTitle, $userUrl, $currentUserId);
        if (!$res['success']) {
            if (strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
                http_response_code(403);
            } else {
                http_response_code(400);
            }
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

        $res = remove_song_media($songId, $currentUserId);
        if (!$res['success'] && strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
            http_response_code(403);
        }
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

        $res = update_song_media_embed_status($songId, $status, $currentUserId);
        if (!$res['success'] && strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
            http_response_code(403);
        }
        echo json_encode($res);
        break;

    // --- LRCLIB Lyrics Endpoints ---

    case 'get_lyrics':
        $songId       = isset($_GET['song_id']) ? (int)$_GET['song_id'] : (int)($input['song_id'] ?? 0);
        $title        = trim($_GET['title'] ?? ($input['title'] ?? ''));
        $artist       = trim($_GET['artist'] ?? ($input['artist'] ?? ''));
        $forceRefresh = !empty($_GET['refresh']) || !empty($input['refresh']);

        // Ground in authoritative catalogue record if song exists
        if ($songId > 0) {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("SELECT `title`, `artist`, `user_id` FROM `songs` WHERE `song_id` = :id LIMIT 1");
            $stmt->execute([':id' => $songId]);
            $songRow = $stmt->fetch();
            if ($songRow) {
                if ($currentUserId !== null && $songRow['user_id'] !== null && (int)$songRow['user_id'] !== (int)$currentUserId) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Unauthorized: Song belongs to another user.']);
                    exit;
                }
                // Ensure current authoritative catalogue title is used (prevents stale frontend button attributes)
                $title = trim($songRow['title']);
                $dbArtist = trim($songRow['artist'] ?? '');
                if (!empty($dbArtist) && $dbArtist !== 'Unknown Artist') {
                    $artist = $dbArtist;
                } elseif (empty($artist) || $artist === 'Unknown Artist') {
                    $artist = '';
                }
            }
        }

        // Allow optional custom search overrides (e.g. from corrected title/artist search)
        $customTitle = trim($_GET['custom_title'] ?? ($input['custom_title'] ?? ''));
        $customArtist = trim($_GET['custom_artist'] ?? ($input['custom_artist'] ?? ''));
        if ($customTitle !== '') {
            $title = $customTitle;
        }
        if ($customArtist !== '') {
            $artist = $customArtist;
        }

        if ($songId <= 0 || empty($title)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Song ID and Title are required.']);
            exit;
        }

        $provider = trim($_GET['provider'] ?? ($input['provider'] ?? 'auto'));
        $res = fetch_multisource_lyrics($songId, $title, $artist, $currentUserId, $provider, $forceRefresh);
        echo json_encode($res);
        break;

    case 'save_manual_lyrics':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $songId = isset($input['song_id']) ? (int)$input['song_id'] : 0;
        $lyrics = isset($input['lyrics']) ? (string)$input['lyrics'] : '';

        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid song ID.']);
            exit;
        }

        $res = save_user_manual_lyrics($songId, $lyrics, $currentUserId);
        if (!$res['success'] && strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
            http_response_code(403);
        }
        echo json_encode($res);
        break;

    case 'clear_manual_lyrics':
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

        $res = clear_user_manual_lyrics($songId, $currentUserId);
        echo json_encode($res);
        break;

    case 'get_manual_lyrics':
        $songId = isset($_GET['song_id']) ? (int)$_GET['song_id'] : (int)($input['song_id'] ?? 0);
        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid song ID.']);
            exit;
        }

        $manual = get_user_manual_lyrics($songId, $currentUserId);
        echo json_encode([
            'success'   => true,
            'has_manual'=> ($manual !== null && !empty($manual['lyrics_text'])),
            'lyrics'    => $manual['lyrics_text'] ?? null,
            'updated_at'=> $manual['updated_at'] ?? null
        ]);
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

        if ($currentUserId !== null) {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("SELECT `user_id` FROM `songs` WHERE `song_id` = :id LIMIT 1");
            $stmt->execute([':id' => $songId]);
            $sRow = $stmt->fetch();
            if ($sRow && $sRow['user_id'] !== null && (int)$sRow['user_id'] !== (int)$currentUserId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Unauthorized: Cannot modify lyrics for another user\'s song.']);
                exit;
            }
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

        $res = clear_song_lyrics_ref($songId, $currentUserId);
        if (!$res['success'] && strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
            http_response_code(403);
        }
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

        if ($currentUserId !== null) {
            $pdo = get_db_connection();
            $chk = $pdo->prepare("SELECT `user_id` FROM `songs` WHERE `song_id` = :id LIMIT 1");
            $chk->execute([':id' => $songId]);
            $sRow = $chk->fetch();
            if ($sRow && $sRow['user_id'] !== null && (int)$sRow['user_id'] !== (int)$currentUserId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Unauthorized: Cannot modify artwork for another user\'s song.']);
                exit;
            }
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

        if ($currentUserId !== null) {
            $pdo = get_db_connection();
            $chk = $pdo->prepare("SELECT `user_id` FROM `songs` WHERE `song_id` = :id LIMIT 1");
            $chk->execute([':id' => $songId]);
            $sRow = $chk->fetch();
            if ($sRow && $sRow['user_id'] !== null && (int)$sRow['user_id'] !== (int)$currentUserId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Unauthorized: Cannot modify artwork for another user\'s song.']);
                exit;
            }
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
            $data = detect_duplicate_candidates($filters, $currentUserId);
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

        if ($currentUserId !== null) {
            $pdo = get_db_connection();
            $chk = $pdo->prepare("SELECT `user_id` FROM `songs` WHERE `song_id` IN (:a, :b)");
            $chk->execute([':a' => $songIdA, ':b' => $songIdB]);
            $uids = $chk->fetchAll(PDO::FETCH_COLUMN);
            foreach ($uids as $u) {
                if ($u !== null && (int)$u !== (int)$currentUserId) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Unauthorized: Song belongs to another user.']);
                    exit;
                }
            }
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
            $existing = find_existing_catalogue_song($data['title'], $data['artist'], $currentUserId);
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
        $existing = find_existing_catalogue_song($title, $artist, $currentUserId);
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

        $res = create_catalogue_song($title, $artist, $youtubeUrl, $discoveryStatus, $currentUserId);
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

        $res = update_catalogue_song($songId, $title, $artist, $youtubeUrl, $currentUserId);
        if (!$res['success']) {
            if (strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
                http_response_code(403);
            } else {
                http_response_code(400);
            }
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

        try {
            $res = delete_catalogue_song($songId, $currentUserId);
            if (!$res['success']) {
                if (strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
                    http_response_code(403);
                } else {
                    http_response_code(!empty($res['blocked']) ? 409 : 400);
                }
            }
            echo json_encode($res);
        } catch (\Throwable $e) {
            error_log("AFM2 delete_song exception: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success'    => false,
                'error_code' => 'server_error',
                'error'      => 'A server error occurred while deleting the song. The technical incident has been logged. Please try again later.'
            ]);
        }
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

        try {
            $res = delete_multiple_catalogue_songs($rawSongIds, $currentUserId);
            if (!$res['success']) {
                if (strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
                    http_response_code(403);
                } else {
                    http_response_code(!empty($res['blocked']) ? 409 : 400);
                }
            }
            echo json_encode($res);
        } catch (\Throwable $e) {
            error_log("AFM2 delete_multiple_songs exception: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success'    => false,
                'error_code' => 'server_error',
                'error'      => 'A server error occurred during bulk deletion. The technical incident has been logged. Please try again later.'
            ]);
        }
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

        $songId = isset($input['song_id']) ? (int)$input['song_id'] : (isset($_GET['song_id']) ? (int)$_GET['song_id'] : 0);
        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid song ID.']);
            exit;
        }

        $pdo = get_db_connection();
        $songStmt = $pdo->prepare("SELECT `song_id`, `title`, `artist`, `user_id` FROM `songs` WHERE `song_id` = :id");
        $songStmt->execute([':id' => $songId]);
        $song = $songStmt->fetch();
        if (!$song) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Song not found in catalogue.']);
            exit;
        }
        if ($currentUserId !== null && $song['user_id'] !== null && (int)$song['user_id'] !== (int)$currentUserId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: Song belongs to another user.']);
            exit;
        }

        $lyricsStmt = $pdo->prepare("SELECT `duration` FROM `song_lyrics_ref` WHERE `song_id` = :id");
        $lyricsStmt->execute([':id' => $songId]);
        $lyricsRow = $lyricsStmt->fetch();
        $expectedDuration = ($lyricsRow && !empty($lyricsRow['duration'])) ? (int)round((float)$lyricsRow['duration']) : null;

        $forceRefresh = !empty($input['force_refresh']) || !empty($_POST['force_refresh']);
        $candidates = [];

        // Acquire short-timeout MySQL advisory lock to serialize concurrent discovery requests for the same song
        $lockKey = "afm2_discover_song_{$songId}";
        $hasLock = false;
        try {
            $lockStmt = $pdo->prepare("SELECT GET_LOCK(:k, 3)");
            $lockStmt->execute([':k' => $lockKey]);
            $hasLock = (int)$lockStmt->fetchColumn() === 1;
        } catch (Exception $e) {
            $hasLock = false;
        }

        try {
            if (!$forceRefresh) {
                $existing = YouTubeMatcher::get_candidates_for_song($songId);
                if (!empty($existing)) {
                    $candidates = $existing;
                }
            } else {
                // Safeguard against accidental rapid duplicate discovery (5-second debounce)
                // Use MySQL TIMESTAMPDIFF to eliminate PHP vs MySQL timezone offset mismatches
                $checkRecentStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, MAX(`updated_at`), NOW()) FROM `song_youtube_candidates` WHERE `song_id` = :id");
                $checkRecentStmt->execute([':id' => $songId]);
                $diffSec = $checkRecentStmt->fetchColumn();
                if ($diffSec !== null && $diffSec !== false && (int)$diffSec >= 0 && (int)$diffSec < 5) {
                    $candidates = YouTubeMatcher::get_candidates_for_song($songId);
                }
            }

            if (empty($candidates)) {
                $rawCandidates = YouTubeMatcher::discover_candidates($song['title'], $song['artist']);
                $candidates = YouTubeMatcher::score_and_rank_candidates($song['title'], $song['artist'], $rawCandidates, $expectedDuration);
                YouTubeMatcher::save_candidates_for_song($songId, $candidates);
            }
        } finally {
            if ($hasLock) {
                try {
                    $pdo->prepare("SELECT RELEASE_LOCK(:k)")->execute([':k' => $lockKey]);
                } catch (Exception $e) {
                    // Advisory lock auto-releases on connection close
                }
            }
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
        $songStmt = $pdo->prepare("SELECT `song_id`, `title`, `artist`, `user_id` FROM `songs` WHERE `song_id` = :id");
        $songStmt->execute([':id' => $songId]);
        $song = $songStmt->fetch();
        if (!$song) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Song not found in catalogue.']);
            exit;
        }
        if ($currentUserId !== null && $song['user_id'] !== null && (int)$song['user_id'] !== (int)$currentUserId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: Song belongs to another user.']);
            exit;
        }

        // Pure read-only candidate retrieval. Side-effecting discovery and writes are restricted to POST youtube_discover_candidates.
        $candidates = YouTubeMatcher::get_candidates_for_song($songId);
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

        $res = YouTubeMatcher::manual_select_candidate($songId, $videoId, $versionType, $versionNotes, $currentUserId);
        if (!$res['success']) {
            if (strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
                http_response_code(403);
            } else {
                http_response_code(400);
            }
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

        $res = YouTubeMatcher::manual_save_url($songId, $urlOrId, $versionType, $versionNotes, $currentUserId);
        if (!$res['success']) {
            if (strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
                http_response_code(403);
            } else {
                http_response_code(400);
            }
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

        $res = YouTubeMatcher::clear_recording($songId, $currentUserId);
        if (!$res['success'] && strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
            http_response_code(403);
        }
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

        $res = YouTubeMatcher::verify_playback_and_embed($videoId, $songId, $currentUserId);
        if (!$res['success'] && strpos(strtolower($res['error'] ?? ''), 'unauthorized') !== false) {
            http_response_code(403);
        }
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

        $res = YouTubeMatcher::process_batch($batchSize, ['force' => $force], $currentUserId);
        $stats = YouTubeMatcher::get_matching_stats(false, $currentUserId);
        $res['stats'] = $stats;
        echo json_encode($res);
        break;

    case 'youtube_job_status':
        $job = YouTubeMatcher::get_or_create_active_job($currentUserId);
        $stats = YouTubeMatcher::get_matching_stats(false, $currentUserId);
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

        YouTubeMatcher::reset_job($currentUserId);
        $job = YouTubeMatcher::get_or_create_active_job($currentUserId);
        $stats = YouTubeMatcher::get_matching_stats(false, $currentUserId);
        echo json_encode([
            'success' => true,
            'message' => 'Matching job reset successfully.',
            'job'     => $job,
            'stats'   => $stats
        ]);
        break;

    // ==================== WEBAUTHN / PASSKEY ACTIONS ====================
    case 'webauthn_register_options':
        $userId = get_current_user_id();
        $username = get_current_username();
        $options = WebAuthnService::generate_registration_options($userId, $username);
        echo json_encode(['success' => true, 'options' => $options]);
        break;

    case 'webauthn_register_verify':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }
        $userId = get_current_user_id();
        $deviceName = trim($input['device_name'] ?? 'Passkey');
        $credData = $input['credential'] ?? $input;
        $result = WebAuthnService::verify_registration_response($userId, $credData, $deviceName);
        echo json_encode($result);
        break;

    case 'webauthn_auth_options':
        $username = trim($input['username'] ?? $_GET['username'] ?? '');
        $options = WebAuthnService::generate_authentication_options($username ?: null);
        echo json_encode(['success' => true, 'options' => $options]);
        break;

    case 'webauthn_auth_verify':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }
        $credData = $input['credential'] ?? $input;
        $result = WebAuthnService::verify_authentication_response($credData);
        echo json_encode($result);
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Unknown API action.']);
        break;
}
