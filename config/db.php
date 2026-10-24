<?php
/**
 * AFM#2 - Database Connection Provider (PDO)
 */

if (!defined('AFM_APP')) {
    require_once __DIR__ . '/config.php';
}

/**
 * Returns a shared PDO connection to the MySQL database.
 * Uses prepared statements and safe error handling.
 */
function get_db_connection(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host    = DB_HOST;
    $port    = DB_PORT;
    $dbname  = DB_NAME;
    $user    = DB_USER;
    $pass    = DB_PASS;
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Environment-driven SSL / TLS configuration for cloud database connections
    $dbSsl = filter_var(env('DB_SSL', false), FILTER_VALIDATE_BOOLEAN);
    if ($dbSsl) {
        $sslCa = trim((string)env('DB_SSL_CA', ''));
        if ($sslCa !== '') {
            // Explicitly user-configured CA path: enforce strictly without silent fallback
            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        } else {
            // Auto-discover standard OS and cloud runtime CA certificate bundles
            $systemCaBundles = [
                '/etc/ssl/certs/ca-certificates.crt', // Debian / Ubuntu / AWS Lambda (Vercel)
                '/etc/pki/tls/certs/ca-bundle.crt',   // RHEL / CentOS / Amazon Linux
                '/etc/ssl/ca-bundle.pem',              // OpenSUSE
                '/etc/ssl/cert.pem',                   // Alpine / macOS
            ];
            foreach ($systemCaBundles as $bundle) {
                if (file_exists($bundle)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $bundle;
                    break;
                }
            }
        }

        // Maintain strict certificate verification by default
        $verifyCert = filter_var(env('DB_SSL_VERIFY_SERVER_CERT', true), FILTER_VALIDATE_BOOLEAN);
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $verifyCert;

        $sslCipher = trim((string)env('DB_SSL_CIPHER', ''));
        if ($sslCipher !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CIPHER] = $sslCipher;
        }
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    } catch (PDOException $e) {
        // Log detailed error internally
        error_log("AFM2 Database Connection Error: " . $e->getMessage());
        // Show safe sanitized error to client
        throw new RuntimeException("Database connection error. Please contact the administrator or check configuration.");
    }

    return $pdo;
}
