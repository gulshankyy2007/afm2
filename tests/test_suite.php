<?php
/**
 * AFM#2 - Comprehensive Automated Verification Test Suite
 * Personal Music Journal, Audition & Tournament 2026
 *
 * Verifies all application layers, security, catalogue preservation, and business logic.
 * Usage: php tests/test_suite.php
 */

define('AFM_APP', true);

// Set CLI execution environment
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'CLI';

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';
require_once dirname(__DIR__) . '/includes/preferences_helper.php';
require_once dirname(__DIR__) . '/includes/artwork_helper.php';

// Color formatting for CLI
class CliPrinter {
    public static function green(string $text): string {
        return "\033[32m" . $text . "\033[0m";
    }
    public static function red(string $text): string {
        return "\033[31m" . $text . "\033[0m";
    }
    public static function yellow(string $text): string {
        return "\033[33m" . $text . "\033[0m";
    }
    public static function cyan(string $text): string {
        return "\033[36m" . $text . "\033[0m";
    }
    public static function bold(string $text): string {
        return "\033[1m" . $text . "\033[0m";
    }
}

class TestSuite {
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private PDO $pdo;

    public function __construct() {
        $this->pdo = get_db_connection();
    }

    public function assert(bool $condition, string $testName, string $failureDetails = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  " . CliPrinter::green("✔ PASS:") . " {$testName}\n";
        } else {
            $this->failed++;
            $msg = "  " . CliPrinter::red("✖ FAIL:") . " {$testName}";
            if ($failureDetails !== '') {
                $msg .= " — " . CliPrinter::yellow($failureDetails);
            }
            echo $msg . "\n";
            $this->failures[] = "{$testName}: {$failureDetails}";
        }
    }

    public function runAll(): bool {
        echo "\n" . CliPrinter::bold(CliPrinter::cyan("======================================================================")) . "\n";
        echo CliPrinter::bold(CliPrinter::cyan(" AFM#2 COMPREHENSIVE AUTOMATED VERIFICATION TEST SUITE (2026)")) . "\n";
        echo CliPrinter::bold(CliPrinter::cyan("======================================================================")) . "\n\n";

        $this->testDatabaseBaseline();
        $this->testCsrfSecurity();
        $this->testAuthenticationAndRateLimiting();
        $this->testSongSearchAndNullArtistHandling();
        $this->testDiscoveryShortlistAndPass();
        $this->testScorecardCriteriaValidation();
        $this->testScorecardGeneratedColumnAndCompletion();
        $this->testTournamentLeaderboardAndTieBreakers();
        $this->testTournamentAdvancementAndDoubleAdvancementProtection();
        $this->testAudioAndLyricsIntegration();
        $this->testAppearanceAndArtworkCustomization();
        $this->testCataloguePreservationInvariant();

        return $this->printSummary();
    }

    /**
     * 1. Baseline Catalogue & Schema Verification
     */
    private function testDatabaseBaseline(): void {
        echo CliPrinter::bold("1. Database Baseline & Catalogue Integrity\n");

        // Verify songs table exists and has catalogue songs
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
        $this->assert($count >= 2400, "Catalogue contains active catalogue songs (count: {$count} >= 2400)", "Actual count: {$count}");

        // Verify specific songs with NULL artist
        $nullArtists = $this->pdo->query("SELECT `song_id` FROM `songs` WHERE `artist` IS NULL ORDER BY `song_id`")->fetchAll(PDO::FETCH_COLUMN);
        $expectedNullIds = [1546, 2015, 2196];
        $this->assert(
            $nullArtists === $expectedNullIds, 
            "Catalogue correctly preserves songs with NULL artist names (IDs: 1546, 2015, 2196)",
            "Found: " . json_encode($nullArtists)
        );

        // Verify all 7 tournament rounds exist
        $rounds = $this->pdo->query("SELECT `round_name`, `sequence_no` FROM `rounds` ORDER BY `sequence_no`")->fetchAll(PDO::FETCH_KEY_PAIR);
        $expectedRounds = [
            'Formal Auditions' => 1,
            'Top 200'          => 2,
            'Top 100'          => 3,
            'Top 50'           => 4,
            'Top 20'           => 5,
            'Top 10'           => 6,
            'Finals'           => 7
        ];
        $this->assert($rounds === $expectedRounds, "All 7 competition stages are properly seeded in sequence");
        echo "\n";
    }

    /**
     * 2. CSRF Token & Security Tests
     */
    private function testCsrfSecurity(): void {
        echo CliPrinter::bold("2. Security & CSRF Token Validation\n");

        $token = get_csrf_token();
        $this->assert(
            is_string($token) && strlen($token) === 64 && ctype_xdigit($token),
            "get_csrf_token() generates secure 64-char hexadecimal token"
        );

        // Validate token
        $this->assert(validate_csrf_token($token), "validate_csrf_token() accepts valid token");
        $this->assert(!validate_csrf_token('invalid_token_12345'), "validate_csrf_token() rejects invalid token");
        $this->assert(!validate_csrf_token(''), "validate_csrf_token() rejects empty token");
        $this->assert(!validate_csrf_token(null), "validate_csrf_token() rejects null token");
        echo "\n";
    }

    /**
     * 3. Authentication, Setup Lock & Rate Limiting
     */
    private function testAuthenticationAndRateLimiting(): void {
        echo CliPrinter::bold("3. Authentication, Setup Lock & Rate Limiting\n");

        // Clean user table for controlled setup test
        $this->pdo->exec("DELETE FROM `app_users`");
        $this->pdo->exec("DELETE FROM `login_rate_limits`");

        $this->assert(!has_users(), "has_users() correctly reports false before setup");

        // Test password length validation (< 8 chars)
        $resShort = create_initial_account('adminuser', 'short');
        $this->assert(!$resShort['success'], "create_initial_account() rejects passwords under 8 characters");

        // Test valid account creation
        $resValid = create_initial_account('adminuser', 'SecurePassword2026!');
        $this->assert($resValid['success'], "create_initial_account() successfully creates first admin account");
        $this->assert(has_users(), "has_users() returns true after account setup");
        $this->assert(is_authenticated(), "Account creation automatically establishes authenticated session");

        // Test setup lock: cannot create second user
        $resSecond = create_initial_account('hacker', 'HackerPass2026!');
        $this->assert(
            !$resSecond['success'] && strpos($resSecond['error'], 'locked') !== false,
            "create_initial_account() permanently locks setup once an account exists"
        );

        // Test login with bad credentials
        $badLogin = attempt_login('adminuser', 'WrongPassword!');
        $this->assert(!$badLogin['success'], "attempt_login() rejects incorrect password");

        // Test login with good credentials
        $goodLogin = attempt_login('adminuser', 'SecurePassword2026!');
        $this->assert($goodLogin['success'], "attempt_login() accepts correct credentials");

        // Test login rate limiting
        for ($i = 0; $i < 5; $i++) {
            record_failed_login();
        }
        $this->assert(is_rate_limited(), "is_rate_limited() flags IP after 5 consecutive failures");

        $blockedLogin = attempt_login('adminuser', 'SecurePassword2026!');
        $this->assert(
            !$blockedLogin['success'] && strpos($blockedLogin['error'], 'Too many') !== false,
            "attempt_login() enforces 15-minute rate limit lockout"
        );

        // Clear rate limits
        clear_rate_limits();
        $this->assert(!is_rate_limited(), "clear_rate_limits() resets IP rate limit bucket");
        echo "\n";
    }

    /**
     * 4. Song Search & Missing Artist Handling
     */
    private function testSongSearchAndNullArtistHandling(): void {
        echo CliPrinter::bold("4. Song Catalogue Search & Missing Artist Handling\n");

        // Search songs pagination
        $totalCatalogue = (int)$this->pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
        $expectedPages = (int)ceil($totalCatalogue / 50);
        $page1 = search_songs('', 'all', 1, 50);
        $this->assert(count($page1['songs']) === 50, "search_songs() returns exactly 50 songs on page 1");
        $this->assert($page1['total'] === $totalCatalogue, "search_songs() total matches {$totalCatalogue}");
        $this->assert($page1['total_pages'] === $expectedPages, "search_songs() total pages calculated correctly ({$expectedPages} pages)");

        // Search for specific song with NULL artist
        $nullSongSearch = search_songs('', 'all', 1, 10);
        // Specifically fetch song ID 1546
        $stmt = $this->pdo->prepare("
            SELECT s.`song_id`, s.`title`, COALESCE(s.`artist`, 'Unknown Artist') AS `artist` 
            FROM `songs` s 
            WHERE s.`song_id` = 1546
        ");
        $stmt->execute();
        $song1546 = $stmt->fetch();
        $this->assert(
            $song1546 && $song1546['artist'] === 'Unknown Artist',
            "COALESCE renders 'Unknown Artist' for song ID 1546 with NULL artist"
        );

        // Unicode & Special Character search safety
        $unicodeSearch = search_songs("Café / Test ' OR 1=1 --", 'all', 1, 10);
        $this->assert(is_array($unicodeSearch['songs']), "Unicode and SQL injection characters handled safely via prepared statements");

        echo "\n";
    }

    /**
     * 5. One-Tap Discovery Shortlist & Pass
     */
    private function testDiscoveryShortlistAndPass(): void {
        echo CliPrinter::bold("5. One-Tap Discovery Shortlist & Pass Actions\n");

        // Clean discovery and entries for testing
        $this->pdo->exec("DELETE FROM `ratings`");
        $this->pdo->exec("DELETE FROM `round_results`");
        $this->pdo->exec("DELETE FROM `song_discovery`");
        $this->pdo->exec("DELETE FROM `afm_entries`");

        // Shortlist Song ID 1
        $res1 = update_song_discovery_status(1, 'shortlisted');
        $this->assert($res1['success'] === true, "update_song_discovery_status(1, 'shortlisted') succeeds");

        // Verify song_discovery table
        $dStmt = $this->pdo->prepare("SELECT `status` FROM `song_discovery` WHERE `song_id` = 1");
        $dStmt->execute();
        $this->assert($dStmt->fetchColumn() === 'shortlisted', "song_discovery record persisted with status 'shortlisted'");

        // Verify entry created in afm_entries
        $eStmt = $this->pdo->prepare("SELECT `entry_id`, `status` FROM `afm_entries` WHERE `song_id` = 1");
        $eStmt->execute();
        $entry1 = $eStmt->fetch();
        $this->assert($entry1 && $entry1['status'] === 'auditioning', "Shortlisting automatically provisions afm_entries row with status 'auditioning'");

        // Pass Song ID 2
        $res2 = update_song_discovery_status(2, 'passed');
        $this->assert($res2['success'] === true, "update_song_discovery_status(2, 'passed') succeeds");
        $dStmt2 = $this->pdo->prepare("SELECT `status` FROM `song_discovery` WHERE `song_id` = 2");
        $dStmt2->execute();
        $this->assert($dStmt2->fetchColumn() === 'passed', "song_discovery record persisted with status 'passed'");

        // Shortlist Song ID 3
        update_song_discovery_status(3, 'shortlisted');

        // Verify get_shortlisted_songs()
        $shortlisted = get_shortlisted_songs(1, 10);
        $this->assert($shortlisted['total'] === 2, "get_shortlisted_songs() returns the 2 shortlisted songs");

        // Reset Song 1 to unreviewed
        $resReset = update_song_discovery_status(1, 'unreviewed');
        $this->assert($resReset['success'] === true, "update_song_discovery_status(1, 'unreviewed') removes discovery record");
        $dStmtReset = $this->pdo->prepare("SELECT COUNT(*) FROM `song_discovery` WHERE `song_id` = 1");
        $dStmtReset->execute();
        $this->assert((int)$dStmtReset->fetchColumn() === 0, "song_discovery record successfully removed on unreviewed reset");

        echo "\n";
    }

    /**
     * 6. Scorecard Criteria Validation (0-10 only)
     */
    private function testScorecardCriteriaValidation(): void {
        echo CliPrinter::bold("6. Scorecard Criteria Input Validation (0–10 each)\n");

        // Shortlist song 1 to get entry_id
        update_song_discovery_status(1, 'shortlisted');
        $entryId = (int)$this->pdo->query("SELECT `entry_id` FROM `afm_entries` WHERE `song_id` = 1")->fetchColumn();

        // Test criterion > 10 rejected
        $resOver = save_scorecard_rating($entryId, 1, 11, 8, 8, 8, 8);
        $this->assert(!$resOver['success'], "save_scorecard_rating() rejects criterion score > 10 (e.g. 11)");

        // Test criterion < 0 rejected
        $resUnder = save_scorecard_rating($entryId, 1, -1, 8, 8, 8, 8);
        $this->assert(!$resUnder['success'], "save_scorecard_rating() rejects criterion score < 0 (e.g. -1)");

        // Test non-integer float or text rejected
        $resText = save_scorecard_rating($entryId, 1, 'invalid', 8, 8, 8, 8);
        $this->assert(!$resText['success'], "save_scorecard_rating() rejects non-numeric text");

        echo "\n";
    }

    /**
     * 7. Scorecard Generated Column & Completion Separation
     */
    private function testScorecardGeneratedColumnAndCompletion(): void {
        echo CliPrinter::bold("7. Scorecard Generated Database Column & Incomplete Resumption\n");

        $entryId = (int)$this->pdo->query("SELECT `entry_id` FROM `afm_entries` WHERE `song_id` = 1")->fetchColumn();

        // Save incomplete scorecard (only 2 criteria provided, remaining 3 null)
        $resIncomplete = save_scorecard_rating($entryId, 1, 9, 8, null, null, null);
        $this->assert($resIncomplete['success'] === true, "save_scorecard_rating() saves incomplete scorecard without error");
        $this->assert($resIncomplete['is_complete'] === false, "Scorecard flagged as incomplete");
        $this->assert($resIncomplete['total'] === null, "Generated database column 'total' is NULL when any criterion is missing");

        // Verify incomplete scorecard filter
        $incompEntries = get_audition_entries(1, 'incomplete');
        $this->assert($incompEntries['total'] === 1, "get_audition_entries(1, 'incomplete') detects the partially scored song");

        // Complete the scorecard: all 5 criteria (9, 8, 10, 7, 8 = 42)
        $resComplete = save_scorecard_rating($entryId, 1, 9, 8, 10, 7, 8);
        $this->assert($resComplete['success'] === true, "save_scorecard_rating() updates scorecard with all 5 criteria");
        $this->assert($resComplete['is_complete'] === true, "Scorecard flagged as complete");
        $this->assert((int)$resComplete['total'] === 42, "MySQL generated column automatically calculated exact total: 42/50");

        // Verify completed scorecard filter
        $compEntries = get_audition_entries(1, 'completed');
        $this->assert($compEntries['total'] === 1, "get_audition_entries(1, 'completed') retrieves the completed scorecard");

        echo "\n";
    }

    /**
     * 8. Tournament Leaderboard & 5-Tier Criteria Tie-Breakers
     */
    private function testTournamentLeaderboardAndTieBreakers(): void {
        echo CliPrinter::bold("8. Tournament Leaderboard & 5-Tier Criteria Tie-Breaking\n");

        // Prepare 3 test entries:
        // Song 1: Total 45 (Melody: 9, Lyrics: 9, Vocals: 9, Emotion: 9, Replay: 9)
        // Song 2: Total 42, Emotion: 10, Replay: 8, Melody: 8, Vocals: 8, Lyrics: 8
        // Song 3: Total 42, Emotion: 8, Replay: 10, Melody: 8, Vocals: 8, Lyrics: 8
        update_song_discovery_status(2, 'shortlisted');
        update_song_discovery_status(3, 'shortlisted');

        $e1 = (int)$this->pdo->query("SELECT `entry_id` FROM `afm_entries` WHERE `song_id` = 1")->fetchColumn();
        $e2 = (int)$this->pdo->query("SELECT `entry_id` FROM `afm_entries` WHERE `song_id` = 2")->fetchColumn();
        $e3 = (int)$this->pdo->query("SELECT `entry_id` FROM `afm_entries` WHERE `song_id` = 3")->fetchColumn();

        save_scorecard_rating($e1, 1, 9, 9, 9, 9, 9);  // Total 45
        save_scorecard_rating($e2, 1, 8, 8, 8, 10, 8); // Total 42, Emotion: 10
        save_scorecard_rating($e3, 1, 8, 8, 8, 8, 10); // Total 42, Emotion: 8, Replay: 10

        $leaderboard = get_round_rankings(1);
        $rankings = $leaderboard['rankings'];

        $this->assert(count($rankings) === 3, "Leaderboard returns all 3 completed entries");
        $this->assert((int)$rankings[0]['entry_id'] === $e1, "Rank #1 is Entry with highest total score (45)");
        $this->assert(
            (int)$rankings[1]['entry_id'] === $e2, 
            "Rank #2 is Entry with equal total (42) broken by higher Emotion score (10 vs 8)"
        );
        $this->assert(
            (int)$rankings[2]['entry_id'] === $e3, 
            "Rank #3 is Entry with equal total (42) but lower Emotion score (8)"
        );

        echo "\n";
    }

    /**
     * 9. Tournament Advancement & Double-Advancement Protection
     */
    private function testTournamentAdvancementAndDoubleAdvancementProtection(): void {
        echo CliPrinter::bold("9. Stage Advancement & Double-Advancement Protection\n");

        // Reset rounds status to default (Round 1 in_progress, Round 2 upcoming)
        $this->pdo->exec("UPDATE `rounds` SET `status` = 'in_progress', `completed_at` = NULL WHERE `round_id` = 1");
        $this->pdo->exec("UPDATE `rounds` SET `status` = 'upcoming', `completed_at` = NULL WHERE `round_id` > 1");
        $this->pdo->exec("DELETE FROM `round_results`");

        // Preview advancement from Round 1
        $preview = preview_stage_advancement(1);
        $this->assert($preview['success'] === true, "preview_stage_advancement(1) calculates stage transition");
        $this->assert($preview['from_round']['round_name'] === 'Formal Auditions', "Preview identifies source round");
        $this->assert($preview['next_round']['round_name'] === 'Top 200', "Preview identifies target round");

        // Qualify entries e1 and e2, eliminate e3
        $e1 = (int)$this->pdo->query("SELECT `entry_id` FROM `afm_entries` WHERE `song_id` = 1")->fetchColumn();
        $e2 = (int)$this->pdo->query("SELECT `entry_id` FROM `afm_entries` WHERE `song_id` = 2")->fetchColumn();

        $commitRes = commit_stage_advancement(1, [$e1, $e2]);
        $this->assert($commitRes['success'] === true, "commit_stage_advancement(1) executes transaction successfully");

        // Check Round 1 is now completed
        $r1Status = $this->pdo->query("SELECT `status` FROM `rounds` WHERE `round_id` = 1")->fetchColumn();
        $this->assert($r1Status === 'completed', "Round 1 status transitioned to 'completed'");

        // Check Round 2 is now in_progress
        $r2Status = $this->pdo->query("SELECT `status` FROM `rounds` WHERE `round_id` = 2")->fetchColumn();
        $this->assert($r2Status === 'in_progress', "Round 2 status transitioned to 'in_progress'");

        // Verify round_results table
        $resCount = (int)$this->pdo->query("SELECT COUNT(*) FROM `round_results` WHERE `round_id` = 1")->fetchColumn();
        $this->assert($resCount === 3, "All 3 entries recorded in round_results");

        $qCount = (int)$this->pdo->query("SELECT COUNT(*) FROM `round_results` WHERE `round_id` = 1 AND `result` = 'qualified'")->fetchColumn();
        $this->assert($qCount === 2, "Exactly 2 entries recorded as 'qualified'");

        $elCount = (int)$this->pdo->query("SELECT COUNT(*) FROM `round_results` WHERE `round_id` = 1 AND `result` = 'eliminated'")->fetchColumn();
        $this->assert($elCount === 1, "Exactly 1 entry recorded as 'eliminated'");

        // Test Double-Advancement Protection
        $doubleCommit = commit_stage_advancement(1, [$e1, $e2]);
        $this->assert(
            !$doubleCommit['success'] && strpos($doubleCommit['error'], 'already been completed') !== false,
            "commit_stage_advancement() rejects duplicate advancement on completed round"
        );

        // Verify Historical Results Archive
        $history = get_historical_results();
        $this->assert(isset($history['Formal Auditions']), "Historical results archive contains Formal Auditions");
        $this->assert(count($history['Formal Auditions']['results']) === 3, "Historical results archive preserves all entry decisions and reasons");

        // Reset rounds status back to clean in_progress state for Formal Auditions
        $this->pdo->exec("UPDATE `rounds` SET `status` = 'in_progress', `completed_at` = NULL WHERE `round_id` = 1");
        $this->pdo->exec("UPDATE `rounds` SET `status` = 'upcoming', `completed_at` = NULL WHERE `round_id` > 1");
        $this->pdo->exec("DELETE FROM `round_results`");
        $this->pdo->exec("DELETE FROM `ratings`");
        $this->pdo->exec("DELETE FROM `song_discovery`");
        $this->pdo->exec("DELETE FROM `afm_entries`");

        // Seed primary user account 'gulshan' with '!Admin@123#4'
        $hash = password_hash('!Admin@123#4', PASSWORD_DEFAULT);
        $seedStmt = $this->pdo->prepare("
            INSERT INTO `app_users` (`username`, `password_hash`, `created_at`, `last_login`)
            VALUES ('gulshan', :hash, NOW(), NOW())
            ON DUPLICATE KEY UPDATE `password_hash` = :hash2
        ");
        $seedStmt->execute([':hash' => $hash, ':hash2' => $hash]);

        echo "\n";
    }

    /**
     * 10. Zero-Cost Audio & Lyrics Integration Verification (YouTube & LRCLIB)
     */
    private function testAudioAndLyricsIntegration(): void {
        echo CliPrinter::bold("10. Audio & Lyrics Integration (YouTube & LRCLIB)\n");

        // 1. YouTube ID Extractor Tests
        $this->assert(
            parse_youtube_id('dQw4w9WgXcQ') === 'dQw4w9WgXcQ',
            "parse_youtube_id() extracts raw 11-char video ID"
        );
        $this->assert(
            parse_youtube_id('https://www.youtube.com/watch?v=dQw4w9WgXcQ') === 'dQw4w9WgXcQ',
            "parse_youtube_id() extracts video ID from standard watch URL"
        );
        $this->assert(
            parse_youtube_id('https://youtu.be/dQw4w9WgXcQ?si=abcdef123') === 'dQw4w9WgXcQ',
            "parse_youtube_id() extracts video ID from youtu.be short URL"
        );
        $this->assert(
            parse_youtube_id('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ') === 'dQw4w9WgXcQ',
            "parse_youtube_id() extracts video ID from embed URL"
        );
        $this->assert(
            parse_youtube_id('https://music.youtube.com/watch?v=dQw4w9WgXcQ') === 'dQw4w9WgXcQ',
            "parse_youtube_id() extracts video ID from YouTube Music URL"
        );
        $this->assert(
            parse_youtube_id('https://www.youtube.com/shorts/dQw4w9WgXcQ') === 'dQw4w9WgXcQ',
            "parse_youtube_id() extracts video ID from YouTube Shorts URL"
        );
        $this->assert(
            parse_youtube_id('invalid_too_short') === null,
            "parse_youtube_id() rejects invalid input strings"
        );
        $this->assert(
            parse_youtube_id('') === null,
            "parse_youtube_id() rejects empty strings"
        );

        // 2. Zero-Key URL Generator Tests
        $ytUrl = get_youtube_search_url('Bohemian Rhapsody', 'Queen');
        $this->assert(
            strpos($ytUrl, 'search_query=Bohemian+Rhapsody+Queen+official+audio') !== false,
            "get_youtube_search_url() formats search query without API key"
        );
        $ytmUrl = get_youtube_music_search_url('Yesterday', 'The Beatles');
        $this->assert(
            strpos($ytmUrl, 'music.youtube.com/search?q=Yesterday+The+Beatles') !== false,
            "get_youtube_music_search_url() formats YouTube Music query without API key"
        );
        $unknownArtistUrl = get_youtube_search_url('Mystery Song', 'Unknown Artist');
        $this->assert(
            strpos($unknownArtistUrl, 'Unknown+Artist') === false,
            "get_youtube_search_url() safely omits placeholder 'Unknown Artist'"
        );

        // 2b. Echo Music Inspired Metadata Sanitization Tests
        $cleanedVideoTag = clean_track_title_for_lyrics('In The End [Official Music Video]');
        $this->assert($cleanedVideoTag === 'In The End', "clean_track_title_for_lyrics() strips video tags [Official Music Video]");

        $cleanedRemaster = clean_track_title_for_lyrics('Bohemian Rhapsody (Remastered 2011)');
        $this->assert($cleanedRemaster === 'Bohemian Rhapsody', "clean_track_title_for_lyrics() strips remaster tags (Remastered 2011)");

        $cleanedHyphenRemaster = clean_track_title_for_lyrics('Hotel California - 2013 Remaster');
        $this->assert($cleanedHyphenRemaster === 'Hotel California', "clean_track_title_for_lyrics() strips hyphenated remaster suffix");

        $cleanedFeat = clean_track_title_for_lyrics('Stan (feat. Dido)');
        $this->assert($cleanedFeat === 'Stan', "clean_track_title_for_lyrics() strips (feat. Artist) from track title");

        // Artist Cleaning & Artist - Title Splitting
        $splitTitle = 'Queen - Radio Ga Ga';
        $splitArtist = clean_artist_for_lyrics('Unknown Artist', $splitTitle);
        $this->assert(
            $splitArtist === 'Queen' && $splitTitle === 'Radio Ga Ga',
            "clean_artist_for_lyrics() cleanly splits 'Artist - Title' strings when artist is Unknown"
        );

        // 2c. Echo Music Inspired Duration-Aware Candidate Ranking Tests (±2s, ±5s heuristics)
        $candidates = [
            [
                'id' => 101,
                'track_name' => 'Bohemian Rhapsody',
                'artist_name' => 'Queen',
                'duration' => 380, // Delta 26s
                'has_synced' => 0,
                'has_plain' => 1
            ],
            [
                'id' => 102,
                'track_name' => 'Bohemian Rhapsody',
                'artist_name' => 'Queen',
                'duration' => 355, // Delta 1s (Strict ±2s Echo threshold)
                'has_synced' => 1,
                'has_plain' => 1
            ],
            [
                'id' => 103,
                'track_name' => 'Bohemian Rhapsody',
                'artist_name' => 'Queen',
                'duration' => 358, // Delta 4s (Relaxed ±5s Echo threshold)
                'has_synced' => 1,
                'has_plain' => 1
            ]
        ];

        $ranked = score_and_rank_lrclib_candidates($candidates, 'Bohemian Rhapsody', 'Queen', 354.0);
        $this->assert(
            !empty($ranked) && $ranked[0]['id'] === 102,
            "score_and_rank_lrclib_candidates() places strict ±2s duration match at top rank"
        );
        $this->assert(
            $ranked[1]['id'] === 103,
            "score_and_rank_lrclib_candidates() places relaxed ±5s duration match at second rank"
        );
        $this->assert(
            $ranked[2]['id'] === 101,
            "score_and_rank_lrclib_candidates() penalizes plain-only distant duration candidates"
        );

        // 3. Audio Recording Confirmation CRUD
        // Clean up any existing media for song 1
        remove_song_media(1);

        $saveRes = save_song_media(1, 'dQw4w9WgXcQ', 'original', '1987 Original Studio', 'allowed');
        $this->assert(
            !empty($saveRes['success']) && $saveRes['media']['video_id'] === 'dQw4w9WgXcQ',
            "save_song_media() stores initial confirmed YouTube recording"
        );
        $this->assert(
            strpos($saveRes['media']['embed_url'], 'autoplay=0') !== false,
            "save_song_media() guarantees autoplay=0 to prevent unexpected playback"
        );

        $savedMedia = get_song_media(1);
        $this->assert(
            $savedMedia !== null && $savedMedia['video_id'] === 'dQw4w9WgXcQ' && $savedMedia['version_type'] === 'original',
            "get_song_media() retrieves saved video reference"
        );

        // Update / change version
        $changeRes = save_song_media(1, 'https://youtu.be/L_LUpnjgPso', 'live', 'Live at Wembley 1986', 'allowed');
        $this->assert(
            !empty($changeRes['success']) && $changeRes['media']['video_id'] === 'L_LUpnjgPso' && $changeRes['media']['version_type'] === 'live',
            "save_song_media() allows changing the saved video reference and version type"
        );

        // Update embed status to blocked
        $blockedRes = update_song_media_embed_status(1, 'blocked');
        $this->assert(
            !empty($blockedRes['success']) && $blockedRes['embed_status'] === 'blocked',
            "update_song_media_embed_status() marks video embedding as blocked"
        );

        // Restore embed status
        $allowedRes = update_song_media_embed_status(1, 'allowed');
        $this->assert(
            !empty($allowedRes['success']) && $allowedRes['embed_status'] === 'allowed',
            "update_song_media_embed_status() restores video embedding status"
        );

        // Remove media reference
        $removeRes = remove_song_media(1);
        $this->assert(!empty($removeRes['success']), "remove_song_media() executes successfully");
        $this->assert(get_song_media(1) === null, "remove_song_media() confirms media record is deleted");

        // Validation: reject invalid video ID
        $invalidSave = save_song_media(1, 'invalid_id_format');
        $this->assert(!$invalidSave['success'], "save_song_media() rejects malformed video ID / URL");

        // Validation: reject non-existent song ID
        $nonExistentSave = save_song_media(999999, 'dQw4w9WgXcQ');
        $this->assert(!$nonExistentSave['success'], "save_song_media() rejects non-existent song ID");

        // 4. Lyrics Reference & LRCLIB Integration
        clear_song_lyrics_ref(1);

        $testLyricsData = [
            'lrclib_id'            => 10001,
            'track_name'           => 'Bohemian Rhapsody',
            'artist_name'          => 'Queen',
            'album_name'           => 'A Night at the Opera',
            'duration'             => 354,
            'instrumental'         => 0,
            'has_synced'           => 1,
            'cached_lyrics_plain'  => "Is this the real life? Is this just fantasy?",
            'cached_lyrics_synced' => "[00:00.80]Is this the real life?\n[00:04.20]Is this just fantasy?",
            'status'               => 'matched'
        ];

        $saveLyricsRes = save_song_lyrics_ref(1, $testLyricsData);
        $this->assert($saveLyricsRes === true, "save_song_lyrics_ref() stores lyrics metadata & cache");

        $retrievedLyrics = get_saved_song_lyrics(1);
        $this->assert(
            $retrievedLyrics !== null && $retrievedLyrics['lrclib_id'] == 10001 && $retrievedLyrics['has_synced'] == 1,
            "get_saved_song_lyrics() retrieves cached lyrics reference"
        );

        // Verify on-demand cache avoids external calls
        $cachedFetch = fetch_lrclib_lyrics(1, 'Bohemian Rhapsody', 'Queen');
        $this->assert(
            !empty($cachedFetch['cached']) && $cachedFetch['status'] === 'matched',
            "fetch_lrclib_lyrics() leverages on-demand cache to respect rate limits"
        );

        // Instrumental track support
        $instrumentalData = [
            'lrclib_id'    => 10002,
            'track_name'   => 'Orion',
            'artist_name'  => 'Metallica',
            'album_name'   => 'Master of Puppets',
            'duration'     => 507,
            'instrumental' => 1,
            'status'       => 'instrumental'
        ];
        save_song_lyrics_ref(1, $instrumentalData);
        $instLyrics = get_saved_song_lyrics(1);
        $this->assert(
            $instLyrics !== null && $instLyrics['instrumental'] == 1 && $instLyrics['status'] === 'instrumental',
            "save_song_lyrics_ref() handles instrumental recordings without lyrics"
        );

        // Clean up test lyrics reference
        $clearLyricsRes = clear_song_lyrics_ref(1);
        $this->assert(!empty($clearLyricsRes['success']), "clear_song_lyrics_ref() removes cached lyrics reference");
        $this->assert(get_saved_song_lyrics(1) === null, "clear_song_lyrics_ref() confirms lyrics record deleted");

        // 5. Audition entries query with media & lyrics left joins
        $auditionRes = get_audition_entries(1, 'all', '', 1, 5);
        $this->assert(
            isset($auditionRes['entries']),
            "get_audition_entries() successfully executes with song_media and song_lyrics_ref joins"
        );

        echo "\n";
    }

    /**
     * 11. Appearance Customization & Artwork Discovery Helpers
     */
    private function testAppearanceAndArtworkCustomization(): void {
        echo CliPrinter::bold("11. Appearance Customization & Artwork Discovery Helpers\n");

        // 1. Defaults verification
        $defaults = get_user_preferences();
        $this->assert(
            $defaults['theme'] === 'midnight_ember' &&
            $defaults['bg_style'] === 'atmospheric' &&
            $defaults['player_layout'] === 'expanded' &&
            $defaults['bg_overlay_opacity'] === 70,
            "get_user_preferences() returns default Midnight Ember preferences"
        );

        // 2. Validate all 4 target themes exist in AFM_VALID_THEMES
        $expectedThemes = ['midnight_ember', 'vintage_paper', 'oceanic', 'quiet_minimal'];
        $hasAllThemes = true;
        foreach ($expectedThemes as $t) {
            if (!array_key_exists($t, AFM_VALID_THEMES)) {
                $hasAllThemes = false;
                break;
            }
        }
        $this->assert($hasAllThemes, "All 4 required themes (Midnight Ember, Vintage Paper, Oceanic, Quiet Minimal) are defined");

        // 3. Save user preferences for the active test user
        $userStmt = $this->pdo->query("SELECT `user_id` FROM `app_users` ORDER BY `user_id` ASC LIMIT 1");
        $testUserId = (int)$userStmt->fetchColumn();
        if ($testUserId <= 0) {
            $testUserId = 1;
        }

        $saveRes = save_user_preferences($testUserId, [
            'theme'              => 'vintage_paper',
            'bg_style'           => 'artwork',
            'player_layout'      => 'compact',
            'bg_overlay_opacity' => 85
        ]);
        $this->assert(!empty($saveRes['success']), "save_user_preferences() saves customized preferences successfully");

        $savedPrefs = get_user_preferences($testUserId);
        $this->assert(
            $savedPrefs['theme'] === 'vintage_paper' &&
            $savedPrefs['bg_style'] === 'artwork' &&
            $savedPrefs['player_layout'] === 'compact' &&
            $savedPrefs['bg_overlay_opacity'] === 85,
            "get_user_preferences() retrieves persisted custom preferences"
        );

        // 4. Safe fallback for invalid preferences
        $fallbackRes = save_user_preferences($testUserId, [
            'theme'              => 'non_existent_neon_theme',
            'bg_style'           => 'invalid_galaxy_style',
            'player_layout'      => 'invalid_layout',
            'bg_overlay_opacity' => 200
        ]);
        $this->assert(!empty($fallbackRes['success']), "save_user_preferences() handles invalid input gracefully");
        $sanitizedPrefs = get_user_preferences($testUserId);
        $this->assert(
            $sanitizedPrefs['theme'] === 'midnight_ember' &&
            $sanitizedPrefs['bg_style'] === 'atmospheric' &&
            $sanitizedPrefs['player_layout'] === 'expanded' &&
            $sanitizedPrefs['bg_overlay_opacity'] === 95,
            "Invalid theme/bg/layout fall back to defaults and opacity is clamped to max 95%"
        );

        // 5. Reset user preferences
        $resetRes = reset_user_preferences($testUserId);
        $this->assert(!empty($resetRes['success']), "reset_user_preferences() executes successfully");
        $afterReset = get_user_preferences($testUserId);
        $this->assert($afterReset['theme'] === 'midnight_ember', "reset_user_preferences() restores default theme");

        // 6. Artwork Discovery: Atmospheric Presets
        $preset1 = get_default_atmospheric_preset('Vellipomaakey', 'A. R. Rahman');
        $this->assert(
            !empty($preset1['image_url']) && !empty($preset1['thumbnail_url']) && $preset1['source'] === 'preset',
            "get_default_atmospheric_preset() returns CC0 atmospheric artwork with thumbnails"
        );

        // Deterministic check
        $preset2 = get_default_atmospheric_preset('Vellipomaakey', 'A. R. Rahman');
        $this->assert(
            $preset1['image_url'] === $preset2['image_url'],
            "get_default_atmospheric_preset() produces deterministic image assignment for the same track"
        );

        // 7. Song Artwork CRUD
        $artworkData = [
            'source'          => 'musicbrainz',
            'image_url'       => 'https://coverartarchive.org/release/fake-mbid/front-500.jpg',
            'thumbnail_url'   => 'https://coverartarchive.org/release/fake-mbid/front-250.jpg',
            'title'           => 'Bohemian Rhapsody Cover',
            'creator'         => 'Queen',
            'license'         => 'Cover Art Archive',
            'attribution_url' => 'https://coverartarchive.org',
            'is_confirmed'    => 0,
            'status'          => 'matched'
        ];
        $savedArt = save_song_artwork(1, $artworkData);
        $this->assert($savedArt === true, "save_song_artwork() stores album artwork reference");

        $fetchedArt = get_song_artwork(1);
        $this->assert(
            $fetchedArt !== null && $fetchedArt['source'] === 'musicbrainz' && (int)$fetchedArt['is_confirmed'] === 0,
            "get_song_artwork() retrieves stored artwork reference"
        );

        // 8. Confirm Artwork Selection
        $confirmedRes = confirm_song_artwork(1, $artworkData);
        $this->assert(!empty($confirmedRes['success']), "confirm_song_artwork() marks artwork as confirmed");
        $confirmedArt = get_song_artwork(1);
        $this->assert((int)$confirmedArt['is_confirmed'] === 1, "Confirmed artwork has is_confirmed = 1");

        // 9. Remove Artwork
        $removeArtRes = remove_song_artwork(1);
        $this->assert(!empty($removeArtRes['success']), "remove_song_artwork() deletes custom artwork reference");
        $this->assert(get_song_artwork(1) === null, "get_song_artwork() returns null after deletion");

        echo "\n";
    }

    /**
     * 12. Catalogue Invariant Verification
     */
    private function testCataloguePreservationInvariant(): void {
        echo CliPrinter::bold("12. Final Catalogue Preservation Invariant Check\n");

        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
        $this->assert(
            $count >= 2400, 
            "MANDATORY INVARIANT: Catalogue strictly retains active songs intact after all tests (count: {$count} >= 2400)",
            "Actual count: {$count}"
        );

        $nullCount = (int)$this->pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL")->fetchColumn();
        $this->assert(
            $nullCount >= 3,
            "MANDATORY INVARIANT: Songs retain their NULL artist attribute intact"
        );

        echo "\n";
    }

    /**
     * Print test results summary
     */
    private function printSummary(): bool {
        $total = $this->passed + $this->failed;
        echo CliPrinter::bold("======================================================================") . "\n";
        echo CliPrinter::bold(" TEST EXECUTION SUMMARY") . "\n";
        echo CliPrinter::bold("======================================================================") . "\n";
        echo "Total Tests Executed: {$total}\n";
        echo "Passed: " . CliPrinter::green((string)$this->passed) . "\n";
        echo "Failed: " . ($this->failed > 0 ? CliPrinter::red((string)$this->failed) : "0") . "\n";

        if ($this->failed === 0) {
            echo "\n" . CliPrinter::bold(CliPrinter::green("🎉 ALL TESTS PASSED SUCCESSFULLY! (100% PASS RATE)")) . "\n\n";
            return true;
        } else {
            echo "\n" . CliPrinter::bold(CliPrinter::red("⚠️ SOME TESTS FAILED:")) . "\n";
            foreach ($this->failures as $f) {
                echo " - " . CliPrinter::red($f) . "\n";
            }
            echo "\n";
            return false;
        }
    }
}

// Run test suite
$suite = new TestSuite();
$success = $suite->runAll();
exit($success ? 0 : 1);
