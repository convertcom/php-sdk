<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\Preview;

use ConvertSdk\Preview\PreviewParam;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * qs-02 capability (B) preview input — AC9: the pure static `convert_preview`
 * link-param parser.
 *
 * Contract §2: "Canonical link format ... convert_preview={experienceId}.
 * {variationId} — dot-separated numeric ids, mirroring the web force-param
 * format `_conv_eforce={expId}.{varId}`. The SDK SHOULD ship a pure static
 * helper (e.g. PreviewParam::parse(string $value): ?array)."
 *
 * Decision (qs-02 decision log): the parsed pair is returned as an associative
 * array `['experienceId' => string, 'variationId' => string]` rather than a
 * positional tuple — the spec leaves the exact return shape open, and an
 * associative array reads directly into
 * `$context->setPreview($parsed['experienceId'], $parsed['variationId'])`
 * without a positional-index footgun.
 *
 * RED-phase note (qs-02 PHP-2, TDD RED): `ConvertSdk\Preview\PreviewParam` does
 * not exist yet. Every test below is expected to fail with
 * `Error: Class "ConvertSdk\Preview\PreviewParam" not found` until the PHP-2
 * GREEN implementation lands.
 *
 * @see ../../../../../ai-driven-product-dev/_bmad-output/planning-artifacts/2026-03-13-convert-php-sdk/qs-02-experiment-preview.md
 */
class PreviewParamTest extends TestCase
{
    #[Test]
    public function parseReturnsExperienceAndVariationIdPairForAWellFormedValue(): void
    {
        $result = PreviewParam::parse('123.456');

        $this->assertSame(['experienceId' => '123', 'variationId' => '456'], $result);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedValueProvider(): array
    {
        return [
            'no dot separator' => ['123456'],
            'too many dots' => ['123.456.789'],
            'non-numeric experienceId' => ['abc.456'],
            'non-numeric variationId' => ['123.abc'],
            'empty string' => [''],
            'empty experienceId segment' => ['.456'],
            'empty variationId segment' => ['123.'],
            'negative experienceId' => ['-123.456'],
            'whitespace around ids' => [' 123.456 '],
            'trailing garbage after variationId' => ['123.456abc'],
        ];
    }

    #[DataProvider('malformedValueProvider')]
    public function testParseReturnsNullForMalformedValue(string $value): void
    {
        $this->assertNull(PreviewParam::parse($value));
    }
}
