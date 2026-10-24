<?php
/**
 * AFM#2 - Audio & Lyrics Media Helper
 * Handles zero-cost YouTube media matching, embedding, and LRCLIB on-demand lyrics integration.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';


/**
 * Valid YouTube 11-character video ID extractor
 * Supports raw IDs, watch URLs, share URLs, embed URLs, YouTube Music URLs, Shorts, and Live URLs
 */
function parse_youtube_id(string $input): ?string {
    $input = trim($input, " \t\n\r\0\x0B\"'<>");
    if ($input === '') {
        return null;
    }

    // Direct 11-char ID
    if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $input)) {
        return $input;
    }

    // Comprehensive regex covering standard watch, shorts, live, embed, nocookie, youtu.be, and music.youtube.com
    $pattern = '/^(?:https?:\/\/)?(?:(?:www|m|music)\.)?(?:youtube(?:-nocookie)?\.com\/(?:(?:watch\S*?[?&]v=)|(?:shorts|live|embed|e|v)\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i';
    if (preg_match($pattern, $input, $matches)) {
        return $matches[1];
    }

    // Fallback: If URL has query string with v=11chars on youtube/music.youtube domain
    if (preg_match('/^(?:https?:\/\/)?(?:[a-z0-9-]+\.)?youtube(?:-nocookie)?\.com\/[^\s]*?[?&]v=([a-zA-Z0-9_-]{11})/i', $input, $matches)) {
        return $matches[1];
    }

    return null;
}


/**
 * Clean track title by removing video junk tags, remaster tags, and featured artist suffixes.
 * Inspired conceptually by Echo Music's MetadataCleaner and CleanerRules.
 */
function clean_track_title_for_lyrics(string $title): string {
    $t = trim($title);
    if (empty($t)) {
        return '';
    }

    // 1. Remove bracketed / parenthesized video/audio and upload/quality tags:
    // e.g. [Official Video], (Official Music Video), [Official Audio], (Visualizer), [MV], (Audio), [HD], (4K), [4K Upgrade], [1080p HD], [Lyrics]
    $junkTagPattern = '/\s*[\[\(](?:official\s*(?:(?:4k|hd|uhd|1080p)\s*)?(?:music\s*video|lyric\s*video|video|audio|track|visualizer|clip|stream)|music\s*video|lyric\s*video|video|audio|mv|visualizer|clip\s*officiel|topic|lyrics|(?:4k|hd|uhd|1080p|720p|hq|ultra\s*hd)(?:\s*(?:upgrade|upscale|restored|restoration|remaster(?:ed)?|60fps|hd|video|audio|clip))?|(?:ai\s*)?(?:upgrade|upscale|restored|restoration)|60fps)[\]\)]/iu';
    $t = preg_replace($junkTagPattern, '', $t);

    // 1b. Remove video/quality junk after hyphens: e.g. - Official Video, - 4K Upgrade
    $hyphenJunkPattern = '/\s*-\s*(?:(?:official\s*)?(?:music\s*video|lyric\s*video|video|audio|visualizer)|4k(?:\s*upgrade)?|hd(?:\s*upgrade)?)$/iu';
    $t = preg_replace($hyphenJunkPattern, '', $t);

    // 2. Remove remaster / edition tags in brackets/parentheses or after hyphens:
    // e.g. (Remastered 2011), [2021 Remaster], (Remastered), - Remastered 2011, (Anniversary Edition), (Deluxe Edition), (4K Remaster)
    $remasterPattern = '/\s*[\[\(](?:(?:\d{4}|4k|hd)?\s*remaster(?:ed)?(?:\s*\d{4})?|anniversary(?:\s*edition)?|deluxe(?:\s*edition)?|bonus\s*track|expanded(?:\s*edition)?)[\]\)]/iu';
    $t = preg_replace($remasterPattern, '', $t);

    $hyphenRemasterPattern = '/\s*-\s*(?:(?:\d{4}|4k|hd)?\s*remaster(?:ed)?(?:\s*\d{4})?|\d{4}\s*remaster|deluxe\s*edition)$/iu';
    $t = preg_replace($hyphenRemasterPattern, '', $t);

    // 3. Remove featured artists from title (e.g. (feat. Drake) or [ft. Eminem] or feat. Drake)
    $featPattern = '/\s*[\[\(](?:feat\.?|ft\.?)\s+[^\]\)]+[\]\)]/iu';
    $t = preg_replace($featPattern, '', $t);
    $featEndPattern = '/\s+(?:feat\.?|ft\.?)\s+.+$/iu';
    $t = preg_replace($featEndPattern, '', $t);

    // 4. Normalize spaces and trim punctuation (preserve genuine version parenthesis like Live/Acoustic)
    $t = preg_replace('/\s+/u', ' ', $t);
    $t = trim($t, " \t\n\r\0\x0B-–—");

    return $t !== '' ? $t : $title;
}

/**
 * Clean artist name and handle "Artist - Title" splitting if artist is unknown or generic.
 * Conceptually inspired by Echo Music's MetadataCleaner artist separation.
 */
function clean_artist_for_lyrics(string $artist, string &$title): string {
    $cleanArtist = trim($artist);
    if ($cleanArtist === 'Unknown Artist' || $cleanArtist === 'Various Artists' || empty($cleanArtist)) {
        // Check if title has "Artist - Track" format
        if (preg_match('/^([^-–—]+)\s*[-–—]\s*(.+)$/u', $title, $matches)) {
            $extractedArtist = trim($matches[1]);
            $extractedTitle = trim($matches[2]);
            if (!empty($extractedArtist) && !empty($extractedTitle)) {
                $cleanArtist = $extractedArtist;
                $title = $extractedTitle;
            }
        } else {
            $cleanArtist = '';
        }
    }

    return $cleanArtist;
}

