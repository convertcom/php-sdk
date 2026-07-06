<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\Preview;

use ConvertSdk\ApiManager;
use ConvertSdk\BucketingManager;
use ConvertSdk\Core;
use ConvertSdk\DataManager;
use ConvertSdk\DTO\BucketedFeature;
use ConvertSdk\DTO\BucketedVariation;
use ConvertSdk\Event\EventManager;
use ConvertSdk\ExperienceManager;
use ConvertSdk\FeatureManager;
use ConvertSdk\Interfaces\SegmentsManagerInterface;
use ConvertSdk\LogManager;
use ConvertSdk\RuleManager;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * Counting/recording spy for the PSR-16 cache Core receives — used to prove
 * qs-02 AC8's memoization key scheme (`preview_{experienceId}`, never the
 * normal `convert_sdk.config.*` key) without asserting on an implementation
 * detail beyond "which keys were touched".
 */
class RecordingCache implements CacheInterface
{
    /** @var array<int, array{op: string, key: string}> */
    public array $calls = [];

    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $this->calls[] = ['op' => 'get', 'key' => $key];
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->calls[] = ['op' => 'set', 'key' => $key];
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        $this->calls[] = ['op' => 'delete', 'key' => $key];
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }
}

/**
 * Counting spy for the duck-typed visitor dataStore (get/set, per
 * DataManager::setDataStore()) — used to prove qs-02 AC6's "zero visitor-state
 * persistence writes" and AC7's "concurrent non-preview context persists
 * normally" without depending on cache internals.
 */
class RecordingDataStore
{
    public int $setCalls = 0;

    /** @var array<int, string> */
    public array $setKeys = [];

    /** @var array<string, mixed> */
    private array $data = [];

    public function get(?string $key = null): mixed
    {
        return $key === null ? $this->data : ($this->data[$key] ?? null);
    }

    public function set(string $key, mixed $value): void
    {
        $this->setCalls++;
        $this->setKeys[] = $key;
        $this->data[$key] = $value;
    }
}

/**
 * qs-02 capability (B) preview input — AC4, AC5, AC6, AC7, AC8 (Context/Core
 * integration level).
 *
 * Every rig below wires REAL BucketingManager/RuleManager/DataManager/
 * ExperienceManager/ApiManager instances (mirrors ContextTest.php's
 * construction pattern) so the bypass assertions (AC5) exercise the actual
 * gating code — matchRulesByField's environment check, isVariationActive's
 * status/traffic filter, and the stored-decision-first branch in
 * DataManager::_retrieveBucketing() — rather than a mock that trivially
 * returns whatever the test wants. FeatureManager/SegmentsManager are mocked
 * since no test here exercises features or segments.
 *
 * Only ApiManager's PSR-18 HTTP client (Http\Mock\Client) and the PSR-16
 * cache / visitor dataStore (RecordingCache / RecordingDataStore, above) are
 * test doubles — both are spies, not stubs, so every assertion is on real
 * call counts/keys/URLs the SDK actually produced.
 *
 * "Shutdown" (AC6) is modeled by directly invoking
 * `ApiManager::releaseQueue('shutdown')` — the exact call
 * `ConvertSDK::create()`'s `register_shutdown_function` handler makes — since
 * PHPUnit cannot observe a real PHP-FPM process teardown.
 *
 * RED-phase note (qs-02 PHP-2, TDD RED): `Context::setPreview()` does not
 * exist yet. Every test below is expected to fail with
 * `Error: Call to undefined method ConvertSdk\Context::setPreview()` until
 * the PHP-2 GREEN implementation lands.
 *
 * @see ../../../../../ai-driven-product-dev/_bmad-output/planning-artifacts/2026-03-13-convert-php-sdk/qs-02-experiment-preview.md
 */
class ContextPreviewTest extends TestCase
{
    private const ENVIRONMENT = 'production';
    private const HOST = 'http://localhost';
    private const PORT = 8093;
    private const OTHER_EXPERIENCE_ID = '500';
    private const OTHER_EXPERIENCE_KEY = 'other-exp';
    private const GOAL_KEY = 'preview-goal';
    private const NO_LOCATION_GATE = ['ignoreLocationProperties' => true];
    private const FEATURE_ID = '20001';
    private const FEATURE_KEY = 'preview-feature';
    private const FEATURE_EXPERIENCE_ID = '9105';
    private const FEATURE_EXPERIENCE_KEY = 'feature-carrying-exp';
    /** Non-empty location properties so the location-agnostic fixtures (no
     * `locations`/`site_area`) fall into matchRulesByField()'s "not restricted"
     * branch and actually reach the bucketing/persistence code — required so a
     * zero-trace regression test genuinely exercises the write path instead of
     * short-circuiting on the location gate before it ever would.
     */
    private const LOCATION_PROPERTIES = ['locationProperties' => ['url' => 'https://convert.com/']];

