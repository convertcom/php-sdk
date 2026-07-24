<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

use ConvertSdk\Enums\Messages;
use ConvertSdk\Enums\RuleType;
use PHPUnit\Framework\TestCase;

/**
 * qs-03 (mutual-exclusion audience rule, `bucketed_into_experience_key`) — PHP-1.
 *
 * Covers the two Enums-package constants a later story (PHP-3) will consume
 * when implementing the rule-matching logic itself:
 *  - the AC8 unknown-target warning message (placeholder-bearing)
 *  - the `bucketed_into_experience_key` rule-type literal
 */
class MutualExclusionRuleConstantsTest extends TestCase
{
    public function testBucketingExclusionTargetNotFoundContainsPlaceholder(): void
    {
        $this->assertStringContainsString('#', Messages::BUCKETING_EXCLUSION_TARGET_NOT_FOUND);
    }

    public function testRuleTypeBucketedIntoExperienceKeyValue(): void
    {
        $this->assertSame('bucketed_into_experience_key', RuleType::BucketedIntoExperienceKey->value);
    }
}
