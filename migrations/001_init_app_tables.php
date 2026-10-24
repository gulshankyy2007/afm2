<?php
/**
 * Migration 001: Initialize application support tables and rounds
 * Safe and additive only. Does not touch existing catalogue data.
 */

return function(PDO $pdo): void {
    // 1. app_users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `app_users` (
            `user_id` int unsigned NOT NULL AUTO_INCREMENT,
            `username` varchar(50) NOT NULL,
            `password_hash` varchar(255) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_login` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`user_id`),
            UNIQUE KEY `uq_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");

    // 2. login_rate_limits table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `login_rate_limits` (
            `attempt_id` int unsigned NOT NULL AUTO_INCREMENT,
            `ip_address` varchar(45) NOT NULL,
            `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`attempt_id`),
            KEY `idx_ip_time` (`ip_address`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");

    // 3. song_discovery table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `song_discovery` (
            `song_id` int unsigned NOT NULL,
            `status` enum('shortlisted','passed') NOT NULL,
            `reviewed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`song_id`),
            KEY `idx_discovery_status` (`status`),
            CONSTRAINT `fk_discovery_song` FOREIGN KEY (`song_id`) REFERENCES `songs` (`song_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");

    // Helper to safely add columns if they do not exist
    $ensureColumn = function(string $table, string $column, string $definition) use ($pdo) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = :table 
              AND COLUMN_NAME = :col
        ");
        $stmt->execute([':table' => $table, ':col' => $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    };

    // 4. Additive columns to rounds
    $ensureColumn('rounds', 'status', "enum('upcoming','in_progress','completed') NOT NULL DEFAULT 'upcoming'");
    $ensureColumn('rounds', 'target_count', "int unsigned NOT NULL DEFAULT 0");
    $ensureColumn('rounds', 'completed_at', "timestamp NULL DEFAULT NULL");

    // 5. Additive columns to round_results
    $ensureColumn('round_results', 'reason', "varchar(255) DEFAULT NULL");
    $ensureColumn('round_results', 'decided_at', "timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP");

    // 6. Seed tournament rounds
    $rounds = [
        [1, 'Formal Auditions', 1, 'in_progress', 0],
        [2, 'Top 200', 2, 'upcoming', 200],
        [3, 'Top 100', 3, 'upcoming', 100],
        [4, 'Top 50', 4, 'upcoming', 50],
        [5, 'Top 20', 5, 'upcoming', 20],
        [6, 'Top 10', 6, 'upcoming', 10],
        [7, 'Finals', 7, 'upcoming', 1]
    ];

    $seedStmt = $pdo->prepare("
        INSERT INTO `rounds` (`round_id`, `round_name`, `sequence_no`, `status`, `target_count`)
        VALUES (:id, :name, :seq, :status, :target)
        ON DUPLICATE KEY UPDATE 
            `sequence_no` = VALUES(`sequence_no`),
            `target_count` = VALUES(`target_count`)
    ");

    foreach ($rounds as $r) {
        $seedStmt->execute([
            ':id'     => $r[0],
            ':name'   => $r[1],
            ':seq'    => $r[2],
            ':status' => $r[3],
            ':target' => $r[4]
        ]);
    }
};
