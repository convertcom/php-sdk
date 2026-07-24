<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\Support;

// Not PSR-4/classmap autoloadable — same constraint as MutualExclusionFixture.php
// (composer.json:52 maps `ConvertSdk\Tests\` only to packages/Utils/tests/, and
// PHPUnit's directory collector only requires `*Test.php`). Consumers MUST
// `require_once` this file's absolute path before referencing any class below.
require_once __DIR__ . '/../MutualExclusionFixture.php';

use ConvertSdk\ApiManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\DataManager;
use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\RuleType;
use ConvertSdk\Event\EventManager;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\BucketingManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Interfaces\LogMethodMapInterface;
use ConvertSdk\Interfaces\RuleManagerInterface;
use ConvertSdk\RuleManager;
use ConvertSdk\Tests\MutualExclusionFixture;
use ConvertSdk\Utils\ObjectUtils;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigAudienceTypes;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\GenericListMatchingOptions;
use OpenAPI\Client\Model\RuleElement;
use OpenAPI\Client\Model\RuleObject;
use OpenAPI\Client\Model\VisitorSegments;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use Psr\Log\AbstractLogger;

/**
 * qs-03 (mutual-exclusion audience rule) test doubles + fixtures shared across
 * every PHP-3 (RED phase) test file. All classes are plain decorators around
 * the REAL production collaborators — they never fake the algorithm under
 * test, they only observe it, so a passing assertion always reflects genuine
 * production behavior.
 */

/**
 * Records every isRuleMatched() invocation while delegating to a real
 * RuleManagerInterface. Used to prove the audience-evaluation seam was
 * actually entered (not gate-skipped) — see MutualExclusionRuleResolutionTest.
 */
final class SpyRuleManager implements RuleManagerInterface
{
    public int $isRuleMatchedCallCount = 0;

    /** @var array<int, array{data: array<string, mixed>, ruleSet: RuleObject, logEntry: ?string, result: bool|RuleError}> */
    public array $calls = [];

    public function __construct(private readonly RuleManagerInterface $real)
    {
    }

    public function getComparisonProcessorMethods(): array
    {
        return $this->real->getComparisonProcessorMethods();
    }

    public function isRuleMatched(array $data, RuleObject $ruleSet, ?string $logEntry = null): bool|RuleError
    {
        $this->isRuleMatchedCallCount++;
        $result = $this->real->isRuleMatched($data, $ruleSet, $logEntry);
        $this->calls[] = ['data' => $data, 'ruleSet' => $ruleSet, 'logEntry' => $logEntry, 'result' => $result];
        return $result;
    }

    public function isValidRule(RuleElement $rule): bool
    {
        return $this->real->isValidRule($rule);
    }

    /**
     * Returns the `result` of the LAST recorded isRuleMatched() call whose
     * `logEntry` contains $needle (e.g. an audience id), or null if none
     * matched. Lets a test pin one specific audience's resolution
     * independently of the overall ALL/ANY aggregate (see
     * MutualExclusionCombinationTest).
     */
    public function lastResultForLogEntryContaining(string $needle): bool|RuleError|null
    {
        for ($i = count($this->calls) - 1; $i >= 0; $i--) {
            if (($this->calls[$i]['logEntry'] ?? null) !== null && str_contains($this->calls[$i]['logEntry'], $needle)) {
                return $this->calls[$i]['result'];
            }
        }
        return null;
    }
}

/**
 * Records every getBucketForVisitor()/getBucketForVisitorAnchored() call's
 * `experienceId` option while delegating to a real BucketingManagerInterface.
 * Used by AC5 to prove the mutual-exclusion check never buckets its target.
 */
final class SpyBucketingManager implements BucketingManagerInterface
{
    /** @var array<int, string|null> experienceId option per bucketing call */
    public array $bucketedExperienceIds = [];

    public function __construct(private readonly BucketingManagerInterface $real)
    {
    }

    public function selectBucket(array $buckets, float $value, float $redistribute = 0.0): ?string
    {
        return $this->real->selectBucket($buckets, $value, $redistribute);
    }

    public function getValueVisitorBased(string $visitorId, ?array $options = null): int
    {
        return $this->real->getValueVisitorBased($visitorId, $options);
    }

    public function getBucketForVisitor(array $buckets, string $visitorId, ?array $options = null): ?array
    {
        $this->bucketedExperienceIds[] = $options['experienceId'] ?? null;
        return $this->real->getBucketForVisitor($buckets, $visitorId, $options);
    }

