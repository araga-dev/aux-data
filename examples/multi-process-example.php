<?php

declare(strict_types=1);

use Araga\AuxData;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Multi-Process Safety Example
 * 
 * This demonstrates that AuxData is safe for concurrent access
 * across multiple processes on the same machine.
 */

$store = AuxData::open(__DIR__ . '/../storage');

// Reset counter
$store->set('concurrent_counter', 0);

echo "Testing concurrent increment operations...\n";
echo "Starting counter: " . $store->get('concurrent_counter') . "\n\n";

// Simulate multiple processes incrementing the same counter
$processes = 5;
$incrementsPerProcess = 20;

$pids = [];
for ($i = 0; $i < $processes; $i++) {
    $pid = pcntl_fork();
    
    if ($pid == -1) {
        die("Could not fork process\n");
    } elseif ($pid == 0) {
        // Child process
        $childStore = AuxData::open(__DIR__ . '/../storage');
        
        for ($j = 0; $j < $incrementsPerProcess; $j++) {
            $value = $childStore->increment('concurrent_counter');
            usleep(10000); // 10ms delay to increase chance of collision
        }
        
        exit(0);
    } else {
        // Parent process - store child PID
        $pids[] = $pid;
    }
}

// Wait for all child processes to complete
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

// Verify the final count
$finalCount = $store->get('concurrent_counter');
$expectedCount = $processes * $incrementsPerProcess;

echo "Expected count: {$expectedCount}\n";
echo "Actual count: {$finalCount}\n";

if ($finalCount === $expectedCount) {
    echo "✅ SUCCESS: All increments were atomic and safe!\n";
} else {
    echo "❌ FAILURE: Lost updates detected (race condition)\n";
    echo "Difference: " . ($expectedCount - $finalCount) . " lost increments\n";
}

// Show statistics
echo "\nDatabase statistics:\n";
$stats = $store->stats();
foreach ($stats as $key => $value) {
    echo "  {$key}: {$value}\n";
}

// Clean up using PSR-16 method
$store->delete('concurrent_counter');

echo "\nNote: This test requires the pcntl extension (CLI only).\n";
echo "If you see the SUCCESS message, AuxData handled concurrency correctly!\n";
