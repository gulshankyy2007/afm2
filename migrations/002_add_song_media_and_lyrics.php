<?php
/**
 * AFM#2 Database Migration: 002_add_song_media_and_lyrics.php
 * Adds additive tables for confirmed YouTube media references and LRCLIB lyrics candidate references.
 * Strictly preserves songs table and all existing catalogue data.
 */

return function(PDO $pdo): void {
    // 1. Confirmed Media References (YouTube video IDs, version types, embed status)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `song_media` (
            `media_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `song_id` INT UNSIGNED NOT NULL,
            `service` VARCHAR(32) NOT NULL DEFAULT 'youtube',
            `video_id` VARCHAR(64) NOT NULL,
            `title` VARCHAR(255) DEFAULT NULL,
            `channel_title` VARCHAR(255) DEFAULT NULL,
            `version_type` ENUM('original', 'live', 'acoustic', 'cover', 'remaster', 'other') NOT NULL DEFAULT 'original',
            `version_notes` VARCHAR(255) DEFAULT NULL,
            `embed_status` ENUM('allowed', 'blocked', 'unknown') NOT NULL DEFAULT 'allowed',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`media_id`),
            UNIQUE KEY `uq_song_media_service` (`song_id`, `service`),
            KEY `idx_song_id` (`song_id`),
            CONSTRAINT `fk_song_media_song` FOREIGN KEY (`song_id`) REFERENCES `songs` (`song_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");

    // 2. Lyrics Reference & On-Demand Cache (Tracks user confirmed LRCLIB candidate & avoids repeated requests)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `song_lyrics_ref` (
            `ref_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `song_id` INT UNSIGNED NOT NULL,
            `lrclib_id` INT UNSIGNED DEFAULT NULL,
            `track_name` VARCHAR(255) DEFAULT NULL,
            `artist_name` VARCHAR(255) DEFAULT NULL,
            `album_name` VARCHAR(255) DEFAULT NULL,
            `duration` DECIMAL(6,2) DEFAULT NULL,
            `instrumental` TINYINT(1) NOT NULL DEFAULT 0,
            `has_synced` TINYINT(1) NOT NULL DEFAULT 0,
            `source_url` VARCHAR(512) DEFAULT NULL,
            `cached_lyrics_plain` MEDIUMTEXT DEFAULT NULL,
            `cached_lyrics_synced` MEDIUMTEXT DEFAULT NULL,
            `status` ENUM('matched', 'not_found', 'instrumental', 'custom') NOT NULL DEFAULT 'matched',
            `cached_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ref_id`),
            UNIQUE KEY `uq_song_lyrics_ref` (`song_id`),
            KEY `idx_lyrics_song_id` (`song_id`),
            CONSTRAINT `fk_song_lyrics_song` FOREIGN KEY (`song_id`) REFERENCES `songs` (`song_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");
};
