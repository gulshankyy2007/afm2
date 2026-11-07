<?php
/**
 * AFM#2 - Migration 014: Stage Finalization & Exceptional Administrative Corrections
 * 
 * Additive schema changes:
 * 1. Creates `tournament_admin_corrections` table to maintain an immutable audit trail
 *    of any exceptional technical or data-integrity corrections executed by administrators.
 * 2. Adds `corrected_by_user_id` and `correction_id` columns to `round_results_history`
 *    for bidirectional traceability between historical stage snapshots and correction logs.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

return function (PDO $pdo) {
    $has0900 = false;
    try {
        $vStmt = $pdo->query("SELECT VERSION()");
        $ver = (string)$vStmt->fetchColumn();
        if (stripos($ver, 'MariaDB') === false) {
            $has0900 = true;
        }
    } catch (\Throwable $e) {}

    $collate = $has0900 ? "COLLATE=utf8mb4_0900_ai_ci" : "";

    // 1. Create tournament_admin_corrections table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tournament_admin_corrections` (
            `correction_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `round_id` INT UNSIGNED NOT NULL,
            `target_user_id` INT UNSIGNED NOT NULL,
            `admin_user_id` INT UNSIGNED NOT NULL,
            `reason` TEXT NOT NULL,
            `iteration` INT UNSIGNED NOT NULL DEFAULT 1,
            `affected_qualifiers_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_tac_round_target` (`round_id`, `target_user_id`),
            KEY `idx_tac_admin` (`admin_user_id`),
            CONSTRAINT `fk_tac_round` FOREIGN KEY (`round_id`) REFERENCES `rounds` (`round_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_tac_target_user` FOREIGN KEY (`target_user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_tac_admin_user` FOREIGN KEY (`admin_user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 {$collate};
    ");

    // 2. Add audit linkage columns to round_results_history if missing
    $rrhCols = $pdo->query("SHOW COLUMNS FROM `round_results_history`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('corrected_by_user_id', $rrhCols, true)) {
        $pdo->exec("ALTER TABLE `round_results_history` ADD COLUMN `corrected_by_user_id` INT UNSIGNED NULL AFTER `reopened_at`");
    }

    if (!in_array('correction_id', $rrhCols, true)) {
        $pdo->exec("ALTER TABLE `round_results_history` ADD COLUMN `correction_id` INT UNSIGNED NULL AFTER `corrected_by_user_id`");
    }
};
