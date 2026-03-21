<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\CrossSdk;

use ConvertSdk\Utils\StringUtils;
use lastguest\Murmur;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cross-SDK hash parity tests.
 *
 * Validates that the PHP MurmurHash3 implementation produces
 * identical output to the JS SDK for all test vectors.
 */
class HashParityTest extends TestCase
{
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

        foreach ($vectors as $i => $vector) {
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
    public function testLastguestMurmurMatchesJsSdk(string $input, int $seed, int $expected): void
    {
        $actual = Murmur::hash3_int($input, $seed);

        $this->assertSame(
            $expected,
            $actual,
            sprintf(
                'Murmur::hash3_int("%s", %d) returned %d, expected %d (JS SDK)',
                mb_substr($input, 0, 30),
                $seed,
                $actual,
                $expected
            )
        );
    }

    #[DataProvider('vectorProvider')]
    public function testStringUtilsGenerateHashMatchesJsSdk(string $input, int $seed, int $expected): void
    {
        $actual = StringUtils::generateHash($input, $seed);

        $this->assertSame(
            $expected,
            $actual,
            sprintf(
                'StringUtils::generateHash("%s", %d) returned %d, expected %d (JS SDK)',
                mb_substr($input, 0, 30),
                $seed,
                $actual,
                $expected
            )
        );
    }

    #[DataProvider('vectorProvider')]
    public function testNativePhpMurmur3aMatchesJsSdk(string $input, int $seed, int $expected): void
    {
        if (!in_array('murmur3a', hash_algos(), true)) {
            $this->markTestSkipped('Native murmur3a hash algorithm not available');
        }

        // StringUtils::generateHash() uses native PHP as the primary path,
        // so this MUST be a hard assertion — not informational.
        $actual = (int) hexdec(hash('murmur3a', $input, false, ['seed' => $seed]));

        $this->assertSame(
            $expected,
            $actual,
            sprintf(
                'Native hash("murmur3a", "%s", seed=%d) returned %d, expected %d (JS SDK)',
                mb_substr($input, 0, 30),
                $seed,
                $actual,
                $expected
            )
        );
    }

    public function testAllVectorsPresent(): void
    {
        $this->assertNotEmpty(self::$vectors, 'Test vectors file is empty');

        $categories = array_unique(array_column(self::$vectors, 'category'));
        $requiredCategories = ['ascii', 'unicode', 'empty', 'numeric', 'long'];

        foreach ($requiredCategories as $category) {
            $this->assertContains(
                $category,
                $categories,
                "Missing required category: $category"
            );
        }
    }

    public function testVectorCountMinimum(): void
    {
        // 15 inputs x 5 seeds = 75 vectors minimum
        $this->assertGreaterThanOrEqual(75, count(self::$vectors));
    }
}
