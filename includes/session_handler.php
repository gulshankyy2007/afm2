<?php
/**
 * AFM#2 - Database-Backed Session Handler (PDO)
 *
 * Implements SessionHandlerInterface and SessionUpdateTimestampHandlerInterface
 * to store PHP sessions in MySQL (table: app_sessions).
 * Enables multi-instance, serverless compatibility across ephemeral AWS Lambda containers on Vercel.
 *
 * Opt-in via SESSION_DRIVER=database in .env.
 * Default remains 'native' (file-based) for local development & InfinityFree shared hosting.
 */

if (!defined('AFM_APP')) {
    define('AFM_APP', true);
}

class PdoDatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    private ?PDO $pdo = null;
    private string $table;
    private int $lifetime;
    private bool $tableChecked = false;

    public function __construct(?PDO $pdo = null, string $table = 'app_sessions', int $lifetime = 86400) {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->lifetime = $lifetime;
    }

    private function getPdo(): PDO {
        if ($this->pdo === null) {
            require_once dirname(__DIR__) . '/config/db.php';
            $this->pdo = get_db_connection();
        }
        return $this->pdo;
    }

    private function ensureTable(): void {
        if ($this->tableChecked) {
            return;
        }
        try {
            $pdo = $this->getPdo();
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `{$this->table}` (
                    `id` VARCHAR(128) NOT NULL PRIMARY KEY,
                    `data` LONGTEXT NOT NULL,
                    `access` INT UNSIGNED NOT NULL,
                    INDEX `idx_access` (`access`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->tableChecked = true;
        } catch (Exception $e) {
            error_log("AFM2 Session Table Creation Warning: " . $e->getMessage());
        }
    }

    public function open(string $path, string $name): bool {
        try {
            $this->ensureTable();
            return true;
        } catch (Exception $e) {
            error_log("AFM2 Session open failed: " . $e->getMessage());
            return false;
        }
    }

    public function close(): bool {
        return true;
    }

    public function validateId(string $id): bool {
        try {
            $this->ensureTable();
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM `{$this->table}` 
                WHERE `id` = :id AND `access` >= :expiry 
                LIMIT 1
            ");
            $stmt->execute([
                ':id'     => $id,
                ':expiry' => time() - $this->lifetime
            ]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }

    public function read(string $id): string|false {
        try {
            $this->ensureTable();
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare("
                SELECT `data` 
                FROM `{$this->table}` 
                WHERE `id` = :id AND `access` >= :expiry 
                LIMIT 1
            ");
            $stmt->execute([
                ':id'     => $id,
                ':expiry' => time() - $this->lifetime
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['data'])) {
                return (string)$row['data'];
            }
            return '';
        } catch (Exception $e) {
            error_log("AFM2 Session read failed: " . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool {
        try {
            $this->ensureTable();
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare("
                INSERT INTO `{$this->table}` (`id`, `data`, `access`)
                VALUES (:id, :data, :access)
                ON DUPLICATE KEY UPDATE `data` = VALUES(`data`), `access` = VALUES(`access`)
            ");
            return $stmt->execute([
                ':id'     => $id,
                ':data'   => $data,
                ':access' => time()
            ]);
        } catch (Exception $e) {
            error_log("AFM2 Session write failed: " . $e->getMessage());
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool {
        try {
            $this->ensureTable();
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare("
                UPDATE `{$this->table}` 
                SET `access` = :access 
                WHERE `id` = :id
            ");
            return $stmt->execute([
                ':id'     => $id,
                ':access' => time()
            ]);
        } catch (Exception $e) {
            error_log("AFM2 Session updateTimestamp failed: " . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool {
        try {
            $this->ensureTable();
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare("DELETE FROM `{$this->table}` WHERE `id` = :id");
            return $stmt->execute([':id' => $id]);
        } catch (Exception $e) {
            error_log("AFM2 Session destroy failed: " . $e->getMessage());
            return false;
        }
    }

    public function gc(int $maxLifetime): int|false {
        try {
            $this->ensureTable();
            $pdo = $this->getPdo();
            $stmt = $pdo->prepare("DELETE FROM `{$this->table}` WHERE `access` < :expiry");
            $stmt->execute([':expiry' => time() - $maxLifetime]);
            return (int)$stmt->rowCount();
        } catch (Exception $e) {
            error_log("AFM2 Session gc failed: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Register database session handler if opt-in configuration is enabled
 */
function init_session_handler(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $driver = strtolower((string)env('SESSION_DRIVER', 'native'));
    if ($driver === 'database' || $driver === 'db') {
        $lifetime = (int)env('SESSION_LIFETIME', 86400);
        $handler = new PdoDatabaseSessionHandler(null, 'app_sessions', $lifetime);
        session_set_save_handler($handler, true);
    }
}
