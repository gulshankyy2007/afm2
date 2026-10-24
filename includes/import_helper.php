<?php
/**
 * AFM#2 - CSV Bulk Import Helper & Validator
 *
 * Implements robust CSV detection, BOM stripping, column mapping,
 * validation, duplicate analysis, and transactional import execution.
 */

if (!defined('AFM_APP')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/db_helper.php';

/**
 * Standard known header aliases for title and artist detection
 */
const CSV_TITLE_ALIASES = [
    'track name', 'track_name', 'trackname', 'title', 'song', 
    'song title', 'song_title', 'track', 'track title', 'track_title', 
    'name', 'song name', 'song_name'
];

const CSV_ARTIST_ALIASES = [
    'artist name', 'artist_name', 'artistname', 'artist', 'artists', 
    'performer', 'band', 'singer', 'creator', 'author', 'artist(s)'
];

/**
 * Detect delimiter from CSV content sample
 */
function detect_csv_delimiter(string $firstLine): string {
    $delimiters = [',', ';', "\t", '|'];
    $bestDelim = ',';
    $bestCount = 0;

    foreach ($delimiters as $delim) {
        $count = substr_count($firstLine, $delim);
        if ($count > $bestCount) {
            $bestCount = $count;
            $bestDelim = $delim;
        }
    }
    return $bestDelim;
}

/**
 * Clean and strip UTF-8 BOM and leading/trailing quotes/spaces
 */
function clean_csv_header_cell(string $cell): string {
    // Strip UTF-8 Byte Order Mark (BOM)
    $cell = preg_replace('/^\xEF\xBB\xBF/', '', $cell);
    return trim($cell, " \t\n\r\0\x0B\"'");
}

/**
 * Automatically detect title and artist column indexes from header row
 */
function detect_csv_headers(array $headers): array {
    $detectedTitleCol = null;
    $detectedArtistCol = null;
    $titleConfidence = 'none';
    $artistConfidence = 'none';

    $cleanedHeaders = [];
    foreach ($headers as $idx => $h) {
        $clean = strtolower(clean_csv_header_cell((string)$h));
        $cleanedHeaders[$idx] = $clean;

        // Exact alias matching for Title
        if ($detectedTitleCol === null && in_array($clean, CSV_TITLE_ALIASES, true)) {
            $detectedTitleCol = $idx;
            $titleConfidence = 'high';
        }

        // Exact alias matching for Artist
        if ($detectedArtistCol === null && in_array($clean, CSV_ARTIST_ALIASES, true)) {
            $detectedArtistCol = $idx;
            $artistConfidence = 'high';
        }
    }

    // Secondary heuristic matching if not yet found
    if ($detectedTitleCol === null) {
        foreach ($cleanedHeaders as $idx => $clean) {
            if ($idx === $detectedArtistCol) continue;
            if (strpos($clean, 'title') !== false || strpos($clean, 'song') !== false || strpos($clean, 'track') !== false) {
                $detectedTitleCol = $idx;
                $titleConfidence = 'medium';
                break;
            }
        }
    }

    if ($detectedArtistCol === null) {
        foreach ($cleanedHeaders as $idx => $clean) {
            if ($idx === $detectedTitleCol) continue;
            if (strpos($clean, 'artist') !== false || strpos($clean, 'performer') !== false || strpos($clean, 'by') !== false) {
                $detectedArtistCol = $idx;
                $artistConfidence = 'medium';
                break;
            }
        }
    }

    // Fallback: If 2 columns exist and headers don't match, assume Col 0 is Title, Col 1 is Artist
    if ($detectedTitleCol === null && count($headers) >= 1) {
        $detectedTitleCol = 0;
        $titleConfidence = 'low';
        if (count($headers) >= 2 && $detectedArtistCol === null) {
            $detectedArtistCol = 1;
            $artistConfidence = 'low';
        }
    }

    return [
        'title_col'         => $detectedTitleCol,
        'artist_col'        => $detectedArtistCol,
        'title_confidence'  => $titleConfidence,
        'artist_confidence' => $artistConfidence,
        'cleaned_headers'   => $cleanedHeaders
    ];
}

/**
 * Safe string lowercasing supporting UTF-8 when mbstring is available
 */
function safe_str_tolower(string $str): string {
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($str, 'UTF-8');
    }
    return strtolower($str);
}

