<?php

declare(strict_types=1);

use Araga\AuxData;

require __DIR__ . '/../vendor/autoload.php';

// Example: values with TTL (time-to-live) using PSR-16 features

$cache = AuxData::open(__DIR__ . '/../storage');

// ============================================
// 1. TTL as integer (seconds)
// ============================================

echo "1. TTL as integer (seconds):\n";

// Store a value that expires in 5 seconds
$cache->set('short_lived', 'I will vanish soon...', 5);

echo "Immediately after set():\n";
var_dump($cache->get('short_lived', 'expired'));

echo "\nChecking with has() (optimized):\n";
var_dump($cache->has('short_lived')); // true

sleep(6);

echo "\nAfter 6 seconds:\n";
var_dump($cache->get('short_lived', 'expired (default)'));

echo "\nChecking with has() after expiration:\n";
var_dump($cache->has('short_lived')); // false

// ============================================
// 2. TTL = 0 means already expired
// ============================================

echo "\n2. TTL = 0 (already expired):\n";

$cache->set('instant_expire', 'already gone', 0);
var_dump($cache->get('instant_expire', 'not found')); // "not found"

// ============================================
// 3. TTL as DateInterval (PSR-16 standard)
// ============================================

echo "\n3. TTL as DateInterval (PSR-16):\n";

// Expires in 10 seconds
$cache->set('with_interval', 'Using DateInterval', new DateInterval('PT10S'));
var_dump($cache->get('with_interval')); // Value is still there

// More complex examples
$cache->set('one_hour', 'expires in 1 hour', new DateInterval('PT1H'));
$cache->set('one_day', 'expires in 1 day', new DateInterval('P1D'));

echo "Stored with 1 hour TTL: " . $cache->get('one_hour') . "\n";
echo "Stored with 1 day TTL: " . $cache->get('one_day') . "\n";

// ============================================
// 4. Batch operations with TTL
// ============================================

echo "\n4. Batch setMultiple with TTL:\n";

$cache->setMultiple([
    'session.user_id' => 123,
    'session.token' => 'abc123',
    'session.expires' => time() + 3600,
], new DateInterval('PT30M')); // All expire in 30 minutes

echo "Session stored with 30-minute TTL\n";

// ============================================
// 5. Cleaning up expired keys
// ============================================

echo "\n5. Garbage collection:\n";

echo "Before cleanup:\n";
$stats = $cache->stats();
echo " - Total: {$stats['total']}, Active: {$stats['active']}, Expired: {$stats['expired']}\n";

$deleted = $cache->cleanExpired();
echo "\nCleaned {$deleted} expired keys.\n";

echo "\nAfter cleanup:\n";
$stats = $cache->stats();
echo " - Total: {$stats['total']}, Active: {$stats['active']}, Expired: {$stats['expired']}\n";

// Clean up test data
$cache->deleteMultiple(['one_hour', 'one_day', 'session.user_id', 'session.token', 'session.expires']);

echo "\nDemo complete!\n";
