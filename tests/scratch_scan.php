<?php
require_once dirname(__DIR__) . '/config/db.php';
$pdo = get_db_connection();

$songs = $pdo->query("SELECT song_id, title, artist FROM songs ORDER BY song_id ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "Total songs in DB: " . count($songs) . "\n";

function norm($s) {
    if (!$s) return "";
    $s = strtolower(trim($s));
    $s = preg_replace("/\s+/", " ", $s);
    return $s;
}

function strip_suffixes($title) {
    $t = norm($title);
    $patterns = [
        "/\s*[\(\[](official\s+(music\s+)?video|official\s+audio|lyric\s+video|visualizer|audio)[\)\]]/iu",
        "/\s*[\(\[](live[^\)\]]*|acoustic[^\)\]]*|unplugged[^\)\]]*)[\)\]]/iu",
        "/\s*[\(\[](remaster(ed)?[^\)\]]*|\d{4}\s+remaster)[\)\]]/iu",
        "/\s*[\(\[](radio\s+edit|extended\s+mix|club\s+mix|album\s+version|single\s+version)[\)\]]/iu",
        "/\s*-\s*(live|acoustic|remastered).*$/iu",
    ];
    foreach ($patterns as $p) {
        $t = preg_replace($p, "", $t);
    }
    $t = preg_replace("/[\'\"’“”]/u", "", $t);
    return trim(preg_replace("/\s+/u", " ", $t));
}

$exactNorm = [];
$versionGroups = [];
$titleDiffArtist = [];

foreach ($songs as $s) {
    $nt = norm($s["title"]);
    $na = norm($s["artist"]);
    $st = strip_suffixes($s["title"]);

    // 1. Exact normalized
    $k1 = $nt . " ### " . $na;
    $exactNorm[$k1][] = $s;

    // 2. Version groups
    if ($na !== "") {
        $k2 = $st . " ### " . $na;
        $versionGroups[$k2][] = $s;
    }

    // 3. Title match diff artist
    $titleDiffArtist[$st][] = $s;
}

$exactMatches = array_filter($exactNorm, function($g) { return count($g) > 1; });
echo "Exact normalized (title + artist) duplicate groups: " . count($exactMatches) . "\n";
foreach (array_slice($exactMatches, 0, 5) as $k => $v) {
    $ids = array_column($v, "song_id");
    echo "  - {$k}: IDs " . implode(", ", $ids) . "\n";
}

$verMatches = array_filter($versionGroups, function($g) { return count($g) > 1; });
echo "Version / Alternate Candidate Groups (Live, Remaster, Acoustic, etc.): " . count($verMatches) . "\n";
foreach (array_slice($verMatches, 0, 10) as $k => $v) {
    $titles = array_map(function($x) { return "#" . $x["song_id"] . " (" . $x["title"] . ")"; }, $v);
    echo "  - {$k}: " . implode(" vs ", $titles) . "\n";
}

$diffArtistMatches = array_filter($titleDiffArtist, function($g) {
    if (count($g) < 2) return false;
    $artists = array_unique(array_map(function($x) { return norm($x["artist"]); }, $g));
    return count($artists) > 1;
});
echo "Same Title Different Artist Groups: " . count($diffArtistMatches) . "\n";
foreach (array_slice($diffArtistMatches, 0, 5) as $k => $v) {
    $artists = array_map(function($x) { return "#" . $x["song_id"] . " by " . ($x["artist"] ?: "NULL"); }, $v);
    echo "  - Title \"{$k}\": " . implode(" vs ", $artists) . "\n";
}
