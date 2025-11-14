<?php

declare(strict_types=1);

namespace Araga;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Throwable;

/**
 * Class AuxData
 *
 * Lightweight key-value storage on top of a SQLite database using PDO.
 *
 * Features:
 * - Simple "settings" style API (set, get, pull, has, clear, increment, decrement).
 * - Automatic directory and database file creation.
 * - Optional per-key TTL (time-to-live) with automatic expiration cleanup.
 * - Support for multiple databases and custom table names.
 * - Atomic increment/decrement operations.
 * - Transaction support for batch operations.
 * - WAL mode enabled for better concurrency.
 * - PSR-16 SimpleCache (CacheInterface) compatible API.
 *
 * This is intentionally small and dependency-light, making it suitable for
 * CLI tools, small applications, and as an internal configuration store.
 *
 * IMPORTANT: While this library handles basic concurrency via SQLite's WAL mode,
 * it is not designed for high-traffic, heavily concurrent write scenarios.
 * For such use cases, consider Redis, Memcached, or a proper RDBMS.
 */
final class AuxData implements CacheInterface
{
    /**
     * Default SQLite database file name (relative to a root path).
     */
    public const DEFAULT_DATABASE = 'auxdata.db';

    /**
     * Default table name to store the key-value data.
     */
    public const DEFAULT_TABLE = 'settings';

    /**
     * Special value stored in the "exp" column to indicate that a record never expires.
     */
    private const NEVER_EXPIRES = -1;

    /**
     * Maximum recommended JSON size in bytes (10MB).
     * SQLite TEXT can hold up to 1GB, but large values impact performance.
     */
    private const MAX_VALUE_SIZE = 10485760; // 10MB

    /**
     * PDO instance connected to the SQLite database.
     */
    private ?PDO $pdo = null;

    /**
     * The table name used by this instance.
     */
    private string $table;

    /**
     * Optional database name when using the fluent ::database()->at() syntax.
     */
    private ?string $pendingDatabaseName = null;

    /**
     * Private constructor.
     *
     * You should create instances using:
     * - AuxData::open(...)
     * - AuxData::database(...)->at(...)
     *
     * @param string $tableName The table name where data will be stored.
     */
    private function __construct(string $tableName = self::DEFAULT_TABLE)
    {
        $this->table = $this->sanitizeTableName($tableName);
    }

    /**
     * Direct opening: root path + optional database name.
     *
     * Examples:
     * AuxData::open(__DIR__ . '/storage');
     * AuxData::open(__DIR__ . '/storage', 'custom.db');
     *
     * @param string      $rootPath     Directory where the SQLite file will be created/read.
     * @param string|null $databaseName Optional database file name; defaults to "auxdata.db".
     * @param string      $tableName    Table name to be used; defaults to "settings".
     *
     * @return self
     */
    public static function open(
        string $rootPath,
        ?string $databaseName = null,
        string $tableName = self::DEFAULT_TABLE
    ): self {
        $instance = new self($tableName);

        $dbName = $databaseName ?: self::DEFAULT_DATABASE;
        $dbPath = self::buildDatabasePath($rootPath, $dbName);

        $instance->initialize($dbPath);

        return $instance;
    }

    /**
     * Fluent API to define the database name before choosing the root path.
     *
     * Example:
     * $settings = AuxData::database('configs.db')->at(__DIR__ . '/storage');
     *
     * @param string $databaseName Database file name (must not be empty).
     * @param string $tableName    Table name to be used; defaults to "settings".
     *
     * @return self
     */
    public static function database(
        string $databaseName,
        string $tableName = self::DEFAULT_TABLE
    ): self {
        if ($databaseName === '') {
            throw new InvalidArgumentException('Database name cannot be empty.');
        }

        $instance = new self($tableName);
        $instance->pendingDatabaseName = $databaseName;

        return $instance;
    }

