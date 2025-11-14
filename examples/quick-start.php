<?php

/**
 * Quick Start Example
 * 
 * The simplest possible usage of AuxData - just the basics.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Araga\AuxData;

// 1. Open a storage (creates if doesn't exist)
$cache = AuxData::open(__DIR__ . '/../storage');

// 2. Store some data
$cache->set('greeting', 'Hello, World!');
$cache->set('user', ['name' => 'John', 'age' => 30]);

// 3. Retrieve data
echo $cache->get('greeting') . "\n";
// Output: Hello, World!

$user = $cache->get('user');
echo "User: {$user['name']}, Age: {$user['age']}\n";
// Output: User: John, Age: 30

// 4. Check if key exists
if ($cache->has('greeting')) {
    echo "Greeting exists!\n";
}

// 5. Delete a key (PSR-16 method)
$cache->delete('greeting');

// 6. That's it! The database is automatically saved
echo "\nDone! Database saved at: " . __DIR__ . "/../storage/auxdata.db\n";
