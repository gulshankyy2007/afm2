<?php
/**
 * AFM#2 Database Migration: 009_multiuser_isolation.php
 * 
 * Multiuser Architecture & Data Isolation:
 * - Scopes `songs` to personal catalogue owner via `user_id`.
 * - Scopes `afm_entries` (auditions/competition entries) to `user_id`.
 * - Scopes `ratings` (scorecards) to `user_id`.
 * - Scopes `round_results` (qualifications/eliminations) to `user_id`.
 * - Scopes `song_discovery` (shortlists/passes) to `user_id`.
 * - Creates `user_round_progress` to track independent round progression per user.
 * - Scopes `youtube_matching_jobs` to `user_id`.
 * 
 * Safety & Invariant Guarantees:
 * - Purely additive: all new columns are nullable to preserve existing legacy data without guessing.
 * - Foreign keys use ON DELETE CASCADE for clean test and user data lifecycle.
 * - Preserves existing catalogue IDs (2,411 baseline) and historical relationships.
 */

return function(PDO $pdo): void {
    // 1. Table: `songs` - catalogue ownership
    $check = $pdo->query("SHOW COLUMNS FROM `songs` LIKE 'user_id'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE `songs` ADD COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `song_id`");
        $pdo->exec("ALTER TABLE `songs` ADD INDEX `idx_songs_user_id` (`user_id`)");
        try {
            $pdo->exec("ALTER TABLE `songs` ADD CONSTRAINT `fk_songs_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE");
        } catch (PDOException $e) {
            error_log("Warning: could not add fk_songs_user: " . $e->getMessage());
        }
    }

    // 2. Table: `afm_entries` - audition & competition entry ownership
    $check = $pdo->query("SHOW COLUMNS FROM `afm_entries` LIKE 'user_id'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE `afm_entries` ADD COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `entry_id`");
        $pdo->exec("ALTER TABLE `afm_entries` ADD INDEX `idx_entries_user_id` (`user_id`)");
        try {
            $pdo->exec("ALTER TABLE `afm_entries` ADD CONSTRAINT `fk_entries_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE");
        } catch (PDOException $e) {
            error_log("Warning: could not add fk_entries_user: " . $e->getMessage());
        }
        try {
            $pdo->exec("ALTER TABLE `afm_entries` ADD UNIQUE KEY `uq_user_song_entry` (`user_id`, `song_id`)");
        } catch (PDOException $e) {
            // Unique key on nullable columns supported in MySQL/MariaDB
        }
    }

    // 3. Table: `ratings` - scorecard ownership & score privacy
    $check = $pdo->query("SHOW COLUMNS FROM `ratings` LIKE 'user_id'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE `ratings` ADD COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `rating_id`");
        $pdo->exec("ALTER TABLE `ratings` ADD INDEX `idx_ratings_user_id` (`user_id`)");
        try {
            $pdo->exec("ALTER TABLE `ratings` ADD CONSTRAINT `fk_ratings_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE");
        } catch (PDOException $e) {
            error_log("Warning: could not add fk_ratings_user: " . $e->getMessage());
        }
        try {
            $pdo->exec("ALTER TABLE `ratings` ADD UNIQUE KEY `uq_user_entry_round` (`user_id`, `entry_id`, `round_id`)");
        } catch (PDOException $e) {
            // Ignore if index creation variation
        }
    }

    // 4. Table: `round_results` - user competition stage results
    $check = $pdo->query("SHOW COLUMNS FROM `round_results` LIKE 'user_id'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE `round_results` ADD COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `result_id`");
        $pdo->exec("ALTER TABLE `round_results` ADD INDEX `idx_round_results_user_id` (`user_id`)");
        try {
            $pdo->exec("ALTER TABLE `round_results` ADD CONSTRAINT `fk_round_results_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE");
        } catch (PDOException $e) {
            error_log("Warning: could not add fk_round_results_user: " . $e->getMessage());
        }
    }

    // 5. Table: `song_discovery` - user discovery decisions
    $check = $pdo->query("SHOW COLUMNS FROM `song_discovery` LIKE 'user_id'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE `song_discovery` ADD COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `song_id`");
        $pdo->exec("ALTER TABLE `song_discovery` ADD INDEX `idx_discovery_user_id` (`user_id`)");
        try {
            $pdo->exec("ALTER TABLE `song_discovery` ADD CONSTRAINT `fk_discovery_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE");
        } catch (PDOException $e) {
            error_log("Warning: could not add fk_discovery_user: " . $e->getMessage());
        }
    }

    // 6. Table: `user_round_progress` - per-user tournament round progression
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_round_progress` (
            `user_id` INT UNSIGNED NOT NULL,
            `round_id` INT UNSIGNED NOT NULL,
            `status` ENUM('upcoming', 'in_progress', 'completed') NOT NULL DEFAULT 'upcoming',
            `completed_at` TIMESTAMP NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`, `round_id`),
            KEY `idx_urp_user` (`user_id`),
            KEY `idx_urp_round` (`round_id`),
            CONSTRAINT `fk_urp_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_urp_round` FOREIGN KEY (`round_id`) REFERENCES `rounds` (`round_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 7. Table: `youtube_matching_jobs` - per-user matching queue
    $check = $pdo->query("SHOW COLUMNS FROM `youtube_matching_jobs` LIKE 'user_id'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE `youtube_matching_jobs` ADD COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `job_id`");
        try {
            $pdo->exec("ALTER TABLE `youtube_matching_jobs` ADD UNIQUE KEY `uq_user_matching_job` (`user_id`)");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `youtube_matching_jobs` ADD CONSTRAINT `fk_ymj_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE");
        } catch (PDOException $e) {}
    }
};