    /**
     * Finalizes the fluent API by specifying the root folder.
     *
     * This method:
     * - Uses the name provided to ::database()
     * - Or falls back to "auxdata.db" if none was provided
     *
     * Example:
     * $settings = AuxData::database('configs.db')->at(__DIR__ . '/storage');
     *
     * @param string $rootPath Directory where the SQLite file will be created/read.
     *
     * @return self
     */
    public function at(string $rootPath): self
    {
        $dbName = $this->pendingDatabaseName ?: self::DEFAULT_DATABASE;
        $dbPath = self::buildDatabasePath($rootPath, $dbName);

        $this->initialize($dbPath);

        return $this;
    }

    /**
     * Store a single key/value pair.
     *
     * @param string                 $key   Key to be stored (must not be empty).
     * @param mixed                  $value Value to store; it will be encoded with json_encode().
     * @param int|DateInterval|null  $ttl   Time-to-live; null means "never expires".
     *                                      When an integer is provided, it is interpreted as seconds.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     *
     * @return bool TRUE on success.
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        if ($key === '') {
            throw new InvalidArgumentException('Key cannot be empty.');
        }

        $ttlSeconds = $this->normalizeTtl($ttl);

        $expiresAt = ($ttlSeconds === null || $ttlSeconds < 0)
            ? self::NEVER_EXPIRES
            : ($ttlSeconds === 0 ? time() : time() + $ttlSeconds);

        $json = json_encode($value, JSON_THROW_ON_ERROR);

        if (strlen($json) > self::MAX_VALUE_SIZE) {
            throw new InvalidArgumentException(
                sprintf(
                    'Value too large (%d bytes). Maximum recommended size is %d bytes.',
                    strlen($json),
                    self::MAX_VALUE_SIZE
                )
            );
        }

        $sql = sprintf(
            'INSERT OR REPLACE INTO %s (key, value, exp)
             VALUES (:key, :value, :exp)',
            $this->table
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':key', $key, PDO::PARAM_STR);
        $stmt->bindValue(':value', $json, PDO::PARAM_STR);
        $stmt->bindValue(':exp', $expiresAt, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    /**
     * Store multiple key/value pairs at once within a transaction (PSR-16: setMultiple).
     *
     * @param iterable<string,mixed>     $values Associative iterable of key => value.
     * @param int|DateInterval|null      $ttl    Time-to-live for all keys; null = never expires.
     *
     * @return bool TRUE on success.
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $arrayValues = is_array($values) ? $values : iterator_to_array($values, true);
        $ttlSeconds  = $this->normalizeTtl($ttl);

        $this->transaction(function () use ($arrayValues, $ttlSeconds) {
            foreach ($arrayValues as $key => $value) {
                if (!is_string($key) || $key === '') {
                    throw new InvalidArgumentException('Cache keys must be non-empty strings.');
                }

                $this->set($key, $value, $ttlSeconds);
            }
        });

        return true;
    }

    /**
     * Retrieve a value by key.
     *
     * If the key does not exist or is expired, the provided default value will be returned.
     *
     * @param string $key     Key to be retrieved.
     * @param mixed  $default Default value if the key doesn't exist or is expired.
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $sql = sprintf(
            'SELECT value, exp FROM %s WHERE key = :key LIMIT 1',
            $this->table
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':key', $key, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch();

        if ($row === false) {
            return $default;
        }

        $exp = (int) $row['exp'];

        if ($this->isExpired($exp)) {
            $this->delete($key);
            return $default;
        }

        return json_decode($row['value'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Retrieve several values at once (PSR-16: getMultiple).
     *
     * For any key that does not exist or is expired, the default value is used.
     *
     * @param iterable<string> $keys    List of keys to retrieve.
     * @param mixed            $default Default value for missing/expired keys.
     *
     * @return iterable<string,mixed> Associative array of key => value.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('Cache keys must be non-empty strings.');
            }

            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    /**
     * Retrieve a value by key and then delete it (like a "pull" operation).
     *
     * @param string $key     Key to be retrieved and deleted.
     * @param mixed  $default Default value if the key doesn't exist or is expired.
     *
     * @return mixed
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->transaction(function () use ($key, $default) {
            $value = $this->get($key, $default);
            $this->delete($key);
            return $value;
        });
    }

    /**
     * Check if a key exists and is not expired.
     *
     * This method is optimized to avoid JSON decoding by using a direct EXISTS query.
     *
     * Note: This checks for key existence in the database, not the value itself.
     * If you need to distinguish between a missing key and a key with value null,
     * use get() and check if the returned value matches your default.
     *
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        $sql = sprintf(
            'SELECT 1 FROM %s WHERE key = :key AND (exp = :never OR exp >= :now) LIMIT 1',
            $this->table
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':key', $key, PDO::PARAM_STR);
        $stmt->bindValue(':never', self::NEVER_EXPIRES, PDO::PARAM_INT);
        $stmt->bindValue(':now', time(), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Delete a key from the storage (PSR-16: delete).
     *
     * @param string $key
     *
     * @return bool TRUE on success.
     */
    public function delete(string $key): bool
    {
        $sql = sprintf('DELETE FROM %s WHERE key = :key', $this->table);

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':key', $key, PDO::PARAM_STR);
        $stmt->execute();

