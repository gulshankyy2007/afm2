<?php
/**
 * AFM#2 - YouTube Recording Matcher, Discovery Engine & Persistence
 * 
 * Implements bulk candidate discovery, multi-signal ranking, confidence classification,
 * persistent MySQL storage, and manual link entry & correction.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/media_helper.php';

// Portable mbstring polyfills
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $str, ?string $encoding = null): string {
        return strtolower($str);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $str, int $start, ?int $length = null, ?string $encoding = null): string {
        return $length === null ? substr($str, $start) : substr($str, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $str, ?string $encoding = null): int {
        return strlen($str);
    }
}

class YouTubeMatcher {

    /**
     * Default ranking criteria weights (Must sum to 1.0)
     */
    public const DEFAULT_WEIGHTS = [
        'title'    => 0.25, // 25% Song-title similarity
        'artist'   => 0.25, // 25% Artist similarity
        'version'  => 0.20, // 20% Recording/version compatibility
        'channel'  => 0.10, // 10% Official or recognized music channel
        'date'     => 0.08, //  8% Upload-date relevance
        'views'    => 0.07, //  7% View-count relevance
        'duration' => 0.05, //  5% Duration similarity
    ];

    public const THRESHOLD_HIGH = 78.0;
    public const THRESHOLD_MEDIUM = 50.0;

    /**
     * Parse duration text into seconds (e.g. "6:32" -> 392, "1:02:15" -> 3735)
     */
    public static function parse_duration_seconds(?string $durationStr): ?int {
        if (!$durationStr) {
            return null;
        }
        $parts = array_map('intval', explode(':', trim($durationStr)));
        if (count($parts) === 2) {
            return ($parts[0] * 60) + $parts[1];
        }
        if (count($parts) === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }
        return null;
    }

    /**
     * Parse view count text into integer (e.g. "119,275,903 views" -> 119275903, "1.2M views" -> 1200000)
     */
    public static function parse_view_count(?string $viewsStr): int {
        if (!$viewsStr) {
            return 0;
        }
        $clean = str_ireplace(['views', 'view', ','], '', trim($viewsStr));
        if (preg_match('/^([\d\.]+)\s*M/i', $clean, $m)) {
            return (int)round(((float)$m[1]) * 1000000);
        }
        if (preg_match('/^([\d\.]+)\s*K/i', $clean, $m)) {
            return (int)round(((float)$m[1]) * 1000);
        }
        if (preg_match('/^([\d\.]+)\s*B/i', $clean, $m)) {
            return (int)round(((float)$m[1]) * 1000000000);
        }
        return (int)preg_replace('/[^\d]/', '', $clean);
    }

    /**
     * Discover candidate YouTube videos for a given song title and artist.
     * Uses structured ytInitialData extraction by default, with optional YouTube Data API v3 fallback.
     */
    public static function discover_candidates(string $title, ?string $artist = null, array $options = []): array {
        $cleanTitle = clean_track_title_for_lyrics($title);
        $tempTitle = $cleanTitle;
        $cleanArtist = $artist ? clean_artist_for_lyrics($artist, $tempTitle) : '';

        $query = trim("{$cleanTitle} {$cleanArtist} official audio");
        if (empty($cleanArtist)) {
            $query = trim("{$cleanTitle} official audio");
        }

        // 1. If YouTube API Key is configured and requested, attempt Data API v3
        $apiKey = env('YOUTUBE_API_KEY');
        if (!empty($apiKey) && ($options['force_api'] ?? false)) {
            $apiCandidates = self::discover_via_data_api($query, $apiKey);
            if (!empty($apiCandidates)) {
                return $apiCandidates;
            }
        }

        // 2. Default Zero-Cost Structured Discovery via ytInitialData
        $searchUrl = "https://www.youtube.com/results?search_query=" . urlencode($query) . "&sp=EgIQAQ%253D%253D"; // sp=EgIQAQ%3D%3D filters for videos only
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\nAccept-Language: en-US,en;q=0.9\r\n",
                'timeout'       => 7,
                'ignore_errors' => true
            ]
        ]);

        $html = @file_get_contents($searchUrl, false, $ctx);
        if (!$html) {
            return [];
        }

        $candidates = [];
        if (preg_match('/ytInitialData\s*=\s*({.+?});<\/script>/s', $html, $m)) {
            $data = json_decode($m[1], true);
            $contents = $data['contents']['twoColumnSearchResultsRenderer']['primaryContents']['sectionListRenderer']['contents'] ?? [];
            foreach ($contents as $sec) {
                $itemSection = $sec['itemSectionRenderer']['contents'] ?? [];
                foreach ($itemSection as $item) {
                    if (isset($item['videoRenderer'])) {
                        $vr = $item['videoRenderer'];
                        $vid = $vr['videoId'] ?? '';
                        if (!preg_match('/^[a-zA-Z0-9_-]{11}$/', $vid)) {
                            continue;
                        }

                        $vTitle = $vr['title']['runs'][0]['text'] ?? '';
                        $vChannel = $vr['ownerText']['runs'][0]['text'] ?? '';
                        $vDuration = $vr['lengthText']['simpleText'] ?? null;
                        $vPublished = $vr['publishedTimeText']['simpleText'] ?? null;
                        $vViews = $vr['viewCountText']['simpleText'] ?? '0';

                        $isArtistChannel = false;
                        $isVerified = false;
                        if (isset($vr['ownerBadges'])) {
                            foreach ($vr['ownerBadges'] as $b) {
                                $tooltip = strtolower($b['metadataBadgeRenderer']['tooltip'] ?? '');
                                if (strpos($tooltip, 'official artist') !== false) {
                                    $isArtistChannel = true;
                                }
                                if (strpos($tooltip, 'verified') !== false) {
                                    $isVerified = true;
                                }
                            }
                        }
                        if (str_ends_with(trim($vChannel), '- Topic')) {
                            $isArtistChannel = true;
                        }

                        $thumb = "https://img.youtube.com/vi/{$vid}/hqdefault.jpg";
                        if (!empty($vr['thumbnail']['thumbnails'])) {
                            $lastThumb = end($vr['thumbnail']['thumbnails']);
                            if (!empty($lastThumb['url'])) {
                                $thumb = $lastThumb['url'];
                            }
                        }

                        $candidates[] = [
                            'video_id'            => $vid,
                            'title'               => $vTitle,
                            'channel_title'       => $vChannel,
                            'duration'            => $vDuration,
                            'duration_seconds'    => self::parse_duration_seconds($vDuration),
                            'view_count'          => self::parse_view_count($vViews),
                            'published_at'        => $vPublished,
                            'channel_is_verified' => $isVerified ? 1 : 0,
                            'channel_is_artist'   => $isArtistChannel ? 1 : 0,
                            'thumbnail_url'       => $thumb
                        ];

                        if (count($candidates) >= 8) {
                            break 2;
                        }
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * Optional official YouTube Data API v3 search
     */
    private static function discover_via_data_api(string $query, string $apiKey): array {
        $url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
            'part'       => 'snippet',
            'q'          => $query,
            'type'       => 'video',
            'maxResults' => 8,
            'key'        => $apiKey
        ]);

        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (!$raw) {
            return [];
        }

        $res = json_decode($raw, true);
        if (empty($res['items'])) {
            return [];
        }

        $candidates = [];
        foreach ($res['items'] as $item) {
            $vid = $item['id']['videoId'] ?? '';
            if (!$vid) continue;
            $snippet = $item['snippet'] ?? [];
            $candidates[] = [
                'video_id'            => $vid,
                'title'               => $snippet['title'] ?? '',
                'channel_title'       => $snippet['channelTitle'] ?? '',
                'duration'            => null,
                'duration_seconds'    => null,
                'view_count'          => null,
                'published_at'        => $snippet['publishedAt'] ?? null,
                'channel_is_verified' => 0,
                'channel_is_artist'   => 0,
                'thumbnail_url'       => $snippet['thumbnails']['high']['url'] ?? "https://img.youtube.com/vi/{$vid}/hqdefault.jpg"
            ];
        }
        return $candidates;
    }

    /**
     * Score and rank candidate YouTube videos using the specified 7-signal criteria.
     */
    public static function score_and_rank_candidates(
        string $songTitle,
        ?string $songArtist,
        array $candidates,
        ?int $expectedDurationSeconds = null,
        array $weights = []
    ): array {
        if (empty($candidates)) {
            return [];
        }

        $w = array_merge(self::DEFAULT_WEIGHTS, $weights);
        $cleanSongTitle = mb_strtolower(clean_track_title_for_lyrics($songTitle));
        $cleanSongArtist = $songArtist ? mb_strtolower(trim($songArtist)) : '';
        $artistSignalAvailable = ($cleanSongArtist !== '' && $cleanSongArtist !== 'unknown artist');

        // Detect version intent in original track title
        $songWantsLive = (bool)preg_match('/\b(live|concert|tour)\b/i', $songTitle);
        $songWantsAcoustic = (bool)preg_match('/\b(acoustic|unplugged)\b/i', $songTitle);
        $songWantsRemix = (bool)preg_match('/\b(remix|mix|edit)\b/i', $songTitle);
        $songWantsCover = (bool)preg_match('/\b(cover|tribute)\b/i', $songTitle);

        $scoredList = [];

        foreach ($candidates as $cand) {
            $candTitle = $cand['title'] ?? '';
            $candTitleLower = mb_strtolower($candTitle);
            $cleanCandTitle = mb_strtolower(clean_track_title_for_lyrics($candTitle));
            $candChannel = $cand['channel_title'] ?? '';
            $candChannelLower = mb_strtolower(preg_replace('/\s*-\s*topic$/i', '', trim($candChannel)));

            $notes = [];
            $missingSignals = [];

            // 1. Song-title similarity (Weight: 25%)
            $titleSim = 0.0;
            if ($cleanCandTitle === $cleanSongTitle || $candTitleLower === $cleanSongTitle) {
                $titleSim = 1.0;
                $notes[] = "Exact title match";
            } else {
                similar_text($cleanSongTitle, $cleanCandTitle, $pct);
                $titleSim = $pct / 100.0;
                // If the entire clean song title is a distinct substring in candidate title
                if (strpos($candTitleLower, $cleanSongTitle) !== false) {
                    $titleSim = max($titleSim, 0.90);
                    $notes[] = "Title contained in candidate";
                }
            }
            $scoreTitle = $titleSim * ($w['title'] * 100.0);

            // 2. Artist similarity (Weight: 25%)
            $artistSim = 0.0;
            if (!$artistSignalAvailable) {
                // Signal unavailable: neutral score, record missing signal
                $missingSignals[] = 'artist';
                $artistSim = 0.70;
            } else {
                if ($candChannelLower === $cleanSongArtist) {
                    $artistSim = 1.0;
                    $notes[] = "Exact channel artist match";
                } else {
                    similar_text($cleanSongArtist, $candChannelLower, $pctChannel);
                    $artistSim = $pctChannel / 100.0;
                    // Check if artist name appears in candidate title (e.g. "Artist - Song")
                    if (strpos($candTitleLower, $cleanSongArtist) !== false) {
                        $artistSim = max($artistSim, 0.92);
                        $notes[] = "Artist in candidate title";
                    }
                    // Check VEVO / channel prefix/suffix (e.g. "EaglesVEVO" contains "Eagles")
                    if (strpos($candChannelLower, $cleanSongArtist) !== false) {
                        $artistSim = max($artistSim, 0.95);
                    }
                }
            }
            $scoreArtist = $artistSim * ($w['artist'] * 100.0);

            // 3. Recording / version compatibility (Weight: 20%)
            $candIsLive = (bool)preg_match('/\b(live|concert|tour)\b/i', $candTitleLower);
            $candIsAcoustic = (bool)preg_match('/\b(acoustic|unplugged)\b/i', $candTitleLower);
            $candIsCover = (bool)preg_match('/\b(cover|karaoke|tribute|instrumental|parody|slowed|sped up|nightcore)\b/i', $candTitleLower);
            $candIsRemix = (bool)preg_match('/\b(remix|club mix|dance mix)\b/i', $candTitleLower);

            $versionCompat = 1.0;
            if (!$songWantsCover && $candIsCover) {
                // Reject or heavily penalize cover/karaoke when studio original requested
                $versionCompat = 0.05;
                $notes[] = "Cover/Karaoke mismatch";
            } elseif (!$songWantsLive && $candIsLive) {
                // Partial penalty for live recording when studio original wanted
                $versionCompat = 0.45;
                $notes[] = "Live version (studio original preferred)";
            } elseif (!$songWantsAcoustic && $candIsAcoustic) {
                $versionCompat = 0.50;
                $notes[] = "Acoustic version";
            } elseif (!$songWantsRemix && $candIsRemix) {
                $versionCompat = 0.45;
                $notes[] = "Remix version";
            } elseif (preg_match('/\b(official audio|official video|original audio|album version)\b/i', $candTitleLower)) {
                $versionCompat = 1.0;
                $notes[] = "Official studio recording";
            }
            $scoreVersion = $versionCompat * ($w['version'] * 100.0);

            // 4. Official or recognized channel (Weight: 10%)
            $channelScore = 0.35; // base for user upload
            if (!empty($cand['channel_is_artist'])) {
                $channelScore = 1.0;
                $notes[] = "Official Artist Channel";
            } elseif (preg_match('/(vevo|records|music|entertainment|atlantic|warner|sony|universal|interscope|sub pop|columbia|republic)/i', $candChannelLower)) {
                $channelScore = 0.90;
                $notes[] = "Recognized music label channel";
            } elseif (!empty($cand['channel_is_verified'])) {
                $channelScore = 0.75;
                $notes[] = "Verified channel";
            }
            $scoreChannel = $channelScore * ($w['channel'] * 100.0);

            // 5. Upload date relevance (Weight: 8%)
            $dateScore = 0.80; // default stable upload
            $published = $cand['published_at'] ?? '';
            if (empty($published)) {
                $missingSignals[] = 'upload_date';
                $dateScore = 0.50; // neutral midpoint
            } else {
                if ($channelScore >= 0.90) {
                    // Official uploads are authentic regardless of age
                    $dateScore = 1.0;
                } elseif (preg_match('/(hour|day|yesterday)/i', $published)) {
                    // Very recent random re-uploads score lower
                    $dateScore = 0.40;
                } else {
                    $dateScore = 0.85;
                }
            }
            $scoreDate = $dateScore * ($w['date'] * 100.0);

            // 6. View count relevance (Weight: 7% - Logarithmic scaling)
            $views = (int)($cand['view_count'] ?? 0);
            $viewsScore = 0.50;
            if ($views <= 0) {
                $missingSignals[] = 'view_count';
                $viewsScore = 0.50;
            } else {
                // Log10 normalized to 100M views
                $viewsScore = min(1.0, max(0.1, log10($views) / 8.0));
                $notes[] = number_format($views) . " views";
            }
            $scoreViews = $viewsScore * ($w['views'] * 100.0);

            // 7. Duration similarity (Weight: 5%)
            $durationScore = 0.50; // neutral midpoint when missing
            $candDurationSec = $cand['duration_seconds'] ?? null;
            if ($expectedDurationSeconds !== null && $candDurationSec !== null && $expectedDurationSeconds > 0) {
                $diff = abs($candDurationSec - $expectedDurationSeconds);
                if ($diff <= 3) {
                    $durationScore = 1.0;
                    $notes[] = "Exact duration (±3s)";
                } elseif ($diff <= 8) {
                    $durationScore = 0.85;
                } elseif ($diff <= 20) {
                    $durationScore = 0.60;
                } elseif ($diff <= 45) {
                    $durationScore = 0.30;
                } else {
                    $durationScore = 0.05;
                    $notes[] = "Duration mismatch (" . abs($diff) . "s diff)";
                }
            } else {
                $missingSignals[] = 'duration';
                // If candidate is extraordinarily long (> 15 mins) it's likely a full album or loop
                if ($candDurationSec !== null && $candDurationSec > 900) {
                    $durationScore = 0.10;
                    $notes[] = "Excessive duration (>15m)";
                } else {
                    $durationScore = 0.50;
                }
            }
            $scoreDuration = $durationScore * ($w['duration'] * 100.0);

            // Total Score calculation
            $rawTotal = $scoreTitle + $scoreArtist + $scoreVersion + $scoreChannel + $scoreDate + $scoreViews + $scoreDuration;
            
            // Hard gate: If severe version mismatch (e.g. karaoke/cover), clamp score
            if ($versionCompat <= 0.05) {
                $rawTotal = min($rawTotal, 35.0);
            }

            // Hard gate: If clear title mismatch, clamp score
            if ($titleSim < 0.35) {
                $rawTotal = min($rawTotal, 38.0);
            }

            // Hard gate: If artist is present but clearly mismatched, clamp score
            if ($artistSignalAvailable && $artistSim < 0.25) {
                $rawTotal = min($rawTotal, 42.0);
            }

            $scoreTotal = round(min(100.0, max(0.0, $rawTotal)), 2);

            // Determine confidence category
            $confidence = 'low';
            if ($scoreTotal >= self::THRESHOLD_HIGH && $titleSim >= 0.70 && $versionCompat >= 0.75 && ($artistSim >= 0.65 || !$artistSignalAvailable)) {
                $confidence = 'high';
            } elseif ($scoreTotal >= self::THRESHOLD_MEDIUM && $titleSim >= 0.40 && ($artistSim >= 0.35 || !$artistSignalAvailable)) {
                $confidence = 'medium';
            }

            $decisionNotes = implode('; ', array_slice($notes, 0, 4));
            if (!empty($missingSignals)) {
                $decisionNotes .= ' [Missing: ' . implode(', ', $missingSignals) . ']';
            }

            $cand['score_total']       = $scoreTotal;
            $cand['score_title']       = round($scoreTitle, 2);
            $cand['score_artist']      = round($scoreArtist, 2);
            $cand['score_version']     = round($scoreVersion, 2);
            $cand['score_channel']     = round($scoreChannel, 2);
            $cand['score_date']        = round($scoreDate, 2);
            $cand['score_views']       = round($scoreViews, 2);
            $cand['score_duration']    = round($scoreDuration, 2);
            $cand['confidence']        = $confidence;
            $cand['decision_notes']    = $decisionNotes;
            $cand['missing_signals']   = $missingSignals;

            $scoredList[] = $cand;
        }

        // Sort descending by total score
        usort($scoredList, function($a, $b) {
            return $b['score_total'] <=> $a['score_total'];
        });

        return $scoredList;
    }

    /**
     * Save discovered candidates into song_youtube_candidates table.
     */
    public static function save_candidates_for_song(int $songId, array $scoredCandidates): void {
        if (empty($scoredCandidates)) {
            return;
        }

        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            INSERT INTO `song_youtube_candidates`
                (`song_id`, `video_id`, `title`, `channel_title`, `duration`, `duration_seconds`, 
                 `view_count`, `published_at`, `channel_is_verified`, `channel_is_artist`, 
                 `thumbnail_url`, `score_total`, `score_title`, `score_artist`, `score_version`, 
                 `score_channel`, `score_date`, `score_views`, `score_duration`, `confidence`, 
                 `decision_notes`, `is_selected`, `updated_at`)
            VALUES
                (:song_id, :video_id, :title, :channel_title, :duration, :duration_seconds, 
                 :view_count, :published_at, :channel_is_verified, :channel_is_artist, 
                 :thumbnail_url, :score_total, :score_title, :score_artist, :score_version, 
                 :score_channel, :score_date, :score_views, :score_duration, :confidence, 
                 :decision_notes, :is_selected, NOW())
            ON DUPLICATE KEY UPDATE
                `title` = VALUES(`title`),
                `channel_title` = VALUES(`channel_title`),
                `duration` = VALUES(`duration`),
                `duration_seconds` = VALUES(`duration_seconds`),
                `view_count` = VALUES(`view_count`),
                `published_at` = VALUES(`published_at`),
                `channel_is_verified` = VALUES(`channel_is_verified`),
                `channel_is_artist` = VALUES(`channel_is_artist`),
                `thumbnail_url` = VALUES(`thumbnail_url`),
                `score_total` = VALUES(`score_total`),
                `score_title` = VALUES(`score_title`),
                `score_artist` = VALUES(`score_artist`),
                `score_version` = VALUES(`score_version`),
                `score_channel` = VALUES(`score_channel`),
                `score_date` = VALUES(`score_date`),
                `score_views` = VALUES(`score_views`),
                `score_duration` = VALUES(`score_duration`),
                `confidence` = VALUES(`confidence`),
                `decision_notes` = VALUES(`decision_notes`),
                `updated_at` = NOW()
        ");

        foreach ($scoredCandidates as $c) {
            $stmt->execute([
                ':song_id'             => $songId,
                ':video_id'            => $c['video_id'],
                ':title'               => mb_substr($c['title'], 0, 255),
                ':channel_title'       => !empty($c['channel_title']) ? mb_substr($c['channel_title'], 0, 255) : null,
                ':duration'            => $c['duration'] ?? null,
                ':duration_seconds'    => $c['duration_seconds'] ?? null,
                ':view_count'          => $c['view_count'] ?? null,
                ':published_at'        => $c['published_at'] ?? null,
                ':channel_is_verified' => !empty($c['channel_is_verified']) ? 1 : 0,
                ':channel_is_artist'   => !empty($c['channel_is_artist']) ? 1 : 0,
                ':thumbnail_url'       => $c['thumbnail_url'] ?? null,
                ':score_total'         => $c['score_total'],
                ':score_title'         => $c['score_title'],
                ':score_artist'        => $c['score_artist'],
                ':score_version'       => $c['score_version'],
                ':score_channel'       => $c['score_channel'],
                ':score_date'          => $c['score_date'],
                ':score_views'         => $c['score_views'],
                ':score_duration'      => $c['score_duration'],
                ':confidence'          => $c['confidence'],
                ':decision_notes'      => !empty($c['decision_notes']) ? mb_substr($c['decision_notes'], 0, 512) : null,
                ':is_selected'         => 0
            ]);
        }
        self::clear_matching_stats_cache();
    }

    /**
     * Retrieve all saved candidates for a song, ordered by score_total descending.
     */
    public static function get_candidates_for_song(int $songId): array {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            SELECT * 
            FROM `song_youtube_candidates` 
            WHERE `song_id` = :id 
            ORDER BY `score_total` DESC
        ");
        $stmt->execute([':id' => $songId]);
        return $stmt->fetchAll();
    }

    /**
     * Automatically accept top candidate if high confidence AND no existing manual selection.
     */
    public static function auto_select_high_confidence_match(int $songId, array $scoredCandidates): array {
        $pdo = get_db_connection();

        // 1. Check existing media: If manual selection exists, DO NOT overwrite!
        $chk = $pdo->prepare("SELECT `media_id`, `video_id`, `is_manual`, `match_method` FROM `song_media` WHERE `song_id` = :id");
        $chk->execute([':id' => $songId]);
        $existing = $chk->fetch();
        if ($existing && !empty($existing['is_manual'])) {
            return [
                'selected' => false,
                'status'   => 'skipped_manual',
                'reason'   => 'Song has a protected manual recording selection. Automated matching will not overwrite it.',
                'message'  => 'Song has a protected manual recording selection. Automated matching will not overwrite it.'
            ];
        }

        if (empty($scoredCandidates)) {
            return [
                'selected' => false,
                'status'   => 'no_candidates',
                'reason'   => 'No candidates discovered.',
                'message'  => 'No candidates discovered.'
            ];
        }

        $top = $scoredCandidates[0];
        if ($top['confidence'] === 'high') {
            $vid = $top['video_id'];
            $canonicalUrl = "https://www.youtube.com/watch?v={$vid}";

            // Persist into song_media
            $stmt = $pdo->prepare("
                INSERT INTO `song_media` 
                    (`song_id`, `service`, `user_url`, `video_id`, `title`, `channel_title`, 
                     `version_type`, `version_notes`, `embed_status`, `match_method`, 
                     `match_score`, `confidence`, `verification_status`, `match_decision_notes`, 
                     `matched_at`, `is_manual`, `updated_at`)
                VALUES 
                    (:song_id, 'youtube', :user_url, :video_id, :title, :channel_title, 
                     'original', NULL, 'allowed', 'automatic', 
                     :match_score, 'high', 'unverified', :decision_notes, 
                     NOW(), 0, NOW())
                ON DUPLICATE KEY UPDATE
                    `user_url` = VALUES(`user_url`),
                    `video_id` = VALUES(`video_id`),
                    `title` = VALUES(`title`),
                    `channel_title` = VALUES(`channel_title`),
                    `match_method` = 'automatic',
                    `match_score` = VALUES(`match_score`),
                    `confidence` = 'high',
                    `match_decision_notes` = VALUES(`match_decision_notes`),
                    `matched_at` = NOW(),
                    `is_manual` = 0,
                    `updated_at` = NOW()
            ");

            $stmt->execute([
                ':song_id'        => $songId,
                ':user_url'       => $canonicalUrl,
                ':video_id'       => $vid,
                ':title'          => mb_substr($top['title'], 0, 255),
                ':channel_title'  => !empty($top['channel_title']) ? mb_substr($top['channel_title'], 0, 255) : null,
                ':match_score'    => $top['score_total'],
                ':decision_notes' => $top['decision_notes']
            ]);

            // Update candidate is_selected flag
            $pdo->prepare("UPDATE `song_youtube_candidates` SET `is_selected` = 0 WHERE `song_id` = :id")->execute([':id' => $songId]);
            $pdo->prepare("UPDATE `song_youtube_candidates` SET `is_selected` = 1 WHERE `song_id` = :id AND `video_id` = :vid")
                ->execute([':id' => $songId, ':vid' => $vid]);

            // Sync confirmed artwork thumbnail
            try {
                $artStmt = $pdo->prepare("
                    INSERT INTO `song_artwork` (`song_id`, `source`, `thumbnail_url`, `image_url`, `is_confirmed`, `status`)
                    VALUES (:id, 'confirmed', :thumb, :img, 1, 'matched')
                    ON DUPLICATE KEY UPDATE 
                        `thumbnail_url` = VALUES(`thumbnail_url`), 
                        `image_url` = VALUES(`image_url`),
                        `is_confirmed` = 1,
                        `status` = 'matched'
                ");
                $thumbUrl = $top['thumbnail_url'] ?? "https://img.youtube.com/vi/{$vid}/hqdefault.jpg";
                $artStmt->execute([
                    ':id'    => $songId,
                    ':thumb' => $thumbUrl,
                    ':img'   => $thumbUrl
                ]);
            } catch (\Throwable $e) {}

            self::clear_matching_stats_cache();

            return [
                'selected'  => true,
                'status'    => 'matched_high',
                'video_id'  => $vid,
                'score'     => $top['score_total'],
                'candidate' => $top,
                'reason'    => "Auto-selected high-confidence candidate ({$top['score_total']}%)",
                'message'   => "Auto-selected high-confidence candidate: {$top['title']} ({$top['score_total']}%)"
            ];
        }

        if ($top['confidence'] === 'medium') {
            return [
                'selected'  => false,
                'status'    => 'review_medium',
                'score'     => $top['score_total'],
                'candidate' => $top,
                'reason'    => "Candidate requires operator review ({$top['score_total']}%)",
                'message'   => "Candidate requires review ({$top['score_total']}%): {$top['title']}"
            ];
        }

        return [
            'selected'  => false,
            'status'    => 'unresolved_low',
            'score'     => $top['score_total'],
            'candidate' => $top,
            'reason'    => "No high or medium confidence match found ({$top['score_total']}%)",
            'message'   => "No high/medium confidence match found ({$top['score_total']}%)."
        ];
    }

    /**
     * Manual selection of a discovered candidate by operator.
     * Sets `is_manual = 1`, `match_method = 'manual'`, `confidence = 'manual'`.
     */
    public static function manual_select_candidate(
        int $songId, 
        string $videoId, 
        string $versionType = 'original', 
        ?string $versionNotes = null,
        ?int $userId = null
    ): array {
        $pdo = get_db_connection();

        if ($userId !== null) {
            $chk = $pdo->prepare("SELECT `user_id` FROM `songs` WHERE `song_id` = :id");
            $chk->execute([':id' => $songId]);
            $sRow = $chk->fetch();
            if ($sRow && $sRow['user_id'] !== null && (int)$sRow['user_id'] !== (int)$userId) {
                return ['success' => false, 'error' => 'Unauthorized: Cannot modify recording for another user\'s song.'];
            }
        }

        $cleanVid = parse_youtube_id($videoId);
        if (!$cleanVid) {
            return ['success' => false, 'error' => 'Invalid video ID.'];
        }

        // Fetch candidate metadata from table if exists
        $candStmt = $pdo->prepare("SELECT * FROM `song_youtube_candidates` WHERE `song_id` = :id AND `video_id` = :vid LIMIT 1");
        $candStmt->execute([':id' => $songId, ':vid' => $cleanVid]);
        $cand = $candStmt->fetch();

        $title = $cand['title'] ?? null;
        $channel = $cand['channel_title'] ?? null;
        $score = $cand ? (float)$cand['score_total'] : null;

        $canonicalUrl = "https://www.youtube.com/watch?v={$cleanVid}";

        // Persist into song_media
        $stmt = $pdo->prepare("
            INSERT INTO `song_media` 
                (`song_id`, `service`, `user_url`, `video_id`, `title`, `channel_title`, 
                 `version_type`, `version_notes`, `embed_status`, `match_method`, 
                 `match_score`, `confidence`, `verification_status`, `match_decision_notes`, 
                 `matched_at`, `is_manual`, `updated_at`)
            VALUES 
                (:song_id, 'youtube', :user_url, :video_id, :title, :channel_title, 
                 :version_type, :version_notes, 'allowed', 'manual', 
                 :match_score, 'manual', 'verified', 'Manually chosen from candidates by operator', 
                 NOW(), 1, NOW())
            ON DUPLICATE KEY UPDATE
                `user_url` = VALUES(`user_url`),
                `video_id` = VALUES(`video_id`),
                `title` = VALUES(`title`),
                `channel_title` = VALUES(`channel_title`),
                `version_type` = VALUES(`version_type`),
                `version_notes` = VALUES(`version_notes`),
                `match_method` = 'manual',
                `match_score` = VALUES(`match_score`),
                `confidence` = 'manual',
                `verification_status` = 'verified',
                `match_decision_notes` = VALUES(`match_decision_notes`),
                `matched_at` = NOW(),
                `is_manual` = 1,
                `updated_at` = NOW()
        ");

        try {
            $stmt->execute([
                ':song_id'       => $songId,
                ':user_url'      => $canonicalUrl,
                ':video_id'      => $cleanVid,
                ':title'         => $title ? mb_substr($title, 0, 255) : null,
                ':channel_title' => $channel ? mb_substr($channel, 0, 255) : null,
                ':version_type'  => $versionType,
                ':version_notes' => $versionNotes,
                ':match_score'   => $score
            ]);

            // Mark candidate is_selected
            $pdo->prepare("UPDATE `song_youtube_candidates` SET `is_selected` = 0 WHERE `song_id` = :id")->execute([':id' => $songId]);
            $pdo->prepare("UPDATE `song_youtube_candidates` SET `is_selected` = 1 WHERE `song_id` = :id AND `video_id` = :vid")
                ->execute([':id' => $songId, ':vid' => $cleanVid]);

            // Sync artwork
            try {
                $artStmt = $pdo->prepare("
                    INSERT INTO `song_artwork` (`song_id`, `source`, `thumbnail_url`, `image_url`, `is_confirmed`, `status`)
                    VALUES (:id, 'confirmed', :thumb, :img, 1, 'matched')
                    ON DUPLICATE KEY UPDATE 
                        `thumbnail_url` = VALUES(`thumbnail_url`), 
                        `image_url` = VALUES(`image_url`),
                        `is_confirmed` = 1,
                        `status` = 'matched'
                ");
                $thumbUrl = "https://img.youtube.com/vi/{$cleanVid}/hqdefault.jpg";
                $artStmt->execute([':id' => $songId, ':thumb' => $thumbUrl, ':img' => $thumbUrl]);
            } catch (\Throwable $e) {}
        } finally {
            self::clear_matching_stats_cache();
        }

        return [
            'success'  => true,
            'message'  => "Candidate manually selected and locked against automatic overwrites.",
            'video_id' => $cleanVid,
            'user_url' => $canonicalUrl
        ];
    }

    /**
     * Manual link entry / replacement by typing or pasting a URL.
     * Sets `is_manual = 1`, `match_method = 'manual'`, `confidence = 'manual'`.
     */
    public static function manual_save_url(
        int $songId, 
        string $urlOrVideoId, 
        string $versionType = 'original', 
        ?string $versionNotes = null,
        ?int $userId = null
    ): array {
        $pdo = get_db_connection();

        if ($userId !== null) {
            $chk = $pdo->prepare("SELECT `user_id` FROM `songs` WHERE `song_id` = :id");
            $chk->execute([':id' => $songId]);
            $sRow = $chk->fetch();
            if ($sRow && $sRow['user_id'] !== null && (int)$sRow['user_id'] !== (int)$userId) {
                return ['success' => false, 'error' => 'Unauthorized: Cannot modify recording for another user\'s song.'];
            }
        }

        $videoId = parse_youtube_id($urlOrVideoId);
        if (!$videoId) {
            return [
                'success' => false,
                'error'   => 'Invalid YouTube link or video ID. Please provide an 11-character video ID or valid YouTube/YouTube Music URL.'
            ];
        }

        $canonicalUrl = "https://www.youtube.com/watch?v={$videoId}";
        $userUrl = trim($urlOrVideoId);

        // Fetch oEmbed metadata
        $meta = extract_youtube_metadata($videoId);
        $title = $meta['title'] ?? null;
        $channel = $meta['channel_title'] ?? null;
        $stmt = $pdo->prepare("
            INSERT INTO `song_media` 
                (`song_id`, `service`, `user_url`, `video_id`, `title`, `channel_title`, 
                 `version_type`, `version_notes`, `embed_status`, `match_method`, 
                 `match_score`, `confidence`, `verification_status`, `match_decision_notes`, 
                 `matched_at`, `is_manual`, `updated_at`)
            VALUES 
                (:song_id, 'youtube', :user_url, :video_id, :title, :channel_title, 
                 :version_type, :version_notes, 'allowed', 'manual', 
                 100.00, 'manual', 'verified', 'Manually entered or replaced link by operator', 
                 NOW(), 1, NOW())
            ON DUPLICATE KEY UPDATE
                `user_url` = VALUES(`user_url`),
                `video_id` = VALUES(`video_id`),
                `title` = VALUES(`title`),
                `channel_title` = VALUES(`channel_title`),
                `version_type` = VALUES(`version_type`),
                `version_notes` = VALUES(`version_notes`),
                `match_method` = 'manual',
                `match_score` = 100.00,
                `confidence` = 'manual',
                `verification_status` = 'verified',
                `match_decision_notes` = VALUES(`match_decision_notes`),
                `matched_at` = NOW(),
                `is_manual` = 1,
                `updated_at` = NOW()
        ");

        try {
            $stmt->execute([
                ':song_id'       => $songId,
                ':user_url'      => $userUrl,
                ':video_id'      => $videoId,
                ':title'         => $title ? mb_substr($title, 0, 255) : null,
                ':channel_title' => $channel ? mb_substr($channel, 0, 255) : null,
                ':version_type'  => $versionType,
                ':version_notes' => $versionNotes
            ]);

            // Sync artwork
            try {
                $artStmt = $pdo->prepare("
                    INSERT INTO `song_artwork` (`song_id`, `source`, `thumbnail_url`, `image_url`, `is_confirmed`, `status`)
                    VALUES (:id, 'confirmed', :thumb, :img, 1, 'matched')
                    ON DUPLICATE KEY UPDATE 
                        `thumbnail_url` = VALUES(`thumbnail_url`), 
                        `image_url` = VALUES(`image_url`),
                        `is_confirmed` = 1,
                        `status` = 'matched'
                ");
                $thumbUrl = $meta['thumbnail_url'] ?? "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
                $artStmt->execute([':id' => $songId, ':thumb' => $thumbUrl, ':img' => $thumbUrl]);
            } catch (\Throwable $e) {}
        } finally {
            self::clear_matching_stats_cache();
        }

        return [
            'success'  => true,
            'message'  => 'Recording link saved successfully and locked against automatic overwrites.',
            'video_id' => $videoId,
            'user_url' => $userUrl,
            'title'    => $title,
            'channel'  => $channel
        ];
    }

    /**
     * Clear recording selection without deleting the song from catalogue.
     */
    public static function clear_recording(int $songId, ?int $userId = null): array {
        $pdo = get_db_connection();

        if ($userId !== null) {
            $chk = $pdo->prepare("SELECT `user_id` FROM `songs` WHERE `song_id` = :id");
            $chk->execute([':id' => $songId]);
            $sRow = $chk->fetch();
            if ($sRow && $sRow['user_id'] !== null && (int)$sRow['user_id'] !== (int)$userId) {
                return ['success' => false, 'error' => 'Unauthorized: Cannot clear recording for another user\'s song.'];
            }
        }

        $pdo->prepare("DELETE FROM `song_media` WHERE `song_id` = :id AND `service` = 'youtube'")->execute([':id' => $songId]);
        $pdo->prepare("UPDATE `song_youtube_candidates` SET `is_selected` = 0 WHERE `song_id` = :id")->execute([':id' => $songId]);
        
        // Remove confirmed artwork
        try {
            $pdo->prepare("DELETE FROM `song_artwork` WHERE `song_id` = :id AND `source` = 'confirmed'")->execute([':id' => $songId]);
        } catch (\Throwable $e) {}

        self::clear_matching_stats_cache();

        return ['success' => true, 'message' => 'Recording selection cleared.'];
    }

    /**
     * Verify whether a video exists and permits embedded playback via YouTube oEmbed API.
     */
    public static function verify_playback_and_embed(string $videoId, ?int $songId = null): array {
        $cleanVid = parse_youtube_id($videoId);
        if (!$cleanVid) {
            return ['success' => false, 'embed_status' => 'blocked', 'error' => 'Invalid video ID'];
        }

        $oembedUrl = 'https://www.youtube.com/oembed?url=' . urlencode("https://www.youtube.com/watch?v={$cleanVid}") . '&format=json';
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 4, 'ignore_errors' => true]
        ]);
        $res = @file_get_contents($oembedUrl, false, $ctx);
        $headers = $http_response_header ?? [];
        $status = 0;
        if (!empty($headers) && preg_match('#HTTP/\S+\s+(\d{3})#i', $headers[0], $m)) {
            $status = (int)$m[1];
        }

        $embedStatus = 'allowed';
        $verified = true;
        if ($status === 401 || $status === 403) {
            $embedStatus = 'blocked';
            $verified = false;
        } elseif ($status === 404) {
            $embedStatus = 'blocked';
            $verified = false;
        }

        if ($songId !== null && $songId > 0) {
            $pdo = get_db_connection();
            $pdo->prepare("
                UPDATE `song_media` 
                SET `embed_status` = :embed_status, 
                    `verification_status` = :ver_status,
                    `updated_at` = NOW() 
                WHERE `song_id` = :id
            ")->execute([
                ':embed_status' => $embedStatus,
                ':ver_status'   => $verified ? 'verified' : 'failed',
                ':id'           => $songId
            ]);
        }

        return [
            'success'             => $verified,
            'http_status'         => $status,
            'embed_status'        => $embedStatus,
            'verification_status' => $verified ? 'verified' : 'failed',
            'video_id'            => $cleanVid
        ];
    }

    /**
     * Get or initialize the active bulk matching job.
     */
    public static function get_or_create_active_job(?int $userId = null): array {
        $pdo = get_db_connection();
        if ($userId !== null) {
            $tStmt = $pdo->prepare("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0 AND (`user_id` = :uid OR `user_id` IS NULL)");
            $tStmt->execute([':uid' => $userId]);
            $totalSongs = (int)$tStmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT * FROM `youtube_matching_jobs` WHERE `user_id` = :uid ORDER BY `job_id` DESC LIMIT 1");
            $stmt->execute([':uid' => $userId]);
        } else {
            $totalSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0")->fetchColumn();
            $stmt = $pdo->query("SELECT * FROM `youtube_matching_jobs` WHERE `user_id` IS NULL ORDER BY `job_id` DESC LIMIT 1");
        }
        $job = $stmt->fetch();

        if (!$job) {
            $pdo->prepare("
                INSERT INTO `youtube_matching_jobs` (`job_name`, `status`, `total_songs`, `user_id`, `started_at`)
                VALUES ('Bulk YouTube Discovery & Matcher', 'idle', :total, :uid, NOW())
            ")->execute([':total' => $totalSongs, ':uid' => $userId]);
            $jobId = (int)$pdo->lastInsertId();
            return $pdo->query("SELECT * FROM `youtube_matching_jobs` WHERE `job_id` = {$jobId}")->fetch();
        }

        // If total count changed, update it
        if ((int)$job['total_songs'] !== $totalSongs) {
            $pdo->prepare("UPDATE `youtube_matching_jobs` SET `total_songs` = :tot WHERE `job_id` = :id")
                ->execute([':tot' => $totalSongs, ':id' => $job['job_id']]);
            $job['total_songs'] = $totalSongs;
        }

        return $job;
    }

    /**
     * Process next batch of songs in bulk matching queue.
     */
    public static function process_batch(int $batchSize = 10, array $options = [], ?int $userId = null): array {
        $pdo = get_db_connection();
        $job = self::get_or_create_active_job($userId);
        $jobId = (int)$job['job_id'];

        // Mark running
        $pdo->prepare("UPDATE `youtube_matching_jobs` SET `status` = 'running', `updated_at` = NOW() WHERE `job_id` = :id")
            ->execute([':id' => $jobId]);

        $lastSongId = (int)$job['last_processed_song_id'];
        $forceReprocess = $options['force'] ?? false;
        $userFilter = ($userId !== null) ? " AND (`user_id` = :uid OR `user_id` IS NULL)" : "";

        // Fetch next batch of songs
        if ($forceReprocess) {
            $songStmt = $pdo->prepare("
                SELECT `song_id`, `title`, `artist`, `user_id` 
                FROM `songs` 
                WHERE `is_consolidated` = 0 {$userFilter}
                ORDER BY `song_id` ASC 
                LIMIT :limit OFFSET :offset
            ");
            if ($userId !== null) {
                $songStmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            }
            $songStmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
            $songStmt->bindValue(':offset', (int)$job['processed_songs'], PDO::PARAM_INT);
        } else {
            $songStmt = $pdo->prepare("
                SELECT `song_id`, `title`, `artist`, `user_id` 
                FROM `songs` 
                WHERE `is_consolidated` = 0 AND `song_id` > :last_id {$userFilter}
                ORDER BY `song_id` ASC 
                LIMIT :limit
            ");
            $songStmt->bindValue(':last_id', $lastSongId, PDO::PARAM_INT);
            if ($userId !== null) {
                $songStmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            }
            $songStmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        }

        $songStmt->execute();
        $songs = $songStmt->fetchAll();

        if (empty($songs)) {
            // Completed!
            $pdo->prepare("
                UPDATE `youtube_matching_jobs` 
                SET `status` = 'completed', `completed_at` = NOW(), `updated_at` = NOW() 
                WHERE `job_id` = :id
            ")->execute([':id' => $jobId]);

            return [
                'success'     => true,
                'completed'   => true,
                'job'         => self::get_or_create_active_job($userId),
                'processed'   => 0,
                'results'     => [],
                'message'     => 'All catalogue songs have been processed.'
            ];
        }

        $batchResults = [];
        $matchedHighDelta = 0;
        $reviewMediumDelta = 0;
        $unresolvedLowDelta = 0;
        $skippedManualDelta = 0;
        $failedDelta = 0;

        foreach ($songs as $s) {
            $sId = (int)$s['song_id'];
            $lastSongId = $sId;

            // Check if song already has manual recording
            $mediaCheck = $pdo->prepare("SELECT `media_id`, `is_manual`, `video_id`, `confidence` FROM `song_media` WHERE `song_id` = :id");
            $mediaCheck->execute([':id' => $sId]);
            $existingMedia = $mediaCheck->fetch();

            if ($existingMedia && !empty($existingMedia['is_manual'])) {
                $skippedManualDelta++;
                $batchResults[] = [
                    'song_id' => $sId,
                    'title'   => $s['title'],
                    'status'  => 'skipped_manual',
                    'message' => 'Preserved protected manual recording selection.'
                ];
                continue;
            }

            // Check expected duration from lyrics ref if cached
            $lyricsStmt = $pdo->prepare("SELECT `duration` FROM `song_lyrics_ref` WHERE `song_id` = :id");
            $lyricsStmt->execute([':id' => $sId]);
            $lyricsRow = $lyricsStmt->fetch();
            $expectedDuration = ($lyricsRow && !empty($lyricsRow['duration'])) ? (int)round((float)$lyricsRow['duration']) : null;

            try {
                // 1. Discover candidates
                $candidates = self::discover_candidates($s['title'], $s['artist'], $options);

                if (empty($candidates)) {
                    $unresolvedLowDelta++;
                    $batchResults[] = [
                        'song_id' => $sId,
                        'title'   => $s['title'],
                        'status'  => 'no_candidates',
                        'message' => 'No YouTube video candidates found.'
                    ];
                    continue;
                }

                // 2. Score candidates
                $scored = self::score_and_rank_candidates($s['title'], $s['artist'], $candidates, $expectedDuration);

                // 3. Save candidates to history
                self::save_candidates_for_song($sId, $scored);

                // 4. Auto select if high confidence
                $matchRes = self::auto_select_high_confidence_match($sId, $scored);

                if ($matchRes['status'] === 'matched_high') {
                    $matchedHighDelta++;
                } elseif ($matchRes['status'] === 'review_medium') {
                    $reviewMediumDelta++;
                } else {
                    $unresolvedLowDelta++;
                }

                $batchResults[] = [
                    'song_id'    => $sId,
                    'title'      => $s['title'],
                    'status'     => $matchRes['status'],
                    'video_id'   => $matchRes['video_id'] ?? null,
                    'score'      => $matchRes['score'] ?? null,
                    'candidate'  => $matchRes['candidate']['title'] ?? null,
                    'message'    => $matchRes['message']
                ];
            } catch (\Throwable $e) {
                $failedDelta++;
                $batchResults[] = [
                    'song_id' => $sId,
                    'title'   => $s['title'],
                    'status'  => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        $processedCount = count($songs);

        // Update Job state
        $pdo->prepare("
            UPDATE `youtube_matching_jobs` 
            SET `processed_songs` = `processed_songs` + :processed,
                `matched_high_count` = `matched_high_count` + :high,
                `review_medium_count` = `review_medium_count` + :medium,
                `unresolved_low_count` = `unresolved_low_count` + :low,
                `failed_count` = `failed_count` + :failed,
                `skipped_manual_count` = `skipped_manual_count` + :skipped,
                `last_processed_song_id` = :last_id,
                `updated_at` = NOW()
            WHERE `job_id` = :id
        ")->execute([
            ':processed' => $processedCount,
            ':high'      => $matchedHighDelta,
            ':medium'    => $reviewMediumDelta,
            ':low'       => $unresolvedLowDelta,
            ':failed'    => $failedDelta,
            ':skipped'   => $skippedManualDelta,
            ':last_id'   => $lastSongId,
            ':id'        => $jobId
        ]);

        $updatedJob = self::get_or_create_active_job($userId);
        $isComplete = ((int)$updatedJob['processed_songs'] >= (int)$updatedJob['total_songs']);
        if ($isComplete) {
            $pdo->prepare("UPDATE `youtube_matching_jobs` SET `status` = 'completed', `completed_at` = NOW() WHERE `job_id` = :id")
                ->execute([':id' => $jobId]);
            $updatedJob['status'] = 'completed';
        }

        return [
            'success'   => true,
            'completed' => $isComplete,
            'job'       => $updatedJob,
            'processed' => $processedCount,
            'results'   => $batchResults,
            'message'   => "Processed {$processedCount} songs."
        ];
    }

    /**
     * Reset job progress so bulk matching can be started cleanly from the beginning.
     */
    public static function reset_job(?int $userId = null): void {
        $pdo = get_db_connection();
        if ($userId !== null) {
            $tStmt = $pdo->prepare("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0 AND (`user_id` = :uid OR `user_id` IS NULL)");
            $tStmt->execute([':uid' => $userId]);
            $totalSongs = (int)$tStmt->fetchColumn();

            $pdo->prepare("
                UPDATE `youtube_matching_jobs` 
                SET `status` = 'idle',
                    `total_songs` = :tot,
                    `processed_songs` = 0,
                    `matched_high_count` = 0,
                    `review_medium_count` = 0,
                    `unresolved_low_count` = 0,
                    `failed_count` = 0,
                    `skipped_manual_count` = 0,
                    `last_processed_song_id` = 0,
                    `completed_at` = NULL,
                    `updated_at` = NOW()
                WHERE `user_id` = :uid
            ")->execute([':tot' => $totalSongs, ':uid' => $userId]);
        } else {
            $totalSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0")->fetchColumn();
            $pdo->prepare("
                UPDATE `youtube_matching_jobs` 
                SET `status` = 'idle',
                    `total_songs` = :tot,
                    `processed_songs` = 0,
                    `matched_high_count` = 0,
                    `review_medium_count` = 0,
                    `unresolved_low_count` = 0,
                    `failed_count` = 0,
                    `skipped_manual_count` = 0,
                    `last_processed_song_id` = 0,
                    `completed_at` = NULL,
                    `updated_at` = NOW()
                WHERE `user_id` IS NULL
            ")->execute([':tot' => $totalSongs]);
        }
        self::clear_matching_stats_cache($userId);
    }

    /**
     * Request-scoped cache container for catalogue matching statistics keyed by user ID or 'global'.
     */
    private static array $matchingStatsCache = [];

    /**
     * Cache whether the active database engine supports window functions (e.g. MySQL 8.0+, MariaDB 10.2+, TiDB 3.0+).
     */
    private static ?bool $engineSupportsWindowFunctions = null;

    /**
     * Probe whether the database engine supports SQL window functions (ROW_NUMBER, OVER).
     */
    public static function supports_window_functions(): bool {
        if (self::$engineSupportsWindowFunctions !== null) {
            return self::$engineSupportsWindowFunctions;
        }

        try {
            $pdo = get_db_connection();
            $pdo->query("SELECT ROW_NUMBER() OVER () LIMIT 0");
            self::$engineSupportsWindowFunctions = true;
        } catch (\Throwable $e) {
            self::$engineSupportsWindowFunctions = false;
        }

        return self::$engineSupportsWindowFunctions;
    }

    /**
     * Fallback candidate hydration for database engines without window function support.
     * Executes grouped count query followed by top candidate lookup with deterministic tie-breaking.
     */
    public static function hydrate_candidates_fallback(PDO $pdo, array $songIds): array {
        if (empty($songIds)) {
            return [];
        }
        $candMap = [];
        $placeholders = implode(',', array_fill(0, count($songIds), '?'));

        try {
            $cntSql = "SELECT song_id, COUNT(*) AS cnt FROM song_youtube_candidates WHERE song_id IN ({$placeholders}) GROUP BY song_id";
            $cStmt = $pdo->prepare($cntSql);
            $cStmt->execute($songIds);
            $counts = $cStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $topSql = "SELECT song_id, confidence, score_total, video_id, title, channel_title, thumbnail_url 
                       FROM song_youtube_candidates WHERE song_id = ? ORDER BY score_total DESC, video_id ASC LIMIT 1";
            $topStmt = $pdo->prepare($topSql);
            foreach ($songIds as $sid) {
                if (!empty($counts[$sid])) {
                    $topStmt->execute([$sid]);
                    $top = $topStmt->fetch();
                    if ($top) {
                        $top['cand_count'] = (int)$counts[$sid];
                        $candMap[$sid] = $top;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("AFM2 Candidate Hydration Fallback Error: " . $e->getMessage());
        }

        return $candMap;
    }

    /**
     * Clear request-scoped matching stats cache.
     */
    public static function clear_matching_stats_cache(?int $userId = null): void {
        if ($userId !== null) {
            unset(self::$matchingStatsCache[(string)$userId]);
        } else {
            self::$matchingStatsCache = [];
        }
    }

    /**
     * Catalogue-wide matching stats across songs, candidates, and media.
     * Memoized per-request to avoid redundant full-scan aggregations across controllers and templates.
     */
    public static function get_matching_stats(bool $forceRefresh = false, ?int $userId = null): array {
        $cacheKey = $userId !== null ? (string)$userId : 'global';
        if ($forceRefresh) {
            unset(self::$matchingStatsCache[$cacheKey]);
        }

        if (isset(self::$matchingStatsCache[$cacheKey])) {
            return self::$matchingStatsCache[$cacheKey];
        }

        $pdo = get_db_connection();

        $totalSongs = null;
        if (function_exists('get_catalogue_song_count')) {
            $totalSongs = get_catalogue_song_count($userId, $forceRefresh);
        }
        if ($totalSongs === null) {
            if ($userId !== null) {
                $tStmt = $pdo->prepare("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0 AND (`user_id` = :uid OR `user_id` IS NULL)");
                $tStmt->execute([':uid' => $userId]);
                $totalSongs = (int)$tStmt->fetchColumn();
            } else {
                $totalSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `is_consolidated` = 0")->fetchColumn();
            }
        }

        $userCond = ($userId !== null) ? " AND (s.`user_id` = :uid OR s.`user_id` IS NULL)" : "";
        $params = ($userId !== null) ? [':uid' => $userId] : [];

        $mStmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_media,
                SUM(CASE WHEN sm.`is_manual` = 1 THEN 1 ELSE 0 END) AS manual_count,
                SUM(CASE WHEN sm.`is_manual` = 0 AND sm.`confidence` = 'high' THEN 1 ELSE 0 END) AS auto_high_count,
                SUM(CASE WHEN sm.`embed_status` = 'blocked' THEN 1 ELSE 0 END) AS blocked_count
            FROM `song_media` sm
            JOIN `songs` s ON sm.`song_id` = s.`song_id` AND s.`is_consolidated` = 0 {$userCond}
            WHERE sm.`service` = 'youtube'
        ");
        $mStmt->execute($params);
        $mediaCounts = $mStmt->fetch();

        $cStmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT c.`song_id`) AS songs_with_candidates,
                COUNT(DISTINCT CASE WHEN c.`confidence` = 'medium' AND (sm.`media_id` IS NULL OR sm.`is_manual` = 0) THEN c.`song_id` END) AS review_needed_count,
                COUNT(DISTINCT CASE WHEN c.`confidence` = 'low' AND sm.`media_id` IS NULL THEN c.`song_id` END) AS low_unresolved_count
            FROM `song_youtube_candidates` c
            JOIN `songs` s ON c.`song_id` = s.`song_id` AND s.`is_consolidated` = 0 {$userCond}
            LEFT JOIN `song_media` sm ON c.`song_id` = sm.`song_id`
        ");
        $cStmt->execute($params);
        $candCounts = $cStmt->fetch();

        $matchedTotal = (int)($mediaCounts['total_media'] ?? 0);
        $unmatchedTotal = max(0, $totalSongs - $matchedTotal);
        $manualCount = (int)($mediaCounts['manual_count'] ?? 0);
        $autoHighCount = (int)($mediaCounts['auto_high_count'] ?? 0);
        $blockedCount = (int)($mediaCounts['blocked_count'] ?? 0);
        $reviewNeeded = (int)($candCounts['review_needed_count'] ?? 0);
        $lowUnresolved = (int)($candCounts['low_unresolved_count'] ?? 0);

        self::$matchingStatsCache[$cacheKey] = [
            'total_songs'      => $totalSongs,
            'matched_total'    => $matchedTotal,
            'unmatched_total'  => $unmatchedTotal,
            'manual_count'     => $manualCount,
            'auto_high_count'  => $autoHighCount,
            'review_needed'    => $reviewNeeded,
            'low_unresolved'   => $lowUnresolved,
            'blocked_count'    => $blockedCount
        ];

        return self::$matchingStatsCache[$cacheKey];
    }

    /**
     * Paginated catalogue listing with recording status, candidate counts, and top candidate info.
     */
    public static function get_recordings_catalogue(array $params = [], ?int $userId = null): array {
        $pdo = get_db_connection();

        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = (int)($params['per_page'] ?? 25);
        if ($perPage < 10) $perPage = 10;
        if ($perPage > 100) $perPage = 100;

        $filter = $params['filter'] ?? 'all';
        $search = trim($params['q'] ?? '');
        $sort = $params['sort'] ?? 'id_asc';

        $whereClauses = ["s.`is_consolidated` = 0"];
        $bindings = [];
        if ($userId !== null) {
            $whereClauses[] = "(s.`user_id` = :uid OR s.`user_id` IS NULL)";
            $bindings[':uid'] = $userId;
        }

        if ($search !== '') {
            $whereClauses[] = "(s.`title` LIKE :q1 OR s.`artist` LIKE :q2 OR sm.`video_id` LIKE :q3)";
            $bindings[':q1'] = "%{$search}%";
            $bindings[':q2'] = "%{$search}%";
            $bindings[':q3'] = "%{$search}%";
        }

        switch ($filter) {
            case 'unmatched':
                $whereClauses[] = "sm.`media_id` IS NULL";
                break;
            case 'high':
                $whereClauses[] = "sm.`confidence` = 'high'";
                break;
            case 'review':
                $whereClauses[] = "(sm.`is_manual` = 0 OR sm.`media_id` IS NULL) AND EXISTS (SELECT 1 FROM `song_youtube_candidates` c3 WHERE c3.`song_id` = s.`song_id` AND c3.`confidence` = 'medium')";
                break;
            case 'low':
                $whereClauses[] = "sm.`media_id` IS NULL AND EXISTS (SELECT 1 FROM `song_youtube_candidates` c3 WHERE c3.`song_id` = s.`song_id`) AND NOT EXISTS (SELECT 1 FROM `song_youtube_candidates` c4 WHERE c4.`song_id` = s.`song_id` AND c4.`confidence` IN ('high', 'medium'))";
                break;
            case 'manual':
                $whereClauses[] = "sm.`is_manual` = 1";
                break;
            case 'blocked':
                $whereClauses[] = "sm.`embed_status` = 'blocked'";
                break;
            case 'all':
            default:
                break;
        }

        $whereSql = implode(' AND ', $whereClauses);

        // Count total matching
        $countSql = "
            SELECT COUNT(*) 
            FROM `songs` s
            LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
            WHERE {$whereSql}
        ";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($bindings);
        $total = (int)$countStmt->fetchColumn();

        // Sort order
        switch ($sort) {
            case 'id_desc':
                $orderSql = "s.`song_id` DESC";
                break;
            case 'title_asc':
                $orderSql = "s.`title` ASC, s.`song_id` ASC";
                break;
            case 'title_desc':
                $orderSql = "s.`title` DESC, s.`song_id` ASC";
                break;
            case 'artist_asc':
                $orderSql = "s.`artist` ASC, s.`title` ASC";
                break;
            case 'artist_desc':
                $orderSql = "s.`artist` DESC, s.`title` ASC";
                break;
            case 'score_desc':
                $orderSql = "sm.`match_score` DESC, s.`song_id` ASC";
                break;
            case 'score_asc':
                $orderSql = "sm.`match_score` ASC, s.`song_id` ASC";
                break;
            case 'id_asc':
            default:
                $orderSql = "s.`song_id` ASC";
                break;
        }

        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        // Decoupled pagination query: selects only the page items without expensive correlated subqueries
        $dataSql = "
            SELECT 
                s.`song_id`,
                s.`title`,
                s.`artist`,
                sm.`media_id`,
                sm.`video_id`,
                sm.`user_url`,
                sm.`version_type`,
                sm.`match_method`,
                sm.`match_score`,
                sm.`confidence` AS media_confidence,
                sm.`verification_status`,
                sm.`match_decision_notes`,
                sm.`is_manual`,
                sm.`embed_status`,
                sa.`thumbnail_url` AS artwork_thumbnail_url,
                sa.`image_url` AS artwork_image_url
            FROM `songs` s
            LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
            LEFT JOIN `song_artwork` sa ON s.`song_id` = sa.`song_id`
            WHERE {$whereSql}
            ORDER BY {$orderSql}
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

        $dataStmt = $pdo->prepare($dataSql);
        $dataStmt->execute($bindings);
        $items = $dataStmt->fetchAll();

        // Batch hydrate candidate counts and top candidate info for the current page
        if (!empty($items)) {
            $songIds = array_map('intval', array_column($items, 'song_id'));
            $candMap = [];

            if (self::supports_window_functions()) {
                $placeholders = implode(',', array_fill(0, count($songIds), '?'));
                try {
                    // High-performance window function: single query resolves both counts and top candidates
                    $winSql = "
                        SELECT song_id, confidence, score_total, video_id, title, channel_title, thumbnail_url, cand_count
                        FROM (
                            SELECT song_id, confidence, score_total, video_id, title, channel_title, thumbnail_url,
                                   ROW_NUMBER() OVER (PARTITION BY song_id ORDER BY score_total DESC, video_id ASC) AS rn,
                                   COUNT(*) OVER (PARTITION BY song_id) AS cand_count
                            FROM song_youtube_candidates
                            WHERE song_id IN ({$placeholders})
                        ) ranked
                        WHERE rn = 1
                    ";
                    $winStmt = $pdo->prepare($winSql);
                    $winStmt->execute($songIds);
                    while ($cRow = $winStmt->fetch()) {
                        $candMap[(int)$cRow['song_id']] = $cRow;
                    }
                } catch (\Throwable $e) {
                    // Query failure on an engine that supports window functions is a real database error, not unsupported syntax
                    error_log("AFM2 Window Query Execution Error: " . $e->getMessage());
                    // Fall back for fault resilience
                    $candMap = self::hydrate_candidates_fallback($pdo, $songIds);
                }
            } else {
                // Database engine lacks window function support: execute fallback query path
                $candMap = self::hydrate_candidates_fallback($pdo, $songIds);
            }

            // Hydrate items array with candidate metrics matching expected schema
            foreach ($items as &$item) {
                $sId = (int)$item['song_id'];
                if (isset($candMap[$sId])) {
                    $c = $candMap[$sId];
                    $item['candidate_count']     = (int)$c['cand_count'];
                    $item['top_cand_confidence'] = $c['confidence'];
                    $item['top_cand_score']      = $c['score_total'];
                    $item['top_cand_video_id']   = $c['video_id'];
                    $item['top_cand_title']      = $c['title'];
                    $item['top_cand_channel']    = $c['channel_title'];
                    $item['top_cand_thumb']      = $c['thumbnail_url'];
                } else {
                    $item['candidate_count']     = 0;
                    $item['top_cand_confidence'] = null;
                    $item['top_cand_score']      = null;
                    $item['top_cand_video_id']   = null;
                    $item['top_cand_title']      = null;
                    $item['top_cand_channel']    = null;
                    $item['top_cand_thumb']      = null;
                }
            }
            unset($item);
        }

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'filter'      => $filter,
            'sort'        => $sort,
            'query'       => $search,
            'stats'       => self::get_matching_stats(false, $userId)
        ];
    }
}

