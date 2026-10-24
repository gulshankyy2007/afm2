#!/usr/bin/env php
<?php
/**
 * AFM#2 - CLI Bulk YouTube Recording Discovery & Matcher
 *
 * Usage:
 *   php bin/bulk_youtube_matcher.php [options]
 *
 * Options:
 *   --limit=N         Total songs to process before exiting (default: process all)
 *   --batch-size=N    Number of songs per batch cycle (default: 10)
 *   --song-id=ID      Process and score a single specific song ID
 *   --force           Re-evaluate songs even if already linked (skips manual selections)
 *   --dry-run         Run discovery and scoring without saving to MySQL
 *   --reset           Reset job progress back to Song ID 1
 *   --stats           Show catalogue matching statistics and exit
 *   --help            Show this help screen
 */

define('AFM_APP', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/youtube_matcher.php';

$options = getopt('', [
    'limit::',
    'batch-size::',
    'song-id::',
    'force',
    'dry-run',
    'reset',
    'stats',
    'help'
]);

if (isset($options['help'])) {
    echo <<<HELP
AFM#2 — CLI Bulk YouTube Recording Discovery & Matcher

Usage:
  php bin/bulk_youtube_matcher.php [options]

Options:
  --limit=N         Total songs to process before exiting (default: all)
  --batch-size=N    Batch size per cycle (default: 10)
  --song-id=ID      Process and rank candidates for a single song ID
  --force           Re-evaluate already matched songs (preserves manual selections)
  --dry-run         Compute matches without writing changes to MySQL
  --reset           Reset active matching job progress back to Song #1
  --stats           Display current catalogue matching statistics and exit
  --help            Display this help screen

HELP;
    exit(0);
}

if (isset($options['stats'])) {
    $stats = YouTubeMatcher::get_matching_stats();
    echo "\n=== AFM#2 Catalogue Matching Statistics ===\n";
    echo "Total Songs:          " . number_format($stats['total_songs']) . "\n";
    echo "Matched Total:        " . number_format($stats['matched_total']) . " (" . round(($stats['matched_total'] / max(1, $stats['total_songs'])) * 100, 1) . "%)\n";
    echo "  - Auto High Score:  " . number_format($stats['auto_high_count']) . "\n";
    echo "  - Manual Protected: " . number_format($stats['manual_count']) . "\n";
    echo "Review Needed (Med):  " . number_format($stats['review_needed']) . "\n";
    echo "Unresolved (Low):     " . number_format($stats['low_unresolved']) . "\n";
    echo "Unmatched Total:      " . number_format($stats['unmatched_total']) . "\n";
    echo "Blocked Embeds:       " . number_format($stats['blocked_count']) . "\n";
    echo "===========================================\n\n";
    exit(0);
}

if (isset($options['reset'])) {
    YouTubeMatcher::reset_job();
    echo "Active matching job has been reset to Song ID 1.\n";
    exit(0);
}

// Single song mode
if (isset($options['song-id'])) {
    $songId = (int)$options['song-id'];
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT song_id, title, artist FROM songs WHERE song_id = ? AND is_consolidated = 0");
    $stmt->execute([$songId]);
    $song = $stmt->fetch();

    if (!$song) {
        fwrite(STDERR, "Error: Song #{$songId} not found in unconsolidated catalogue.\n");
        exit(1);
    }

    echo "\nScanning candidates for Song #{$songId}: \"{$song['title']}\" by {$song['artist']}...\n";
    $candidates = YouTubeMatcher::discover_candidates($song['title'], $song['artist']);
    $scored = YouTubeMatcher::score_and_rank_candidates($song['title'], $song['artist'], $candidates);

    echo "Found " . count($scored) . " candidates.\n\n";
    foreach ($scored as $idx => $c) {
        $rank = $idx + 1;
        $score = number_format($c['score_total'], 1);
        $conf = strtoupper($c['confidence']);
        $ver = $c['channel_is_artist'] ? " [Official Artist]" : ($c['channel_is_verified'] ? " [Verified]" : "");
        echo "#{$rank} [{$score}% - {$conf}] {$c['title']}\n";
        echo "   Video ID: {$c['video_id']} | Channel: {$c['channel_title']}{$ver}\n";
        echo "   Views: " . ($c['view_count'] ? number_format($c['view_count']) : "N/A") . " | Duration: {$c['duration']}\n";
        echo "   Breakdown: Title: " . round($c['score_title'], 1) . "/25, Artist: " . round($c['score_artist'], 1) . "/25, Version: " . round($c['score_version'], 1) . "/20, Channel: " . round($c['score_channel'], 1) . "/10, Date: " . round($c['score_date'], 1) . "/8, Views: " . round($c['score_views'], 1) . "/7, Dur: " . round($c['score_duration'], 1) . "/5\n";
        if (!empty($c['decision_notes'])) {
            echo "   Notes: {$c['decision_notes']}\n";
        }
        echo "\n";
    }

    if (!isset($options['dry-run'])) {
        YouTubeMatcher::save_candidates_for_song($songId, $scored);
        $auto = YouTubeMatcher::auto_select_high_confidence_match($songId, $scored);
        if ($auto['selected']) {
            echo "-> Auto-selected high-confidence match: {$auto['video_id']} (Score: " . round($auto['candidate']['score_total'], 1) . "%)\n";
        } else {
            echo "-> Not auto-selected: {$auto['reason']}\n";
        }
    }
    exit(0);
}

// Bulk batch mode
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 0;
$batchSize = isset($options['batch-size']) ? max(1, min(50, (int)$options['batch-size'])) : 10;
$force = isset($options['force']);
$dryRun = isset($options['dry-run']);

echo "=== AFM#2 Bulk YouTube Recording Matcher ===\n";
echo "Batch Size: {$batchSize}" . ($limit > 0 ? " | Limit: {$limit} songs" : " | Limit: Process entire catalogue") . "\n";
echo "Force Mode: " . ($force ? "YES" : "NO") . " | Dry-Run: " . ($dryRun ? "YES" : "NO") . "\n";
echo "--------------------------------------------\n";

$totalProcessed = 0;
$totalHighSelected = 0;
$totalReviewNeeded = 0;
$totalUnresolved = 0;

while (true) {
    if ($limit > 0 && $totalProcessed >= $limit) {
        echo "\nReached specified limit of {$limit} songs. Exiting.\n";
        break;
    }

    $currentBatchSize = $batchSize;
    if ($limit > 0 && ($totalProcessed + $currentBatchSize) > $limit) {
        $currentBatchSize = $limit - $totalProcessed;
    }

    $batchResult = YouTubeMatcher::process_batch($currentBatchSize, [
        'force' => $force,
        'dry_run' => $dryRun
    ]);

    $processed = $batchResult['processed'] ?? 0;
    if ($processed === 0) {
        echo "\nCatalogue processing complete or no remaining eligible songs found.\n";
        break;
    }

    $results = $batchResult['results'] ?? [];
    $highCount = count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'auto_high'));
    $reviewCount = count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'review_needed'));
    $lowCount = count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'unresolved'));
    $skippedCount = count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'skipped_manual'));

    $totalProcessed += $processed;
    $totalHighSelected += $highCount;
    $totalReviewNeeded += $reviewCount;
    $totalUnresolved += $lowCount;

    $job = $batchResult['job'] ?? [];
    $jobProcessed = $job['processed_songs'] ?? $totalProcessed;
    $lastSongId = $job['last_processed_song_id'] ?? 0;

    echo sprintf(
        "Batch complete: %d songs (Last ID: #%d) | High: %d, Review: %d, Low: %d, Skipped: %d [Job Total: %d]\n",
        $processed,
        $lastSongId,
        $highCount,
        $reviewCount,
        $lowCount,
        $skippedCount,
        $jobProcessed
    );

    if (!empty($batchResult['completed'])) {
        echo "\nAll songs in catalogue reached! Process complete.\n";
        break;
    }

    // Small courteous pause between batch requests
    usleep(500000); // 0.5s
}

echo "--------------------------------------------\n";
echo "Run Summary:\n";
echo "Songs Processed:     {$totalProcessed}\n";
echo "High Matches Linked: {$totalHighSelected}\n";
echo "Sent for Review:     {$totalReviewNeeded}\n";
echo "Left Unresolved:     {$totalUnresolved}\n";
echo "============================================\n\n";