/**
 * Generate zero-cost YouTube search link without requiring an API key
 */
function get_youtube_search_url(string $title, string $artist): string {
    $tempTitle = $title;
    $cleanArtist = clean_artist_for_lyrics($artist, $tempTitle);
    $cleanTitle = clean_track_title_for_lyrics($tempTitle);
    $query = trim("{$cleanTitle} {$cleanArtist} official audio");
    return 'https://www.youtube.com/results?search_query=' . urlencode($query);
}

/**
 * Generate zero-cost YouTube Music search link
 */
function get_youtube_music_search_url(string $title, string $artist): string {
    $tempTitle = $title;
    $cleanArtist = clean_artist_for_lyrics($artist, $tempTitle);
    $cleanTitle = clean_track_title_for_lyrics($tempTitle);
    $query = trim("{$cleanTitle} {$cleanArtist}");
    return 'https://music.youtube.com/search?q=' . urlencode($query);
}

/**
 * Generate zero-cost Spotify search link without requiring an API key
 */
function get_spotify_search_url(string $title, string $artist): string {
    $tempTitle = $title;
    $cleanArtist = clean_artist_for_lyrics($artist, $tempTitle);
    $cleanTitle = clean_track_title_for_lyrics($tempTitle);
    $query = trim("{$cleanTitle} {$cleanArtist}");
    return 'https://open.spotify.com/search/' . urlencode($query);
}

/**
 * Generate zero-cost SoundCloud search link without requiring an API key
 */
function get_soundcloud_search_url(string $title, string $artist): string {
    $tempTitle = $title;
    $cleanArtist = clean_artist_for_lyrics($artist, $tempTitle);
    $cleanTitle = clean_track_title_for_lyrics($tempTitle);
    $query = trim("{$cleanTitle} {$cleanArtist}");
    return 'https://soundcloud.com/search?q=' . urlencode($query);
}

/**
 * Retrieve confirmed media for a song
 */
function get_song_media(int $songId, string $service = 'youtube'): ?array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT * 
        FROM `song_media` 
        WHERE `song_id` = :song_id AND `service` = :service 
        LIMIT 1
    ");
    $stmt->execute([':song_id' => $songId, ':service' => $service]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $videoId = $row['video_id'];
    $row['watch_url'] = "https://www.youtube.com/watch?v={$videoId}";
    $row['music_url'] = "https://music.youtube.com/watch?v={$videoId}";
    $row['embed_url'] = "https://www.youtube-nocookie.com/embed/{$videoId}?autoplay=0&rel=0&enablejsapi=1";
    $row['canonical_url'] = !empty($row['user_url']) ? $row['user_url'] : $row['watch_url'];
    return $row;
}

/**
 * Save user confirmed media reference (YouTube video ID, version type, notes, original user link)
 */
