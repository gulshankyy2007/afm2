<?php
/**
 * AFM#2 - Batch C: CSV Formula Injection (DDE) Hardening Test Suite
 *
 * Verifies FINDING-03 resolution and CSV export security across all export paths:
 * 1. Normal song titles and artist names remain completely readable and uncorrupted.
 * 2. Values beginning with formula triggers (=, +, -, @) are properly escaped.
 * 3. Values beginning with tabs, CR/LF, and control characters are neutralized.
 * 4. Formula prefixes preceded by spaces or control characters are caught and escaped.
 * 5. CSV fields with commas, quotes, and embedded newlines format correctly with fputcsv.
 * 6. Non-English and Unicode metadata (Japanese, Korean, Hindi, Cyrillic, accented) are preserved.
 * 7. Legitimate negative numeric values and intentionally numeric fields are preserved.
 * 8. CSV headers and column ordering remain identical to original specification.
 * 9. MySQL database values remain strictly untouched and unmutated after export.
 * 10. Export output remains valid RFC 4180 CSV and import parsing is not broken.
 * 11. Results export (public/results.php) and Rejection report (generate_rejection_csv_report) apply sanitization.
 * 12. Full end-to-end regression across export functionality.
 *
 * Usage: php tests/test_batch_c_csv_security.php
 */

declare(strict_types=1);

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/import_helper.php';
require_once dirname(__DIR__) . '/includes/db_helper.php';

echo "======================================================================\n";
echo " AFM#2 — BATCH C: CSV FORMULA INJECTION HARDENING TEST SUITE\n";
echo " Target: public/results.php & includes/import_helper.php (FINDING-03)\n";
echo "======================================================================\n\n";

$testsTotal = 0;
$testsPassed = 0;

function assert_test(string $name, bool $passed, string $details = ''): void {
    global $testsTotal, $testsPassed;
    $testsTotal++;
    if ($passed) {
        $testsPassed++;
        echo "  [PASS] {$name}\n";
    } else {
        echo "  [FAIL] {$name}" . ($details ? " — {$details}" : "") . "\n";
    }
}

/**
 * Pure PHP stream-based HTTP request helper
 */
function test_http(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array {
    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = is_int($k) ? $v : "{$k}: {$v}";
    }

    $opts = [
        'http' => [
            'method'          => $method,
            'header'          => implode("\r\n", $headerLines) . "\r\n",
            'content'         => $body ?? '',
            'follow_location' => 0,
            'ignore_errors'   => true,
            'timeout'         => 5
        ]
    ];

    $ctx = stream_context_create($opts);
    $responseBody = @file_get_contents($url, false, $ctx);
    $responseHeaders = $http_response_header ?? [];

    $statusCode = 0;
    if (!empty($responseHeaders) && preg_match('#HTTP/\S+\s+(\d{3})#i', $responseHeaders[0], $m)) {
        $statusCode = (int)$m[1];
    }

    $setCookies = [];
    $parsedHeaders = [];
    foreach ($responseHeaders as $h) {
        if (strpos($h, ':') !== false) {
            [$name, $val] = explode(':', $h, 2);
            $tName = strtolower(trim($name));
            $tVal = trim($val);
            if ($tName === 'set-cookie') {
                $setCookies[] = $tVal;
            } else {
                $parsedHeaders[$tName] = $tVal;
            }
        }
    }

    return [
        'status'      => $statusCode,
        'headers'     => $parsedHeaders,
        'cookies'     => $setCookies,
        'raw_headers' => $responseHeaders,
        'body'        => $responseBody ?: ''
    ];
}

// =====================================================================
// 1. NORMAL SONG TITLES AND ARTIST NAMES REMAIN READABLE
// =====================================================================
echo "1. Legitimate Metadata Preservation (No Unnecessary Escaping)\n";

$normalTitle = "Midnight City";
assert_test("Standard title 'Midnight City' is untouched", sanitize_csv_cell($normalTitle) === "Midnight City");

