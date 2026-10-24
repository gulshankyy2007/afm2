<?php
/**
 * AFM#2 - Database Migration Runner
 * Executes versioned, additive migrations (.php and .sql) and tracks execution history.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';

function run_migrations(): array {
    $pdo = get_db_connection();
    $logs = [];

    // Ensure schema_migrations table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `migration_id` int unsigned NOT NULL AUTO_INCREMENT,
            `migration_name` varchar(100) NOT NULL,
            `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`migration_id`),
            UNIQUE KEY `uq_migration_name` (`migration_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
    ");

    // Fetch applied migrations
    $stmt = $pdo->query("SELECT `migration_name` FROM `schema_migrations`");
    $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Scan migration directory for .php and .sql files
    $migrationDir = __DIR__;
    $files = glob($migrationDir . '/*');
    $migrationFiles = [];
    foreach ($files as $f) {
        $ext = pathinfo($f, PATHINFO_EXTENSION);
        $base = basename($f);
        if (($ext === 'php' || $ext === 'sql') && $base !== 'migration_runner.php') {
            $migrationFiles[] = $f;
        }
    }
    sort($migrationFiles);

    $appliedCount = 0;
    foreach ($migrationFiles as $file) {
        $migrationName = basename($file);
        if (in_array($migrationName, $applied, true)) {
            continue;
        }

        $logs[] = "Applying migration: {$migrationName}...";
        $ext = pathinfo($file, PATHINFO_EXTENSION);

        if ($ext === 'php') {
            $callable = require $file;
            if (is_callable($callable)) {
                $callable($pdo);
            }
        } elseif ($ext === 'sql') {
            $sql = file_get_contents($file);
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($stmt) => !empty($stmt)
            );
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $pdo->exec($statement);
                }
            }
        }

        // Record migration
        $rec = $pdo->prepare("INSERT INTO `schema_migrations` (`migration_name`) VALUES (:name)");
        $rec->execute([':name' => $migrationName]);

        $logs[] = "Successfully applied: {$migrationName}";
        $appliedCount++;
    }

    if ($appliedCount === 0) {
        $logs[] = "Database schema is up to date. No pending migrations.";
    }

    return $logs;
}

// Allow CLI execution
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    echo "Running AFM#2 database migrations...\n";
    try {
        $logs = run_migrations();
        foreach ($logs as $log) {
            echo "  [INFO] $log\n";
        }
        echo "Done.\n";
    } catch (Exception $e) {
        echo "  [ERROR] Migration failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
