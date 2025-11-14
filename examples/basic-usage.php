<?php

declare(strict_types=1);

use Araga\AuxData;

require __DIR__ . '/../vendor/autoload.php';

// Basic example: PSR-16 SimpleCache interface

// Open or create the SQLite database in ../storage
$cache = AuxData::open(__DIR__ . '/../storage');

// Set some values
$cache->set('app.name', 'AuxData Demo');
$cache->set('app.env', 'local');

// Get values
$name = $cache->get('app.name', 'Unnamed');
$env  = $cache->get('app.env', 'production');

echo "App name: {$name}\n";
echo "Environment: {$env}\n";

// Check if a key exists (optimized - no JSON decode)
if ($cache->has('app.name')) {
    echo "The key app.name exists.\n";
}

// PSR-16 batch operations
echo "\nBatch operations:\n";

// Set multiple values at once (atomic)
$cache->setMultiple([
    'feature.dark_mode' => true,
    'feature.beta' => false,
    'limits.max_items' => 100,
]);

// Get multiple values at once
$features = $cache->getMultiple(['feature.dark_mode', 'feature.beta', 'missing'], null);
foreach ($features as $key => $value) {
    $valueStr = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    echo " - {$key}: {$valueStr}\n";
}

// Atomic increment example
$views = $cache->increment('page.views', 5);
echo "\nPage views: {$views}\n";

// Transaction example
$result = $cache->transaction(function ($cache) {
    $cache->set('setting1', 'value1');
    $cache->set('setting2', 'value2');
    return 'committed';
});

echo "Transaction result: {$result}\n";

// List all keys
echo "\nAll keys:\n";
foreach ($cache->keys() as $key) {
    echo " - {$key}\n";
}

// Show statistics
$stats = $cache->stats();
echo "\nDatabase statistics:\n";
echo " - Total keys: {$stats['total']}\n";
echo " - Active keys: {$stats['active']}\n";
echo " - Expired keys: {$stats['expired']}\n";
echo " - Database size: " . number_format($stats['size']) . " bytes\n";

// Clean expired keys
$deleted = $cache->cleanExpired();
echo "\nCleaned {$deleted} expired keys.\n";

// Clear all (PSR-16 method)
// $cache->clear();
