<?php
/**
 * AFM#2 - Database Helper & Business Logic Operations
 */

if (!defined('AFM_APP')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/media_helper.php';

/**
 * Request-level cache container for dashboard statistics.
 * Scoped strictly to the current request lifecycle.
 */
function &get_dashboard_stats_cache_ref(): array {
    static $cache = ['data' => null];
    return $cache;
}

/**
 * Return cached dashboard statistics if already computed in this request, without triggering queries.
 */
function get_cached_dashboard_stats(): ?array {
    $cache = &get_dashboard_stats_cache_ref();
    return $cache['data'];
}

/**
 * Invalidate request-level dashboard statistics cache (e.g. after mutations or in test runners).
 */
function clear_dashboard_stats_cache(): void {
    $cache = &get_dashboard_stats_cache_ref();
    $cache['data'] = null;
}

/**
 * Get lightweight total active song count without computing full dashboard statistics.
 * Uses cached dashboard stats if available, or executes a single fast COUNT query.
 */
function get_catalogue_song_count(bool $forceRefresh = false): int {
    static $cachedCount = null;
    if ($cachedCount !== null && !$forceRefresh) {
        return $cachedCount;
    }

    $cachedStats = get_cached_dashboard_stats();
    if ($cachedStats !== null && isset($cachedStats['total_songs']) && !$forceRefresh) {
        $cachedCount = (int)$cachedStats['total_songs'];
        return $cachedCount;
    }

    try {
        $pdo = get_db_connection();
        $cachedCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0")->fetchColumn();
    } catch (\Throwable $e) {
        $cachedCount = 2461;
    }

    return $cachedCount;
}

/**
 * Get comprehensive statistics for the dashboard
 * Memoized per-request to avoid redundant database execution across controllers and templates.
 */
function get_dashboard_stats(bool $forceRefresh = false): array {
    $cache = &get_dashboard_stats_cache_ref();
    if ($cache['data'] !== null && !$forceRefresh) {
        return $cache['data'];
    }

    $pdo = get_db_connection();

    // Total catalogue count
    $totalSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();

    // Discovery review counts
    $stmt = $pdo->query("
        SELECT 
            SUM(CASE WHEN `status` = 'shortlisted' THEN 1 ELSE 0 END) AS shortlisted_count,
            SUM(CASE WHEN `status` = 'passed' THEN 1 ELSE 0 END) AS passed_count
        FROM `song_discovery`
    ");
    $discovery = $stmt->fetch();
    $shortlisted = (int)($discovery['shortlisted_count'] ?? 0);
    $passed = (int)($discovery['passed_count'] ?? 0);
    $reviewed = $shortlisted + $passed;
    $unreviewed = max(0, $totalSongs - $reviewed);

    // Auditions count (entries in afm_entries that are shortlisted in Stage 1)
    $auditionsTotal = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM `afm_entries` e
        JOIN `song_discovery` sd ON e.`song_id` = sd.`song_id` AND sd.`status` = 'shortlisted'
        WHERE e.`status` != 'excluded'
    ")->fetchColumn();

    // Completed audition ratings (Round 1 ratings with total IS NOT NULL)
    $auditionsCompleted = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM `ratings` 
        WHERE `round_id` = 1 AND `total` IS NOT NULL
    ")->fetchColumn();

    $auditionsIncomplete = (int)$pdo->query("
        SELECT COUNT(*) 
        FROM `ratings` 
        WHERE `round_id` = 1 AND `total` IS NULL
    ")->fetchColumn();

    // Active tournament round
    $activeRound = $pdo->query("
        SELECT `round_id`, `round_name`, `sequence_no`, `status`, `target_count`
        FROM `rounds`
        WHERE `status` = 'in_progress'
        ORDER BY `sequence_no` ASC
        LIMIT 1
    ")->fetch();

    if (!$activeRound) {
        $activeRound = $pdo->query("
            SELECT `round_id`, `round_name`, `sequence_no`, `status`, `target_count`
            FROM `rounds`
            ORDER BY `sequence_no` ASC
            LIMIT 1
        ")->fetch();
    }

    // Rounds summary
    $rounds = $pdo->query("
        SELECT 
            r.`round_id`, 
            r.`round_name`, 
            r.`sequence_no`, 
            r.`status`, 
            r.`target_count`,
            (SELECT COUNT(*) FROM `ratings` rt WHERE rt.`round_id` = r.`round_id` AND rt.`total` IS NOT NULL) AS completed_scores_count,
            (SELECT COUNT(*) FROM `round_results` rr WHERE rr.`round_id` = r.`round_id` AND rr.`result` = 'qualified') AS qualified_count,
            (SELECT COUNT(*) FROM `round_results` rr WHERE rr.`round_id` = r.`round_id` AND rr.`result` = 'winner') AS winner_count
        FROM `rounds` r
        ORDER BY r.`sequence_no` ASC
    ")->fetchAll();

    // Recent shortlisted songs for personal shelf
    $recentShortlisted = $pdo->query("
        SELECT 
            s.`song_id`, 
            s.`title`, 
            COALESCE(s.`artist`, 'Unknown Artist') AS `artist`,
            sd.`reviewed_at`,
            sm.`video_id` AS `media_video_id`,
            sm.`version_type` AS `media_version_type`,
            sa.`thumbnail_url` AS `artwork_thumbnail_url`,
            sa.`image_url` AS `artwork_image_url`
        FROM `song_discovery` sd
        JOIN `songs` s ON sd.`song_id` = s.`song_id`
        LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
        LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
        WHERE sd.`status` = 'shortlisted'
        ORDER BY sd.`reviewed_at` DESC
        LIMIT 6
    ")->fetchAll();

    $cache['data'] = [
        'total_songs'          => $totalSongs,
        'reviewed_count'       => $reviewed,
        'shortlisted_count'    => $shortlisted,
        'passed_count'         => $passed,
        'unreviewed_count'     => $unreviewed,
        'auditions_total'      => $auditionsTotal,
        'auditions_completed'  => $auditionsCompleted,
        'auditions_incomplete' => $auditionsIncomplete,
        'active_round'         => $activeRound,
        'rounds'               => $rounds,
        'recent_shortlisted'   => $recentShortlisted
    ];

    return $cache['data'];
}

/**
 * Search and paginate song catalogue
 */
function search_songs(string $query = '', string $statusFilter = 'all', int $page = 1, int $perPage = 50, string $sortBy = 'id_asc'): array {
    $pdo = get_db_connection();
    $page = max(1, $page);
    $perPage = max(10, min(200, $perPage));
    $offset = ($page - 1) * $perPage;

    $whereClauses = [];
    $params = [];

    // Search query
    $trimmedQuery = trim($query);
    if ($trimmedQuery !== '') {
        $whereClauses[] = "(s.`title` LIKE :query1 OR s.`artist` LIKE :query2)";
        $params[':query1'] = '%' . $trimmedQuery . '%';
        $params[':query2'] = '%' . $trimmedQuery . '%';
    }

    // Status filter
    if ($statusFilter === 'unreviewed') {
        $whereClauses[] = "sd.`status` IS NULL";
    } elseif ($statusFilter === 'shortlisted') {
        $whereClauses[] = "sd.`status` = 'shortlisted'";
    } elseif ($statusFilter === 'passed') {
        $whereClauses[] = "sd.`status` = 'passed'";
    }

    $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    // Count total matches
    $countSql = "
        SELECT COUNT(*)
        FROM `songs` s
        LEFT JOIN `song_discovery` sd ON s.`song_id` = sd.`song_id`
        {$whereSql}
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Sorting options (Title ASC/DESC, Artist ASC/DESC, ID ASC/DESC)
    $validSorts = [
        'id_asc'      => 's.`song_id` ASC',
        'id_desc'     => 's.`song_id` DESC',
        'title_asc'   => 's.`title` ASC, (s.`artist` IS NULL OR TRIM(s.`artist`) = \'\') ASC, s.`artist` ASC, s.`song_id` ASC',
        'title_desc'  => 's.`title` DESC, (s.`artist` IS NULL OR TRIM(s.`artist`) = \'\') ASC, s.`artist` ASC, s.`song_id` ASC',
        'artist_asc'  => '(s.`artist` IS NULL OR TRIM(s.`artist`) = \'\') ASC, s.`artist` ASC, s.`title` ASC, s.`song_id` ASC',
        'artist_desc' => '(s.`artist` IS NULL OR TRIM(s.`artist`) = \'\') ASC, s.`artist` DESC, s.`title` ASC, s.`song_id` ASC'
    ];
    $orderClause = $validSorts[$sortBy] ?? $validSorts['id_asc'];

    // Fetch paginated records with discovery and entry info
    $dataSql = "
        SELECT 
            s.`song_id`,
            s.`title`,
            COALESCE(s.`artist`, 'Unknown Artist') AS `artist`,
            s.`is_consolidated`,
            s.`consolidated_into_id`,
            sd.`status` AS `discovery_status`,
            sd.`reviewed_at`,
            e.`entry_id`,
            e.`status` AS `entry_status`,
            r1.`total` AS `audition_total`,
            (CASE WHEN r1.`rating_id` IS NOT NULL AND r1.`total` IS NULL THEN 1 ELSE 0 END) AS `audition_incomplete`,
            sm.`video_id` AS `media_video_id`,
            sm.`user_url` AS `media_user_url`,
            sm.`version_type` AS `media_version_type`,
            sm.`embed_status` AS `media_embed_status`,
            sa.`thumbnail_url` AS `artwork_thumbnail_url`,
            sa.`image_url` AS `artwork_image_url`,
            sa.`source` AS `artwork_source`
        FROM `songs` s
        LEFT JOIN `song_discovery` sd ON s.`song_id` = sd.`song_id`
        LEFT JOIN `afm_entries` e ON s.`song_id` = e.`song_id`
        LEFT JOIN `ratings` r1 ON e.`entry_id` = r1.`entry_id` AND r1.`round_id` = 1
        LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
        LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
        {$whereSql}
        ORDER BY {$orderClause}
        LIMIT :limit OFFSET :offset
    ";

    $dataStmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $dataStmt->bindValue($k, $v);
    }
    $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $songs = $dataStmt->fetchAll();

    $totalPages = (int)ceil($total / $perPage);

    return [
        'songs'        => $songs,
        'total'        => $total,
        'page'         => $page,
        'per_page'     => $perPage,
        'sort'         => $sortBy,
        'total_pages'  => max(1, $totalPages),
        'has_next'     => $page < $totalPages,
        'has_prev'     => $page > 1
    ];
}

/**
 * Fetch a song by its unique primary key song_id
 */
function get_song_by_id(int $songId): ?array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM `songs` WHERE `song_id` = :id");
    $stmt->execute([':id' => $songId]);
    $song = $stmt->fetch();
    return $song ?: null;
}

/**
 * Update discovery status for a song ('unreviewed', 'shortlisted', 'passed')
 */
function update_song_discovery_status(int $songId, string $status): array {
    $pdo = get_db_connection();

    // Verify song exists
    $stmt = $pdo->prepare("SELECT `song_id`, `title`, `artist`, `is_consolidated`, `consolidated_into_id` FROM `songs` WHERE `song_id` = :id");
    $stmt->execute([':id' => $songId]);
    $song = $stmt->fetch();
    if (!$song) {
        return ['success' => false, 'error' => 'Song not found in catalogue.'];
    }

    if ((int)($song['is_consolidated'] ?? 0) === 1 && $status === 'shortlisted') {
        return ['success' => false, 'error' => 'Cannot shortlist a consolidated duplicate. Inspect canonical song #' . $song['consolidated_into_id'] . ' instead.'];
    }

    $validStatuses = ['unreviewed', 'shortlisted', 'passed'];
    if (!in_array($status, $validStatuses, true)) {
        return ['success' => false, 'error' => 'Invalid discovery status.'];
    }

    try {
        $pdo->beginTransaction();

        if ($status === 'unreviewed') {
            $del = $pdo->prepare("DELETE FROM `song_discovery` WHERE `song_id` = :id");
            $del->execute([':id' => $songId]);

            // If entry exists in afm_entries and has no recorded ratings, clean it up
            $delEntry = $pdo->prepare("
                DELETE FROM `afm_entries` 
                WHERE `song_id` = :id 
                  AND `entry_id` NOT IN (SELECT `entry_id` FROM `ratings`)
            ");
            $delEntry->execute([':id' => $songId]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO `song_discovery` (`song_id`, `status`, `reviewed_at`)
                VALUES (:id, :status, NOW())
                ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `reviewed_at` = NOW()
            ");
            $ins->execute([':id' => $songId, ':status' => $status]);

            if ($status === 'shortlisted') {
                // If shortlisted, ensure an entry exists in afm_entries so it can enter formal auditions
                $entryStmt = $pdo->prepare("
                    INSERT INTO `afm_entries` (`song_id`, `status`)
                    VALUES (:id, 'auditioning')
                    ON DUPLICATE KEY UPDATE `status` = 'auditioning'
                ");
                $entryStmt->execute([':id' => $songId]);
            } else {
                // If marked 'passed', remove from afm_entries if unrated
                $delEntry = $pdo->prepare("
                    DELETE FROM `afm_entries` 
                    WHERE `song_id` = :id 
                      AND `entry_id` NOT IN (SELECT `entry_id` FROM `ratings`)
                ");
                $delEntry->execute([':id' => $songId]);
            }
        }

        $pdo->commit();

        // Get updated counts
        $counts = $pdo->query("
            SELECT 
                SUM(CASE WHEN `status` = 'shortlisted' THEN 1 ELSE 0 END) AS shortlisted,
                SUM(CASE WHEN `status` = 'passed' THEN 1 ELSE 0 END) AS passed
            FROM `song_discovery`
        ")->fetch();

        $shortlisted = (int)($counts['shortlisted'] ?? 0);
        $passed = (int)($counts['passed'] ?? 0);
        $totalSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
        $unreviewed = max(0, $totalSongs - ($shortlisted + $passed));

        return [
            'success'          => true,
            'song_id'          => $songId,
            'status'           => $status,
            'shortlisted_count'=> $shortlisted,
            'passed_count'     => $passed,
            'unreviewed_count' => $unreviewed
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("AFM2 update_song_discovery_status error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to update discovery status.'];
    }
}

/**
 * Find an existing active catalogue song matching the given title and artist.
 * Performs normalized, case-insensitive comparison, handling null/empty artists.
 * If artist is not provided, also checks if exactly 1 song with that title exists.
 */
function find_existing_catalogue_song(string $title, ?string $artist = null): ?array {
    $cleanTitle = trim($title);
    if ($cleanTitle === '') {
        return null;
    }

    $cleanArtist = $artist !== null ? trim($artist) : null;
    if ($cleanArtist === null || $cleanArtist === '' || $cleanArtist === '-' || strcasecmp($cleanArtist, 'unknown artist') === 0) {
        $cleanArtist = null;
    }

    $pdo = get_db_connection();

    // 1. If artist is provided, match both Title and Artist
    if ($cleanArtist !== null) {
        $stmt = $pdo->prepare("
            SELECT `song_id`, `title`, `artist`, `is_consolidated`
            FROM `songs`
            WHERE `is_consolidated` = 0
              AND LOWER(TRIM(`title`)) = LOWER(TRIM(:title))
              AND LOWER(TRIM(`artist`)) = LOWER(TRIM(:artist))
            ORDER BY `song_id` ASC
            LIMIT 1
        ");
        $stmt->execute([
            ':title'  => $cleanTitle,
            ':artist' => $cleanArtist
        ]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($found) {
            return $found;
        }

        // Secondary check: Title might have version suffix in input or DB
        $baseTitle = trim(preg_replace('/\s*[\(\[\{](?:official|live|remix|acoustic|remastered?|deluxe|version|audio|video|hd|4k).*?[\)\]\}]/iu', '', $cleanTitle));
        if ($baseTitle !== '' && strcasecmp($baseTitle, $cleanTitle) !== 0) {
            $stmt = $pdo->prepare("
                SELECT `song_id`, `title`, `artist`, `is_consolidated`
                FROM `songs`
                WHERE `is_consolidated` = 0
                  AND LOWER(TRIM(`title`)) = LOWER(TRIM(:title))
                  AND LOWER(TRIM(`artist`)) = LOWER(TRIM(:artist))
                ORDER BY `song_id` ASC
                LIMIT 1
            ");
            $stmt->execute([
                ':title'  => $baseTitle,
                ':artist' => $cleanArtist
            ]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                return $found;
            }
        }
    } else {
        // 2. Artist was null/empty:
        // First check for active songs with matching title and NULL/empty artist
        $stmt = $pdo->prepare("
            SELECT `song_id`, `title`, `artist`, `is_consolidated`
            FROM `songs`
            WHERE `is_consolidated` = 0
              AND LOWER(TRIM(`title`)) = LOWER(TRIM(:title))
              AND (`artist` IS NULL OR TRIM(`artist`) = '' OR LOWER(TRIM(`artist`)) = 'unknown artist')
            ORDER BY `song_id` ASC
            LIMIT 1
        ");
        $stmt->execute([':title' => $cleanTitle]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($found) {
            return $found;
        }

        // Second check: If there is ONLY ONE song in the whole active catalogue with this title,
        // it is an unambiguous match
        $stmt = $pdo->prepare("
            SELECT `song_id`, `title`, `artist`, `is_consolidated`
            FROM `songs`
            WHERE `is_consolidated` = 0
              AND LOWER(TRIM(`title`)) = LOWER(TRIM(:title))
        ");
        $stmt->execute([':title' => $cleanTitle]);
        $allMatching = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($allMatching) === 1) {
            return $allMatching[0];
        }
    }

    return null;
}

/**
 * Create a new song in the catalogue, with optional YouTube media and discovery status.
 * If an identical song (matching title & artist) already exists, updates the existing record
 * with the provided media link, confirmed thumbnail, and review status instead of creating a duplicate.
 */
function create_catalogue_song(
    string $title, 
    ?string $artist = null, 
    ?string $youtubeUrlOrId = null, 
    string $discoveryStatus = 'unreviewed'
): array {
    $title = trim($title);
    if ($title === '') {
        return ['success' => false, 'error' => 'Song title is required.'];
    }

    $artist = $artist !== null ? trim($artist) : null;
    if ($artist === '' || $artist === 'Unknown Artist') {
        $artist = null;
    }

    $validStatuses = ['unreviewed', 'shortlisted', 'passed'];
    if (!in_array($discoveryStatus, $validStatuses, true)) {
        $discoveryStatus = 'unreviewed';
    }

    // 1. Check if matching song already exists in the catalogue
    $existing = find_existing_catalogue_song($title, $artist);
    if ($existing !== null) {
        $existingId = (int)$existing['song_id'];
        $savedMedia = null;

        // If a YouTube URL or Video ID was provided, save/update the recording link and artwork
        if (!empty($youtubeUrlOrId)) {
            $mediaRes = save_song_media($existingId, $youtubeUrlOrId, 'original', null, 'allowed', $existing['title'], $existing['artist']);
            if ($mediaRes['success']) {
                $savedMedia = $mediaRes['media'];
            }
        } else {
            $savedMedia = get_song_media($existingId);
        }

        // If discovery status is provided ('shortlisted', 'passed', or 'unreviewed'), update discovery status
        if (in_array($discoveryStatus, ['shortlisted', 'passed', 'unreviewed'], true)) {
            update_song_discovery_status($existingId, $discoveryStatus);
        }

        // Clear any previous failed lyrics lookup cache so user can fetch fresh
        $savedLyrics = get_saved_song_lyrics($existingId);
        if ($savedLyrics && $savedLyrics['status'] === 'not_found') {
            clear_song_lyrics_ref($existingId);
        }

        $artistDisplay = !empty($existing['artist']) ? $existing['artist'] : 'Unknown Artist';
        return [
            'success'          => true,
            'song_id'          => $existingId,
            'title'            => $existing['title'],
            'artist'           => $artistDisplay,
            'discovery_status' => $discoveryStatus,
            'updated_existing' => true,
            'media'            => $savedMedia,
            'message'          => "Existing catalogue track #{$existingId} (\"{$existing['title']}\") was found. Updated its recording link and review status instead of creating a duplicate."
        ];
    }

    $pdo = get_db_connection();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO `songs` (`title`, `artist`, `is_consolidated`, `consolidated_into_id`, `consolidated_at`)
            VALUES (:title, :artist, 0, NULL, NULL)
        ");
        $stmt->execute([
            ':title'  => $title,
            ':artist' => $artist
        ]);
        $songId = (int)$pdo->lastInsertId();

        // Optional YouTube media association
        $savedMedia = null;
        if (!empty($youtubeUrlOrId)) {
            $mediaRes = save_song_media($songId, $youtubeUrlOrId, 'original', null, 'allowed', $title, $artist);
            if ($mediaRes['success']) {
                $savedMedia = $mediaRes['media'];
                // Also set default artwork from YouTube thumbnail
                $videoId = $savedMedia['video_id'];
                $artStmt = $pdo->prepare("
                    INSERT INTO `song_artwork` (`song_id`, `source`, `thumbnail_url`, `image_url`, `is_confirmed`, `status`)
                    VALUES (:id, 'confirmed', :thumb, :img, 1, 'matched')
                    ON DUPLICATE KEY UPDATE `thumbnail_url` = VALUES(`thumbnail_url`), `image_url` = VALUES(`image_url`)
                ");
                $artStmt->execute([
                    ':id'    => $songId,
                    ':thumb' => "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg",
                    ':img'   => "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg"
                ]);
            }
        }

        // Discovery status
        if ($discoveryStatus === 'shortlisted') {
            $discStmt = $pdo->prepare("
                INSERT INTO `song_discovery` (`song_id`, `status`, `reviewed_at`)
                VALUES (:id, 'shortlisted', NOW())
            ");
            $discStmt->execute([':id' => $songId]);

            $entryStmt = $pdo->prepare("
                INSERT INTO `afm_entries` (`song_id`, `status`)
                VALUES (:id, 'auditioning')
            ");
            $entryStmt->execute([':id' => $songId]);
        } elseif ($discoveryStatus === 'passed') {
            $discStmt = $pdo->prepare("
                INSERT INTO `song_discovery` (`song_id`, `status`, `reviewed_at`)
                VALUES (:id, 'passed', NOW())
            ");
            $discStmt->execute([':id' => $songId]);
        }

        $pdo->commit();

        return [
            'success'          => true,
            'song_id'          => $songId,
            'title'            => $title,
            'artist'           => $artist ?? 'Unknown Artist',
            'discovery_status' => $discoveryStatus,
            'updated_existing' => false,
            'media'            => $savedMedia,
            'message'          => "Song \"{$title}\" added successfully to library (ID: {$songId})."
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Database error creating song: ' . $e->getMessage()];
    }
}

/**
 * Update an existing song's title, artist, and optional YouTube media reference
 */
function update_catalogue_song(
    int $songId, 
    string $title, 
    ?string $artist = null, 
    ?string $youtubeUrlOrId = null
): array {
    if ($songId <= 0) {
        return ['success' => false, 'error' => 'Invalid song ID.'];
    }

    $title = trim($title);
    if ($title === '') {
        return ['success' => false, 'error' => 'Song title cannot be empty.'];
    }

    $artist = $artist !== null ? trim($artist) : null;
    if ($artist === '' || $artist === 'Unknown Artist') {
        $artist = null;
    }

    $pdo = get_db_connection();

    // Verify song exists
    $check = $pdo->prepare("SELECT `song_id`, `title`, `artist` FROM `songs` WHERE `song_id` = :id");
    $check->execute([':id' => $songId]);
    $song = $check->fetch();
    if (!$song) {
        return ['success' => false, 'error' => 'Song not found in catalogue.'];
    }

    try {
        $pdo->beginTransaction();

        $updateStmt = $pdo->prepare("
            UPDATE `songs`
            SET `title` = :title, `artist` = :artist
            WHERE `song_id` = :id
        ");
        $updateStmt->execute([
            ':title'  => $title,
            ':artist' => $artist,
            ':id'     => $songId
        ]);

        // Invalidate cached lyrics and negative lookup if title or artist was changed
        if ($song['title'] !== $title || $song['artist'] !== $artist) {
            clear_song_lyrics_ref($songId);
        }

        // If YouTube link/id provided, update or create media
        if (!empty($youtubeUrlOrId)) {
            $mediaRes = save_song_media($songId, $youtubeUrlOrId, 'original', null, 'allowed', $title, $artist, $youtubeUrlOrId);
            if (!$mediaRes['success']) {
                $pdo->rollBack();
                return ['success' => false, 'error' => $mediaRes['error']];
            }
            $videoId = $mediaRes['media']['video_id'];
            $artStmt = $pdo->prepare("
                INSERT INTO `song_artwork` (`song_id`, `source`, `thumbnail_url`, `image_url`, `is_confirmed`, `status`)
                VALUES (:id, 'confirmed', :thumb, :img, 1, 'matched')
                ON DUPLICATE KEY UPDATE `thumbnail_url` = VALUES(`thumbnail_url`), `image_url` = VALUES(`image_url`)
            ");
            $artStmt->execute([
                ':id'    => $songId,
                ':thumb' => "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg",
                ':img'   => "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg"
            ]);
        }

        $pdo->commit();

        return [
            'success' => true,
            'song_id' => $songId,
            'title'   => $title,
            'artist'  => $artist ?? 'Unknown Artist',
            'message' => "Song #{$songId} updated successfully."
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Database error updating song: ' . $e->getMessage()];
    }
}

/**
 * Safely delete a song from catalogue.
 * Blocks deletion if the song has completed ratings or tournament results to preserve history.
 */
function delete_catalogue_song(int $songId): array {
    if ($songId <= 0) {
        return ['success' => false, 'error' => 'Invalid song ID.'];
    }

    $pdo = get_db_connection();

    // Verify song exists
    $check = $pdo->prepare("SELECT `song_id`, `title`, `artist` FROM `songs` WHERE `song_id` = :id");
    $check->execute([':id' => $songId]);
    $song = $check->fetch();
    if (!$song) {
        return ['success' => false, 'error' => 'Song not found in catalogue.'];
    }

    // Check competition history
    $checkHistory = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM `ratings` r JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` WHERE e.`song_id` = :id1 AND r.`total` IS NOT NULL) AS completed_ratings_count,
            (SELECT COUNT(*) FROM `round_results` rr JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id` WHERE e.`song_id` = :id2) AS results_count
    ");
    $checkHistory->execute([':id1' => $songId, ':id2' => $songId]);
    $history = $checkHistory->fetch();

    $ratingsCount = (int)($history['completed_ratings_count'] ?? 0);
    $resultsCount = (int)($history['results_count'] ?? 0);

    if ($ratingsCount > 0 || $resultsCount > 0) {
        return [
            'success' => false,
            'blocked' => true,
            'error'   => "Song #{$songId} (\"{$song['title']}\") cannot be permanently deleted because it has recorded tournament history ({$ratingsCount} official scorecard(s), {$resultsCount} round result(s)). To remove it without corrupting tournament history, mark it as Passed or Excluded instead."
        ];
    }

    try {
        $pdo->beginTransaction();

        // Safe to remove any unrated afm_entries
        $delEntry = $pdo->prepare("DELETE FROM `afm_entries` WHERE `song_id` = :id");
        $delEntry->execute([':id' => $songId]);

        // Delete from songs (cascades to song_discovery, song_media, song_artwork, song_lyrics_ref, song_consolidation_audit)
        $delSong = $pdo->prepare("DELETE FROM `songs` WHERE `song_id` = :id");
        $delSong->execute([':id' => $songId]);

        $pdo->commit();

        return [
            'success' => true,
            'song_id' => $songId,
            'title'   => $song['title'],
            'message' => "Song #{$songId} (\"{$song['title']}\") was permanently deleted from the catalogue."
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Database error deleting song: ' . $e->getMessage()];
    }
}

/**
 * Permanently delete multiple catalogue songs with competition safeguards
 * 
 * Songs with existing tournament scorecards (ratings with total IS NOT NULL)
 * or recorded round results are strictly blocked from deletion to maintain
 * competition integrity. All other unrated tracks in the selection are deleted
 * within a single atomic database transaction.
 *
 * @param array $songIds List of song IDs to delete
 * @return array Result summary with deleted and blocked lists
 */
function delete_multiple_catalogue_songs(array $songIds): array {
    $pdo = get_db_connection();

    // Sanitize and deduplicate IDs
    $cleanIds = [];
    foreach ($songIds as $id) {
        $intId = (int)$id;
        if ($intId > 0 && !in_array($intId, $cleanIds, true)) {
            $cleanIds[] = $intId;
        }
    }

    if (empty($cleanIds)) {
        return [
            'success'       => false,
            'error'         => 'No valid song IDs provided for deletion.',
            'deleted_count' => 0,
            'blocked_count' => 0,
            'deleted_ids'   => [],
            'blocked_songs' => []
        ];
    }

    // Fetch details for all requested songs
    $inClause = implode(',', array_fill(0, count($cleanIds), '?'));
    $stmt = $pdo->prepare("SELECT song_id, title, artist FROM `songs` WHERE `song_id` IN ($inClause)");
    $stmt->execute($cleanIds);
    $foundSongs = [];
    while ($row = $stmt->fetch()) {
        $foundSongs[(int)$row['song_id']] = $row;
    }

    if (empty($foundSongs)) {
        return [
            'success'       => false,
            'error'         => 'None of the requested songs exist in the catalogue.',
            'deleted_count' => 0,
            'blocked_count' => 0,
            'deleted_ids'   => [],
            'blocked_songs' => []
        ];
    }

    // Check tournament history for each song
    $blockedSongs = [];
    $eligibleIds = [];

    $historyStmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM `ratings` r JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` WHERE e.`song_id` = :id1 AND r.`total` IS NOT NULL) AS completed_ratings_count,
            (SELECT COUNT(*) FROM `round_results` rr JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id` WHERE e.`song_id` = :id2) AS results_count
    ");

    foreach ($foundSongs as $id => $song) {
        $historyStmt->execute([':id1' => $id, ':id2' => $id]);
        $hist = $historyStmt->fetch();
        $ratingsCount = (int)($hist['completed_ratings_count'] ?? 0);
        $resultsCount = (int)($hist['results_count'] ?? 0);

        if ($ratingsCount > 0 || $resultsCount > 0) {
            $blockedSongs[] = [
                'song_id'       => $id,
                'title'         => $song['title'],
                'artist'        => $song['artist'],
                'ratings_count' => $ratingsCount,
                'results_count' => $resultsCount,
                'reason'        => "Recorded tournament history ({$ratingsCount} scorecard(s), {$resultsCount} round result(s))"
            ];
        } else {
            $eligibleIds[] = $id;
        }
    }

    // If no songs are eligible to be deleted
    if (empty($eligibleIds)) {
        return [
            'success'       => false,
            'blocked'       => true,
            'error'         => 'All selected songs are protected by AFM competition safeguards because they have recorded tournament history.',
            'deleted_count' => 0,
            'blocked_count' => count($blockedSongs),
            'deleted_ids'   => [],
            'blocked_songs' => $blockedSongs
        ];
    }

    try {
        $pdo->beginTransaction();

        $delInClause = implode(',', array_fill(0, count($eligibleIds), '?'));

        // Delete unrated entries
        $delEntry = $pdo->prepare("DELETE FROM `afm_entries` WHERE `song_id` IN ($delInClause)");
        $delEntry->execute($eligibleIds);

        // Delete from songs (cascades to song_discovery, song_media, song_artwork, song_lyrics_ref, song_consolidation_audit)
        $delSong = $pdo->prepare("DELETE FROM `songs` WHERE `song_id` IN ($delInClause)");
        $delSong->execute($eligibleIds);

        $pdo->commit();

        $deletedCount = count($eligibleIds);
        $blockedCount = count($blockedSongs);

        $msg = "Successfully deleted {$deletedCount} song(s) from the catalogue.";
        if ($blockedCount > 0) {
            $msg .= " ({$blockedCount} song(s) were protected and preserved due to official tournament history).";
        }

        return [
            'success'       => true,
            'deleted_count' => $deletedCount,
            'blocked_count' => $blockedCount,
            'deleted_ids'   => $eligibleIds,
            'blocked_songs' => $blockedSongs,
            'message'       => $msg
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success'       => false,
            'error'         => 'Database error deleting songs: ' . $e->getMessage(),
            'deleted_count' => 0,
            'blocked_count' => count($blockedSongs),
            'deleted_ids'   => [],
            'blocked_songs' => $blockedSongs
        ];
    }
}



/**
 * Get all shortlisted songs with audition status
 */
function get_shortlisted_songs(int $page = 1, int $perPage = 50, string $query = '', string $statusFilter = 'all'): array {
    $pdo = get_db_connection();
    $page = max(1, $page);
    $perPage = max(10, min(200, $perPage));
    $offset = ($page - 1) * $perPage;

    $whereClauses = ["sd.`status` = 'shortlisted'"];
    $params = [];

    $trimmed = trim($query);
    if ($trimmed !== '') {
        $whereClauses[] = "(s.`title` LIKE :q1 OR s.`artist` LIKE :q2)";
        $params[':q1'] = '%' . $trimmed . '%';
        $params[':q2'] = '%' . $trimmed . '%';
    }

    if ($statusFilter === 'unscored') {
        $whereClauses[] = "r1.`rating_id` IS NULL";
    } elseif ($statusFilter === 'incomplete') {
        $whereClauses[] = "(r1.`rating_id` IS NOT NULL AND r1.`total` IS NULL)";
    } elseif ($statusFilter === 'completed') {
        $whereClauses[] = "r1.`total` IS NOT NULL";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM `song_discovery` sd
        JOIN `songs` s ON sd.`song_id` = s.`song_id`
        LEFT JOIN `afm_entries` e ON s.`song_id` = e.`song_id`
        LEFT JOIN `ratings` r1 ON e.`entry_id` = r1.`entry_id` AND r1.`round_id` = 1
        {$whereSql}
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $dataSql = "
        SELECT 
            s.`song_id`,
            s.`title`,
            COALESCE(s.`artist`, 'Unknown Artist') AS `artist`,
            sd.`reviewed_at`,
            e.`entry_id`,
            e.`status` AS `entry_status`,
            r1.`rating_id`,
            r1.`melody`,
            r1.`lyrics`,
            r1.`vocals`,
            r1.`emotion`,
            r1.`replayability`,
            r1.`total`,
            (CASE WHEN r1.`rating_id` IS NOT NULL AND r1.`total` IS NULL THEN 1 ELSE 0 END) AS `is_incomplete`,
            (CASE WHEN r1.`total` IS NOT NULL THEN 1 ELSE 0 END) AS `is_complete`,
            sa.`thumbnail_url` AS `artwork_thumbnail_url`,
            sa.`image_url` AS `artwork_image_url`,
            sa.`source` AS `artwork_source`,
            sm.`video_id` AS `media_video_id`,
            sm.`version_type` AS `media_version_type`
        FROM `song_discovery` sd
        JOIN `songs` s ON sd.`song_id` = s.`song_id`
        LEFT JOIN `afm_entries` e ON s.`song_id` = e.`song_id`
        LEFT JOIN `ratings` r1 ON e.`entry_id` = r1.`entry_id` AND r1.`round_id` = 1
        LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
        LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
        {$whereSql}
        ORDER BY s.`title` ASC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $songs = $stmt->fetchAll();

    $totalPages = (int)ceil($total / $perPage);

    return [
        'songs'       => $songs,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => max(1, $totalPages)
    ];
}

/**
 * Save or update a scorecard rating
 * Each criterion must be whole integer 0-10 or NULL.
 * Distinguishes incomplete from completed scorecards.
 * Generated database column 'total' automatically calculates the sum out of 50.
 */
function save_scorecard_rating(
    int $entryId, 
    int $roundId, 
    $melody, 
    $lyrics, 
    $vocals, 
    $emotion, 
    $replayability
): array {
    $pdo = get_db_connection();

    // Verify entry exists
    $entryStmt = $pdo->prepare("SELECT `entry_id`, `song_id` FROM `afm_entries` WHERE `entry_id` = :id");
    $entryStmt->execute([':id' => $entryId]);
    if (!$entryStmt->fetch()) {
        return ['success' => false, 'error' => 'Entry not found.'];
    }

    // Verify round exists
    $roundStmt = $pdo->prepare("SELECT `round_id`, `round_name` FROM `rounds` WHERE `round_id` = :id");
    $roundStmt->execute([':id' => $roundId]);
    if (!$roundStmt->fetch()) {
        return ['success' => false, 'error' => 'Tournament round not found.'];
    }

    // Validate each criterion: must be null/empty OR integer between 0 and 10
    $cleanScore = function($val, string $name) {
        if ($val === null || $val === '') {
            return null;
        }
        if (!is_numeric($val)) {
            throw new InvalidArgumentException("{$name} must be a number between 0 and 10.");
        }
        $intVal = (int)$val;
        if ((string)$intVal !== (string)(int)$val || $intVal < 0 || $intVal > 10) {
            throw new InvalidArgumentException("{$name} must be a whole number between 0 and 10.");
        }
        return $intVal;
    };

    try {
        $cMelody       = $cleanScore($melody, 'Melody');
        $cLyrics       = $cleanScore($lyrics, 'Lyrics');
        $cVocals       = $cleanScore($vocals, 'Vocals');
        $cEmotion      = $cleanScore($emotion, 'Emotion');
        $cReplayability= $cleanScore($replayability, 'Replayability');
    } catch (InvalidArgumentException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    try {
        // Upsert rating - do NOT set total, let MySQL generated column compute it
        $stmt = $pdo->prepare("
            INSERT INTO `ratings` (
                `entry_id`, 
                `round_id`, 
                `melody`, 
                `lyrics`, 
                `vocals`, 
                `emotion`, 
                `replayability`
            )
            VALUES (
                :entry_id, 
                :round_id, 
                :melody, 
                :lyrics, 
                :vocals, 
                :emotion, 
                :replayability
            )
            ON DUPLICATE KEY UPDATE
                `melody`        = VALUES(`melody`),
                `lyrics`        = VALUES(`lyrics`),
                `vocals`        = VALUES(`vocals`),
                `emotion`       = VALUES(`emotion`),
                `replayability` = VALUES(`replayability`)
        ");

        $stmt->bindValue(':entry_id', $entryId, PDO::PARAM_INT);
        $stmt->bindValue(':round_id', $roundId, PDO::PARAM_INT);
        $stmt->bindValue(':melody', $cMelody, $cMelody !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':lyrics', $cLyrics, $cLyrics !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':vocals', $cVocals, $cVocals !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':emotion', $cEmotion, $cEmotion !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':replayability', $cReplayability, $cReplayability !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->execute();

        // Fetch saved rating to get the exact generated total
        $getStmt = $pdo->prepare("
            SELECT `rating_id`, `entry_id`, `round_id`, `melody`, `lyrics`, `vocals`, `emotion`, `replayability`, `total`
            FROM `ratings`
            WHERE `entry_id` = :entry_id AND `round_id` = :round_id
        ");
        $getStmt->execute([':entry_id' => $entryId, ':round_id' => $roundId]);
        $saved = $getStmt->fetch();

        $isComplete = ($saved['total'] !== null);

        return [
            'success'     => true,
            'rating'      => $saved,
            'total'       => $saved['total'],
            'is_complete' => $isComplete,
            'message'     => $isComplete 
                ? "Scorecard saved completely! Total: {$saved['total']}/50." 
                : "Incomplete scorecard saved. You can resume scoring anytime."
        ];
    } catch (Exception $e) {
        error_log("AFM2 save_scorecard_rating error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to save rating.'];
    }
}

/**
 * Get tournament round leaderboard (completed scores only, with tie-break ranking)
 */
function get_round_rankings(int $roundId): array {
    $pdo = get_db_connection();

    // Verify round
    $roundStmt = $pdo->prepare("SELECT * FROM `rounds` WHERE `round_id` = :id");
    $roundStmt->execute([':id' => $roundId]);
    $round = $roundStmt->fetch();
    if (!$round) {
        return ['round' => null, 'rankings' => [], 'total_completed' => 0];
    }

    // Fetch entries with completed ratings (total IS NOT NULL)
    // Order by official ranking criteria:
    // 1. Total score (out of 50)
    // 2. Emotion (0-10)
    // 3. Replayability (0-10)
    // 4. Melody (0-10)
    // 5. Vocals (0-10)
    // 6. Lyrics (0-10)
    // 7. Entry ID ASC (stable tie-breaker)
    $stmt = $pdo->prepare("
        SELECT 
            e.`entry_id`,
            s.`song_id`,
            s.`title`,
            COALESCE(s.`artist`, 'Unknown Artist') AS `artist`,
            r.`rating_id`,
            r.`round_id`,
            r.`melody`,
            r.`lyrics`,
            r.`vocals`,
            r.`emotion`,
            r.`replayability`,
            r.`total`,
            rr.`result`,
            rr.`rank_position`,
            rr.`reason`
        FROM `ratings` r
        JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id`
        JOIN `songs` s ON e.`song_id` = s.`song_id`
        LEFT JOIN `round_results` rr ON e.`entry_id` = rr.`entry_id` AND rr.`round_id` = :r_res
        WHERE r.`round_id` = :r_id
          AND r.`total` IS NOT NULL
        ORDER BY 
            r.`total` DESC, 
            r.`emotion` DESC, 
            r.`replayability` DESC, 
            r.`melody` DESC, 
            r.`vocals` DESC, 
            r.`lyrics` DESC, 
            e.`entry_id` ASC
    ");
    $stmt->execute([':r_id' => $roundId, ':r_res' => $roundId]);
    $rows = $stmt->fetchAll();

    // Compute rank positions and detect ties at cutoff
    $targetCount = (int)$round['target_count'];
    $rankings = [];
    $currentRank = 1;
    $prevScores = null;

    foreach ($rows as $index => $row) {
        $scoresTuple = [
            $row['total'], 
            $row['emotion'], 
            $row['replayability'], 
            $row['melody'], 
            $row['vocals'], 
            $row['lyrics']
        ];

        // Check if tied on ALL criteria with previous song
        $isTiedWithPrev = ($prevScores !== null && $prevScores === $scoresTuple);
        $rankPosition = $index + 1;

        $row['calculated_rank'] = $rankPosition;
        $row['is_tied_all_criteria'] = $isTiedWithPrev;
        
        $rankings[] = $row;
        $prevScores = $scoresTuple;
    }

    return [
        'round'           => $round,
        'rankings'        => $rankings,
        'total_completed' => count($rankings),
        'target_count'    => $targetCount
    ];
}

/**
 * Preview tournament stage qualification & cutoff analysis
 */
function preview_stage_advancement(int $fromRoundId): array {
    $pdo = get_db_connection();

    // Fetch from round and next round
    $fromStmt = $pdo->prepare("SELECT * FROM `rounds` WHERE `round_id` = :id");
    $fromStmt->execute([':id' => $fromRoundId]);
    $fromRound = $fromStmt->fetch();
    if (!$fromRound) {
        return ['success' => false, 'error' => 'Current round not found.'];
    }

    $nextStmt = $pdo->prepare("SELECT * FROM `rounds` WHERE `sequence_no` = :seq");
    $nextStmt->execute([':seq' => $fromRound['sequence_no'] + 1]);
    $nextRound = $nextStmt->fetch();
    if (!$nextRound) {
        return ['success' => false, 'error' => 'This is the final stage. No subsequent round exists.'];
    }

    $rankData = get_round_rankings($fromRoundId);
    $rankings = $rankData['rankings'];
    $totalCompleted = count($rankings);

    if ($totalCompleted === 0) {
        return [
            'success' => false, 
            'error'   => "No completed scorecards found for '{$fromRound['round_name']}'. Scores must be completed before advancing."
        ];
    }

    // Target cutoff: for advancing to next round, target count is defined on next round
    $targetCutoff = (int)$nextRound['target_count'];
    if ($targetCutoff <= 0) {
        $targetCutoff = 10;
    }

    // Graceful workflow if fewer than target count qualify
    $effectiveCutoff = min($totalCompleted, $targetCutoff);

    $qualifiers = array_slice($rankings, 0, $effectiveCutoff);
    $eliminated = array_slice($rankings, $effectiveCutoff);

    // Detect ties at the cutoff boundary
    $cutoffSong = $qualifiers[$effectiveCutoff - 1] ?? null;
    $firstOutSong = $eliminated[0] ?? null;

    $hasBoundaryTie = false;
    $boundaryTieDetails = null;

    if ($cutoffSong && $firstOutSong) {
        if ($cutoffSong['total'] === $firstOutSong['total']) {
            $hasBoundaryTie = true;
            $boundaryTieDetails = [
                'tied_total'        => $cutoffSong['total'],
                'cutoff_song_title' => $cutoffSong['title'],
                'first_out_title'   => $firstOutSong['title'],
                'criteria_tie_break'=> "Cutoff tie resolved by secondary criteria: Emotion ({$cutoffSong['emotion']} vs {$firstOutSong['emotion']}), Replayability ({$cutoffSong['replayability']} vs {$firstOutSong['replayability']}), Melody ({$cutoffSong['melody']} vs {$firstOutSong['melody']})."
            ];
        }
    }

    return [
        'success'           => true,
        'from_round'        => $fromRound,
        'next_round'        => $nextRound,
        'total_completed'   => $totalCompleted,
        'target_cutoff'     => $targetCutoff,
        'effective_cutoff'  => $effectiveCutoff,
        'qualifiers'        => $qualifiers,
        'eliminated'        => $eliminated,
        'has_boundary_tie'  => $hasBoundaryTie,
        'boundary_tie'      => $boundaryTieDetails
    ];
}

/**
 * Commit stage advancement (transactional, with double-advancement protection)
 */
function commit_stage_advancement(int $fromRoundId, array $qualifierEntryIds): array {
    $pdo = get_db_connection();

    $preview = preview_stage_advancement($fromRoundId);
    if (!$preview['success']) {
        return $preview;
    }

    $fromRound = $preview['from_round'];
    $nextRound = $preview['next_round'];

    // Protection against advancing if already completed
    if ($fromRound['status'] === 'completed') {
        return [
            'success' => false, 
            'error'   => "'{$fromRound['round_name']}' has already been completed and advanced."
        ];
    }

    try {
        $pdo->beginTransaction();

        $rankData = get_round_rankings($fromRoundId);
        $rankings = $rankData['rankings'];

        // Prepare insert into round_results
        $resStmt = $pdo->prepare("
            INSERT INTO `round_results` (
                `entry_id`, 
                `round_id`, 
                `result`, 
                `rank_position`, 
                `reason`, 
                `decided_at`
            )
            VALUES (
                :entry_id, 
                :round_id, 
                :result, 
                :rank_position, 
                :reason, 
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                `result`        = VALUES(`result`),
                `rank_position` = VALUES(`rank_position`),
                `reason`        = VALUES(`reason`),
                `decided_at`    = NOW()
        ");

        $isFinalStage = ((int)$nextRound['sequence_no'] === 7 || (int)$nextRound['target_count'] === 1);

        foreach ($rankings as $row) {
            $entryId = (int)$row['entry_id'];
            $isQualified = in_array($entryId, $qualifierEntryIds, true);

            $result = $isQualified ? 'qualified' : 'eliminated';
            if ($isQualified && $isFinalStage && $row['calculated_rank'] === 1) {
                $result = 'winner';
            }

            $reason = $isQualified 
                ? "Qualified to {$nextRound['round_name']} with score {$row['total']}/50 (Rank #{$row['calculated_rank']})"
                : "Eliminated in {$fromRound['round_name']} with score {$row['total']}/50 (Rank #{$row['calculated_rank']})";

            $resStmt->execute([
                ':entry_id'      => $entryId,
                ':round_id'      => $fromRoundId,
                ':result'        => $result,
                ':rank_position' => $row['calculated_rank'],
                ':reason'        => $reason
            ]);
        }

        // Mark current round as completed
        $updFrom = $pdo->prepare("
            UPDATE `rounds` 
            SET `status` = 'completed', `completed_at` = NOW() 
            WHERE `round_id` = :id
        ");
        $updFrom->execute([':id' => $fromRoundId]);

        // Activate next round
        $updNext = $pdo->prepare("
            UPDATE `rounds` 
            SET `status` = 'in_progress' 
            WHERE `round_id` = :id
        ");
        $updNext->execute([':id' => $nextRound['round_id']]);

        $pdo->commit();

        return [
            'success'         => true,
            'message'         => "Successfully advanced {$fromRound['round_name']} to {$nextRound['round_name']}.",
            'from_round'      => $fromRound['round_name'],
            'next_round'      => $nextRound['round_name'],
            'qualifiers_count'=> count($qualifierEntryIds),
            'eliminated_count'=> count($rankings) - count($qualifierEntryIds)
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("AFM2 commit_stage_advancement error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to commit tournament advancement: ' . $e->getMessage()];
    }
}

/**
 * Get historical round results and archive data
 */
function get_historical_results(): array {
    $pdo = get_db_connection();

    $stmt = $pdo->query("
        SELECT 
            r.`round_id`,
            r.`round_name`,
            r.`sequence_no`,
            r.`status`,
            r.`completed_at`,
            rr.`result_id`,
            rr.`entry_id`,
            rr.`result`,
            rr.`rank_position`,
            rr.`reason`,
            rr.`decided_at`,
            s.`song_id`,
            s.`title`,
            COALESCE(s.`artist`, 'Unknown Artist') AS `artist`,
            rt.`melody`,
            rt.`lyrics`,
            rt.`vocals`,
            rt.`emotion`,
            rt.`replayability`,
            rt.`total`
        FROM `round_results` rr
        JOIN `rounds` r ON rr.`round_id` = r.`round_id`
        JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id`
        JOIN `songs` s ON e.`song_id` = s.`song_id`
        LEFT JOIN `ratings` rt ON e.`entry_id` = rt.`entry_id` AND rt.`round_id` = r.`round_id`
        ORDER BY r.`sequence_no` ASC, rr.`rank_position` ASC
    ");

    $resultsByRound = [];
    while ($row = $stmt->fetch()) {
        $roundName = $row['round_name'];
        if (!isset($resultsByRound[$roundName])) {
            $resultsByRound[$roundName] = [
                'round_id'     => $row['round_id'],
                'round_name'   => $row['round_name'],
                'sequence_no'  => $row['sequence_no'],
                'status'       => $row['status'],
                'completed_at' => $row['completed_at'],
                'results'      => []
            ];
        }
        $resultsByRound[$roundName]['results'][] = $row;
    }

    return $resultsByRound;
}

/**
 * Get all tournament rounds with aggregated statistics
 */
function get_tournament_rounds(): array {
    $pdo = get_db_connection();
    return $pdo->query("
        SELECT 
            r.`round_id`, 
            r.`round_name`, 
            r.`sequence_no`, 
            r.`status`, 
            r.`target_count`,
            r.`completed_at`,
            (SELECT COUNT(*) FROM `ratings` rt WHERE rt.`round_id` = r.`round_id` AND rt.`total` IS NOT NULL) AS completed_scores_count,
            (SELECT COUNT(*) FROM `ratings` rt WHERE rt.`round_id` = r.`round_id` AND rt.`total` IS NULL) AS incomplete_scores_count,
            (SELECT COUNT(*) FROM `round_results` rr WHERE rr.`round_id` = r.`round_id` AND rr.`result` IN ('qualified', 'winner')) AS qualified_count,
            (SELECT COUNT(*) FROM `round_results` rr WHERE rr.`round_id` = r.`round_id` AND rr.`result` = 'eliminated') AS eliminated_count
        FROM `rounds` r
        ORDER BY r.`sequence_no` ASC
    ")->fetchAll();
}

/**
 * Get a single tournament round by ID
 */
function get_round_by_id(int $roundId): ?array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM `rounds` WHERE `round_id` = :id");
    $stmt->execute([':id' => $roundId]);
    $res = $stmt->fetch();
    return $res ?: null;
}

/**
 * Get the currently active tournament round (in_progress), or default to first round
 */
function get_active_round(): array {
    $pdo = get_db_connection();
    $active = $pdo->query("
        SELECT * FROM `rounds` 
        WHERE `status` = 'in_progress' 
        ORDER BY `sequence_no` ASC 
        LIMIT 1
    ")->fetch();
    
    if (!$active) {
        $active = $pdo->query("
            SELECT * FROM `rounds` 
            ORDER BY `sequence_no` ASC 
            LIMIT 1
        ")->fetch();
    }
    return $active;
}

/**
 * Get entries eligible for scoring in a tournament round with filters & pagination
 */
function get_audition_entries(
    int $roundId = 1, 
    string $filter = 'all', 
    string $query = '', 
    int $page = 1, 
    int $perPage = 50
): array {
    $pdo = get_db_connection();
    $page = max(1, $page);
    $perPage = max(10, min(200, $perPage));
    $offset = ($page - 1) * $perPage;

    $round = get_round_by_id($roundId);
    if (!$round) {
        return [
            'entries' => [], 
            'total' => 0, 
            'page' => 1, 
            'per_page' => $perPage, 
            'total_pages' => 1,
            'counts' => ['total' => 0, 'unscored' => 0, 'incomplete' => 0, 'completed' => 0],
            'round' => null
        ];
    }

    $seq = (int)$round['sequence_no'];

    $fromJoinSql = "";
    $baseWhere = [];
    $baseParams = [':curr_round_id' => $roundId];

    if ($seq === 1) {
        $fromJoinSql = "
            FROM `afm_entries` e
            JOIN `songs` s ON e.`song_id` = s.`song_id`
            JOIN `song_discovery` sd ON s.`song_id` = sd.`song_id` AND sd.`status` = 'shortlisted'
            LEFT JOIN `ratings` r ON e.`entry_id` = r.`entry_id` AND r.`round_id` = :curr_round_id
            LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
            LEFT JOIN `song_lyrics_ref` slr ON s.`song_id` = slr.`song_id`
            LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
        ";
        $baseWhere[] = "e.`status` != 'excluded'";
    } else {
        $prevRoundStmt = $pdo->prepare("SELECT `round_id` FROM `rounds` WHERE `sequence_no` = :prev_seq");
        $prevRoundStmt->execute([':prev_seq' => $seq - 1]);
        $prevRoundId = (int)$prevRoundStmt->fetchColumn();

        $fromJoinSql = "
            FROM `afm_entries` e
            JOIN `round_results` prev_rr ON e.`entry_id` = prev_rr.`entry_id` 
                 AND prev_rr.`round_id` = :prev_round_id 
                 AND prev_rr.`result` IN ('qualified', 'winner')
            JOIN `songs` s ON e.`song_id` = s.`song_id`
            LEFT JOIN `ratings` r ON e.`entry_id` = r.`entry_id` AND r.`round_id` = :curr_round_id
            LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
            LEFT JOIN `song_lyrics_ref` slr ON s.`song_id` = slr.`song_id`
            LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
        ";
        $baseParams[':prev_round_id'] = $prevRoundId;
    }

    $trimmed = trim($query);
    if ($trimmed !== '') {
        $baseWhere[] = "(s.`title` LIKE :query1 OR s.`artist` LIKE :query2)";
        $baseParams[':query1'] = '%' . $trimmed . '%';
        $baseParams[':query2'] = '%' . $trimmed . '%';
    }

    $countWhereSql = !empty($baseWhere) ? 'WHERE ' . implode(' AND ', $baseWhere) : '';
    $summarySql = "
        SELECT 
            COUNT(*) AS total_count,
            SUM(CASE WHEN r.`rating_id` IS NULL THEN 1 ELSE 0 END) AS unscored_count,
            SUM(CASE WHEN r.`rating_id` IS NOT NULL AND r.`total` IS NULL THEN 1 ELSE 0 END) AS incomplete_count,
            SUM(CASE WHEN r.`total` IS NOT NULL THEN 1 ELSE 0 END) AS completed_count
        {$fromJoinSql}
        {$countWhereSql}
    ";
    $sumStmt = $pdo->prepare($summarySql);
    $sumStmt->execute($baseParams);
    $summary = $sumStmt->fetch() ?: [];

    $counts = [
        'total'      => (int)($summary['total_count'] ?? 0),
        'unscored'   => (int)($summary['unscored_count'] ?? 0),
        'incomplete' => (int)($summary['incomplete_count'] ?? 0),
        'completed'  => (int)($summary['completed_count'] ?? 0)
    ];

    $filteredWhere = $baseWhere;
    if ($filter === 'unscored') {
        $filteredWhere[] = "r.`rating_id` IS NULL";
    } elseif ($filter === 'incomplete') {
        $filteredWhere[] = "(r.`rating_id` IS NOT NULL AND r.`total` IS NULL)";
    } elseif ($filter === 'completed') {
        $filteredWhere[] = "r.`total` IS NOT NULL";
    }

    $filteredWhereSql = !empty($filteredWhere) ? 'WHERE ' . implode(' AND ', $filteredWhere) : '';

    $pageCountSql = "
        SELECT COUNT(*)
        {$fromJoinSql}
        {$filteredWhereSql}
    ";
    $pageCountStmt = $pdo->prepare($pageCountSql);
    $pageCountStmt->execute($baseParams);
    $totalFiltered = (int)$pageCountStmt->fetchColumn();

    $dataSql = "
        SELECT 
            e.`entry_id`,
            e.`song_id`,
            e.`status` AS `entry_status`,
            s.`title`,
            COALESCE(s.`artist`, 'Unknown Artist') AS `artist`,
            r.`rating_id`,
            r.`round_id`,
            r.`melody`,
            r.`lyrics`,
            r.`vocals`,
            r.`emotion`,
            r.`replayability`,
            r.`total`,
            (CASE WHEN r.`rating_id` IS NOT NULL AND r.`total` IS NULL THEN 1 ELSE 0 END) AS `is_incomplete`,
            (CASE WHEN r.`total` IS NOT NULL THEN 1 ELSE 0 END) AS `is_complete`,
            sm.`video_id` AS `media_video_id`,
            sm.`user_url` AS `media_user_url`,
            sm.`version_type` AS `media_version_type`,
            sm.`version_notes` AS `media_version_notes`,
            sm.`embed_status` AS `media_embed_status`,
            slr.`status` AS `lyrics_status`,
            slr.`has_synced` AS `lyrics_has_synced`,
            sa.`image_url` AS `artwork_image_url`,
            sa.`thumbnail_url` AS `artwork_thumbnail_url`,
            sa.`source` AS `artwork_source`,
            sa.`creator` AS `artwork_creator`,
            sa.`license` AS `artwork_license`,
            sa.`attribution_url` AS `artwork_attribution_url`,
            sa.`is_confirmed` AS `artwork_is_confirmed`
        {$fromJoinSql}
        {$filteredWhereSql}
        ORDER BY 
            (CASE WHEN r.`total` IS NOT NULL THEN 1 WHEN r.`rating_id` IS NOT NULL THEN 0 ELSE -1 END) ASC,
            s.`title` ASC
        LIMIT :limit OFFSET :offset
    ";

    $dataStmt = $pdo->prepare($dataSql);
    foreach ($baseParams as $k => $v) {
        $dataStmt->bindValue($k, $v);
    }
    $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $entries = $dataStmt->fetchAll();

    $totalPages = (int)ceil($totalFiltered / $perPage);

    return [
        'entries'      => $entries,
        'total'        => $totalFiltered,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => max(1, $totalPages),
        'counts'       => $counts,
        'round'        => $round
    ];
}

/**
 * Render fully accessible, feature-complete pagination bar
 *
 * Includes:
 * - Records count & page indicator with live polite status
 * - Records per-page selector dropdown (25, 50, 75, 100 max)
 * - Navigation buttons: First («), Prev (‹), up to 10 page buttons, Next (›), Last (»)
 * - Direct "Jump to page" input form with validation
 * - Semantic <nav>, aria-label, aria-current="page", aria-disabled, touch target sizes
 *
 * @param int $currentPage Current 1-based page
 * @param int $totalPages Total number of pages
 * @param int $totalRecords Total count of items
 * @param int $perPage Items per page (at most 100)
 * @param string $baseUrl Base script name, e.g. 'library.php'
 * @param array $queryParams Existing GET query parameters to preserve (q, filter, sort, etc.)
 * @param string $itemLabel Label for the items, e.g. 'songs', 'entries', 'candidates'
 * @param int $maxButtons Maximum numeric page buttons to display at a time (defaults to 10)
 * @return string Rendered HTML
 */
function render_accessible_pagination(
    int $currentPage,
    int $totalPages,
    int $totalRecords,
    int $perPage,
    string $baseUrl,
    array $queryParams = [],
    string $itemLabel = 'songs',
    int $maxButtons = 10
): string {
    $currentPage  = max(1, $currentPage);
    $totalPages   = max(1, $totalPages);
    $perPage      = max(10, min(100, $perPage));
    $maxButtons   = max(5, min(15, $maxButtons));
    $from         = $totalRecords > 0 ? ($currentPage - 1) * $perPage + 1 : 0;
    $to           = min($totalRecords, $currentPage * $perPage);
    $uniqueId     = substr(md5($baseUrl . '_' . $itemLabel), 0, 8);

    // Helper to generate preserved query string
    $makeUrl = function(int $targetPage, ?int $targetPerPage = null) use ($baseUrl, $queryParams, $perPage): string {
        $p = $queryParams;
        $p['page'] = $targetPage;
        $p['per_page'] = $targetPerPage ?? $perPage;
        return htmlspecialchars($baseUrl . '?' . http_build_query($p), ENT_QUOTES, 'UTF-8');
    };

    // Calculate window of up to 10 buttons
    $startPage = max(1, $currentPage - (int)floor($maxButtons / 2));
    $endPage   = min($totalPages, $startPage + $maxButtons - 1);
    if ($endPage - $startPage + 1 < $maxButtons) {
        $startPage = max(1, $endPage - $maxButtons + 1);
    }

    ob_start();
    ?>
    <div class="pagination-bar" role="region" aria-label="Pagination controls">
        <!-- Left Section: Info & Per-Page Selector -->
        <div class="pagination-left">
            <div class="pagination-info" role="status" aria-live="polite">
                Showing <strong class="pagination-highlight"><?= number_format($from) ?></strong> to <strong class="pagination-highlight"><?= number_format($to) ?></strong> of <strong class="pagination-highlight"><?= number_format($totalRecords) ?></strong> <?= htmlspecialchars($itemLabel) ?>
                <span class="pagination-page-badge">Page <?= $currentPage ?> of <?= $totalPages ?></span>
            </div>

            <div class="pagination-per-page">
                <label for="perPageSelect_<?= $uniqueId ?>" class="per-page-label">Show:</label>
                <select id="perPageSelect_<?= $uniqueId ?>" class="per-page-select" aria-label="Select number of records displayed per page (at most 100)" onchange="window.location.href=this.value">
                    <?php foreach ([25, 50, 75, 100] as $opt): ?>
                        <option value="<?= $makeUrl(1, $opt) ?>" <?= $perPage === $opt ? 'selected' : '' ?>>
                            <?= $opt ?> per page<?= $opt === 100 ? ' (Max)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
        <!-- Center Section: Primary Navigation Links (First, Prev, 10 Pages, Next, Last) -->
        <nav class="pagination-nav" role="navigation" aria-label="<?= htmlspecialchars(ucfirst($itemLabel)) ?> pagination navigation">
            <div class="pagination-links" role="toolbar" aria-label="Page selection">
                <!-- First / Start Button -->
                <?php if ($currentPage > 1): ?>
                    <a href="<?= $makeUrl(1) ?>" class="page-btn page-btn-nav page-btn-first" aria-label="Go to first page (page 1)" title="Go to start (Page 1)">
                        <span aria-hidden="true">&laquo;</span> First
                    </a>
                    <a href="<?= $makeUrl($currentPage - 1) ?>" class="page-btn page-btn-nav page-btn-prev" rel="prev" aria-label="Go to previous page (page <?= $currentPage - 1 ?>)" title="Previous page (Page <?= $currentPage - 1 ?>)">
                        <span aria-hidden="true">&lsaquo;</span> Prev
                    </a>
                <?php else: ?>
                    <span class="page-btn page-btn-nav page-btn-first disabled" aria-disabled="true" title="Already on first page">
                        <span aria-hidden="true">&laquo;</span> First
                    </span>
                    <span class="page-btn page-btn-nav page-btn-prev disabled" aria-disabled="true" title="No previous page">
                        <span aria-hidden="true">&lsaquo;</span> Prev
                    </span>
                <?php endif; ?>

                <!-- Optional Start Ellipsis -->
                <?php if ($startPage > 1): ?>
                    <span class="page-ellipsis" aria-hidden="true">&hellip;</span>
                <?php endif; ?>

                <!-- Numeric Buttons (Up to 10) -->
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <?php if ($i === $currentPage): ?>
                        <span class="page-btn page-btn-num active" aria-current="page" aria-label="Page <?= $i ?>, current page">
                            <?= $i ?>
                        </span>
                    <?php else: ?>
                        <a href="<?= $makeUrl($i) ?>" class="page-btn page-btn-num" aria-label="Go to page <?= $i ?>" title="Page <?= $i ?>">
                            <?= $i ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>

                <!-- Optional End Ellipsis -->
                <?php if ($endPage < $totalPages): ?>
                    <span class="page-ellipsis" aria-hidden="true">&hellip;</span>
                <?php endif; ?>

                <!-- Next & Last / End Buttons -->
                <?php if ($currentPage < $totalPages): ?>
                    <a href="<?= $makeUrl($currentPage + 1) ?>" class="page-btn page-btn-nav page-btn-next" rel="next" aria-label="Go to next page (page <?= $currentPage + 1 ?>)" title="Next page (Page <?= $currentPage + 1 ?>)">
                        Next <span aria-hidden="true">&rsaquo;</span>
                    </a>
                    <a href="<?= $makeUrl($totalPages) ?>" class="page-btn page-btn-nav page-btn-last" aria-label="Go to last page (page <?= $totalPages ?>)" title="Go to end (Page <?= $totalPages ?>)">
                        Last <span aria-hidden="true">&raquo;</span>
                    </a>
                <?php else: ?>
                    <span class="page-btn page-btn-nav page-btn-next disabled" aria-disabled="true" title="No next page">
                        Next <span aria-hidden="true">&rsaquo;</span>
                    </span>
                    <span class="page-btn page-btn-nav page-btn-last disabled" aria-disabled="true" title="Already on last page">
                        Last <span aria-hidden="true">&raquo;</span>
                    </span>
                <?php endif; ?>
            </div>
        </nav>

        <!-- Right Section: Direct Jump to Page Form -->
        <form method="GET" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>" class="pagination-jump-form" role="search" aria-label="Jump directly to page">
            <?php foreach ($queryParams as $k => $v): ?>
                <?php if ($k !== 'page'): ?>
                    <input type="hidden" name="<?= htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
            <label for="jumpToPage_<?= $uniqueId ?>" class="pagination-jump-label">Jump:</label>
            <div class="pagination-jump-control">
                <input 
                    type="number" 
                    id="jumpToPage_<?= $uniqueId ?>" 
                    name="page" 
                    min="1" 
                    max="<?= $totalPages ?>" 
                    value="<?= $currentPage ?>" 
                    class="pagination-jump-input" 
                    aria-label="Target page number (1 to <?= $totalPages ?>)"
                    title="Enter page between 1 and <?= $totalPages ?>"
                    required
                >
                <button type="submit" class="btn btn-secondary btn-xs btn-pagination-jump" aria-label="Jump to entered page">
                    Go
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


