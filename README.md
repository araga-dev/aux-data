# AuxData

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![PSR-16](https://img.shields.io/badge/PSR--16-compliant-brightgreen.svg)](https://www.php-fig.org/psr/psr-16/)

Small, dependency-free key/value storage on top of SQLite using PDO.

AuxData is designed for situations where you want a simple and persistent
configuration or cache-like store without bringing a full cache system or database
abstraction layer into your project.

## Features

- **PSR-16 SimpleCache compliant** - Standard interface for interoperability
- Lightweight: a single PHP class with minimal dependencies
- Uses SQLite via PDO with WAL mode for better concurrency
- Simple API: `set`, `get`, `has`, `delete`, `clear`
- PSR-16 batch operations: `setMultiple`, `getMultiple`, `deleteMultiple`
- Optional TTL (time-to-live) per key with `DateInterval` support
- Multiple databases and table names supported
- Atomic increment/decrement operations (safe for concurrent access)
- Transaction support for batch operations
- Garbage collection of expired keys
- Statistics and chunked iteration for large datasets
- Automatically creates the directory and SQLite file if needed

## Requirements

- PHP 8.1+
- PDO SQLite extension
- PSR-16 Simple Cache interface (`psr/simple-cache`)

## When to Use

**✅ Perfect for:**
- CLI tools and scripts
- Single-user desktop applications
- Local configuration storage
- Development/testing caches
- Low to moderate traffic web applications
- Background job storage
- Simple session-like data
- Any project needing PSR-16 SimpleCache without external services

**❌ Not recommended for:**
- High-traffic APIs with heavy concurrent writes (use Redis/Memcached instead)
- Distributed systems requiring shared cache across multiple servers
- Applications needing sub-millisecond response times
- Systems with hundreds of concurrent write operations per second

**💡 Concurrency Notes:**
- SQLite with WAL mode handles multiple readers + one writer well
- Perfect for scenarios with read-heavy workloads
- `increment/decrement` operations are atomic and safe
- Multiple processes on the same machine can safely access the database
- For multi-server environments, use a network-based cache instead

## Installation

```bash
composer require araga/aux-data
```

## Basic Usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Araga\AuxData;

// Open (or create) auxdata.db inside the "storage" folder
$cache = AuxData::open(__DIR__ . '/storage');

// Store a simple value
$cache->set('app.name', 'My Awesome App');

// Store an array (it will be JSON-encoded)
$cache->set('features', [
    'dark_mode' => true,
    'beta'      => ['new_layout' => true],
]);

// Retrieve values
$name     = $cache->get('app.name');                    // "My Awesome App"
$missing  = $cache->get('missing.key', 'default-value'); // "default-value"

// Check existence (optimized - no JSON decode)
if ($cache->has('features')) {
    $features = $cache->get('features');
}

// Remove a single key
$cache->delete('app.name');

// Remove all keys
// $cache->clear();
```

## PSR-16 SimpleCache Interface

AuxData implements the standard PSR-16 interface:

```php
<?php

use Araga\AuxData;
use Psr\SimpleCache\CacheInterface;

// Type-hint with the standard interface
function processWithCache(CacheInterface $cache) {
    $cache->set('key', 'value', 60); // TTL in seconds
    return $cache->get('key');
}

$cache = AuxData::open(__DIR__ . '/storage');
$result = processWithCache($cache); // Works with any PSR-16 implementation!
```

## Using TTL (Time-to-Live)

```php
<?php

use Araga\AuxData;

$cache = AuxData::open(__DIR__ . '/storage');

// TTL as integer (seconds)
$cache->set('api_token', 'abc123', 60); // Expires in 60 seconds

// TTL as DateInterval (PSR-16 standard)
$cache->set('session', $data, new DateInterval('PT1H')); // Expires in 1 hour

// No expiration
$cache->set('permanent', 'forever', null);

// After expiration, get() returns the default value
$token = $cache->get('api_token', null);

// Clean up all expired keys manually (reclaim disk space)
$deletedCount = $cache->cleanExpired();
```

## Multiple Databases and Tables

```php
<?php

use Araga\AuxData;

// Using a custom database name
$userStorage = AuxData::open(__DIR__ . '/storage', 'users.db');

// Using the fluent API
$logs = AuxData::database('logs.db')->at(__DIR__ . '/storage');

// Using a different table name
$config = AuxData::open(__DIR__ . '/storage', null, 'config_table');
```

## PSR-16 Batch Operations

```php
<?php

use Araga\AuxData;

$cache = AuxData::open(__DIR__ . '/storage');

// Set multiple values at once (atomic transaction)
$cache->setMultiple([
    'app.locale' => 'en',
    'app.debug'  => true,
    'limits'     => ['max_items' => 50],
], 3600); // TTL for all keys

// Get multiple values at once
$values = $cache->getMultiple(['app.locale', 'app.debug', 'missing'], 'N/A');
// Returns: ['app.locale' => 'en', 'app.debug' => true, 'missing' => 'N/A']

// Delete multiple keys
$cache->deleteMultiple(['app.locale', 'app.debug']);
```

## Atomic Increment / Decrement

These operations are thread-safe and work correctly with concurrent processes:

```php
<?php

use Araga\AuxData;

$counter = AuxData::open(__DIR__ . '/storage');

// Initialize (if not exists) and increment
$views = $counter->increment('page.views'); // 1

// Increment by custom amount
$views = $counter->increment('page.views', 10); // 11

// Decrement
$views = $counter->decrement('page.views', 2); // 9
```

## Transactions

Execute multiple operations atomically:

```php
<?php

use Araga\AuxData;

$store = AuxData::open(__DIR__ . '/storage');

$result = $store->transaction(function ($store) {
    $store->set('key1', 'value1');
    $store->set('key2', 'value2');
    
    // If an exception is thrown, all changes are rolled back
    
    return 'success';
});
```

## Advanced Features

### Pull Operation (Get + Delete)

```php
<?php

$cache = AuxData::open(__DIR__ . '/storage');

// Get value and immediately delete it
$token = $cache->pull('one_time_token', null);
```

### Processing Large Datasets

Use `chunk()` to avoid loading all data into memory:

```php
<?php

use Araga\AuxData;

$store = AuxData::open(__DIR__ . '/storage');

// Process 100 records at a time
$store->chunk(100, function (array $items) {
    foreach ($items as $key => $value) {
        // Process each item
    }
});
```

### Get All Keys/Values

```php
<?php

$cache = AuxData::open(__DIR__ . '/storage');

// Get all valid keys
$keys = $cache->keys();

// Get all valid key => value pairs (WARNING: loads all into memory)
$all = $cache->all();
```

### Statistics

Get information about your database:

```php
<?php

use Araga\AuxData;

$store = AuxData::open(__DIR__ . '/storage');

$stats = $store->stats();
// Returns:
// [
//   'total' => 150,      // Total keys (including expired)
//   'active' => 145,     // Non-expired keys  
//   'expired' => 5,      // Expired keys
//   'size' => 24576,     // Database file size in bytes
// ]
```

## Multi-Process Usage

AuxData is safe for use across multiple processes on the same machine:

```php
<?php

// Process 1 (web server)
$cache = AuxData::open('/var/www/storage');
$cache->increment('requests');

// Process 2 (background worker) 
$cache = AuxData::open('/var/www/storage');
$cache->increment('requests');

// Process 3 (CLI script)
$cache = AuxData::open('/var/www/storage');
$count = $cache->get('requests'); // Gets the correct total
```

SQLite's WAL mode (enabled by default) allows:
- Multiple processes reading simultaneously
- One writer at a time (with 5-second busy timeout)
- Automatic locking and retry on conflicts

## Performance Considerations

- **Concurrency**: WAL mode allows multiple readers + one writer. Good for read-heavy workloads.
- **Value Size**: Maximum recommended size is 10MB per value. Larger values impact performance.
- **Expired Keys**: Call `cleanExpired()` periodically to reclaim disk space.
- **Large Datasets**: Use `chunk()` instead of `all()` when working with thousands of keys.
- **Busy Timeout**: Set to 5 seconds by default. Writers will retry during this period.

## Error Handling

AuxData throws:

- `InvalidArgumentException` for invalid keys, values, or parameters
- `RuntimeException` for I/O problems or PDO connection failures

Make sure the target directory is writable by your PHP process.

## API Reference

### PSR-16 Methods (Standard)
- `set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool`
- `get(string $key, mixed $default = null): mixed`
- `delete(string $key): bool`
- `clear(): bool`
- `has(string $key): bool`
- `setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool`
- `getMultiple(iterable $keys, mixed $default = null): iterable`
- `deleteMultiple(iterable $keys): bool`

### Additional Methods (Non-standard)
- `AuxData::open(string $rootPath, ?string $databaseName = null, string $tableName = 'settings'): self`
- `AuxData::database(string $databaseName, string $tableName = 'settings'): self`
- `at(string $rootPath): self`
- `pull(string $key, mixed $default = null): mixed`
- `all(): array`
- `keys(): array`
- `increment(string $key, int $by = 1): int`
- `decrement(string $key, int $by = 1): int`
- `transaction(callable $callback): mixed`
- `chunk(int $size, callable $callback): void`
- `cleanExpired(): int`
- `stats(): array`

## Examples

See the `/examples` directory for complete working examples:

- `quick-start.php` - Simplest possible usage
- `psr16-example.php` - **PSR-16 interface demonstration**
- `basic-usage.php` - Common operations and API overview
- `ttl-example.php` - Time-to-live with integer and DateInterval
- `chunk-example.php` - Processing large datasets
- `concurrent-example.php` - Atomic counter operations
- `multi-process-example.php` - Multi-process safety demonstration

## Migration from Non-PSR-16 Version

If you're migrating from an older version, here are the breaking changes:

| Old Method | New Method | Notes |
|------------|------------|-------|
| `forget($key)` | `delete($key)` | Now returns `bool` |
| `flush()` | `clear()` | Now returns `bool` |
| `setMany($values, $ttl)` | `setMultiple($values, $ttl)` | Now returns `bool` |
| `many($keys, $default)` | `getMultiple($keys, $default)` | Returns iterable |

All other methods remain unchanged.

## Project Structure

```
.
├── src
│   └── AuxData.php (PSR-16 compliant)
├── examples/
│   ├── quick-start.php
│   ├── psr16-example.php
│   ├── basic-usage.php
│   ├── ttl-example.php
│   ├── chunk-example.php
│   ├── concurrent-example.php
│   └── multi-process-example.php
├── composer.json
├── CHANGELOG.md
├── LICENSE
└── README.md
```

## License

This library is open-sourced software licensed under the [MIT license](LICENSE).
