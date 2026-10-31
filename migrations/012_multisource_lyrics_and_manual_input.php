<?php
/**
 * AFM#2 Database Migration: 012_multisource_lyrics_and_manual_input.php
 * Adds user_song_lyrics for user-scoped private manual lyrics notes
 * and expands song_lyrics_ref with provider attribution and error details.
 */

return function(PDO $pdo): void {
    $execTable = function(string $sqlModern, string $sqlFallback) use ($pdo): void {
        try {
            $pdo->exec($sqlModern);
        } catch (PDOException $e) {
            $pdo->exec($sqlFallback);
        }
    };

    // 1. Create user_song_lyrics table for private user-scoped manual lyrics
    $execTable("
        CREATE TABLE IF NOT EXISTS `user_song_lyrics` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `song_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `lyrics_text` MEDIUMTEXT NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_song_lyrics` (`user_id`, `song_id`),
            KEY `idx_user_lyrics_song` (`song_id`),
            CONSTRAINT `fk_user_lyrics_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_user_lyrics_song` FOREIGN KEY (`song_id`) REFERENCES `songs` (`song_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ", "
        CREATE TABLE IF NOT EXISTS `user_song_lyrics` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `song_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `lyrics_text` MEDIUMTEXT NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_song_lyrics` (`user_id`, `song_id`),
            KEY `idx_user_lyrics_song` (`song_id`),
            CONSTRAINT `fk_user_lyrics_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_user_lyrics_song` FOREIGN KEY (`song_id`) REFERENCES `songs` (`song_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 2. Add provider and error_type columns to song_lyrics_ref if missing
    $hasCol = function(string $table, string $col) use ($pdo): bool {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
        return (bool)$stmt->fetch();
    };

    if (!$hasCol('song_lyrics_ref', 'provider')) {
        $pdo->exec("ALTER TABLE `song_lyrics_ref` ADD COLUMN `provider` VARCHAR(32) NOT NULL DEFAULT 'lrclib' AFTER `status`");
    }

    if (!$hasCol('song_lyrics_ref', 'error_type')) {
        $pdo->exec("ALTER TABLE `song_lyrics_ref` ADD COLUMN `error_type` VARCHAR(32) NULL DEFAULT NULL AFTER `provider`");
    }
};