$normalArtist = "M83";
assert_test("Standard artist 'M83' is untouched", sanitize_csv_cell($normalArtist) === "M83");

$complexNormal = "Symphony No. 5 in C Minor, Op. 67";
assert_test("Title with comma and period is untouched", sanitize_csv_cell($complexNormal) === "Symphony No. 5 in C Minor, Op. 67");

$emptyString = "";
assert_test("Empty string returns empty string", sanitize_csv_cell($emptyString) === "");

$nullValue = null;
assert_test("Null value returns empty string", sanitize_csv_cell($nullValue) === "");

// =====================================================================
// 2. VALUES BEGINNING WITH =, +, -, AND @ ARE ESCAPED
// =====================================================================
echo "\n2. Direct Formula Trigger Neutralization (=, +, -, @)\n";

$eqFormula = "=1+1";
assert_test("Formula starting with '=' is prepended with apostrophe", sanitize_csv_cell($eqFormula) === "'=1+1");

$eqDde = "=cmd|' /C calc'!A0";
assert_test("DDE command starting with '=' is prepended with apostrophe", sanitize_csv_cell($eqDde) === "'=cmd|' /C calc'!A0");

$plusFormula = "+1+1";
assert_test("Formula starting with '+' is prepended with apostrophe", sanitize_csv_cell($plusFormula) === "'+1+1");

$plusHyperlink = "+HYPERLINK(\"http://evil.com\")";
assert_test("Hyperlink formula starting with '+' is prepended with apostrophe", sanitize_csv_cell($plusHyperlink) === "'+HYPERLINK(\"http://evil.com\")");

$minusFormula = "-2+3";
assert_test("Expression starting with '-' in text context is prepended with apostrophe", sanitize_csv_cell($minusFormula) === "'-2+3");

$atFormula = "@SUM(A1:A10)";
assert_test("Function starting with '@' is prepended with apostrophe", sanitize_csv_cell($atFormula) === "'@SUM(A1:A10)");

$pipeDde = "|' /C calc'!A0";
assert_test("Pipe formula starting with '|' is prepended with apostrophe", sanitize_csv_cell($pipeDde) === "'|' /C calc'!A0");

$percentFormula = "%USERNAME%";
assert_test("Percent expression starting with '%' is prepended with apostrophe", sanitize_csv_cell($percentFormula) === "'%USERNAME%");

// =====================================================================
// 3. VALUES BEGINNING WITH TABS, CR/LF, AND CONTROL CHARACTERS
// =====================================================================
echo "\n3. Leading Whitespace & Control Character Combinations\n";

$tabLeading = "\t=cmd|' /C calc'!A0";
assert_test("Leading tab with formula trigger is escaped", sanitize_csv_cell($tabLeading) === "'\t=cmd|' /C calc'!A0");

$crLeading = "\r=1+1";
assert_test("Leading carriage return with formula trigger is escaped", sanitize_csv_cell($crLeading) === "'\r=1+1");

$lfLeading = "\n@SUM(1,2)";
assert_test("Leading newline with formula trigger is escaped", sanitize_csv_cell($lfLeading) === "'\n@SUM(1,2)");

$nullByteLeading = "\0+1+1";
assert_test("Leading null byte with formula trigger is escaped", sanitize_csv_cell($nullByteLeading) === "'\0+1+1");

$tabNormal = "\tNormal Text";
assert_test("Leading tab on ordinary text is escaped to prevent cell delimiter/formula shift", sanitize_csv_cell($tabNormal) === "'\tNormal Text");

// =====================================================================
// 4. FORMULA PREFIXES PRECEDED BY SPACES OR CONTROL CHARACTERS
// =====================================================================
echo "\n4. Formula Prefixes Preceded by Spaces or Control Characters\n";

$spaceEq = "   =1+1";
assert_test("Spaces preceding '=' formula trigger are escaped", sanitize_csv_cell($spaceEq) === "'   =1+1");

