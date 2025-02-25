<?php
// Enable error reporting for debugging purposes
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../vendor/autoload.php';

use ConvertSdk\ConvertSDK;

// Sample data for configuration
$data = [
    'account_id' => '100414055',
    'project'    => [
        'id' => '100415443'
    ]
];

// Build configuration array. Adjust values as needed.
$config = [
    'sdkKey' => '100414055/100415443', // Replace with your actual SDK key.
    'api'    => [
        'endpoint' => [
            'config' => 'https://cdn-4.convertexperiments.com/api/v1/', // Replace with your config endpoint URL
            'track'  => 'https://100415443.metrics.convertexperiments.com/v1/' // Replace with your track endpoint URL
        ]
    ],
    'data'   => $data
];

// Instantiate the SDK
$sdk = new ConvertSDK($config);

// If your SDK's onReady() or getConfig() returns a promise (like Guzzle promises),
// call wait() to block until the asynchronous operation completes.
// Otherwise, if it's synchronous, you can call the method directly.
try {
    $configData = $sdk->apiManager->getConfig()->wait();
    var_dump($configData);
} catch (\Exception $e) {
    echo "Error fetching configuration: " . $e->getMessage() . "\n";
}
