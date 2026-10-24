<?php
/**
 * AFM#2 Database Migration: 006_add_youtube_matching_and_candidates.php
 * Adds additive columns to `song_media` for match metadata,
 * creates `song_youtube_candidates` for candidate history and scores,
 * creates `youtube_matching_jobs` for tracking resumable bulk runs.
 * Strictly preserves the existing songs and all existing catalogue data.
 */

return function(PDO $pdo): void {
    // 1. Additive columns on song_media
    $colsToAdd = [
        'match_method'         => "ADD COLUMN `match_method` VARCHAR(32) NOT NULL DEFAULT 'manual' AFTER `embed_status`",
        'match_score'          => "ADD COLUMN `match_score` DECIMAL(5,2) NULL DEFAULT NULL AFTER `match_method`",
        'confidence'           => "ADD COLUMN `confidence` VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER `match_score`",
        'verification_status'  => "ADD COLUMN `verification_status` VARCHAR(20) NOT NULL DEFAULT 'unverified' AFTER `confidence`",
        'match_decision_notes' => "ADD COLUMN `match_decision_notes` VARCHAR(512) NULL DEFAULT NULL AFTER `verification_status`",
        'matched_at'           => "ADD COLUMN `matched_at` TIMESTAMP NULL DEFAULT NULL AFTER `match_decision_notes`",
        'is_manual'            => "ADD COLUMN `is_manual` TINYINT(1) NOT NULL DEFAULT 1 AFTER `matched_at`",
    ];

    foreach ($colsToAdd as $col => $sql) {
        $check = $pdo->query("SHOW COLUMNS FROM `song_media` LIKE '{$col}'");
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE `song_media` {$sql};");
        }
    }

    // 2. Table: song_youtube_candidates
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `song_youtube_candidates` (
            `candidate_id` int unsigned NOT NULL AUTO_INCREMENT,
            `song_id` int unsigned NOT NULL,
            `video_id` varchar(64) NOT NULL,
            `title` varchar(255) NOT NULL,
            `channel_title` varchar(255) NULL DEFAULT NULL,
            `duration` varchar(32) NULL DEFAULT NULL,
            `duration_seconds` int unsigned NULL DEFAULT NULL,
            `view_count` bigint unsigned NULL DEFAULT NULL,
            `published_at` varchar(64) NULL DEFAULT NULL,
            `channel_is_verified` tinyint(1) NOT NULL DEFAULT '0',
            `channel_is_artist` tinyint(1) NOT NULL DEFAULT '0',
            `thumbnail_url` varchar(512) NULL DEFAULT NULL,
            `score_total` decimal(5,2) NOT NULL DEFAULT '0.00',
            `score_title` decimal(5,2) NOT NULL DEFAULT '0.00',
            `score_artist` decimal(5,2) NOT NULL DEFAULT '0.00',
            `score_version` decimal(5,2) NOT NULL DEFAULT '0.00',
            `score_channel` decimal(5,2) NOT NULL DEFAULT '0.00',
            `score_date` decimal(5,2) NOT NULL DEFAULT '0.00',
            `score_views` decimal(5,2) NOT NULL DEFAULT '0.00',
            `score_duration` decimal(5,2) NOT NULL DEFAULT '0.00',
            `confidence` enum('high','medium','low') NOT NULL DEFAULT 'low',
            `decision_notes` varchar(512) NULL DEFAULT NULL,
            `is_selected` tinyint(1) NOT NULL DEFAULT '0',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`candidate_id`),
            UNIQUE KEY `uq_song_video` (`song_id`, `video_id`),
            KEY `idx_cand_song` (`song_id`),
            KEY `idx_cand_confidence` (`confidence`),
            KEY `idx_cand_score` (`score_total`),
            CONSTRAINT `fk_candidates_song` FOREIGN KEY (`song_id`) REFERENCES `songs` (`song_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");

    // 3. Table: youtube_matching_jobs
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `youtube_matching_jobs` (
            `job_id` int unsigned NOT NULL AUTO_INCREMENT,
            `job_name` varchar(100) NOT NULL DEFAULT 'Bulk YouTube Discovery',
            `status` enum('idle','running','paused','completed','failed') NOT NULL DEFAULT 'idle',
            `total_songs` int unsigned NOT NULL DEFAULT '0',
            `processed_songs` int unsigned NOT NULL DEFAULT '0',
            `matched_high_count` int unsigned NOT NULL DEFAULT '0',
            `review_medium_count` int unsigned NOT NULL DEFAULT '0',
            `unresolved_low_count` int unsigned NOT NULL DEFAULT '0',
            `failed_count` int unsigned NOT NULL DEFAULT '0',
            `skipped_manual_count` int unsigned NOT NULL DEFAULT '0',
            `last_processed_song_id` int unsigned NOT NULL DEFAULT '0',
            `started_at` timestamp NULL DEFAULT NULL,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `completed_at` timestamp NULL DEFAULT NULL,
            `error_log` mediumtext NULL,
            `settings_json` text NULL,
            PRIMARY KEY (`job_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");
};
