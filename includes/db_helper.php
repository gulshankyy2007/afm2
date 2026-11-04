<?php
/**
 * AFM#2 - Database Helper & Business Logic Operations
 */

if (!defined('AFM_APP')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/media_helper.php';
require_once __DIR__ . '/auth.php';

/**
 * Request-level cache container for dashboard statistics and catalogue metrics.
 * Scoped strictly to the current request lifecycle.
 */
function &get_dashboard_stats_cache_ref(): array {
    static $cache = [
        'stats'      => null,
        'song_count' => null,
    ];
    return $cache;
}

/**
 * Return cached dashboard statistics if already computed in this request, without triggering queries.
 */
function get_cached_dashboard_stats(): ?array {
    $cache = &get_dashboard_stats_cache_ref();
    return $cache['stats'];
}

/**
 * Invalidate request-level dashboard statistics cache and catalogue count.
 */
function clear_dashboard_stats_cache(): void {
    $cache = &get_dashboard_stats_cache_ref();
    $cache['stats'] = null;
    $cache['song_count'] = null;
}

/**
 * Transactionally initialize a user's independent starter catalogue from the immutable master collection.
 * 
 * Safely copies all songs, media links, and artwork references from master_starter_* tables,
 * assigning brand new auto-increment song IDs under the user's specific user_id.
 * Protects against duplicate execution and concurrent initialization requests using InnoDB row locks.
 *
 * @param int $userId Target user ID
 * @param int $starterVersion Starter catalogue version (default 1)
 * @param PDO|null $pdo Optional existing PDO connection
 * @return array Result summary with counts and status
 */
function initialize_user_starter_catalogue(int $userId, int $starterVersion = 1, ?PDO $pdo = null): array {
    if ($userId <= 0) {
        return ['success' => false, 'error' => 'Invalid user ID.'];
    }

    if ($pdo === null) {
        $pdo = get_db_connection();
    }

    // Verify user exists in app_users
    $userStmt = $pdo->prepare("SELECT `user_id`, `username` FROM `app_users` WHERE `user_id` = :uid LIMIT 1");
    $userStmt->execute([':uid' => $userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return ['success' => false, 'error' => "User ID {$userId} does not exist."];
    }

    $inOurTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $inOurTx = true;
    }

    try {
        // Concurrency protection: Check if user already initialized using FOR UPDATE
        $checkStmt = $pdo->prepare("
            SELECT `user_id`, `starter_version`, `songs_cloned` 
            FROM `user_catalogue_initialization` 
            WHERE `user_id` = :uid 
            FOR UPDATE
        ");
        $checkStmt->execute([':uid' => $userId]);
        $existingInit = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingInit) {
            if ($inOurTx) {
                $pdo->commit();
            }
            return [
                'success'             => true,
                'already_initialized' => true,
                'user_id'             => $userId,
                'starter_version'     => (int)$existingInit['starter_version'],
                'songs_cloned'        => (int)$existingInit['songs_cloned']
            ];
        }

        // Verify master_starter_songs has records. If not (first boot), seed it from songs <= 2411
        $masterCountStmt = $pdo->prepare("SELECT COUNT(*) FROM `master_starter_songs` WHERE `starter_version` = :ver");
        $masterCountStmt->execute([':ver' => $starterVersion]);
        $masterCount = (int)$masterCountStmt->fetchColumn();

        if ($masterCount === 0) {
            $pdo->exec("
                INSERT INTO `master_starter_songs` (`source_song_id`, `starter_version`, `title`, `artist`)
                SELECT `song_id`, 1, `title`, `artist`
                FROM `songs`
                WHERE `song_id` <= 2411
                ORDER BY `song_id` ASC
                ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `artist` = VALUES(`artist`)
            ");
            $masterCount = (int)$pdo->query("SELECT COUNT(*) FROM `master_starter_songs` WHERE `starter_version` = 1")->fetchColumn();
        }

        // Fetch all starter songs ordered by source_song_id
        $starterSongsStmt = $pdo->prepare("
            SELECT `source_song_id`, `title`, `artist` 
            FROM `master_starter_songs` 
            WHERE `starter_version` = :ver 
            ORDER BY `source_song_id` ASC
        ");
        $starterSongsStmt->execute([':ver' => $starterVersion]);
        $starterSongs = $starterSongsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($starterSongs)) {
            if ($inOurTx) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Master starter catalogue is empty.'];
        }

        // Insert cloned songs into songs table under this user_id
        $insertSong = $pdo->prepare("
            INSERT INTO `songs` (`user_id`, `title`, `artist`, `is_consolidated`, `deleted_at`)
            VALUES (:uid, :title, :artist, 0, NULL)
        ");

        $songIdMap = []; // source_song_id => new_song_id
        foreach ($starterSongs as $s) {
            $insertSong->execute([
                ':uid'    => $userId,
                ':title'  => $s['title'],
                ':artist' => $s['artist']
            ]);
            $newSongId = (int)$pdo->lastInsertId();
            $songIdMap[(int)$s['source_song_id']] = $newSongId;
        }

        // Clone starter media (160 tracks)
        $mediaClonedCount = 0;
        $starterMedia = $pdo->query("SELECT * FROM `master_starter_media`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($starterMedia)) {
            $insMedia = $pdo->prepare("
                INSERT INTO `song_media` (`song_id`, `service`, `video_id`, `title`, `channel_title`, `version_type`, `embed_status`)
                VALUES (:song_id, :service, :video_id, :title, :channel_title, :version_type, :embed_status)
            ");
            foreach ($starterMedia as $m) {
                $srcId = (int)$m['source_song_id'];
                if (isset($songIdMap[$srcId])) {
                    $insMedia->execute([
                        ':song_id'       => $songIdMap[$srcId],
                        ':service'       => $m['service'],
                        ':video_id'      => $m['video_id'],
                        ':title'         => $m['title'],
                        ':channel_title' => $m['channel_title'],
                        ':version_type'  => $m['version_type'],
                        ':embed_status'  => $m['embed_status']
                    ]);
                    $mediaClonedCount++;
                }
            }
        }

        // Clone starter artwork (160 tracks)
        $artClonedCount = 0;
        $starterArt = $pdo->query("SELECT * FROM `master_starter_artwork`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($starterArt)) {
            $insArt = $pdo->prepare("
                INSERT INTO `song_artwork` (`song_id`, `source`, `image_url`, `thumbnail_url`, `is_confirmed`, `status`)
                VALUES (:song_id, :source, :image_url, :thumbnail_url, :is_confirmed, :status)
            ");
            foreach ($starterArt as $a) {
                $srcId = (int)$a['source_song_id'];
                if (isset($songIdMap[$srcId])) {
                    $insArt->execute([
                        ':song_id'       => $songIdMap[$srcId],
                        ':source'        => $a['source'],
                        ':image_url'     => $a['image_url'],
                        ':thumbnail_url' => $a['thumbnail_url'],
                        ':is_confirmed'  => $a['is_confirmed'],
                        ':status'        => $a['status']
                    ]);
                    $artClonedCount++;
                }
            }
        }

        // Initialize user_round_progress for this user across all tournament rounds
        $rounds = $pdo->query("SELECT `round_id`, `status` FROM `rounds` ORDER BY `sequence_no` ASC")->fetchAll(PDO::FETCH_ASSOC);
        $insUrp = $pdo->prepare("
            INSERT INTO `user_round_progress` (`user_id`, `round_id`, `status`, `completed_at`)
            VALUES (:uid, :rid, :st, NULL)
            ON DUPLICATE KEY UPDATE `status` = VALUES(`status`)
        ");
        foreach ($rounds as $idx => $r) {
            $initStatus = ($idx === 0) ? 'in_progress' : 'upcoming';
            $insUrp->execute([
                ':uid' => $userId,
                ':rid' => $r['round_id'],
                ':st'  => $initStatus
            ]);
        }

        // Record initialization in user_catalogue_initialization
        $insInit = $pdo->prepare("
            INSERT INTO `user_catalogue_initialization` (`user_id`, `starter_version`, `songs_cloned`)
            VALUES (:uid, :ver, :cnt)
        ");
        $insInit->execute([
            ':uid' => $userId,
            ':ver' => $starterVersion,
            ':cnt' => count($starterSongs)
        ]);

        if ($inOurTx) {
            $pdo->commit();
        }

        return [
            'success'             => true,
            'already_initialized' => false,
            'user_id'             => $userId,
            'starter_version'     => $starterVersion,
            'songs_cloned'        => count($starterSongs),
            'media_cloned'        => $mediaClonedCount,
            'artwork_cloned'      => $artClonedCount
        ];
    } catch (\Throwable $e) {
        if ($inOurTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("AFM2 initialize_user_starter_catalogue error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to initialize starter catalogue: ' . $e->getMessage()];
    }
}

/**
 * Check if a table exists in the current database schema
 */
function has_table(string $tableName, ?PDO $pdo = null): bool {
    static $tables = [];
    if (isset($tables[$tableName])) {
        return $tables[$tableName];
    }
    if ($pdo === null) {
        $pdo = get_db_connection();
    }
    try {
        $safeTbl = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $stmt = $pdo->query("SHOW TABLES LIKE '{$safeTbl}'");
        $tables[$tableName] = (bool)($stmt ? $stmt->fetch() : false);
    } catch (\Throwable $e) {
        $tables[$tableName] = false;
    }
    return $tables[$tableName];
}

/**
 * Check if a specific column exists on a database table
 */
function has_column(string $tableName, string $columnName, ?PDO $pdo = null): bool {
    static $columns = [];
    $key = "{$tableName}.{$columnName}";
    if (isset($columns[$key])) {
        return $columns[$key];
    }
    if ($pdo === null) {
        $pdo = get_db_connection();
    }
    try {
        $safeTbl = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $safeCol = preg_replace('/[^a-zA-Z0-9_]/', '', $columnName);
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$safeTbl}` LIKE '{$safeCol}'");
        $columns[$key] = (bool)($stmt ? $stmt->fetch() : false);
    } catch (\Throwable $e) {
        $columns[$key] = false;
    }
    return $columns[$key];
}

/**
 * Safely check if the songs table has the deleted_at column
 */
function has_song_deleted_at_column(?PDO $pdo = null): bool {
    return has_column('songs', 'deleted_at', $pdo);
}

/**
 * Resolves the primary legacy administrator/owner user ID (username = 'gulshan').
 * In production (InfinityFree), Gulshan's user ID is 118.
 * In local/dev environments, it may be 995 or 118.
 * Caches the resolved user ID statically for high query performance.
 */
function get_legacy_user_id(?PDO $pdo = null): int {
    static $cachedId = null;
    if ($cachedId !== null) {
        return $cachedId;
    }
    if ($pdo === null) {
        $pdo = get_db_connection();
    }
    try {
        $stmt = $pdo->query("SELECT `user_id` FROM `app_users` WHERE `username` = 'gulshan' LIMIT 1");
        $val = $stmt ? $stmt->fetchColumn() : false;
        if ($val !== false && $val !== null) {
            $cachedId = (int)$val;
            return $cachedId;
        }
    } catch (\Throwable $e) {}
    $cachedId = 995; // safe fallback
    return $cachedId;
}

/**
 * Determines whether a given user ID is the primary legacy catalogue owner.
 */
function is_legacy_owner(?int $userId, ?PDO $pdo = null): bool {
    if ($userId === null) {
        return false;
    }
    return (int)$userId === get_legacy_user_id($pdo);
}

/**
 * Returns SQL snippet for deleted_at check if column exists, or 1=1 if not yet migrated
 */
function song_deleted_sql(string $tableAlias = '', ?PDO $pdo = null): string {
    if (!has_song_deleted_at_column($pdo)) {
        return '1=1';
    }
    $prefix = $tableAlias !== '' ? "`{$tableAlias}`." : '';
    return "{$prefix}`deleted_at` IS NULL";
}

/**
 * Returns user ownership condition. In unmigrated legacy databases where songs.user_id is NULL,
 * assigns legacy unassigned songs to the primary administrator/owner (Gulshan).
 */
function song_user_scope_sql(int $userId, string $tableAlias = '', ?PDO $pdo = null): string {
    $prefix = $tableAlias !== '' ? "`{$tableAlias}`." : '';
    if ($pdo === null) {
        $pdo = get_db_connection();
    }
    $aliasToTable = [
        's'   => 'songs',
        'e'   => 'afm_entries',
        'r'   => 'ratings',
        'rt'  => 'ratings',
        'r1'  => 'ratings',
        'rr'  => 'round_results',
        'sd'  => 'song_discovery',
    ];
    $tbl = $aliasToTable[$tableAlias] ?? ($tableAlias !== '' ? $tableAlias : 'songs');
    if (!has_column($tbl, 'user_id', $pdo)) {
        return '1=1';
    }
    $legacyId = get_legacy_user_id($pdo);
    if ($userId === $legacyId) {
        return "({$prefix}`user_id` = {$legacyId} OR {$prefix}`user_id` IS NULL)";
    }
    return "{$prefix}`user_id` = " . (int)$userId;
}

/**
 * Get lightweight total active song count without computing full dashboard statistics.
 * Uses cached dashboard stats if available, or executes a single fast COUNT query.
 * Returns null if the database query fails, avoiding fabricated fallback counts.
 */
function get_catalogue_song_count(int|bool|null $userId = null, bool $forceRefresh = false): ?int {
    if (is_bool($userId)) {
        $forceRefresh = $userId;
        $userId = null;
    }
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    $cache = &get_dashboard_stats_cache_ref();
    if ($userId === null && $cache['song_count'] !== null && !$forceRefresh) {
        return $cache['song_count'];
    }
    if ($userId !== null && isset($cache['user_count_' . $userId]) && !$forceRefresh) {
        return $cache['user_count_' . $userId];
    }

    try {
        $pdo = get_db_connection();
        $delSql = song_deleted_sql('', $pdo);
        if ($userId !== null) {
            $userSql = song_user_scope_sql($userId, '', $pdo);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0 AND {$delSql} AND {$userSql}");
            $stmt->execute();
            $count = (int)$stmt->fetchColumn();
            $cache['user_count_' . $userId] = $count;
            return $count;
        }
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0 AND {$delSql}")->fetchColumn();
        $cache['song_count'] = $count;
        return $count;
    } catch (\Throwable $e) {
        error_log("AFM2 Catalogue Song Count Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get comprehensive statistics for the dashboard
 * Memoized per-request to avoid redundant database execution across controllers and templates.
 */
function get_dashboard_stats(?int $userId = null, bool $forceRefresh = false): array {
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    $cache = &get_dashboard_stats_cache_ref();
    if ($userId === null && $cache['stats'] !== null && !$forceRefresh) {
        return $cache['stats'];
    }
    if ($userId !== null && isset($cache['user_' . $userId]) && !$forceRefresh) {
        return $cache['user_' . $userId];
    }

    $pdo = get_db_connection();

    if ($userId !== null) {
        $delSql = song_deleted_sql('', $pdo);
        $delSqlS = song_deleted_sql('s', $pdo);
        $userSql = song_user_scope_sql($userId, '', $pdo);
        $userSqlS = song_user_scope_sql($userId, 's', $pdo);

        // Scoped strictly to specific authenticated user
        $stmtSongCount = $pdo->prepare("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0 AND {$delSql} AND {$userSql}");
        $stmtSongCount->execute();
        $totalSongs = (int)$stmtSongCount->fetchColumn();

        $stmtDisc = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN sd.`status` = 'shortlisted' THEN 1 ELSE 0 END) AS shortlisted_count,
                SUM(CASE WHEN sd.`status` = 'passed' THEN 1 ELSE 0 END) AS passed_count
            FROM `song_discovery` sd
            JOIN `songs` s ON sd.`song_id` = s.`song_id` AND s.`is_consolidated` = 0 AND {$delSqlS}
            WHERE (sd.`user_id` = :uid OR (sd.`user_id` IS NULL AND :uid_g = 995))
              AND {$userSqlS}
        ");
        $stmtDisc->execute([':uid' => $userId, ':uid_g' => $userId]);
        $discovery = $stmtDisc->fetch(PDO::FETCH_ASSOC);
        $shortlisted = (int)($discovery['shortlisted_count'] ?? 0);
        $passed = (int)($discovery['passed_count'] ?? 0);
        $reviewed = $shortlisted + $passed;
        $unreviewed = max(0, $totalSongs - $reviewed);

        $stmtAud = $pdo->prepare("
            SELECT COUNT(*) 
            FROM `afm_entries` e
            JOIN `songs` s ON e.`song_id` = s.`song_id` AND s.`is_consolidated` = 0 AND {$delSqlS}
            WHERE (e.`user_id` = :uid OR (e.`user_id` IS NULL AND :uid_g = 995))
              AND {$userSqlS}
              AND e.`status` != 'excluded'
        ");
        $stmtAud->execute([':uid' => $userId, ':uid_g' => $userId]);
        $auditionsTotal = (int)$stmtAud->fetchColumn();

        $stmtComp = $pdo->prepare("SELECT COUNT(*) FROM `ratings` WHERE `round_id` = 1 AND (user_id = :uid OR (user_id IS NULL AND :uid_g = 995)) AND `total` IS NOT NULL");
        $stmtComp->execute([':uid' => $userId, ':uid_g' => $userId]);
        $auditionsCompleted = (int)$stmtComp->fetchColumn();

        $stmtIncomp = $pdo->prepare("SELECT COUNT(*) FROM `ratings` WHERE `round_id` = 1 AND (user_id = :uid OR (user_id IS NULL AND :uid_g = 995)) AND `total` IS NULL");
        $stmtIncomp->execute([':uid' => $userId, ':uid_g' => $userId]);
        $auditionsIncomplete = (int)$stmtIncomp->fetchColumn();

        $rounds = get_tournament_rounds($userId);
        $activeRound = get_active_round($userId);

        $stmtRecent = $pdo->prepare("
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
            JOIN `songs` s ON sd.`song_id` = s.`song_id` AND s.`is_consolidated` = 0 AND {$delSqlS}
            LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
            LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
            WHERE (sd.`user_id` = :uid OR (sd.`user_id` IS NULL AND :uid_g = 995))
              AND {$userSqlS}
              AND sd.`status` = 'shortlisted'
            ORDER BY sd.`reviewed_at` DESC
            LIMIT 6
        ");
        $stmtRecent->execute([':uid' => $userId, ':uid_g' => $userId]);
        $recentShortlisted = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

        $stats = [
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
        $cache['user_' . $userId] = $stats;
        return $stats;
    }

    // Unscoped / Global (e.g. for existing CLI test suites)
    $delSql = song_deleted_sql('', $pdo);
    // Total catalogue count
    $totalSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0 AND {$delSql}")->fetchColumn();

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

    $cache['stats'] = [
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
    $cache['song_count'] = $totalSongs;

    return $cache['stats'];
}

/**
 * Search and paginate song catalogue
 */
function search_songs(string $query = '', string $statusFilter = 'all', int $page = 1, int $perPage = 50, string $sortBy = 'id_asc', ?int $userId = null): array {
    $pdo = get_db_connection();
    $page = max(1, $page);
    $perPage = max(10, min(200, $perPage));
    $offset = ($page - 1) * $perPage;

    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }

    if ($userId === null) {
        return [
            'songs'        => [],
            'total'        => 0,
            'page'         => $page,
            'per_page'     => $perPage,
            'sort'         => $sortBy,
            'total_pages'  => 1,
            'has_next'     => false,
            'has_prev'     => false
        ];
    }

    $uidVal = (int)$userId;
    $userClause = song_user_scope_sql($userId, 's', $pdo);
    $delClause = song_deleted_sql('s', $pdo);
    $whereClauses = [
        $userClause,
        $delClause,
        "s.`is_consolidated` = 0"
    ];
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

    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

    $discJoinCond = ($userId === 995)
        ? " AND (sd.`user_id` = 995 OR sd.`user_id` IS NULL)"
        : " AND sd.`user_id` = {$uidVal}";
    $entryJoinCond = ($userId === 995)
        ? " AND (e.`user_id` = 995 OR e.`user_id` IS NULL)"
        : " AND e.`user_id` = {$uidVal}";
    $ratingJoinCond = ($userId === 995)
        ? " AND (r1.`user_id` = 995 OR r1.`user_id` IS NULL)"
        : " AND r1.`user_id` = {$uidVal}";

    // Count total matches
    $countSql = "
        SELECT COUNT(*)
        FROM `songs` s
        LEFT JOIN `song_discovery` sd ON s.`song_id` = sd.`song_id`{$discJoinCond}
        {$whereSql}
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    if ($total === 0) {
        return [
            'songs'        => [],
            'total'        => 0,
            'page'         => $page,
            'per_page'     => $perPage,
            'sort'         => $sortBy,
            'total_pages'  => 1,
            'has_next'     => false,
            'has_prev'     => false
        ];
    }

    // Sorting options (Title ASC/DESC, Artist ASC/DESC, ID ASC/DESC)
    $validSorts = [
        'id_asc'      => 's.`song_id` ASC',
        'id_desc'     => 's.`song_id` DESC',
        'title_asc'   => 's.`title` ASC, (s.`artist` IS NULL) ASC, s.`artist` ASC, s.`song_id` ASC',
        'title_desc'  => 's.`title` DESC, (s.`artist` IS NULL) ASC, s.`artist` ASC, s.`song_id` ASC',
        'artist_asc'  => '(s.`artist` IS NULL) ASC, s.`artist` ASC, s.`title` ASC, s.`song_id` ASC',
        'artist_desc' => '(s.`artist` IS NULL) ASC, s.`artist` DESC, s.`title` ASC, s.`song_id` ASC'
    ];
    $orderClause = $validSorts[$sortBy] ?? $validSorts['id_asc'];

    // Fetch paginated records with deferred join for high-performance index pagination
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
        FROM (
            SELECT s.`song_id`
            FROM `songs` s
            LEFT JOIN `song_discovery` sd ON s.`song_id` = sd.`song_id`{$discJoinCond}
            {$whereSql}
            ORDER BY {$orderClause}
            LIMIT :limit OFFSET :offset
        ) p
        JOIN `songs` s ON p.`song_id` = s.`song_id`
        LEFT JOIN `song_discovery` sd ON s.`song_id` = sd.`song_id`{$discJoinCond}
        LEFT JOIN `afm_entries` e ON s.`song_id` = e.`song_id`{$entryJoinCond}
        LEFT JOIN `ratings` r1 ON e.`entry_id` = r1.`entry_id` AND r1.`round_id` = 1{$ratingJoinCond}
        LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
        LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
        ORDER BY {$orderClause}
    ";

    $dataStmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $dataStmt->bindValue($k, $v);
    }
    $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $songs = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

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
function get_song_by_id(int $songId, ?int $userId = null): ?array {
    $pdo = get_db_connection();
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    if ($userId === null) {
        return null;
    }
    $delSql = song_deleted_sql('', $pdo);
    $userSql = song_user_scope_sql($userId, '', $pdo);
    $stmt = $pdo->prepare("SELECT * FROM `songs` WHERE `song_id` = :id AND {$userSql} AND {$delSql}");
    $stmt->execute([':id' => $songId]);
    $song = $stmt->fetch(PDO::FETCH_ASSOC);
    return $song ?: null;
}

/**
 * Update discovery status for a song ('unreviewed', 'shortlisted', 'passed')
 */
function update_song_discovery_status(int $songId, string $status, ?int $userId = null): array {
    $pdo = get_db_connection();
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    if ($userId === null && php_sapi_name() === 'cli') {
        $userId = get_legacy_user_id($pdo);
    }
    if ($userId === null) {
        return ['success' => false, 'error' => 'Authentication required.'];
    }

    $legacyId = get_legacy_user_id($pdo);

    // Verify song exists and belongs to user
    $delSql = song_deleted_sql('', $pdo);
    $delSqlS = song_deleted_sql('s', $pdo);
    $userSql = song_user_scope_sql($userId, '', $pdo);
    $userSqlS = song_user_scope_sql($userId, 's', $pdo);

    $stmt = $pdo->prepare("SELECT `song_id`, `user_id`, `title`, `artist`, `is_consolidated`, `consolidated_into_id` FROM `songs` WHERE `song_id` = :id AND {$userSql} AND {$delSql}");
    $stmt->execute([':id' => $songId]);
    $song = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$song) {
        return ['success' => false, 'error' => 'Unauthorized: Song not found in your catalogue.'];
    }

    if ((int)($song['is_consolidated'] ?? 0) === 1 && $status === 'shortlisted') {
        return ['success' => false, 'error' => 'Cannot shortlist a consolidated duplicate. Inspect canonical song #' . $song['consolidated_into_id'] . ' instead.'];
    }

    $validStatuses = ['unreviewed', 'shortlisted', 'passed'];
    if (!in_array($status, $validStatuses, true)) {
        return ['success' => false, 'error' => 'Invalid discovery status.'];
    }

    // FORMAL AUDITIONS IMMUTABILITY SAFEGUARD: Once Formal Auditions is finalized, the shortlist is permanently frozen.
    $stage1 = get_round_by_id(1, $userId);
    $isFormalAuditionsClosed = ($stage1 && $stage1['status'] === 'completed');
    if ($isFormalAuditionsClosed) {
        if ($status === 'shortlisted') {
            return [
                'success' => false,
                'blocked' => true,
                'error'   => 'Formal Auditions has been finalized and locked. New songs cannot be added to the finalized shortlist.'
            ];
        }
        // If the song is currently shortlisted on the historical shortlist, prevent removing it
        $curStatusStmt = $pdo->prepare("
            SELECT `status` FROM `song_discovery` 
            WHERE `song_id` = :id AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = :leg_id))
        ");
        $curStatusStmt->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
        if ($curStatusStmt->fetchColumn() === 'shortlisted') {
            return [
                'success' => false,
                'blocked' => true,
                'error'   => 'Formal Auditions has been finalized and locked. Songs cannot be removed from the finalized historical shortlist.'
            ];
        }
    }

    // SAFEGUARD: If moving from shortlisted to unreviewed or passed, check for active scores or tournament history
    if ($status !== 'shortlisted') {
        $scoreCheck = $pdo->prepare("
            SELECT COUNT(*) 
            FROM `ratings` r 
            JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` 
            WHERE e.`song_id` = :id 
              AND (r.`user_id` = :uid OR (r.`user_id` IS NULL AND :uid_g = :leg_id))
              AND (r.`total` IS NOT NULL OR r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL)
        ");
        $scoreCheck->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
        if ((int)$scoreCheck->fetchColumn() > 0) {
            return [
                'success' => false,
                'blocked' => true,
                'error'   => "Cannot remove \"{$song['title']}\" from your shortlist: It currently has an active audition score. Please reset its score first."
            ];
        }

        $rrCheck = $pdo->prepare("
            SELECT COUNT(*) 
            FROM `round_results` rr 
            JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id` 
            WHERE e.`song_id` = :id 
              AND (rr.`user_id` = :uid OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))
        ");
        $rrCheck->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
        if ((int)$rrCheck->fetchColumn() > 0) {
            return [
                'success' => false,
                'blocked' => true,
                'error'   => "Cannot remove \"{$song['title']}\" from competition: This song is referenced in your completed tournament history."
            ];
        }
    } else {
        // When shortlisting, check if another active entry already exists for the same title and artist for this user
        $cleanTitle = trim($song['title']);
        $cleanArtist = trim($song['artist'] ?? '');
        $dupEntryStmt = $pdo->prepare("
            SELECT e.`entry_id`, e.`song_id`, s.`title`
            FROM `afm_entries` e
            JOIN `songs` s ON e.`song_id` = s.`song_id`
            WHERE (e.`user_id` = :uid OR (e.`user_id` IS NULL AND :uid_g = :leg_id))
              AND e.`song_id` != :sid
              AND LOWER(TRIM(s.`title`)) = LOWER(TRIM(:title))
              AND LOWER(TRIM(COALESCE(s.`artist`, ''))) = LOWER(TRIM(:artist))
              AND e.`status` = 'auditioning'
            LIMIT 1
        ");
        $dupEntryStmt->execute([
            ':uid'    => $userId,
            ':uid_g'  => $userId,
            ':leg_id' => $legacyId,
            ':sid'    => $songId,
            ':title'  => $cleanTitle,
            ':artist' => $cleanArtist
        ]);
        $dupEntry = $dupEntryStmt->fetch(PDO::FETCH_ASSOC);
        if ($dupEntry) {
            return [
                'success' => false,
                'blocked' => true,
                'error'   => "Cannot shortlist: An active audition entry already exists for \"{$song['title']}\" (Track #{$dupEntry['song_id']}) in your competition."
            ];
        }
    }

    try {
        $pdo->beginTransaction();

        if ($status === 'unreviewed') {
            $del = $pdo->prepare("DELETE FROM `song_discovery` WHERE `song_id` = :id AND `user_id` = :uid");
            $del->execute([':id' => $songId, ':uid' => $userId]);

            // If entry exists in afm_entries and has no recorded ratings, clean it up
            $delEntry = $pdo->prepare("
                DELETE FROM `afm_entries` 
                WHERE `song_id` = :id 
                  AND `user_id` = :uid 
                  AND `entry_id` NOT IN (SELECT `entry_id` FROM `ratings` WHERE `user_id` = :uid2)
            ");
            $delEntry->execute([':id' => $songId, ':uid' => $userId, ':uid2' => $userId]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO `song_discovery` (`song_id`, `user_id`, `status`, `reviewed_at`)
                VALUES (:id, :uid, :status, NOW())
                ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `reviewed_at` = NOW()
            ");
            $ins->execute([':id' => $songId, ':uid' => $userId, ':status' => $status]);

            if ($status === 'shortlisted') {
                $entryStmt = $pdo->prepare("
                    INSERT INTO `afm_entries` (`song_id`, `user_id`, `status`)
                    VALUES (:id, :uid, 'auditioning')
                    ON DUPLICATE KEY UPDATE `status` = 'auditioning'
                ");
                $entryStmt->execute([':id' => $songId, ':uid' => $userId]);
            } else {
                $delEntry = $pdo->prepare("
                    DELETE FROM `afm_entries` 
                    WHERE `song_id` = :id 
                      AND `user_id` = :uid 
                      AND `entry_id` NOT IN (SELECT `entry_id` FROM `ratings` WHERE `user_id` = :uid2)
                ");
                $delEntry->execute([':id' => $songId, ':uid' => $userId, ':uid2' => $userId]);
            }
        }

        $pdo->commit();
        clear_dashboard_stats_cache();

        // Get updated counts
        $stmtCounts = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN sd.`status` = 'shortlisted' THEN 1 ELSE 0 END) AS shortlisted,
                SUM(CASE WHEN sd.`status` = 'passed' THEN 1 ELSE 0 END) AS passed
            FROM `song_discovery` sd
            JOIN `songs` s ON sd.`song_id` = s.`song_id` AND s.`is_consolidated` = 0 AND {$delSqlS}
            WHERE (sd.`user_id` = :uid OR (sd.`user_id` IS NULL AND :uid_g = 995))
              AND {$userSqlS}
        ");
        $stmtCounts->execute([':uid' => $userId, ':uid_g' => $userId]);
        $counts = $stmtCounts->fetch(PDO::FETCH_ASSOC);

        $totStmt = $pdo->prepare("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0 AND {$delSql} AND {$userSql}");
        $totStmt->execute();
        $totalSongs = (int)$totStmt->fetchColumn();

        $shortlisted = (int)($counts['shortlisted_count'] ?? ($counts['shortlisted'] ?? 0));
        $passed = (int)($counts['passed_count'] ?? ($counts['passed'] ?? 0));
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
function find_existing_catalogue_song(string $title, ?string $artist = null, ?int $userId = null): ?array {
    $cleanTitle = trim($title);
    if ($cleanTitle === '') {
        return null;
    }

    $cleanArtist = $artist !== null ? trim($artist) : null;
    if ($cleanArtist === null || $cleanArtist === '' || $cleanArtist === '-' || strcasecmp($cleanArtist, 'unknown artist') === 0) {
        $cleanArtist = null;
    }

    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }

    $pdo = get_db_connection();
    $delSql = song_deleted_sql('', $pdo);
    $userScopeSql = $userId !== null ? " AND " . song_user_scope_sql($userId, '', $pdo) . " AND {$delSql}" : " AND {$delSql}";

    // 1. If artist is provided, match both Title and Artist
    if ($cleanArtist !== null) {
        $stmt = $pdo->prepare("
            SELECT `song_id`, `title`, `artist`, `is_consolidated`, `user_id`
            FROM `songs`
            WHERE `is_consolidated` = 0
              {$userScopeSql}
              AND LOWER(TRIM(`title`)) = LOWER(TRIM(:title))
              AND LOWER(TRIM(`artist`)) = LOWER(TRIM(:artist))
            ORDER BY (`user_id` IS NOT NULL) DESC, `song_id` ASC
            LIMIT 1
        ");
        $params = [
            ':title'  => $cleanTitle,
            ':artist' => $cleanArtist
        ];
        $stmt->execute($params);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($found) {
            return $found;
        }

        // Secondary check: Title might have version suffix in input or DB
        $baseTitle = trim(preg_replace('/\s*[\(\[\{](?:official|live|remix|acoustic|remastered?|deluxe|version|audio|video|hd|4k).*?[\)\]\}]/iu', '', $cleanTitle));
        if ($baseTitle !== '' && strcasecmp($baseTitle, $cleanTitle) !== 0) {
            $stmt = $pdo->prepare("
                SELECT `song_id`, `title`, `artist`, `is_consolidated`, `user_id`
                FROM `songs`
                WHERE `is_consolidated` = 0
                  {$userScopeSql}
                  AND LOWER(TRIM(`title`)) = LOWER(TRIM(:title))
                  AND LOWER(TRIM(`artist`)) = LOWER(TRIM(:artist))
                ORDER BY (`user_id` IS NOT NULL) DESC, `song_id` ASC
                LIMIT 1
            ");
            $params[':title'] = $baseTitle;
            $stmt->execute($params);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                return $found;
            }
        }
    } else {
        // 2. Artist was null/empty:
        // First check for active songs with matching title and NULL/empty artist
        $stmt = $pdo->prepare("
            SELECT `song_id`, `title`, `artist`, `is_consolidated`, `user_id`
            FROM `songs`
            WHERE `is_consolidated` = 0
              {$userScopeSql}
              AND LOWER(TRIM(`title`)) = LOWER(TRIM(:title))
              AND (`artist` IS NULL OR TRIM(`artist`) = '' OR LOWER(TRIM(`artist`)) = 'unknown artist')
            ORDER BY (`user_id` IS NOT NULL) DESC, `song_id` ASC
            LIMIT 1
        ");
        $params = [':title' => $cleanTitle];
        $stmt->execute($params);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($found) {
            return $found;
        }

        // Second check: If there is ONLY ONE song in the active catalogue with this title
        $stmt = $pdo->prepare("
            SELECT `song_id`, `title`, `artist`, `is_consolidated`, `user_id`
            FROM `songs`
            WHERE `is_consolidated` = 0
              {$userScopeSql}
              AND LOWER(TRIM(`title`)) = LOWER(TRIM(:title))
        ");
        $stmt->execute($params);
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
    string $discoveryStatus = 'unreviewed',
    $userIdOrThumbnail = null,
    ?int $userId = null
): array {
    $thumbnailUrl = null;
    if (is_int($userIdOrThumbnail)) {
        $userId = $userIdOrThumbnail;
    } elseif (is_string($userIdOrThumbnail) && $userIdOrThumbnail !== '') {
        $thumbnailUrl = $userIdOrThumbnail;
    }

    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    if ($userId === null) {
        return ['success' => false, 'error' => 'Authentication required to add songs to catalogue.'];
    }

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

    if ($discoveryStatus === 'shortlisted') {
        $stage1 = get_round_by_id(1, $userId);
        if ($stage1 && $stage1['status'] === 'completed') {
            return [
                'success' => false,
                'error'   => 'Formal Auditions has been finalized and locked. New songs cannot be added to the finalized shortlist.'
            ];
        }
    }

    // 1. Check if matching song already exists in user's active catalogue
    $pdo = get_db_connection();
    $existing = find_existing_catalogue_song($title, $artist, $userId);
    if ($existing === null && has_song_deleted_at_column($pdo)) {
        $cleanArtistVal = ($artist !== null && $artist !== '' && $artist !== '-') ? trim($artist) : null;
        if ($cleanArtistVal !== null) {
            $archivedStmt = $pdo->prepare("
                SELECT `song_id`, `title`, `artist`, `is_consolidated`, `user_id`
                FROM `songs`
                WHERE `user_id` = :uid
                  AND LOWER(TRIM(`title`)) = LOWER(TRIM(:title))
                  AND LOWER(TRIM(`artist`)) = LOWER(TRIM(:artist))
                  AND `deleted_at` IS NOT NULL
                ORDER BY `song_id` ASC
                LIMIT 1
            ");
            $archivedStmt->execute([':uid' => $userId, ':title' => trim($title), ':artist' => $cleanArtistVal]);
        } else {
            $archivedStmt = $pdo->prepare("
                SELECT `song_id`, `title`, `artist`, `is_consolidated`, `user_id`
                FROM `songs`
                WHERE `user_id` = :uid
                  AND LOWER(TRIM(`title`)) = LOWER(TRIM(:title))
                  AND (`artist` IS NULL OR TRIM(`artist`) = '' OR LOWER(TRIM(`artist`)) = 'unknown artist')
                  AND `deleted_at` IS NOT NULL
                ORDER BY `song_id` ASC
                LIMIT 1
            ");
            $archivedStmt->execute([':uid' => $userId, ':title' => trim($title)]);
        }
        $archived = $archivedStmt->fetch(PDO::FETCH_ASSOC);
        if ($archived) {
            $pdo->prepare("
                UPDATE `songs` 
                SET `deleted_at` = NULL, `is_consolidated` = 0 
                WHERE `song_id` = :id AND `user_id` = :uid
            ")->execute([':id' => $archived['song_id'], ':uid' => $userId]);
            $existing = $archived;
        }
    }
    if ($existing !== null) {
        $existingId = (int)$existing['song_id'];
        $savedMedia = null;

        // If a YouTube URL or Video ID was provided, save/update the recording link and artwork
        if (!empty($youtubeUrlOrId)) {
            $mediaRes = save_song_media($existingId, $youtubeUrlOrId, 'original', null, 'allowed', $existing['title'], $existing['artist'], null, $userId);
            if ($mediaRes['success']) {
                $savedMedia = $mediaRes['media'];
            }
        } else {
            $savedMedia = get_song_media($existingId);
        }

        if ($thumbnailUrl !== null) {
            $pdo = get_db_connection();
            $artStmt = $pdo->prepare("
                INSERT INTO `song_artwork` (`song_id`, `source`, `thumbnail_url`, `image_url`, `is_confirmed`, `status`)
                VALUES (:id, 'confirmed', :thumb, :img, 1, 'matched')
                ON DUPLICATE KEY UPDATE `thumbnail_url` = VALUES(`thumbnail_url`), `image_url` = VALUES(`image_url`)
            ");
            $artStmt->execute([
                ':id'    => $existingId,
                ':thumb' => $thumbnailUrl,
                ':img'   => $thumbnailUrl
            ]);
        }

        // If discovery status is provided ('shortlisted', 'passed', or 'unreviewed'), update discovery status
        if (in_array($discoveryStatus, ['shortlisted', 'passed', 'unreviewed'], true)) {
            update_song_discovery_status($existingId, $discoveryStatus, $userId);
        }

        // Clear any previous failed lyrics lookup cache so user can fetch fresh
        $savedLyrics = get_saved_song_lyrics($existingId);
        if ($savedLyrics && $savedLyrics['status'] === 'not_found') {
            clear_song_lyrics_ref($existingId, $userId);
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

        if (has_song_deleted_at_column($pdo)) {
            $stmt = $pdo->prepare("
                INSERT INTO `songs` (`title`, `artist`, `user_id`, `is_consolidated`, `deleted_at`)
                VALUES (:title, :artist, :user_id, 0, NULL)
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO `songs` (`title`, `artist`, `user_id`, `is_consolidated`)
                VALUES (:title, :artist, :user_id, 0)
            ");
        }
        $stmt->execute([
            ':title'   => $title,
            ':artist'  => $artist,
            ':user_id' => $userId
        ]);
        $songId = (int)$pdo->lastInsertId();

        // Optional YouTube media association
        $savedMedia = null;
        if (!empty($youtubeUrlOrId)) {
            $mediaRes = save_song_media($songId, $youtubeUrlOrId, 'original', null, 'allowed', $title, $artist, null, $userId);
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
                INSERT INTO `song_discovery` (`song_id`, `user_id`, `status`, `reviewed_at`)
                VALUES (:id, :uid, 'shortlisted', NOW())
            ");
            $discStmt->execute([':id' => $songId, ':uid' => $userId]);

            $entryStmt = $pdo->prepare("
                INSERT INTO `afm_entries` (`song_id`, `user_id`, `status`)
                VALUES (:id, :uid, 'auditioning')
            ");
            $entryStmt->execute([':id' => $songId, ':uid' => $userId]);
        } elseif ($discoveryStatus === 'passed') {
            $discStmt = $pdo->prepare("
                INSERT INTO `song_discovery` (`song_id`, `user_id`, `status`, `reviewed_at`)
                VALUES (:id, :uid, 'passed', NOW())
            ");
            $discStmt->execute([':id' => $songId, ':uid' => $userId]);
        }

        $pdo->commit();
        clear_dashboard_stats_cache();

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
    ?string $youtubeUrlOrId = null,
    ?int $userId = null
): array {
    if ($songId <= 0) {
        return ['success' => false, 'error' => 'Invalid song ID.'];
    }

    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    if ($userId === null) {
        return ['success' => false, 'error' => 'Authentication required.'];
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

    // Verify song exists and belongs to user
    $delSql = song_deleted_sql('', $pdo);
    $userSql = song_user_scope_sql($userId, '', $pdo);
    $check = $pdo->prepare("SELECT `song_id`, `title`, `artist`, `user_id` FROM `songs` WHERE `song_id` = :id AND {$userSql} AND {$delSql}");
    $check->execute([':id' => $songId]);
    $song = $check->fetch(PDO::FETCH_ASSOC);
    if (!$song) {
        return ['success' => false, 'error' => 'Unauthorized: Song not found in your catalogue.'];
    }

    try {
        $pdo->beginTransaction();

        $updateStmt = $pdo->prepare("
            UPDATE `songs`
            SET `title` = :title, `artist` = :artist
            WHERE `song_id` = :id AND `user_id` = :uid
        ");
        $updateStmt->execute([
            ':title'  => $title,
            ':artist' => $artist,
            ':id'     => $songId,
            ':uid'    => $userId
        ]);

        // Invalidate cached lyrics and negative lookup if title or artist was changed
        if ($song['title'] !== $title || $song['artist'] !== $artist) {
            clear_song_lyrics_ref($songId, $userId);
        }

        // If YouTube link/id provided, update or create media
        if (!empty($youtubeUrlOrId)) {
            $mediaRes = save_song_media($songId, $youtubeUrlOrId, 'original', null, 'allowed', $title, $artist, $youtubeUrlOrId, $userId);
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
        clear_dashboard_stats_cache();

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
 * If tournament history exists, deletion is blocked by safeguards to preserve
 * historical scorecards and competition results.
 * If no history exists, permanently deletes the song and its auxiliary records.
 */
function delete_catalogue_song(int $songId, ?int $userId = null): array {
    if ($songId <= 0) {
        return ['success' => false, 'error' => 'Invalid song ID.'];
    }

    $pdo = get_db_connection();
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    if ($userId === null && php_sapi_name() === 'cli') {
        $userId = get_legacy_user_id($pdo);
    }
    if ($userId === null) {
        return ['success' => false, 'error' => 'Authentication required.'];
    }

    $legacyId = get_legacy_user_id($pdo);
    $isOwner = is_legacy_owner($userId, $pdo);

    try {
        // Verify song exists and belongs to user
        $delSql = song_deleted_sql('', $pdo);
        $userSql = song_user_scope_sql($userId, '', $pdo);
        $selectCols = has_column('songs', 'user_id', $pdo) ? "`song_id`, `title`, `artist`, `user_id`" : "`song_id`, `title`, `artist`, NULL AS `user_id`";
        $check = $pdo->prepare("SELECT {$selectCols} FROM `songs` WHERE `song_id` = :id AND {$userSql} AND {$delSql}");
        $check->execute([':id' => $songId]);
        $song = $check->fetch(PDO::FETCH_ASSOC);
        if (!$song) {
            return ['success' => false, 'error' => 'Unauthorized: Song not found in your catalogue.'];
        }

        // SAFEGUARD 1: Check if song is currently on the Audition Shortlist
        $hasDiscoveryUser = has_column('song_discovery', 'user_id', $pdo);
        if ($hasDiscoveryUser) {
            $checkShortlist = $pdo->prepare("
                SELECT COUNT(*) 
                FROM `song_discovery` 
                WHERE `song_id` = :id 
                  AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = :leg_id))
                  AND `status` = 'shortlisted'
            ");
            $checkShortlist->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
        } else {
            $checkShortlist = $pdo->prepare("
                SELECT COUNT(*) 
                FROM `song_discovery` 
                WHERE `song_id` = :id 
                  AND `status` = 'shortlisted'
            ");
            $checkShortlist->execute([':id' => $songId]);
        }
        $isShortlisted = ((int)$checkShortlist->fetchColumn() > 0);
        if ($isShortlisted) {
            return [
                'success' => false,
                'blocked' => true,
                'reason'  => 'shortlisted',
                'error'   => "Cannot delete \"{$song['title']}\": This song is currently on your Audition Shortlist. Remove it from your shortlist first before deleting."
            ];
        }

        // SAFEGUARD 2: Check if song has an active audition score in an in-progress stage
        $hasUrp = has_table('user_round_progress', $pdo);
        $hasRatingsUser = has_column('ratings', 'user_id', $pdo);
        $hasEntriesUser = has_column('afm_entries', 'user_id', $pdo);

        if ($hasUrp && $hasRatingsUser && $hasEntriesUser) {
            $checkActiveScore = $pdo->prepare("
                SELECT COUNT(*) 
                FROM `ratings` r 
                JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` 
                JOIN `rounds` rnd ON r.`round_id` = rnd.`round_id`
                LEFT JOIN `user_round_progress` urp ON urp.`user_id` = :uid1 AND urp.`round_id` = rnd.`round_id`
                WHERE e.`song_id` = :id 
                  AND (r.`user_id` = :uid2 OR (r.`user_id` IS NULL AND :uid_g = :leg_id))
                  AND (urp.`status` = 'in_progress' OR (urp.`status` IS NULL AND rnd.`status` = 'in_progress'))
                  AND (r.`total` IS NOT NULL OR r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL)
            ");
            $checkActiveScore->execute([':id' => $songId, ':uid1' => $userId, ':uid2' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
        } else {
            $userRatingCond = $hasRatingsUser ? "AND (r.`user_id` = :uid2 OR (r.`user_id` IS NULL AND :uid_g = :leg_id))" : "";
            $checkActiveScore = $pdo->prepare("
                SELECT COUNT(*) 
                FROM `ratings` r 
                JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` 
                JOIN `rounds` rnd ON r.`round_id` = rnd.`round_id`
                WHERE e.`song_id` = :id 
                  {$userRatingCond}
                  AND rnd.`status` = 'in_progress'
                  AND (r.`total` IS NOT NULL OR r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL)
            ");
            $params = [':id' => $songId];
            if ($hasRatingsUser) {
                $params[':uid2'] = $userId;
                $params[':uid_g'] = $userId;
                $params[':leg_id'] = $legacyId;
            }
            $checkActiveScore->execute($params);
        }
        $hasActiveScore = ((int)$checkActiveScore->fetchColumn() > 0);
        if ($hasActiveScore) {
            return [
                'success' => false,
                'blocked' => true,
                'reason'  => 'scored',
                'error'   => "Cannot delete \"{$song['title']}\": This song has an active audition score in an ongoing tournament stage. Please reset or clear its score first before deleting."
            ];
        }

        // SAFEGUARD 3: Check if song is an active participant in an ongoing or upcoming tournament stage
        $hasResultsUser = has_column('round_results', 'user_id', $pdo);
        if ($hasUrp && $hasResultsUser && $hasEntriesUser) {
            $checkActiveParticipant = $pdo->prepare("
                SELECT COUNT(*) 
                FROM `round_results` rr 
                JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id` 
                JOIN `rounds` r ON rr.`round_id` = r.`round_id`
                LEFT JOIN `user_round_progress` urp ON urp.`user_id` = :uid1 AND urp.`round_id` = r.`round_id`
                LEFT JOIN `rounds` next_r ON next_r.`sequence_no` = r.`sequence_no` + 1
                LEFT JOIN `user_round_progress` next_urp ON next_urp.`user_id` = :uid2 AND next_urp.`round_id` = next_r.`round_id`
                WHERE e.`song_id` = :id 
                  AND (rr.`user_id` = :uid3 OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))
                  AND rr.`result` IN ('qualified', 'winner')
                  AND (
                      (urp.`status` = 'in_progress' OR (urp.`status` IS NULL AND r.`status` = 'in_progress'))
                      OR (next_r.`round_id` IS NOT NULL AND (next_urp.`status` IN ('in_progress', 'upcoming') OR (next_urp.`status` IS NULL AND next_r.`status` IN ('in_progress', 'upcoming'))))
                  )
            ");
            $checkActiveParticipant->execute([
                ':id'    => $songId,
                ':uid1'  => $userId,
                ':uid2'  => $userId,
                ':uid3'  => $userId,
                ':uid_g' => $userId,
                ':leg_id'=> $legacyId
            ]);
        } else {
            $userRrCond = $hasResultsUser ? "AND (rr.`user_id` = :uid3 OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))" : "";
            $checkActiveParticipant = $pdo->prepare("
                SELECT COUNT(*) 
                FROM `round_results` rr 
                JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id` 
                JOIN `rounds` r ON rr.`round_id` = r.`round_id`
                LEFT JOIN `rounds` next_r ON next_r.`sequence_no` = r.`sequence_no` + 1
                WHERE e.`song_id` = :id 
                  {$userRrCond}
                  AND rr.`result` IN ('qualified', 'winner')
                  AND (
                      r.`status` = 'in_progress'
                      OR (next_r.`round_id` IS NOT NULL AND next_r.`status` IN ('in_progress', 'upcoming'))
                  )
            ");
            $params = [':id' => $songId];
            if ($hasResultsUser) {
                $params[':uid3'] = $userId;
                $params[':uid_g'] = $userId;
                $params[':leg_id'] = $legacyId;
            }
            $checkActiveParticipant->execute($params);
        }
        $isActiveParticipant = ((int)$checkActiveParticipant->fetchColumn() > 0);
        if ($isActiveParticipant) {
            return [
                'success' => false,
                'blocked' => true,
                'reason'  => 'active_participant',
                'error'   => "Cannot delete \"{$song['title']}\": This song is an active participant in an ongoing or upcoming tournament stage. Eliminate the participant or reset the stage before deleting."
            ];
        }

        // SAFEGUARD 4: Finalized competition record immutability
        // Check if song participated in finalized Formal Auditions (has evaluation or tournament result)
        $stage1 = get_round_by_id(1, $userId);
        $isStage1Completed = ($stage1 && $stage1['status'] === 'completed');
        if ($isStage1Completed) {
            $userAudCond = $hasEntriesUser ? "AND (e.`user_id` = :uid OR (e.`user_id` IS NULL AND :uid_g = :leg_id))" : "";
            $checkAudition = $pdo->prepare("
                SELECT COUNT(*) 
                FROM `afm_entries` e
                LEFT JOIN `ratings` r ON e.`entry_id` = r.`entry_id` AND r.`round_id` = 1
                LEFT JOIN `round_results` rr ON e.`entry_id` = rr.`entry_id` AND rr.`round_id` = 1
                WHERE e.`song_id` = :id 
                  {$userAudCond}
                  AND (r.`total` IS NOT NULL OR rr.`result_id` IS NOT NULL)
            ");
            $params = [':id' => $songId];
            if ($hasEntriesUser) {
                $params[':uid'] = $userId;
                $params[':uid_g'] = $userId;
                $params[':leg_id'] = $legacyId;
            }
            $checkAudition->execute($params);
            if ((int)$checkAudition->fetchColumn() > 0) {
                return [
                    'success' => false,
                    'blocked' => true,
                    'reason'  => 'finalized_competition_record',
                    'error'   => "Cannot delete \"{$song['title']}\": This song participated in finalized Formal Auditions and is permanently preserved in the competition historical records."
                ];
            }
        }

        // Check if song participated in any finalized tournament stage
        if ($hasUrp && $hasResultsUser && $hasEntriesUser) {
            $checkFinalizedStage = $pdo->prepare("
                SELECT r.`round_name`
                FROM `round_results` rr
                JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id`
                JOIN `rounds` r ON rr.`round_id` = r.`round_id`
                LEFT JOIN `user_round_progress` urp ON urp.`user_id` = :uid1 AND urp.`round_id` = r.`round_id`
                WHERE e.`song_id` = :id
                  AND (rr.`user_id` = :uid2 OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))
                  AND (urp.`status` = 'completed' OR (urp.`status` IS NULL AND r.`status` = 'completed'))
                LIMIT 1
            ");
            $checkFinalizedStage->execute([':id' => $songId, ':uid1' => $userId, ':uid2' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
        } else {
            $userRrCond = $hasResultsUser ? "AND (rr.`user_id` = :uid2 OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))" : "";
            $checkFinalizedStage = $pdo->prepare("
                SELECT r.`round_name`
                FROM `round_results` rr
                JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id`
                JOIN `rounds` r ON rr.`round_id` = r.`round_id`
                WHERE e.`song_id` = :id
                  {$userRrCond}
                  AND r.`status` = 'completed'
                LIMIT 1
            ");
            $params = [':id' => $songId];
            if ($hasResultsUser) {
                $params[':uid2'] = $userId;
                $params[':uid_g'] = $userId;
                $params[':leg_id'] = $legacyId;
            }
            $checkFinalizedStage->execute($params);
        }
        $finalizedStageName = $checkFinalizedStage->fetchColumn();
        if ($finalizedStageName) {
            return [
                'success' => false,
                'blocked' => true,
                'reason'  => 'finalized_competition_record',
                'error'   => "Cannot delete \"{$song['title']}\": This song participated in finalized tournament stage '{$finalizedStageName}' and is permanently preserved in the competition historical records."
            ];
        }

        // Proceed to permanent deletion in transaction
        $pdo->beginTransaction();

        // 1. Remove auxiliary assets
        if (has_table('song_media', $pdo)) {
            $pdo->prepare("DELETE FROM `song_media` WHERE `song_id` = :id")->execute([':id' => $songId]);
        }
        if (has_table('song_lyrics_ref', $pdo)) {
            $pdo->prepare("DELETE FROM `song_lyrics_ref` WHERE `song_id` = :id")->execute([':id' => $songId]);
        }
        if (has_table('song_artwork', $pdo)) {
            $pdo->prepare("DELETE FROM `song_artwork` WHERE `song_id` = :id")->execute([':id' => $songId]);
        }
        if (has_table('song_youtube_candidates', $pdo)) {
            $pdo->prepare("DELETE FROM `song_youtube_candidates` WHERE `song_id` = :id")->execute([':id' => $songId]);
        }
        if (has_table('user_song_lyrics', $pdo)) {
            try {
                if ($hasEntriesUser) {
                    $pdo->prepare("DELETE FROM `user_song_lyrics` WHERE `song_id` = :id AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = :leg_id))")
                        ->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
                } else {
                    $pdo->prepare("DELETE FROM `user_song_lyrics` WHERE `song_id` = :id")->execute([':id' => $songId]);
                }
            } catch (\Throwable $e) {}
        }

        // 2. Remove discovery records
        if (has_table('song_discovery', $pdo)) {
            if ($hasDiscoveryUser) {
                $delDisc = $pdo->prepare("DELETE FROM `song_discovery` WHERE `song_id` = :id AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = :leg_id))");
                $delDisc->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
            } else {
                $delDisc = $pdo->prepare("DELETE FROM `song_discovery` WHERE `song_id` = :id");
                $delDisc->execute([':id' => $songId]);
            }
        }

        // 3. Remove non-competing afm_entries rows for this song
        if ($hasEntriesUser) {
            $delEntry = $pdo->prepare("DELETE FROM `afm_entries` WHERE `song_id` = :id AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = :leg_id))");
            $delEntry->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
        } else {
            $delEntry = $pdo->prepare("DELETE FROM `afm_entries` WHERE `song_id` = :id");
            $delEntry->execute([':id' => $songId]);
        }

        // 4. Remove song from songs table
        if (has_column('songs', 'user_id', $pdo)) {
            if ($isOwner) {
                $delSong = $pdo->prepare("DELETE FROM `songs` WHERE `song_id` = :id AND (`user_id` = :uid OR `user_id` IS NULL)");
            } else {
                $delSong = $pdo->prepare("DELETE FROM `songs` WHERE `song_id` = :id AND `user_id` = :uid");
            }
            $delSong->execute([':id' => $songId, ':uid' => $userId]);
        } else {
            $delSong = $pdo->prepare("DELETE FROM `songs` WHERE `song_id` = :id");
            $delSong->execute([':id' => $songId]);
        }

        $pdo->commit();
        clear_dashboard_stats_cache();

        return [
            'success'  => true,
            'song_id'  => $songId,
            'title'    => $song['title'],
            'archived' => false,
            'message'  => "Song #{$songId} (\"{$song['title']}\") was permanently deleted from your catalogue."
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("AFM2 delete_catalogue_song database exception: " . $e->getMessage());
        return [
            'success'    => false,
            'error_code' => 'database_error',
            'error'      => 'A database error occurred while deleting the song. The technical incident has been logged. Please try again or contact support.'
        ];
    }
}

/**
 * Permanently delete multiple catalogue songs with competition safeguards
 * 
 * Songs currently on shortlist, with active audition scores, or with completed
 * tournament results are blocked with descriptive explanations.
 * Only clean, unshortlisted, unscored songs are permanently deleted.
 *
 * @param array $songIds List of song IDs to delete
 * @param int|null $userId User ID to enforce ownership
 * @return array Result summary with deleted and blocked lists
 */
function delete_multiple_catalogue_songs(array $songIds, ?int $userId = null): array {
    $pdo = get_db_connection();
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    if ($userId === null && php_sapi_name() === 'cli') {
        $userId = get_legacy_user_id($pdo);
    }
    if ($userId === null) {
        return ['success' => false, 'error' => 'Authentication required.'];
    }

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

    $legacyId = get_legacy_user_id($pdo);
    $isOwner = is_legacy_owner($userId, $pdo);

    try {
        $inClause = implode(',', array_fill(0, count($cleanIds), '?'));
        $delSql = song_deleted_sql('', $pdo);
        $selectCols = has_column('songs', 'user_id', $pdo) ? "`song_id`, `title`, `artist`, `user_id`" : "`song_id`, `title`, `artist`, NULL AS `user_id`";
        $stmt = $pdo->prepare("SELECT {$selectCols} FROM `songs` WHERE `song_id` IN ($inClause) AND {$delSql}");
        $stmt->execute($cleanIds);
        $foundSongs = [];
        $blockedSongs = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rowUid = $row['user_id'] !== null ? (int)$row['user_id'] : null;
            $isOwned = ($rowUid === (int)$userId) || ($rowUid === null && $isOwner);
            if (!$isOwned) {
                $blockedSongs[] = [
                    'song_id' => (int)$row['song_id'],
                    'title'   => $row['title'],
                    'artist'  => $row['artist'],
                    'reason'  => 'Unauthorized: Song belongs to another user'
                ];
            } else {
                $foundSongs[(int)$row['song_id']] = $row;
            }
        }

        if (empty($foundSongs) && !empty($blockedSongs)) {
            return [
                'success'       => false,
                'blocked'       => true,
                'error'         => 'Unauthorized: None of the requested songs belong to your catalogue.',
                'deleted_count' => 0,
                'blocked_count' => count($blockedSongs),
                'deleted_ids'   => [],
                'blocked_songs' => $blockedSongs
            ];
        }

        if (empty($foundSongs)) {
            return [
                'success'       => false,
                'error'         => 'None of the requested songs exist in your active catalogue.',
                'deleted_count' => 0,
                'blocked_count' => 0,
                'deleted_ids'   => [],
                'blocked_songs' => []
            ];
        }

        $hasUrp = has_table('user_round_progress', $pdo);
        $hasDiscoveryUser = has_column('song_discovery', 'user_id', $pdo);
        $hasRatingsUser = has_column('ratings', 'user_id', $pdo);
        $hasEntriesUser = has_column('afm_entries', 'user_id', $pdo);
        $hasResultsUser = has_column('round_results', 'user_id', $pdo);

        $hardDeleteIds = [];
        $archivedIds = [];

        foreach ($foundSongs as $id => $song) {
            // SAFEGUARD 2: Active audition score in progress
            if ($hasUrp && $hasRatingsUser && $hasEntriesUser) {
                $chkScore = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM `ratings` r 
                    JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` 
                    JOIN `rounds` rnd ON r.`round_id` = rnd.`round_id`
                    LEFT JOIN `user_round_progress` urp ON urp.`user_id` = :uid1 AND urp.`round_id` = rnd.`round_id`
                    WHERE e.`song_id` = :id 
                      AND (r.`user_id` = :uid2 OR (r.`user_id` IS NULL AND :uid_g = :leg_id))
                      AND (urp.`status` = 'in_progress' OR (urp.`status` IS NULL AND rnd.`status` = 'in_progress'))
                      AND (r.`total` IS NOT NULL OR r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL)
                ");
                $chkScore->execute([':id' => $id, ':uid1' => $userId, ':uid2' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
            } else {
                $userCond = $hasRatingsUser ? "AND (r.`user_id` = :uid2 OR (r.`user_id` IS NULL AND :uid_g = :leg_id))" : "";
                $chkScore = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM `ratings` r 
                    JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` 
                    JOIN `rounds` rnd ON r.`round_id` = rnd.`round_id`
                    WHERE e.`song_id` = :id 
                      {$userCond}
                      AND rnd.`status` = 'in_progress'
                      AND (r.`total` IS NOT NULL OR r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL)
                ");
                $params = [':id' => $id];
                if ($hasRatingsUser) {
                    $params[':uid2'] = $userId;
                    $params[':uid_g'] = $userId;
                    $params[':leg_id'] = $legacyId;
                }
                $chkScore->execute($params);
            }
            if ((int)$chkScore->fetchColumn() > 0) {
                $blockedSongs[] = [
                    'song_id' => $id,
                    'title'   => $song['title'],
                    'artist'  => $song['artist'],
                    'reason'  => 'Has active audition score / tournament history. Reset score first.'
                ];
                continue;
            }

            // SAFEGUARD 3: Active participant in ongoing or upcoming tournament stage
            if ($hasUrp && $hasResultsUser && $hasEntriesUser) {
                $chkPart = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM `round_results` rr 
                    JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id` 
                    JOIN `rounds` r ON rr.`round_id` = r.`round_id`
                    LEFT JOIN `user_round_progress` urp ON urp.`user_id` = :uid1 AND urp.`round_id` = r.`round_id`
                    LEFT JOIN `rounds` next_r ON next_r.`sequence_no` = r.`sequence_no` + 1
                    LEFT JOIN `user_round_progress` next_urp ON next_urp.`user_id` = :uid2 AND next_urp.`round_id` = next_r.`round_id`
                    WHERE e.`song_id` = :id 
                      AND (rr.`user_id` = :uid3 OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))
                      AND rr.`result` IN ('qualified', 'winner')
                      AND (
                          (urp.`status` = 'in_progress' OR (urp.`status` IS NULL AND r.`status` = 'in_progress'))
                          OR (next_r.`round_id` IS NOT NULL AND (next_urp.`status` IN ('in_progress', 'upcoming') OR (next_urp.`status` IS NULL AND next_r.`status` IN ('in_progress', 'upcoming'))))
                      )
                ");
                $chkPart->execute([
                    ':id'    => $id,
                    ':uid1'  => $userId,
                    ':uid2'  => $userId,
                    ':uid3'  => $userId,
                    ':uid_g' => $userId,
                    ':leg_id'=> $legacyId
                ]);
            } else {
                $userRrCond = $hasResultsUser ? "AND (rr.`user_id` = :uid3 OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))" : "";
                $chkPart = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM `round_results` rr 
                    JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id` 
                    JOIN `rounds` r ON rr.`round_id` = r.`round_id`
                    LEFT JOIN `rounds` next_r ON next_r.`sequence_no` = r.`sequence_no` + 1
                    WHERE e.`song_id` = :id 
                      {$userRrCond}
                      AND rr.`result` IN ('qualified', 'winner')
                      AND (
                          r.`status` = 'in_progress'
                          OR (next_r.`round_id` IS NOT NULL AND next_r.`status` IN ('in_progress', 'upcoming'))
                      )
                ");
                $params = [':id' => $id];
                if ($hasResultsUser) {
                    $params[':uid3'] = $userId;
                    $params[':uid_g'] = $userId;
                    $params[':leg_id'] = $legacyId;
                }
                $chkPart->execute($params);
            }
            if ((int)$chkPart->fetchColumn() > 0) {
                $blockedSongs[] = [
                    'song_id' => $id,
                    'title'   => $song['title'],
                    'artist'  => $song['artist'],
                    'reason'  => 'Active participant in an ongoing or upcoming tournament stage.'
                ];
                continue;
            }

            // SAFEGUARD 4: Finalized competition record immutability
            // Check completed stage 1 (only blocks if song actually evaluated or decided in round 1)
            $stage1 = get_round_by_id(1, $userId);
            if ($stage1 && $stage1['status'] === 'completed') {
                $userAudCond = $hasEntriesUser ? "AND (e.`user_id` = :uid OR (e.`user_id` IS NULL AND :uid_g = :leg_id))" : "";
                $chkAud = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM `afm_entries` e
                    LEFT JOIN `ratings` r ON e.`entry_id` = r.`entry_id` AND r.`round_id` = 1
                    LEFT JOIN `round_results` rr ON e.`entry_id` = rr.`entry_id` AND rr.`round_id` = 1
                    WHERE e.`song_id` = :id 
                      {$userAudCond}
                      AND (r.`total` IS NOT NULL OR rr.`result_id` IS NOT NULL)
                ");
                $params = [':id' => $id];
                if ($hasEntriesUser) {
                    $params[':uid'] = $userId;
                    $params[':uid_g'] = $userId;
                    $params[':leg_id'] = $legacyId;
                }
                $chkAud->execute($params);
                if ((int)$chkAud->fetchColumn() > 0) {
                    $blockedSongs[] = [
                        'song_id' => $id,
                        'title'   => $song['title'],
                        'artist'  => $song['artist'],
                        'reason'  => 'Participated in finalized Formal Auditions / tournament history. Permanent deletion is prohibited.'
                    ];
                    continue;
                }
            }

            // Check completed stage in round_results
            if ($hasUrp && $hasResultsUser && $hasEntriesUser) {
                $chkFinal = $pdo->prepare("
                    SELECT r.`round_name`
                    FROM `round_results` rr
                    JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id`
                    JOIN `rounds` r ON rr.`round_id` = r.`round_id`
                    LEFT JOIN `user_round_progress` urp ON urp.`user_id` = :uid1 AND urp.`round_id` = r.`round_id`
                    WHERE e.`song_id` = :id
                      AND (rr.`user_id` = :uid2 OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))
                      AND (urp.`status` = 'completed' OR (urp.`status` IS NULL AND r.`status` = 'completed'))
                    LIMIT 1
                ");
                $chkFinal->execute([':id' => $id, ':uid1' => $userId, ':uid2' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
            } else {
                $userCond = $hasResultsUser ? "AND (rr.`user_id` = :uid2 OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))" : "";
                $chkFinal = $pdo->prepare("
                    SELECT r.`round_name`
                    FROM `round_results` rr
                    JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id`
                    JOIN `rounds` r ON rr.`round_id` = r.`round_id`
                    WHERE e.`song_id` = :id
                      {$userCond}
                      AND r.`status` = 'completed'
                    LIMIT 1
                ");
                $params = [':id' => $id];
                if ($hasResultsUser) {
                    $params[':uid2'] = $userId;
                    $params[':uid_g'] = $userId;
                    $params[':leg_id'] = $legacyId;
                }
                $chkFinal->execute($params);
            }
            $stageName = $chkFinal->fetchColumn();
            if ($stageName) {
                $blockedSongs[] = [
                    'song_id' => $id,
                    'title'   => $song['title'],
                    'artist'  => $song['artist'],
                    'reason'  => "Participated in finalized tournament stage '{$stageName}' / tournament history. Permanent deletion is prohibited."
                ];
                continue;
            }

            // Song is clean and eligible for permanent hard deletion
            $hardDeleteIds[] = $id;
        }

        if (empty($hardDeleteIds)) {
            return [
                'success'       => false,
                'blocked'       => true,
                'error'         => 'All selected songs were blocked from deletion due to active shortlist, audition scores, or finalized competition records.',
                'deleted_count' => 0,
                'archived_count'=> 0,
                'blocked_count' => count($blockedSongs),
                'deleted_ids'   => [],
                'archived_ids'  => [],
                'blocked_songs' => $blockedSongs
            ];
        }

        $pdo->beginTransaction();

        $allAffectedIds = $hardDeleteIds;
        $allInClause = implode(',', array_fill(0, count($allAffectedIds), '?'));
        $allParams = array_merge([$userId], $allAffectedIds);

        // Clean auxiliary tables
        if (has_table('song_media', $pdo)) {
            $pdo->prepare("DELETE FROM `song_media` WHERE `song_id` IN ($allInClause)")->execute($allAffectedIds);
        }
        if (has_table('song_lyrics_ref', $pdo)) {
            $pdo->prepare("DELETE FROM `song_lyrics_ref` WHERE `song_id` IN ($allInClause)")->execute($allAffectedIds);
        }
        if (has_table('song_artwork', $pdo)) {
            $pdo->prepare("DELETE FROM `song_artwork` WHERE `song_id` IN ($allInClause)")->execute($allAffectedIds);
        }
        if (has_table('song_youtube_candidates', $pdo)) {
            $pdo->prepare("DELETE FROM `song_youtube_candidates` WHERE `song_id` IN ($allInClause)")->execute($allAffectedIds);
        }
        if (has_table('user_song_lyrics', $pdo)) {
            try {
                if ($hasEntriesUser) {
                    if ($isOwner) {
                        $pdo->prepare("DELETE FROM `user_song_lyrics` WHERE (`user_id` = ? OR `user_id` IS NULL) AND `song_id` IN ($allInClause)")->execute($allParams);
                    } else {
                        $pdo->prepare("DELETE FROM `user_song_lyrics` WHERE `user_id` = ? AND `song_id` IN ($allInClause)")->execute($allParams);
                    }
                } else {
                    $pdo->prepare("DELETE FROM `user_song_lyrics` WHERE `song_id` IN ($allInClause)")->execute($allAffectedIds);
                }
            } catch (\Throwable $e) {}
        }
        if (has_table('song_discovery', $pdo)) {
            if ($hasDiscoveryUser) {
                if ($isOwner) {
                    $pdo->prepare("DELETE FROM `song_discovery` WHERE (`user_id` = ? OR `user_id` IS NULL) AND `song_id` IN ($allInClause)")->execute($allParams);
                } else {
                    $pdo->prepare("DELETE FROM `song_discovery` WHERE `user_id` = ? AND `song_id` IN ($allInClause)")->execute($allParams);
                }
            } else {
                $pdo->prepare("DELETE FROM `song_discovery` WHERE `song_id` IN ($allInClause)")->execute($allAffectedIds);
            }
        }

        // Delete from afm_entries
        if ($hasEntriesUser) {
            if ($isOwner) {
                $pdo->prepare("DELETE FROM `afm_entries` WHERE (`user_id` = ? OR `user_id` IS NULL) AND `song_id` IN ($allInClause)")->execute($allParams);
            } else {
                $pdo->prepare("DELETE FROM `afm_entries` WHERE `user_id` = ? AND `song_id` IN ($allInClause)")->execute($allParams);
            }
        } else {
            $pdo->prepare("DELETE FROM `afm_entries` WHERE `song_id` IN ($allInClause)")->execute($allAffectedIds);
        }

        // Delete from songs
        if (has_column('songs', 'user_id', $pdo)) {
            if ($isOwner) {
                $pdo->prepare("DELETE FROM `songs` WHERE (`user_id` = ? OR `user_id` IS NULL) AND `song_id` IN ($allInClause)")->execute($allParams);
            } else {
                $pdo->prepare("DELETE FROM `songs` WHERE `user_id` = ? AND `song_id` IN ($allInClause)")->execute($allParams);
            }
        } else {
            $pdo->prepare("DELETE FROM `songs` WHERE `song_id` IN ($allInClause)")->execute($allAffectedIds);
        }

        $pdo->commit();
        clear_dashboard_stats_cache();

        $deletedCount  = count($hardDeleteIds);
        $archivedCount = 0;
        $blockedCount  = count($blockedSongs);

        $msg = "{$deletedCount} song(s) permanently deleted.";
        if ($blockedCount > 0) {
            $msg .= " ({$blockedCount} song(s) were blocked due to active dependencies).";
        }

        return [
            'success'        => true,
            'deleted_count'  => $deletedCount,
            'archived_count' => $archivedCount,
            'blocked_count'  => $blockedCount,
            'deleted_ids'    => $hardDeleteIds,
            'archived_ids'   => [],
            'blocked_songs'  => $blockedSongs,
            'message'        => $msg
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("AFM2 delete_multiple_catalogue_songs database exception: " . $e->getMessage());
        return [
            'success'       => false,
            'error_code'    => 'database_error',
            'error'         => 'A database error occurred during bulk deletion. The technical incident has been logged. Please try again or contact support.',
            'deleted_count' => 0,
            'blocked_count' => 0,
            'deleted_ids'   => [],
            'blocked_songs' => []
        ];
    }
}

/**
 * Check eligibility of a batch of songs for bulk actions (shortlist, pass, delete).
 * Validates ownership, stage finalization / immutability, active audition scores,
 * active tournament participation, and duplicate active competition entries.
 *
 * @param string $operation 'shortlist' | 'pass' | 'delete'
 * @param array $songIds List of song IDs to evaluate
 * @param int|null $userId User ID to enforce ownership and scope
 * @return array Detailed eligibility breakdown with eligible IDs and reasons for ineligible songs
 */
function check_bulk_songs_eligibility(string $operation, array $songIds, ?int $userId = null): array {
    $pdo = get_db_connection();
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    if ($userId === null && php_sapi_name() === 'cli') {
        $userId = get_legacy_user_id($pdo);
    }
    if ($userId === null) {
        return ['success' => false, 'error' => 'Authentication required.'];
    }

    $operation = strtolower(trim($operation));
    if (!in_array($operation, ['shortlist', 'pass', 'delete'], true)) {
        return ['success' => false, 'error' => 'Invalid bulk action operation. Must be shortlist, pass, or delete.'];
    }

    $cleanIds = [];
    foreach ($songIds as $id) {
        $intId = (int)$id;
        if ($intId > 0 && !in_array($intId, $cleanIds, true)) {
            $cleanIds[] = $intId;
        }
    }

    if (empty($cleanIds)) {
        return [
            'success'          => false,
            'error'            => 'No valid song IDs provided for eligibility check.',
            'total_selected'   => 0,
            'eligible_count'   => 0,
            'ineligible_count' => 0,
            'eligible_ids'     => [],
            'eligible_songs'   => [],
            'ineligible_songs' => []
        ];
    }

    $legacyId = get_legacy_user_id($pdo);
    $isOwner = is_legacy_owner($userId, $pdo);

    // Schema inspection helpers
    $hasUrp = has_table('user_round_progress', $pdo);
    $hasDiscoveryUser = has_column('song_discovery', 'user_id', $pdo);
    $hasRatingsUser = has_column('ratings', 'user_id', $pdo);
    $hasEntriesUser = has_column('afm_entries', 'user_id', $pdo);
    $hasResultsUser = has_column('round_results', 'user_id', $pdo);

    // Formal Auditions (Stage 1) status check
    $stage1 = get_round_by_id(1, $userId);
    $isFormalAuditionsClosed = ($stage1 && $stage1['status'] === 'completed');

    // Fetch song records
    $inClause = implode(',', array_fill(0, count($cleanIds), '?'));
    $delSql = song_deleted_sql('', $pdo);
    $selectCols = has_column('songs', 'user_id', $pdo) 
        ? "`song_id`, `title`, `artist`, `user_id`, `is_consolidated`, `consolidated_into_id`" 
        : "`song_id`, `title`, `artist`, NULL AS `user_id`, `is_consolidated`, `consolidated_into_id`";
    
    $stmt = $pdo->prepare("SELECT {$selectCols} FROM `songs` WHERE `song_id` IN ($inClause) AND {$delSql}");
    $stmt->execute($cleanIds);

    $foundSongs = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $foundSongs[(int)$row['song_id']] = $row;
    }

    $eligibleIds = [];
    $eligibleSongs = [];
    $ineligibleSongs = [];

    foreach ($cleanIds as $id) {
        if (!isset($foundSongs[$id])) {
            $ineligibleSongs[] = [
                'song_id' => $id,
                'title'   => "Song #{$id}",
                'artist'  => 'Unknown',
                'reason'  => 'Song not found in your active catalogue.'
            ];
            continue;
        }

        $song = $foundSongs[$id];
        $rowUid = $song['user_id'] !== null ? (int)$song['user_id'] : null;
        $isOwned = ($rowUid === (int)$userId) || ($rowUid === null && $isOwner);

        if (!$isOwned) {
            $ineligibleSongs[] = [
                'song_id' => $id,
                'title'   => $song['title'],
                'artist'  => $song['artist'] ?? 'Unknown Artist',
                'reason'  => 'Unauthorized: Song belongs to another user.'
            ];
            continue;
        }

        // Operation-specific rules
        if ($operation === 'shortlist') {
            // Rule 1: Consolidated duplicate cannot be shortlisted
            if ((int)($song['is_consolidated'] ?? 0) === 1) {
                $ineligibleSongs[] = [
                    'song_id' => $id,
                    'title'   => $song['title'],
                    'artist'  => $song['artist'] ?? 'Unknown Artist',
                    'reason'  => 'Consolidated duplicate. Inspect canonical song #' . ($song['consolidated_into_id'] ?? '') . ' instead.'
                ];
                continue;
            }

            // Rule 2: Formal Auditions finalized and locked
            if ($isFormalAuditionsClosed) {
                $ineligibleSongs[] = [
                    'song_id' => $id,
                    'title'   => $song['title'],
                    'artist'  => $song['artist'] ?? 'Unknown Artist',
                    'reason'  => 'Formal Auditions has been finalized and locked. New songs cannot be added to the finalized shortlist.'
                ];
                continue;
            }

            // Rule 3: Duplicate active audition entry check
            $cleanTitle = trim($song['title']);
            $cleanArtist = trim($song['artist'] ?? '');
            $dupEntryStmt = $pdo->prepare("
                SELECT e.`entry_id`, e.`song_id`, s.`title`
                FROM `afm_entries` e
                JOIN `songs` s ON e.`song_id` = s.`song_id`
                WHERE (e.`user_id` = :uid OR (e.`user_id` IS NULL AND :uid_g = :leg_id))
                  AND e.`song_id` != :sid
                  AND LOWER(TRIM(s.`title`)) = LOWER(TRIM(:title))
                  AND LOWER(TRIM(COALESCE(s.`artist`, ''))) = LOWER(TRIM(:artist))
                  AND e.`status` = 'auditioning'
                LIMIT 1
            ");
            $dupEntryStmt->execute([
                ':uid'    => $userId,
                ':uid_g'  => $userId,
                ':leg_id' => $legacyId,
                ':sid'    => $id,
                ':title'  => $cleanTitle,
                ':artist' => $cleanArtist
            ]);
            $dupEntry = $dupEntryStmt->fetch(PDO::FETCH_ASSOC);
            if ($dupEntry) {
                $ineligibleSongs[] = [
                    'song_id' => $id,
                    'title'   => $song['title'],
                    'artist'  => $song['artist'] ?? 'Unknown Artist',
                    'reason'  => "Duplicate entry: An active audition entry already exists for \"{$song['title']}\" (Track #{$dupEntry['song_id']}) in your competition."
                ];
                continue;
            }

            // Song is eligible to be shortlisted
            $eligibleIds[] = $id;
            $eligibleSongs[] = [
                'song_id' => $id,
                'title'   => $song['title'],
                'artist'  => $song['artist'] ?? 'Unknown Artist'
            ];

        } elseif ($operation === 'pass') {
            // Rule 1: Formal Auditions closed & currently on historical shortlist
            if ($isFormalAuditionsClosed) {
                $curStatusStmt = $pdo->prepare("
                    SELECT `status` FROM `song_discovery` 
                    WHERE `song_id` = :id AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = :leg_id))
                ");
                $curStatusStmt->execute([':id' => $id, ':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
                if ($curStatusStmt->fetchColumn() === 'shortlisted') {
                    $ineligibleSongs[] = [
                        'song_id' => $id,
                        'title'   => $song['title'],
                        'artist'  => $song['artist'] ?? 'Unknown Artist',
                        'reason'  => 'Formal Auditions has been finalized and locked. Songs cannot be removed from the finalized historical shortlist.'
                    ];
                    continue;
                }
            }

            // Rule 2: Active audition score in ratings
            $scoreCheck = $pdo->prepare("
                SELECT COUNT(*) 
                FROM `ratings` r 
                JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` 
                WHERE e.`song_id` = :id 
                  AND (r.`user_id` = :uid OR (r.`user_id` IS NULL AND :uid_g = :leg_id))
                  AND (r.`total` IS NOT NULL OR r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL)
            ");
            $scoreCheck->execute([':id' => $id, ':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
            if ((int)$scoreCheck->fetchColumn() > 0) {
                $ineligibleSongs[] = [
                    'song_id' => $id,
                    'title'   => $song['title'],
                    'artist'  => $song['artist'] ?? 'Unknown Artist',
                    'reason'  => "Cannot pass: \"{$song['title']}\" currently has an active audition score. Please reset its score first."
                ];
                continue;
            }

            // Rule 3: Referenced in completed tournament history
            $rrCheck = $pdo->prepare("
                SELECT COUNT(*) 
                FROM `round_results` rr 
                JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id` 
                WHERE e.`song_id` = :id 
                  AND (rr.`user_id` = :uid OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))
            ");
            $rrCheck->execute([':id' => $id, ':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
            if ((int)$rrCheck->fetchColumn() > 0) {
                $ineligibleSongs[] = [
                    'song_id' => $id,
                    'title'   => $song['title'],
                    'artist'  => $song['artist'] ?? 'Unknown Artist',
                    'reason'  => "Cannot pass: \"{$song['title']}\" is referenced in your completed tournament history."
                ];
                continue;
            }

            // Song is eligible to be passed
            $eligibleIds[] = $id;
            $eligibleSongs[] = [
                'song_id' => $id,
                'title'   => $song['title'],
                'artist'  => $song['artist'] ?? 'Unknown Artist'
            ];

        } elseif ($operation === 'delete') {
            // Rule 1: Currently on Audition Shortlist
            if ($hasDiscoveryUser) {
                $chkShort = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM `song_discovery` 
                    WHERE `song_id` = :id 
                      AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = :leg_id))
                      AND `status` = 'shortlisted'
                ");
                $chkShort->execute([':id' => $id, ':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
            } else {
                $chkShort = $pdo->prepare("SELECT COUNT(*) FROM `song_discovery` WHERE `song_id` = :id AND `status` = 'shortlisted'");
                $chkShort->execute([':id' => $id]);
            }
            if ((int)$chkShort->fetchColumn() > 0) {
                $ineligibleSongs[] = [
                    'song_id' => $id,
                    'title'   => $song['title'],
                    'artist'  => $song['artist'] ?? 'Unknown Artist',
                    'reason'  => "Currently on your Audition Shortlist. Remove it from your shortlist first before deleting."
                ];
                continue;
            }

            // Rule 2: Active audition score in in-progress stage
            if ($hasUrp && $hasRatingsUser && $hasEntriesUser) {
                $chkScore = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM `ratings` r 
                    JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` 
                    JOIN `rounds` rnd ON r.`round_id` = rnd.`round_id`
                    LEFT JOIN `user_round_progress` urp ON urp.`user_id` = :uid1 AND urp.`round_id` = rnd.`round_id`
                    WHERE e.`song_id` = :id 
                      AND (r.`user_id` = :uid2 OR (r.`user_id` IS NULL AND :uid_g = :leg_id))
                      AND (urp.`status` = 'in_progress' OR (urp.`status` IS NULL AND rnd.`status` = 'in_progress'))
                      AND (r.`total` IS NOT NULL OR r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL)
                ");
                $chkScore->execute([':id' => $id, ':uid1' => $userId, ':uid2' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
            } else {
                $userCond = $hasRatingsUser ? "AND (r.`user_id` = :uid2 OR (r.`user_id` IS NULL AND :uid_g = :leg_id))" : "";
                $chkScore = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM `ratings` r 
                    JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` 
                    JOIN `rounds` rnd ON r.`round_id` = rnd.`round_id`
                    WHERE e.`song_id` = :id 
                      {$userCond}
                      AND rnd.`status` = 'in_progress'
                      AND (r.`total` IS NOT NULL OR r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL)
                ");
                $params = [':id' => $id];
                if ($hasRatingsUser) {
                    $params[':uid2'] = $userId;
                    $params[':uid_g'] = $userId;
                    $params[':leg_id'] = $legacyId;
                }
                $chkScore->execute($params);
            }
            if ((int)$chkScore->fetchColumn() > 0) {
                $ineligibleSongs[] = [
                    'song_id' => $id,
                    'title'   => $song['title'],
                    'artist'  => $song['artist'] ?? 'Unknown Artist',
                    'reason'  => "Has an active audition score in an ongoing tournament stage. Please reset or clear its score first."
                ];
                continue;
            }

            // Rule 3: Active participant in ongoing or upcoming tournament stage
            if ($hasUrp && $hasResultsUser && $hasEntriesUser) {
                $chkPart = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM `round_results` rr 
                    JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id` 
                    JOIN `rounds` r ON rr.`round_id` = r.`round_id`
                    LEFT JOIN `user_round_progress` urp ON urp.`user_id` = :uid1 AND urp.`round_id` = r.`round_id`
                    LEFT JOIN `rounds` next_r ON next_r.`sequence_no` = r.`sequence_no` + 1
                    LEFT JOIN `user_round_progress` next_urp ON next_urp.`user_id` = :uid2 AND next_urp.`round_id` = next_r.`round_id`
                    WHERE e.`song_id` = :id 
                      AND (rr.`user_id` = :uid3 OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))
                      AND rr.`result` IN ('qualified', 'winner')
                      AND (
                          (urp.`status` = 'in_progress' OR (urp.`status` IS NULL AND r.`status` = 'in_progress'))
                          OR (next_r.`round_id` IS NOT NULL AND (next_urp.`status` IN ('in_progress', 'upcoming') OR (next_urp.`status` IS NULL AND next_r.`status` IN ('in_progress', 'upcoming'))))
                      )
                ");
                $chkPart->execute([
                    ':id'    => $id,
                    ':uid1'  => $userId,
                    ':uid2'  => $userId,
                    ':uid3'  => $userId,
                    ':uid_g' => $userId,
                    ':leg_id'=> $legacyId
                ]);
            } else {
                $userRrCond = $hasResultsUser ? "AND (rr.`user_id` = :uid3 OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))" : "";
                $chkPart = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM `round_results` rr 
                    JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id` 
                    JOIN `rounds` r ON rr.`round_id` = r.`round_id`
                    LEFT JOIN `rounds` next_r ON next_r.`sequence_no` = r.`sequence_no` + 1
                    WHERE e.`song_id` = :id 
                      {$userRrCond}
                      AND rr.`result` IN ('qualified', 'winner')
                      AND (
                          r.`status` = 'in_progress'
                          OR (next_r.`round_id` IS NOT NULL AND next_r.`status` IN ('in_progress', 'upcoming'))
                      )
                ");
                $params = [':id' => $id];
                if ($hasResultsUser) {
                    $params[':uid3'] = $userId;
                    $params[':uid_g'] = $userId;
                    $params[':leg_id'] = $legacyId;
                }
                $chkPart->execute($params);
            }
            if ((int)$chkPart->fetchColumn() > 0) {
                $ineligibleSongs[] = [
                    'song_id' => $id,
                    'title'   => $song['title'],
                    'artist'  => $song['artist'] ?? 'Unknown Artist',
                    'reason'  => "Active participant in an ongoing or upcoming tournament stage."
                ];
                continue;
            }

            // Rule 4: Participated in finalized Formal Auditions / tournament stage
            if ($isFormalAuditionsClosed) {
                $userAudCond = $hasEntriesUser ? "AND (e.`user_id` = :uid OR (e.`user_id` IS NULL AND :uid_g = :leg_id))" : "";
                $chkAud = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM `afm_entries` e
                    LEFT JOIN `ratings` r ON e.`entry_id` = r.`entry_id` AND r.`round_id` = 1
                    LEFT JOIN `round_results` rr ON e.`entry_id` = rr.`entry_id` AND rr.`round_id` = 1
                    WHERE e.`song_id` = :id 
                      {$userAudCond}
                      AND (r.`total` IS NOT NULL OR rr.`result_id` IS NOT NULL)
                ");
                $params = [':id' => $id];
                if ($hasEntriesUser) {
                    $params[':uid'] = $userId;
                    $params[':uid_g'] = $userId;
                    $params[':leg_id'] = $legacyId;
                }
                $chkAud->execute($params);
                if ((int)$chkAud->fetchColumn() > 0) {
                    $ineligibleSongs[] = [
                        'song_id' => $id,
                        'title'   => $song['title'],
                        'artist'  => $song['artist'] ?? 'Unknown Artist',
                        'reason'  => "Participated in finalized Formal Auditions / tournament history. Permanent deletion is prohibited."
                    ];
                    continue;
                }
            }

            if ($hasUrp && $hasResultsUser && $hasEntriesUser) {
                $chkFinal = $pdo->prepare("
                    SELECT r.`round_name`
                    FROM `round_results` rr
                    JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id`
                    JOIN `rounds` r ON rr.`round_id` = r.`round_id`
                    LEFT JOIN `user_round_progress` urp ON urp.`user_id` = :uid1 AND urp.`round_id` = r.`round_id`
                    WHERE e.`song_id` = :id
                      AND (rr.`user_id` = :uid2 OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))
                      AND (urp.`status` = 'completed' OR (urp.`status` IS NULL AND r.`status` = 'completed'))
                    LIMIT 1
                ");
                $chkFinal->execute([':id' => $id, ':uid1' => $userId, ':uid2' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
            } else {
                $userCond = $hasResultsUser ? "AND (rr.`user_id` = :uid2 OR (rr.`user_id` IS NULL AND :uid_g = :leg_id))" : "";
                $chkFinal = $pdo->prepare("
                    SELECT r.`round_name`
                    FROM `round_results` rr
                    JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id`
                    JOIN `rounds` r ON rr.`round_id` = r.`round_id`
                    WHERE e.`song_id` = :id
                      {$userCond}
                      AND r.`status` = 'completed'
                    LIMIT 1
                ");
                $params = [':id' => $id];
                if ($hasResultsUser) {
                    $params[':uid2'] = $userId;
                    $params[':uid_g'] = $userId;
                    $params[':leg_id'] = $legacyId;
                }
                $chkFinal->execute($params);
            }
            $stageName = $chkFinal->fetchColumn();
            if ($stageName) {
                $ineligibleSongs[] = [
                    'song_id' => $id,
                    'title'   => $song['title'],
                    'artist'  => $song['artist'] ?? 'Unknown Artist',
                    'reason'  => "Participated in finalized tournament stage '{$stageName}' / tournament history. Permanent deletion is prohibited."
                ];
                continue;
            }

            // Song is eligible to be deleted
            $eligibleIds[] = $id;
            $eligibleSongs[] = [
                'song_id' => $id,
                'title'   => $song['title'],
                'artist'  => $song['artist'] ?? 'Unknown Artist'
            ];
        }
    }

    return [
        'success'          => true,
        'operation'        => $operation,
        'total_selected'   => count($cleanIds),
        'eligible_count'   => count($eligibleIds),
        'ineligible_count' => count($ineligibleSongs),
        'eligible_ids'     => $eligibleIds,
        'eligible_songs'   => $eligibleSongs,
        'ineligible_songs' => $ineligibleSongs
    ];
}

/**
 * Authoritative bulk update of song discovery statuses (shortlist or pass).
 * Strictly updates only tracks that pass all eligibility rules.
 * Does NOT perform speculative updates on ineligible tracks.
 *
 * @param string $status 'shortlisted' | 'passed'
 * @param array $songIds List of song IDs to update
 * @param int|null $userId User ID to enforce ownership and scope
 * @return array Result summary with confirmed updated IDs, skipped songs, and counts
 */
function bulk_update_song_discovery(string $status, array $songIds, ?int $userId = null): array {
    $pdo = get_db_connection();
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    if ($userId === null && php_sapi_name() === 'cli') {
        $userId = get_legacy_user_id($pdo);
    }
    if ($userId === null) {
        return ['success' => false, 'error' => 'Authentication required.'];
    }

    $cleanStatus = ($status === 'shortlist' || $status === 'shortlisted') ? 'shortlisted' : (($status === 'pass' || $status === 'passed') ? 'passed' : '');
    if ($cleanStatus === '') {
        return ['success' => false, 'error' => 'Invalid discovery status. Must be shortlisted or passed.'];
    }

    $opName = ($cleanStatus === 'shortlisted') ? 'shortlist' : 'pass';
    $eligibility = check_bulk_songs_eligibility($opName, $songIds, $userId);
    if (!$eligibility['success']) {
        return $eligibility;
    }

    $eligibleIds = $eligibility['eligible_ids'];
    $ineligibleSongs = $eligibility['ineligible_songs'];
    $totalSelected = $eligibility['total_selected'];
    $pastTense = ($cleanStatus === 'shortlisted') ? 'shortlisted' : 'passed';

    if (empty($eligibleIds)) {
        return [
            'success'          => false,
            'blocked'          => true,
            'operation'        => $cleanStatus,
            'total_selected'   => $totalSelected,
            'updated_count'    => 0,
            'skipped_count'    => count($ineligibleSongs),
            'updated_ids'      => [],
            'skipped_songs'    => $ineligibleSongs,
            'error'            => "None of the selected tracks could be {$pastTense} due to competition restrictions.",
            'message'          => "0 tracks {$pastTense}. " . count($ineligibleSongs) . " track(s) skipped due to competition restrictions."
        ];
    }

    try {
        $pdo->beginTransaction();

        $legacyId = get_legacy_user_id($pdo);

        if ($cleanStatus === 'shortlisted') {
            $insDisc = $pdo->prepare("
                INSERT INTO `song_discovery` (`song_id`, `user_id`, `status`, `reviewed_at`)
                VALUES (:id, :uid, 'shortlisted', NOW())
                ON DUPLICATE KEY UPDATE `status` = 'shortlisted', `reviewed_at` = NOW()
            ");
            $insEntry = $pdo->prepare("
                INSERT INTO `afm_entries` (`song_id`, `user_id`, `status`)
                VALUES (:id, :uid, 'auditioning')
                ON DUPLICATE KEY UPDATE `status` = 'auditioning'
            ");

            foreach ($eligibleIds as $sId) {
                $insDisc->execute([':id' => $sId, ':uid' => $userId]);
                $insEntry->execute([':id' => $sId, ':uid' => $userId]);
            }
        } else {
            // cleanStatus === 'passed'
            $insDisc = $pdo->prepare("
                INSERT INTO `song_discovery` (`song_id`, `user_id`, `status`, `reviewed_at`)
                VALUES (:id, :uid, 'passed', NOW())
                ON DUPLICATE KEY UPDATE `status` = 'passed', `reviewed_at` = NOW()
            ");
            $delEntry = $pdo->prepare("
                DELETE FROM `afm_entries` 
                WHERE `song_id` = :id 
                  AND `user_id` = :uid 
                  AND `entry_id` NOT IN (SELECT `entry_id` FROM `ratings` WHERE `user_id` = :uid2)
            ");

            foreach ($eligibleIds as $sId) {
                $insDisc->execute([':id' => $sId, ':uid' => $userId]);
                $delEntry->execute([':id' => $sId, ':uid' => $userId, ':uid2' => $userId]);
            }
        }

        $pdo->commit();
        clear_dashboard_stats_cache();

        // Calculate updated counters for UI header/dashboard
        $delSql = song_deleted_sql('', $pdo);
        $delSqlS = song_deleted_sql('s', $pdo);
        $userSql = song_user_scope_sql($userId, '', $pdo);
        $userSqlS = song_user_scope_sql($userId, 's', $pdo);

        $stmtCounts = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN sd.`status` = 'shortlisted' THEN 1 ELSE 0 END) AS shortlisted,
                SUM(CASE WHEN sd.`status` = 'passed' THEN 1 ELSE 0 END) AS passed
            FROM `song_discovery` sd
            JOIN `songs` s ON sd.`song_id` = s.`song_id` AND s.`is_consolidated` = 0 AND {$delSqlS}
            WHERE (sd.`user_id` = :uid OR (sd.`user_id` IS NULL AND :uid_g = :leg_id))
              AND {$userSqlS}
        ");
        $stmtCounts->execute([':uid' => $userId, ':uid_g' => $userId, ':leg_id' => $legacyId]);
        $counts = $stmtCounts->fetch(PDO::FETCH_ASSOC);

        $totStmt = $pdo->prepare("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0 AND {$delSql} AND {$userSql}");
        $totStmt->execute();
        $totalSongs = (int)$totStmt->fetchColumn();

        $shortlisted = (int)($counts['shortlisted'] ?? 0);
        $passed = (int)($counts['passed'] ?? 0);
        $unreviewed = max(0, $totalSongs - ($shortlisted + $passed));

        $updatedCount = count($eligibleIds);
        $skippedCount = count($ineligibleSongs);

        $msg = "{$updatedCount} track(s) successfully {$pastTense}.";
        if ($skippedCount > 0) {
            $msg .= " {$skippedCount} track(s) skipped due to competition restrictions.";
        }

        return [
            'success'           => true,
            'operation'         => $cleanStatus,
            'total_selected'    => $totalSelected,
            'updated_count'     => $updatedCount,
            'skipped_count'     => $skippedCount,
            'updated_ids'       => $eligibleIds,
            'skipped_songs'     => $ineligibleSongs,
            'message'           => $msg,
            'shortlisted_count' => $shortlisted,
            'passed_count'      => $passed,
            'unreviewed_count'  => $unreviewed
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("AFM2 bulk_update_song_discovery database exception: " . $e->getMessage());
        return [
            'success'          => false,
            'error_code'       => 'database_error',
            'error'            => 'A database error occurred during bulk status update. The technical incident has been logged. Please try again.',
            'total_selected'   => $totalSelected,
            'updated_count'    => 0,
            'skipped_count'    => count($ineligibleSongs),
            'updated_ids'      => [],
            'skipped_songs'    => $ineligibleSongs
        ];
    }
}

/**
 * Get all shortlisted songs with audition status
 */
function get_shortlisted_songs(int $page = 1, int $perPage = 50, string $query = '', string $statusFilter = 'all', ?int $userId = null): array {
    $pdo = get_db_connection();
    $page = max(1, $page);
    $perPage = max(10, min(200, $perPage));
    $offset = ($page - 1) * $perPage;

    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    if ($userId === null) {
        return [
            'songs'       => [],
            'total'       => 0,
            'page'        => 1,
            'per_page'    => $perPage,
            'total_pages' => 1
        ];
    }

    $delSqlS = song_deleted_sql('s', $pdo);
    $userSqlS = song_user_scope_sql($userId, 's', $pdo);
    $userSqlSd = ($userId === 995) ? "(sd.`user_id` = 995 OR sd.`user_id` IS NULL)" : "sd.`user_id` = " . (int)$userId;
    $whereClauses = [
        "sd.`status` = 'shortlisted'",
        $userSqlSd,
        $userSqlS,
        $delSqlS,
        "s.`is_consolidated` = 0"
    ];
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

    $entryJoinSql = ($userId === 995)
        ? "LEFT JOIN `afm_entries` e ON s.`song_id` = e.`song_id` AND (e.`user_id` = 995 OR e.`user_id` IS NULL)"
        : "LEFT JOIN `afm_entries` e ON s.`song_id` = e.`song_id` AND e.`user_id` = :uid_entry";
    $ratingJoinSql = ($userId === 995)
        ? "LEFT JOIN `ratings` r1 ON e.`entry_id` = r1.`entry_id` AND r1.`round_id` = 1 AND (r1.`user_id` = 995 OR r1.`user_id` IS NULL)"
        : "LEFT JOIN `ratings` r1 ON e.`entry_id` = r1.`entry_id` AND r1.`round_id` = 1 AND r1.`user_id` = :uid_rating";

    $params = [];
    if ($userId !== 995) {
        $params[':uid_entry'] = $userId;
        $params[':uid_rating'] = $userId;
    }

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM `song_discovery` sd
        JOIN `songs` s ON sd.`song_id` = s.`song_id`
        {$entryJoinSql}
        {$ratingJoinSql}
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
        {$entryJoinSql}
        {$ratingJoinSql}
        LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
        LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
        {$whereSql}
        ORDER BY sd.`reviewed_at` DESC, s.`song_id` ASC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    $replayability,
    ?int $userId = null
): array {
    $pdo = get_db_connection();

    // Verify entry exists and check ownership
    $entryStmt = $pdo->prepare("
        SELECT e.`entry_id`, e.`song_id`, e.`user_id` AS `entry_user_id`, s.`user_id` AS `song_user_id`
        FROM `afm_entries` e
        JOIN `songs` s ON e.`song_id` = s.`song_id`
        WHERE e.`entry_id` = :id
    ");
    $entryStmt->execute([':id' => $entryId]);
    $entry = $entryStmt->fetch();
    if (!$entry) {
        return ['success' => false, 'error' => 'Entry not found.'];
    }
    if ($userId !== null) {
        $entryOwner = $entry['entry_user_id'] !== null ? (int)$entry['entry_user_id'] : 995;
        $songOwner = $entry['song_user_id'] !== null ? (int)$entry['song_user_id'] : 995;
        if ($entryOwner !== (int)$userId) {
            return ['success' => false, 'error' => 'Unauthorized: Cannot score another user\'s entry.'];
        }
        if ($songOwner !== (int)$userId) {
            return ['success' => false, 'error' => 'Unauthorized: Cannot score another user\'s song.'];
        }
    }

    // Verify round exists and check status/eligibility
    $effectiveUid = $userId ?? (is_authenticated() ? get_current_user_id() : 995);
    $roundStmt = $pdo->prepare("
        SELECT r.`round_id`, r.`round_name`, r.`sequence_no`, CASE WHEN urp.`status` IS NOT NULL THEN urp.`status` ELSE r.`status` END AS `status`
        FROM `rounds` r
        LEFT JOIN `user_round_progress` urp ON r.`round_id` = urp.`round_id` AND urp.`user_id` = :uid
        WHERE r.`round_id` = :id
    ");
    $roundStmt->execute([':id' => $roundId, ':uid' => $effectiveUid]);
    $roundInfo = $roundStmt->fetch(PDO::FETCH_ASSOC);
    if (!$roundInfo) {
        return ['success' => false, 'error' => 'Tournament round not found.'];
    }

    // SAFEGUARD 1: Stage Immutability Protection
    if ($roundInfo['status'] === 'completed') {
        return [
            'success' => false,
            'blocked' => true,
            'error'   => "Stage '{$roundInfo['round_name']}' is finalized and immutable. Completed evaluations cannot be modified."
        ];
    }

    // SAFEGUARD 1B: Entry Finalization in Tournament History
    $historyCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM `round_results` 
        WHERE `entry_id` = :eid 
          AND `round_id` = :rid 
          AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = 995))
    ");
    $historyCheck->execute([
        ':eid'   => $entryId, 
        ':rid'   => $roundId, 
        ':uid'   => $effectiveUid, 
        ':uid_g' => $effectiveUid
    ]);
    if ((int)$historyCheck->fetchColumn() > 0) {
        return [
            'success' => false,
            'blocked' => true,
            'error'   => "This scorecard is finalized and immutable. It has been recorded in completed tournament competition history and cannot be modified."
        ];
    }

    // SAFEGUARD 2: Participant Eligibility Protection
    if ((int)$roundInfo['sequence_no'] === 1) {
        $sdCheckStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM `song_discovery`
            WHERE `song_id` = :sid
              AND `status` = 'shortlisted'
              AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = 995))
        ");
        $sdCheckStmt->execute([
            ':sid'   => $entry['song_id'],
            ':uid'   => $effectiveUid,
            ':uid_g' => $effectiveUid,
        ]);
        if ((int)$sdCheckStmt->fetchColumn() === 0) {
            return [
                'success' => false,
                'blocked' => true,
                'error'   => "Song is not shortlisted in Formal Auditions. Only shortlisted songs can receive evaluations in Formal Auditions."
            ];
        }
    } else {
        $prevSeq = (int)$roundInfo['sequence_no'] - 1;
        $prevRoundStmt = $pdo->prepare("SELECT `round_id` FROM `rounds` WHERE `sequence_no` = :prev_seq");
        $prevRoundStmt->execute([':prev_seq' => $prevSeq]);
        $prevRoundId = (int)$prevRoundStmt->fetchColumn();

        $qualCheckStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM `round_results` 
            WHERE `round_id` = :prev_rid 
              AND `entry_id` = :eid 
              AND `result` IN ('qualified', 'winner')
              AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = 995))
        ");
        $qualCheckStmt->execute([
            ':prev_rid' => $prevRoundId,
            ':eid'      => $entryId,
            ':uid'      => $effectiveUid,
            ':uid_g'    => $effectiveUid,
        ]);
        if ((int)$qualCheckStmt->fetchColumn() === 0) {
            return [
                'success' => false,
                'blocked' => true,
                'error'   => "Entry is not an officially advanced participant in '{$roundInfo['round_name']}'. Only participants qualified from the preceding stage can receive evaluations."
            ];
        }
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
                `user_id`,
                `melody`, 
                `lyrics`, 
                `vocals`, 
                `emotion`, 
                `replayability`
            )
            VALUES (
                :entry_id, 
                :round_id, 
                :user_id,
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
                `replayability` = VALUES(`replayability`),
                `user_id`       = COALESCE(`ratings`.`user_id`, VALUES(`user_id`))
        ");

        $stmt->bindValue(':entry_id', $entryId, PDO::PARAM_INT);
        $stmt->bindValue(':round_id', $roundId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':melody', $cMelody, $cMelody !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':lyrics', $cLyrics, $cLyrics !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':vocals', $cVocals, $cVocals !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':emotion', $cEmotion, $cEmotion !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':replayability', $cReplayability, $cReplayability !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->execute();

        // Fetch saved rating to get the exact generated total
        $getStmt = $pdo->prepare("
            SELECT `rating_id`, `entry_id`, `round_id`, `user_id`, `melody`, `lyrics`, `vocals`, `emotion`, `replayability`, `total`
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
 * Reset / unscore a scorecard rating for an entry in a tournament round
 * 
 * Removes active scores and returns the scorecard to genuinely unscored state.
 * Strictly verifies ownership and ensures completed tournament history is protected.
 *
 * @param int $entryId The afm_entries ID
 * @param int $roundId The tournament round ID
 * @param int|null $userId User ID for strict isolation
 * @return array Success status and message
 */
function reset_scorecard_rating(int $entryId, int $roundId, ?int $userId = null): array {
    $pdo = get_db_connection();

    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    if ($userId === null) {
        return ['success' => false, 'error' => 'Authentication required.'];
    }

    // Verify entry exists and check ownership
    $entryStmt = $pdo->prepare("
        SELECT e.`entry_id`, e.`song_id`, e.`user_id` AS `entry_user_id`, s.`title`, s.`user_id` AS `song_user_id`
        FROM `afm_entries` e
        JOIN `songs` s ON e.`song_id` = s.`song_id`
        WHERE e.`entry_id` = :id
    ");
    $entryStmt->execute([':id' => $entryId]);
    $entry = $entryStmt->fetch();
    if (!$entry) {
        return ['success' => false, 'error' => 'Entry not found.'];
    }

    $entryOwner = $entry['entry_user_id'] !== null ? (int)$entry['entry_user_id'] : 995;
    $songOwner  = $entry['song_user_id'] !== null ? (int)$entry['song_user_id'] : 995;
    if ($entryOwner !== (int)$userId && $songOwner !== (int)$userId) {
        return ['success' => false, 'error' => 'Unauthorized: Cannot reset score for another user\'s entry.'];
    }

    // SAFEGUARD 1: Stage Immutability Check
    $stageStatusCheck = $pdo->prepare("
        SELECT CASE WHEN urp.`status` IS NOT NULL THEN urp.`status` ELSE r.`status` END AS `status`, r.`round_name`
        FROM `rounds` r
        LEFT JOIN `user_round_progress` urp ON r.`round_id` = urp.`round_id` AND urp.`user_id` = :uid
        WHERE r.`round_id` = :rid
    ");
    $stageStatusCheck->execute([':rid' => $roundId, ':uid' => $userId]);
    $stg = $stageStatusCheck->fetch(PDO::FETCH_ASSOC);
    if ($stg && $stg['status'] === 'completed') {
        return [
            'success' => false,
            'blocked' => true,
            'error'   => "Stage '{$stg['round_name']}' is finalized and immutable. Completed scorecards cannot be reset."
        ];
    }

    // SAFEGUARD 2: Check if this entry and round have been finalized into completed round_results
    $historyCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM `round_results` 
        WHERE `entry_id` = :eid 
          AND `round_id` = :rid 
          AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = 995))
    ");
    $historyCheck->execute([':eid' => $entryId, ':rid' => $roundId, ':uid' => $userId, ':uid_g' => $userId]);
    $isFinalized = ((int)$historyCheck->fetchColumn() > 0);

    if ($isFinalized) {
        return [
            'success' => false,
            'blocked' => true,
            'error'   => "Cannot reset score: Stage results for \"{$entry['title']}\" have already been finalized in your tournament history. Resetting would compromise historical tournament integrity."
        ];
    }

    try {
        $pdo->beginTransaction();

        $delRating = $pdo->prepare("
            DELETE FROM `ratings` 
            WHERE `entry_id` = :eid 
              AND `round_id` = :rid 
              AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = 995))
        ");
        $delRating->execute([
            ':eid'   => $entryId, 
            ':rid'   => $roundId, 
            ':uid'   => $userId, 
            ':uid_g' => $userId
        ]);

        $pdo->commit();
        clear_dashboard_stats_cache();

        return [
            'success'     => true,
            'entry_id'    => $entryId,
            'round_id'    => $roundId,
            'song_id'     => (int)$entry['song_id'],
            'title'       => $entry['title'],
            'is_complete' => false,
            'total'       => null,
            'message'     => "Scorecard for \"{$entry['title']}\" has been reset to an unscored state."
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("AFM2 reset_scorecard_rating error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error resetting scorecard rating: ' . $e->getMessage()];
    }
}

/**
 * Get tournament round leaderboard (completed scores only, with tie-break ranking)
 */
function get_round_rankings(int $roundId, ?int $userId = null): array {
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
    if ($userId !== null) {
        $scopeR = song_user_scope_sql($userId, 'r', $pdo);
        $scopeE = song_user_scope_sql($userId, 'e', $pdo);
        $scopeS = song_user_scope_sql($userId, 's', $pdo);
        $scopeRr = ($userId === 995) 
            ? "AND (rr.`user_id` = 995 OR rr.`user_id` IS NULL)" 
            : "AND rr.`user_id` = " . (int)$userId;

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
                 {$scopeRr}
            WHERE r.`round_id` = :r_id
              AND r.`total` IS NOT NULL
              AND {$scopeR}
              AND {$scopeE}
              AND {$scopeS}
            ORDER BY 
                r.`total` DESC, 
                r.`emotion` DESC, 
                r.`replayability` DESC, 
                r.`melody` DESC, 
                r.`vocals` DESC, 
                r.`lyrics` DESC, 
                e.`entry_id` ASC
        ");
        $stmt->execute([
            ':r_id'   => $roundId, 
            ':r_res'  => $roundId
        ]);
    } else {
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
        $stmt->execute([
            ':r_id'  => $roundId, 
            ':r_res' => $roundId
        ]);
    }
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
 * Stage Advancement Target & Minimum Eligibility Rules Definition
 * 
 * Flow:
 * - Formal Auditions (1) -> Top 200 (2): Target 200, Min Eligible 201
 * - Top 200 (2) -> Top 100 (3): Target 100, Min Eligible 101
 * - Top 100 (3) -> Top 50 (4): Target 50, Min Eligible 51
 * - Top 50 (4) -> Top 20 (5): Target 20, Min Eligible 21
 * - Top 20 (5) -> Top 10 (6): Target 10, Min Eligible 11
 * - Top 10 (6) -> Finals (7): Target 1, Min Eligible 2
 */
function get_stage_advancement_rules(int $fromSequenceNo): array {
    $rules = [
        1 => ['target' => 200, 'min_eligible' => 201, 'target_count' => 200, 'min_eligible_participants' => 201, 'name' => 'Top 200', 'from_name' => 'Formal Auditions'],
        2 => ['target' => 100, 'min_eligible' => 101, 'target_count' => 100, 'min_eligible_participants' => 101, 'name' => 'Top 100', 'from_name' => 'Top 200'],
        3 => ['target' => 50,  'min_eligible' => 51,  'target_count' => 50,  'min_eligible_participants' => 51,  'name' => 'Top 50',  'from_name' => 'Top 100'],
        4 => ['target' => 20,  'min_eligible' => 21,  'target_count' => 20,  'min_eligible_participants' => 21,  'name' => 'Top 20',  'from_name' => 'Top 50'],
        5 => ['target' => 10,  'min_eligible' => 11,  'target_count' => 10,  'min_eligible_participants' => 11,  'name' => 'Top 10',  'from_name' => 'Top 20'],
        6 => ['target' => 1,   'min_eligible' => 2,   'target_count' => 1,   'min_eligible_participants' => 2,   'name' => 'Finals',  'from_name' => 'Top 10'],
    ];
    return $rules[$fromSequenceNo] ?? ['target' => 1, 'min_eligible' => 2, 'target_count' => 1, 'min_eligible_participants' => 2, 'name' => 'Next Stage', 'from_name' => 'Current Stage'];
}

/**
 * Fetch all eligible stage participants with evaluation statuses and advisory rankings
 */
function get_stage_qualification_candidates(int $fromRoundId, ?int $userId = null): array {
    $pdo = get_db_connection();
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }

    $fromRound = get_round_by_id($fromRoundId, $userId);
    if (!$fromRound) {
        return ['success' => false, 'error' => 'Current round not found.'];
    }

    $seq = (int)$fromRound['sequence_no'];
    $rules = get_stage_advancement_rules($seq);

    $nextStmt = $pdo->prepare("SELECT * FROM `rounds` WHERE `sequence_no` = :seq");
    $nextStmt->execute([':seq' => $seq + 1]);
    $nextRound = $nextStmt->fetch(PDO::FETCH_ASSOC);
    if ($nextRound && $userId !== null) {
        $nextRound = get_round_by_id((int)$nextRound['round_id'], $userId) ?? $nextRound;
    }

    $delSqlS = song_deleted_sql('s', $pdo);
    $userScopeE = ($userId !== null) ? song_user_scope_sql($userId, 'e', $pdo) : '1=1';
    $userScopeS = ($userId !== null) ? song_user_scope_sql($userId, 's', $pdo) : '1=1';

    $sdUserJoin = ($userId !== null)
        ? (($userId === 995) ? "AND (sd.`user_id` = 995 OR sd.`user_id` IS NULL)" : "AND sd.`user_id` = :uid_sd")
        : "";
    $rUserJoin = ($userId !== null)
        ? (($userId === 995) ? "AND (r.`user_id` = 995 OR r.`user_id` IS NULL)" : "AND r.`user_id` = :uid_r")
        : "";
    $rrUserJoin = ($userId !== null)
        ? (($userId === 995) ? "AND (rr.`user_id` = 995 OR rr.`user_id` IS NULL)" : "AND rr.`user_id` = :uid_rr")
        : "";

    if ($seq === 1) {
        $params = [
            ':round_id_r'  => $fromRoundId,
            ':round_id_rr' => $fromRoundId,
        ];
        if ($userId !== null && $userId !== 995) {
            $params[':uid_sd'] = $userId;
            $params[':uid_r']  = $userId;
            $params[':uid_rr'] = $userId;
        }

        // Stage 1: Eligible participants are all songs shortlisted in discovery candidate pool
        $sql = "
            SELECT 
                e.`entry_id`,
                s.`song_id`,
                s.`title`,
                COALESCE(s.`artist`, 'Unknown Artist') AS `artist`,
                r.`rating_id`,
                r.`melody`,
                r.`lyrics`,
                r.`vocals`,
                r.`emotion`,
                r.`replayability`,
                r.`total`,
                CASE 
                    WHEN r.`melody` IS NOT NULL AND r.`lyrics` IS NOT NULL AND r.`vocals` IS NOT NULL 
                         AND r.`emotion` IS NOT NULL AND r.`replayability` IS NOT NULL THEN 'completed'
                    WHEN r.`rating_id` IS NOT NULL AND (
                         r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL 
                         OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL
                    ) THEN 'incomplete'
                    ELSE 'untouched'
                END AS `eval_status`,
                rr.`result` AS `saved_result`,
                rr.`rank_position` AS `saved_rank`,
                rr.`manual_order` AS `saved_manual_order`
            FROM `afm_entries` e
            JOIN `songs` s ON e.`song_id` = s.`song_id`
            JOIN `song_discovery` sd ON s.`song_id` = sd.`song_id` AND sd.`status` = 'shortlisted' {$sdUserJoin}
            LEFT JOIN `ratings` r ON e.`entry_id` = r.`entry_id` AND r.`round_id` = :round_id_r {$rUserJoin}
            LEFT JOIN `round_results` rr ON e.`entry_id` = rr.`entry_id` AND rr.`round_id` = :round_id_rr {$rrUserJoin}
            WHERE {$userScopeE} AND {$userScopeS} AND {$delSqlS}
              AND s.`is_consolidated` = 0
              AND e.`status` != 'excluded'
        ";
    } else {
        // Subsequent Stages: Eligible participants are songs that qualified from the preceding stage
        $prevRoundStmt = $pdo->prepare("SELECT `round_id` FROM `rounds` WHERE `sequence_no` = :prev_seq");
        $prevRoundStmt->execute([':prev_seq' => $seq - 1]);
        $prevRoundId = (int)$prevRoundStmt->fetchColumn();

        $prevRrUserJoin = ($userId !== null)
            ? (($userId === 995) ? "AND (prev_rr.`user_id` = 995 OR prev_rr.`user_id` IS NULL)" : "AND prev_rr.`user_id` = :uid_prev_rr")
            : "";

        $params = [
            ':prev_round_id' => $prevRoundId,
            ':round_id_r'    => $fromRoundId,
            ':round_id_rr'   => $fromRoundId,
        ];
        if ($userId !== null && $userId !== 995) {
            $params[':uid_prev_rr'] = $userId;
            $params[':uid_r']       = $userId;
            $params[':uid_rr']      = $userId;
        }

        $sql = "
            SELECT 
                e.`entry_id`,
                s.`song_id`,
                s.`title`,
                COALESCE(s.`artist`, 'Unknown Artist') AS `artist`,
                r.`rating_id`,
                r.`melody`,
                r.`lyrics`,
                r.`vocals`,
                r.`emotion`,
                r.`replayability`,
                r.`total`,
                CASE 
                    WHEN r.`melody` IS NOT NULL AND r.`lyrics` IS NOT NULL AND r.`vocals` IS NOT NULL 
                         AND r.`emotion` IS NOT NULL AND r.`replayability` IS NOT NULL THEN 'completed'
                    WHEN r.`rating_id` IS NOT NULL AND (
                         r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL 
                         OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL
                    ) THEN 'incomplete'
                    ELSE 'untouched'
                END AS `eval_status`,
                rr.`result` AS `saved_result`,
                rr.`rank_position` AS `saved_rank`,
                rr.`manual_order` AS `saved_manual_order`,
                prev_rr.`rank_position` AS `seed_rank`,
                prev_rr.`manual_order` AS `seed_manual_order`
            FROM `afm_entries` e
            JOIN `round_results` prev_rr ON e.`entry_id` = prev_rr.`entry_id` 
                 AND prev_rr.`round_id` = :prev_round_id 
                 AND prev_rr.`result` IN ('qualified', 'winner')
                 {$prevRrUserJoin}
            JOIN `songs` s ON e.`song_id` = s.`song_id`
            LEFT JOIN `ratings` r ON e.`entry_id` = r.`entry_id` AND r.`round_id` = :round_id_r {$rUserJoin}
            LEFT JOIN `round_results` rr ON e.`entry_id` = rr.`entry_id` AND rr.`round_id` = :round_id_rr {$rrUserJoin}
            WHERE {$userScopeE} AND {$userScopeS} AND {$delSqlS}
              AND s.`is_consolidated` = 0
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute Advisory Rankings strictly adhering to the 5 official criteria tie-breakers:
    // Total DESC -> Emotion DESC -> Replayability DESC -> Melody DESC -> Vocals DESC -> Lyrics DESC -> entry_id ASC
    usort($rows, function ($a, $b) {
        $aCompleted = ($a['eval_status'] === 'completed');
        $bCompleted = ($b['eval_status'] === 'completed');
        if ($aCompleted !== $bCompleted) {
            return $aCompleted ? -1 : 1;
        }

        if ($aCompleted && $bCompleted) {
            $totalDiff = (int)$b['total'] - (int)$a['total'];
            if ($totalDiff !== 0) return $totalDiff;

            $emotionDiff = (int)$b['emotion'] - (int)$a['emotion'];
            if ($emotionDiff !== 0) return $emotionDiff;

            $replayDiff = (int)$b['replayability'] - (int)$a['replayability'];
            if ($replayDiff !== 0) return $replayDiff;

            $melodyDiff = (int)$b['melody'] - (int)$a['melody'];
            if ($melodyDiff !== 0) return $melodyDiff;

            $vocalsDiff = (int)$b['vocals'] - (int)$a['vocals'];
            if ($vocalsDiff !== 0) return $vocalsDiff;

            $lyricsDiff = (int)$b['lyrics'] - (int)$a['lyrics'];
            if ($lyricsDiff !== 0) return $lyricsDiff;
        }

        return (int)$a['entry_id'] - (int)$b['entry_id'];
    });

    $targetCount = $rules['target'];
    $minEligible = $rules['min_eligible'];
    $totalEligible = count($rows);
    $completedCount = 0;
    $incompleteCount = 0;

    $candidates = [];
    $suggestedQualifiers = [];

    foreach ($rows as $idx => $row) {
        $rank = $idx + 1;
        $row['suggested_rank'] = $rank;
        $row['calculated_rank'] = $rank;
        $row['is_eval_complete'] = ($row['eval_status'] === 'completed');
        $row['status'] = $row['eval_status'];
        $row['is_suggested'] = ($row['eval_status'] === 'completed' && $rank <= $targetCount);

        if ($row['eval_status'] === 'completed') {
            $completedCount++;
            if ($rank <= $targetCount) {
                $suggestedQualifiers[] = $row;
            }
        } else {
            $incompleteCount++;
        }
        $candidates[] = $row;
    }

    $blockingReasons = [];
    if ($totalEligible < $minEligible) {
        $blockingReasons[] = "{$fromRound['round_name']} requires at least {$minEligible} eligible participants before advancing (current eligible: {$totalEligible}).";
    }
    if ($incompleteCount > 0) {
        $blockingReasons[] = "All eligible participants must have completed evaluations before finalizing the stage ({$incompleteCount} incomplete or untouched).";
    }

    $canAdvance = empty($blockingReasons) && ($fromRound['status'] === 'in_progress');

    return [
        'success'               => true,
        'from_round'            => $fromRound,
        'next_round'            => $nextRound,
        'rules'                 => $rules,
        'target_count'          => $targetCount,
        'min_eligible_required' => $minEligible,
        'total_eligible'        => $totalEligible,
        'completed_evaluations' => $completedCount,
        'incomplete_evaluations'=> $incompleteCount,
        'can_advance'           => $canAdvance,
        'blocking_reasons'      => $blockingReasons,
        'candidates'            => $candidates,
        'suggested_qualifiers'  => $suggestedQualifiers
    ];
}

/**
 * Preview tournament stage qualification & cutoff analysis
 */
function preview_stage_advancement(int $fromRoundId, ?int $userId = null): array {
    $candData = get_stage_qualification_candidates($fromRoundId, $userId);
    if (!$candData['success']) {
        return $candData;
    }

    $fromRound = $candData['from_round'];
    $nextRound = $candData['next_round'];
    if (!$nextRound) {
        return ['success' => false, 'error' => 'This is the final stage. No subsequent round exists.'];
    }

    $targetCutoff = $candData['target_count'];
    $qualifiers = $candData['suggested_qualifiers'];
    $eliminated = array_slice($candData['candidates'], count($qualifiers));

    $hasBoundaryTie = false;
    $boundaryTieDetails = null;

    if (!empty($qualifiers) && !empty($eliminated)) {
        $cutoffSong = end($qualifiers);
        $firstOut = reset($eliminated);
        if ($cutoffSong && $firstOut && isset($cutoffSong['total'], $firstOut['total']) && $cutoffSong['total'] === $firstOut['total']) {
            $hasBoundaryTie = true;
            $boundaryTieDetails = [
                'tied_total'        => $cutoffSong['total'],
                'cutoff_song_title' => $cutoffSong['title'],
                'first_out_title'   => $firstOut['title'],
                'criteria_tie_break'=> "Cutoff tie resolved by advisory tie-breakers: Emotion ({$cutoffSong['emotion']} vs {$firstOut['emotion']}), Replayability ({$cutoffSong['replayability']} vs {$firstOut['replayability']}), Melody ({$cutoffSong['melody']} vs {$firstOut['melody']})."
            ];
        }
    }

    return [
        'success'               => true,
        'from_round'            => $fromRound,
        'next_round'            => $nextRound,
        'rules'                 => $candData['rules'],
        'total_eligible'        => $candData['total_eligible'],
        'total_completed'       => $candData['completed_evaluations'],
        'incomplete_evaluations'=> $candData['incomplete_evaluations'],
        'target_cutoff'         => $targetCutoff,
        'min_eligible_required' => $candData['min_eligible_required'],
        'can_advance'           => $candData['can_advance'],
        'blocking_reasons'      => $candData['blocking_reasons'],
        'candidates'            => $candData['candidates'],
        'qualifiers'            => $qualifiers,
        'eliminated'            => $eliminated,
        'has_boundary_tie'      => $hasBoundaryTie,
        'boundary_tie'          => $boundaryTieDetails
    ];
}

/**
 * Commit stage advancement (transactional, with double-advancement protection and manual ordering persistence)
 */
function commit_stage_advancement(
    int $fromRoundId, 
    array $qualifierEntryIds, 
    ?int $userId = null,
    ?array $manualOrderMap = null
): array {
    $pdo = get_db_connection();
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }

    $candData = get_stage_qualification_candidates($fromRoundId, $userId);
    if (!$candData['success']) {
        return $candData;
    }

    $fromRound = $candData['from_round'];
    $nextRound = $candData['next_round'];
    if (!$nextRound) {
        return ['success' => false, 'error' => 'This is the final stage. No subsequent round exists.'];
    }

    if ($fromRound['status'] === 'completed') {
        return [
            'success' => false, 
            'error'   => "'{$fromRound['round_name']}' has already been completed and advanced."
        ];
    }

    // Strict Rule Validation: Minimum participants required
    if ($candData['total_eligible'] < $candData['min_eligible_required']) {
        return [
            'success' => false,
            'error'   => "Cannot advance: {$fromRound['round_name']} requires at least {$candData['min_eligible_required']} eligible participants before advancing (current: {$candData['total_eligible']})."
        ];
    }

    // Strict Rule Validation: All eligible participants must have completed evaluations
    if ($candData['incomplete_evaluations'] > 0) {
        return [
            'success' => false,
            'error'   => "Cannot advance: All eligible participants must be fully evaluated before finalizing the stage ({$candData['incomplete_evaluations']} incomplete or untouched evaluations remaining)."
        ];
    }

    // Strict Rule Validation: Exact advancement target count
    $cleanQualifierIds = array_values(array_unique(array_map('intval', $qualifierEntryIds)));
    $targetCount = (int)$candData['target_count'];

    if (count($cleanQualifierIds) !== $targetCount) {
        return [
            'success' => false,
            'error'   => "Advancement target requires exactly {$targetCount} participants (received: " . count($cleanQualifierIds) . ")."
        ];
    }

    // Strict Rule Validation: Eligibility of every selected song
    $eligibleEntryMap = [];
    foreach ($candData['candidates'] as $c) {
        $eligibleEntryMap[(int)$c['entry_id']] = $c;
    }

    foreach ($cleanQualifierIds as $qid) {
        if (!isset($eligibleEntryMap[$qid])) {
            return [
                'success' => false,
                'error'   => "Invalid participant selection: Entry ID {$qid} is not an eligible participant in {$fromRound['round_name']}."
            ];
        }
    }

    try {
        $pdo->beginTransaction();

        // Mutex concurrency lock
        if ($userId !== null) {
            $lockStmt = $pdo->prepare("SELECT `status` FROM `user_round_progress` WHERE `user_id` = :uid AND `round_id` = :rid FOR UPDATE");
            $lockStmt->execute([':uid' => $userId, ':rid' => $fromRoundId]);
            $currentLockedStatus = $lockStmt->fetchColumn();

            if ($currentLockedStatus === 'completed') {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'error'   => "'{$fromRound['round_name']}' has already been completed and advanced by a concurrent request."
                ];
            }
        } else {
            $lockStmt = $pdo->prepare("SELECT `status` FROM `rounds` WHERE `round_id` = :rid FOR UPDATE");
            $lockStmt->execute([':rid' => $fromRoundId]);
            $currentLockedStatus = $lockStmt->fetchColumn();

            if ($currentLockedStatus === 'completed') {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'error'   => "'{$fromRound['round_name']}' has already been completed and advanced by a concurrent request."
                ];
            }
        }

        // Prepare insert into round_results with snapshot and manual order columns
        $resStmt = $pdo->prepare("
            INSERT INTO `round_results` (
                `entry_id`, 
                `round_id`, 
                `user_id`,
                `result`, 
                `rank_position`,
                `manual_order`,
                `song_title`,
                `song_artist`,
                `score_total`,
                `reason`, 
                `decided_at`
            )
            VALUES (
                :entry_id, 
                :round_id, 
                :user_id,
                :result, 
                :rank_position,
                :manual_order,
                :song_title,
                :song_artist,
                :score_total,
                :reason, 
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                `result`        = VALUES(`result`),
                `rank_position` = VALUES(`rank_position`),
                `manual_order`  = VALUES(`manual_order`),
                `song_title`    = VALUES(`song_title`),
                `song_artist`   = VALUES(`song_artist`),
                `score_total`   = VALUES(`score_total`),
                `reason`        = VALUES(`reason`),
                `user_id`       = COALESCE(`round_results`.`user_id`, VALUES(`user_id`)),
                `decided_at`    = NOW()
        ");

        $isFinalStage = ((int)$nextRound['sequence_no'] === 7 || (int)$nextRound['target_count'] === 1);

        foreach ($candData['candidates'] as $cand) {
            $eid = (int)$cand['entry_id'];
            $isQualified = in_array($eid, $cleanQualifierIds, true);

            $manualOrder = null;
            if ($isQualified) {
                // Determine 1-indexed position in user's manually arranged order
                if (is_array($manualOrderMap) && isset($manualOrderMap[$eid])) {
                    $manualOrder = (int)$manualOrderMap[$eid];
                } else {
                    $pos = array_search($eid, $cleanQualifierIds, true);
                    $manualOrder = ($pos !== false) ? ($pos + 1) : null;
                }
            }

            $result = $isQualified ? 'qualified' : 'eliminated';
            if ($isQualified && $isFinalStage && $manualOrder === 1) {
                $result = 'winner';
            }

            $rankPosition = $isQualified ? $manualOrder : $cand['suggested_rank'];

            $scoreDisplay = ($cand['total'] !== null) ? "{$cand['total']}/50" : 'unscored';
            $reason = $isQualified
                ? "Qualified to {$nextRound['round_name']} (Manual Seed #{$manualOrder}) with score {$scoreDisplay}"
                : "Eliminated in {$fromRound['round_name']} with score {$scoreDisplay} (Rank #{$cand['suggested_rank']})";

            $resStmt->execute([
                ':entry_id'      => $eid,
                ':round_id'      => $fromRoundId,
                ':user_id'       => $userId,
                ':result'        => $result,
                ':rank_position' => $rankPosition,
                ':manual_order'  => $manualOrder,
                ':song_title'    => $cand['title'],
                ':song_artist'   => $cand['artist'],
                ':score_total'   => $cand['total'],
                ':reason'        => $reason
            ]);
        }

        if ($userId !== null) {
            $progStmt = $pdo->prepare("
                INSERT INTO `user_round_progress` (`user_id`, `round_id`, `status`, `completed_at`, `updated_at`)
                VALUES (:uid, :from_id, 'completed', NOW(), NOW())
                ON DUPLICATE KEY UPDATE `status` = 'completed', `completed_at` = NOW(), `updated_at` = NOW()
            ");
            $progStmt->execute([':uid' => $userId, ':from_id' => $fromRoundId]);

            $progNext = $pdo->prepare("
                INSERT INTO `user_round_progress` (`user_id`, `round_id`, `status`, `completed_at`, `updated_at`)
                VALUES (:uid, :next_id, 'in_progress', NULL, NOW())
                ON DUPLICATE KEY UPDATE `status` = 'in_progress', `updated_at` = NOW()
            ");
            $progNext->execute([':uid' => $userId, ':next_id' => $nextRound['round_id']]);
        } else {
            $updFrom = $pdo->prepare("UPDATE `rounds` SET `status` = 'completed', `completed_at` = NOW() WHERE `round_id` = :id");
            $updFrom->execute([':id' => $fromRoundId]);

            $updNext = $pdo->prepare("UPDATE `rounds` SET `status` = 'in_progress' WHERE `round_id` = :id");
            $updNext->execute([':id' => $nextRound['round_id']]);
        }

        $pdo->commit();
        clear_dashboard_stats_cache();

        return [
            'success'          => true,
            'message'          => "Successfully advanced {$fromRound['round_name']} to {$nextRound['round_name']}.",
            'from_round'       => $fromRound['round_name'],
            'next_round'       => $nextRound['round_name'],
            'qualifiers_count' => count($cleanQualifierIds),
            'eliminated_count' => count($candData['candidates']) - count($cleanQualifierIds)
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
 * Check if a given user ID has administrator role
 */
function is_user_admin(?int $userId): bool {
    if ($userId === null) {
        return false;
    }
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT `role` FROM `app_users` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        $role = $stmt->fetchColumn();
        return $role === 'admin';
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Exceptional Administrative Stage Correction
 * 
 * Strict administrative correction mechanism for exceptional technical or data-integrity errors.
 * Requires:
 * 1. Explicit administrator authorization
 * 2. Documented technical reason (>= 10 chars)
 * 3. Records admin ID, timestamp, and reason in tournament_admin_corrections
 * 4. Preserves full historical decisions in round_results_history linked via correction_id
 * 5. Maintains consistency across affected stages without silent overwriting
 *
 * @param int $roundId Target round ID to correct
 * @param string $reason Documented technical justification
 * @param int|null $adminUserId Admin performing correction
 * @param int|null $targetUserId Tournament owner being corrected
 * @return array Result metadata
 */
function admin_correct_tournament_stage(
    int $roundId, 
    string $reason, 
    ?int $adminUserId = null, 
    ?int $targetUserId = null
): array {
    $pdo = get_db_connection();

    if ($adminUserId === null && is_authenticated()) {
        $adminUserId = get_current_user_id();
    }
    if ($adminUserId === null) {
        return ['success' => false, 'error' => 'Authentication required.'];
    }

    // 1. Explicit administrative authorization
    if (!is_user_admin($adminUserId)) {
        return [
            'success' => false, 
            'error'   => 'Unauthorized: Only administrators are authorized to execute exceptional stage corrections.'
        ];
    }

    // 2. Documented reason requirement
    $reason = trim($reason);
    if (strlen($reason) < 10) {
        return [
            'success' => false, 
            'error'   => 'A documented technical reason of at least 10 characters is required for exceptional administrative corrections.'
        ];
    }

    if ($targetUserId === null) {
        $targetUserId = $adminUserId;
    }

    $round = get_round_by_id($roundId, $targetUserId);
    if (!$round) {
        return ['success' => false, 'error' => 'Tournament stage not found.'];
    }

    if ($round['status'] !== 'completed') {
        return ['success' => false, 'error' => "Only completed stages can receive administrative corrections (current stage status: '{$round['status']}')."];
    }

    $seq = (int)$round['sequence_no'];

    try {
        $pdo->beginTransaction();

        // Concurrency mutex
        if ($targetUserId !== null) {
            $lockStmt = $pdo->prepare("SELECT `status` FROM `user_round_progress` WHERE `user_id` = :uid AND `round_id` = :rid FOR UPDATE");
            $lockStmt->execute([':uid' => $targetUserId, ':rid' => $roundId]);
        } else {
            $lockStmt = $pdo->prepare("SELECT `status` FROM `rounds` WHERE `round_id` = :rid FOR UPDATE");
            $lockStmt->execute([':rid' => $roundId]);
        }

        // Count current active decisions
        $userScopeRr = ($targetUserId !== null)
            ? (($targetUserId === 995) ? "AND (rr.`user_id` = 995 OR rr.`user_id` IS NULL)" : "AND rr.`user_id` = :uid_rr")
            : "";
        $countParams = [':rid' => $roundId];
        if ($targetUserId !== null && $targetUserId !== 995) {
            $countParams[':uid_rr'] = $targetUserId;
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `round_results` rr WHERE rr.`round_id` = :rid {$userScopeRr}");
        $countStmt->execute($countParams);
        $affectedCount = (int)$countStmt->fetchColumn();

        // Determine next history iteration number
        $userScopeH = ($targetUserId !== null) ? "WHERE `user_id` = :uid AND `round_id` = :rid" : "WHERE `round_id` = :rid";
        $iterStmt = $pdo->prepare("SELECT COALESCE(MAX(`iteration`), 0) + 1 FROM `round_results_history` {$userScopeH}");
        $iterParams = [':rid' => $roundId];
        if ($targetUserId !== null) {
            $iterParams[':uid'] = $targetUserId;
        }
        $iterStmt->execute($iterParams);
        $nextIteration = (int)$iterStmt->fetchColumn();

        // 3. Record the administrative correction in audit table
        $corrStmt = $pdo->prepare("
            INSERT INTO `tournament_admin_corrections` (
                `round_id`, 
                `target_user_id`, 
                `admin_user_id`, 
                `reason`, 
                `iteration`, 
                `affected_qualifiers_count`, 
                `created_at`
            ) VALUES (
                :rid, 
                :target_uid, 
                :admin_uid, 
                :reason, 
                :iteration, 
                :affected_cnt, 
                NOW()
            )
        ");
        $corrStmt->execute([
            ':rid'          => $roundId,
            ':target_uid'   => $targetUserId,
            ':admin_uid'    => $adminUserId,
            ':reason'       => $reason,
            ':iteration'    => $nextIteration,
            ':affected_cnt' => $affectedCount,
        ]);
        $correctionId = (int)$pdo->lastInsertId();

        // 4 & 5. Archive current finalized decisions into round_results_history linked via correction_id
        $archiveParams = [
            ':rid'           => $roundId,
            ':iter'          => $nextIteration,
            ':correction_id' => $correctionId,
            ':admin_uid'     => $adminUserId,
            ':reason'        => $reason,
        ];
        if ($targetUserId !== null) {
            $archiveParams[':uid_target'] = $targetUserId;
            if ($targetUserId !== 995) {
                $archiveParams[':uid_rr'] = $targetUserId;
            }
        }

        $archiveSql = "
            INSERT INTO `round_results_history` (
                `round_id`, 
                `user_id`, 
                `iteration`, 
                `entry_id`, 
                `song_id`,
                `song_title`, 
                `song_artist`, 
                `result`, 
                `rank_position`, 
                `manual_order`,
                `score_total`, 
                `reason`, 
                `decided_at`, 
                `reopened_at`,
                `corrected_by_user_id`,
                `correction_id`
            )
            SELECT 
                rr.`round_id`, 
                COALESCE(rr.`user_id`, :uid_target), 
                :iter, 
                rr.`entry_id`, 
                e.`song_id`,
                COALESCE(rr.`song_title`, s.`title`, 'Archived Song'),
                COALESCE(rr.`song_artist`, s.`artist`, 'Unknown Artist'),
                rr.`result`, 
                rr.`rank_position`, 
                rr.`manual_order`,
                COALESCE(rr.`score_total`, rt.`total`),
                :reason, 
                rr.`decided_at`, 
                NOW(),
                :admin_uid,
                :correction_id
            FROM `round_results` rr
            JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id`
            LEFT JOIN `songs` s ON e.`song_id` = s.`song_id`
            LEFT JOIN `ratings` rt ON e.`entry_id` = rt.`entry_id` AND rt.`round_id` = rr.`round_id`
            WHERE rr.`round_id` = :rid {$userScopeRr}
        ";
        $archiveStmt = $pdo->prepare($archiveSql);
        $archiveStmt->execute($archiveParams);

        // Delete active decisions for this round
        $delRrUser = ($targetUserId !== null)
            ? (($targetUserId === 995) ? "AND (`user_id` = 995 OR `user_id` IS NULL)" : "AND `user_id` = :uid")
            : "";
        $delRrParams = [':rid' => $roundId];
        if ($targetUserId !== null && $targetUserId !== 995) {
            $delRrParams[':uid'] = $targetUserId;
        }
        $pdo->prepare("DELETE FROM `round_results` WHERE `round_id` = :rid {$delRrUser}")->execute($delRrParams);

        // Maintain consistency across affected stages: Delete active decisions in subsequent rounds
        $pdo->prepare("
            DELETE rr FROM `round_results` rr
            JOIN `rounds` r ON rr.`round_id` = r.`round_id`
            WHERE r.`sequence_no` > :seq {$userScopeRr}
        ")->execute([':seq' => $seq] + ($targetUserId !== null && $targetUserId !== 995 ? [':uid_rr' => $targetUserId] : []));

        // 6. Reset target stage to 'in_progress', subsequent stages to 'upcoming'
        if ($targetUserId !== null) {
            $pdo->prepare("
                UPDATE `user_round_progress` 
                SET `status` = 'in_progress', `completed_at` = NULL, `updated_at` = NOW() 
                WHERE `user_id` = :uid AND `round_id` = :rid
            ")->execute([':uid' => $targetUserId, ':rid' => $roundId]);

            $pdo->prepare("
                UPDATE `user_round_progress` urp
                JOIN `rounds` r ON urp.`round_id` = r.`round_id`
                SET urp.`status` = 'upcoming', urp.`completed_at` = NULL, urp.`updated_at` = NOW()
                WHERE urp.`user_id` = :uid AND r.`sequence_no` > :seq
            ")->execute([':uid' => $targetUserId, ':seq' => $seq]);
        } else {
            $pdo->prepare("UPDATE `rounds` SET `status` = 'in_progress', `completed_at` = NULL WHERE `round_id` = :rid")
                ->execute([':rid' => $roundId]);
            $pdo->prepare("UPDATE `rounds` SET `status` = 'upcoming', `completed_at` = NULL WHERE `sequence_no` > :seq")
                ->execute([':seq' => $seq]);
        }

        $pdo->commit();
        clear_dashboard_stats_cache();

        return [
            'success'                  => true,
            'message'                  => "Administrative correction recorded (Correction ID #{$correctionId}). Stage '{$round['round_name']}' unlocked for editing. Previous decisions archived under Revision #{$nextIteration}.",
            'correction_id'            => $correctionId,
            'round_id'                 => $roundId,
            'round_name'               => $round['round_name'],
            'history_iteration'        => $nextIteration,
            'affected_qualifiers_count'=> $affectedCount,
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("AFM2 admin_correct_tournament_stage error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to execute administrative correction: ' . $e->getMessage()];
    }
}

/**
 * Controlled Stage Reopening (Legacy / Wrapper)
 * Ordinary stage reopening is disabled to maintain historical integrity.
 * Only authorized administrators with a documented reason can perform exceptional corrections.
 */
function reopen_tournament_stage(int $roundId, ?int $userId = null, string $reason = ''): array {
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }

    if (!is_user_admin($userId)) {
        return [
            'success' => false,
            'blocked' => true,
            'error'   => 'Ordinary stage reopening is disabled. Finalized tournament stages are immutable historical records. Only authorized administrators may perform exceptional corrections with a documented reason.'
        ];
    }

    if (empty(trim($reason))) {
        return [
            'success' => false,
            'blocked' => true,
            'error'   => 'A documented technical reason is required for exceptional administrative corrections.'
        ];
    }

    return admin_correct_tournament_stage($roundId, $reason, $userId, $userId);
}

/**
 * Get stage reopen metadata and affected stages summary
 */
function get_stage_reopen_info(int $roundId, ?int $userId = null): array {
    $pdo = get_db_connection();
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }

    $round = get_round_by_id($roundId, $userId);
    if (!$round) {
        return ['success' => false, 'error' => 'Stage not found.'];
    }

    $seq = (int)$round['sequence_no'];

    // Find subsequent rounds that will be affected
    $subStmt = $pdo->prepare("SELECT `round_id`, `round_name`, `sequence_no`, `status` FROM `rounds` WHERE `sequence_no` > :seq ORDER BY `sequence_no` ASC");
    $subStmt->execute([':seq' => $seq]);
    $affectedRounds = $subStmt->fetchAll(PDO::FETCH_ASSOC);

    // Current qualifications count
    $userScopeRr = ($userId !== null)
        ? (($userId === 995) ? "AND (`user_id` = 995 OR `user_id` IS NULL)" : "AND `user_id` = :uid")
        : "";
    $countParams = [':rid' => $roundId];
    if ($userId !== null && $userId !== 995) {
        $countParams[':uid'] = $userId;
    }
    $resCountStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN `result` IN ('qualified', 'winner') THEN 1 ELSE 0 END) AS qualified_count,
            SUM(CASE WHEN `result` = 'eliminated' THEN 1 ELSE 0 END) AS eliminated_count
        FROM `round_results`
        WHERE `round_id` = :rid {$userScopeRr}
    ");
    $resCountStmt->execute($countParams);
    $counts = $resCountStmt->fetch(PDO::FETCH_ASSOC);

    return [
        'success'          => true,
        'round'            => $round,
        'qualified_count'  => (int)($counts['qualified_count'] ?? 0),
        'eliminated_count' => (int)($counts['eliminated_count'] ?? 0),
        'affected_stages'  => array_column($affectedRounds, 'round_name')
    ];
}

/**
 * Get historical round results and archive data
 */
function get_historical_results(?int $userId = null): array {
    $pdo = get_db_connection();

    $whereSql = "";
    $params = [];
    $ratingJoinCondition = "";
    if ($userId !== null) {
        if ($userId === 995) {
            $whereSql = "WHERE (rr.`user_id` = 995 OR rr.`user_id` IS NULL) AND (e.`user_id` = 995 OR e.`user_id` IS NULL) AND (s.`user_id` = 995 OR s.`user_id` IS NULL OR s.`song_id` IS NULL)";
            $ratingJoinCondition = "AND (rt.`user_id` = 995 OR rt.`user_id` IS NULL)";
        } else {
            $whereSql = "WHERE rr.`user_id` = :uid AND e.`user_id` = :uid_e AND (s.`user_id` = :uid_s OR s.`song_id` IS NULL)";
            $params[':uid'] = $userId;
            $params[':uid_e'] = $userId;
            $params[':uid_s'] = $userId;
            $ratingJoinCondition = "AND rt.`user_id` = :uid_rt";
            $params[':uid_rt'] = $userId;
        }
    }

    $stmt = $pdo->prepare("
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
            rr.`manual_order`,
            rr.`reason`,
            rr.`decided_at`,
            e.`song_id`,
            COALESCE(s.`title`, rr.`song_title`, 'Archived Song') AS `title`,
            COALESCE(s.`artist`, rr.`song_artist`, 'Unknown Artist') AS `artist`,
            COALESCE(rt.`melody`, 0) AS `melody`,
            COALESCE(rt.`lyrics`, 0) AS `lyrics`,
            COALESCE(rt.`vocals`, 0) AS `vocals`,
            COALESCE(rt.`emotion`, 0) AS `emotion`,
            COALESCE(rt.`replayability`, 0) AS `replayability`,
            COALESCE(rt.`total`, rr.`score_total`, 0) AS `total`
        FROM `round_results` rr
        JOIN `rounds` r ON rr.`round_id` = r.`round_id`
        JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id`
        LEFT JOIN `songs` s ON e.`song_id` = s.`song_id`
        LEFT JOIN `ratings` rt ON e.`entry_id` = rt.`entry_id` AND rt.`round_id` = r.`round_id` {$ratingJoinCondition}
        {$whereSql}
        ORDER BY r.`sequence_no` ASC, COALESCE(rr.`manual_order`, rr.`rank_position`) ASC
    ");

    $stmt->execute($params);

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
 * Retrieve the authoritative tournament champion from the finalized Finals stage result
 *
 * Sourced strictly from the Finals record (sequence_no = 7, target_count = 1) with criteria breakdown.
 * Does not overwrite or conflate with historical Top 10 scores.
 *
 * @param int|null $userId User ID for multi-user isolation
 * @return array|null The champion record with total, emotion, replayability, melody, vocals, lyrics, or null
 */
function get_tournament_champion(?int $userId = null): ?array {
    $pdo = get_db_connection();

    $whereSql = "";
    $params = [];
    $ratingJoinCondition = "";
    if ($userId !== null) {
        if ($userId === 995) {
            $whereSql = "WHERE (rr.`user_id` = 995 OR rr.`user_id` IS NULL) AND (e.`user_id` = 995 OR e.`user_id` IS NULL) AND (s.`user_id` = 995 OR s.`user_id` IS NULL OR s.`song_id` IS NULL)";
            $ratingJoinCondition = "AND (rt.`user_id` = 995 OR rt.`user_id` IS NULL)";
        } else {
            $whereSql = "WHERE rr.`user_id` = :uid AND e.`user_id` = :uid_e AND (s.`user_id` = :uid_s OR s.`song_id` IS NULL)";
            $params[':uid'] = $userId;
            $params[':uid_e'] = $userId;
            $params[':uid_s'] = $userId;
            $ratingJoinCondition = "AND rt.`user_id` = :uid_rt";
            $params[':uid_rt'] = $userId;
        }
    }

    // 1. First priority: Check authoritative Finals stage (sequence_no = 7)
    $stmt = $pdo->prepare("
        SELECT 
            r.`round_id`,
            r.`round_name`,
            r.`sequence_no`,
            r.`status` AS `round_status`,
            r.`completed_at`,
            rr.`result_id`,
            rr.`entry_id`,
            rr.`result`,
            rr.`rank_position`,
            rr.`manual_order`,
            rr.`reason`,
            rr.`decided_at`,
            e.`song_id`,
            COALESCE(s.`title`, rr.`song_title`, 'Archived Song') AS `title`,
            COALESCE(s.`artist`, rr.`song_artist`, 'Unknown Artist') AS `artist`,
            COALESCE(rt.`melody`, 0) AS `melody`,
            COALESCE(rt.`lyrics`, 0) AS `lyrics`,
            COALESCE(rt.`vocals`, 0) AS `vocals`,
            COALESCE(rt.`emotion`, 0) AS `emotion`,
            COALESCE(rt.`replayability`, 0) AS `replayability`,
            COALESCE(rt.`total`, rr.`score_total`, 0) AS `total`,
            r.`round_name` AS `winning_stage`
        FROM `round_results` rr
        JOIN `rounds` r ON rr.`round_id` = r.`round_id`
        JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id`
        LEFT JOIN `songs` s ON e.`song_id` = s.`song_id`
        LEFT JOIN `ratings` rt ON e.`entry_id` = rt.`entry_id` AND rt.`round_id` = r.`round_id` {$ratingJoinCondition}
        {$whereSql}
        AND r.`sequence_no` = 7 
        AND rr.`result` IN ('winner', 'qualified')
        ORDER BY COALESCE(rr.`manual_order`, rr.`rank_position`) ASC, rr.`decided_at` DESC
        LIMIT 1
    ");
    $stmt->execute($params);
    $champion = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($champion) {
        return $champion;
    }

    // 2. Fallback: If Finals has not been finalized yet, check latest stage where result = 'winner'
    $stmtFallback = $pdo->prepare("
        SELECT 
            r.`round_id`,
            r.`round_name`,
            r.`sequence_no`,
            r.`status` AS `round_status`,
            r.`completed_at`,
            rr.`result_id`,
            rr.`entry_id`,
            rr.`result`,
            rr.`rank_position`,
            rr.`manual_order`,
            rr.`reason`,
            rr.`decided_at`,
            e.`song_id`,
            COALESCE(s.`title`, rr.`song_title`, 'Archived Song') AS `title`,
            COALESCE(s.`artist`, rr.`song_artist`, 'Unknown Artist') AS `artist`,
            COALESCE(rt.`melody`, 0) AS `melody`,
            COALESCE(rt.`lyrics`, 0) AS `lyrics`,
            COALESCE(rt.`vocals`, 0) AS `vocals`,
            COALESCE(rt.`emotion`, 0) AS `emotion`,
            COALESCE(rt.`replayability`, 0) AS `replayability`,
            COALESCE(rt.`total`, rr.`score_total`, 0) AS `total`,
            r.`round_name` AS `winning_stage`
        FROM `round_results` rr
        JOIN `rounds` r ON rr.`round_id` = r.`round_id`
        JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id`
        LEFT JOIN `songs` s ON e.`song_id` = s.`song_id`
        LEFT JOIN `ratings` rt ON e.`entry_id` = rt.`entry_id` AND rt.`round_id` = r.`round_id` {$ratingJoinCondition}
        {$whereSql}
        AND rr.`result` = 'winner'
        ORDER BY r.`sequence_no` DESC, COALESCE(rr.`manual_order`, rr.`rank_position`) ASC
        LIMIT 1
    ");
    $stmtFallback->execute($params);
    $fallbackChampion = $stmtFallback->fetch(PDO::FETCH_ASSOC);

    return $fallbackChampion ?: null;
}

/**
 * Get all tournament rounds with aggregated statistics
 */
function get_tournament_rounds(?int $userId = null): array {
    $pdo = get_db_connection();

    if ($userId !== null) {
        $stmt = $pdo->prepare("
            SELECT 
                r.`round_id`, 
                r.`round_name`, 
                r.`sequence_no`, 
                CASE 
                    WHEN urp.`status` IS NOT NULL THEN urp.`status`
                    WHEN :uid_g1 = 995 THEN r.`status`
                    WHEN r.`sequence_no` = 1 THEN 'in_progress'
                    ELSE 'upcoming'
                END AS `status`, 
                r.`target_count`,
                CASE 
                    WHEN urp.`status` IS NOT NULL THEN urp.`completed_at`
                    WHEN :uid_g2 = 995 THEN r.`completed_at`
                    ELSE NULL
                END AS `completed_at`,
                (SELECT COUNT(*) FROM `ratings` rt WHERE rt.`round_id` = r.`round_id` AND rt.`total` IS NOT NULL AND (rt.`user_id` = :uid1 OR (rt.`user_id` IS NULL AND :uid_g3 = 995))) AS completed_scores_count,
                (SELECT COUNT(*) FROM `ratings` rt WHERE rt.`round_id` = r.`round_id` AND rt.`total` IS NULL AND (rt.`user_id` = :uid2 OR (rt.`user_id` IS NULL AND :uid_g4 = 995))) AS incomplete_scores_count,
                (SELECT COUNT(*) FROM `round_results` rr WHERE rr.`round_id` = r.`round_id` AND rr.`result` IN ('qualified', 'winner') AND (rr.`user_id` = :uid3 OR (rr.`user_id` IS NULL AND :uid_g5 = 995))) AS qualified_count,
                (SELECT COUNT(*) FROM `round_results` rr WHERE rr.`round_id` = r.`round_id` AND rr.`result` = 'eliminated' AND (rr.`user_id` = :uid4 OR (rr.`user_id` IS NULL AND :uid_g6 = 995))) AS eliminated_count
            FROM `rounds` r
            LEFT JOIN `user_round_progress` urp ON r.`round_id` = urp.`round_id` AND urp.`user_id` = :uid0
            ORDER BY r.`sequence_no` ASC
        ");
        $stmt->execute([
            ':uid0'   => $userId,
            ':uid_g1' => $userId,
            ':uid_g2' => $userId,
            ':uid1'   => $userId,
            ':uid_g3' => $userId,
            ':uid2'   => $userId,
            ':uid_g4' => $userId,
            ':uid3'   => $userId,
            ':uid_g5' => $userId,
            ':uid4'   => $userId,
            ':uid_g6' => $userId
        ]);
        return $stmt->fetchAll();
    }

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
 * Get a single tournament round by ID, optionally scoped to a user's progression state.
 */
function get_round_by_id(int $roundId, ?int $userId = null): ?array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM `rounds` WHERE `round_id` = :id");
    $stmt->execute([':id' => $roundId]);
    $res = $stmt->fetch();
    if (!$res) {
        return null;
    }
    if ($userId !== null && has_table('user_round_progress', $pdo)) {
        $urpStmt = $pdo->prepare("SELECT `status`, `completed_at` FROM `user_round_progress` WHERE `user_id` = :uid AND `round_id` = :rid");
        $urpStmt->execute([':uid' => $userId, ':rid' => $roundId]);
        $urp = $urpStmt->fetch();
        if ($urp) {
            $res['status'] = $urp['status'];
            $res['completed_at'] = $urp['completed_at'];
        } else {
            // Uninitialized progress defaults: Stage 1 in_progress, others upcoming
            $res['status'] = ((int)$res['sequence_no'] === 1) ? 'in_progress' : 'upcoming';
            $res['completed_at'] = null;
        }
    }
    return $res;
}

/**
 * Get the currently active tournament round (in_progress), or default to first round
 */
function get_active_round(?int $userId = null): array {
    $pdo = get_db_connection();

    if ($userId !== null) {
        $stmt = $pdo->prepare("
            SELECT r.* FROM `rounds` r
            JOIN `user_round_progress` urp ON r.`round_id` = urp.`round_id`
            WHERE urp.`user_id` = :uid AND urp.`status` = 'in_progress'
            ORDER BY r.`sequence_no` ASC 
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $activeUserRound = $stmt->fetch();
        if ($activeUserRound) {
            return $activeUserRound;
        }

        if ($userId === 995) {
            $active = $pdo->query("
                SELECT * FROM `rounds` 
                WHERE `status` = 'in_progress' 
                ORDER BY `sequence_no` ASC 
                LIMIT 1
            ")->fetch();
            if ($active) {
                return $active;
            }
        }

        // For any other user without in_progress user_round_progress: default to Round 1
        $first = $pdo->query("
            SELECT * FROM `rounds` 
            ORDER BY `sequence_no` ASC 
            LIMIT 1
        ")->fetch();
        if ($first) {
            $first['status'] = 'in_progress';
            return $first;
        }
    }

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
    int $perPage = 50,
    ?int $userId = null
): array {
    $pdo = get_db_connection();
    $page = max(1, $page);
    $perPage = max(10, min(200, $perPage));
    $offset = ($page - 1) * $perPage;

    $round = get_round_by_id($roundId, $userId);
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
    $isRoundCompleted = ($round && ($round['status'] ?? '') === 'completed');

    $fromJoinSql = "";
    $baseWhere = [];
    $baseParams = [
        ':curr_round_id'    => $roundId,
        ':curr_round_id_rr' => $roundId
    ];

    $delSqlS = song_deleted_sql('s', $pdo);
    $baseWhere[] = $delSqlS;
    $baseWhere[] = "s.`is_consolidated` = 0";

    if ($userId !== null) {
        $userScopeE = song_user_scope_sql($userId, 'e', $pdo);
        $userScopeS = song_user_scope_sql($userId, 's', $pdo);
        $baseWhere[] = $userScopeE;
        $baseWhere[] = $userScopeS;
    }

    $currRrUserJoin = ($userId !== null) 
        ? (($userId === 995) ? "AND (curr_rr.`user_id` = 995 OR curr_rr.`user_id` IS NULL)" : "AND curr_rr.`user_id` = :uid_curr_rr")
        : "";

    if ($seq === 1) {
        $sdUserJoin = ($userId !== null) 
            ? (($userId === 995) ? "AND (sd.`user_id` = 995 OR sd.`user_id` IS NULL)" : "AND sd.`user_id` = :uid_sd")
            : "";
        $rUserJoin = ($userId !== null) 
            ? (($userId === 995) ? "AND (r.`user_id` = 995 OR r.`user_id` IS NULL)" : "AND r.`user_id` = :uid_r")
            : "";
        $fromJoinSql = "
            FROM `afm_entries` e
            JOIN `songs` s ON e.`song_id` = s.`song_id`
            JOIN `song_discovery` sd ON s.`song_id` = sd.`song_id` AND sd.`status` = 'shortlisted' {$sdUserJoin}
            LEFT JOIN `ratings` r ON e.`entry_id` = r.`entry_id` AND r.`round_id` = :curr_round_id {$rUserJoin}
            LEFT JOIN `round_results` curr_rr ON e.`entry_id` = curr_rr.`entry_id` AND curr_rr.`round_id` = :curr_round_id_rr {$currRrUserJoin}
            LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
            LEFT JOIN `song_lyrics_ref` slr ON s.`song_id` = slr.`song_id`
            LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
        ";
        if ($userId !== null && $userId !== 995) {
            $baseParams[':uid_sd'] = $userId;
            $baseParams[':uid_r'] = $userId;
            $baseParams[':uid_curr_rr'] = $userId;
        }
        $baseWhere[] = "e.`status` != 'excluded'";
    } else {
        $prevRoundStmt = $pdo->prepare("SELECT `round_id` FROM `rounds` WHERE `sequence_no` = :prev_seq");
        $prevRoundStmt->execute([':prev_seq' => $seq - 1]);
        $prevRoundId = (int)$prevRoundStmt->fetchColumn();

        $prevRrUserJoin = ($userId !== null) 
            ? (($userId === 995) ? "AND (prev_rr.`user_id` = 995 OR prev_rr.`user_id` IS NULL)" : "AND prev_rr.`user_id` = :uid_prev_rr")
            : "";
        $rUserJoin = ($userId !== null) 
            ? (($userId === 995) ? "AND (r.`user_id` = 995 OR r.`user_id` IS NULL)" : "AND r.`user_id` = :uid_r")
            : "";
        $fromJoinSql = "
            FROM `afm_entries` e
            JOIN `round_results` prev_rr ON e.`entry_id` = prev_rr.`entry_id` 
                 AND prev_rr.`round_id` = :prev_round_id 
                 AND prev_rr.`result` IN ('qualified', 'winner')
                 {$prevRrUserJoin}
            JOIN `songs` s ON e.`song_id` = s.`song_id`
            LEFT JOIN `ratings` r ON e.`entry_id` = r.`entry_id` AND r.`round_id` = :curr_round_id
                 {$rUserJoin}
            LEFT JOIN `round_results` curr_rr ON e.`entry_id` = curr_rr.`entry_id` AND curr_rr.`round_id` = :curr_round_id_rr {$currRrUserJoin}
            LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
            LEFT JOIN `song_lyrics_ref` slr ON s.`song_id` = slr.`song_id`
            LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
        ";
        $baseParams[':prev_round_id'] = $prevRoundId;
        if ($userId !== null && $userId !== 995) {
            $baseParams[':uid_prev_rr'] = $userId;
            $baseParams[':uid_r'] = $userId;
            $baseParams[':uid_curr_rr'] = $userId;
        }
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
            (CASE 
                WHEN :is_round_completed = 1 AND r.`total` IS NOT NULL THEN 1 
                WHEN curr_rr.`result_id` IS NOT NULL THEN 1 
                ELSE 0 
            END) AS `is_finalized`,
            curr_rr.`result` AS `finalized_result`,
            curr_rr.`rank_position` AS `finalized_rank`,
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
    $dataStmt->bindValue(':is_round_completed', $isRoundCompleted ? 1 : 0, PDO::PARAM_INT);
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

/**
 * Format a UTC database timestamp in Asia/Kolkata local time.
 */
if (!function_exists('format_local_timestamp')) {
    function format_local_timestamp(?string $timestamp, string $format = 'M d, Y H:i'): string {
        if (empty($timestamp)) {
            return 'Never';
        }
        try {
            $dt = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format($format);
        } catch (\Throwable $e) {
            return htmlspecialchars((string)$timestamp);
        }
    }
}