/**
 * Normalizes title and artist for comparison
 */
function normalize_song_key(string $title, ?string $artist): string {
    $normTitle = safe_str_tolower(trim($title));
    // Normalize spaces and punctuation
    $normTitle = preg_replace('/\s+/u', ' ', $normTitle);
    
    $normArtist = $artist !== null ? safe_str_tolower(trim($artist)) : '';
    $normArtist = preg_replace('/\s+/u', ' ', $normArtist);

    return $normTitle . '|||' . $normArtist;
}

/**
 * Strips version suffixes (Remix, Live, Acoustic, Remastered, etc.) for similarity check
 */
function strip_version_suffix(string $title): string {
    $t = safe_str_tolower(trim($title));
    // Strip parenthesized or bracketed suffixes like (Live), [Remix], - Acoustic Version
    $t = preg_replace('/\s*[\(\[\{](live|remix|acoustic|remastered?|radio edit|deluxe|version|audio|video|official).*?[\)\]\}]/iu', '', $t);
    $t = preg_replace('/\s*-\s*(live|remix|acoustic|remastered?|version).*$/iu', '', $t);
    return trim($t);
}

/**
 * Parse and validate CSV file contents against the database catalogue
 *
 * @param string $filepath Path to uploaded CSV file
 * @param int|null $titleColOverride User-selected title column index
 * @param int|null $artistColOverride User-selected artist column index
 * @param int $maxRows Safety limit for upload processing (default 5,000)
 * @return array Validation analysis result
 */