    public function getBucketRanges(array $allocations): array
    {
        return $this->real->getBucketRanges($allocations);
    }

    public function selectBucketAnchored(array $ranges, float $value): ?string
    {
        return $this->real->selectBucketAnchored($ranges, $value);
    }

    public function getBucketForVisitorAnchored(array $allocations, string $visitorId, ?array $options = null): ?array
    {
        $this->bucketedExperienceIds[] = $options['experienceId'] ?? null;
        return $this->real->getBucketForVisitorAnchored($allocations, $visitorId, $options);
    }

    public function resetSpy(): void
    {
        $this->bucketedExperienceIds = [];
    }
}

/**
 * Records every enqueue() call's visitorId while delegating to a real
 * ApiManagerInterface. Used by AC5 to prove the mutual-exclusion check never
 * fires a tracking event.
 */
final class SpyApiManager implements ApiManagerInterface
{
    /** @var array<int, string> */
    public array $enqueuedVisitorIds = [];

    public function __construct(private readonly ApiManagerInterface $real)
    {
    }

    public function request(string $method, array $path, array $data = [], array $headers = []): array
    {
        return $this->real->request($method, $path, $data, $headers);
    }

    public function enqueue(string $visitorId, VisitorTrackingEvents $eventRequest, ?VisitorSegments $segments = null): void
    {
        $this->enqueuedVisitorIds[] = $visitorId;
        $this->real->enqueue($visitorId, $eventRequest, $segments);
    }

    public function releaseQueue(?string $reason = null): void
    {
        $this->real->releaseQueue($reason);
    }

    public function enableTracking(): void
    {
        $this->real->enableTracking();
    }

    public function disableTracking(): void
    {
        $this->real->disableTracking();
    }

    public function setData(ConfigResponseData $data): void
    {
        $this->real->setData($data);
    }

    public function getConfig(): ConfigResponseData
    {
        return $this->real->getConfig();
    }

    public function getConfigForExperience(string $experienceId): ConfigResponseData
    {
        return $this->real->getConfigForExperience($experienceId);
    }

    public function resetSpy(): void
    {
        $this->enqueuedVisitorIds = [];
    }
}

/**
 * Records every warn() call (and every call at any level) while satisfying
 * LogManagerInterface. Used by AC8 (unknown-target warning) and AC5 (no
 * side-effecting log path is exercised as a proxy is unnecessary — kept
 * intentionally minimal, no delegation needed since assertions only read
 * recorded calls).
 */
final class SpyLogManager implements LogManagerInterface
{
    /** @var array<int, array<int, mixed>> */
    public array $warnCalls = [];

    /** @var array<int, array{level: string, args: array<int, mixed>}> */
    public array $allCalls = [];

    public function log(LogLevel $level, mixed ...$args): void
    {
        $this->allCalls[] = ['level' => $level->value, 'args' => $args];
    }

    public function trace(mixed ...$args): void
    {
        $this->allCalls[] = ['level' => 'trace', 'args' => $args];
    }

    public function debug(mixed ...$args): void
    {
        $this->allCalls[] = ['level' => 'debug', 'args' => $args];
    }

    public function info(mixed ...$args): void
    {
        $this->allCalls[] = ['level' => 'info', 'args' => $args];
    }

    public function warn(mixed ...$args): void
    {
        $this->warnCalls[] = $args;
        $this->allCalls[] = ['level' => 'warn', 'args' => $args];
    }

    public function error(mixed ...$args): void
    {
        $this->allCalls[] = ['level' => 'error', 'args' => $args];
    }

    public function addClient(mixed $client = null, ?LogLevel $level = null, ?LogMethodMapInterface $methodMap = null): void
    {
        // No-op: this spy never fans out to a real client, only records.
    }

    public function setClientLevel(LogLevel $level, mixed $client = null): void
    {
        // No-op: see addClient().
    }

