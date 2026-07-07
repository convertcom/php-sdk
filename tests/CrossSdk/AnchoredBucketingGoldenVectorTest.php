<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\CrossSdk;

use ConvertSdk\BucketingManager;
use ConvertSdk\DataManager;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\RuleManagerInterface;
use ConvertSdk\LogManager;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden-vector parity gate for the anchored bucketing layout (bucketing contract v12).
 *
 * Consumes tests/CrossSdk/cross-sdk-bucketing-vectors.json verbatim (59 vectors, versions
 * {11 (packed), 12 (anchored)}), copied byte-identical from the JS SDK reference (qs-01
 * quick-spec). The fixture's `expected` values ARE the cross-SDK contract — this test never
 * recomputes them.
 *
 * Each vector is driven through the REAL DataManager::getBucketingById() fresh-bucketing
 * path (BucketingManager real hash math + DataManager's version-gated bucket selection),
 * not a parallel reimplementation of the anchored/packed algorithm. A vector fails here iff
 * this SDK's behavior diverges from the shared cross-SDK contract.
 *
 * RED-phase note (qs-01, TDD RED): anchored (version > 11) cases are EXPECTED to fail until
 * the anchored layout is implemented in BucketingManager / DataManager's fresh-bucketing
 * branch. Packed (version <= 11) cases are expected to pass unchanged (AC6 regression lock).
 *
 * Spec: _bmad-output/planning-artifacts/2026-03-13-convert-php-sdk/qs-01-anchored-bucketing-layout.md
 */
class AnchoredBucketingGoldenVectorTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/cross-sdk-bucketing-vectors.json';

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function vectorProvider(): iterable
    {
        $vectors = json_decode(file_get_contents(self::FIXTURE_PATH), true);

        foreach ($vectors as $index => $vector) {
            $label = sprintf(
                '#%d [v%d] %s',
                $index,
                $vector['version'],
                mb_substr($vector['description'], 0, 90)
            );
            yield $label => [$vector];
        }
    }

    /**
     * @param array{description: string, experienceId: string, visitorId: string, version: int|float, variations: array<int, array<string, mixed>>, expected: string|null} $vector
     */
    #[DataProvider('vectorProvider')]
    public function testGoldenVectorMatchesExpectedVariation(array $vector): void
    {
        $result = $this->bucketVisitor(
            $vector['variations'],
            $vector['visitorId'],
            $vector['version'],
            $vector['experienceId']
        );

        if ($vector['expected'] === null) {
            $this->assertSame(
                BucketingError::VariationNotDecided,
                $result,
                $vector['description']
            );
            return;
        }

        $this->assertIsArray($result, $vector['description']);
        $this->assertSame($vector['expected'], $result['id'], $vector['description']);
    }

    /**
     * Drives ONE vector through the real fresh-bucketing path: a brand-new DataManager per
     * vector (so no visitor ever carries a stored decision across rows — every row is an
     * independent "first encounter", matching how the fixture rows are authored), the real
     * BucketingManager (real MurmurHash3 + real bucket math, unmocked), and
     * DataManager::getBucketingById() — the actual production entry point that resolves a
     * visitor into a variation for an experience, including whatever version-gated
     * packed/anchored branch it contains.
     *
     * @param array<int, array<string, mixed>> $variations
     */
    private function bucketVisitor(
        array $variations,
        string $visitorId,
        int|float $version,
        string $experienceId
    ): array|RuleError|BucketingError|null {
        $dataManager = new DataManager(
            new Config([
                'environment' => 'production',
                'data' => new ConfigResponseData([
                    'account_id' => 'cross-sdk-test-account',
                    'project' => ['id' => 'cross-sdk-test-project'],
                    'experiences' => [[
                        'id' => $experienceId,
                        'key' => $experienceId . '-key',
                        'name' => 'Cross-SDK Anchored Bucketing Vector',
                        'version' => $version,
                        'variations' => $variations,
                    ]],
                ]),
            ]),
            new BucketingManager(),
            $this->createMock(RuleManagerInterface::class),
            $this->createMock(EventManagerInterface::class),
            $this->createMock(ApiManagerInterface::class),
            new LogManager()
        );

        return $dataManager->getBucketingById(
            $visitorId,
            $experienceId,
            new BucketingAttributes([
                'ignoreLocationProperties' => true,
                'enableTracking' => false,
            ])
        );
    }
}
