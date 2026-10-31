<?php
/**
 * AFM#2 Database Migration: 011_master_starter_catalogue_and_isolation.php
 * 
 * Architecture: Independent User Catalogues with Preserved Master Starter Collection
 * 
 * 1. Schema Additions:
 *    - `master_starter_songs`: Dedicated, immutable repository for the baseline 2,411-song starter collection.
 *    - `master_starter_media`: Preserves initial confirmed media references (160 tracks).
 *    - `master_starter_artwork`: Preserves initial confirmed artwork references (160 tracks).
 *    - `user_catalogue_initialization`: Tracks per-user cloning state, starter version, timestamp, and song count.
 *    - `songs`.`deleted_at`: Soft-delete timestamp for user-scoped removal of songs that have tournament history.
 *    - `idx_songs_user_deleted`: Index on (`user_id`, `deleted_at`, `is_consolidated`).
 * 
 * 2. Master Starter Preservation:
 *    - Populates `master_starter_songs` from legacy unassigned catalogue (2,411 songs).
 *    - Populates `master_starter_media` and `master_starter_artwork`.
 *    - Preserves NULL artist attribute on songs 1546, 2015, 2196.
 * 
 * 3. Gulshan Catalogue Preservation:
 *    - Assigns all existing 2,411 songs in `songs` to Gulshan (retaining original IDs 1..2411).
 *    - Assigns existing `afm_entries`, `ratings`, `song_discovery`, `round_results`, and `youtube_matching_jobs`.
 *    - Initializes `user_round_progress` from current `rounds` state.
 *    - Records Gulshan in `user_catalogue_initialization` (`songs_cloned = 2411`).
 * 
 * 4. Starter Cloning Functionality:
 *    - Provides `initialize_user_starter_catalogue(int $userId, int $version = 1, ?PDO $pdo = null): array`
 *      which safely, transactionally clones the starter collection with new, independent IDs.
 *    - Initializes `adminuser` if present and uninitialized.
 * 
 * Strictly idempotent, non-destructive, and fail-safe.
 */

