<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

// MutualExclusionFixture.php is NOT reachable via Composer's PSR-4 autoload:
// the root composer.json maps `ConvertSdk\Tests\` only to `packages/Utils/tests/`
// (see composer.json:52 / vendor/composer/autoload_psr4.php), and PHPUnit's
// directory-based test collector only `require`s files matching `*Test.php`.
// A plain-named fixture class in this directory must be pulled in explicitly.
require_once __DIR__ . '/MutualExclusionFixture.php';

use PHPUnit\Framework\TestCase;

/**
 * Guards the qs-03 mutual-exclusion fixture itself (MutualExclusionFixture::rows())
 * against accidental drift from the contract table in
 * `qs-03-mutual-exclusion-rule.md` lines 66-77 — the fixture's expected values
 * ARE the contract, so this locks its shape/cardinality, not its semantics
 * (semantics are exercised by PHP-3's RuleManager/DataManager-facing tests).
 */
final class MutualExclusionFixtureTest extends TestCase
{
    public function testFixtureHasExactlyEightRows(): void
    {
        $this->assertCount(8, MutualExclusionFixture::rows());
    }

    public function testFixtureRowKeysAreUniqueAndDescriptive(): void
    {
        $keys = array_keys(MutualExclusionFixture::rows());
        $this->assertSame(array_unique($keys), $keys, 'Row keys must be unique dataset names');
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^row[1-8]_/', $key);
        }
    }

    public function testEachRowHasTheContractShape(): void
    {
        foreach (MutualExclusionFixture::rows() as $name => $row) {
            $this->assertCount(6, $row, "Row '$name' must have exactly 6 columns");
            [$bucketingMap, $ruleValue, $negated, $expectedMatched, $expectsWarning, $storeOnly] = $row;
            $this->assertIsArray($bucketingMap, "Row '$name' bucketingMap must be an array");
            $this->assertIsString($ruleValue, "Row '$name' ruleValue must be a string");
            $this->assertIsBool($negated, "Row '$name' negated must be a bool");
            $this->assertIsBool($expectedMatched, "Row '$name' expectedMatched must be a bool");
            $this->assertIsBool($expectsWarning, "Row '$name' expectsWarning must be a bool");
            $this->assertIsBool($storeOnly, "Row '$name' storeOnly must be a bool");
        }
    }

    public function testOnlyRowsSixAndSevenExpectAWarning(): void
    {
        $warnRows = array_keys(array_filter(
            MutualExclusionFixture::rows(),
            fn (array $row): bool => $row[4] === true
        ));

        $this->assertSame(
            ['row6_unknownTarget_notNegated', 'row7_unknownTarget_negated'],
            $warnRows,
            'AC8: only the unknown-target rows (6/7) expect a warning'
        );
    }

    public function testOnlyRowEightIsStoreOnly(): void
    {
        $storeOnlyRows = array_keys(array_filter(
            MutualExclusionFixture::rows(),
            fn (array $row): bool => $row[5] === true
        ));

        $this->assertSame(
            ['row8_storeOnlyBucketing_negated_expA'],
            $storeOnlyRows,
            'AC3: only row 8 requires persistent-store-only placement'
        );
    }

    public function testRowsSixAndSevenTargetAKeyAbsentFromTheFixtureConfig(): void
    {
        $config = json_decode(
            (string) file_get_contents(__DIR__ . '/mutual-exclusion-config.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $experienceKeys = array_column($config['data']['experiences'], 'key');

        $this->assertNotContains(MutualExclusionFixture::UNKNOWN_EXPERIENCE_KEY, $experienceKeys);
        $this->assertContains(MutualExclusionFixture::EXPERIENCE_A_KEY, $experienceKeys);
        $this->assertContains(MutualExclusionFixture::EXPERIENCE_B_KEY, $experienceKeys);
    }
}