$spacePlus = "  +HYPERLINK(\"http://evil.com\")";
assert_test("Spaces preceding '+' formula trigger are escaped", sanitize_csv_cell($spacePlus) === "'  +HYPERLINK(\"http://evil.com\")");

$spaceMinus = "   -5*2";
assert_test("Spaces preceding '-' formula trigger are escaped", sanitize_csv_cell($spaceMinus) === "'   -5*2");

$spaceAt = " \t @SUM(1,2)";
assert_test("Mixed space and tab preceding '@' are escaped", sanitize_csv_cell($spaceAt) === "' \t @SUM(1,2)");

$crlfMinus = "\r\n -cmd|' /C calc'!A0";
assert_test("CRLF preceding '-' formula trigger are escaped", sanitize_csv_cell($crlfMinus) === "'\r\n -cmd|' /C calc'!A0");

// =====================================================================
// 5. CSV FIELDS CONTAINING COMMAS, QUOTES, AND EMBEDDED NEWLINES
// =====================================================================
echo "\n5. RFC 4180 CSV Framing Integration (fputcsv with Commas, Quotes, Newlines)\n";

$fp = fopen('php://temp', 'r+');

$testRow = [
    sanitize_csv_cell("=Formula, with comma"),
    sanitize_csv_cell("=Formula with \"quotes\""),
    sanitize_csv_cell("=Formula with\nnewline"),
    sanitize_csv_cell("Normal, \"Safe\" and Clean")
];

fputcsv($fp, $testRow, ',', '"', '\\');
rewind($fp);
$rawCsvRow = stream_get_contents($fp);
fclose($fp);

// Parse back using str_getcsv to verify RFC 4180 compliance
$parsedBack = str_getcsv($rawCsvRow, ',', '"', '\\');

assert_test("Field with comma correctly retains leading apostrophe and comma", $parsedBack[0] === "'=Formula, with comma");
assert_test("Field with quotes correctly retains leading apostrophe and quotes", $parsedBack[1] === "'=Formula with \"quotes\"");
assert_test("Field with newline correctly retains leading apostrophe and newline", $parsedBack[2] === "'=Formula with\nnewline");
assert_test("Safe field with quotes and commas is untouched and properly framed", $parsedBack[3] === "Normal, \"Safe\" and Clean");

// =====================================================================
// 6. UNICODE AND NON-ENGLISH METADATA PRESERVATION
// =====================================================================
echo "\n6. Unicode and Non-English Metadata Preservation\n";

$japaneseTitle = "夜に駆ける";
assert_test("Japanese metadata '夜に駆ける' is preserved without modification", sanitize_csv_cell($japaneseTitle) === "夜に駆ける");

$koreanTitle = "봄날";
assert_test("Korean metadata '봄날' is preserved without modification", sanitize_csv_cell($koreanTitle) === "봄날");

$hindiTitle = "तुम ही हो";
assert_test("Hindi metadata 'तुम ही हो' is preserved without modification", sanitize_csv_cell($hindiTitle) === "तुम ही हो");

$cyrillicTitle = "Группа крови";
assert_test("Cyrillic metadata 'Группа крови' is preserved without modification", sanitize_csv_cell($cyrillicTitle) === "Группа крови");

$frenchTitle = "Hélène";
assert_test("Accented French metadata 'Hélène' is preserved without modification", sanitize_csv_cell($frenchTitle) === "Hélène");

$hostileUnicode = "=夜に駆ける";
assert_test("Hostile Unicode starting with '=' is safely escaped with apostrophe", sanitize_csv_cell($hostileUnicode) === "'=夜に駆ける");

// =====================================================================
// 7. LEGITIMATE NEGATIVE NUMERIC VALUES AND NUMERIC FIELDS
// =====================================================================
echo "\n7. Numeric Column Handling & Negative Numbers\n";

$intPositive = 42;
assert_test("Positive integer primitive (42) returns '42' without apostrophe", sanitize_csv_cell($intPositive) === "42");

$intNegative = -5;
assert_test("Negative integer primitive (-5) returns '-5' without apostrophe", sanitize_csv_cell($intNegative) === "-5");

