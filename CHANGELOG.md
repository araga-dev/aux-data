# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - Unreleased

### Added
- **PSR-16 SimpleCache compliance** - Full implementation of `Psr\SimpleCache\CacheInterface`
- `DateInterval` support for TTL values (PSR-16 standard)
- `setMultiple()` method for batch setting (PSR-16)
- `getMultiple()` method for batch retrieval (PSR-16)
- `deleteMultiple()` method for batch deletion (PSR-16)
- Simple key/value storage with SQLite
- TTL support for automatic expiration
- Atomic increment/decrement operations
- Transaction support
- WAL mode for better concurrency
- Chunk processing for large datasets
- Database statistics via `stats()`
- Garbage collection for expired keys via `cleanExpired()`
- `pull()` method for get-and-delete operation
- Multiple examples demonstrating usage
- Comprehensive documentation

### Changed
- **BREAKING**: `forget()` renamed to `delete()` (PSR-16 standard)
- **BREAKING**: `flush()` renamed to `clear()` (PSR-16 standard)
- **BREAKING**: `setMany()` renamed to `setMultiple()` (PSR-16 standard)
- **BREAKING**: `many()` renamed to `getMultiple()` (PSR-16 standard)
- `set()` now returns `bool` instead of `void` (PSR-16 compliance)
- `delete()` now returns `bool` instead of `void` (PSR-16 compliance)
- `clear()` now returns `bool` instead of `void` (PSR-16 compliance)
- TTL parameter now accepts `int|DateInterval|null` instead of just `int|null`

### Fixed
- TTL handling with value 0 (now correctly treated as "already expired")
- Optimized `has()` method to avoid unnecessary JSON decoding
- Batch deletion of expired keys in `all()` method

### Security
- Table name sanitization to prevent SQL injection
- Value size validation (max 10MB recommended)
- Proper key validation in batch operations

## Migration Guide from Pre-1.0

If you're upgrading from a pre-PSR-16 version, update your code as follows:

```php
// Before
$cache->forget('key');
$cache->flush();
$cache->setMany(['key' => 'value']);
$values = $cache->many(['key1', 'key2']);

// After (PSR-16 compliant)
$cache->delete('key');
$cache->clear();
$cache->setMultiple(['key' => 'value']);
$values = $cache->getMultiple(['key1', 'key2']);
```

All other methods (`get`, `has`, `increment`, `decrement`, `pull`, `all`, `keys`, `transaction`, `chunk`, `cleanExpired`, `stats`) remain unchanged.
