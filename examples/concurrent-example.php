<?php

declare(strict_types=1);

use Araga\AuxData;

require __DIR__ . '/../vendor/autoload.php';

// Example: atomic increment/decrement for concurrent scenarios

$store = AuxData::open(__DIR__ . '/../storage');

echo "Testing atomic increment/decrement operations:\n\n";

// Initialize counter
$store->set('counter', 0);
echo "Initial value: " . $store->get('counter') . "\n";

// Increment by 1
$value = $store->increment('counter');
echo "After increment(): {$value}\n";

// Increment by 10
$value = $store->increment('counter', 10);
echo "After increment(10): {$value}\n";

// Decrement by 5
$value = $store->decrement('counter', 5);
echo "After decrement(5): {$value}\n";

// Auto-initialize if key doesn't exist
$store->delete('counter');
$value = $store->increment('counter', 100);
echo "After delete() and increment(100): {$value}\n";

// Simulating concurrent increments (in real scenarios, these would be separate processes)
echo "\nSimulating concurrent operations:\n";
$store->set('page_views', 0);

for ($i = 0; $i < 10; $i++) {
    $views = $store->increment('page_views');
    echo "Visitor #{$i}: page_views = {$views}\n";
}

echo "\nFinal page views: " . $store->get('page_views') . "\n";

// Clean up
$store->delete('page_views');
echo "\n✅ Demo complete!\n";