function parse_and_validate_csv(
    string $filepath, 
    ?int $titleColOverride = null, 
    ?int $artistColOverride = null, 
    int $maxRows = 5000
): array {
    if (!file_exists($filepath) || !is_readable($filepath)) {
        return ['success' => false, 'error' => 'CSV file does not exist or cannot be read.'];
    }

    $handle = fopen($filepath, 'r');
    if (!$handle) {
        return ['success' => false, 'error' => 'Failed to open CSV file for parsing.'];
    }

    // Read first line to detect delimiter and BOM
    $firstLine = fgets($handle);
    if ($firstLine === false || trim($firstLine) === '') {
        fclose($handle);
        return ['success' => false, 'error' => 'Uploaded CSV file is completely empty.'];
    }

    $delimiter = detect_csv_delimiter($firstLine);

    // Rewind file to read with proper fgetcsv
    rewind($handle);

    // Read header row
    $rawHeaders = fgetcsv($handle, 0, $delimiter, '"', '\\');
    if ($rawHeaders === false || empty($rawHeaders)) {
        fclose($handle);
        return ['success' => false, 'error' => 'Could not read header row from CSV file.'];
    }

    // Clean BOM from first header column
    if (isset($rawHeaders[0])) {
        $rawHeaders[0] = clean_csv_header_cell((string)$rawHeaders[0]);
    }

    $headerDetection = detect_csv_headers($rawHeaders);

    $titleCol = $titleColOverride !== null ? (int)$titleColOverride : $headerDetection['title_col'];
    $artistCol = $artistColOverride !== null ? (int)$artistColOverride : $headerDetection['artist_col'];

    if ($titleCol === null || $titleCol < 0 || $titleCol >= count($rawHeaders)) {
        fclose($handle);
        return [
            'success'          => false,
            'error'            => 'Required song title column could not be automatically identified. Please select the title column manually.',
            'headers'          => $rawHeaders,
            'header_detection' => $headerDetection
        ];
    }

    // Pre-load existing catalogue for fast in-memory exact & similarity lookup
    $pdo = get_db_connection();
    $existingStmt = $pdo->query("SELECT song_id, title, artist FROM `songs`");
    
    $existingExact = []; // key: normalized(title|||artist) => song_id
    $existingByBaseTitle = []; // key: baseTitle => [ [song_id, title, artist] ]

    while ($r = $existingStmt->fetch(PDO::FETCH_ASSOC)) {
        $sId = (int)$r['song_id'];
        $sTitle = $r['title'];
        $sArtist = $r['artist'];

        $key = normalize_song_key($sTitle, $sArtist);
        $existingExact[$key] = $sId;

        $baseTitle = strip_version_suffix($sTitle);
        if ($baseTitle !== '') {
            $existingByBaseTitle[$baseTitle][] = [
                'song_id' => $sId,
                'title'   => $sTitle,
                'artist'  => $sArtist
            ];
        }
    }

    $rows = [];
    $seenInFile = []; // key => first line number
    $lineNum = 1; // Line 1 is header

    $counts = [
        'total'             => 0,
        'valid_new'         => 0,
        'existing_in_db'    => 0,
        'similar_in_db'     => 0,
        'duplicate_in_file' => 0,
        'invalid'           => 0
    ];

    while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $lineNum++;

        // Stop if exceeded safety limit
        if ($counts['total'] >= $maxRows) {
            break;
        }

        // Skip completely blank rows
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue;
        }

        $counts['total']++;

        $rawTitle = isset($row[$titleCol]) ? trim((string)$row[$titleCol]) : '';
        $rawArtist = ($artistCol !== null && isset($row[$artistCol])) ? trim((string)$row[$artistCol]) : '';

        // If artist is empty string or '-' or 'unknown', normalize to NULL
        $artist = ($rawArtist === '' || strcasecmp($rawArtist, 'unknown artist') === 0 || $rawArtist === '-') ? null : $rawArtist;

        $rowData = [
            'line_num'         => $lineNum,
            'raw_title'        => $rawTitle,
            'title'            => $rawTitle,
            'raw_artist'       => $rawArtist,
            'artist'           => $artist,
            'status'           => 'valid_new',
            'reason'           => 'Valid new song ready to import',
            'existing_song_id' => null,
            'similar_match'    => null,
            'default_approved' => true
        ];

        // 1. Validation: Title is strictly required
        if ($rawTitle === '') {
            $rowData['status'] = 'invalid';
            $rowData['reason'] = "Line {$lineNum}: Missing required song title.";
            $rowData['default_approved'] = false;
            $counts['invalid']++;
            $rows[] = $rowData;
            continue;
        }

        $fileKey = normalize_song_key($rawTitle, $artist);

        // 2. Validation: Duplicate within uploaded CSV
        if (isset($seenInFile[$fileKey])) {
            $prevLine = $seenInFile[$fileKey];
            $rowData['status'] = 'duplicate_in_file';
            $rowData['reason'] = "Duplicate within file (identical to row on line {$prevLine}).";
            $rowData['default_approved'] = false;
            $counts['duplicate_in_file']++;
            $rows[] = $rowData;
            continue;
        }
        $seenInFile[$fileKey] = $lineNum;

        // 3. Validation: Exact duplicate already in database catalogue
        if (isset($existingExact[$fileKey])) {
            $matchedSongId = $existingExact[$fileKey];
            $rowData['status'] = 'existing_in_db';
            $rowData['existing_song_id'] = $matchedSongId;
            $rowData['reason'] = "Already exists in catalogue (Track #{$matchedSongId}).";
            $rowData['default_approved'] = false; // By default skip already existing
            $counts['existing_in_db']++;
            $rows[] = $rowData;
            continue;
        }

        // 4. Validation: Similar title / version review
        // Avoid auto-merging recordings or discarding remixes/live versions
        $baseTitle = strip_version_suffix($rawTitle);
        if ($baseTitle !== '' && isset($existingByBaseTitle[$baseTitle])) {
            $candidate = $existingByBaseTitle[$baseTitle][0];
            $rowData['status'] = 'similar_in_db';
            $rowData['similar_match'] = $candidate;
            $candArtist = $candidate['artist'] ?? 'Unknown Artist';
            $rowData['reason'] = "Similar catalogue title: \"{$candidate['title']}\" by {$candArtist} (#{$candidate['song_id']}). Review version.";
            // Similar versions (remixes, live, acoustic) are allowed and can be imported
            // Keep default_approved = true so user can review and keep or uncheck
            $rowData['default_approved'] = true;
            $counts['similar_in_db']++;
            $rows[] = $rowData;
            continue;
        }

        // 5. Valid new track
        $counts['valid_new']++;
        $rows[] = $rowData;
    }

    fclose($handle);

    return [
        'success'             => true,
        'delimiter'           => $delimiter,
        'headers'             => $rawHeaders,
        'detected_title_col'  => $titleCol,
        'detected_artist_col' => $artistCol,
        'header_detection'    => $headerDetection,
        'counts'              => $counts,
        'rows'                => $rows
    ];
}