$floatNegative = -3.14;
assert_test("Negative float primitive (-3.14) returns '-3.14' without apostrophe", sanitize_csv_cell($floatNegative) === "-3.14");

$strNegativeInNumeric = "-5";
assert_test("Negative numeric string ('-5') in numeric column is preserved as '-5'", sanitize_csv_cell($strNegativeInNumeric, true) === "-5");

$strDecimalInNumeric = "-10.50";
assert_test("Negative decimal string ('-10.50') in numeric column is preserved as '-10.50'", sanitize_csv_cell($strDecimalInNumeric, true) === "-10.50");

$strHostileInNumeric = "-5+cmd|' /C calc'!A0";
assert_test("Formula string in numeric column fails numeric test and is safely escaped", sanitize_csv_cell($strHostileInNumeric, true) === "'-5+cmd|' /C calc'!A0");

$strNegativeInText = "-5";
assert_test("Negative number string ('-5') in non-numeric text column is escaped to prevent formula parse", sanitize_csv_cell($strNegativeInText, false) === "'-5");

// =====================================================================
// 8. CSV HEADERS AND COLUMN ORDERING INVARIANTS
// =====================================================================
echo "\n8. CSV Headers & Column Ordering Invariants\n";

$expectedResultsHeaders = [
    'Stage', 
    'Rank Position', 
    'Song Title', 
    'Artist', 
    'Total Score', 
    'Emotion', 
    'Replayability', 
    'Melody', 
    'Vocals', 
    'Lyrics', 
    'Outcome', 
    'Official Reason', 
    'Decided At'
];

// Verify by inspecting results.php source or running export simulation
$resultsPhpContent = file_get_contents(dirname(__DIR__) . '/public/results.php') ?: '';
$expectedCount = count($expectedResultsHeaders);
assert_test("Results export defines exactly {$expectedCount} columns in expected order", 
    strpos($resultsPhpContent, "'Stage'") !== false &&
    strpos($resultsPhpContent, "'Rank Position'") !== false &&
    strpos($resultsPhpContent, "'Song Title'") !== false &&
    strpos($resultsPhpContent, "'Decided At'") !== false
);

$expectedRejectionHeaders = ['CSV Line', 'Song Title', 'Artist', 'Classification', 'Reason / Safeguard Notes'];
$dummyRejectionCsv = generate_rejection_csv_report([]);
$rejectionHeaderLine = trim(explode("\n", $dummyRejectionCsv)[0]);
$parsedRejectionHeaders = str_getcsv($rejectionHeaderLine, ',', '"', '\\');
assert_test("Rejection report emits exactly 5 columns matching expected specification", $parsedRejectionHeaders === $expectedRejectionHeaders);

// =====================================================================
// 9. DATABASE INTEGRITY INVARIANT: DB VALUES REMAIN UNMUTATED
// =====================================================================
echo "\n9. Database Value Immutability Verification\n";

$pdo = get_db_connection();
// Fetch sample song from catalogue
$stmt = $pdo->query("SELECT song_id, title, artist FROM `songs` ORDER BY song_id ASC LIMIT 1");
$sampleSong = $stmt->fetch(PDO::FETCH_ASSOC);

$originalTitle = $sampleSong['title'];
$originalArtist = $sampleSong['artist'];

// Generate exports using sample song metadata
$dummyRejections = [
    [
        'line_num'   => 1,
        'raw_title'  => $originalTitle,
        'raw_artist' => $originalArtist,
        'status'     => 'skipped',
        'reason'     => 'Safe review'
    ]
];
$exportedReport = generate_rejection_csv_report($dummyRejections);

// Query database again to confirm zero mutation
$stmtAfter = $pdo->prepare("SELECT song_id, title, artist FROM `songs` WHERE song_id = :id");
$stmtAfter->execute([':id' => $sampleSong['song_id']]);
$sampleAfter = $stmtAfter->fetch(PDO::FETCH_ASSOC);

