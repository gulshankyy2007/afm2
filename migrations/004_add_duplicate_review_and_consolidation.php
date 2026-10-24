<?php
/**
 * AFM#2 Database Migration: 004_add_duplicate_review_and_consolidation.php
 * Adds:
 * 1. Additive columns to `songs` for soft-consolidation tracking (is_consolidated, consolidated_into_id, consolidated_at).
 * 2. `duplicate_reviews` table for storing user duplicate-review decisions (keep_both, excluded, deferred, consolidated).
 * 3. `song_consolidation_audit` table for durable snapshot storage and verified recovery.
 * Strictly preserves the 2,475 existing songs and all existing catalogue data.
 */

return function(PDO $pdo): void {
    // 1. Add soft-consolidation tracking columns to `songs` if not already present
    $stmt = $pdo->query("SHOW COLUMNS FROM `songs` LIKE 'is_consolidated'");
    if (!$stmt->fetch()) {
        $pdo->exec("
            ALTER TABLE `songs`
            ADD COLUMN `is_consolidated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `artist`,
            ADD COLUMN `consolidated_into_id` INT UNSIGNED NULL DEFAULT NULL AFTER `is_consolidated`,
            ADD COLUMN `consolidated_at` TIMESTAMP NULL DEFAULT NULL AFTER `consolidated_into_id`,
            ADD KEY `idx_songs_consolidated` (`is_consolidated`, `consolidated_into_id`),
            ADD CONSTRAINT `fk_songs_consolidated_into` FOREIGN KEY (`consolidated_into_id`) REFERENCES `songs` (`song_id`) ON DELETE SET NULL;
        ");
    }

    // 2. Duplicate Reviews tracking table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `duplicate_reviews` (
            `review_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `song_id_a` INT UNSIGNED NOT NULL,
            `song_id_b` INT UNSIGNED NOT NULL,
            `match_type` VARCHAR(50) NOT NULL,
            `confidence` VARCHAR(20) NOT NULL DEFAULT 'medium',
            `decision` ENUM('keep_both', 'excluded', 'deferred', 'consolidated') NOT NULL,
            `canonical_song_id` INT UNSIGNED NULL DEFAULT NULL,
            `notes` TEXT NULL,
            `reviewed_by` INT UNSIGNED NULL DEFAULT NULL,
            `reviewed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`review_id`),
            UNIQUE KEY `uq_duplicate_pair` (`song_id_a`, `song_id_b`),
            KEY `idx_dup_decision` (`decision`),
            KEY `idx_dup_match_type` (`match_type`),
            CONSTRAINT `fk_dup_song_a` FOREIGN KEY (`song_id_a`) REFERENCES `songs` (`song_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_dup_song_b` FOREIGN KEY (`song_id_b`) REFERENCES `songs` (`song_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_dup_canonical` FOREIGN KEY (`canonical_song_id`) REFERENCES `songs` (`song_id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");

    // 3. Durable Consolidation Audit & Recovery Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `song_consolidation_audit` (
            `audit_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `canonical_song_id` INT UNSIGNED NOT NULL,
            `consolidated_song_id` INT UNSIGNED NOT NULL,
            `snapshot_data` LONGTEXT NOT NULL,
            `consolidated_by` INT UNSIGNED NULL DEFAULT NULL,
            `consolidated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `is_recovered` TINYINT(1) NOT NULL DEFAULT 0,
            `recovered_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`audit_id`),
            KEY `idx_audit_canonical` (`canonical_song_id`),
            KEY `idx_audit_consolidated` (`consolidated_song_id`),
            KEY `idx_audit_recovered` (`is_recovered`),
            CONSTRAINT `fk_audit_canonical` FOREIGN KEY (`canonical_song_id`) REFERENCES `songs` (`song_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_audit_consolidated` FOREIGN KEY (`consolidated_song_id`) REFERENCES `songs` (`song_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");
};
