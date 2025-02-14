<?php

require __DIR__ . '/vendor/autoload.php';

use Convert\PhpSdk\Client;

$apiKey = null;
$client = new Client($apiKey);

$experimentKey = "homepage-experiment";
$visitorId = "visitor_123";

echo "Running Experiment:\n";
$variation = $client->runExperiment($experimentKey, $visitorId);
echo "Assigned Variation: " . $variation . "\n";

// Testing event tracking
$client->trackEvent("purchase", ["amount" => 100]);

echo "[Success]: Test completed without errors.\n";