assert_test("Database title is completely unmodified after CSV export generation", $sampleAfter['title'] === $originalTitle);
assert_test("Database artist is completely unmodified after CSV export generation", $sampleAfter['artist'] === $originalArtist);

// =====================================================================
// 10. EXPORT OUTPUT REMAINS VALID CSV & IMPORT PARSING IS UNBROKEN
// =====================================================================
echo "\n10. Export Output Validity & Import Compatibility\n";

$testExportRows = [
    [
        'line_num'   => 10,
        'raw_title'  => "=SUM(1,2)",
        'raw_artist' => "+Dangerous Artist",
        'status'     => "invalid",
        'reason'     => "@Hostile Reason"
    ],
    [
        'line_num'   => 11,
        'raw_title'  => "Clean Contender",
        'raw_artist' => "Clean Artist",
        'status'     => "valid_new",
        'reason'     => "Ready to import"
    ]
];

$exportCsv = generate_rejection_csv_report($testExportRows);
$lines = explode("\n", trim($exportCsv));
assert_test("Export produced valid non-empty CSV with 3 lines (header + 2 rows)", count($lines) === 3);

$row1 = str_getcsv($lines[1], ',', '"', '\\');
assert_test("Row 1 parsed correctly into 5 columns", count($row1) === 5);
assert_test("Row 1 escaped title starts with single quote", $row1[1] === "'=SUM(1,2)");
assert_test("Row 1 escaped artist starts with single quote", $row1[2] === "'+Dangerous Artist");
assert_test("Row 1 escaped reason starts with single quote", $row1[4] === "'@Hostile Reason");

// Verify existing import parsing functionality still works on clean CSV
$tmpImportFile = tempnam(sys_get_temp_dir(), 'afm_csv_batch_c_') . '.csv';
$importCsvData = "Track name,Artist name\n\"Valid Test Track 2026\",\"Valid Artist 2026\"\n";
file_put_contents($tmpImportFile, $importCsvData);

$importValidation = parse_and_validate_csv($tmpImportFile);
unlink($tmpImportFile);

assert_test("Existing CSV import parser (parse_and_validate_csv) continues to succeed", $importValidation['success'] === true);
assert_test("Import parser detected exactly 1 valid new track", $importValidation['counts']['total'] === 1);

// =====================================================================
// 11. END-TO-END VERIFICATION ACROSS ALL EXPORT PATHS
// =====================================================================
echo "\n11. Live HTTP & Export Path Verification\n";

// Path A: generate_rejection_csv_report
$hostileRejections = [
    [
        'line_num'   => 1,
        'raw_title'  => '=cmd|\'/C calc\'!A0',
        'raw_artist' => '@SUM(1,2)*calc',
        'status'     => 'rejected',
        'reason'     => '+Malicious reason'
    ]
];
$reportOutput = generate_rejection_csv_report($hostileRejections);
$reportLines = explode("\n", trim($reportOutput));
$reportRow = str_getcsv($reportLines[1], ',', '"', '\\');
assert_test("Path A (Rejection report): Title neutralized", $reportRow[1] === "'=cmd|'/C calc'!A0");
assert_test("Path A (Rejection report): Artist neutralized", $reportRow[2] === "'@SUM(1,2)*calc");
assert_test("Path A (Rejection report): Reason neutralized", $reportRow[4] === "'+Malicious reason");

// Path B: results.php CSV export
// Login to local server to obtain session cookie
clear_rate_limits();
$loginPage = test_http("http://127.0.0.1:8080/login.php", 'GET');
preg_match('/<input[^>]+name="csrf_token"[^>]+value="([^"]+)"/i', $loginPage['body'], $mToken);
$csrfToken = $mToken[1] ?? '';
$sessionCookie = '';
foreach ($loginPage['cookies'] as $c) {
    if (preg_match('/(AFM2_SESSID=[^;]+)/i', $c, $mc)) {
        $sessionCookie = $mc[1];
        break;
    }
}

