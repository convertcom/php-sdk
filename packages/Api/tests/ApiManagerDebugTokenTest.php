<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\ApiManager;
use ConvertSdk\Config\DefaultConfig;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Utils\ObjectUtils;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorTrackingEvents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * qs-02 capability (A) `debugToken` config option.
 *
 * Covers:
 * - AC1 (transport): config-fetch URL carries `debug_token=<value>` AND
 *   `_conv_low_cache=1` when set, forced regardless of `network.cacheLevel`;
 *   neither when unset (unless cacheLevel=low already adds the low-cache flag).
 * - AC3 (token hygiene): the token never reaches the track-endpoint payload
 *   nor any log call in clear text.
 *
 * @see ../../../../ai-driven-product-dev/_bmad-output/planning-artifacts/2026-03-13-convert-php-sdk/qs-02-experiment-preview.md
 */
class ApiManagerDebugTokenTest extends TestCase
{
    private const HOST = 'http://localhost';
    private const PORT = 8091;
    private const BATCH_SIZE = 2;
    private const SECRET_TOKEN = 'qa-debug-token-xyz789';

    private MockHttpClient $mockHttpClient;
    private Psr17Factory $psr17Factory;
    /** @var EventManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventManagerMock;
    /** @var LogManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $loggerManagerMock;

    protected function setUp(): void
    {
        $this->mockHttpClient = new MockHttpClient();
        $this->psr17Factory = new Psr17Factory();
        $this->eventManagerMock = $this->createMock(EventManagerInterface::class);
        $this->loggerManagerMock = $this->createMock(LogManagerInterface::class);
    }

    /**
     * Build a Config merging the shared test fixture + SDK defaults + per-test
     * overrides, mirroring ApiManagerTest's fixture assembly so the two test
     * classes stay consistent.
     *
     * @param array<string, mixed> $overrides
     */
    private function buildConfig(array $overrides = []): Config
    {
        $testConfig = json_decode((string) file_get_contents(__DIR__ . '/test-config.json'), true);
        $defaultConfig = DefaultConfig::getDefault();
        $mergedConfig = ObjectUtils::objectDeepMerge($testConfig, $defaultConfig);

        $baseOverrides = [
            'api' => [
                'endpoint' => [
                    'config' => self::HOST . ':' . self::PORT,
                    'track'  => self::HOST . ':' . self::PORT,
                ],
            ],
            'events' => [
                'batch_size' => self::BATCH_SIZE,
            ],
            'mapper' => null,
        ];
        $layeredOverrides = ObjectUtils::objectDeepMerge($baseOverrides, $overrides);
        $finalConfig = ObjectUtils::objectDeepMerge($mergedConfig, $layeredOverrides);

        if (isset($finalConfig['sdkKey'])) {
            unset($finalConfig['sdkKey']);
        }
        $finalConfig['data'] = new ConfigResponseData($finalConfig['data']);

        return new Config($finalConfig);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildApiManager(array $overrides = []): ApiManager
    {
        return new ApiManager(
            $this->buildConfig($overrides),
            $this->eventManagerMock,
            $this->loggerManagerMock,
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory
        );
    }

    private function queueSuccessfulConfigResponse(): void
    {
        $this->mockHttpClient->addResponse(
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'data' => [
                    'account_id' => '999',
                    'project' => ['id' => '888', 'key' => 'test-project'],
                    'experiences' => [],
                    'features' => [],
                    'segments' => [],
                    'audiences' => [],
                    'goals' => [],
                    'locations' => [],
                ],
            ]))
        );
    }

    /**
     * Stub every logger method to capture its arguments into $captured,
     * so AC3 tests can assert the secret never appears in any log call
     * regardless of level.
     *
     * @param array<int, array{0: string, 1: array<int, mixed>}> $captured
     */
    private function spyAllLogCalls(array &$captured): void
    {
        foreach (['trace', 'debug', 'info', 'warn', 'error'] as $method) {
            $this->loggerManagerMock->method($method)
                ->willReturnCallback(function (...$args) use (&$captured, $method): void {
                    $captured[] = [$method, $args];
                });
        }
    }

    /**
     * @param array<int, array{0: string, 1: array<int, mixed>}> $captured
     */
    private function assertNoSecretLeak(array $captured, string $secret): void
    {
        $serialized = json_encode($captured, JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString($secret, $serialized, 'Secret token leaked into a log payload');
    }

    /**
     * @return array<string, array{0: ?string, 1: string, 2: bool, 3: bool}>
     */
    public static function debugTokenUrlProvider(): array
    {
        return [
            'debugToken set, default cacheLevel — both params forced' => [
                self::SECRET_TOKEN, 'default', true, true,
            ],
            'debugToken unset, default cacheLevel — neither param' => [
                null, 'default', false, false,
            ],
            'debugToken unset, cacheLevel=low — only low-cache param (regression, today\'s behavior)' => [
                null, 'low', false, true,
            ],
            'debugToken set, cacheLevel=low — both, forced regardless' => [
                self::SECRET_TOKEN, 'low', true, true,
            ],
        ];
    }

    /**
     * AC1 — debugToken transport.
     */
    #[DataProvider('debugTokenUrlProvider')]
    public function testDebugTokenUrlTransport(
        ?string $debugToken,
        string $cacheLevel,
        bool $expectDebugTokenParam,
        bool $expectLowCacheParam
    ): void {
        $overrides = ['network' => ['cacheLevel' => $cacheLevel]];
        if ($debugToken !== null) {
            $overrides['debugToken'] = $debugToken;
        }

        $apiManager = $this->buildApiManager($overrides);
        $this->queueSuccessfulConfigResponse();

        $apiManager->getConfig();

        $sentUri = (string) $this->mockHttpClient->getLastRequest()->getUri();

        if ($expectDebugTokenParam) {
            $this->assertStringContainsString('debug_token=' . $debugToken, $sentUri);
        } else {
            $this->assertStringNotContainsString('debug_token=', $sentUri);
        }

        if ($expectLowCacheParam) {
            $this->assertStringContainsString('_conv_low_cache=1', $sentUri);
        } else {
            $this->assertStringNotContainsString('_conv_low_cache=1', $sentUri);
        }
    }

    /**
     * AC3 — token hygiene: never sent to the track endpoint.
     */
    #[Test]
    public function debugTokenNeverAppearsInTrackEndpointPayload(): void
    {
        $apiManager = $this->buildApiManager(['debugToken' => self::SECRET_TOKEN]);

        $requestData = new VisitorTrackingEvents([
            'eventType' => 'bucketing',
            'data' => ['experienceId' => '11', 'variationId' => '12'],
        ]);

        $this->mockHttpClient->addResponse(
            new Response(200, ['Content-Type' => 'application/json'], '{}')
        );

        for ($i = 1; $i <= self::BATCH_SIZE; $i++) {
            $apiManager->enqueue("VID$i", $requestData);
        }

        $sentRequest = $this->mockHttpClient->getLastRequest();
        $this->assertNotNull($sentRequest);
        $this->assertStringContainsString('/track/', (string) $sentRequest->getUri());

        $body = (string) $sentRequest->getBody();
        $this->assertStringNotContainsString(self::SECRET_TOKEN, $body);
        $this->assertStringNotContainsString('debug_token', $body);
    }

    /**
     * AC3 — token hygiene: never logged in clear on a successful config fetch.
     */
    #[Test]
    public function debugTokenNeverAppearsInConfigFetchSuccessLogs(): void
    {
        $captured = [];
        $this->spyAllLogCalls($captured);

        $apiManager = $this->buildApiManager(['debugToken' => self::SECRET_TOKEN]);
        $this->queueSuccessfulConfigResponse();

        $apiManager->getConfig();

        $this->assertNotEmpty($captured, 'Expected at least one log call to inspect');
        $this->assertNoSecretLeak($captured, self::SECRET_TOKEN);
    }

    /**
     * AC3 — token hygiene: never logged in clear when the config fetch fails
     * (bad HTTP status) — this is the codepath most likely to leak the raw
     * query string via the 'endpoint' log field.
     */
    #[Test]
    public function debugTokenNeverAppearsInConfigFetchBadStatusLogs(): void
    {
        $captured = [];
        $this->spyAllLogCalls($captured);

        $apiManager = $this->buildApiManager(['debugToken' => self::SECRET_TOKEN]);
        $this->mockHttpClient->addResponse(
            new Response(500, ['Content-Type' => 'application/json'], '{"error":"internal"}')
        );

        try {
            $apiManager->getConfig();
        } catch (\RuntimeException $e) {
            // Expected — the failure path also logs; the assertion below still applies.
        }

        $this->assertNotEmpty($captured, 'Expected at least one log call to inspect');
        $this->assertNoSecretLeak($captured, self::SECRET_TOKEN);
    }

    /**
     * AC3 — token hygiene: never logged in clear when the HTTP client throws
     * a network-level exception (the other logging codepath in getConfig()).
     */
    #[Test]
    public function debugTokenNeverAppearsInConfigFetchNetworkErrorLogs(): void
    {
        $captured = [];
        $this->spyAllLogCalls($captured);

        $apiManager = $this->buildApiManager(['debugToken' => self::SECRET_TOKEN]);
        $this->mockHttpClient->addException(
            new \Http\Client\Exception\NetworkException(
                'Connection refused',
                $this->psr17Factory->createRequest('GET', 'http://localhost')
            )
        );

        try {
            $apiManager->getConfig();
        } catch (\RuntimeException $e) {
            // Expected — getConfig() wraps and rethrows; the assertion below still applies.
        }

        $this->assertNotEmpty($captured, 'Expected at least one log call to inspect');
        $this->assertNoSecretLeak($captured, self::SECRET_TOKEN);
    }
}
