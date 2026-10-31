<?php
/**
 * AFM#2 - Migration 013: Tournament Manual Advancement, Seeding & Stage Decision History
 * 
 * Additive schema changes:
 * 1. Creates `round_results_history` table to store immutable snapshots of qualification
 *    decisions whenever a tournament stage is reopened or revised.
 * 2. Adds `manual_order`, `song_title`, `song_artist`, and `score_total` snapshot columns
 *    to `round_results` to preserve manual seeding and song identities independently of
 *    active catalogue lifecycle.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

return function (PDO $pdo) {
    // 1. Create round_results_history table with collation fallback
    $has0900 = false;
    try {
        $vStmt = $pdo->query("SELECT VERSION()");
        $ver = (string)$vStmt->fetchColumn();
        if (stripos($ver, 'MariaDB') === false) {
            $has0900 = true;
        }
    } catch (\Throwable $e) {}

    $collate = $has0900 ? "COLLATE=utf8mb4_0900_ai_ci" : "";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `round_results_history` (
            `history_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `round_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `iteration` INT UNSIGNED NOT NULL DEFAULT 1,
            `entry_id` INT UNSIGNED NOT NULL,
            `song_id` INT UNSIGNED NULL,
            `song_title` VARCHAR(255) NOT NULL,
            `song_artist` VARCHAR(255) NULL,
            `result` ENUM('qualified','eliminated','winner') NOT NULL,
            `rank_position` INT UNSIGNED NULL,
            `manual_order` INT UNSIGNED NULL,
            `score_total` TINYINT UNSIGNED NULL,
            `reason` VARCHAR(255) NULL,
            `decided_at` TIMESTAMP NOT NULL,
            `reopened_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_rrh_user_round` (`user_id`, `round_id`),
            KEY `idx_rrh_entry` (`entry_id`),
            CONSTRAINT `fk_rrh_round` FOREIGN KEY (`round_id`) REFERENCES `rounds` (`round_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_rrh_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 {$collate};
    ");

    // 2. Add snapshot & manual ordering columns to round_results if missing
    $cols = $pdo->query("SHOW COLUMNS FROM `round_results`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('manual_order', $cols, true)) {
        $pdo->exec("ALTER TABLE `round_results` ADD COLUMN `manual_order` INT UNSIGNED NULL AFTER `rank_position`");
    }

    if (!in_array('song_title', $cols, true)) {
        $pdo->exec("ALTER TABLE `round_results` ADD COLUMN `song_title` VARCHAR(255) NULL AFTER `manual_order`");
    }

    if (!in_array('song_artist', $cols, true)) {
        $pdo->exec("ALTER TABLE `round_results` ADD COLUMN `song_artist` VARCHAR(255) NULL AFTER `song_title`");
    }

    if (!in_array('score_total', $cols, true)) {
        $pdo->exec("ALTER TABLE `round_results` ADD COLUMN `score_total` TINYINT UNSIGNED NULL AFTER `song_artist`");
    }

    // 3. Backfill existing snapshot columns in round_results for historical integrity
    $pdo->exec("
        UPDATE `round_results` rr
        JOIN `afm_entries` e ON rr.`entry_id` = e.`entry_id`
        JOIN `songs` s ON e.`song_id` = s.`song_id`
        LEFT JOIN `ratings` rt ON e.`entry_id` = rt.`entry_id` AND rt.`round_id` = rr.`round_id`
        SET 
            rr.`song_title` = COALESCE(rr.`song_title`, s.`title`),
            rr.`song_artist` = COALESCE(rr.`song_artist`, s.`artist`),
            rr.`score_total` = COALESCE(rr.`score_total`, rt.`total`),
            rr.`manual_order` = COALESCE(rr.`manual_order`, rr.`rank_position`)
        WHERE rr.`song_title` IS NULL
    ");
};
