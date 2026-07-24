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

    /**
     * Contains a space so its urlencode() and rawurlencode() representations
     * genuinely differ (`+` vs `%20`) — required to prove the encoding-agnostic
     * redaction fix, since the old `str_replace('debug_token=' . urlencode(...))`
     * approach only ever matched the urlencode() shape.
     */
    private const ENCODING_TEST_TOKEN = 'qa debug token xyz';

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
     * Build a PSR-18 network-level exception whose message embeds the full
     * config-fetch URL — including `debug_token=<value>` — mirroring how
     * Guzzle's ConnectException/RequestException append `" for <URI>"` to
     * connection/DNS/TLS/timeout failures. This is the shape that actually
     * exercises the AC3 leak: a message-less exception (e.g. bare
     * "Connection refused") never touches the token and passes trivially
     * regardless of whether redaction is applied.
     */
    private function buildNetworkExceptionWithLeakingUrl(): \Http\Client\Exception\NetworkException
    {
        $leakingUrl = self::HOST . ':' . self::PORT
            . '/config/?environment=staging&debug_token=' . self::SECRET_TOKEN . '&_conv_low_cache=1';

        return new \Http\Client\Exception\NetworkException(
            'cURL error 6: Could not resolve host: localhost for ' . $leakingUrl,
            $this->psr17Factory->createRequest('GET', $leakingUrl)
        );
    }

    /**
     * AC3 — token hygiene: never logged in clear, and never present in the
     * rethrown exception's message, when the HTTP client throws a
     * network-level exception whose own message embeds the full config URL
     * (Guzzle's ConnectException/RequestException behavior). This is the
     * codepath that leaked before the fix: `redactDebugTokenForLog()` was
     * applied to the sibling `endpoint` log field but not to `$e->getMessage()`
     * itself, which is both logged raw and interpolated raw into the
     * rethrown RuntimeException.
     */
    #[Test]
    public function debugTokenNeverAppearsInConfigFetchNetworkErrorLogs(): void
    {
        $captured = [];
        $this->spyAllLogCalls($captured);

        $apiManager = $this->buildApiManager(['debugToken' => self::SECRET_TOKEN]);
        $this->mockHttpClient->addException($this->buildNetworkExceptionWithLeakingUrl());

        $thrown = null;
        try {
            $apiManager->getConfig();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected getConfig() to rethrow a RuntimeException');
        $this->assertNotEmpty($captured, 'Expected at least one log call to inspect');
        $this->assertNoSecretLeak($captured, self::SECRET_TOKEN);
        $this->assertStringNotContainsString(
            self::SECRET_TOKEN,
            $thrown->getMessage(),
            'Secret token leaked into the rethrown exception message'
        );
    }

    /**
     * AC3/AC4 symmetry — the preview-fetch entry point
     * (`getConfigForExperience()`, used by `PreviewResolver`) shares the same
     * `fetchConfigFromEndpoint()` error-handling body as `getConfig()`, so it
     * must be equally immune to the network-exception leak.
     */
    #[Test]
    public function debugTokenNeverAppearsInConfigForExperienceNetworkErrorLogs(): void
    {
        $captured = [];
        $this->spyAllLogCalls($captured);

        $apiManager = $this->buildApiManager(['debugToken' => self::SECRET_TOKEN]);
        $this->mockHttpClient->addException($this->buildNetworkExceptionWithLeakingUrl());

        $thrown = null;
        try {
            $apiManager->getConfigForExperience('exp-1');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected getConfigForExperience() to rethrow a RuntimeException');
        $this->assertNotEmpty($captured, 'Expected at least one log call to inspect');
        $this->assertNoSecretLeak($captured, self::SECRET_TOKEN);
        $this->assertStringNotContainsString(
            self::SECRET_TOKEN,
            $thrown->getMessage(),
            'Secret token leaked into the rethrown exception message'
        );
    }

    /**
     * Build a PSR-18 network-level exception whose message embeds
     * `debug_token=<tokenAsItAppearsInUrl>&other=param` — the caller controls
     * exactly how the token is represented in the message (rawurlencode()'d,
     * fully decoded/un-encoded, etc.) so the redaction fix can be proven
     * encoding-agnostic rather than coupled to `urlencode()` output.
     */
    private function buildNetworkExceptionWithEncodedDebugToken(
        string $tokenAsItAppearsInUrl
    ): \Http\Client\Exception\NetworkException {
        $leakingUrl = self::HOST . ':' . self::PORT
            . '/config/?environment=staging&debug_token=' . $tokenAsItAppearsInUrl . '&other=param';

        return new \Http\Client\Exception\NetworkException(
            'cURL error 6: Could not resolve host: localhost for ' . $leakingUrl,
            $this->psr17Factory->createRequest('GET', self::HOST . ':' . self::PORT . '/config/')
        );
    }

    /**
     * Two representations of {@see ENCODING_TEST_TOKEN} that a plugged-in
     * PSR-18 client could plausibly embed in an exception message: Guzzle
     * (and most HTTP clients) rawurlencode() query values, while a client
     * that logs/presents a decoded URL for readability would show the value
     * fully un-encoded. Both must be redacted regardless of which shows up.
     *
     * @return array<string, array{0: string}>
     */
    public static function debugTokenEncodingProvider(): array
    {
        return [
            'rawurlencoded (spaces as %20)' => [rawurlencode(self::ENCODING_TEST_TOKEN)],
            'un-encoded / decoded' => [self::ENCODING_TEST_TOKEN],
        ];
    }

    /**
     * AC3 — token hygiene must hold regardless of how the plugged-in PSR-18
     * client encoded (or didn't encode) the token in its own exception
     * message. Before the fix, `redactDebugTokenForLog()` matched only the
     * exact `urlencode($this->debugToken)` byte sequence — since
     * `urlencode('qa debug token xyz')` produces `qa+debug+token+xyz`, it
     * would silently no-op against a rawurlencode()'d (`%20`) or fully
     * decoded (raw space) representation, leaking the token verbatim into
     * both the log payload and the rethrown RuntimeException. The
     * regex-based fix redacts `debug_token=<value>` regardless of encoding,
     * while leaving the surrounding message (prefix and the trailing
     * `&other=param`) untouched.
     */
    #[DataProvider('debugTokenEncodingProvider')]
    public function testDebugTokenRedactionIsEncodingAgnostic(string $tokenAsItAppearsInUrl): void
    {
        $captured = [];
        $this->spyAllLogCalls($captured);

        $apiManager = $this->buildApiManager(['debugToken' => self::ENCODING_TEST_TOKEN]);
        $this->mockHttpClient->addException(
            $this->buildNetworkExceptionWithEncodedDebugToken($tokenAsItAppearsInUrl)
        );

        $thrown = null;
        try {
            $apiManager->getConfig();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'Expected getConfig() to rethrow a RuntimeException');
        $this->assertNotEmpty($captured, 'Expected at least one log call to inspect');

        $serializedLogs = (string) json_encode($captured, JSON_PARTIAL_OUTPUT_ON_ERROR);

        // The exact on-the-wire representation the exception message carried
        // must be gone — this is what proves the fix works regardless of
        // encoding (for the decoded row, this representation IS the full
        // secret; for the rawurlencoded row, it's the %20-encoded value).
        $this->assertStringNotContainsString(
            $tokenAsItAppearsInUrl,
            $serializedLogs,
            'Debug token representation leaked into a log payload'
        );
        $this->assertStringNotContainsString(
            $tokenAsItAppearsInUrl,
            $thrown->getMessage(),
            'Debug token representation leaked into the rethrown exception message'
        );

        // The full configured secret must never appear verbatim either way.
        $this->assertNoSecretLeak($captured, self::ENCODING_TEST_TOKEN);
        $this->assertStringNotContainsString(
            self::ENCODING_TEST_TOKEN,
            $thrown->getMessage(),
            'Full secret token leaked into the rethrown exception message'
        );

        // Surrounding message content — before `debug_token=` and after the
        // redacted value — must be preserved untouched.
        $this->assertStringContainsString(
            'Could not resolve host',
            $thrown->getMessage(),
            'Message content preceding debug_token= must be preserved'
        );
        $this->assertStringContainsString(
            '&other=param',
            $thrown->getMessage(),
            'Message content following the redacted value must be preserved'
        );
    }
}
