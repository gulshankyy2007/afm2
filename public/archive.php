<?php
/**
 * AFM#2 Archive Redirect Endpoint
 * Permanently redirects /archive.php to the results and historical tournament records (/results.php).
 */
header("Location: results.php", true, 301);
exit;