function save_song_media(
    int $songId, 
    string $videoIdOrUrl, 
    string $versionType = 'original', 
    ?string $versionNotes = null, 
    string $embedStatus = 'allowed',
    ?string $title = null,
    ?string $channelTitle = null,
    ?string $userUrl = null
): array {
    $videoId = parse_youtube_id($videoIdOrUrl);
    if (!$videoId) {
        return [
            'success' => false, 
            'error' => 'Invalid YouTube Video ID or URL. Please provide an 11-character video ID or valid YouTube/YouTube Music link (watch, youtu.be, shorts, live, or embed).'
        ];
    }

    $validVersions = ['original', 'live', 'acoustic', 'cover', 'remaster', 'other'];
    if (!in_array($versionType, $validVersions, true)) {
        $versionType = 'original';
    }

    $validEmbed = ['allowed', 'blocked', 'unknown'];
    if (!in_array($embedStatus, $validEmbed, true)) {
        $embedStatus = 'allowed';
    }

    $versionNotes = $versionNotes !== null ? trim($versionNotes) : null;
    if ($versionNotes === '') {
        $versionNotes = null;
    }

    $cleanUserUrl = $userUrl !== null ? trim($userUrl) : null;
    if (empty($cleanUserUrl) && preg_match('/^https?:\/\//i', trim($videoIdOrUrl))) {
        $cleanUserUrl = trim($videoIdOrUrl);
    }

    $pdo = get_db_connection();

    // Verify song exists
    $checkSong = $pdo->prepare("SELECT `song_id`, `title`, `artist` FROM `songs` WHERE `song_id` = :id");
    $checkSong->execute([':id' => $songId]);
    $song = $checkSong->fetch();
    if (!$song) {
        return ['success' => false, 'error' => 'Song not found in catalogue.'];
    }

    $resolvedTitle = $title ?: $song['title'];
    $resolvedChannel = $channelTitle;

    // If title or channel title are missing, attempt metadata extraction via oEmbed
    if (empty($resolvedChannel)) {
        $meta = extract_youtube_metadata($videoId);
        if ($meta['success']) {
            if (empty($title) && !empty($meta['title'])) {
                $resolvedTitle = $meta['title'];
            }
            if (!empty($meta['channel_title'])) {
                $resolvedChannel = $meta['channel_title'];
            }
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO `song_media` 
            (`song_id`, `service`, `user_url`, `video_id`, `title`, `channel_title`, `version_type`, `version_notes`, `embed_status`, `match_method`, `confidence`, `verification_status`, `is_manual`, `updated_at`)
        VALUES 
            (:song_id, 'youtube', :user_url, :video_id, :title, :channel_title, :version_type, :version_notes, :embed_status, 'manual', 'manual', 'verified', 1, NOW())
        ON DUPLICATE KEY UPDATE
            `user_url` = VALUES(`user_url`),
            `video_id` = VALUES(`video_id`),
            `title` = VALUES(`title`),
            `channel_title` = VALUES(`channel_title`),
            `version_type` = VALUES(`version_type`),
            `version_notes` = VALUES(`version_notes`),
            `embed_status` = VALUES(`embed_status`),
            `match_method` = 'manual',
            `confidence` = 'manual',
            `verification_status` = 'verified',
            `is_manual` = 1,
            `updated_at` = NOW()
    ");

    $stmt->execute([
        ':song_id'       => $songId,
        ':user_url'      => $cleanUserUrl,
        ':video_id'      => $videoId,
        ':title'         => $resolvedTitle,
        ':channel_title' => $resolvedChannel,
        ':version_type'  => $versionType,
        ':version_notes' => $versionNotes,
        ':embed_status'  => $embedStatus
    ]);

    // Automatically sync confirmed YouTube thumbnail to song_artwork if not custom
    try {
        $artStmt = $pdo->prepare("
            INSERT INTO `song_artwork` (`song_id`, `source`, `thumbnail_url`, `image_url`, `is_confirmed`, `status`)
            VALUES (:id, 'confirmed', :thumb, :img, 1, 'matched')
            ON DUPLICATE KEY UPDATE 
                `thumbnail_url` = VALUES(`thumbnail_url`), 
                `image_url` = VALUES(`image_url`),
                `is_confirmed` = 1,
                `status` = 'matched'
        ");
        $artStmt->execute([
            ':id'    => $songId,
            ':thumb' => "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg",
            ':img'   => "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg"
        ]);
    } catch (\Throwable $e) {}

    return [
        'success' => true,
        'media' => [
            'song_id'       => $songId,
            'service'       => 'youtube',
            'user_url'      => $cleanUserUrl,
            'video_id'      => $videoId,
            'title'         => $resolvedTitle,
            'channel_title' => $resolvedChannel,
            'version_type'  => $versionType,
            'version_notes' => $versionNotes,
            'embed_status'  => $embedStatus,
            'watch_url'     => "https://www.youtube.com/watch?v={$videoId}",
            'music_url'     => "https://music.youtube.com/watch?v={$videoId}",
            'embed_url'     => "https://www.youtube-nocookie.com/embed/{$videoId}?autoplay=0&rel=0&enablejsapi=1",
            'canonical_url' => $cleanUserUrl ?: "https://www.youtube.com/watch?v={$videoId}"
        ]
    ];
}

/**
 * Remove confirmed media reference
 */
function remove_song_media(int $songId, string $service = 'youtube'): array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("DELETE FROM `song_media` WHERE `song_id` = :song_id AND `service` = :service");
    $stmt->execute([':song_id' => $songId, ':service' => $service]);

    // Also remove auto-synced YouTube artwork thumbnail
    try {
        $artDel = $pdo->prepare("DELETE FROM `song_artwork` WHERE `song_id` = :song_id AND `source` = 'confirmed'");
        $artDel->execute([':song_id' => $songId]);
    } catch (\Throwable $e) {}

    return ['success' => true];
}

/**
 * Extract YouTube metadata (title, artist, channel, thumbnail, video ID)
 * using zero-cost oEmbed and smart regex parsing without requiring an API key.
 */
function extract_youtube_metadata(string $urlOrVideoId): array {
    $videoId = parse_youtube_id($urlOrVideoId);
    if (!$videoId) {
        return [
            'success' => false, 
            'error'   => 'Invalid YouTube link or video ID. Please provide an 11-character video ID or valid YouTube/YouTube Music URL.'
        ];
    }

    $fallbackThumbnail = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
    $embedUrl = "https://www.youtube-nocookie.com/embed/{$videoId}?autoplay=0&rel=0";
    $watchUrl = "https://www.youtube.com/watch?v={$videoId}";

    $oembedUrl = 'https://www.youtube.com/oembed?url=' . urlencode($watchUrl) . '&format=json';
    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 4,
            'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AFM2/1.0',
            'ignore_errors' => true
        ]
    ]);

    $raw = @file_get_contents($oembedUrl, false, $ctx);
    if (!$raw) {
        return [
            'success'       => true,
            'video_id'      => $videoId,
            'title'         => '',
            'artist'        => '',
            'channel_title' => '',
            'thumbnail_url' => $fallbackThumbnail,
            'raw_title'     => '',
            'watch_url'     => $watchUrl,
            'embed_url'     => $embedUrl,
            'warning'       => 'Metadata could not be fetched automatically from YouTube oEmbed. You can enter the title and artist manually.'
        ];
    }

    $data = json_decode($raw, true) ?: [];
    $rawTitle = trim($data['title'] ?? '');
    $channel = trim($data['author_name'] ?? '');
    $channelClean = preg_replace('/\s*-\s*Topic$/iu', '', $channel);

    $cleanedTitle = clean_track_title_for_lyrics($rawTitle);
    $candidateArtist = $channelClean;
    $candidateTitle = $cleanedTitle;

    // Check for "Artist - Title" or "Artist – Title" in cleaned title
    if (preg_match('/^([^-–—]+)\s*[-–—]\s*(.+)$/u', $cleanedTitle, $matches)) {
        $part1 = trim($matches[1]);
        $part2 = trim($matches[2]);
        if (!empty($channelClean) && stripos($part1, $channelClean) !== false) {
            $candidateArtist = $channelClean;
            $candidateTitle = clean_track_title_for_lyrics($part2);
        } else {
            $candidateArtist = $part1;
            $candidateTitle = clean_track_title_for_lyrics($part2);
        }
    }

    $thumbnailUrl = $data['thumbnail_url'] ?? $fallbackThumbnail;

    return [
        'success'       => true,
        'video_id'      => $videoId,
        'title'         => $candidateTitle,
        'artist'        => $candidateArtist,
        'channel_title' => $channelClean,
        'thumbnail_url' => $thumbnailUrl,
        'raw_title'     => $rawTitle,
        'watch_url'     => $watchUrl,
        'embed_url'     => $embedUrl
    ];
}