    private MockHttpClient $mockHttpClient;
    private Psr17Factory $psr17Factory;

    protected function setUp(): void
    {
        $this->mockHttpClient = new MockHttpClient();
        $this->psr17Factory = new Psr17Factory();
    }

    // -- Rig construction ----------------------------------------------------------------

    /**
     * Builds a fresh Core wired with real managers sharing ONE ApiManager/DataManager
     * pair (mirrors ConvertSDK::create()'s single-singleton wiring, per the "shared
     * singleton managers" crux documented for this task). `$extraExperiences` are
     * visible in the config from the moment the rig is built — i.e. "already in the
     * current config" per qs-02 contract §2, needing no ?exp= fetch.
     *
     * @param array<int, array<string, mixed>> $extraExperiences
     * @param array<int, array<string, mixed>> $features
     * @return array{core: Core, dataManager: DataManager, apiManager: ApiManager, cache: RecordingCache, dataStore: RecordingDataStore}
     */
    private function buildRig(array $extraExperiences = [], array $features = []): array
    {
        $data = new ConfigResponseData([
            'account_id' => 'acct-1',
            'project' => ['id' => 'proj-1'],
            'experiences' => array_merge([$this->otherExperience()], $extraExperiences),
            'features' => $features,
            'goals' => [
                ['id' => '7001', 'key' => self::GOAL_KEY, 'name' => 'Preview Goal', 'rules' => null],
            ],
        ]);

        $config = new Config([
            'environment' => self::ENVIRONMENT,
            'data' => $data,
            'api' => [
                'endpoint' => [
                    'config' => self::HOST . ':' . self::PORT,
                    'track' => self::HOST . ':' . self::PORT,
                ],
            ],
            'network' => ['tracking' => false],
        ]);

        $eventManager = new EventManager();
        $apiManager = new ApiManager(
            $config,
            $eventManager,
            null,
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory
        );
        $dataManager = new DataManager(
            $config,
            new BucketingManager(),
            new RuleManager(),
            $eventManager,
            $apiManager,
            new LogManager()
        );
        $dataStore = new RecordingDataStore();
        $dataManager->setDataStore($dataStore);

        $experienceManager = new ExperienceManager(dataManager: $dataManager);
        $featureManager = new FeatureManager(dataManager: $dataManager);
        $cache = new RecordingCache();

        $core = new Core(
            $config,
            $dataManager,
            $eventManager,
            $experienceManager,
            $featureManager,
            $this->createMock(SegmentsManagerInterface::class),
            $apiManager,
            $cache,
            Core::DEFAULT_DATA_REFRESH_INTERVAL,
            null,
        );

        return [
            'core' => $core,
            'dataManager' => $dataManager,
            'apiManager' => $apiManager,
            'cache' => $cache,
            'dataStore' => $dataStore,
        ];
    }

    /**
     * A location/audience-agnostic, always-decidable experience present in the base
     * config of every rig — used to prove "other experiences still evaluate and
     * decide normally on a preview context" (AC6) and "a concurrent non-preview
     * context buckets normally" (AC7).
     *
     * @return array<string, mixed>
     */
    private function otherExperience(): array
    {
        return [
            'id' => self::OTHER_EXPERIENCE_ID,
            'key' => self::OTHER_EXPERIENCE_KEY,
            'name' => 'Other Experience',
            'status' => 'running',
            'variations' => [
                $this->variation(self::OTHER_EXPERIENCE_ID . '-A', 'a'),
                $this->variation(self::OTHER_EXPERIENCE_ID . '-B', 'b'),
            ],
        ];
    }

    /**
     * A feature declaration paired with {@see featureCarryingExperience()} — used to prove
     * the Context::runFeature()/runFeatures() zero-trace regression (qs-02 decision-audit
     * Defect 1/2): these methods bucket EVERY experience in the config (not just a
     * targeted one), so a leak here would persist/track for an experience the caller
     * never even named.
     *
     * @return array<string, mixed>
     */
    private function featureFixture(): array
    {
        return [
            'id' => self::FEATURE_ID,
            'key' => self::FEATURE_KEY,
            'name' => 'Preview Feature',
            'variables' => [],
        ];
    }

