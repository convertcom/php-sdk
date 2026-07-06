<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\Config;

use ConvertSdk\Core;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\ExperienceManagerInterface;
use ConvertSdk\Interfaces\FeatureManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Interfaces\SegmentsManagerInterface;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * qs-02 capability (A) `debugToken` — AC2 cache elimination.
 *
 * With `debugToken` set, `Core::fetchConfig()` must neither read nor write
 * the PSR-16 config cache entry: every request (i.e. every `Core` instance,
 * since PHP-FPM is stateless between requests) hits the (mocked) origin via
 * `ApiManagerInterface::getConfig()`.
 *
 * @see ../../../../../ai-driven-product-dev/_bmad-output/planning-artifacts/2026-03-13-convert-php-sdk/qs-02-experiment-preview.md
 */
class CoreDebugTokenCacheTest extends TestCase
{
    private const DEBUG_TOKEN = 'qa-debug-token-xyz789';

    private function validConfigData(): ConfigResponseData
    {
        return new ConfigResponseData([
            'account_id' => '10022898',
            'project' => ['id' => '10025986', 'name' => 'Test Project'],
        ]);
    }

    private function makeConfig(string $sdkKey, string $debugToken = self::DEBUG_TOKEN): Config
    {
        return new Config([
            'sdkKey' => $sdkKey,
            'debugToken' => $debugToken,
            'environment' => 'staging',
            'api' => [
                'endpoint' => [
                    'config' => 'http://cdn.example.com',
                    'track' => 'http://track.example.com',
                ],
            ],
        ]);
    }

    /**
     * @return array{
     *     dataManager: DataManagerInterface&\PHPUnit\Framework\MockObject\MockObject,
     *     eventManager: EventManagerInterface&\PHPUnit\Framework\MockObject\MockObject,
     *     experienceManager: ExperienceManagerInterface&\PHPUnit\Framework\MockObject\MockObject,
     *     featureManager: FeatureManagerInterface&\PHPUnit\Framework\MockObject\MockObject,
     *     segmentsManager: SegmentsManagerInterface&\PHPUnit\Framework\MockObject\MockObject,
     *     loggerManager: LogManagerInterface&\PHPUnit\Framework\MockObject\MockObject,
     * }
     */
    private function makeDependencies(): array
    {
        return [
            'dataManager' => $this->createMock(DataManagerInterface::class),
            'eventManager' => $this->createMock(EventManagerInterface::class),
            'experienceManager' => $this->createMock(ExperienceManagerInterface::class),
            'featureManager' => $this->createMock(FeatureManagerInterface::class),
            'segmentsManager' => $this->createMock(SegmentsManagerInterface::class),
            'loggerManager' => $this->createMock(LogManagerInterface::class),
        ];
    }

    /**
     * @param array{
     *     dataManager: DataManagerInterface,
     *     eventManager: EventManagerInterface,
     *     experienceManager: ExperienceManagerInterface,
     *     featureManager: FeatureManagerInterface,
     *     segmentsManager: SegmentsManagerInterface,
     *     loggerManager: LogManagerInterface,
     * } $deps
     */
    private function buildCore(Config $config, ApiManagerInterface $apiManager, CacheInterface $cache, array $deps): Core
    {
        return new Core(
            $config,
            $deps['dataManager'],
            $deps['eventManager'],
            $deps['experienceManager'],
            $deps['featureManager'],
            $deps['segmentsManager'],
            $apiManager,
            $cache,
            Core::DEFAULT_DATA_REFRESH_INTERVAL,
            $deps['loggerManager'],
        );
    }

    /**
     * A non-throwing counting spy around the PSR-16 cache contract.
     *
     * A PHPUnit mock configured with `expects($this->never())` would work
     * too, but `Core::initialize()` wraps `fetchConfig()` in a broad
     * `catch (\Exception $e)` — the mock's ExpectationFailedException would
     * be swallowed there and surface only as an indirect, confusing failure
     * downstream (e.g. "getConfig() expected once, called 0 times"). A
     * counting spy that never throws sidesteps that interaction and gives a
     * direct, unambiguous assertion on call counts after construction.
     */
    private function makeCountingCache(): CacheInterface
    {
        return new class () implements CacheInterface {
            public int $getCalls = 0;
            public int $setCalls = 0;
            public int $deleteCalls = 0;

            public function get(string $key, mixed $default = null): mixed
            {
                $this->getCalls++;
                return $default;
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $this->setCalls++;
                return true;
            }

            public function delete(string $key): bool
            {
                $this->deleteCalls++;
                return true;
            }

            public function clear(): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return false;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }
        };
    }

    /**
     * @param CacheInterface&object{getCalls: int, setCalls: int, deleteCalls: int} $cache
     */
    private function assertCacheNeverConsulted(CacheInterface $cache): void
    {
        $this->assertSame(0, $cache->getCalls, 'PSR-16 cache->get() must not be consulted when debugToken is set');
        $this->assertSame(0, $cache->setCalls, 'PSR-16 cache->set() must not be written to when debugToken is set');
        $this->assertSame(0, $cache->deleteCalls, 'PSR-16 cache->delete() must not be invoked when debugToken is set');
    }

    /**
     * AC2 — a single fetch with debugToken set must not touch the PSR-16
     * config cache entry at all (no get/set/delete), and must hit origin.
     */
    #[Test]
    public function debugTokenSkipsCacheReadAndWriteOnFetch(): void
    {
        $configData = $this->validConfigData();

        $apiManager = $this->createMock(ApiManagerInterface::class);
        $apiManager->expects($this->once())->method('getConfig')->willReturn($configData);

        $cache = $this->makeCountingCache();

        $deps = $this->makeDependencies();
        $deps['dataManager']->expects($this->once())->method('setConfigData')->with($configData);

        $config = $this->makeConfig('debug_key_1');

        $core = $this->buildCore($config, $apiManager, $cache, $deps);
        $this->assertInstanceOf(Core::class, $core);
        $this->assertCacheNeverConsulted($cache);
    }

    /**
     * AC2 — two sequential fetches (modelled as two separate `Core`
     * instances, since PHP-FPM tears down state between requests) must both
     * hit origin; the shared PSR-16 cache backend must never be consulted.
     */
    #[Test]
    public function debugTokenCausesEveryRequestToHitOriginAcrossSeparateCoreInstances(): void
    {
        $configData = $this->validConfigData();

        $apiManager = $this->createMock(ApiManagerInterface::class);
        $apiManager->expects($this->exactly(2))->method('getConfig')->willReturn($configData);

        $cache = $this->makeCountingCache();

        $config = $this->makeConfig('debug_key_2');

        $firstCore = $this->buildCore($config, $apiManager, $cache, $this->makeDependencies());
        $secondCore = $this->buildCore($config, $apiManager, $cache, $this->makeDependencies());

        $this->assertInstanceOf(Core::class, $firstCore);
        $this->assertInstanceOf(Core::class, $secondCore);
        $this->assertCacheNeverConsulted($cache);
    }
}
