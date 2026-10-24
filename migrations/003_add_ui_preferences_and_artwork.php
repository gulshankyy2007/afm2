<?php
/**
 * AFM#2 Database Migration: 003_add_ui_preferences_and_artwork.php
 * Adds user_preferences table for persistent theme & background settings,
 * and song_artwork table for confirmed/cached Cover Art Archive and Openverse imagery.
 * Strictly preserves songs table and all existing catalogue data.
 */

return function(PDO $pdo): void {
    // 1. User UI Preferences (Theme, Background Style, Layout, Overlay Opacity)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_preferences` (
            `user_id` INT UNSIGNED NOT NULL,
            `theme` VARCHAR(32) NOT NULL DEFAULT 'midnight_ember',
            `bg_style` VARCHAR(32) NOT NULL DEFAULT 'atmospheric',
            `player_layout` VARCHAR(32) NOT NULL DEFAULT 'expanded',
            `bg_overlay_opacity` INT NOT NULL DEFAULT 70,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`),
            CONSTRAINT `fk_user_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");

    // 2. Song Artwork Cache & Confirmation Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `song_artwork` (
            `artwork_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `song_id` INT UNSIGNED NOT NULL,
            `source` ENUM('confirmed', 'musicbrainz', 'openverse', 'preset', 'none') NOT NULL DEFAULT 'none',
            `image_url` VARCHAR(512) DEFAULT NULL,
            `thumbnail_url` VARCHAR(512) DEFAULT NULL,
            `title` VARCHAR(255) DEFAULT NULL,
            `creator` VARCHAR(255) DEFAULT NULL,
            `license` VARCHAR(64) DEFAULT NULL,
            `license_url` VARCHAR(512) DEFAULT NULL,
            `attribution_url` VARCHAR(512) DEFAULT NULL,
            `is_confirmed` TINYINT(1) NOT NULL DEFAULT 0,
            `status` ENUM('matched', 'not_found', 'manual') NOT NULL DEFAULT 'matched',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`artwork_id`),
            UNIQUE KEY `uq_song_artwork_song` (`song_id`),
            KEY `idx_artwork_song_id` (`song_id`),
            CONSTRAINT `fk_song_artwork_song` FOREIGN KEY (`song_id`) REFERENCES `songs` (`song_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");
};