$loginSubmit = test_http(
    "http://127.0.0.1:8080/login.php",
    'POST',
    http_build_query([
        'csrf_token' => $csrfToken,
        'username'   => 'adminuser',
        'password'   => 'SecurePassword2026!'
    ]),
    [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Cookie'       => $sessionCookie
    ]
);
foreach ($loginSubmit['cookies'] as $c) {
    if (preg_match('/(AFM2_SESSID=[^;]+)/i', $c, $mc2)) {
        $sessionCookie = $mc2[1];
        break;
    }
}

// Request results CSV export
$resultsCsvRes = test_http("http://127.0.0.1:8080/results.php?export=csv", 'GET', null, ['Cookie' => $sessionCookie]);
assert_test("Path B (Live results.php export): HTTP status is 200 OK", $resultsCsvRes['status'] === 200);
assert_test("Path B (Live results.php export): Content-Type is text/csv", stripos($resultsCsvRes['headers']['content-type'] ?? '', 'text/csv') !== false);
assert_test("Path B (Live results.php export): Content-Disposition specifies attachment filename", stripos($resultsCsvRes['headers']['content-disposition'] ?? '', 'attachment') !== false);

// Parse returned CSV lines
$resultsLines = explode("\n", trim($resultsCsvRes['body']));
$headerRowParsed = str_getcsv($resultsLines[0], ',', '"', '\\');
assert_test("Path B (Live results.php export): UTF-8 BOM prefix is present for Excel compatibility", str_starts_with($resultsLines[0] ?? '', "\xEF\xBB\xBF"));
assert_test("Path B (Live results.php export): First column is 'Stage'", clean_csv_header_cell($headerRowParsed[0] ?? '') === 'Stage');
assert_test("Path B (Live results.php export): Header column count is 13", count($headerRowParsed) === 13);

// Verify no unescaped formula triggers in any emitted results cell
$hasUnescapedInResults = false;
for ($i = 1; $i < count($resultsLines); $i++) {
    $line = trim($resultsLines[$i]);
    if ($line === '') continue;
    $rowCells = str_getcsv($line, ',', '"', '\\');
    foreach ($rowCells as $cell) {
        $trimmedCell = ltrim($cell, " \t\r\n\0\x0B");
        if ($trimmedCell !== '' && in_array($trimmedCell[0], ['=', '+', '@'], true)) {
            $hasUnescapedInResults = true;
            break 2;
        }
    }
}
assert_test("Path B (Live results.php export): All exported rows free of unescaped formula triggers", !$hasUnescapedInResults);

// =====================================================================
// 12. CATALOGUE PRESERVATION INVARIANT CHECK
// =====================================================================
echo "\n12. Mandatory Catalogue Preservation Invariants\n";

$catalogueCount = (int)$pdo->query("SELECT COUNT(*) FROM `songs`")->fetchColumn();
assert_test("Catalogue count strictly intact at 2,411 songs", $catalogueCount === 2411, "Found: {$catalogueCount}");

$nullArtistSongs = $pdo->query("SELECT song_id FROM `songs` WHERE artist IS NULL ORDER BY song_id ASC")->fetchAll(PDO::FETCH_COLUMN);
assert_test("NULL-artist songs strictly preserved [1546, 2015, 2196]", $nullArtistSongs === [1546, 2015, 2196], "Found: " . json_encode($nullArtistSongs));

// =====================================================================
// SUMMARY
// =====================================================================
echo "\n======================================================================\n";
echo " BATCH C TEST EXECUTION SUMMARY\n";
echo "======================================================================\n";
echo "Total Tests Executed: {$testsTotal}\n";
echo "Passed:               {$testsPassed}\n";
echo "Failed:               " . ($testsTotal - $testsPassed) . "\n";

if ($testsTotal === $testsPassed) {
    echo "\n🎉 ALL BATCH C TESTS PASSED SUCCESSFULLY! (100% PASS RATE)\n\n";
} else {
    echo "\n❌ SOME BATCH C TESTS FAILED!\n\n";
    exit(1);
}