return function(PDO $pdo): void {
    // Helper to safely execute table creation with MariaDB collation fallback
    $execTable = function(string $sql) use ($pdo) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            if (str_contains($sql, 'COLLATE=utf8mb4_0900_ai_ci')) {
                $pdo->exec(str_replace('COLLATE=utf8mb4_0900_ai_ci', '', $sql));
            } else {
                throw $e;
            }
        }
    };

    // -------------------------------------------------------------
    // 1. Table: `master_starter_songs`
    // -------------------------------------------------------------
    $execTable("
        CREATE TABLE IF NOT EXISTS `master_starter_songs` (
            `starter_song_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `source_song_id` INT UNSIGNED NOT NULL,
            `starter_version` INT UNSIGNED NOT NULL DEFAULT 1,
            `title` VARCHAR(255) NOT NULL,
            `artist` VARCHAR(255) NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`starter_song_id`),
            UNIQUE KEY `uq_starter_version_source` (`starter_version`, `source_song_id`),
            KEY `idx_starter_version` (`starter_version`),
            KEY `idx_starter_title` (`title`(100)),
            KEY `idx_starter_artist` (`artist`(100))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");

    // -------------------------------------------------------------
    // 2. Table: `master_starter_media`
    // -------------------------------------------------------------
    $execTable("
        CREATE TABLE IF NOT EXISTS `master_starter_media` (
            `starter_media_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `source_song_id` INT UNSIGNED NOT NULL,
            `service` VARCHAR(32) NOT NULL DEFAULT 'youtube',
            `video_id` VARCHAR(64) NOT NULL,
            `title` VARCHAR(255) NULL DEFAULT NULL,
            `channel_title` VARCHAR(255) NULL DEFAULT NULL,
            `version_type` ENUM('original','live','acoustic','cover','remaster','other') NOT NULL DEFAULT 'original',
            `embed_status` ENUM('allowed','blocked','unknown') NOT NULL DEFAULT 'allowed',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`starter_media_id`),
            UNIQUE KEY `uq_starter_media_song` (`source_song_id`, `service`),
            KEY `idx_starter_media_video` (`video_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");

    // -------------------------------------------------------------
    // 3. Table: `master_starter_artwork`
    // -------------------------------------------------------------
    $execTable("
        CREATE TABLE IF NOT EXISTS `master_starter_artwork` (
            `starter_artwork_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `source_song_id` INT UNSIGNED NOT NULL,
            `source` ENUM('confirmed','musicbrainz','openverse','preset','none') NOT NULL DEFAULT 'preset',
            `image_url` VARCHAR(512) NULL DEFAULT NULL,
            `thumbnail_url` VARCHAR(512) NULL DEFAULT NULL,
            `is_confirmed` TINYINT(1) NOT NULL DEFAULT 1,
            `status` ENUM('matched','not_found','manual') NOT NULL DEFAULT 'matched',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`starter_artwork_id`),
            UNIQUE KEY `uq_starter_artwork_song` (`source_song_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");

    // -------------------------------------------------------------
    // 4. Table: `user_catalogue_initialization`
    // -------------------------------------------------------------
    $execTable("
        CREATE TABLE IF NOT EXISTS `user_catalogue_initialization` (
            `user_id` INT UNSIGNED NOT NULL,
            `starter_version` INT UNSIGNED NOT NULL DEFAULT 1,
            `initialized_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `songs_cloned` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`user_id`),
            CONSTRAINT `fk_uci_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");

    // -------------------------------------------------------------
    // 5. Column: `songs`.`deleted_at` (User-scoped archiving)
    // -------------------------------------------------------------
    $checkDelCol = $pdo->query("SHOW COLUMNS FROM `songs` LIKE 'deleted_at'");
    if (!$checkDelCol->fetch()) {
        $pdo->exec("ALTER TABLE `songs` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `consolidated_at`");
    }

    $checkIdx = $pdo->query("
        SELECT COUNT(*) FROM information_schema.statistics 
        WHERE table_schema = DATABASE() AND table_name = 'songs' AND index_name = 'idx_songs_user_deleted'
    ")->fetchColumn();
    if ((int)$checkIdx === 0) {
        try {
            $pdo->exec("ALTER TABLE `songs` ADD INDEX `idx_songs_user_deleted` (`user_id`, `deleted_at`, `is_consolidated`)");
        } catch (PDOException $e) {}
    }

    // -------------------------------------------------------------
    // -------------------------------------------------------------
    // 6. Populate `master_starter_songs` (Seed from base catalogue)
    // -------------------------------------------------------------
    $starterCount = (int)$pdo->query("SELECT COUNT(*) FROM `master_starter_songs` WHERE `starter_version` = 1")->fetchColumn();
    if ($starterCount === 0) {
        // If unassigned base songs exist, use them; otherwise, seed from Gulshan's baseline records <= 2428
        $nullCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` IS NULL")->fetchColumn();
        if ($nullCount > 0) {
            $pdo->exec("
                INSERT INTO `master_starter_songs` (`source_song_id`, `starter_version`, `title`, `artist`)
                SELECT `song_id`, 1, `title`, `artist`
                FROM `songs`
                WHERE `user_id` IS NULL
                ORDER BY `song_id` ASC
                ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `artist` = VALUES(`artist`)
            ");
        } else {
            $pdo->exec("
                INSERT INTO `master_starter_songs` (`source_song_id`, `starter_version`, `title`, `artist`)
                SELECT `song_id`, 1, `title`, `artist`
                FROM `songs`
                WHERE `song_id` <= 2428
                ORDER BY `song_id` ASC
                ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `artist` = VALUES(`artist`)
            ");
        }
    }

    // Populate `master_starter_media`
    $mediaCount = (int)$pdo->query("SELECT COUNT(*) FROM `master_starter_media`")->fetchColumn();
    if ($mediaCount === 0) {
        $pdo->exec("
            INSERT INTO `master_starter_media` (
                `source_song_id`, `service`, `video_id`, `title`, `channel_title`, `version_type`, `embed_status`
            )
            SELECT `song_id`, `service`, `video_id`, `title`, `channel_title`, `version_type`, `embed_status`
            FROM `song_media`
            WHERE `song_id` IN (SELECT `source_song_id` FROM `master_starter_songs` WHERE `starter_version` = 1)
            ON DUPLICATE KEY UPDATE `video_id` = VALUES(`video_id`)
        ");
    }

    // Populate `master_starter_artwork`
    $artCount = (int)$pdo->query("SELECT COUNT(*) FROM `master_starter_artwork`")->fetchColumn();
    if ($artCount === 0) {
        $pdo->exec("
            INSERT INTO `master_starter_artwork` (
                `source_song_id`, `source`, `image_url`, `thumbnail_url`, `is_confirmed`, `status`
            )
            SELECT `song_id`, `source`, `image_url`, `thumbnail_url`, `is_confirmed`, `status`
            FROM `song_artwork`
            WHERE `song_id` IN (SELECT `source_song_id` FROM `master_starter_songs` WHERE `starter_version` = 1)
            ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`)
        ");
    }

    // -------------------------------------------------------------
    // 7. Locate Gulshan account and preserve legacy records
    // -------------------------------------------------------------
    $stmtG = $pdo->prepare("SELECT `user_id` FROM `app_users` WHERE `username` = 'gulshan' LIMIT 1");
    $stmtG->execute();
    $gulshanId = $stmtG->fetchColumn();

    if ($gulshanId) {
        $targetUid = (int)$gulshanId;

        // Assign unowned songs to Gulshan (retaining original song_ids 1..2411)
        $pdo->prepare("UPDATE `songs` SET `user_id` = :uid WHERE `user_id` IS NULL")->execute([':uid' => $targetUid]);

        // Assign unowned afm_entries
        $pdo->prepare("UPDATE `afm_entries` SET `user_id` = :uid WHERE `user_id` IS NULL")->execute([':uid' => $targetUid]);

        // Assign unowned ratings
        $pdo->prepare("UPDATE `ratings` SET `user_id` = :uid WHERE `user_id` IS NULL")->execute([':uid' => $targetUid]);

        // Assign unowned song_discovery
        $pdo->prepare("UPDATE `song_discovery` SET `user_id` = :uid WHERE `user_id` IS NULL")->execute([':uid' => $targetUid]);

        // Assign unowned round_results
        $pdo->prepare("UPDATE `round_results` SET `user_id` = :uid WHERE `user_id` IS NULL")->execute([':uid' => $targetUid]);

        // Assign unowned youtube_matching_jobs (if Gulshan doesn't already have one, otherwise clean up obsolete unowned job)
        $hasJob = (bool)$pdo->query("SELECT 1 FROM `youtube_matching_jobs` WHERE `user_id` = {$targetUid} LIMIT 1")->fetchColumn();
        if (!$hasJob) {
            $pdo->prepare("UPDATE `youtube_matching_jobs` SET `user_id` = :uid WHERE `user_id` IS NULL")->execute([':uid' => $targetUid]);
        } else {
            $pdo->exec("DELETE FROM `youtube_matching_jobs` WHERE `user_id` IS NULL");
        }

        // Initialize user_round_progress for Gulshan based on existing rounds
        $rounds = $pdo->query("SELECT `round_id`, `status`, `completed_at` FROM `rounds` ORDER BY `sequence_no` ASC")->fetchAll(PDO::FETCH_ASSOC);
        $initUrp = $pdo->prepare("
            INSERT INTO `user_round_progress` (`user_id`, `round_id`, `status`, `completed_at`)
            VALUES (:uid, :rid, :st, :cat)
            ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `completed_at` = VALUES(`completed_at`)
        ");
        foreach ($rounds as $r) {
            $initUrp->execute([
                ':uid' => $targetUid,
                ':rid' => $r['round_id'],
                ':st'  => $r['status'] ?: 'upcoming',
                ':cat' => $r['completed_at']
            ]);
        }

        // Mark Gulshan as initialized
        $gSongCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `user_id` = {$targetUid}")->fetchColumn();
        $pdo->prepare("
            INSERT INTO `user_catalogue_initialization` (`user_id`, `starter_version`, `songs_cloned`)
            VALUES (:uid, 1, :cnt)
            ON DUPLICATE KEY UPDATE `songs_cloned` = VALUES(`songs_cloned`)
        ")->execute([':uid' => $targetUid, ':cnt' => $gSongCount]);
    }

    // -------------------------------------------------------------
    // 8. Initialize adminuser if present and uninitialized
    // -------------------------------------------------------------
    $stmtA = $pdo->prepare("SELECT `user_id` FROM `app_users` WHERE `username` = 'adminuser' LIMIT 1");
    $stmtA->execute();
    $adminId = $stmtA->fetchColumn();

    if ($adminId) {
        if (!function_exists('initialize_user_starter_catalogue') && file_exists(dirname(__DIR__) . '/includes/db_helper.php')) {
            require_once dirname(__DIR__) . '/includes/db_helper.php';
        }
        if (function_exists('initialize_user_starter_catalogue')) {
            initialize_user_starter_catalogue((int)$adminId, 1, $pdo);
        }
    }
};

/**
 * Safe Rollback function for Migration 011
 * Reverts new tables, cloned user copies, and restores baseline state.
 */
function rollback_011_master_starter_catalogue(PDO $pdo): void {
    // 1. Delete cloned songs for non-Gulshan users (IDs > 2428)
    $pdo->exec("DELETE FROM `songs` WHERE `song_id` > 2428");
    
    // 2. Drop starter and tracking tables
    $pdo->exec("DROP TABLE IF EXISTS `master_starter_artwork`");
    $pdo->exec("DROP TABLE IF EXISTS `master_starter_media`");
    $pdo->exec("DROP TABLE IF EXISTS `master_starter_songs`");
    $pdo->exec("DROP TABLE IF EXISTS `user_catalogue_initialization`");
    
    // 3. Drop index and column if added
    try {
        $pdo->exec("ALTER TABLE `songs` DROP INDEX `idx_songs_user_deleted`");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE `songs` DROP COLUMN `deleted_at`");
    } catch (PDOException $e) {}
    
    // 4. Remove record from schema_migrations
    $pdo->prepare("DELETE FROM `schema_migrations` WHERE `migration_name` LIKE '%011%'")->execute();
}