    /** True iff any warn() call contains a string argument (recursively) containing $needle. */
    public function hasWarnContaining(string $needle): bool
    {
        foreach ($this->warnCalls as $args) {
            if ($this->argsContain($args, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function argsContain(mixed $value, string $needle): bool
    {
        if (is_string($value)) {
            return str_contains($value, $needle);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->argsContain($item, $needle)) {
                    return true;
                }
            }
        }
        return false;
    }
}

/**
 * PSR-3 logger capturing every message, for use as a `logger.customLoggers`
 * entry via the PUBLIC ConvertSDK::create() config (`'logger' =>
 * ['logLevel' => LogLevel::Trace, 'customLoggers' => [$capture]]`).
 *
 * Used by integration tests that only have the public Context/ConvertSDK API
 * available (no internal RuleManager DI seam like MutualExclusionDataManagerFactory
 * provides at the DataManager-unit level) to prove that DataManager's
 * audience-evaluation path (filterMatchedRecordsWithRule(), which LogManager
 * traces unconditionally on every call — DataManager.php:1363) was genuinely
 * entered, rather than skipped by the AC4 line-410 gate. LogManager
 * concatenates every trace() arg into a single string and forwards it to a
 * PSR-3 client's debug() method (trace maps to 'debug' in
 * LogManager::$_monologMapping) — see LogManager.php:_log().
 */
final class MutualExclusionLogCapture extends AbstractLogger
{
    /** @var array<int, string> */
    public array $messages = [];

    public function log($level, $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function hasMessageContaining(string $needle): bool
    {
        foreach ($this->messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }
}

/**
 * Minimal PSR-16-shaped (get/set) persistent-store double. Distinct from
 * DataManager's in-memory `_bucketedVisitors` — writing directly to this
 * double (bypassing DataManager::putData()) is how AC3/row 8 places a
 * decision ONLY in the persistent layer, never in memory.
 */
final class MutualExclusionDataStoreDouble
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var array<int, string> keys passed to set() */
    public array $setCalls = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->setCalls[] = $key;
        $this->store[$key] = $value;
    }

    public function resetSpy(): void
    {
        $this->setCalls = [];
    }
}

/**
 * Builds config-data fragments (audiences + a synthetic experience) that
 * carry a `bucketed_into_experience_key` rule, layered on top of the shared
 * mutual-exclusion-config.json fixture. Never mutates the JSON file itself —
 * every method returns a new in-memory `data` array.
 */
final class MutualExclusionAudienceBuilder
{
    /** Synthetic third experience — isolated from exp-a/exp-b (AC1/AC4/AC8/AC5). */
    public const UNDER_TEST_EXPERIENCE_ID = '100333';
    public const UNDER_TEST_EXPERIENCE_KEY = 'exp-under-test';
    public const UNDER_TEST_VARIATION_ID = '100903';

    public const EXCLUSION_AUDIENCE_ID = '100777';
    public const EXCLUSION_AUDIENCE_KEY = 'mx-exclusion-audience';
    public const GENERIC_AUDIENCE_ID = '100778';
    public const GENERIC_AUDIENCE_KEY = 'mx-generic-audience';

    /** @return array<string, mixed> the decoded `data` object of mutual-exclusion-config.json */
    public static function loadBaseConfigData(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(__DIR__ . '/../mutual-exclusion-config.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        return $decoded['data'];
    }

    /** @return array{rule_type: string, matching: array{match_type: string, negated: bool}, value: string} */
    public static function exclusionRuleElement(string $targetExperienceKey, bool $negated): array
    {
        return [
            'rule_type' => RuleType::BucketedIntoExperienceKey->value,
            'matching' => ['match_type' => 'equals', 'negated' => $negated],
            'value' => $targetExperienceKey,
        ];
    }

    /** @return array{rule_type: string, matching: array{match_type: string, negated: bool}, value: string, key: string} */
    public static function genericRuleElement(string $key, string $value, bool $negated = false): array
    {
        return [
            'rule_type' => 'generic_key_value',
            'matching' => ['match_type' => 'matches', 'negated' => $negated],
            'value' => $value,
            'key' => $key,
        ];
    }

    /** @param array<string, mixed> $ruleElement */
    private static function singleRuleAudience(string $id, string $key, array $ruleElement): array
    {
        return [
            'id' => $id,
            'name' => $key,
            'type' => ConfigAudienceTypes::TRANSIENT,
            'status' => 'active',
            'key' => $key,
            'preset' => false,
            'rules' => [
                'OR' => [
                    ['AND' => [
                        ['OR_WHEN' => [$ruleElement]],
                    ]],
                ],
            ],
        ];
    }

    public static function exclusionOnlyAudience(string $targetExperienceKey, bool $negated): array
    {
        return self::singleRuleAudience(
            self::EXCLUSION_AUDIENCE_ID,
            self::EXCLUSION_AUDIENCE_KEY,
            self::exclusionRuleElement($targetExperienceKey, $negated)
        );
    }

    public static function genericOnlyAudience(string $key, string $value, bool $negated = false): array
    {
        return self::singleRuleAudience(
            self::GENERIC_AUDIENCE_ID,
            self::GENERIC_AUDIENCE_KEY,
            self::genericRuleElement($key, $value, $negated)
        );
    }

    /**
     * Shared shape for the synthetic "exp-under-test" experience, parameterized
     * only by which audience id(s) it carries. Used by both
     * withUnderTestExperience() (exclusion-rule audience) and
     * withGenericOnlyUnderTestExperience() (AC7 regression lock — generic-only
     * audience, no exclusion rule anywhere in the tree) so the two stay in
     * lockstep and avoid duplicating this ~15-line fixture shape.
     *
     * @param array<int, string> $audienceIds
     * @return array<string, mixed>
     */
    private static function underTestExperienceShape(array $audienceIds): array
    {
        return [
            'id' => self::UNDER_TEST_EXPERIENCE_ID,
            'name' => 'Mutual Exclusion Under Test',
            'key' => self::UNDER_TEST_EXPERIENCE_KEY,
            'type' => 'a/b_fullstack',
            'version' => 6,
            'status' => 'active',
            'environments' => ['live', 'staging'],
            'audiences' => $audienceIds,
            'settings' => ['matching_options' => ['audiences' => GenericListMatchingOptions::ALL]],
            'variations' => [[
                'id' => self::UNDER_TEST_VARIATION_ID,
                'name' => 'Original',
                'status' => 'running',
                'is_baseline' => true,
                'changes' => [],
                'key' => self::UNDER_TEST_VARIATION_ID . '-original',
                'traffic_allocation' => 100.0,
            ]],
        ];
    }

    /**
     * AC1/AC4/AC8/AC5: appends a synthetic "exp-under-test" experience whose
     * SOLE audience is the exclusion rule — isolated from exp-a/exp-b so the
     * fixture's bucketing-map rows never interact with exp-under-test's own
     * bucketing state.
     *
     * @param array<string, mixed> $configData
     * @return array<string, mixed>
     */
    public static function withUnderTestExperience(array $configData, string $ruleValue, bool $negated): array
    {
        $configData['audiences'][] = self::exclusionOnlyAudience($ruleValue, $negated);
        $configData['experiences'][] = self::underTestExperienceShape([self::EXCLUSION_AUDIENCE_ID]);
        return $configData;
    }

    /**
     * AC7 (generic-rule regression lock): appends the SAME synthetic
     * "exp-under-test" experience shape, but with a SOLE audience that carries
     * only a generic key/value rule — no bucketed_into_experience_key rule
     * anywhere in the tree. Used to lock that the qs-03 gate widening at
     * DataManager.php:410-425 (`$visitorProperties || $hasBucketingExclusionAudience`)
     * stays scoped strictly to exclusion audiences: a generic-only audience
     * evaluated with empty visitorProperties must still resolve to null, exactly
     * as the pre-qs-03 `if ($visitorProperties)` gate did.
     *
     * @param array<string, mixed> $configData
     * @return array<string, mixed>
     */
    public static function withGenericOnlyUnderTestExperience(array $configData, string $key, string $value): array
    {
        $configData['audiences'][] = self::genericOnlyAudience($key, $value);
        $configData['experiences'][] = self::underTestExperienceShape([self::GENERIC_AUDIENCE_ID]);
        return $configData;
    }

    /**
     * AC2/AC3/AC4: attaches the negated exclusion audience (targeting exp-a)
     * directly onto the real exp-b, with `matching_options.audiences = all`.
     *
     * @param array<string, mixed> $configData
     * @return array<string, mixed>
     */
    public static function withExclusionOnExperienceB(array $configData, bool $negated = true): array
    {
        $configData['audiences'][] = self::exclusionOnlyAudience(MutualExclusionFixture::EXPERIENCE_A_KEY, $negated);
        foreach ($configData['experiences'] as &$experience) {
            if ($experience['key'] === MutualExclusionFixture::EXPERIENCE_B_KEY) {
                $experience['audiences'] = [self::EXCLUSION_AUDIENCE_ID];
                $experience['settings'] = ['matching_options' => ['audiences' => GenericListMatchingOptions::ALL]];
            }
        }
        unset($experience);
        return $configData;
    }

    /**
     * AC6: attaches BOTH a generic key/value audience and the exclusion
     * audience onto the real exp-b, with a configurable
     * `matching_options.audiences` (all/any).
     *
     * @param array<string, mixed> $configData
     * @return array<string, mixed>
     */
    public static function withCombinedAudiencesOnExperienceB(
        array $configData,
        string $matchingOption,
        string $genericKey,
        string $genericValue,
        bool $exclusionNegated = true
    ): array {
        $configData['audiences'][] = self::genericOnlyAudience($genericKey, $genericValue);
        $configData['audiences'][] = self::exclusionOnlyAudience(MutualExclusionFixture::EXPERIENCE_A_KEY, $exclusionNegated);
        foreach ($configData['experiences'] as &$experience) {
            if ($experience['key'] === MutualExclusionFixture::EXPERIENCE_B_KEY) {
                $experience['audiences'] = [self::GENERIC_AUDIENCE_ID, self::EXCLUSION_AUDIENCE_ID];
                $experience['settings'] = ['matching_options' => ['audiences' => $matchingOption]];
            }
        }
        unset($experience);
        return $configData;
    }
}

/**
 * Wires a fully-functional DataManager (config + real BucketingManager +
 * spy-wrapped RuleManager/ApiManager/BucketingManager + SpyLogManager) over
 * a given `data` array, mirroring DataManagerTest's setUp() so PHP-3 tests
 * don't duplicate that ~30-line wiring block per file (SonarCloud
 * duplication gate — see .claude/rules/sonarqube-new-code-duplication.md).
 */
final class MutualExclusionDataManagerFactory
{
    /**
     * @param array<string, mixed> $data The `data` array (post-builder augmentation)
     * @param array{
     *   dataStore?: MutualExclusionDataStoreDouble,
     *   ruleManager?: RuleManagerInterface,
     *   bucketingManager?: BucketingManagerInterface,
     *   apiManager?: ApiManagerInterface,
     *   logManager?: LogManagerInterface,
     * } $overrides
     * @return array{
     *   dataManager: DataManager,
     *   ruleManager: SpyRuleManager|RuleManagerInterface,
     *   bucketingManager: SpyBucketingManager|BucketingManagerInterface,
     *   apiManager: SpyApiManager|ApiManagerInterface,
     *   logManager: SpyLogManager|LogManagerInterface,
     * }
     */
    public static function build(array $data, array $overrides = []): array
    {
        $baseConfig = json_decode(
            (string) file_get_contents(__DIR__ . '/../mutual-exclusion-config.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $defaultConfig = DefaultConfig::getDefault();
        $mergedConfig = ObjectUtils::objectDeepMerge($baseConfig, $defaultConfig, [
            'api' => [
                'endpoint' => [
                    'config' => 'http://localhost:8099',
                    'track' => 'http://localhost:8099',
                ],
            ],
        ]);
        $mergedConfig['data'] = new ConfigResponseData($data);
        unset($mergedConfig['sdkKey']);
        $config = new Config($mergedConfig);

        $mockHttpClient = new MockHttpClient();
        $psr17Factory = new Psr17Factory();

        $bucketingConfig = $config->getBucketing();
        $realBucketingManager = new BucketingManager(
            maxTraffic: $bucketingConfig['max_traffic'] ?? 10000,
            hashSeed: $bucketingConfig['hash_seed'] ?? 9999,
        );
        $bucketingManager = $overrides['bucketingManager'] ?? new SpyBucketingManager($realBucketingManager);

        $realRuleManager = new RuleManager();
        $ruleManager = $overrides['ruleManager'] ?? new SpyRuleManager($realRuleManager);

        $eventManager = new EventManager();

        $realApiManager = new ApiManager($config, $eventManager, null, $mockHttpClient, $psr17Factory, $psr17Factory);
        $apiManager = $overrides['apiManager'] ?? new SpyApiManager($realApiManager);

        $logManager = $overrides['logManager'] ?? new SpyLogManager();

        $dataManager = new DataManager($config, $bucketingManager, $ruleManager, $eventManager, $apiManager, $logManager);

        if (isset($overrides['dataStore'])) {
            $dataManager->setDataStore($overrides['dataStore']);
        }

        return [
            'dataManager' => $dataManager,
            'ruleManager' => $ruleManager,
            'bucketingManager' => $bucketingManager,
            'apiManager' => $apiManager,
            'logManager' => $logManager,
        ];
    }
}
