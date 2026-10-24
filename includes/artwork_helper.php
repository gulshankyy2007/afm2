<?php
/**
 * AFM#2 - Artwork Discovery & Fallback Helper
 * Discovers, caches, and manages song-specific album artwork & atmospheric photography.
 * Priority order:
 *  1. Confirmed song-specific album artwork (remembered in database)
 *  2. Cover Art Archive using MusicBrainz metadata
 *  3. Openverse openly-licensed atmospheric images with full attribution
 *  4. Local theme-matched atmospheric photography and CSS vinyl gradients
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/media_helper.php';

/**
 * Retrieve cached or confirmed artwork for a song from the database
 */
function get_song_artwork(int $songId): ?array {
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT * FROM `song_artwork` WHERE `song_id` = :id LIMIT 1");
        $stmt->execute([':id' => $songId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Save or update artwork reference in database
 */
function save_song_artwork(int $songId, array $data): bool {
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            INSERT INTO `song_artwork` 
                (`song_id`, `source`, `image_url`, `thumbnail_url`, `title`, `creator`, `license`, `license_url`, `attribution_url`, `is_confirmed`, `status`, `updated_at`)
            VALUES 
                (:song_id, :source, :image_url, :thumbnail_url, :title, :creator, :license, :license_url, :attribution_url, :is_confirmed, :status, NOW())
            ON DUPLICATE KEY UPDATE
                `source` = VALUES(`source`),
                `image_url` = VALUES(`image_url`),
                `thumbnail_url` = VALUES(`thumbnail_url`),
                `title` = VALUES(`title`),
                `creator` = VALUES(`creator`),
                `license` = VALUES(`license`),
                `license_url` = VALUES(`license_url`),
                `attribution_url` = VALUES(`attribution_url`),
                `is_confirmed` = VALUES(`is_confirmed`),
                `status` = VALUES(`status`),
                `updated_at` = NOW()
        ");

        return $stmt->execute([
            ':song_id'         => $songId,
            ':source'          => $data['source'] ?? 'none',
            ':image_url'       => $data['image_url'] ?? null,
            ':thumbnail_url'   => $data['thumbnail_url'] ?? ($data['image_url'] ?? null),
            ':title'           => $data['title'] ?? null,
            ':creator'         => $data['creator'] ?? null,
            ':license'         => $data['license'] ?? null,
            ':license_url'     => $data['license_url'] ?? null,
            ':attribution_url' => $data['attribution_url'] ?? null,
            ':is_confirmed'    => !empty($data['is_confirmed']) ? 1 : 0,
            ':status'          => $data['status'] ?? 'matched'
        ]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Confirm user selected artwork candidate
 */
function confirm_song_artwork(int $songId, array $artworkData): array {
    $artworkData['is_confirmed'] = 1;
    $artworkData['status'] = 'matched';
    $saved = save_song_artwork($songId, $artworkData);
    if ($saved) {
        return ['success' => true, 'artwork' => $artworkData];
    }
    return ['success' => false, 'error' => 'Failed to save confirmed artwork.'];
}

/**
 * Remove confirmed artwork reference
 */
function remove_song_artwork(int $songId): array {
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("DELETE FROM `song_artwork` WHERE `song_id` = :id");
        $stmt->execute([':id' => $songId]);
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Fetch artwork on-demand following the priority cascade
 */
function fetch_song_artwork(int $songId, string $title, string $artist, bool $forceRefresh = false): array {
    // 1. Check database cache / confirmed reference
    if (!$forceRefresh) {
        $cached = get_song_artwork($songId);
        if ($cached && ($cached['status'] === 'matched' || $cached['status'] === 'manual')) {
            return [
                'success'         => true,
                'cached'          => true,
                'source'          => $cached['source'],
                'image_url'       => $cached['image_url'],
                'thumbnail_url'   => $cached['thumbnail_url'] ?: $cached['image_url'],
                'title'           => $cached['title'],
                'creator'         => $cached['creator'],
                'license'         => $cached['license'],
                'license_url'     => $cached['license_url'],
                'attribution_url' => $cached['attribution_url'],
                'is_confirmed'    => (bool)$cached['is_confirmed']
            ];
        }
    }

    $cleanTitle = clean_track_title_for_lyrics($title);
    $cleanArtist = clean_artist_for_lyrics($artist, $cleanTitle);

    // 2. Priority: Cover Art Archive via MusicBrainz
    $mbResult = query_musicbrainz_cover_art($cleanTitle, $cleanArtist);
    if ($mbResult['success']) {
        save_song_artwork($songId, [
            'source'          => 'musicbrainz',
            'image_url'       => $mbResult['image_url'],
            'thumbnail_url'   => $mbResult['thumbnail_url'],
            'title'           => $mbResult['title'] ?: "{$cleanTitle} - Cover Art",
            'creator'         => $cleanArtist,
            'license'         => 'Cover Art Archive',
            'attribution_url' => 'https://coverartarchive.org',
            'is_confirmed'    => 0,
            'status'          => 'matched'
        ]);

        return array_merge(['success' => true, 'cached' => false], $mbResult);
    }

    // 3. Priority: Openverse Openly-Licensed Atmospheric Photography
    $ovResult = query_openverse_imagery("{$cleanTitle} {$cleanArtist}");
    if ($ovResult['success']) {
        save_song_artwork($songId, [
            'source'          => 'openverse',
            'image_url'       => $ovResult['image_url'],
            'thumbnail_url'   => $ovResult['thumbnail_url'],
            'title'           => $ovResult['title'],
            'creator'         => $ovResult['creator'],
            'license'         => $ovResult['license'],
            'license_url'     => $ovResult['license_url'],
            'attribution_url' => $ovResult['attribution_url'],
            'is_confirmed'    => 0,
            'status'          => 'matched'
        ]);

        return array_merge(['success' => true, 'cached' => false], $ovResult);
    }

    // 4. Fallback: Theme-styled atmospheric preset
    $preset = get_default_atmospheric_preset($cleanTitle, $cleanArtist);
    save_song_artwork($songId, [
        'source'          => 'preset',
        'image_url'       => $preset['image_url'],
        'thumbnail_url'   => $preset['thumbnail_url'],
        'title'           => $preset['title'],
        'creator'         => 'AFM#2 Atmospheric Presets',
        'license'         => 'CC0 Public Domain',
        'attribution_url' => 'https://unsplash.com',
        'is_confirmed'    => 0,
        'status'          => 'matched'
    ]);

    return array_merge(['success' => true, 'cached' => false], $preset);
}

/**
 * Query Cover Art Archive using MusicBrainz API
 */
function query_musicbrainz_cover_art(string $title, string $artist): array {
    if (empty($title)) {
        return ['success' => false];
    }

    $artistPart = !empty($artist) ? " AND artist:\"{$artist}\"" : "";
    $query = "recording:\"{$title}\"{$artistPart}";
    $mbUrl = "https://musicbrainz.org/ws/2/recording/?query=" . urlencode($query) . "&fmt=json&limit=3";

    $res = http_get_json($mbUrl, 5);
    if (!$res['success'] || empty($res['data']['recordings'])) {
        return ['success' => false];
    }

    foreach ($res['data']['recordings'] as $rec) {
        if (empty($rec['releases'])) continue;
        foreach ($rec['releases'] as $rel) {
            $mbid = $rel['id'] ?? null;
            if (!$mbid) continue;

            $caaUrl = "https://coverartarchive.org/release/{$mbid}";
            $caaRes = http_get_json($caaUrl, 4);

            // Cover Art Archive responds with 200 or 307
            if (!empty($caaRes['data']['images'])) {
                $img = $caaRes['data']['images'][0];
                $fullImg = $img['thumbnails']['500'] ?? ($img['thumbnails']['large'] ?? $img['image']);
                $thumbImg = $img['thumbnails']['250'] ?? ($img['thumbnails']['small'] ?? $fullImg);

                return [
                    'success'         => true,
                    'source'          => 'musicbrainz',
                    'image_url'       => $fullImg,
                    'thumbnail_url'   => $thumbImg,
                    'title'           => $rel['title'] ?? $title,
                    'creator'         => $artist ?: 'Various Artists',
                    'license'         => 'Cover Art Archive',
                    'attribution_url' => "https://musicbrainz.org/release/{$mbid}",
                    'mbid'            => $mbid
                ];
            }
        }
    }

    return ['success' => false];
}

/**
 * Query Openverse for CC-licensed atmospheric photography
 */
function query_openverse_imagery(string $query): array {
    $q = trim($query);
    if (empty($q)) return ['success' => false];

    $ovUrl = "https://api.openverse.org/v1/images/?q=" . urlencode($q) . "&page_size=3";
    $res = http_get_json($ovUrl, 4);

    if ($res['success'] && !empty($res['data']['results'])) {
        $first = $res['data']['results'][0];
        return [
            'success'         => true,
            'source'          => 'openverse',
            'image_url'       => $first['url'],
            'thumbnail_url'   => $first['thumbnail'] ?? $first['url'],
            'title'           => $first['title'] ?? 'Atmospheric Landscape',
            'creator'         => $first['creator'] ?? 'Openverse Contributor',
            'license'         => strtoupper($first['license'] ?? 'CC'),
            'license_url'     => $first['license_url'] ?? 'https://creativecommons.org',
            'attribution_url' => $first['foreign_landing_url'] ?? 'https://openverse.org'
        ];
    }

    return ['success' => false];
}

/**
 * Curated atmospheric photography presets (High resolution, CC0 Public Domain)
 * Inspired by the reference image's warm dusk, coastal road, crescent moon, and twilight mood
 */
function get_default_atmospheric_preset(string $title, string $artist): array {
    $presets = [
        [
            'title'           => 'Dusk Mountain Highway & Coastal Lights',
            'image_url'       => 'https://images.unsplash.com/photo-1519681393784-d120267933ba?auto=format&fit=crop&w=1200&q=80',
            'thumbnail_url'   => 'https://images.unsplash.com/photo-1519681393784-d120267933ba?auto=format&fit=crop&w=400&q=80',
            'creator'         => 'Benjamin Voros',
            'license'         => 'Unsplash Free License',
            'attribution_url' => 'https://unsplash.com'
        ],
        [
            'title'           => 'Twilight Coastline & Starry Night Sky',
            'image_url'       => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=1200&q=80',
            'thumbnail_url'   => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=400&q=80',
            'creator'         => 'Sean Oulashin',
            'license'         => 'Unsplash Free License',
            'attribution_url' => 'https://unsplash.com'
        ],
        [
            'title'           => 'Amber Sunset Over Rolling Hills',
            'image_url'       => 'https://images.unsplash.com/photo-1470246973918-29a93221c455?auto=format&fit=crop&w=1200&q=80',
            'thumbnail_url'   => 'https://images.unsplash.com/photo-1470246973918-29a93221c455?auto=format&fit=crop&w=400&q=80',
            'creator'         => 'Dawid Zawiła',
            'license'         => 'Unsplash Free License',
            'attribution_url' => 'https://unsplash.com'
        ],
        [
            'title'           => 'Cinematic Horizon & Crescent Glow',
            'image_url'       => 'https://images.unsplash.com/photo-1495616811223-4d98c6e9c869?auto=format&fit=crop&w=1200&q=80',
            'thumbnail_url'   => 'https://images.unsplash.com/photo-1495616811223-4d98c6e9c869?auto=format&fit=crop&w=400&q=80',
            'creator'         => 'Eberhard Grossgasteiger',
            'license'         => 'Unsplash Free License',
            'attribution_url' => 'https://unsplash.com'
        ]
    ];

    // Pick a deterministic preset based on string hash of title + artist
    $index = abs(crc32($title . $artist)) % count($presets);
    $selected = $presets[$index];

    return [
        'success'         => true,
        'source'          => 'preset',
        'image_url'       => $selected['image_url'],
        'thumbnail_url'   => $selected['thumbnail_url'],
        'title'           => $selected['title'],
        'creator'         => $selected['creator'],
        'license'         => $selected['license'],
        'attribution_url' => $selected['attribution_url'],
        'is_confirmed'    => 0
    ];
}

/**
 * Search candidates across MusicBrainz and Openverse for the user to choose
 */
function search_artwork_candidates(string $title, string $artist): array {
    $cleanTitle = clean_track_title_for_lyrics($title);
    $cleanArtist = clean_artist_for_lyrics($artist, $cleanTitle);
    $candidates = [];

    // 1. MusicBrainz / Cover Art Archive candidates
    $artistPart = !empty($cleanArtist) ? " AND artist:\"{$cleanArtist}\"" : "";
    $query = "recording:\"{$cleanTitle}\"{$artistPart}";
    $mbUrl = "https://musicbrainz.org/ws/2/recording/?query=" . urlencode($query) . "&fmt=json&limit=5";

    $res = http_get_json($mbUrl, 5);
    if ($res['success'] && !empty($res['data']['recordings'])) {
        foreach ($res['data']['recordings'] as $rec) {
            if (empty($rec['releases'])) continue;
            foreach ($rec['releases'] as $rel) {
                $mbid = $rel['id'] ?? null;
                if (!$mbid) continue;

                $caaUrl = "https://coverartarchive.org/release/{$mbid}";
                $caaRes = http_get_json($caaUrl, 3);
                if (!empty($caaRes['data']['images'])) {
                    $img = $caaRes['data']['images'][0];
                    $candidates[] = [
                        'source'          => 'musicbrainz',
                        'image_url'       => $img['thumbnails']['500'] ?? ($img['thumbnails']['large'] ?? $img['image']),
                        'thumbnail_url'   => $img['thumbnails']['250'] ?? ($img['thumbnails']['small'] ?? $img['image']),
                        'title'           => $rel['title'] ?? $title,
                        'creator'         => $cleanArtist ?: 'MusicBrainz',
                        'license'         => 'Cover Art Archive',
                        'attribution_url' => "https://musicbrainz.org/release/{$mbid}"
                    ];
                    if (count($candidates) >= 3) break 2;
                }
            }
        }
    }

    // 2. Openverse atmospheric imagery
    $ovUrl = "https://api.openverse.org/v1/images/?q=" . urlencode("{$cleanTitle} landscape") . "&page_size=4";
    $ovRes = http_get_json($ovUrl, 4);
    if ($ovRes['success'] && !empty($ovRes['data']['results'])) {
        foreach ($ovRes['data']['results'] as $item) {
            $candidates[] = [
                'source'          => 'openverse',
                'image_url'       => $item['url'],
                'thumbnail_url'   => $item['thumbnail'] ?? $item['url'],
                'title'           => $item['title'] ?? 'Openverse Artwork',
                'creator'         => $item['creator'] ?? 'Openverse',
                'license'         => strtoupper($item['license'] ?? 'CC'),
                'attribution_url' => $item['foreign_landing_url'] ?? 'https://openverse.org'
            ];
        }
    }

    // 3. Always include 2 default atmospheric presets as reliable fallbacks
    $preset1 = get_default_atmospheric_preset($title, $artist);
    $preset2 = get_default_atmospheric_preset($title . ' alternate', $artist);
    $candidates[] = $preset1;
    if ($preset2['image_url'] !== $preset1['image_url']) {
        $candidates[] = $preset2;
    }

    return ['success' => true, 'candidates' => $candidates];
}
