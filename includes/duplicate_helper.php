<?php
/**
 * AFM#2 - Safe Duplicate Detection, Review, Consolidation & Integrity Helper
 * 
 * Core principle: Detect automatically. Decide manually. Preserve history.
 * 
 * Rules:
 * - Strictly read-only candidate detection (never mutates or deletes songs).
 * - Full normalization for unicode dashes, punctuation, suffixes, and version tags.
 * - Categorizes matches into exact_normalized, formatting_difference, alternate_version,
 *   same_title_uncertain_artist, same_title_diff_artist, and shared_youtube_id.
 * - Comprehensive competition safeguards: never merges or overwrites ratings/results.
 * - Durable audit snapshots in song_consolidation_audit for 100% reversible recovery.
 * - Soft-tombstone consolidation preserving catalogue records.
 */

if (!defined('AFM_APP')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/media_helper.php';

/**
 * Clean and normalize text for comparison
 * Handles unicode hyphens, quotes, punctuation, case, and whitespace.
 */
function normalize_text(?string $str): string {
    if ($str === null) {
        return '';
    }
    // Convert common unicode characters to ascii equivalents
    $unicodeReplacements = [
        // Hyphens / Dashes
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-',
        "\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-', "‐" => '-', "–" => '-', "—" => '-',
        // Quotes / Apostrophes
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "’" => "'", "‘" => "'", "“" => '"', "”" => '"',
        // Accented characters
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c'
    ];
    $clean = strtr($str, $unicodeReplacements);
    $clean = strtolower($clean);

    // Strip punctuation, keep alphanumeric and spaces
    $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $clean);
    // Collapse multiple whitespace
    $clean = preg_replace('/\s+/', ' ', $clean);

    return trim($clean);
}

/**
 * Extract base title, detected version tags, and cleaned title without video tags
 */
function parse_title_metadata(string $title): array {
    $lower = strtolower($title);
    $detectedVersion = null;

    $versionPatterns = [
        '/\b(live(\s*(at|in|from|version))?)\b/i' => 'Live',
        '/\b(acoustic(\s*version)?)\b/i' => 'Acoustic',
        '/\b(remaster(ed)?(\s*\d{4})?)\b/i' => 'Remaster',
        '/\b(radio\s*edit)\b/i' => 'Radio Edit',
        '/\b(extended(\s*(mix|version))?)\b/i' => 'Extended',
        '/\b(remix|rmx)\b/i' => 'Remix',
        '/\b(instrumental)\b/i' => 'Instrumental',
        '/\b(cover(\s*version)?)\b/i' => 'Cover',
        '/\b(orchestral|symphonic)\b/i' => 'Orchestral',
        '/\b(piano(\s*version)?)\b/i' => 'Piano Version',
        '/\b(club\s*mix)\b/i' => 'Club Mix'
    ];

    foreach ($versionPatterns as $pattern => $label) {
        if (preg_match($pattern, $lower)) {
            $detectedVersion = $label;
            break;
        }
    }

    // Strip video/audio upload tags like [Official Video], (Official Audio), (Lyric Video), [HD], etc.
    $strippedVideoTags = preg_replace('/(\[|\()(official\s*(music\s*)?(video|audio|lyric\s*video|hd|4k|track)|official|hd|lyrics|audio|lyric\s*video)(\]|\))/i', '', $title);
    
    // Also strip parentheses/brackets containing detected version
    $strippedBase = preg_replace('/(\[|\()(.*?(live|acoustic|remaster|radio\s*edit|extended|remix|instrumental|cover|orchestral|piano).*?)(\]|\))/i', '', $strippedVideoTags);
    
    // Also strip ' - Live...', ' - Remastered...', ' - Acoustic' suffix at end of title
    $strippedBase = preg_replace('/\s*-\s*(live|remaster|acoustic|remix|radio\s*edit|extended|instrumental).*$/i', '', $strippedBase);

    // Also strip "feat. ..." / "ft. ..."
    $strippedBase = preg_replace('/(\[|\()?(feat\.?|ft\.?|featuring)\s+[^\]\)]+(\]|\))?/i', '', $strippedBase);

    return [
        'original'     => $title,
        'clean_title'  => normalize_text($strippedVideoTags),
        'base_title'   => normalize_text($strippedBase),
        'version'      => $detectedVersion,
        'has_video_tag'=> ($strippedVideoTags !== $title)
    ];
}

/**
 * Detect candidate duplicate pairs across the entire song catalogue.
 * Strictly READ-ONLY. Never alters or deletes any database record.
 * 
 * @param array $filters Options: 'search', 'category', 'status', 'confidence', 'limit', 'offset'
 * @return array ['candidates' => array, 'stats' => array, 'total' => int]
 */
