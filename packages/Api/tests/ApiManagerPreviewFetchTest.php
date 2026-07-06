<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\ApiManager;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * qs-02 capability (B) preview input — the `?exp=` origin fetch at the
 * ApiManager unit level.
 *
 * Contract §2 (resolution): "if experienceId is not in the current config,
 * fetch the config with exp={experienceId} and _conv_low_cache=1 appended
 * (plus debug_token if configured) and use the response for this context."
 *
 * This covers the URL-construction slice of AC4 ("forced decision ... delivered
 * only via the ?exp= fetch") in isolation from bucketing/rule/context concerns.
 * Full-chain forcing/bypass/zero-trace/isolation/memoization behavior is
 * covered by ContextPreviewTest (packages/Php-sdk/tests/Preview/).
 *
 * RED-phase note (qs-02 PHP-2, TDD RED): `ApiManager::getConfigForExperience()`
 * does not exist yet. Every test below is expected to fail with
 * `Error: Call to undefined method ConvertSdk\ApiManager::getConfigForExperience()`
 * until the PHP-2 GREEN implementation lands.
 *
 * @see ../../../../ai-driven-product-dev/_bmad-output/planning-artifacts/2026-03-13-convert-php-sdk/qs-02-experiment-preview.md
 */
class ApiManagerPreviewFetchTest extends TestCase
{
    private const HOST = 'http://localhost';
    private const PORT = 8092;
    private const EXPERIENCE_ID = '9001';
    private const DEBUG_TOKEN = 'qa-debug-token-xyz789';

    private MockHttpClient $mockHttpClient;
    private Psr17Factory $psr17Factory;

    protected function setUp(): void
    {
        $this->mockHttpClient = new MockHttpClient();
        $this->psr17Factory = new Psr17Factory();
    }

    private function buildApiManager(?string $debugToken = null): ApiManager
    {
        $config = [
            'sdkKey' => 'test-sdk-key',
            'environment' => 'production',
            'api' => [
                'endpoint' => [
                    'config' => self::HOST . ':' . self::PORT,
                    'track' => self::HOST . ':' . self::PORT,
                ],
            ],
        ];
        if ($debugToken !== null) {
            $config['debugToken'] = $debugToken;
        }

        return new ApiManager(
            new Config($config),
            $this->createMock(EventManagerInterface::class),
            null,
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory
        );
    }

    private function queueExpConfigResponse(): void
    {
        $this->mockHttpClient->addResponse(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'data' => [
                'account_id' => '999',
                'project' => ['id' => '888'],
                'experiences' => [
                    ['id' => self::EXPERIENCE_ID, 'key' => 'preview-target', 'status' => 'draft', 'variations' => []],
                ],
            ],
        ])));
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function debugTokenProvider(): array
    {
        return [
            'no debugToken configured' => [null],
            'debugToken configured' => [self::DEBUG_TOKEN],
        ];
    }

    /**
     * AC4 (fetch contract): the exp= fetch always forces `exp={id}` and
     * `_conv_low_cache=1`, plus `debug_token=` only when configured — reusing
     * the same URL surface PHP-1 built for the debugToken config-fetch path.
     */
    #[DataProvider('debugTokenProvider')]
    public function testExpFetchUrlCarriesExperienceIdLowCacheAndOptionalDebugToken(?string $debugToken): void
    {
        $apiManager = $this->buildApiManager($debugToken);
        $this->queueExpConfigResponse();

        $apiManager->getConfigForExperience(self::EXPERIENCE_ID);

        $sentUri = (string) $this->mockHttpClient->getLastRequest()->getUri();

        $this->assertStringContainsString('exp=' . self::EXPERIENCE_ID, $sentUri);
        $this->assertStringContainsString('_conv_low_cache=1', $sentUri);

        if ($debugToken !== null) {
            $this->assertStringContainsString('debug_token=' . $debugToken, $sentUri);
        } else {
            $this->assertStringNotContainsString('debug_token=', $sentUri);
        }
    }

    #[Test]
    public function getConfigForExperienceReturnsParsedConfigResponseData(): void
    {
        $apiManager = $this->buildApiManager();
        $this->queueExpConfigResponse();

        $result = $apiManager->getConfigForExperience(self::EXPERIENCE_ID);

        $this->assertInstanceOf(ConfigResponseData::class, $result);
        $experiences = $result->getExperiences() ?? [];
        $this->assertNotEmpty($experiences, 'exp= fetch response must be parsed into ConfigResponseData with the injected experience');
        $this->assertSame(self::EXPERIENCE_ID, $experiences[0]['id'] ?? null);
    }
}
