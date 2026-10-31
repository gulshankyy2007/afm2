<?php
/**
 * AFM#2 - Migration 015: Harmonize Tournament Status Collations (Optional Alignment)
 * 
 * Harmonizes the collation of `user_round_progress`.`status` and `rounds`.`status`
 * to `utf8mb4_unicode_ci` across MariaDB and MySQL environments.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

return function (PDO $pdo) {
    if (has_table('user_round_progress', $pdo)) {
        try {
            $pdo->exec("ALTER TABLE `user_round_progress` MODIFY COLUMN `status` ENUM('upcoming', 'in_progress', 'completed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upcoming'");
        } catch (\Throwable $e) {
            error_log("Migration 015 user_round_progress non-fatal note: " . $e->getMessage());
        }
    }

    if (has_table('rounds', $pdo)) {
        try {
            $pdo->exec("ALTER TABLE `rounds` MODIFY COLUMN `status` ENUM('upcoming', 'in_progress', 'completed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upcoming'");
        } catch (\Throwable $e) {
            error_log("Migration 015 rounds non-fatal note: " . $e->getMessage());
        }
    }
};