/**
 * Validate, resolve and preview candidate YouTube/YouTube Music recording before saving.
 * Extracts video ID, fetches oEmbed metadata, checks saved status, and builds normalized playback URLs.
 */
function resolve_youtube_recording(string $urlOrVideoId, ?int $songId = null): array {
    $videoId = parse_youtube_id($urlOrVideoId);
    if (!$videoId) {
        return [
            'success' => false,
            'error'   => 'Could not identify a valid YouTube or YouTube Music video ID from the provided link. Please provide a standard watch link, youtu.be, shorts, live, or 11-character video ID.'
        ];
    }

    $meta = extract_youtube_metadata($videoId);
    $watchUrl = "https://www.youtube.com/watch?v={$videoId}";
    $musicUrl = "https://music.youtube.com/watch?v={$videoId}";
    $embedUrl = "https://www.youtube-nocookie.com/embed/{$videoId}?autoplay=0&rel=0&enablejsapi=1";

    $existingMedia = null;
    if ($songId !== null && $songId > 0) {
        $existingMedia = get_song_media($songId);
    }

    return [
        'success'           => true,
        'video_id'          => $videoId,
        'user_url'          => trim($urlOrVideoId),
        'title'             => $meta['title'] ?? '',
        'artist'            => $meta['artist'] ?? '',
        'channel_title'     => $meta['channel_title'] ?? '',
        'thumbnail_url'     => $meta['thumbnail_url'] ?? "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg",
        'watch_url'         => $watchUrl,
        'music_url'         => $musicUrl,
        'embed_url'         => $embedUrl,
        'is_already_saved'  => ($existingMedia && $existingMedia['video_id'] === $videoId),
        'current_saved_id'  => $existingMedia['video_id'] ?? null,
        'current_version'   => $existingMedia['version_type'] ?? null,
        'current_notes'     => $existingMedia['version_notes'] ?? null
    ];
}



/**
 * Update embed status (e.g. when user reports embedding is blocked by YouTube)
 */
function update_song_media_embed_status(int $songId, string $embedStatus): array {
    $validEmbed = ['allowed', 'blocked', 'unknown'];
    if (!in_array($embedStatus, $validEmbed, true)) {
        return ['success' => false, 'error' => 'Invalid embed status.'];
    }

    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        UPDATE `song_media` 
        SET `embed_status` = :status, `updated_at` = NOW() 
        WHERE `song_id` = :song_id AND `service` = 'youtube'
    ");
    $stmt->execute([':status' => $embedStatus, ':song_id' => $songId]);
    return ['success' => true, 'embed_status' => $embedStatus];
}

/**
 * Helper to make HTTP requests with proper User-Agent identification & timeouts
 */
function http_get_json(string $url, int $timeoutSeconds = 5): array {
    // If a mock handler is registered (for automated testing without external API dependence)
    if (isset($GLOBALS['__mock_http_get_json']) && is_callable($GLOBALS['__mock_http_get_json'])) {
        return call_user_func($GLOBALS['__mock_http_get_json'], $url, $timeoutSeconds);
    }

    $userAgent = 'AFM2-MusicJournal/1.0 (https://github.com/afm2-app; contact: personal-audition-journal)';
    
    $opts = [
        'http' => [
            'method'        => 'GET',
            'header'        => "User-Agent: {$userAgent}\r\nAccept: application/json\r\n",
            'timeout'       => $timeoutSeconds,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true
        ]
    ];

    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    $statusCode = 0;
    $retryAfter = null;

    $headers = function_exists('http_get_last_response_headers') 
        ? (http_get_last_response_headers() ?? []) 
        : ($http_response_header ?? []);

    if (!empty($headers)) {
        if (preg_match('#HTTP/\S+\s+(\d{3})#i', $headers[0], $m)) {
            $statusCode = (int)$m[1];
        }
        foreach ($headers as $hdr) {
            if (stripos($hdr, 'Retry-After:') === 0) {
                $retryAfter = trim(substr($hdr, 12));
            }
        }
    }

    if ($response === false && $statusCode === 0) {
        return [
            'success'     => false,
            'status_code' => 0,
            'error'       => 'Network connection timeout or DNS resolution failure.'
        ];
    }

    $decoded = json_decode($response, true);
    $isJsonValid = (json_last_error() === JSON_ERROR_NONE && is_array($decoded));

    return [
        'success'     => ($statusCode >= 200 && $statusCode < 300 && $isJsonValid),
        'status_code' => $statusCode,
        'retry_after' => $retryAfter,
        'data'        => $isJsonValid ? $decoded : null,
        'error'       => (!$isJsonValid && $statusCode >= 200) ? 'Malformed JSON response from lyrics service.' : null,
        'raw_body'    => $response
    ];
}

/**
 * Retrieve saved lyrics reference for a song
 */
