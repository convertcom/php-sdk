<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\CrossSdk;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ConvertSdk\Utils\StringUtils;
use ConvertSdk\BucketingManager;

/**
 * Cross-SDK bucketing consistency tests.
 *
 * Validates that the full PHP bucketing pipeline (hash → normalize → bucket)
 * produces results consistent with JS SDK test vectors and formula.
 */
class BucketingConsistencyTest extends TestCase
{
    private const MAX_HASH = 4294967296;
    private const DEFAULT_MAX_TRAFFIC = 10000;

    private static array $vectors = [];

    public static function setUpBeforeClass(): void
    {
        $path = __DIR__ . '/test-vectors.json';
        self::$vectors = json_decode(file_get_contents($path), true);
    }

    public static function vectorProvider(): iterable
    {
        $path = __DIR__ . '/test-vectors.json';
        $vectors = json_decode(file_get_contents($path), true);

        foreach ($vectors as $vector) {
            $label = sprintf(
                '%s: "%s" seed=%d',
                $vector['category'],
                mb_substr($vector['input'], 0, 30),
                $vector['seed']
            );
            yield $label => [$vector['input'], $vector['seed'], $vector['expected']];
        }
    }

    #[DataProvider('vectorProvider')]
    public function testStringUtilsHashMatchesVector(string $input, int $seed, int $expected): void
    {
        $this->assertSame(
            $expected,
            StringUtils::generateHash($input, $seed),
            "Hash mismatch for input=\"$input\" seed=$seed"
        );
    }

    public static function bucketingPipelineProvider(): iterable
    {
        $path = __DIR__ . '/test-vectors.json';
        $vectors = json_decode(file_get_contents($path), true);

        // Use a subset of vectors for full pipeline testing:
        // pick vectors with default seed (9999) which is what bucketing uses
        foreach ($vectors as $vector) {
            if ($vector['seed'] !== 9999) {
                continue;
            }
            $label = sprintf('%s: "%s"', $vector['category'], mb_substr($vector['input'], 0, 30));
            yield $label => [$vector['input'], $vector['expected']];
        }
    }

    #[DataProvider('bucketingPipelineProvider')]
    public function testBucketingPipelineNormalization(string $input, int $expectedHash): void
    {
        $manager = new BucketingManager();

        // The full pipeline: hash → normalize to [0, maxTraffic)
        // Formula: intval((hash / MAX_HASH) * maxTraffic)
        $expectedNormalized = intval(($expectedHash / self::MAX_HASH) * self::DEFAULT_MAX_TRAFFIC);

        // Use input as both experienceId+visitorId concatenated
        // getValueVisitorBased concatenates experienceId + visitorId, so we pass
        // empty experienceId and input as visitorId to get hash of just input
        $actualNormalized = $manager->getValueVisitorBased($input, [
            'experienceId' => '',
            'seed' => 9999,
        ]);

        $this->assertSame(
            $expectedNormalized,
            $actualNormalized,
            "Normalized value mismatch for input=\"$input\""
        );
    }

    public function testFullBucketingPipelineWithKnownInputs(): void
    {
        $manager = new BucketingManager();

        // Known input from Dev Notes:
        // visitorId="visitor-456", experienceId="100234567", seed=9999
        // Step 1: concatenate → "100234567visitor-456"
        // Step 2: hash = StringUtils::generateHash("100234567visitor-456", 9999)
        $hash = StringUtils::generateHash('100234567visitor-456', 9999);
        $expectedNormalized = intval(($hash / self::MAX_HASH) * self::DEFAULT_MAX_TRAFFIC);

        $actualNormalized = $manager->getValueVisitorBased('visitor-456', [
            'experienceId' => '100234567',
            'seed' => 9999,
        ]);

        $this->assertSame($expectedNormalized, $actualNormalized);

        // Step 4: selectBucket maps normalized value to variation
        $buckets = [
            'var-A' => 50,
            'var-B' => 50,
        ];

        $result = $manager->getBucketForVisitor($buckets, 'visitor-456', [
            'experienceId' => '100234567',
        ]);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('variationId', $result);
        $this->assertArrayHasKey('bucketingAllocation', $result);
        $this->assertSame($actualNormalized, $result['bucketingAllocation']);
    }

    public function testBucketingDeterminismAcrossInstances(): void
    {
        // Create two separate BucketingManager instances
        $manager1 = new BucketingManager();
        $manager2 = new BucketingManager();

        $buckets = [
            'variation-1' => 33,
            'variation-2' => 33,
            'variation-3' => 34,
        ];

        // Same inputs must produce same results across instances
        for ($i = 0; $i < 100; $i++) {
            $visitorId = "visitor-$i";
            $result1 = $manager1->getBucketForVisitor($buckets, $visitorId, ['experienceId' => 'exp-1']);
            $result2 = $manager2->getBucketForVisitor($buckets, $visitorId, ['experienceId' => 'exp-1']);

            $this->assertSame($result1, $result2, "Mismatch for $visitorId across instances");
        }
    }
}