        return true;
    }

    /**
     * Delete multiple keys from the storage (PSR-16: deleteMultiple).
     *
     * @param iterable<string> $keys
     *
     * @return bool TRUE on success.
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $arrayKeys = is_array($keys) ? $keys : iterator_to_array($keys, false);

        $this->transaction(function () use ($arrayKeys) {
            foreach ($arrayKeys as $key) {
                if (!is_string($key) || $key === '') {
                    throw new InvalidArgumentException('Cache keys must be non-empty strings.');
                }

                $this->delete($key);
            }
        });

        return true;
    }

    /**
     * Delete *all* keys from the storage (PSR-16: clear).
     *
     * @return bool TRUE on success.
     */
    public function clear(): bool
    {
        $sql = sprintf('DELETE FROM %s', $this->table);
        $this->pdo->exec($sql);

        return true;
    }

    /**
     * Retrieve all valid keys and values.
     *
     * WARNING: This loads all data into memory. For large datasets, consider using chunk() instead.
     * Expired keys are automatically removed.
     *
     * @return array<string,mixed> Associative array of key => value.
     */
    public function all(): array
    {
        $sql = sprintf('SELECT key, value, exp FROM %s', $this->table);

        $stmt = $this->pdo->query($sql);

        $result   = [];
        $toDelete = [];

        while ($row = $stmt->fetch()) {
            $exp = (int) $row['exp'];

            if ($this->isExpired($exp)) {
                $toDelete[] = $row['key'];
                continue;
            }

            $result[$row['key']] = json_decode(
                $row['value'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        // Batch delete expired keys
        if (!empty($toDelete)) {
            $this->transaction(function () use ($toDelete) {
                foreach ($toDelete as $key) {
                    $this->delete($key);
                }
            });
        }

        return $result;
    }

    /**
     * Return only the (non-expired) keys.
     *
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * Increment an integer value atomically.
     *
     * If the key does not exist, it will be initialized with 0 and then incremented.
     * This operation is atomic and safe for concurrent access.
     *
     * @param string $key Key to increment.
     * @param int    $by  Increment step (can be negative).
     *
     * @return int The new value after increment.
     */
    public function increment(string $key, int $by = 1): int
    {
        if ($key === '') {
            throw new InvalidArgumentException('Key cannot be empty.');
        }

        return $this->transaction(function () use ($key, $by) {
            // Try to get current value
            $sql = sprintf(
                'SELECT value, exp FROM %s WHERE key = :key LIMIT 1',
                $this->table
            );

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':key', $key, PDO::PARAM_STR);
            $stmt->execute();

            $row = $stmt->fetch();

            if ($row === false || $this->isExpired((int) $row['exp'])) {
                // Key doesn't exist or is expired, initialize with $by
                $newValue = $by;
                $this->set($key, $newValue);
                return $newValue;
            }

            // Key exists and is valid, increment it
            $currentValue = (int) json_decode($row['value'], true, 512, JSON_THROW_ON_ERROR);
            $newValue     = $currentValue + $by;

            $updateSql = sprintf(
                'UPDATE %s SET value = :value WHERE key = :key',
                $this->table
            );

            $updateStmt = $this->pdo->prepare($updateSql);
            $updateStmt->bindValue(':value', json_encode($newValue), PDO::PARAM_STR);
            $updateStmt->bindValue(':key', $key, PDO::PARAM_STR);
            $updateStmt->execute();

            return $newValue;
        });
    }

    /**
     * Decrement an integer value atomically.
     *
     * @param string $key Key to decrement.
     * @param int    $by  Decrement step (defaults to 1).
     *
     * @return int The new value after decrement.
     */
    public function decrement(string $key, int $by = 1): int
    {
        return $this->increment($key, -$by);
    }

    /**
     * Execute a callback within a database transaction.
     *
     * If the callback throws an exception, the transaction is rolled back.
     * Otherwise, it is committed.
     *
     * Example:
     * $result = $store->transaction(function ($store) {
     *     $store->set('key1', 'value1');
     *     $store->set('key2', 'value2');
     *     return 'done';
     * });
     *
     * @param callable $callback Receives this AuxData instance as argument.
     *
     * @return mixed The return value of the callback.
     * @throws Throwable
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Remove all expired keys from the database.
     *
     * This is useful for periodic cleanup to reclaim disk space.
     * Returns the number of keys deleted.
     *
     * @return int Number of expired keys removed.
     */
    public function cleanExpired(): int
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE exp != :never AND exp < :now',
            $this->table
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':never', self::NEVER_EXPIRES, PDO::PARAM_INT);
        $stmt->bindValue(':now', time(), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Process records in chunks to avoid memory exhaustion.
     *
     * The callback receives an array of key => value pairs for each chunk.
     * Expired records are automatically skipped.
     *
     * Example:
     * $store->chunk(100, function (array $items) {
     *     foreach ($items as $key => $value) {
     *         echo "{$key}: {$value}\n";
     *     }
     * });
     *
     * @param int      $size     Number of records per chunk.
     * @param callable $callback Receives array of key => value pairs.
     *
     * @return void
     */
    public function chunk(int $size, callable $callback): void
    {
        if ($size <= 0) {
            throw new InvalidArgumentException('Chunk size must be greater than 0.');
        }

        $offset = 0;

        while (true) {
            $sql = sprintf(
                'SELECT key, value, exp FROM %s LIMIT :limit OFFSET :offset',
                $this->table
            );

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':limit', $size, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                break;
            }

            $chunk = [];

            foreach ($rows as $row) {
                $exp = (int) $row['exp'];

                if ($this->isExpired($exp)) {
                    continue;
                }

                $chunk[$row['key']] = json_decode(
                    $row['value'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            }

            if (!empty($chunk)) {
                $callback($chunk);
            }

            $offset += $size;

            if (count($rows) < $size) {
                break;
            }
        }
    }

    /**
     * Get database statistics.
     *
     * Returns information about:
     * - total: Total number of keys (including expired)
     * - active: Number of non-expired keys
     * - expired: Number of expired keys
     * - size: Approximate database size in bytes
     *
     * @return array<string,int>
     */
    public function stats(): array
    {
        $totalSql = sprintf('SELECT COUNT(*) FROM %s', $this->table);
        $total    = (int) $this->pdo->query($totalSql)->fetchColumn();

        $expiredSql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE exp != :never AND exp < :now',
            $this->table
        );
        $stmt = $this->pdo->prepare($expiredSql);
        $stmt->bindValue(':never', self::NEVER_EXPIRES, PDO::PARAM_INT);
        $stmt->bindValue(':now', time(), PDO::PARAM_INT);
        $stmt->execute();
        $expired = (int) $stmt->fetchColumn();

        $active = $total - $expired;

        // Get database file size
        $dbPath = $this->pdo->query("PRAGMA database_list")->fetch()['file'] ?? '';
        $size   = file_exists($dbPath) ? filesize($dbPath) : 0;

        return [
            'total'   => $total,
            'active'  => $active,
            'expired' => $expired,
            'size'    => $size ?: 0,
        ];
    }

    // ============================================================
    // ======================= Internals ==========================
    // ============================================================

    /**
     * Build the full SQLite database path from a root directory and file name.
     *
     * @param string $rootPath
     * @param string $databaseName
     *
     * @return string
     */
    private static function buildDatabasePath(string $rootPath, string $databaseName): string
    {
        $rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);

        return $rootPath . DIRECTORY_SEPARATOR . $databaseName;
    }

    /**
     * Initialize the SQLite database file and PDO connection.
     *
     * This method:
     * - Ensures the directory exists (and creates it if needed).
     * - Ensures read/write permissions.
     * - Opens or creates the SQLite file.
     * - Enables WAL mode for better concurrency.
     * - Creates the table and index if they do not exist.
     *
     * @param string $databasePath Full path to the SQLite file.
     *
     * @throws RuntimeException
     */
    private function initialize(string $databasePath): void
    {
        $dir = \dirname($databasePath);

        // Ensure directory exists
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException(
                    "Unable to create directory: {$dir}"
                );
            }
        }

        // If the file already exists, check read/write permissions
        if (file_exists($databasePath)) {
            if (!is_readable($databasePath)) {
                throw new RuntimeException(
                    "Database exists but is not readable: {$databasePath}"
                );
            }

            if (!is_writable($databasePath)) {
                throw new RuntimeException(
                    "Database exists but is not writable: {$databasePath}"
                );
            }
        } else {
            // If it does not exist, ensure the directory is writable
            if (!is_writable($dir)) {
                throw new RuntimeException(
                    "Directory is not writable to create the database: {$dir}"
                );
            }
        }

        try {
            $this->pdo = new PDO('sqlite:' . $databasePath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(
                PDO::ATTR_DEFAULT_FETCH_MODE,
                PDO::FETCH_ASSOC
            );

            // Enable WAL mode for better concurrency
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            // Use NORMAL synchronous mode for better performance while maintaining safety
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            // Set busy timeout to 5 seconds for concurrent access
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Unable to open/create SQLite database: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $this->createTableIfNotExists();
        $this->createIndexIfNotExists();
    }

    /**
     * Create the key-value table if it does not already exist.
     */
    private function createTableIfNotExists(): void
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                exp   INTEGER NOT NULL
            )',
            $this->table
        );

        $this->pdo->exec($sql);
    }

    /**
     * Create an index on the exp column for faster expiration queries.
     */
    private function createIndexIfNotExists(): void
    {
        $indexName = 'idx_' . $this->table . '_exp';

        $sql = sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s(exp) WHERE exp != %d',
            $indexName,
            $this->table,
            self::NEVER_EXPIRES
        );

        $this->pdo->exec($sql);
    }

    /**
     * Check if a given expiration timestamp is already expired.
     *
     * @param int $expiration
     *
     * @return bool
     */
    private function isExpired(int $expiration): bool
    {
        if ($expiration === self::NEVER_EXPIRES) {
            return false;
        }

        return $expiration < time();
    }

    /**
     * Normalize a TTL value (int|DateInterval|null) into seconds or null.
     *
     * @param int|DateInterval|null $ttl
     *
     * @return int|null Number of seconds, or null when no expiration is set.
     */
    private function normalizeTtl(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            $reference = new DateTimeImmutable();
            $target    = $reference->add($ttl);

            $seconds = $target->getTimestamp() - $reference->getTimestamp();

            return $seconds < 0 ? 0 : $seconds;
        }

        return $ttl;
    }

    /**
     * Sanitize the table name to avoid SQL injection and invalid identifiers.
     *
     * Only letters, numbers and underscore are allowed.
     * If the first character is a number, an underscore is prefixed.
     *
     * @param string $name Raw table name.
     *
     * @return string Safe table name.
     */
    private function sanitizeTableName(string $name): string
    {
        // Allow only letters, numbers and underscore
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? self::DEFAULT_TABLE;

        if ($name === '' || is_numeric($name[0])) {
            $name = '_' . $name;
        }

        return $name;
    }
}