    /**
     * A location/audience-agnostic, always-decidable experience carrying a fullStackFeature
     * change linked to {@see featureFixture()} — present in the base config (like
     * {@see otherExperience()}) so Context::runFeature()/runFeatures() bucket it as part of
     * their "iterate every experience in config" sweep.
     *
     * @return array<string, mixed>
     */
    private function featureCarryingExperience(): array
    {
        return $this->experienceFixture(self::FEATURE_EXPERIENCE_ID, self::FEATURE_EXPERIENCE_KEY, [], [
            $this->variation(self::FEATURE_EXPERIENCE_ID . '-A', 'a', [
                'changes' => [['id' => 'chg-feat-a', 'type' => 'fullStackFeature', 'data' => ['feature_id' => self::FEATURE_ID]]],
            ]),
            $this->variation(self::FEATURE_EXPERIENCE_ID . '-B', 'b', [
                'changes' => [['id' => 'chg-feat-b', 'type' => 'fullStackFeature', 'data' => ['feature_id' => self::FEATURE_ID]]],
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $experienceOverrides
     * @param array<int, array<string, mixed>> $variations
     * @return array<string, mixed>
     */
    private function experienceFixture(string $id, string $key, array $experienceOverrides, array $variations): array
    {
        return array_merge([
            'id' => $id,
            'key' => $key,
            'name' => 'Preview Target ' . $key,
            'status' => 'running',
            'variations' => $variations,
        ], $experienceOverrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function variation(string $id, string $key, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'key' => $key,
            'status' => 'running',
            'traffic_allocation' => 50,
            'changes' => [['id' => 'chg-' . $id, 'type' => 'fullStackFeature', 'data' => []]],
        ], $overrides);
    }

    /**
     * Response body shape verified against the backend OpenAPI contract
     * (`backend/apiDoc/serving/src/responses/index.yaml` — `ProjectConfigResponse`
     * resolves directly to the `ConfigResponseData` schema, with no enclosing
     * envelope) and against the JS SDK's own real-HTTP-server integration test
     * for `getConfigByExperience()`
     * (`javascript-sdk/packages/api/tests/api-manager-config-by-experience.tests.ts`),
     * whose mock server returns the config fields at the top level of the
     * response body.
     *
     * @param array<string, mixed> $experience
     */
    private function queueExpFetchResponse(array $experience): void
    {
        $this->mockHttpClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'account_id' => 'acct-1',
            'project' => ['id' => 'proj-1'],
            'experiences' => [$experience],
        ])));
    }

    /**
     * @return array<int, \Psr\Http\Message\RequestInterface>
     */
    private function trackRequests(): array
    {
        return array_values(array_filter(
            $this->mockHttpClient->getRequests(),
            fn ($request) => str_contains((string) $request->getUri(), '/track/')
        ));
    }

    /**
     * @return array<int, \Psr\Http\Message\RequestInterface>
     */
    private function expRequestsFor(string $experienceId): array
    {
        return array_values(array_filter(
            $this->mockHttpClient->getRequests(),
            fn ($request) => str_contains((string) $request->getUri(), 'exp=' . $experienceId)
        ));
    }

    // -- AC4 + AC5: full bypass sweep -----------------------------------------------------

    /**
     * Six cases, each disabling exactly one normal gate that would otherwise block or
     * redirect the decision; the shared assertion is "preview forces the given
     * variation regardless, and — where a real non-preview control call is
     * meaningful — the non-preview path is unaffected by the preview override."
     *
     * 'draft status' is also AC4's literal scenario ("a draft experience delivered
     * only via the ?exp= fetch").
     *
     * @return array<string, array{
     *     0: string, 1: string, 2: array<string, mixed>,
     *     3: array<int, array<string, mixed>>, 4: bool, 5: ?string, 6: string, 7: ?string
     * }>
     */
    public static function bypassCasesProvider(): array
    {
        return [
            'draft status — delivered only via ?exp= fetch (AC4)' => [
                '9001', 'draft-exp', ['status' => 'draft'],
                [['id' => '9001-A', 'key' => 'a'], ['id' => '9001-B', 'key' => 'b']],
                false, null, '9001-B', null,
            ],
            'paused status — delivered only via ?exp= fetch' => [
                '9002', 'paused-exp', ['status' => 'paused'],
                [['id' => '9002-A', 'key' => 'a'], ['id' => '9002-B', 'key' => 'b']],
                false, null, '9002-B', null,
            ],
            'mismatched environment — present in config under a different environment' => [
                '9003', 'env-mismatch-exp', ['environment' => 'staging'],
                [['id' => '9003-A', 'key' => 'a'], ['id' => '9003-B', 'key' => 'b']],
                true, null, '9003-B', null,
            ],
            'non-running variation — sole variation is not RUNNING' => [
                '9004', 'non-running-exp', [],
                [['id' => '9004-A', 'key' => 'a', 'overrides' => ['status' => 'paused']]],
                true, null, '9004-A', null,
            ],
            'zero-traffic variation — sole variation has traffic_allocation 0' => [
                '9005', 'zero-traffic-exp', [],
                [['id' => '9005-A', 'key' => 'a', 'overrides' => ['traffic_allocation' => 0]]],
                true, null, '9005-A', null,
            ],
            'different stored decision — visitor already bucketed elsewhere' => [
                '9006', 'stored-decision-exp', [],
                [['id' => '9006-A', 'key' => 'a'], ['id' => '9006-B', 'key' => 'b']],
                true, '9006-A', '9006-B', '9006-A',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $experienceOverrides
     * @param array<int, array<string, mixed>> $variationSpecs
     */
    #[DataProvider('bypassCasesProvider')]
    public function testPreviewBypassesAllNormalGates(
        string $experienceId,
        string $experienceKey,
        array $experienceOverrides,
        array $variationSpecs,
        bool $presentInBaseConfig,
        ?string $seedStoredVariationId,
        string $forcedVariationId,
        ?string $expectedNormalVariationId
    ): void {
        $variations = array_map(
            fn (array $spec) => $this->variation($spec['id'], $spec['key'], $spec['overrides'] ?? []),
            $variationSpecs
        );
        $experience = $this->experienceFixture($experienceId, $experienceKey, $experienceOverrides, $variations);

        $rig = $this->buildRig($presentInBaseConfig ? [$experience] : []);
        if (!$presentInBaseConfig) {
            $this->queueExpFetchResponse($experience);
        }

        $previewVisitorId = 'preview-visitor-' . $experienceId;
        if ($seedStoredVariationId !== null) {
            $rig['dataManager']->putData($previewVisitorId, ['bucketing' => [$experienceId => $seedStoredVariationId]]);
        }

        $previewContext = $rig['core']->createContext($previewVisitorId);
        $previewContext->setPreview($experienceId, $forcedVariationId);
        $decision = $previewContext->runExperience($experienceKey);

        $this->assertInstanceOf(BucketedVariation::class, $decision, 'preview must force a decision regardless of the disabled gate');
        $this->assertSame($forcedVariationId, $decision->variationId, 'preview must return the exact requested variation');
        $this->assertSame($experienceId, $decision->experienceId);

        if (!$presentInBaseConfig) {
            $this->assertCount(1, $this->expRequestsFor($experienceId), 'AC4: exactly one ?exp= fetch expected when the experience is absent from the current config');
        }

        $normalVisitorId = $seedStoredVariationId !== null ? $previewVisitorId : 'normal-visitor-' . $experienceId;
        $normalContext = $rig['core']->createContext($normalVisitorId);
        $normalDecision = $normalContext->runExperience($experienceKey, new BucketingAttributes(self::NO_LOCATION_GATE));

        if ($expectedNormalVariationId === null) {
            $this->assertNull($normalDecision, 'non-preview evaluation must be blocked by the real gate this case exercises');
        } else {
            $this->assertInstanceOf(BucketedVariation::class, $normalDecision);
            $this->assertSame($expectedNormalVariationId, $normalDecision->variationId, 'non-preview context must be unaffected by the preview override (preview must not have persisted anything)');
        }
    }

    // -- §3 precedence: the bulk method must also honor preview forcing ------------------

    /**
     * qs-02 decision-audit remediation, Defect 3: contract §3 ("preview forcing beats
     * stored decisions and normal bucketing for the target experience on that context")
     * is method-agnostic — runExperiences() must honor it for an in-config, running,
     * environment-matching preview target, not just runExperience(). Seeds a stored
     * decision (mirroring the 'different stored decision' bypassCasesProvider() case)
     * so the un-forced bulk result is deterministic, proving the override — not hash
     * luck — is what makes the assertion pass.
     */
    #[Test]
    public function previewForcingOverridesTheTargetExperienceInBulkRunExperiences(): void
    {
        $targetId = '9106';
        $targetKey = 'bulk-precedence-exp';
        $target = $this->experienceFixture($targetId, $targetKey, [], [
            $this->variation('9106-A', 'a'),
            $this->variation('9106-B', 'b'),
        ]);

        $rig = $this->buildRig([$target]);
        $visitorId = 'preview-visitor-bulk-precedence';
        $rig['dataManager']->putData($visitorId, ['bucketing' => [$targetId => '9106-A']]);

        $context = $rig['core']->createContext($visitorId);
        $context->setPreview($targetId, '9106-B');

        $decisions = $context->runExperiences(new BucketingAttributes(self::NO_LOCATION_GATE));

        $byKey = [];
        foreach ($decisions as $decision) {
            $byKey[$decision->experienceKey] = $decision;
        }

        $this->assertArrayHasKey($targetKey, $byKey, 'the in-config running preview target must still appear in the bulk result');
        $this->assertSame('9106-B', $byKey[$targetKey]->variationId, 'preview forcing must beat the stored decision for the target experience in the bulk method too (contract §3, method-agnostic)');
        $this->assertArrayHasKey(self::OTHER_EXPERIENCE_KEY, $byKey, 'other experiences must still decide normally on a preview context');
    }

    // -- AC6: zero trace across the full lifecycle, including shutdown -------------------

    #[Test]
    public function previewContextLeavesZeroTraceAcrossFullLifecycleIncludingShutdown(): void
    {
        $targetId = '9101';
        $targetKey = 'shutdown-exp';
        $experience = $this->experienceFixture($targetId, $targetKey, ['status' => 'draft'], [
            $this->variation('9101-A', 'a'),
            $this->variation('9101-B', 'b'),
        ]);

        $rig = $this->buildRig();
        $this->queueExpFetchResponse($experience);

        $context = $rig['core']->createContext('preview-visitor-ac6');
        $context->setPreview($targetId, '9101-B');

        $forced = $context->runExperience($targetKey);
        $this->assertInstanceOf(BucketedVariation::class, $forced);
        $this->assertSame('9101-B', $forced->variationId);

        // A DIFFERENT experience on the SAME preview context must still decide
        // normally (coherent rendering) — but the whole context is zero-trace, not
        // just the forced target.
        $other = $context->runExperience(self::OTHER_EXPERIENCE_KEY, new BucketingAttributes(self::NO_LOCATION_GATE));
        $this->assertInstanceOf(BucketedVariation::class, $other, 'other experiences must still decide normally on a preview context');

        $context->trackConversion(self::GOAL_KEY);

        // Model the PHP-FPM shutdown handler ConvertSDK::create() registers.
        $rig['apiManager']->releaseQueue('shutdown');

        $this->assertSame([], $this->trackRequests(), 'zero requests to the track endpoint across the full preview-context lifecycle, including shutdown flush');
        $this->assertSame(0, $rig['dataStore']->setCalls, 'zero visitor-state dataStore writes across the full preview-context lifecycle');
    }

    /**
     * qs-02 decision-audit remediation, Defect 1/2: Context::runFeature()/runFeatures() must
     * be just as zero-trace as runExperience()/runExperiences() on a preview-set context.
     * These feature methods bucket EVERY experience in the config (FeatureManager::runFeatures()
     * has no experience filter by default), so calling either one on a preview context leaks
     * persistence/tracking for every not-yet-bucketed experience unless suppressPersistence is
     * forwarded — exactly the gap the original AC6 test missed by mocking FeatureManager instead
     * of exercising the real bucketing path.
     */
    #[Test]
    public function previewContextLeavesZeroTraceAcrossFeatureMethodsIncludingShutdown(): void
    {
        $targetId = '9104';
        $targetKey = 'feature-preview-target-exp';
        $target = $this->experienceFixture($targetId, $targetKey, ['status' => 'draft'], [
            $this->variation('9104-A', 'a'),
            $this->variation('9104-B', 'b'),
        ]);

        $rig = $this->buildRig([$this->featureCarryingExperience()], [$this->featureFixture()]);
        $this->queueExpFetchResponse($target);

        $context = $rig['core']->createContext('preview-visitor-feature');
        $context->setPreview($targetId, '9104-B');

        $forced = $context->runExperience($targetKey);
        $this->assertInstanceOf(BucketedVariation::class, $forced, 'preview target must still force its decision');

        $feature = $context->runFeature(self::FEATURE_KEY, new BucketingAttributes(self::LOCATION_PROPERTIES));
        $this->assertInstanceOf(BucketedFeature::class, $feature, 'the feature-carrying experience must actually bucket, not be blocked by a gate — otherwise this test would pass trivially');

        $features = $context->runFeatures(new BucketingAttributes(self::LOCATION_PROPERTIES));
        $this->assertNotEmpty($features, 'runFeatures() must actually bucket experiences, not be blocked by a gate — otherwise this test would pass trivially');

        // Model the PHP-FPM shutdown handler ConvertSDK::create() registers.
        $rig['apiManager']->releaseQueue('shutdown');

        $this->assertSame([], $this->trackRequests(), 'zero requests to the track endpoint after runFeature()/runFeatures() on a preview context, including shutdown flush');
        $this->assertSame(0, $rig['dataStore']->setCalls, 'zero visitor-state dataStore writes after runFeature()/runFeatures() on a preview context');
    }

    // -- AC7: isolation from a concurrent non-preview context ----------------------------

    #[Test]
    public function concurrentNonPreviewContextTracksAndPersistsNormallyOnSharedManagers(): void
    {
        $targetId = '9102';
        $targetKey = 'concurrent-exp';
        $experience = $this->experienceFixture($targetId, $targetKey, ['status' => 'draft'], [
            $this->variation('9102-A', 'a'),
            $this->variation('9102-B', 'b'),
        ]);

        $rig = $this->buildRig();
        $this->queueExpFetchResponse($experience);

        $previewContext = $rig['core']->createContext('preview-visitor-ac7');
        $previewContext->setPreview($targetId, '9102-B');
        $previewContext->runExperience($targetKey);
        $previewContext->trackConversion(self::GOAL_KEY);

        // Concurrent NON-preview context on the SAME Core/managers (same ApiManager
        // queue, same DataManager, same dataStore/cache) must be unaffected.
        $normalContext = $rig['core']->createContext('normal-visitor-ac7');
        $normalDecision = $normalContext->runExperience(self::OTHER_EXPERIENCE_KEY, new BucketingAttributes(self::NO_LOCATION_GATE));
        $normalContext->trackConversion(self::GOAL_KEY);

        $rig['apiManager']->releaseQueue('shutdown');

        $this->assertInstanceOf(BucketedVariation::class, $normalDecision);
        $this->assertGreaterThan(0, $rig['dataStore']->setCalls, 'the concurrent non-preview context must persist visitor state normally');
        $this->assertNotEmpty($this->trackRequests(), 'the concurrent non-preview context must still send tracking requests');
    }

    // -- AC8: memoization ------------------------------------------------------------------

    #[Test]
    public function twoPreviewResolutionsWithinSixtySecondsCauseExactlyOneOriginFetchViaPreviewScopedKey(): void
    {
        $targetId = '9103';
        $targetKey = 'memo-exp';
        $experience = $this->experienceFixture($targetId, $targetKey, ['status' => 'draft'], [
            $this->variation('9103-A', 'a'),
            $this->variation('9103-B', 'b'),
        ]);

        $rig = $this->buildRig();
        // Exactly ONE response queued — a second fetch would fall through to the
        // mock client's default empty-200 response, which the assertions below
        // would catch via the raw request count (not via queue exhaustion).
        $this->queueExpFetchResponse($experience);

        $firstContext = $rig['core']->createContext('preview-visitor-ac8-a');
        $firstContext->setPreview($targetId, '9103-B');
        $firstDecision = $firstContext->runExperience($targetKey);

        $secondContext = $rig['core']->createContext('preview-visitor-ac8-b');
        $secondContext->setPreview($targetId, '9103-B');
        $secondDecision = $secondContext->runExperience($targetKey);

        $this->assertInstanceOf(BucketedVariation::class, $firstDecision);
        $this->assertInstanceOf(BucketedVariation::class, $secondDecision);
        $this->assertSame('9103-B', $firstDecision->variationId);
        $this->assertSame('9103-B', $secondDecision->variationId);

        $this->assertCount(1, $this->expRequestsFor($targetId), 'two preview resolutions for the same experience within 60s must cause exactly one origin fetch');

        $recordedKeys = array_column($rig['cache']->calls, 'key');
        $this->assertContains('preview_' . $targetId, $recordedKeys, 'memoization must use the preview-scoped key preview_{experienceId}');
        foreach ($recordedKeys as $key) {
            $this->assertStringNotContainsString('convert_sdk.config.', $key, 'preview memoization must never use the normal config cache key');
        }
    }
}
