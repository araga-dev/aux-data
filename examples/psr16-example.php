<?php

declare(strict_types=1);

use Araga\AuxData;
use Psr\SimpleCache\CacheInterface;

require __DIR__ . '/../vendor/autoload.php';

/**
 * PSR-16 SimpleCache Interface Example
 * 
 * This demonstrates AuxData's compliance with PSR-16,
 * allowing it to be used anywhere a CacheInterface is expected.
 */

// ============================================
// 1. Type-hinting with PSR-16 interface
// ============================================

function cacheUserData(CacheInterface $cache, int $userId): array
{
    $cacheKey = "user.{$userId}";
    
    // Try to get from cache first
    $userData = $cache->get($cacheKey);
    
    if ($userData === null) {
        // Simulate database fetch
        $userData = [
            'id' => $userId,
            'name' => 'User ' . $userId,
            'email' => "user{$userId}@example.com",
            'fetched_at' => date('Y-m-d H:i:s'),
        ];
        
        // Store in cache for 1 hour
        $cache->set($cacheKey, $userData, 3600);
        
        echo "✅ User {$userId} fetched from 'database' and cached\n";
    } else {
        echo "✅ User {$userId} retrieved from cache (fetched at: {$userData['fetched_at']})\n";
    }
    
    return $userData;
}

echo "PSR-16 SimpleCache Interface Demo\n";
echo "==================================\n\n";

// Create AuxData instance
$cache = AuxData::open(__DIR__ . '/../storage');

// Clear any existing test data
$cache->clear();

// ============================================
// 2. Using PSR-16 methods
// ============================================

echo "1. Basic PSR-16 operations:\n";

// set() with TTL
$cache->set('api.token', 'secret-token-123', 60);
echo "✅ Token cached with 60s TTL\n";

// has()
if ($cache->has('api.token')) {
    echo "✅ Token exists in cache\n";
}

// get()
$token = $cache->get('api.token', 'default-token');
echo "✅ Retrieved token: {$token}\n";

// delete()
$cache->delete('api.token');
echo "✅ Token deleted\n\n";

// ============================================
// 3. Batch operations (PSR-16)
// ============================================

echo "2. PSR-16 batch operations:\n";

// setMultiple()
$settings = [
    'app.name' => 'My Application',
    'app.version' => '1.0.0',
    'app.debug' => false,
];

$cache->setMultiple($settings, new DateInterval('PT5M')); // 5 minutes
echo "✅ Multiple settings cached\n";

// getMultiple()
$keys = ['app.name', 'app.version', 'app.debug', 'app.missing'];
$values = $cache->getMultiple($keys, 'N/A');

echo "Retrieved values:\n";
foreach ($values as $key => $value) {
    $valueStr = is_bool($value) ? ($value ? 'true' : 'false') : $value;
    echo "  {$key} = {$valueStr}\n";
}

// deleteMultiple()
$cache->deleteMultiple(['app.name', 'app.version']);
echo "✅ Multiple keys deleted\n\n";

// ============================================
// 4. Using with type-hinted function
// ============================================

echo "3. Using with type-hinted function:\n";

// First call - fetches from 'database'
$user1 = cacheUserData($cache, 1);

// Second call - retrieves from cache
$user1Again = cacheUserData($cache, 1);

// Different user - fetches from 'database'
$user2 = cacheUserData($cache, 2);

echo "\n";

// ============================================
// 5. Interoperability demo
// ============================================

echo "4. Interoperability:\n";

function processWithAnyCache(CacheInterface $cache): void
{
    $cache->set('test.key', 'test.value', 30);
    
    if ($cache->has('test.key')) {
        echo "✅ Cache implementation works correctly!\n";
        echo "   Value: " . $cache->get('test.key') . "\n";
    }
    
    $cache->delete('test.key');
}

// This function works with ANY PSR-16 implementation
processWithAnyCache($cache);

echo "\n";

// ============================================
// 6. DateInterval TTL support
// ============================================

echo "5. DateInterval TTL (PSR-16 standard):\n";

$intervals = [
    'PT30S' => '30 seconds',
    'PT5M' => '5 minutes',
    'PT1H' => '1 hour',
    'P1D' => '1 day',
];

foreach ($intervals as $interval => $description) {
    $cache->set("ttl.{$interval}", $description, new DateInterval($interval));
    echo "✅ Cached with TTL: {$description}\n";
}

echo "\n";

// ============================================
// 7. Cleanup
// ============================================

echo "6. Cleanup:\n";

// Clear all
$cache->clear();
echo "✅ All cache cleared\n";

// Verify
echo "Cache is empty: " . ($cache->keys() === [] ? 'YES' : 'NO') . "\n";

echo "\n✅ PSR-16 compliance demonstration complete!\n";