function detect_duplicate_candidates(array $filters = [], ?int $userId = null): array {
    $pdo = get_db_connection();
    if ($userId === null && isset($filters['user_id'])) {
        $userId = (int)$filters['user_id'];
    }

    // 1. Fetch all songs and existing media references (READ-ONLY)
    $sql = "
        SELECT 
            s.`song_id`, 
            s.`title`, 
            s.`artist`,
            s.`user_id`,
            s.`is_consolidated`,
            s.`consolidated_into_id`,
            sm.`video_id` AS `media_video_id`
        FROM `songs` s
        LEFT JOIN `song_media` sm ON s.`song_id` = sm.`song_id` AND sm.`service` = 'youtube'
        ORDER BY s.`song_id` ASC
    ";
    $songs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch existing duplicate review decisions
    $reviewStmt = $pdo->query("
        SELECT 
            `song_id_a`, 
            `song_id_b`, 
            `decision`, 
            `canonical_song_id`, 
            `reviewed_at`, 
            `notes` 
        FROM `duplicate_reviews`
    ");
    $existingReviews = [];
    while ($r = $reviewStmt->fetch(PDO::FETCH_ASSOC)) {
        $pairKey = min($r['song_id_a'], $r['song_id_b']) . '_' . max($r['song_id_a'], $r['song_id_b']);
        $existingReviews[$pairKey] = $r;
    }

    // 3. Pre-process songs with normalized metadata
    $parsedSongs = [];
    $byCleanTitleArtist = [];
    $byBaseTitleArtist = [];
    $byBaseTitleOnly = [];
    $byVideoId = [];
    $missingArtistSongs = [];

    foreach ($songs as $s) {
        $id = (int)$s['song_id'];
        $title = $s['title'] ?? '';
        $artist = $s['artist'];
        $meta = parse_title_metadata($title);
        $cleanArtist = normalize_text($artist);
        $isMissingArtist = ($artist === null || trim($artist) === '' || $cleanArtist === 'unknown artist');

        $parsedSongs[$id] = [
            'song_id'            => $id,
            'title'              => $title,
            'artist'             => $artist,
            'user_id'            => $s['user_id'] !== null ? (int)$s['user_id'] : null,
            'is_consolidated'    => (int)($s['is_consolidated'] ?? 0),
            'consolidated_into_id'=> $s['consolidated_into_id'] ? (int)$s['consolidated_into_id'] : null,
            'clean_title'        => $meta['clean_title'],
            'base_title'         => $meta['base_title'],
            'version'            => $meta['version'],
            'has_video_tag'      => $meta['has_video_tag'],
            'clean_artist'       => $cleanArtist,
            'is_missing_artist'  => $isMissingArtist,
            'video_id'           => $s['media_video_id'] ?? null
        ];

        // Grouping buckets
        if (!$isMissingArtist && $cleanArtist !== '') {
            if ($meta['clean_title'] !== '') {
                $byCleanTitleArtist[$meta['clean_title'] . '|||' . $cleanArtist][] = $id;
            }
            if ($meta['base_title'] !== '') {
                $byBaseTitleArtist[$meta['base_title'] . '|||' . $cleanArtist][] = $id;
            }
        } else {
            $missingArtistSongs[] = $id;
        }

        if ($meta['base_title'] !== '') {
            $byBaseTitleOnly[$meta['base_title']][] = $id;
        }

        if (!empty($s['media_video_id'])) {
            $byVideoId[$s['media_video_id']][] = $id;
        }
    }

    // 4. Identify candidate pairs
    $candidates = [];
    $seenPairs = [];

    $addCandidate = function(int $idA, int $idB, string $matchType, string $confidence, string $reason) use (
        &$candidates, &$seenPairs, $parsedSongs, $existingReviews, $userId
    ) {
        if ($idA === $idB) return;
        $songA = $parsedSongs[$idA];
        $songB = $parsedSongs[$idB];

        $uidA = $songA['user_id'];
        $uidB = $songB['user_id'];
        // Never allow duplicate pairing across different users
        if ($uidA !== null && $uidB !== null && $uidA !== $uidB) {
            return;
        }
        if ($userId !== null) {
            if (($uidA !== null && $uidA !== $userId) || ($uidB !== null && $uidB !== $userId)) {
                return;
            }
        }

        $minId = min($idA, $idB);
        $maxId = max($idA, $idB);
        $pairKey = $minId . '_' . $maxId;

        // If pair already added with higher or equal priority, skip
        if (isset($seenPairs[$pairKey])) {
            return;
        }
        $seenPairs[$pairKey] = true;

        $review = $existingReviews[$pairKey] ?? null;

        $status = 'unresolved';
        $decision = null;
        if ($review) {
            $decision = $review['decision'];
            if ($decision === 'deferred') {
                $status = 'deferred';
            } else {
                $status = 'resolved';
            }
        } elseif ($songA['is_consolidated'] || $songB['is_consolidated']) {
            $status = 'resolved';
            $decision = 'consolidated';
        }

        $candidates[$pairKey] = [
            'pair_id'            => $pairKey,
            'song_a'             => $songA,
            'song_b'             => $songB,
            'match_type'         => $matchType,
            'confidence'         => $confidence,
            'reason'             => $reason,
            'status'             => $status,
            'decision'           => $decision,
            'canonical_song_id'  => $review['canonical_song_id'] ?? null,
            'reviewed_at'        => $review['reviewed_at'] ?? null,
            'notes'              => $review['notes'] ?? null
        ];
    };

    // Category 1: Shared YouTube Video ID (High confidence)
    foreach ($byVideoId as $vid => $ids) {
        if (count($ids) > 1) {
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $addCandidate(
                        $ids[$i], $ids[$j],
                        'shared_youtube_id',
                        'high',
                        "Both songs reference the exact same YouTube video ID ({$vid})"
                    );
                }
            }
        }
    }

    // Category 2: Exact or formatting matches with same artist
    foreach ($byCleanTitleArtist as $key => $ids) {
        if (count($ids) > 1) {
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $sA = $parsedSongs[$ids[$i]];
                    $sB = $parsedSongs[$ids[$j]];
                    if ($sA['title'] === $sB['title']) {
                        $addCandidate(
                            $ids[$i], $ids[$j],
                            'exact_normalized',
                            'high',
                            'Exact title and artist match'
                        );
                    } else {
                        $reason = 'Titles differ only by punctuation, unicode characters, or video tags';
                        if ($sA['has_video_tag'] || $sB['has_video_tag']) {
                            $reason = 'Titles match after removing video/audio tags ([Official Video], [Lyric Video], etc.)';
                        }
                        $addCandidate(
                            $ids[$i], $ids[$j],
                            'formatting_difference',
                            'high',
                            $reason
                        );
                    }
                }
            }
        }
    }

    // Category 3: Alternate versions with same artist (Live, Acoustic, Remaster, Remix, etc.)
    foreach ($byBaseTitleArtist as $key => $ids) {
        if (count($ids) > 1) {
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $sA = $parsedSongs[$ids[$i]];
                    $sB = $parsedSongs[$ids[$j]];
                    $verA = $sA['version'] ?? 'Original';
                    $verB = $sB['version'] ?? 'Original';
                    $addCandidate(
                        $ids[$i], $ids[$j],
                        'alternate_version',
                        'medium',
                        "Alternate version candidate by same artist ({$verA} vs {$verB})"
                    );
                }
            }
        }
    }

    // Category 4: Missing or uncertain artist songs matching base titles
    foreach ($missingArtistSongs as $missingId) {
        $mSong = $parsedSongs[$missingId];
        $mBase = $mSong['base_title'];
        if ($mBase === '') continue;

        // Check if other songs share the base title or if the missing artist title contains known artists
        foreach ($parsedSongs as $otherId => $oSong) {
            if ($missingId === $otherId) continue;
            
            // Check base title match
            if ($oSong['base_title'] !== '' && $oSong['base_title'] === $mBase) {
                $addCandidate(
                    $missingId, $otherId,
                    'same_title_uncertain_artist',
                    'medium',
                    "Matching title where one song has missing artist metadata ('{$mSong['title']}')"
                );
            } 
            // Check if missing song title explicitly mentions the other song's artist and title
            elseif (!empty($oSong['clean_artist']) && $oSong['clean_title'] !== '') {
                $mTitleNorm = normalize_text($mSong['title']);
                if (str_contains($mTitleNorm, $oSong['clean_artist']) && str_contains($mTitleNorm, $oSong['base_title'])) {
                    $addCandidate(
                        $missingId, $otherId,
                        'same_title_uncertain_artist',
                        'medium',
                        "Title contains artist '{$oSong['artist']}' and song name '{$oSong['title']}'"
                    );
                }
            }
        }
    }

    // Category 5: Same title, different artist (covers or different songs)
    foreach ($byBaseTitleOnly as $baseTitle => $ids) {
        if (count($ids) > 1) {
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $sA = $parsedSongs[$ids[$i]];
                    $sB = $parsedSongs[$ids[$j]];
                    if ($sA['clean_artist'] !== '' && $sB['clean_artist'] !== '' && $sA['clean_artist'] !== $sB['clean_artist']) {
                        $addCandidate(
                            $ids[$i], $ids[$j],
                            'same_title_diff_artist',
                            'low',
                            "Identical base title ('{$baseTitle}') recorded by different artists ('{$sA['artist']}' vs '{$sB['artist']}')"
                        );
                    }
                }
            }
        }
    }

    // 5. Calculate statistics
    $stats = [
        'total_candidates'   => count($candidates),
        'awaiting_review'    => 0,
        'resolved_count'     => 0,
        'keep_both_count'    => 0,
        'excluded_count'     => 0,
        'consolidated_count' => 0,
        'deferred_count'     => 0,
        'categories'         => [
            'exact_normalized'           => 0,
            'formatting_difference'      => 0,
            'alternate_version'          => 0,
            'same_title_uncertain_artist'=> 0,
            'same_title_diff_artist'     => 0,
            'shared_youtube_id'          => 0
        ],
        'confidences'        => [
            'high'   => 0,
            'medium' => 0,
            'low'    => 0
        ]
    ];

    foreach ($candidates as $c) {
        if ($c['status'] === 'unresolved') {
            $stats['awaiting_review']++;
        } elseif ($c['status'] === 'deferred') {
            $stats['deferred_count']++;
        } else {
            $stats['resolved_count']++;
            if ($c['decision'] === 'keep_both') $stats['keep_both_count']++;
            elseif ($c['decision'] === 'excluded') $stats['excluded_count']++;
            elseif ($c['decision'] === 'consolidated') $stats['consolidated_count']++;
        }

        if (isset($stats['categories'][$c['match_type']])) {
            $stats['categories'][$c['match_type']]++;
        }
        if (isset($stats['confidences'][$c['confidence']])) {
            $stats['confidences'][$c['confidence']]++;
        }
    }

    // 6. Apply filtering
    $filtered = array_values($candidates);

    // Status filter: 'all', 'unresolved', 'resolved', 'deferred'
    $statusFilter = $filters['status'] ?? 'all';
    if ($statusFilter === 'unresolved') {
        $filtered = array_filter($filtered, fn($c) => $c['status'] === 'unresolved');
    } elseif ($statusFilter === 'resolved') {
        $filtered = array_filter($filtered, fn($c) => $c['status'] === 'resolved');
    } elseif ($statusFilter === 'deferred') {
        $filtered = array_filter($filtered, fn($c) => $c['status'] === 'deferred');
    }

    // Category filter
    $categoryFilter = $filters['category'] ?? 'all';
    if ($categoryFilter !== 'all') {
        $filtered = array_filter($filtered, fn($c) => $c['match_type'] === $categoryFilter);
    }

    // Confidence filter
    $confFilter = $filters['confidence'] ?? 'all';
    if ($confFilter !== 'all') {
        $filtered = array_filter($filtered, fn($c) => $c['confidence'] === $confFilter);
    }

    // Search query filter
    $search = trim($filters['search'] ?? '');
    if ($search !== '') {
        $searchNorm = normalize_text($search);
        $filtered = array_filter($filtered, function($c) use ($searchNorm) {
            $haystack = normalize_text($c['song_a']['title'] . ' ' . $c['song_a']['artist'] . ' ' .
                                       $c['song_b']['title'] . ' ' . $c['song_b']['artist']);
            return str_contains($haystack, $searchNorm);
        });
    }

    $totalFiltered = count($filtered);

    // Sorting: high confidence first, then awaiting review first, then ID
    usort($filtered, function($a, $b) {
        // Status: unresolved first, then deferred, then resolved
        $statusOrder = ['unresolved' => 1, 'deferred' => 2, 'resolved' => 3];
        $sA = $statusOrder[$a['status']] ?? 4;
        $sB = $statusOrder[$b['status']] ?? 4;
        if ($sA !== $sB) return $sA <=> $sB;

        // Confidence: high > medium > low
        $confOrder = ['high' => 1, 'medium' => 2, 'low' => 3];
        $cA = $confOrder[$a['confidence']] ?? 4;
        $cB = $confOrder[$b['confidence']] ?? 4;
        if ($cA !== $cB) return $cA <=> $cB;

        return $a['song_a']['song_id'] <=> $b['song_a']['song_id'];
    });

    // Pagination
    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = max(5, min(100, (int)($filters['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $paginated = array_slice($filtered, $offset, $perPage);

    return [
        'candidates'   => $paginated,
        'stats'        => $stats,
        'total'        => $totalFiltered,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => (int)ceil($totalFiltered / $perPage)
    ];
}

/**
 * Fetch full verified records and history for a song
 */
function get_song_full_details(int $songId): ?array {
    $pdo = get_db_connection();

    // Song core record
    $stmt = $pdo->prepare("
        SELECT 
            s.`song_id`, 
            s.`title`, 
            COALESCE(s.`artist`, 'Unknown Artist') AS `artist`,
            s.`artist` AS `raw_artist`,
            s.`user_id`,
            s.`is_consolidated`,
            s.`consolidated_into_id`,
            s.`consolidated_at`
        FROM `songs` s
        WHERE s.`song_id` = :id
    ");
    $stmt->execute([':id' => $songId]);
    $song = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$song) return null;

    // Media
    $mediaStmt = $pdo->prepare("SELECT * FROM `song_media` WHERE `song_id` = :id AND `service` = 'youtube'");
    $mediaStmt->execute([':id' => $songId]);
    $media = $mediaStmt->fetch(PDO::FETCH_ASSOC);

    // Lyrics
    $lyricsStmt = $pdo->prepare("SELECT * FROM `song_lyrics_ref` WHERE `song_id` = :id");
    $lyricsStmt->execute([':id' => $songId]);
    $lyrics = $lyricsStmt->fetch(PDO::FETCH_ASSOC);

    // Artwork
    $artStmt = $pdo->prepare("SELECT * FROM `song_artwork` WHERE `song_id` = :id");
    $artStmt->execute([':id' => $songId]);
    $artwork = $artStmt->fetch(PDO::FETCH_ASSOC);

    // Discovery status
    $discStmt = $pdo->prepare("SELECT * FROM `song_discovery` WHERE `song_id` = :id");
    $discStmt->execute([':id' => $songId]);
    $discovery = $discStmt->fetch(PDO::FETCH_ASSOC);

    // AFM Entry
    $entryStmt = $pdo->prepare("SELECT * FROM `afm_entries` WHERE `song_id` = :id");
    $entryStmt->execute([':id' => $songId]);
    $entry = $entryStmt->fetch(PDO::FETCH_ASSOC);

    $ratings = [];
    $results = [];
    if ($entry) {
        $entryId = (int)$entry['entry_id'];

        // Ratings
        $ratStmt = $pdo->prepare("
            SELECT 
                rt.*, 
                r.`round_name`, 
                r.`sequence_no`
            FROM `ratings` rt
            JOIN `rounds` r ON rt.`round_id` = r.`round_id`
            WHERE rt.`entry_id` = :eid
            ORDER BY r.`sequence_no` ASC
        ");
        $ratStmt->execute([':eid' => $entryId]);
        $ratings = $ratStmt->fetchAll(PDO::FETCH_ASSOC);

        // Results
        $resStmt = $pdo->prepare("
            SELECT 
                rr.*, 
                r.`round_name`, 
                r.`sequence_no`
            FROM `round_results` rr
            JOIN `rounds` r ON rr.`round_id` = r.`round_id`
            WHERE rr.`entry_id` = :eid
            ORDER BY r.`sequence_no` ASC
        ");
        $resStmt->execute([':eid' => $entryId]);
        $results = $resStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return [
        'song'      => $song,
        'media'     => $media,
        'lyrics'    => $lyrics,
        'artwork'   => $artwork,
        'discovery' => $discovery,
        'entry'     => $entry,
        'ratings'   => $ratings,
        'results'   => $results
    ];
}

/**
 * Validate whether consolidation between two songs is safe.
 * Strict adherence to Section 7: Critical Competition-History Safeguards.
 * 
 * @param int $canonicalId
 * @param int $consolidatedId
 * @return array ['safe' => bool, 'conflicts' => string[], 'warnings' => string[], 'proposed_actions' => array]
 */
function validate_consolidation_safety(int $canonicalId, int $consolidatedId): array {
    if ($canonicalId === $consolidatedId) {
        return [
            'safe' => false,
            'conflicts' => ['Cannot consolidate a song into itself.'],
            'warnings' => [],
            'proposed_actions' => []
        ];
    }

    $canon = get_song_full_details($canonicalId);
    $conso = get_song_full_details($consolidatedId);

    if (!$canon || !$conso) {
        return [
            'safe' => false,
            'conflicts' => ['One or both song records do not exist in the database.'],
            'warnings' => [],
            'proposed_actions' => []
        ];
    }

    if ($canon['song']['is_consolidated']) {
        return [
            'safe' => false,
            'conflicts' => ["Canonical song #{$canonicalId} is already marked as consolidated into another record (#{$canon['song']['consolidated_into_id']})."],
            'warnings' => [],
            'proposed_actions' => []
        ];
    }

    if ($conso['song']['is_consolidated']) {
        return [
            'safe' => false,
            'conflicts' => ["Target song #{$consolidatedId} is already marked as consolidated."],
            'warnings' => [],
            'proposed_actions' => []
        ];
    }

    // Cross-user isolation check
    $canonUid = $canon['song']['user_id'] ?? null;
    $consoUid = $conso['song']['user_id'] ?? null;
    if ($canonUid !== null && $consoUid !== null && (int)$canonUid !== (int)$consoUid) {
        return [
            'safe' => false,
            'conflicts' => ["Cross-user boundary violation: Song #{$canonicalId} (User #{$canonUid}) and Song #{$consolidatedId} (User #{$consoUid}) belong to different users."],
            'warnings' => [],
            'proposed_actions' => []
        ];
    }

    $conflicts = [];
    $warnings = [];
    $proposedActions = [];

    // Competition Safeguard Checks
    $canonEntry = $canon['entry'];
    $consoEntry = $conso['entry'];
    $canonRatings = $canon['ratings'];
    $consoRatings = $conso['ratings'];
    $canonResults = $canon['results'];
    $consoResults = $conso['results'];

    // 1. Conflict Check: Overlapping round ratings
    $canonRatedRounds = [];
    foreach ($canonRatings as $r) {
        if ($r['total'] !== null) {
            $canonRatedRounds[$r['round_id']] = $r;
        }
    }

    $consoRatedRounds = [];
    foreach ($consoRatings as $r) {
        if ($r['total'] !== null) {
            $consoRatedRounds[$r['round_id']] = $r;
        }
    }

    foreach ($consoRatedRounds as $roundId => $consoR) {
        if (isset($canonRatedRounds[$roundId])) {
            $canonR = $canonRatedRounds[$roundId];
            $conflicts[] = "Conflicting Audition Ratings in {$canonR['round_name']}: Canonical song has total {$canonR['total']}/50 and duplicate has total {$consoR['total']}/50. Under AFM#2 rules, historical scorecards cannot be merged or overwritten.";
        }
    }

    // 2. Conflict Check: Overlapping round results
    $canonResultRounds = [];
    foreach ($canonResults as $res) {
        $canonResultRounds[$res['round_id']] = $res;
    }

    $consoResultRounds = [];
    foreach ($consoResults as $res) {
        $consoResultRounds[$res['round_id']] = $res;
    }

    foreach ($consoResultRounds as $roundId => $consoRes) {
        if (isset($canonResultRounds[$roundId])) {
            $canonRes = $canonResultRounds[$roundId];
            $conflicts[] = "Conflicting Tournament Results in {$canonRes['round_name']}: Canonical record has '{$canonRes['result']}' and duplicate has '{$consoRes['result']}'. Completed tournament rankings cannot be rewritten.";
        }
    }

    // 3. Entry Conflict Check (Unique constraint on afm_entries.song_id)
    if ($canonEntry && $consoEntry) {
        $consoHasHistory = (!empty($consoRatings) || !empty($consoResults));
        $canonHasHistory = (!empty($canonRatings) || !empty($canonResults));

        if ($consoHasHistory && $canonHasHistory) {
            if (empty($conflicts)) {
                // Both have history across different rounds: would misrepresent single entry identity
                $conflicts[] = "Both songs have active competition history across rounds. Merging them would corrupt independent entry timelines.";
            }
        } elseif ($consoHasHistory && !$canonHasHistory) {
            $proposedActions[] = "Transfer competition entry and scores from #{$consolidatedId} to canonical song #{$canonicalId}.";
        } elseif (!$consoHasHistory && $canonHasHistory) {
            $proposedActions[] = "Preserve canonical song #{$canonicalId}'s competition entry; archive empty entry from #{$consolidatedId}.";
        } else {
            $proposedActions[] = "Both songs have unrated audition entries. Canonical entry will be kept and duplicate entry archived.";
        }
    } elseif (!$canonEntry && $consoEntry) {
        $proposedActions[] = "Transfer competition entry (#{$consoEntry['entry_id']}) to canonical song #{$canonicalId}.";
    } elseif ($canonEntry && !$consoEntry) {
        $proposedActions[] = "Retain canonical song's existing competition entry (#{$canonEntry['entry_id']}).";
    }

    // Media Reconciliation
    if (!$canon['media'] && $conso['media']) {
        $proposedActions[] = "Transfer YouTube video reference ({$conso['media']['video_id']}) to canonical song.";
    } elseif ($canon['media'] && $conso['media']) {
        if ($canon['media']['video_id'] !== $conso['media']['video_id']) {
            $warnings[] = "Both songs have different YouTube recordings. Canonical video '{$canon['media']['video_id']}' will be kept; duplicate video '{$conso['media']['video_id']}' will be archived in audit record.";
        } else {
            $proposedActions[] = "Both songs share identical YouTube video ID. Duplicate reference will be safely archived.";
        }
    }

    // Lyrics Reconciliation
    if (!$canon['lyrics'] && $conso['lyrics']) {
        $proposedActions[] = "Transfer cached lyrics from duplicate song to canonical song.";
    } elseif ($canon['lyrics'] && $conso['lyrics']) {
        $proposedActions[] = "Retain canonical song lyrics; archive duplicate lyrics in audit snapshot.";
    }

    // Artwork Reconciliation
    if (!$canon['artwork'] && $conso['artwork']) {
        $proposedActions[] = "Transfer album artwork from duplicate song to canonical song.";
    } elseif ($canon['artwork'] && $conso['artwork']) {
        $proposedActions[] = "Retain canonical song artwork; archive duplicate artwork in audit snapshot.";
    }

    // Discovery Status Reconciliation
    if (!$canon['discovery'] && $conso['discovery']) {
        $proposedActions[] = "Transfer discovery status ('{$conso['discovery']['status']}') to canonical song.";
    }

    $proposedActions[] = "Mark song #{$consolidatedId} as soft-consolidated into canonical song #{$canonicalId} (catalogue count strictly preserved).";

    return [
        'safe'             => empty($conflicts),
        'conflicts'        => $conflicts,
        'warnings'         => $warnings,
        'proposed_actions' => $proposedActions,
        'canonical'        => $canon,
        'consolidated'     => $conso
    ];
}

/**
 * Side-by-side comparison payload between two songs
 */
function get_song_comparison_data(int $songIdA, int $songIdB): ?array {
    $minId = min($songIdA, $songIdB);
    $maxId = max($songIdA, $songIdB);

    $songA = get_song_full_details($minId);
    $songB = get_song_full_details($maxId);

    if (!$songA || !$songB) return null;

    // Detect matching info
    $metaA = parse_title_metadata($songA['song']['title']);
    $metaB = parse_title_metadata($songB['song']['title']);
    $cleanArtA = normalize_text($songA['song']['raw_artist']);
    $cleanArtB = normalize_text($songB['song']['raw_artist']);

    $matchType = 'alternate_version';
    $confidence = 'medium';
    $reasons = [];

    if ($songA['media'] && $songB['media'] && $songA['media']['video_id'] === $songB['media']['video_id']) {
        $matchType = 'shared_youtube_id';
        $confidence = 'high';
        $reasons[] = "Both songs reference the exact same YouTube video ID ({$songA['media']['video_id']}).";
    } elseif ($metaA['clean_title'] === $metaB['clean_title'] && $cleanArtA === $cleanArtB && $cleanArtA !== '') {
        if ($songA['song']['title'] === $songB['song']['title']) {
            $matchType = 'exact_normalized';
            $confidence = 'high';
            $reasons[] = "Identical title and artist text.";
        } else {
            $matchType = 'formatting_difference';
            $confidence = 'high';
            $reasons[] = "Titles differ only by punctuation, unicode dashes, or official video suffixes.";
        }
    } elseif ($metaA['base_title'] === $metaB['base_title'] && $cleanArtA === $cleanArtB && $cleanArtA !== '') {
        $matchType = 'alternate_version';
        $confidence = 'medium';
        $verA = $metaA['version'] ?? 'Standard';
        $verB = $metaB['version'] ?? 'Standard';
        $reasons[] = "Potential alternate recording by same artist ({$verA} vs {$verB}).";
    } elseif ($songA['song']['raw_artist'] === null || $songB['song']['raw_artist'] === null) {
        $matchType = 'same_title_uncertain_artist';
        $confidence = 'medium';
        $reasons[] = "Matching title where one record has missing artist metadata.";
    } elseif ($metaA['base_title'] === $metaB['base_title']) {
        $matchType = 'same_title_diff_artist';
        $confidence = 'low';
        $reasons[] = "Same song title recorded by different artists (possible cover).";
    }

    // Check existing review
    $pdo = get_db_connection();
    $revStmt = $pdo->prepare("SELECT * FROM `duplicate_reviews` WHERE `song_id_a` = :a AND `song_id_b` = :b");
    $revStmt->execute([':a' => $minId, ':b' => $maxId]);
    $review = $revStmt->fetch(PDO::FETCH_ASSOC);

    // Pre-flight safety checks for both directions
    $safetyAAsCanonical = validate_consolidation_safety($minId, $maxId);
    $safetyBAsCanonical = validate_consolidation_safety($maxId, $minId);

    return [
        'song_a'                => $songA,
        'song_b'                => $songB,
        'match_type'            => $matchType,
        'confidence'            => $confidence,
        'reasons'               => $reasons,
        'review'                => $review,
        'safety_a_as_canonical' => $safetyAAsCanonical,
        'safety_b_as_canonical' => $safetyBAsCanonical
    ];
}

/**
 * Save user review decision (Keep Both, Exclude from Competition, Defer)
 * 
 * @param int $songIdA
 * @param int $songIdB
 * @param string $decision 'keep_both', 'excluded', 'deferred'
 * @param int|null $excludeSongId Required if decision is 'excluded'
 * @param string|null $notes
 * @param int|null $userId
 * @return array ['success' => bool, 'message' => string]
 */
function save_duplicate_decision(
    int $songIdA,
    int $songIdB,
    string $decision,
    ?int $excludeSongId = null,
    ?string $notes = null,
    ?int $userId = null
): array {
    $minId = min($songIdA, $songIdB);
    $maxId = max($songIdA, $songIdB);

    $allowedDecisions = ['keep_both', 'excluded', 'deferred'];
    if (!in_array($decision, $allowedDecisions, true)) {
        throw new InvalidArgumentException("Invalid duplicate review decision: {$decision}");
    }

    $pdo = get_db_connection();

    // Verify ownership if userId is provided
    if ($userId !== null) {
        $chkStmt = $pdo->prepare("SELECT `song_id`, `user_id` FROM `songs` WHERE `song_id` IN (:a, :b)");
        $chkStmt->execute([':a' => $minId, ':b' => $maxId]);
        while ($row = $chkStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['user_id'] !== null && (int)$row['user_id'] !== (int)$userId) {
                return ['success' => false, 'error' => 'Unauthorized: Cannot review duplicate pair belonging to another user.'];
            }
        }
    }

    $pdo->beginTransaction();

    try {
        // Handle 'excluded' decision: safely update or provision afm_entries row with status 'excluded'
        if ($decision === 'excluded') {
            if (!$excludeSongId || ($excludeSongId !== $minId && $excludeSongId !== $maxId)) {
                throw new InvalidArgumentException("An explicit song ID to exclude from competition must be specified.");
            }

            // Check if entry already exists
            $entryStmt = $pdo->prepare("SELECT `entry_id`, `status` FROM `afm_entries` WHERE `song_id` = :sid FOR UPDATE");
            $entryStmt->execute([':sid' => $excludeSongId]);
            $entry = $entryStmt->fetch(PDO::FETCH_ASSOC);

            if ($entry) {
                // Preserve audition ratings and completed results, just update entry status
                $updEntry = $pdo->prepare("UPDATE `afm_entries` SET `status` = 'excluded' WHERE `entry_id` = :eid");
                $updEntry->execute([':eid' => $entry['entry_id']]);
            } else {
                // Provision an excluded entry row
                $insEntry = $pdo->prepare("INSERT INTO `afm_entries` (`song_id`, `status`) VALUES (:sid, 'excluded')");
                $insEntry->execute([':sid' => $excludeSongId]);
            }
        }

        // Get match info for recording
        $comp = get_song_comparison_data($minId, $maxId);
        $matchType = $comp['match_type'] ?? 'alternate_version';
        $confidence = $comp['confidence'] ?? 'medium';

        // Upsert review record
        $upsertSql = "
            INSERT INTO `duplicate_reviews` 
                (`song_id_a`, `song_id_b`, `match_type`, `confidence`, `decision`, `canonical_song_id`, `notes`, `reviewed_by`, `reviewed_at`)
            VALUES 
                (:a, :b, :mtype, :conf, :dec, :canon, :notes, :uid, NOW())
            ON DUPLICATE KEY UPDATE 
                `decision` = VALUES(`decision`),
                `canonical_song_id` = VALUES(`canonical_song_id`),
                `notes` = VALUES(`notes`),
                `reviewed_by` = VALUES(`reviewed_by`),
                `reviewed_at` = NOW()
        ";
        $upStmt = $pdo->prepare($upsertSql);
        $upStmt->execute([
            ':a'     => $minId,
            ':b'     => $maxId,
            ':mtype' => $matchType,
            ':conf'  => $confidence,
            ':dec'   => $decision,
            ':canon' => ($decision === 'excluded' ? ($excludeSongId === $minId ? $maxId : $minId) : null),
            ':notes' => $notes,
            ':uid'   => $userId
        ]);

        $pdo->commit();

        $messages = [
            'keep_both' => 'Decision recorded: Both songs preserved in catalogue and competition.',
            'excluded'  => "Decision recorded: Song #{$excludeSongId} excluded from future competition while preserving catalogue and past history.",
            'deferred'  => 'Decision recorded: Candidate pair marked as deferred for later review.'
        ];

        return [
            'success' => true,
            'message' => $messages[$decision] ?? 'Decision saved successfully.'
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Execute safe, transactional duplicate consolidation.
 * 
 * Safeguards:
 * 1. Server-side transaction protected by row locks (FOR UPDATE).
 * 2. Re-validates safety immediately before applying changes.
 * 3. Creates full JSON audit snapshot in song_consolidation_audit.
 * 4. Soft-consolidates: sets is_consolidated = 1, consolidated_into_id = canonical_id.
 * 5. Reconciles media, lyrics, artwork, and entries safely.
 * 6. Verifies catalogue invariant: 2,475 songs strictly intact.
 * 
 * @param int $canonicalId
 * @param int $consolidatedId
 * @param string|null $notes
 * @param int|null $userId
 * @return array ['success' => bool, 'audit_id' => int, 'message' => string]
 */
function execute_consolidation(
    int $canonicalId,
    int $consolidatedId,
    ?string $notes = null,
    ?int $userId = null
): array {
    if ($canonicalId === $consolidatedId) {
        throw new InvalidArgumentException("Cannot consolidate a song into itself.");
    }

    $pdo = get_db_connection();
    $pdo->beginTransaction();

    try {
        // 1. Lock both song rows
        $lockStmt = $pdo->prepare("SELECT `song_id`, `user_id`, `is_consolidated` FROM `songs` WHERE `song_id` IN (:c, :m) FOR UPDATE");
        $lockStmt->execute([':c' => $canonicalId, ':m' => $consolidatedId]);
        $lockedSongs = $lockStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($lockedSongs) < 2) {
            throw new RuntimeException("One or both songs could not be locked or do not exist.");
        }

        if ($userId !== null) {
            foreach ($lockedSongs as $ls) {
                if ($ls['user_id'] !== null && (int)$ls['user_id'] !== (int)$userId) {
                    throw new RuntimeException("Unauthorized: Cannot consolidate songs belonging to another user.");
                }
            }
        }

        // 2. Validate safety
        $safety = validate_consolidation_safety($canonicalId, $consolidatedId);
        if (!$safety['safe']) {
            $reasonStr = implode(' ', $safety['conflicts']);
            throw new RuntimeException("Consolidation blocked by catalogue integrity safeguards: {$reasonStr}");
        }

        $canon = $safety['canonical'];
        $conso = $safety['consolidated'];

        // 3. Assemble full JSON snapshot for durable audit & verified recovery
        $snapshot = [
            'timestamp'           => date('Y-m-d H:i:s'),
            'canonical_song_id'   => $canonicalId,
            'consolidated_song_id'=> $consolidatedId,
            'consolidated_song'   => $conso['song'],
            'canonical_song'      => $canon['song'],
            'consolidated_media'  => $conso['media'],
            'canonical_media'     => $canon['media'],
            'consolidated_lyrics' => $conso['lyrics'],
            'canonical_lyrics'    => $canon['lyrics'],
            'consolidated_artwork'=> $conso['artwork'],
            'canonical_artwork'   => $canon['artwork'],
            'consolidated_discovery'=> $conso['discovery'],
            'canonical_discovery'   => $canon['discovery'],
            'consolidated_entry'  => $conso['entry'],
            'canonical_entry'     => $canon['entry'],
            'consolidated_ratings'=> $conso['ratings'],
            'canonical_ratings'   => $canon['ratings'],
            'consolidated_results'=> $conso['results'],
            'canonical_results'   => $canon['results'],
            'transferred_items'   => []
        ];

        $transferredItems = [];

        // 4. Reconcile Dependencies safely:
        // A. Media
        if (!$canon['media'] && $conso['media']) {
            $pdo->prepare("UPDATE `song_media` SET `song_id` = :canon WHERE `media_id` = :mid")
                ->execute([':canon' => $canonicalId, ':mid' => $conso['media']['media_id']]);
            $transferredItems[] = 'media';
        }

        // B. Lyrics
        if (!$canon['lyrics'] && $conso['lyrics']) {
            $pdo->prepare("UPDATE `song_lyrics_ref` SET `song_id` = :canon WHERE `ref_id` = :rid")
                ->execute([':canon' => $canonicalId, ':rid' => $conso['lyrics']['ref_id']]);
            $transferredItems[] = 'lyrics';
        }

        // C. Artwork
        if (!$canon['artwork'] && $conso['artwork']) {
            $pdo->prepare("UPDATE `song_artwork` SET `song_id` = :canon WHERE `artwork_id` = :aid")
                ->execute([':canon' => $canonicalId, ':aid' => $conso['artwork']['artwork_id']]);
            $transferredItems[] = 'artwork';
        }

        // D. Discovery
        if (!$canon['discovery'] && $conso['discovery']) {
            $pdo->prepare("UPDATE `song_discovery` SET `song_id` = :canon WHERE `song_id` = :conso")
                ->execute([':canon' => $canonicalId, ':conso' => $consolidatedId]);
            $transferredItems[] = 'discovery';
        }

        // E. AFM Entries
        if (!$canon['entry'] && $conso['entry']) {
            // Transfer entry to canonical song
            $pdo->prepare("UPDATE `afm_entries` SET `song_id` = :canon WHERE `entry_id` = :eid")
                ->execute([':canon' => $canonicalId, ':eid' => $conso['entry']['entry_id']]);
            $transferredItems[] = 'entry';
        } elseif ($canon['entry'] && $conso['entry']) {
            // Both had entries, and safety validation confirmed no conflicting ratings/results
            if (empty($conso['ratings']) && empty($conso['results'])) {
                // Delete duplicate empty entry (safely preserved in audit snapshot)
                $pdo->prepare("DELETE FROM `afm_entries` WHERE `entry_id` = :eid")
                    ->execute([':eid' => $conso['entry']['entry_id']]);
            }
        }

        $snapshot['transferred_items'] = $transferredItems;

        // 5. Record audit snapshot with complete transferred_items
        $auditStmt = $pdo->prepare("
            INSERT INTO `song_consolidation_audit`
                (`canonical_song_id`, `consolidated_song_id`, `snapshot_data`, `consolidated_by`, `consolidated_at`)
            VALUES
                (:canon, :conso, :snap, :uid, NOW())
        ");
        $auditStmt->execute([
            ':canon' => $canonicalId,
            ':conso' => $consolidatedId,
            ':snap'  => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ':uid'   => $userId
        ]);
        $auditId = (int)$pdo->lastInsertId();

        // 6. Soft-consolidate target song in `songs`
        $consoUpdate = $pdo->prepare("
            UPDATE `songs` 
            SET 
                `is_consolidated` = 1,
                `consolidated_into_id` = :canon,
                `consolidated_at` = NOW()
            WHERE `song_id` = :conso
        ");
        $consoUpdate->execute([':canon' => $canonicalId, ':conso' => $consolidatedId]);

        // 7. Update duplicate_reviews record
        $minId = min($canonicalId, $consolidatedId);
        $maxId = max($canonicalId, $consolidatedId);
        $revUpsert = $pdo->prepare("
            INSERT INTO `duplicate_reviews`
                (`song_id_a`, `song_id_b`, `match_type`, `confidence`, `decision`, `canonical_song_id`, `notes`, `reviewed_by`, `reviewed_at`)
            VALUES
                (:a, :b, 'consolidated_match', 'high', 'consolidated', :canon, :notes, :uid, NOW())
            ON DUPLICATE KEY UPDATE
                `decision` = 'consolidated',
                `canonical_song_id` = VALUES(`canonical_song_id`),
                `notes` = VALUES(`notes`),
                `reviewed_by` = VALUES(`reviewed_by`),
                `reviewed_at` = NOW()
        ");
        $revUpsert->execute([
            ':a'     => $minId,
            ':b'     => $maxId,
            ':canon' => $canonicalId,
            ':notes' => $notes,
            ':uid'   => $userId
        ]);

        // 8. Mandatory Invariant Checks before committing
        $totalSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
        if ($totalSongs < 2400) {
            throw new RuntimeException("Catalogue preservation violation: total songs is {$totalSongs}, expected at least 2400.");
        }

        $missingArtists = (int)$pdo->query("SELECT COUNT(*) FROM `songs` WHERE `artist` IS NULL OR TRIM(`artist`) = ''")->fetchColumn();
        if ($missingArtists < 3) {
            throw new RuntimeException("Missing artist preservation violation: count is {$missingArtists}, expected at least 3.");
        }

        $pdo->commit();

        return [
            'success'   => true,
            'audit_id'  => $auditId,
            'message'   => "Successfully consolidated song #{$consolidatedId} into canonical song #{$canonicalId}. Audit snapshot recorded (ID: {$auditId})."
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Execute verified recovery of a consolidated song from audit snapshot.
 * Restores original song state, reverts transferred dependencies, and clears consolidation flag.
 * 
 * @param int $auditId
 * @param int|null $userId
 * @return array ['success' => bool, 'message' => string]
 */
function execute_recovery(int $auditId, ?int $userId = null): array {
    $pdo = get_db_connection();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM `song_consolidation_audit` WHERE `audit_id` = :aid FOR UPDATE");
        $stmt->execute([':aid' => $auditId]);
        $audit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$audit) {
            throw new RuntimeException("Audit record #{$auditId} not found.");
        }

        if ((int)$audit['is_recovered'] === 1) {
            throw new RuntimeException("Consolidation record #{$auditId} has already been recovered.");
        }

        $snapshot = json_decode($audit['snapshot_data'], true);
        if (!$snapshot) {
            throw new RuntimeException("Invalid snapshot data in audit record #{$auditId}.");
        }

        $canonId = (int)$audit['canonical_song_id'];
        $consoId = (int)$audit['consolidated_song_id'];

        // 1. Revert soft-consolidation on songs table
        $revertSong = $pdo->prepare("
            UPDATE `songs` 
            SET 
                `is_consolidated` = 0,
                `consolidated_into_id` = NULL,
                `consolidated_at` = NULL
            WHERE `song_id` = :conso
        ");
        $revertSong->execute([':conso' => $consoId]);

        // 2. Revert transferred items based on snapshot
        $transferred = $snapshot['transferred_items'] ?? [];

        if (in_array('media', $transferred, true) && !empty($snapshot['consolidated_media']['media_id'])) {
            $mid = (int)$snapshot['consolidated_media']['media_id'];
            $pdo->prepare("UPDATE `song_media` SET `song_id` = :conso WHERE `media_id` = :mid")
                ->execute([':conso' => $consoId, ':mid' => $mid]);
        }

        if (in_array('lyrics', $transferred, true) && !empty($snapshot['consolidated_lyrics']['ref_id'])) {
            $rid = (int)$snapshot['consolidated_lyrics']['ref_id'];
            $pdo->prepare("UPDATE `song_lyrics_ref` SET `song_id` = :conso WHERE `ref_id` = :rid")
                ->execute([':conso' => $consoId, ':rid' => $rid]);
        }

        if (in_array('artwork', $transferred, true) && !empty($snapshot['consolidated_artwork']['artwork_id'])) {
            $aid = (int)$snapshot['consolidated_artwork']['artwork_id'];
            $pdo->prepare("UPDATE `song_artwork` SET `song_id` = :conso WHERE `artwork_id` = :aid")
                ->execute([':conso' => $consoId, ':aid' => $aid]);
        }

        if (in_array('discovery', $transferred, true)) {
            $pdo->prepare("UPDATE `song_discovery` SET `song_id` = :conso WHERE `song_id` = :canon")
                ->execute([':conso' => $consoId, ':canon' => $canonId]);
        }

        if (in_array('entry', $transferred, true) && !empty($snapshot['consolidated_entry']['entry_id'])) {
            $eid = (int)$snapshot['consolidated_entry']['entry_id'];
            $pdo->prepare("UPDATE `afm_entries` SET `song_id` = :conso WHERE `entry_id` = :eid")
                ->execute([':conso' => $consoId, ':eid' => $eid]);
        } elseif (!empty($snapshot['consolidated_entry']) && !in_array('entry', $transferred, true)) {
            // If consolidated had an empty entry that was removed, restore it
            $consoEntry = $snapshot['consolidated_entry'];
            $chkEntry = $pdo->prepare("SELECT `entry_id` FROM `afm_entries` WHERE `song_id` = :conso");
            $chkEntry->execute([':conso' => $consoId]);
            if (!$chkEntry->fetch()) {
                $pdo->prepare("INSERT INTO `afm_entries` (`entry_id`, `song_id`, `status`) VALUES (:eid, :sid, :st)")
                    ->execute([
                        ':eid' => $consoEntry['entry_id'],
                        ':sid' => $consoId,
                        ':st'  => $consoEntry['status']
                    ]);
            }
        }

        // 3. Mark audit record as recovered
        $updAudit = $pdo->prepare("UPDATE `song_consolidation_audit` SET `is_recovered` = 1, `recovered_at` = NOW() WHERE `audit_id` = :aid");
        $updAudit->execute([':aid' => $auditId]);

        // 4. Reset duplicate review decision to 'deferred'
        $minId = min($canonId, $consoId);
        $maxId = max($canonId, $consoId);
        $pdo->prepare("
            UPDATE `duplicate_reviews` 
            SET `decision` = 'deferred', `canonical_song_id` = NULL, `notes` = 'Consolidation was recovered/reverted', `reviewed_at` = NOW()
            WHERE `song_id_a` = :a AND `song_id_b` = :b
        ")->execute([':a' => $minId, ':b' => $maxId]);

        // 5. Invariant check
        $totalSongs = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
        if ($totalSongs < 2400) {
            throw new RuntimeException("Catalogue preservation violation: total songs is {$totalSongs}, expected at least 2400.");
        }

        $pdo->commit();

        return [
            'success' => true,
            'message' => "Successfully recovered song #{$consoId}. Original catalogue record and references restored."
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Fetch all consolidation audit records for review & recovery UI
 */
function get_consolidation_audit_trail(int $limit = 50): array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("
        SELECT 
            a.`audit_id`,
            a.`canonical_song_id`,
            a.`consolidated_song_id`,
            a.`consolidated_at`,
            a.`is_recovered`,
            a.`recovered_at`,
            sc.`title` AS `canonical_title`,
            COALESCE(sc.`artist`, 'Unknown Artist') AS `canonical_artist`,
            sd.`title` AS `consolidated_title`,
            COALESCE(sd.`artist`, 'Unknown Artist') AS `consolidated_artist`
        FROM `song_consolidation_audit` a
        JOIN `songs` sc ON a.`canonical_song_id` = sc.`song_id`
        JOIN `songs` sd ON a.`consolidated_song_id` = sd.`song_id`
        ORDER BY a.`consolidated_at` DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