/**
 * Execute bulk import of approved CSV rows inside an atomic database transaction
 *
 * @param array $approvedRows Validated rows approved by user
 * @param string $defaultDiscoveryStatus 'unreviewed' (default) or 'shortlisted'
 * @return array Import execution metrics
 */
function execute_csv_import(array $approvedRows, string $defaultDiscoveryStatus = 'unreviewed'): array {
    $pdo = get_db_connection();

    if (empty($approvedRows)) {
        return [
            'success'       => false,
            'error'         => 'No approved rows provided for import.',
            'songs_added'   => 0,
            'new_song_ids'  => []
        ];
    }

    $validStatus = in_array($defaultDiscoveryStatus, ['unreviewed', 'shortlisted', 'passed'], true) 
        ? $defaultDiscoveryStatus 
        : 'unreviewed';

    $insertSong = $pdo->prepare("INSERT INTO `songs` (`title`, `artist`) VALUES (:title, :artist)");
    $insertDisc = $pdo->prepare("INSERT INTO `song_discovery` (`song_id`, `status`) VALUES (:song_id, :status)");
    $insertEntry = $pdo->prepare("INSERT INTO `afm_entries` (`song_id`, `status`) VALUES (:song_id, 'auditioning')");

    $addedSongs = [];
    $addedIds = [];

    try {
        $pdo->beginTransaction();

        foreach ($approvedRows as $row) {
            $title = trim($row['title'] ?? '');
            if ($title === '') {
                continue; // Skip invalid records safely
            }

            $rawArtist = isset($row['artist']) ? trim((string)$row['artist']) : '';
            $artist = ($rawArtist === '' || strcasecmp($rawArtist, 'unknown artist') === 0) ? null : $rawArtist;

            $insertSong->execute([
                ':title'  => $title,
                ':artist' => $artist
            ]);

            $newId = (int)$pdo->lastInsertId();
            $addedIds[] = $newId;
            $addedSongs[] = [
                'song_id' => $newId,
                'title'   => $title,
                'artist'  => $artist
            ];

            // If shortlisted or passed, insert discovery status
            if ($validStatus !== 'unreviewed') {
                $insertDisc->execute([
                    ':song_id' => $newId,
                    ':status'  => $validStatus
                ]);

                // If shortlisted, automatically enroll into stage 1 auditioning
                if ($validStatus === 'shortlisted') {
                    $insertEntry->execute([':song_id' => $newId]);
                }
            }
        }

        $pdo->commit();

        return [
            'success'       => true,
            'songs_added'   => count($addedIds),
            'new_song_ids'  => $addedIds,
            'added_songs'   => $addedSongs,
            'message'       => "Successfully imported " . count($addedIds) . " songs into the catalogue."
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success'      => false,
            'error'        => 'Database transaction error during CSV import: ' . $e->getMessage(),
            'songs_added'  => 0,
            'new_song_ids' => []
        ];
    }
}

/**
 * Generate CSV rejection and review report for skipped/invalid records
 */
function generate_rejection_csv_report(array $rejectedRows): string {
    $output = fopen('php://temp', 'r+');
    
    // Header
    fputcsv($output, ['CSV Line', 'Song Title', 'Artist', 'Classification', 'Reason / Safeguard Notes'], ',', '"', '\\');

    foreach ($rejectedRows as $r) {
        fputcsv($output, [
            $r['line_num'] ?? '-',
            $r['raw_title'] ?? ($r['title'] ?? ''),
            $r['raw_artist'] ?? ($r['artist'] ?? ''),
            $r['status'] ?? 'skipped',
            $r['reason'] ?? 'Skipped by user during import review'
        ], ',', '"', '\\');
    }

    rewind($output);
    $csvContent = stream_get_contents($output);
    fclose($output);

    return $csvContent;
}
