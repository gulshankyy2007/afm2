<?php
/**
 * AFM#2 Database Migration: 007_optimize_candidate_scores_index.php
 * 
 * [LOCAL / STAGING DATABASE OPTIMIZATION ONLY — NOT YET APPLIED TO PRODUCTION]
 * 
 * Adds composite index `idx_cand_song_score` on `song_youtube_candidates(song_id, score_total DESC)`
 * to accelerate candidate ordering and eliminate filesorts on score-ranked candidate lookups.
 * Strictly preserves existing tables, data, and tournament records.
 */

return function(PDO $pdo): void {
    $stmt = $pdo->query("SHOW INDEX FROM `song_youtube_candidates` WHERE `Key_name` = 'idx_cand_song_score'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `song_youtube_candidates` ADD INDEX `idx_cand_song_score` (`song_id`, `score_total` DESC);");
    }
};
