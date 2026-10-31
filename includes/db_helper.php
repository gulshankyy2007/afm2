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
 * Safely check if the songs table has the deleted_at column
 */
function has_song_deleted_at_column(?PDO $pdo = null): bool {
    static $hasColumn = null;
    if ($hasColumn !== null) {
        return $hasColumn;
    }
    if ($pdo === null) {
        $pdo = get_db_connection();
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `songs` LIKE 'deleted_at'");
        $hasColumn = (bool)$stmt->fetch();
    } catch (\Throwable $e) {
        $hasColumn = false;
    }
    return $hasColumn;
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
 * assigns legacy unassigned songs to Gulshan (user_id = 995).
 */
function song_user_scope_sql(int $userId, string $tableAlias = '', ?PDO $pdo = null): string {
    $prefix = $tableAlias !== '' ? "`{$tableAlias}`." : '';
    if ($userId === 995) {
        return "({$prefix}`user_id` = 995 OR {$prefix}`user_id` IS NULL)";
    }
    return "{$prefix}`user_id` = " . (int)$userId;
}

/**
 * Get lightweight total active song count without computing full dashboard statistics.
 * Uses cached dashboard stats if available, or executes a single fast COUNT query.
 * Returns null if the database query fails, avoiding fabricated fallback counts.
 */
function get_catalogue_song_count(?int $userId = null, bool $forceRefresh = false): ?int {
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
        'title_asc'   => 's.`title` ASC, (s.`artist` IS NULL OR TRIM(s.`artist`) = \'\') ASC, s.`artist` ASC, s.`song_id` ASC',
        'title_desc'  => 's.`title` DESC, (s.`artist` IS NULL OR TRIM(s.`artist`) = \'\') ASC, s.`artist` ASC, s.`song_id` ASC',
        'artist_asc'  => '(s.`artist` IS NULL OR TRIM(s.`artist`) = \'\') ASC, s.`artist` ASC, s.`title` ASC, s.`song_id` ASC',
        'artist_desc' => '(s.`artist` IS NULL OR TRIM(s.`artist`) = \'\') ASC, s.`artist` DESC, s.`title` ASC, s.`song_id` ASC'
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
    if ($userId === null) {
        return ['success' => false, 'error' => 'Authentication required.'];
    }

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

    // SAFEGUARD: If moving from shortlisted to unreviewed or passed, check for active scores or tournament history
    if ($status !== 'shortlisted') {
        $scoreCheck = $pdo->prepare("
            SELECT COUNT(*) 
            FROM `ratings` r 
            JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` 
            WHERE e.`song_id` = :id 
              AND (r.`user_id` = :uid OR (r.`user_id` IS NULL AND :uid_g = 995))
              AND (r.`total` IS NOT NULL OR r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL)
        ");
        $scoreCheck->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId]);
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
              AND (rr.`user_id` = :uid OR (rr.`user_id` IS NULL AND :uid_g = 995))
        ");
        $rrCheck->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId]);
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
            WHERE (e.`user_id` = :uid OR (e.`user_id` IS NULL AND :uid_g = 995))
              AND e.`song_id` != :sid
              AND LOWER(TRIM(s.`title`)) = LOWER(TRIM(:title))
              AND LOWER(TRIM(COALESCE(s.`artist`, ''))) = LOWER(TRIM(:artist))
              AND e.`status` = 'auditioning'
            LIMIT 1
        ");
        $dupEntryStmt->execute([
            ':uid'    => $userId,
            ':uid_g'  => $userId,
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
 * If tournament history exists, soft-deletes/archives the song so historical scorecards
 * and results remain intact while excluding it from the active catalogue.
 * If no history exists, permanently deletes the song row.
 */
function delete_catalogue_song(int $songId, ?int $userId = null): array {
    if ($songId <= 0) {
        return ['success' => false, 'error' => 'Invalid song ID.'];
    }

    $pdo = get_db_connection();
    if ($userId === null && is_authenticated()) {
        $userId = get_current_user_id();
    }
    if ($userId === null) {
        return ['success' => false, 'error' => 'Authentication required.'];
    }

    // Verify song exists and belongs to user
    $delSql = song_deleted_sql('', $pdo);
    $userSql = song_user_scope_sql($userId, '', $pdo);
    $check = $pdo->prepare("SELECT `song_id`, `title`, `artist`, `user_id` FROM `songs` WHERE `song_id` = :id AND {$userSql} AND {$delSql}");
    $check->execute([':id' => $songId]);
    $song = $check->fetch(PDO::FETCH_ASSOC);
    if (!$song) {
        return ['success' => false, 'error' => 'Unauthorized: Song not found in your catalogue.'];
    }

    // SAFEGUARD 1: Check if song is currently on the Audition Shortlist
    $checkShortlist = $pdo->prepare("
        SELECT COUNT(*) 
        FROM `song_discovery` 
        WHERE `song_id` = :id 
          AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = 995))
          AND `status` = 'shortlisted'
    ");
    $checkShortlist->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId]);
    $isShortlisted = ((int)$checkShortlist->fetchColumn() > 0);
    if ($isShortlisted) {
        return [
            'success' => false,
            'blocked' => true,
            'reason'  => 'shortlisted',
            'error'   => "Cannot delete \"{$song['title']}\": This song is currently on your Audition Shortlist. Remove it from your shortlist first before deleting."
        ];
    }

    // SAFEGUARD 2: Check if song has an active audition score
    $checkActiveScore = $pdo->prepare("
        SELECT COUNT(*) 
        FROM `ratings` r 
        JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` 
        WHERE e.`song_id` = :id 
          AND (r.`user_id` = :uid OR (r.`user_id` IS NULL AND :uid_g = 995))
          AND (r.`total` IS NOT NULL OR r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL)
    ");
    $checkActiveScore->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId]);
    $hasActiveScore = ((int)$checkActiveScore->fetchColumn() > 0);
    if ($hasActiveScore) {
        return [
            'success' => false,
            'blocked' => true,
            'reason'  => 'scored',
            'error'   => "Cannot delete \"{$song['title']}\": This song has an active audition score. Please reset or clear its score first before deleting."
        ];
    }

    // SAFEGUARD 3: Check if song is referenced by completed tournament history
    $checkResults = $pdo->prepare("
        SELECT COUNT(*) 
        FROM `round_results` rr 
        JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id` 
        WHERE e.`song_id` = :id 
          AND (rr.`user_id` = :uid OR (rr.`user_id` IS NULL AND :uid_g = 995))
    ");
    $checkResults->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId]);
    $hasTournamentHistory = ((int)$checkResults->fetchColumn() > 0);
    if ($hasTournamentHistory) {
        return [
            'success' => false,
            'blocked' => true,
            'reason'  => 'tournament_history',
            'error'   => "Cannot delete \"{$song['title']}\": This song is referenced in your completed tournament history and cannot be deleted without compromising tournament integrity."
        ];
    }

    // Once cleaned up (unshortlisted, unscored, no historical tournament results): permanently delete
    try {
        $pdo->beginTransaction();

        // Remove auxiliary song assets and references
        $pdo->prepare("DELETE FROM `song_media` WHERE `song_id` = :id")->execute([':id' => $songId]);
        $pdo->prepare("DELETE FROM `song_lyrics_ref` WHERE `song_id` = :id")->execute([':id' => $songId]);
        $pdo->prepare("DELETE FROM `song_artwork` WHERE `song_id` = :id")->execute([':id' => $songId]);
        $pdo->prepare("DELETE FROM `song_youtube_candidates` WHERE `song_id` = :id")->execute([':id' => $songId]);
        try {
            $pdo->prepare("DELETE FROM `user_song_lyrics` WHERE `song_id` = :id AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = 995))")
                ->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId]);
        } catch (\Throwable $e) {}

        // Remove unscored competition entries & discovery records
        $delEntry = $pdo->prepare("DELETE FROM `afm_entries` WHERE `song_id` = :id AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = 995))");
        $delEntry->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId]);

        $delDisc = $pdo->prepare("DELETE FROM `song_discovery` WHERE `song_id` = :id AND (`user_id` = :uid OR (`user_id` IS NULL AND :uid_g = 995))");
        $delDisc->execute([':id' => $songId, ':uid' => $userId, ':uid_g' => $userId]);

        $delSong = $pdo->prepare("DELETE FROM `songs` WHERE `song_id` = :id AND `user_id` = :uid");
        $delSong->execute([':id' => $songId, ':uid' => $userId]);

        $pdo->commit();
        clear_dashboard_stats_cache();

        return [
            'success'  => true,
            'song_id'  => $songId,
            'title'    => $song['title'],
            'archived' => false,
            'message'  => "Song #{$songId} (\"{$song['title']}\") was permanently deleted from your catalogue."
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

    $inClause = implode(',', array_fill(0, count($cleanIds), '?'));
    $delSql = song_deleted_sql('', $pdo);
    $stmt = $pdo->prepare("SELECT `song_id`, `title`, `artist`, `user_id` FROM `songs` WHERE `song_id` IN ($inClause) AND {$delSql}");
    $stmt->execute($cleanIds);
    $foundSongs = [];
    $blockedSongs = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ((int)$row['user_id'] !== (int)$userId) {
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

    $checkBlockedStmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM `song_discovery` sd WHERE sd.`song_id` = :id1 AND (sd.`user_id` = :uid1 OR (sd.`user_id` IS NULL AND :uid_g1 = 995)) AND sd.`status` = 'shortlisted') AS is_shortlisted,
            (SELECT COUNT(*) FROM `ratings` r JOIN `afm_entries` e ON r.`entry_id` = e.`entry_id` WHERE e.`song_id` = :id2 AND (r.`user_id` = :uid2 OR (r.`user_id` IS NULL AND :uid_g2 = 995)) AND (r.`total` IS NOT NULL OR r.`melody` IS NOT NULL OR r.`lyrics` IS NOT NULL OR r.`vocals` IS NOT NULL OR r.`emotion` IS NOT NULL OR r.`replayability` IS NOT NULL)) AS active_ratings_count,
            (SELECT COUNT(*) FROM `round_results` rr JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id` WHERE e.`song_id` = :id3 AND (rr.`user_id` = :uid3 OR (rr.`user_id` IS NULL AND :uid_g3 = 995))) AS results_count
    ");

    $hardDeleteIds = [];

    foreach ($foundSongs as $id => $song) {
        $checkBlockedStmt->execute([
            ':id1' => $id, ':uid1' => $userId, ':uid_g1' => $userId,
            ':id2' => $id, ':uid2' => $userId, ':uid_g2' => $userId,
            ':id3' => $id, ':uid3' => $userId, ':uid_g3' => $userId
        ]);
        $hist = $checkBlockedStmt->fetch(PDO::FETCH_ASSOC);
        $isShortlisted = (int)($hist['is_shortlisted'] ?? 0);
        $ratingsCount  = (int)($hist['active_ratings_count'] ?? 0);
        $resultsCount  = (int)($hist['results_count'] ?? 0);

        if ($isShortlisted > 0) {
            $blockedSongs[] = [
                'song_id' => $id,
                'title'   => $song['title'],
                'artist'  => $song['artist'],
                'reason'  => 'Currently on Audition Shortlist. Remove from shortlist first.'
            ];
        } elseif ($ratingsCount > 0) {
            $blockedSongs[] = [
                'song_id' => $id,
                'title'   => $song['title'],
                'artist'  => $song['artist'],
                'reason'  => 'Has active audition score. Reset score first.'
            ];
        } elseif ($resultsCount > 0) {
            $blockedSongs[] = [
                'song_id' => $id,
                'title'   => $song['title'],
                'artist'  => $song['artist'],
                'reason'  => 'Referenced in completed tournament history.'
            ];
        } else {
            $hardDeleteIds[] = $id;
        }
    }

    if (empty($hardDeleteIds)) {
        return [
            'success'       => false,
            'blocked'       => true,
            'error'         => 'All selected songs were blocked from deletion due to active shortlist, audition scores, or tournament history.',
            'deleted_count' => 0,
            'blocked_count' => count($blockedSongs),
            'deleted_ids'   => [],
            'blocked_songs' => $blockedSongs
        ];
    }

    try {
        $pdo->beginTransaction();

        $delInClause = implode(',', array_fill(0, count($hardDeleteIds), '?'));
        $params = array_merge([$userId], $hardDeleteIds);
        $rawSongParams = $hardDeleteIds;

        // Clean auxiliary tables
        $pdo->prepare("DELETE FROM `song_media` WHERE `song_id` IN ($delInClause)")->execute($rawSongParams);
        $pdo->prepare("DELETE FROM `song_lyrics_ref` WHERE `song_id` IN ($delInClause)")->execute($rawSongParams);
        $pdo->prepare("DELETE FROM `song_artwork` WHERE `song_id` IN ($delInClause)")->execute($rawSongParams);
        $pdo->prepare("DELETE FROM `song_youtube_candidates` WHERE `song_id` IN ($delInClause)")->execute($rawSongParams);
        try {
            $pdo->prepare("DELETE FROM `user_song_lyrics` WHERE `user_id` = ? AND `song_id` IN ($delInClause)")->execute($params);
        } catch (\Throwable $e) {}

        $delEntries = $pdo->prepare("DELETE FROM `afm_entries` WHERE `user_id` = ? AND `song_id` IN ($delInClause)");
        $delEntries->execute($params);

        $delDisc = $pdo->prepare("DELETE FROM `song_discovery` WHERE `user_id` = ? AND `song_id` IN ($delInClause)");
        $delDisc->execute($params);

        $delSongs = $pdo->prepare("DELETE FROM `songs` WHERE `user_id` = ? AND `song_id` IN ($delInClause)");
        $delSongs->execute($params);

        $pdo->commit();
        clear_dashboard_stats_cache();

        $deletedCount = count($hardDeleteIds);
        $blockedCount = count($blockedSongs);

        $msg = "Successfully deleted {$deletedCount} song(s) from your catalogue.";
        if ($blockedCount > 0) {
            $msg .= " ({$blockedCount} song(s) were blocked due to active shortlist, scores, or tournament history).";
        }

        return [
            'success'       => true,
            'deleted_count' => $deletedCount,
            'blocked_count' => $blockedCount,
            'deleted_ids'   => $hardDeleteIds,
            'blocked_songs' => $blockedSongs,
            'message'       => $msg
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Database error deleting songs: ' . $e->getMessage()];
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

    // SAFEGUARD: Check if this entry and round have been finalized into completed round_results
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
 * Preview tournament stage qualification & cutoff analysis
 */
function preview_stage_advancement(int $fromRoundId, ?int $userId = null): array {
    $pdo = get_db_connection();

    // Fetch from round and next round
    $fromStmt = $pdo->prepare("SELECT * FROM `rounds` WHERE `round_id` = :id");
    $fromStmt->execute([':id' => $fromRoundId]);
    $fromRound = $fromStmt->fetch();
    if (!$fromRound) {
        return ['success' => false, 'error' => 'Current round not found.'];
    }

    if ($userId !== null) {
        $urpStmt = $pdo->prepare("SELECT `status` FROM `user_round_progress` WHERE `user_id` = :uid AND `round_id` = :r_id");
        $urpStmt->execute([':uid' => $userId, ':r_id' => $fromRoundId]);
        $urpStatus = $urpStmt->fetchColumn();
        if ($urpStatus) {
            $fromRound['status'] = $urpStatus;
        }
    }

    $nextStmt = $pdo->prepare("SELECT * FROM `rounds` WHERE `sequence_no` = :seq");
    $nextStmt->execute([':seq' => $fromRound['sequence_no'] + 1]);
    $nextRound = $nextStmt->fetch();
    if (!$nextRound) {
        return ['success' => false, 'error' => 'This is the final stage. No subsequent round exists.'];
    }

    if ($userId !== null) {
        $urpNext = $pdo->prepare("SELECT `status` FROM `user_round_progress` WHERE `user_id` = :uid AND `round_id` = :r_id");
        $urpNext->execute([':uid' => $userId, ':r_id' => $nextRound['round_id']]);
        $urpNextStatus = $urpNext->fetchColumn();
        if ($urpNextStatus) {
            $nextRound['status'] = $urpNextStatus;
        }
    }

    $rankData = get_round_rankings($fromRoundId, $userId);
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
function commit_stage_advancement(int $fromRoundId, array $qualifierEntryIds, ?int $userId = null): array {
    $pdo = get_db_connection();

    $preview = preview_stage_advancement($fromRoundId, $userId);
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

        // Concurrency mutex: lock the round status for update within transaction
        if ($userId !== null) {
            $lockStmt = $pdo->prepare("
                SELECT `status` 
                FROM `user_round_progress` 
                WHERE `user_id` = :uid AND `round_id` = :rid 
                FOR UPDATE
            ");
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
            $lockStmt = $pdo->prepare("
                SELECT `status` 
                FROM `rounds` 
                WHERE `round_id` = :rid 
                FOR UPDATE
            ");
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

        $rankData = get_round_rankings($fromRoundId, $userId);
        $rankings = $rankData['rankings'];

        // Prepare insert into round_results
        $resStmt = $pdo->prepare("
            INSERT INTO `round_results` (
                `entry_id`, 
                `round_id`, 
                `user_id`,
                `result`, 
                `rank_position`, 
                `reason`, 
                `decided_at`
            )
            VALUES (
                :entry_id, 
                :round_id, 
                :user_id,
                :result, 
                :rank_position, 
                :reason, 
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                `result`        = VALUES(`result`),
                `rank_position` = VALUES(`rank_position`),
                `reason`        = VALUES(`reason`),
                `user_id`       = COALESCE(`round_results`.`user_id`, VALUES(`user_id`)),
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
                ':user_id'       => $userId,
                ':result'        => $result,
                ':rank_position' => $row['calculated_rank'],
                ':reason'        => $reason
            ]);
        }

        if ($userId !== null) {
            // Update per-user progression
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
        }

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
function get_historical_results(?int $userId = null): array {
    $pdo = get_db_connection();

    $whereSql = "";
    $params = [];
    $ratingJoinCondition = "";
    if ($userId !== null) {
        if ($userId === 995) {
            $whereSql = "WHERE (rr.`user_id` = 995 OR rr.`user_id` IS NULL) AND (e.`user_id` = 995 OR e.`user_id` IS NULL) AND (s.`user_id` = 995 OR s.`user_id` IS NULL)";
            $ratingJoinCondition = "AND (rt.`user_id` = 995 OR rt.`user_id` IS NULL)";
        } else {
            $whereSql = "WHERE rr.`user_id` = :uid AND e.`user_id` = :uid_e AND s.`user_id` = :uid_s";
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
        LEFT JOIN `ratings` rt ON e.`entry_id` = rt.`entry_id` AND rt.`round_id` = r.`round_id` {$ratingJoinCondition}
        {$whereSql}
        ORDER BY r.`sequence_no` ASC, rr.`rank_position` ASC
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
    if ($userId !== null) {
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
    $baseParams = [
        ':curr_round_id' => $roundId
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
            LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
            LEFT JOIN `song_lyrics_ref` slr ON s.`song_id` = slr.`song_id`
            LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
        ";
        if ($userId !== null && $userId !== 995) {
            $baseParams[':uid_sd'] = $userId;
            $baseParams[':uid_r'] = $userId;
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
            LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
            LEFT JOIN `song_lyrics_ref` slr ON s.`song_id` = slr.`song_id`
            LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
        ";
        $baseParams[':prev_round_id'] = $prevRoundId;
        if ($userId !== null && $userId !== 995) {
            $baseParams[':uid_prev_rr'] = $userId;
            $baseParams[':uid_r'] = $userId;
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



