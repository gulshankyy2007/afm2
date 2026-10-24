<?php
/**
 * AFM#2 Database Migration: 005_add_media_user_url.php
 * Adds additive `user_url` column to `song_media` table to store the original user-supplied URL.
 * Strictly preserves the 2,475 existing songs and all existing catalogue data.
 */

return function(PDO $pdo): void {
    $stmt = $pdo->query("SHOW COLUMNS FROM `song_media` LIKE 'user_url'");
    if (!$stmt->fetch()) {
        $pdo->exec("
            ALTER TABLE `song_media`
            ADD COLUMN `user_url` VARCHAR(512) NULL DEFAULT NULL AFTER `service`;
        ");
    }
};