function get_saved_song_lyrics(int $songId): ?array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM `song_lyrics_ref` WHERE `song_id` = :id LIMIT 1");
    $stmt->execute([':id' => $songId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Fetch lyrics on-demand from LRCLIB for a song
 */
function fetch_lrclib_lyrics(
    int $songId, 
    string $title, 
    string $artist, 
    bool $forceRefresh = false,
    ?float $targetDuration = null
): array {
    $pdo = get_db_connection();

    // Normalize input metadata
    $normTitle = trim(preg_replace('/\s+/u', ' ', $title));
    $tempTitle = $normTitle;
    $normArtist = clean_artist_for_lyrics($artist, $tempTitle);
    if ($tempTitle !== $normTitle && !empty($tempTitle)) {
        $normTitle = $tempTitle;
    }
    $cleanTitle = clean_track_title_for_lyrics($normTitle);
    $cleanArtist = clean_artist_for_lyrics($normArtist, $cleanTitle);

    // If targetDuration was not explicitly supplied, check if confirmed media exists
    if ($targetDuration === null && $songId > 0) {
        $savedMedia = get_song_media($songId);
        if ($savedMedia && !empty($savedMedia['duration'])) {
            $targetDuration = (float)$savedMedia['duration'];
        }
    }

    // 1. Check database reference / cache unless forceRefresh
    if (!$forceRefresh && $songId > 0) {
        $saved = get_saved_song_lyrics($songId);
        if ($saved) {
            $cachedTrack = trim($saved['track_name'] ?? '');
            $cachedArtist = trim($saved['artist_name'] ?? '');
            if ($cachedArtist === 'Unknown Artist') {
                $cachedArtist = '';
            }

            // Verify if cached metadata matches current requested song title & artist
            if ($saved['status'] === 'matched' || $saved['status'] === 'instrumental') {
                $trackMatches = (
                    strcasecmp($cachedTrack, $normTitle) === 0 || 
                    strcasecmp($cachedTrack, $cleanTitle) === 0 || 
                    stripos($normTitle, $cachedTrack) !== false || 
                    stripos($cachedTrack, $cleanTitle) !== false
                );
                $artistMatches = (
                    empty($normArtist) || 
                    empty($cachedArtist) || 
                    strcasecmp($cachedArtist, $normArtist) === 0 || 
                    strcasecmp($cachedArtist, $cleanArtist) === 0 || 
                    stripos($normArtist, $cachedArtist) !== false ||
                    stripos($cachedArtist, $normArtist) !== false
                );

                if ($trackMatches && $artistMatches) {
                    return [
                        'success'     => true,
                        'cached'      => true,
                        'status'      => $saved['status'],
                        'lrclib_id'   => $saved['lrclib_id'],
                        'track_name'  => $saved['track_name'],
                        'artist_name' => $saved['artist_name'],
                        'album_name'  => $saved['album_name'],
                        'duration'    => $saved['duration'],
                        'instrumental'=> (bool)$saved['instrumental'],
                        'has_synced'  => (bool)$saved['has_synced'],
                        'plain_lyrics'=> $saved['cached_lyrics_plain'],
                        'synced_lyrics'=> $saved['cached_lyrics_synced'],
                        'source_url'  => $saved['source_url'] ?: 'https://lrclib.net'
                    ];
                } else {
                    // Stale cache from a previous title/artist: clear and proceed fresh
                    clear_song_lyrics_ref($songId);
                }
            } elseif ($saved['status'] === 'not_found') {
                // Only honor negative cache if it was for the exact same track & artist
                $cachedMatchesCurrent = (
                    (strcasecmp($cachedTrack, $normTitle) === 0 || strcasecmp($cachedTrack, $cleanTitle) === 0) &&
                    (empty($normArtist) || strcasecmp($cachedArtist, $normArtist) === 0 || strcasecmp($cachedArtist, $cleanArtist) === 0)
                );

                if ($cachedMatchesCurrent) {
                    $cacheTime = strtotime($saved['updated_at'] ?? $saved['cached_at']);
                    // Negative cache TTL: 3600 seconds (1 hour) instead of 4 hours
                    if (time() - $cacheTime < 3600) {
                        return [
                            'success'    => false,
                            'cached'     => true,
                            'status'     => 'not_found',
                            'message'    => 'No lyrics found on LRCLIB for this song.',
                            'search_url' => 'https://www.google.com/search?q=' . urlencode("{$normTitle} {$normArtist} lyrics")
                        ];
                    }
                } else {
                    // Stale negative cache from before renaming: clear and proceed fresh
                    clear_song_lyrics_ref($songId);
                }
            }
        }
    }

    // 2. Query LRCLIB /api/get
    // Step 2a: Try exact normalized title and artist first (preserves genuine version information)
    $exactParams = ['track_name' => $normTitle];
    if (!empty($normArtist)) {
        $exactParams['artist_name'] = $normArtist;
    }
    $getUrl = 'https://lrclib.net/api/get?' . http_build_query($exactParams);
    $res = http_get_json($getUrl, 5);

    // Step 2b: If exact lookup returned 404, try cleaned title (upload/quality suffixes removed)
    if (!$res['success'] && $res['status_code'] === 404 && $cleanTitle !== '' && strcasecmp($cleanTitle, $normTitle) !== 0) {
        $cleanParams = ['track_name' => $cleanTitle];
        if (!empty($cleanArtist)) {
            $cleanParams['artist_name'] = $cleanArtist;
        }
        $cleanUrl = 'https://lrclib.net/api/get?' . http_build_query($cleanParams);
        $cleanRes = http_get_json($cleanUrl, 5);
        if ($cleanRes['success']) {
            $res = $cleanRes;
        }
    }

    // Exact Match Success (HTTP 200)
    if ($res['success'] && is_array($res['data'])) {
        $d = $res['data'];
        $isInstrumental = !empty($d['instrumental']);
        $plain = $d['plainLyrics'] ?? null;
        $synced = $d['syncedLyrics'] ?? null;
        $hasSynced = !empty($synced);
        $status = $isInstrumental ? 'instrumental' : 'matched';

        // Cache on-demand reference if valid song ID
        if ($songId > 0) {
            save_song_lyrics_ref($songId, [
                'lrclib_id'            => $d['id'] ?? null,
                'track_name'           => $d['trackName'] ?? $cleanTitle,
                'artist_name'          => $d['artistName'] ?? $cleanArtist,
                'album_name'           => $d['albumName'] ?? null,
                'duration'             => $d['duration'] ?? null,
                'instrumental'         => $isInstrumental ? 1 : 0,
                'has_synced'           => $hasSynced ? 1 : 0,
                'source_url'           => 'https://lrclib.net',
                'cached_lyrics_plain'  => $plain,
                'cached_lyrics_synced' => $synced,
                'status'               => $status
            ]);
        }

        return [
            'success'      => true,
            'cached'       => false,
            'match_type'   => 'exact',
            'status'       => $status,
            'lrclib_id'    => $d['id'] ?? null,
            'track_name'   => $d['trackName'] ?? $cleanTitle,
            'artist_name'  => $d['artistName'] ?? $cleanArtist,
            'album_name'   => $d['albumName'] ?? null,
            'duration'     => $d['duration'] ?? null,
            'instrumental' => $isInstrumental,
            'has_synced'   => $hasSynced,
            'plain_lyrics' => $plain,
            'synced_lyrics'=> $synced,
            'source_url'   => 'https://lrclib.net'
        ];
    }

    // Handle Rate Limiting (429) & Server Overloaded (503) — do NOT cache failure
    if ($res['status_code'] === 429) {
        return [
            'success'     => false,
            'status'      => 'rate_limited',
            'retry_after' => $res['retry_after'],
            'message'     => 'LRCLIB rate limit reached. Please wait a moment before retrying.',
            'search_url'  => 'https://www.google.com/search?q=' . urlencode("{$cleanTitle} {$cleanArtist} lyrics")
        ];
    }

    if ($res['status_code'] === 503) {
        return [
            'success'    => false,
            'status'     => 'overloaded',
            'message'    => 'Lyrics service (LRCLIB) is currently busy. You can retry in a moment.',
            'search_url' => 'https://www.google.com/search?q=' . urlencode("{$cleanTitle} {$cleanArtist} lyrics")
        ];
    }

    // Handle network / DNS / connection failure — do NOT cache failure
    if ($res['status_code'] === 0) {
        return [
            'success'    => false,
            'status'     => 'network_error',
            'message'    => $res['error'] ?? 'Could not connect to LRCLIB lyrics service.',
            'search_url' => 'https://www.google.com/search?q=' . urlencode("{$cleanTitle} {$cleanArtist} lyrics")
        ];
    }

    // 3. Fallback: Search candidates via /api/search
    $searchRes = search_lrclib_candidates($cleanTitle !== '' ? $cleanTitle : $normTitle, $cleanArtist, $targetDuration);
    if (!empty($searchRes['candidates'])) {
        $top = $searchRes['candidates'][0];
        // If the top candidate is an unambiguous strong match for both title & artist
        if (
            count($searchRes['candidates']) === 1 || 
            ($top['match_score'] >= 75 && 
             strcasecmp($top['track_name'], $cleanTitle) === 0 && 
             (empty($cleanArtist) || strcasecmp($top['artist_name'], $cleanArtist) === 0))
        ) {
            $isInstrumental = !empty($top['instrumental']);
            $plain = $top['plain_lyrics'] ?? null;
            $synced = $top['synced_lyrics'] ?? null;
            $hasSynced = !empty($synced);
            $status = $isInstrumental ? 'instrumental' : 'matched';

            if ($songId > 0) {
                save_song_lyrics_ref($songId, [
                    'lrclib_id'            => $top['id'],
                    'track_name'           => $top['track_name'],
                    'artist_name'          => $top['artist_name'],
                    'album_name'           => $top['album_name'],
                    'duration'             => $top['duration'],
                    'instrumental'         => $isInstrumental ? 1 : 0,
                    'has_synced'           => $hasSynced ? 1 : 0,
                    'source_url'           => 'https://lrclib.net',
                    'cached_lyrics_plain'  => $plain,
                    'cached_lyrics_synced' => $synced,
                    'status'               => $status
                ]);
            }

            return [
                'success'      => true,
                'cached'       => false,
                'match_type'   => 'search_match',
                'status'       => $status,
                'lrclib_id'    => $top['id'],
                'track_name'   => $top['track_name'],
                'artist_name'  => $top['artist_name'],
                'album_name'   => $top['album_name'],
                'duration'     => $top['duration'],
                'instrumental' => $isInstrumental,
                'has_synced'   => $hasSynced,
                'plain_lyrics' => $plain,
                'synced_lyrics'=> $synced,
                'source_url'   => 'https://lrclib.net'
            ];
        }

        return [
            'success'          => true,
            'match_type'       => 'candidates',
            'status'           => 'ambiguous',
            'candidates'       => $searchRes['candidates'],
            'top_candidate_id' => $searchRes['candidates'][0]['id'] ?? null,
            'message'          => 'Multiple lyric recordings found. Choose your preferred version below.'
        ];
    }

    // If search had an API error (e.g. 503 or 429), report it without caching negative lookup
    if (!$searchRes['success'] && ($searchRes['status_code'] ?? 0) === 503) {
        return [
            'success'    => false,
            'status'     => 'overloaded',
            'message'    => 'Lyrics service (LRCLIB) is currently busy. You can retry in a moment.',
            'search_url' => 'https://www.google.com/search?q=' . urlencode("{$cleanTitle} {$cleanArtist} lyrics")
        ];
    }
    if (!$searchRes['success'] && ($searchRes['status_code'] ?? 0) === 429) {
        return [
            'success'    => false,
            'status'     => 'rate_limited',
            'message'    => 'LRCLIB rate limit reached. Please wait a moment before retrying.',
            'search_url' => 'https://www.google.com/search?q=' . urlencode("{$cleanTitle} {$cleanArtist} lyrics")
        ];
    }

    // If neither /api/get nor /api/search found matching lyrics, record not_found with current metadata
    if ($songId > 0) {
        save_song_lyrics_ref($songId, [
            'track_name'  => $normTitle,
            'artist_name' => $normArtist,
            'status'      => 'not_found',
            'source_url'  => 'https://lrclib.net'
        ]);
    }

    return [
        'success'    => false,
        'status'     => 'not_found',
        'message'    => 'No lyrics found on LRCLIB for this song.',
        'search_url' => 'https://www.google.com/search?q=' . urlencode("{$cleanTitle} {$cleanArtist} lyrics")
    ];
}

/**
 * Score and rank LRCLIB candidate tracks based on synced lyrics availability,
 * duration proximity (Echo Music's ±2s and ±5s heuristics), and title/artist match.
 * Conceptually inspired by Echo Music's bestMatchingFor and bestMatchingForRelaxed in LrcLib.kt.
 */
function score_and_rank_lrclib_candidates(
    array $candidates, 
    string $cleanTitle, 
    string $cleanArtist = '', 
    ?float $targetDuration = null
): array {
    if (empty($candidates)) {
        return [];
    }

    $filtered = [];

    // Identify genuine version intent in requested title
    $isLiveRequested = (bool)preg_match('/\b(live)\b/iu', $cleanTitle);
    $isAcousticRequested = (bool)preg_match('/\b(acoustic)\b/iu', $cleanTitle);
    $isRemixRequested = (bool)preg_match('/\b(remix|mix)\b/iu', $cleanTitle);

    foreach ($candidates as $c) {
        $score = 0;
        $candTitle = trim($c['track_name'] ?? '');
        $candArtist = trim($c['artist_name'] ?? '');

        // 1. Title match (must have title relevance)
        $titleScore = 0;
        if ($candTitle !== '' && $cleanTitle !== '') {
            if (strcasecmp($candTitle, $cleanTitle) === 0) {
                $titleScore = 35;
            } elseif (stripos($candTitle, $cleanTitle) !== false || stripos($cleanTitle, $candTitle) !== false) {
                $titleScore = 20;
            }
        }

        // If title does not match even as a substring, discard completely unrelated tracks
        if ($titleScore === 0) {
            continue;
        }
        $score += $titleScore;

        // 2. Artist match & unrelated artist rejection
        if ($cleanArtist !== '') {
            $artistScore = 0;
            if ($candArtist !== '') {
                if (strcasecmp($candArtist, $cleanArtist) === 0) {
                    $artistScore = 30;
                } elseif (stripos($candArtist, $cleanArtist) !== false || stripos($cleanArtist, $candArtist) !== false) {
                    $artistScore = 15;
                }
            }
            if ($artistScore === 0) {
                // Completely mismatched artist -> discard unrelated track
                continue;
            }
            $score += $artistScore;
        }

        // 3. Genuine version protection (Live, Acoustic, Remix)
        $candIsLive = (bool)preg_match('/\b(live)\b/iu', $candTitle . ' ' . ($c['album_name'] ?? ''));
        $candIsAcoustic = (bool)preg_match('/\b(acoustic)\b/iu', $candTitle . ' ' . ($c['album_name'] ?? ''));
        $candIsRemix = (bool)preg_match('/\b(remix|mix)\b/iu', $candTitle . ' ' . ($c['album_name'] ?? ''));

        if ($isLiveRequested) {
            $score += $candIsLive ? 25 : -20;
        } else {
            if ($candIsLive) {
                $score -= 15; // User wanted studio version, penalize live recordings
            }
        }

        if ($isAcousticRequested) {
            $score += $candIsAcoustic ? 25 : -20;
        } else {
            if ($candIsAcoustic) {
                $score -= 15;
            }
        }

        if ($isRemixRequested) {
            $score += $candIsRemix ? 25 : -20;
        } else {
            if ($candIsRemix) {
                $score -= 15;
            }
        }

        // 4. Synced lyrics preference (Echo Music prioritizes synced)
        if (!empty($c['has_synced'])) {
            $score += 20;
        } elseif (!empty($c['has_plain'])) {
            $score += 10;
        }

        // 5. Duration delta scoring (Echo Music's ±2s and ±5s heuristics)
        if ($targetDuration !== null && $targetDuration > 0 && !empty($c['duration'])) {
            $delta = abs((float)$c['duration'] - $targetDuration);
            $c['duration_delta'] = round($delta, 1);
            if ($delta <= 2.0) {
                $score += 40; // Echo's strict tolerance
            } elseif ($delta <= 5.0) {
                $score += 20; // Echo's relaxed tolerance
            } elseif ($delta <= 10.0) {
                $score += 10;
            } elseif ($delta > 30.0) {
                $score -= 20; // Distant version penalty
            }
        } else {
            $c['duration_delta'] = null;
        }

        $c['match_score'] = $score;
        $filtered[] = $c;
    }

    // Sort descending by match_score
    usort($filtered, function ($a, $b) {
        if ($b['match_score'] === $a['match_score']) {
            if (!empty($b['has_synced']) !== !empty($a['has_synced'])) {
                return !empty($b['has_synced']) ? 1 : -1;
            }
            return 0;
        }
        return ($b['match_score'] > $a['match_score']) ? 1 : -1;
    });

    return $filtered;
}

/**
 * Search LRCLIB candidates when multiple recordings exist
 */
function search_lrclib_candidates(string $title, string $artist, ?float $targetDuration = null): array {
    $cleanTitle = clean_track_title_for_lyrics($title);
    $cleanArtist = clean_artist_for_lyrics($artist, $cleanTitle);

    $params = ['track_name' => $cleanTitle];
    if (!empty($cleanArtist)) {
        $params['artist_name'] = $cleanArtist;
    }

    $searchUrl = 'https://lrclib.net/api/search?' . http_build_query($params);
    $res = http_get_json($searchUrl, 5);

    if ($res['success'] && is_array($res['data'])) {
        $candidates = [];
        foreach ($res['data'] as $c) {
            $candidates[] = [
                'id'           => $c['id'],
                'track_name'   => $c['trackName'] ?? $c['name'] ?? '',
                'artist_name'  => $c['artistName'] ?? '',
                'album_name'   => $c['albumName'] ?? 'Single / Unknown Album',
                'duration'     => isset($c['duration']) ? (float)$c['duration'] : null,
                'instrumental' => !empty($c['instrumental']),
                'has_synced'   => !empty($c['syncedLyrics']),
                'has_plain'    => !empty($c['plainLyrics']),
                'plain_lyrics' => $c['plainLyrics'] ?? null,
                'synced_lyrics'=> $c['syncedLyrics'] ?? null
            ];
        }
        $ranked = score_and_rank_lrclib_candidates($candidates, $cleanTitle, $cleanArtist, $targetDuration);
        return ['success' => true, 'candidates' => $ranked];
    }

    return [
        'success'     => false, 
        'status_code' => $res['status_code'] ?? 0,
        'candidates'  => []
    ];
}

/**
 * Fetch specific LRCLIB lyrics by ID when user selects a candidate
 */
function get_lrclib_lyrics_by_id(int $songId, int $lrclibId): array {
    $url = "https://lrclib.net/api/get/{$lrclibId}";
    $res = http_get_json($url, 5);

    if ($res['success'] && is_array($res['data'])) {
        $d = $res['data'];
        $isInstrumental = !empty($d['instrumental']);
        $plain = $d['plainLyrics'] ?? null;
        $synced = $d['syncedLyrics'] ?? null;
        $hasSynced = !empty($synced);
        $status = $isInstrumental ? 'instrumental' : 'matched';

        save_song_lyrics_ref($songId, [
            'lrclib_id'            => $d['id'],
            'track_name'           => $d['trackName'] ?? '',
            'artist_name'          => $d['artistName'] ?? '',
            'album_name'           => $d['albumName'] ?? null,
            'duration'             => $d['duration'] ?? null,
            'instrumental'         => $isInstrumental ? 1 : 0,
            'has_synced'           => $hasSynced ? 1 : 0,
            'source_url'           => 'https://lrclib.net',
            'cached_lyrics_plain'  => $plain,
            'cached_lyrics_synced' => $synced,
            'status'               => $status
        ]);

        return [
            'success'      => true,
            'status'       => $status,
            'lrclib_id'    => $d['id'],
            'track_name'   => $d['trackName'] ?? '',
            'artist_name'  => $d['artistName'] ?? '',
            'album_name'   => $d['albumName'] ?? '',
            'duration'     => $d['duration'] ?? null,
            'instrumental' => $isInstrumental,
            'has_synced'   => $hasSynced,
            'plain_lyrics' => $plain,
            'synced_lyrics'=> $synced,
            'source_url'   => 'https://lrclib.net'
        ];
    }

    return [
        'success' => false, 
        'error'   => 'Failed to retrieve candidate lyrics from LRCLIB.'
    ];
}

/**
 * Save or update lyrics preference record in database
 */
function save_song_lyrics_ref(int $songId, array $data): bool {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        INSERT INTO `song_lyrics_ref` 
            (`song_id`, `lrclib_id`, `track_name`, `artist_name`, `album_name`, `duration`, `instrumental`, `has_synced`, `source_url`, `cached_lyrics_plain`, `cached_lyrics_synced`, `status`, `updated_at`)
        VALUES 
            (:song_id, :lrclib_id, :track_name, :artist_name, :album_name, :duration, :instrumental, :has_synced, :source_url, :plain, :synced, :status, NOW())
        ON DUPLICATE KEY UPDATE
            `lrclib_id` = VALUES(`lrclib_id`),
            `track_name` = VALUES(`track_name`),
            `artist_name` = VALUES(`artist_name`),
            `album_name` = VALUES(`album_name`),
            `duration` = VALUES(`duration`),
            `instrumental` = VALUES(`instrumental`),
            `has_synced` = VALUES(`has_synced`),
            `source_url` = VALUES(`source_url`),
            `cached_lyrics_plain` = VALUES(`cached_lyrics_plain`),
            `cached_lyrics_synced` = VALUES(`cached_lyrics_synced`),
            `status` = VALUES(`status`),
            `updated_at` = NOW()
    ");

    return $stmt->execute([
        ':song_id'      => $songId,
        ':lrclib_id'    => $data['lrclib_id'] ?? null,
        ':track_name'   => $data['track_name'] ?? null,
        ':artist_name'  => $data['artist_name'] ?? null,
        ':album_name'   => $data['album_name'] ?? null,
        ':duration'     => $data['duration'] ?? null,
        ':instrumental' => !empty($data['instrumental']) ? 1 : 0,
        ':has_synced'   => !empty($data['has_synced']) ? 1 : 0,
        ':source_url'   => $data['source_url'] ?? 'https://lrclib.net',
        ':plain'        => $data['cached_lyrics_plain'] ?? null,
        ':synced'       => $data['cached_lyrics_synced'] ?? null,
        ':status'       => $data['status'] ?? 'matched'
    ]);
}

/**
 * Clear cached lyrics for a song (e.g. to re-trigger fresh search)
 */
function clear_song_lyrics_ref(int $songId): array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("DELETE FROM `song_lyrics_ref` WHERE `song_id` = :id");
    $stmt->execute([':id' => $songId]);
    return ['success' => true];
}
