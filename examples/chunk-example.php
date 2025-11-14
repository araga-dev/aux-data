<?php

declare(strict_types=1);

use Araga\AuxData;

require __DIR__ . '/../vendor/autoload.php';

// Example: processing large datasets with chunk()

$store = AuxData::open(__DIR__ . '/../storage');

// Populate with sample data
echo "Populating database with 250 keys...\n";
$store->transaction(function ($store) {
    for ($i = 1; $i <= 250; $i++) {
        $store->set("item_{$i}", [
            'id' => $i,
            'name' => "Item {$i}",
            'active' => $i % 2 === 0,
        ]);
    }
});

echo "Done.\n\n";

// Process in chunks to avoid memory issues
echo "Processing in chunks of 50:\n";
$totalProcessed = 0;

$store->chunk(50, function (array $items) use (&$totalProcessed) {
    echo "Processing chunk with " . count($items) . " items...\n";
    
    foreach ($items as $key => $value) {
        // Do something with each item
        $totalProcessed++;
    }
});

echo "\nTotal items processed: {$totalProcessed}\n";

// Show statistics
$stats = $store->stats();
echo "\nDatabase statistics:\n";
echo " - Total keys: {$stats['total']}\n";
echo " - Active keys: {$stats['active']}\n";
echo " - Database size: " . number_format($stats['size']) . " bytes\n";

// Clean up using PSR-16 method
echo "\nCleaning up test data...\n";
$store->clear();
echo "Done.\n";
