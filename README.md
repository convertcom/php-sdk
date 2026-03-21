# Convert PHP SDK

The official PHP SDK for [Convert Experiences](https://www.convert.com/) — a server-side A/B testing and feature flagging platform.

Bucket visitors into experiment variations, resolve feature flags with typed variables, track goal conversions, and report revenue — all with deterministic, cross-SDK parity with the Convert JavaScript SDK.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Visitor Context](#visitor-context)
- [Experience Bucketing](#experience-bucketing)
- [Feature Flags](#feature-flags)
- [Conversion Tracking](#conversion-tracking)
- [Revenue Reporting](#revenue-reporting)
- [Force Multiple Transactions](#force-multiple-transactions)
- [Flushing Events](#flushing-events)
- [Event System](#event-system)
- [Logging](#logging)
- [Return Types](#return-types)
- [Testing](#testing)
- [License](#license)

## Requirements

- PHP 8.2, 8.3, or 8.4
- A PSR-18 HTTP client (e.g., `guzzlehttp/guzzle ^7`)
- A [Convert Experiences](https://www.convert.com/) account with an SDK key

The SDK auto-discovers your PSR-18 client via [`php-http/discovery`](https://github.com/php-http/discovery). Install any compliant client — no adapter code needed.

## Installation

```bash
composer require convertcom/php-sdk
```

This installs the SDK and all internal packages. The only external runtime dependencies are:

- `psr/log ^3.0` (PSR-3 logging interface)
- `psr/simple-cache ^3.0` (PSR-16 caching interface)
- `php-http/discovery ^1.19` (auto-discovers your HTTP client)

## Quick Start

```php
<?php

declare(strict_types=1);

use ConvertSdk\ConvertSDK;
use ConvertSdk\DTO\ConversionAttributes;
use ConvertSdk\DTO\GoalData;
use ConvertSdk\Enums\GoalDataKey;

// 1. Initialize the SDK
$sdk = ConvertSDK::create([
    'sdkKey' => 'your-sdk-key',
]);

// 2. Create a visitor context
$context = $sdk->createContext('visitor-123', [
    'country' => 'US',
    'plan'    => 'premium',
]);

// 3. Run an experience
$variation = $context->runExperience('homepage-redesign');

if ($variation !== null) {
    echo "Variation: {$variation->variationKey}\n";
}

// 4. Resolve a feature flag
$feature = $context->runFeature('dark-mode');

if ($feature !== null && $feature->status->value === 'enabled') {
    $theme = $feature->variables['theme'] ?? 'dark';
}

// 5. Track a conversion with revenue
$context->trackConversion('purchase-completed', new ConversionAttributes(
    conversionData: [
        new GoalData(GoalDataKey::Amount, 49.99),
        new GoalData(GoalDataKey::TransactionId, 'txn-abc-123'),
    ],
));

// Events auto-flush on shutdown in PHP-FPM, or flush manually:
$sdk->flush();
```

## Configuration

### Initialize with SDK key (remote config fetch)

```php
$sdk = ConvertSDK::create([
    'sdkKey' => 'your-sdk-key',
]);
```

The SDK fetches project configuration from the Convert CDN on initialization. The config is cached using a PSR-16 cache (defaults to an in-memory array cache).

### Initialize with direct config data

```php
$sdk = ConvertSDK::create([
    'data' => [
        'account_id' => '100123456',
        'project' => [
            'id' => '10045678',
            // ... full project config
        ],
    ],
]);
```

Pass a config array (or `ConfigResponseData` object) directly to skip the HTTP fetch. Useful for testing or when you manage config distribution yourself.

### Inject a PSR-3 logger

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('convert');
$logger->pushHandler(new StreamHandler('php://stderr'));

$sdk = ConvertSDK::create([
    'sdkKey' => 'your-sdk-key',
    'logger' => $logger,
]);
```

Pass any PSR-3 `LoggerInterface`. When omitted, a `NullLogger` is used (no output).

### Inject a PSR-16 cache

```php
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Cache\Adapter\RedisAdapter;

$cache = new Psr16Cache(RedisAdapter::createConnection('redis://localhost'));

$sdk = ConvertSDK::create([
    'sdkKey' => 'your-sdk-key',
    'cache'  => $cache,
]);
```

Pass any PSR-16 `CacheInterface`. When omitted, an in-memory `ArrayCache` is used (no persistence between requests).

### Full configuration options

```php
$sdk = ConvertSDK::create([
    'sdkKey'              => 'your-sdk-key',     // SDK key for remote config
    'data'                => [...],               // Direct config data (alternative to sdkKey)
    'logger'              => $logger,             // PSR-3 LoggerInterface
    'cache'               => $cache,              // PSR-16 CacheInterface
    'dataRefreshInterval' => 300000,              // Config cache TTL in milliseconds (default: 300000 = 5 min)
    'environment'         => 'production',        // Environment targeting
]);
```

You must provide either `sdkKey` or `data`. If both are missing, an `InvalidArgumentException` is thrown.

## Visitor Context

Create a context for each visitor. The context holds visitor attributes and provides the API for bucketing, feature flags, and conversion tracking.

```php
$context = $sdk->createContext('visitor-123', [
    'country' => 'US',
    'plan'    => 'premium',
    'age'     => 30,
]);
```

**Parameters:**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `$visitorId` | `string` | Yes | Unique visitor identifier. Must not be empty. |
| `$visitorAttributes` | `array<string, mixed>\|null` | No | Key-value pairs for audience targeting. |

**Returns:** `ContextInterface|null` — `null` if the SDK is not initialized.

### Update attributes after creation

```php
// Set a single attribute
$context->setAttribute('plan', 'enterprise');

// Set multiple attributes (merges with existing)
$context->setAttributes(['country' => 'UK', 'device' => 'mobile']);

// Read current attributes
$attributes = $context->getAttributes();
```

## Experience Bucketing

### Run a single experience

```php
$variation = $context->runExperience('homepage-redesign');

if ($variation !== null) {
    echo "Experience: {$variation->experienceKey}\n";
    echo "Variation:  {$variation->variationKey}\n";
    echo "Changes:    " . json_encode($variation->changes) . "\n";
}
```

**Returns:** `BucketedVariation|null` — `null` if the visitor does not qualify (audience/location rules, traffic allocation) or the experience key is not found.

### Run all experiences

```php
$variations = $context->runExperiences();

foreach ($variations as $variation) {
    echo "{$variation->experienceKey} => {$variation->variationKey}\n";
}
```

**Returns:** `BucketedVariation[]` — an array of all variations the visitor qualifies for.

### Location-scoped bucketing

Pass `BucketingAttributes` to scope bucketing to a specific location:

```php
use OpenAPI\Client\BucketingAttributes;

$variation = $context->runExperience('checkout-flow', new BucketingAttributes([
    'locationProperties' => ['page' => '/checkout'],
]));
```

## Feature Flags

### Resolve a single feature

```php
use ConvertSdk\Enums\FeatureStatus;

$feature = $context->runFeature('dark-mode');

if ($feature !== null && $feature->status === FeatureStatus::Enabled) {
    $theme     = $feature->variables['theme'] ?? 'dark';
    $intensity = $feature->variables['intensity'] ?? 80;
    echo "Dark mode: theme={$theme}, intensity={$intensity}\n";
}
```

**Returns:** `BucketedFeature|null` — `null` if the feature key is not found or the visitor does not qualify.

### Resolve all features

```php
$features = $context->runFeatures();

foreach ($features as $feature) {
    echo "{$feature->featureKey}: {$feature->status->value}\n";
    foreach ($feature->variables as $key => $value) {
        echo "  {$key} = {$value}\n";
    }
}
```

**Returns:** `BucketedFeature[]` — an array of all resolved features.

## Conversion Tracking

Track a goal conversion for the current visitor:

```php
$result = $context->trackConversion('signup-completed');
```

**Parameters:**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `$goalKey` | `string` | Yes | The goal key defined in your Convert project. |
| `$attributes` | `ConversionAttributes\|null` | No | Optional conversion data, rule data, and settings. |

**Returns:** `RuleError|bool|null`

| Return Value | Meaning |
|---|---|
| `null` | Conversion tracked successfully. |
| `false` | Goal key not found in project config, or goal rules did not match. |
| `RuleError` | Rule evaluation error (e.g., missing data). |

### Deduplication

By default, each goal fires **once per visitor**. Calling `trackConversion()` a second time for the same visitor and goal is a no-op. See [Force Multiple Transactions](#force-multiple-transactions) to override this.

### Goal rule matching

If a goal has targeting rules, pass `ruleData` to evaluate them:

```php
use ConvertSdk\DTO\ConversionAttributes;

$context->trackConversion('checkout-goal', new ConversionAttributes(
    ruleData: ['page_type' => 'checkout', 'cart_value' => 100],
));
```

The conversion only fires if the rules match.

## Revenue Reporting

Track revenue by passing `GoalData` entries with your conversion:

```php
use ConvertSdk\DTO\ConversionAttributes;
use ConvertSdk\DTO\GoalData;
use ConvertSdk\Enums\GoalDataKey;

$context->trackConversion('purchase-completed', new ConversionAttributes(
    conversionData: [
        new GoalData(GoalDataKey::Amount, 99.99),
        new GoalData(GoalDataKey::ProductsCount, 3),
        new GoalData(GoalDataKey::TransactionId, 'txn-abc-123'),
    ],
));
```

When `conversionData` is present, the SDK sends **two events**: a conversion event and a transaction event (with the goal data). This matches the JS SDK behavior.

### Available GoalDataKey values

| Key | Backed Value | Type |
|---|---|---|
| `GoalDataKey::Amount` | `'amount'` | `int\|float` |
| `GoalDataKey::ProductsCount` | `'productsCount'` | `int` |
| `GoalDataKey::TransactionId` | `'transactionId'` | `string` |
| `GoalDataKey::CustomDimension1` | `'customDimension1'` | `int\|float\|string` |
| `GoalDataKey::CustomDimension2` | `'customDimension2'` | `int\|float\|string` |
| `GoalDataKey::CustomDimension3` | `'customDimension3'` | `int\|float\|string` |
| `GoalDataKey::CustomDimension4` | `'customDimension4'` | `int\|float\|string` |
| `GoalDataKey::CustomDimension5` | `'customDimension5'` | `int\|float\|string` |

## Force Multiple Transactions

By default, goal deduplication prevents the same goal from firing twice for one visitor. For recurring transactions (e.g., subscription renewals), override deduplication:

```php
use ConvertSdk\DTO\ConversionAttributes;
use ConvertSdk\DTO\GoalData;
use ConvertSdk\Enums\GoalDataKey;
use ConvertSdk\Enums\ConversionSettingKey;

$context->trackConversion('subscription-renewal', new ConversionAttributes(
    conversionData: [
        new GoalData(GoalDataKey::Amount, 29.99),
        new GoalData(GoalDataKey::TransactionId, 'renewal-456'),
    ],
    conversionSetting: [
        ConversionSettingKey::ForceMultipleTransactions->value => true,
    ],
));
```

### Behavior matrix

| Scenario | Conversion Event | Transaction Event |
|---|---|---|
| First trigger, no goal data | Sent | Not sent |
| First trigger, with goal data | Sent | Sent |
| Repeat trigger, no force | Not sent | Not sent |
| Repeat trigger, force=true, no goal data | Not sent | Not sent |
| Repeat trigger, force=true, with goal data | Not sent | Sent |

## Flushing Events

The SDK batches tracking events and posts them to the Convert Tracking API. Events auto-flush in three ways:

1. **Batch size threshold** — when 10 events accumulate (default).
2. **PHP-FPM shutdown** — `register_shutdown_function` calls `fastcgi_finish_request()` (releases the HTTP response first), then flushes the queue.
3. **Manual flush** — call `flush()` explicitly.

```php
// Flush all queued events across all contexts
$sdk->flush();

// Flush events for a specific context
$context->releaseQueues();
```

Failed POST requests are retried up to 2 times with exponential backoff (100ms, 300ms). HTTP 4xx errors are not retried.

## Event System

Subscribe to SDK lifecycle events:

```php
use ConvertSdk\Enums\SystemEvents;

// SDK ready (fires once, deferred — if you subscribe after init, you still get it)
$sdk->on(SystemEvents::Ready->value, function (mixed $args, mixed $err): void {
    if ($err !== null) {
        echo "SDK init failed: {$err->getMessage()}\n";
        return;
    }
    echo "SDK ready\n";
});

// Bucketing event
$sdk->on(SystemEvents::Bucketing->value, function (mixed $args): void {
    echo "Visitor bucketed\n";
});

// Conversion tracked
$sdk->on(SystemEvents::Conversion->value, function (mixed $args): void {
    echo "Conversion tracked\n";
});

// API queue released (success or failure)
$sdk->on(SystemEvents::ApiQueueReleased->value, function (mixed $args): void {
    echo "Events posted to tracking API\n";
});
```

### Available events

| Event | Fired When |
|---|---|
| `SystemEvents::Ready` | SDK initialization completes (success or failure). Deferred — late subscribers still receive it. |
| `SystemEvents::ConfigUpdated` | Config is refreshed after initial load. |
| `SystemEvents::Bucketing` | A visitor is bucketed into an experience variation. |
| `SystemEvents::Conversion` | A goal conversion is tracked. |
| `SystemEvents::ApiQueueReleased` | The event queue is flushed to the Tracking API. |
| `SystemEvents::Segments` | Segments are evaluated. |
| `SystemEvents::LocationActivated` | A location rule matches. |
| `SystemEvents::LocationDeactivated` | A location rule stops matching. |
| `SystemEvents::Audiences` | Audience rules are evaluated. |

## Logging

The SDK uses PSR-3 logging. Pass any `LoggerInterface` at initialization:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('convert-sdk');
$logger->pushHandler(new StreamHandler('php://stderr', \Monolog\Level::Debug));

$sdk = ConvertSDK::create([
    'sdkKey' => 'your-sdk-key',
    'logger' => $logger,
]);
```

When no logger is provided, the SDK uses `Psr\Log\NullLogger` (silent).

The SDK logs at these levels:

| Level | What is logged |
|---|---|
| `trace` | Internal method calls, config data, initialization steps |
| `debug` | Event firing, entity lookups, bucketing internals |
| `warn` | Failed HTTP requests, retry attempts, discarded batches |
| `error` | Initialization failures, config fetch errors, invalid config |

## Return Types

### BucketedVariation

Returned by `runExperience()` and `runExperiences()`.

```php
readonly class BucketedVariation
{
    public string $experienceId;
    public string $experienceKey;
    public string $variationId;
    public string $variationKey;
    public array  $changes;       // Variation changes (DOM mutations, redirects, etc.)
}
```

### BucketedFeature

Returned by `runFeature()` and `runFeatures()`.

```php
readonly class BucketedFeature
{
    public string        $featureId;
    public string        $featureKey;
    public FeatureStatus $status;     // FeatureStatus::Enabled or FeatureStatus::Disabled
    public array         $variables;  // Resolved feature variables (key => value)
}
```

### ConversionAttributes

Passed to `trackConversion()`.

```php
readonly class ConversionAttributes
{
    public ?array $ruleData;           // Key-value pairs for goal rule matching
    public ?array $conversionData;     // Array of GoalData entries
    public ?array $conversionSetting;  // Behavior overrides (e.g., forceMultipleTransactions)
}
```

### GoalData

Individual revenue/goal data entry.

```php
readonly class GoalData
{
    public GoalDataKey     $key;    // GoalDataKey enum (Amount, TransactionId, etc.)
    public int|float|string $value;
}
```

## Testing

Run the full test suite from the repository root:

```bash
# All tests
composer test

# Unit tests only
composer test:unit

# Cross-SDK parity tests
composer test:cross-sdk

# Integration tests
composer test:integration

# Coverage report (requires PCOV)
composer test:coverage
```

Static analysis and code style:

```bash
# PHPStan (level 6)
composer analyze

# PHP-CS-Fixer (PSR-12)
composer cs-check

# Fix code style
composer cs-fix
```

## License

Apache-2.0 — see [LICENSE](LICENSE) for details.
