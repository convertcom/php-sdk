<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

require_once __DIR__ . '/Support/MutualExclusionTestSupport.php';

use ConvertSdk\Tests\Support\MutualExclusionAudienceBuilder;
use ConvertSdk\Tests\Support\MutualExclusionDataManagerFactory;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\IdentityField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * qs-03 (mutual-exclusion audience rule, `bucketed_into_experience_key`) —
 * PHP-4, AC7 (generic-rule regression lock): "The 3 generic key/value rule
 * types behave bit-identically to today (full suite green + targeted
 * regression vectors if the resolution seam changed their path)."
 * (qs-03-mutual-exclusion-rule.md line 87.)
 *
 * The "3 generic key/value rule types" are the real served `rule_type`
 * literals defined by the generated OpenAPI models
 * packages/Types/lib/Generated/Model/Generic{Text,Numeric,Bool}KeyValueMatchRulesTypes.php:
 * `generic_text_key_value`, `generic_numeric_key_value`, `generic_bool_key_value`.
 * All three (plus a realistic mixed-shape example) already appear together in
 * production-shaped fixture data at tests/Integration/static-config.json's
 * "adv-audience"/"dasadsa" location entries — this suite mirrors those exact
 * (key, match_type, value-type) combinations rather than inventing new ones.
 *
 * The qs-03 PHP-3 seam under regression lock here is
 * DataManager::filterMatchedRecordsWithRule()'s new sole-rule collector
 * (`_findSoleBucketedIntoExperienceKeyRule()` / `_collectRuleElements()`,
 * introduced to route a SOLE `bucketed_into_experience_key` rule through a
 * synthetic single-key isRuleMatched() delegation — see
 * DataManager.php:1401-1449) and DataManager::matchRulesByField()'s widened
 * empty-visitorProperties gate (DataManager.php:407-425). Every rule tree in
 * this suite is generic-only (zero bucketed_into_experience_key elements), so
 * the collector must be on the call stack (whenever $visitorId is passed) but
 * ALWAYS take the else-branch — the original, unmodified
 * `isRuleMatched($visitorProperties, new RuleObject($item['rules']), ...)`
 * call that existed before PHP-3.
 *
 * ADDITIONAL to, not a replacement for, the pre-existing regression vector
 * tests/Integration/FullChainIntegrationTest.php::
 * testRunExperienceWithAudienceAndNoVisitorPropertiesReturnsNull (unmodified,
 * confirmed still green by PHP-3's own verification).
 */
final class MutualExclusionGenericRegressionTest extends TestCase
{
    /**
     * Item 1: generic key/value rules match identically through
     * DataManager::filterMatchedRecordsWithRule(), across the 3 real generic
     * rule_type literals, covering:
     *   (a) a matching case
     *   (b) a non-matching case
     *   (c) $visitorId explicitly passed (puts the sole-rule collector on the
     *       call stack)
     *   (d) $visitorId null/omitted (collector short-circuits without even
     *       being invoked — see DataManager.php:1413)
     * In every row the new $visitorId parameter must not alter the generic
     * matching outcome in either direction.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: mixed, 4: array<string, mixed>, 5: ?string, 6: bool}>
     */
    public static function genericRuleVectors(): array
    {
        return [
            // generic_text_key_value — mirrors static-config.json's "browser"
            // audience rule shape (string key/value, 'equals'-family comparator).
            'text_matches_visitorIdPassed' => [
                'generic_text_key_value', 'browser', 'equals', 'chrome',
                ['browser' => 'chrome'], 'mx-generic-visitor-1', true,
            ],
            'text_notMatches_visitorIdPassed' => [
                'generic_text_key_value', 'browser', 'equals', 'chrome',
                ['browser' => 'firefox'], 'mx-generic-visitor-1', false,
            ],
            // generic_numeric_key_value — mirrors static-config.json's "feature"
            // location rule shape (numeric key/value, 'less' comparator).
            'numeric_matches_visitorIdNull' => [
                'generic_numeric_key_value', 'feature', 'less', 5,
                ['feature' => 3], null, true,
            ],
            'numeric_notMatches_visitorIdNull' => [
                'generic_numeric_key_value', 'feature', 'less', 5,
                ['feature' => 9], null, false,
            ],
            // generic_bool_key_value — mirrors static-config.json's "desktop"
            // audience rule shape (real PHP bool key/value, 'equals' comparator).
            'bool_matches_visitorIdPassed' => [
                'generic_bool_key_value', 'desktop', 'equals', true,
                ['desktop' => true], 'mx-generic-visitor-2', true,
            ],
            'bool_notMatches_visitorIdOmitted' => [
                'generic_bool_key_value', 'desktop', 'equals', true,
                ['desktop' => false], null, false,
            ],
        ];
    }

    #[DataProvider('genericRuleVectors')]
    public function testGenericRuleTypeMatchesIdenticallyRegardlessOfVisitorIdParam(
        string $ruleType,
        string $key,
        string $matchType,
        mixed $ruleValue,
        array $visitorProperties,
        ?string $visitorId,
        bool $expectedMatched
    ): void {
        $item = self::singleRuleItem($ruleType, $key, $matchType, $ruleValue, negated: false);

        $built = MutualExclusionDataManagerFactory::build(MutualExclusionAudienceBuilder::loadBaseConfigData());
        $dataManager = $built['dataManager'];
        $ruleManager = $built['ruleManager'];

        $matched = $visitorId !== null
            ? $dataManager->filterMatchedRecordsWithRule([$item], $visitorProperties, 'audience', IdentityField::KEY, $visitorId)
            : $dataManager->filterMatchedRecordsWithRule([$item], $visitorProperties, 'audience', IdentityField::KEY);

        self::assertSame(
            $expectedMatched,
            $matched === [$item],
            sprintf(
                'Expected %s rule (%s %s %s) against visitorProperties=%s (visitorId=%s) to resolve matched=%s',
                $ruleType,
                $key,
                $matchType,
                var_export($ruleValue, true),
                json_encode($visitorProperties),
                $visitorId ?? 'null',
                $expectedMatched ? 'true' : 'false'
            )
        );

        self::assertCount(
            1,
            $ruleManager->calls,
            'A single-item, single-rule-element generic tree must invoke isRuleMatched() exactly once.'
        );
        self::assertSame(
            $visitorProperties,
            $ruleManager->calls[0]['data'],
            'A generic-only rule tree must route through isRuleMatched() with the ORIGINAL $visitorProperties '
                . 'unchanged — never the qs-03 synthetic single-key data pair — proving the else-branch (the '
                . 'original, untouched generic dispatch path) was taken regardless of whether $visitorId was passed.'
        );
    }

    /**
     * Item 2 — the critical AC7 counterpart to AC4: an experience whose ONLY
     * audience carries a generic rule (zero bucketed_into_experience_key
     * elements anywhere in its tree), evaluated with visitorProperties=[],
     * must still resolve to null/not-matched. DataManager.php:419's
     * `$hasBucketingExclusionAudience` must compute false here, so the widened
     * gate (`$visitorProperties || $hasBucketingExclusionAudience`,
     * DataManager.php:425) behaves EXACTLY as the pre-qs-03
     * `if ($visitorProperties)` gate did — audience evaluation must not even
     * be entered.
     */
    public function testGenericOnlyAudienceWithEmptyVisitorPropertiesStaysExcluded(): void
    {
        $configData = MutualExclusionAudienceBuilder::withGenericOnlyUnderTestExperience(
            MutualExclusionAudienceBuilder::loadBaseConfigData(),
            'plan',
            'enterprise'
        );

        $built = MutualExclusionDataManagerFactory::build($configData);
        $dataManager = $built['dataManager'];
        $ruleManager = $built['ruleManager'];

        $result = $dataManager->matchRulesByField(
            'mx-generic-only-empty-props-visitor',
            MutualExclusionAudienceBuilder::UNDER_TEST_EXPERIENCE_KEY,
            IdentityField::KEY,
            new BucketingAttributes([
                'visitorProperties' => [],
                'ignoreLocationProperties' => true,
            ])
        );

        self::assertNull(
            $result,
            'A generic-only audience with empty visitorProperties must still resolve to null — the qs-03 gate '
                . 'widening is scoped strictly to bucketed_into_experience_key audiences (AC7).'
        );

        self::assertSame(
            0,
            $ruleManager->isRuleMatchedCallCount,
            'isRuleMatched() must never be invoked for a generic-only audience when visitorProperties is empty — '
                . 'the :410-area gate must skip audience evaluation entirely, exactly as before qs-03.'
        );
    }

    /**
     * Item 3: a rule tree containing MORE THAN ONE rule element (two generic
     * rules combined under a single AND group) is never mistaken for a "sole
     * exclusion rule" — _findSoleBucketedIntoExperienceKeyRule()'s "exactly
     * one element total" guard (DataManager.php:1481-1488) falls through to
     * the untouched generic AND-walk dispatch regardless of element count.
     * Mirrors static-config.json's "homescreen" location rule shape (two
     * generic_text_key_value elements ANDed together).
     *
     * Constructing a real mixed generic+exclusion tree is out of scope per
     * qs-03's non-goals (no served config can yet emit one — see
     * qs-03-mutual-exclusion-rule.md Non-goals, and PHP-3's decision-log
     * "Assume a single bucketed_into_experience_key rule per exclusion
     * audience tree" entry); two generic rules is the documented acceptable
     * substitute, since it already exercises the ">1 element => not sole"
     * guard using existing generic rule types.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function twoElementVectors(): array
    {
        return [
            'bothMatch_andSatisfied' => [
                ['browser' => 'chrome', 'country' => 'US'], true,
            ],
            'oneMismatches_andFails' => [
                ['browser' => 'chrome', 'country' => 'DE'], false,
            ],
        ];
    }

    #[DataProvider('twoElementVectors')]
    public function testMultiElementGenericTreeNeverMistakenForSoleExclusionRule(
        array $visitorProperties,
        bool $expectedMatched
    ): void {
        $item = [
            'id' => 'multi-element-item',
            'key' => 'multi-element-item',
            'rules' => [
                'OR' => [
                    ['AND' => [
                        ['OR_WHEN' => [[
                            'rule_type' => 'generic_text_key_value',
                            'matching' => ['match_type' => 'equals', 'negated' => false],
                            'value' => 'chrome',
                            'key' => 'browser',
                        ]]],
                        ['OR_WHEN' => [[
                            'rule_type' => 'generic_text_key_value',
                            'matching' => ['match_type' => 'equals', 'negated' => false],
                            'value' => 'US',
                            'key' => 'country',
                        ]]],
                    ]],
                ],
            ],
        ];

        $built = MutualExclusionDataManagerFactory::build(MutualExclusionAudienceBuilder::loadBaseConfigData());
        $dataManager = $built['dataManager'];
        $ruleManager = $built['ruleManager'];

        $matched = $dataManager->filterMatchedRecordsWithRule(
            [$item],
            $visitorProperties,
            'audience',
            IdentityField::KEY,
            'mx-multi-element-visitor'
        );

        self::assertSame($expectedMatched, $matched === [$item]);

        self::assertCount(
            1,
            $ruleManager->calls,
            'A single item still results in exactly ONE top-level isRuleMatched() call, regardless of how many '
                . 'rule elements its tree contains — the AND walk happens inside the real, unmodified RuleManager.'
        );
        self::assertSame(
            $visitorProperties,
            $ruleManager->calls[0]['data'],
            'A >1-rule-element tree must route through isRuleMatched() with the ORIGINAL $visitorProperties — '
                . 'proving the sole-exclusion-rule guard correctly excludes multi-element trees from synthetic '
                . 'delegation, even though $visitorId was passed.'
        );
    }

    /**
     * Builds a single-rule-element OR/AND/OR_WHEN tree item, matching the
     * shape MutualExclusionAudienceBuilder::genericRuleElement() uses but
     * parameterized by rule_type/match_type/value-type so this single helper
     * covers all 3 generic rule_type literals under test (avoids copy-pasting
     * the tree shape per data-provider row — see
     * .claude/rules/sonarqube-new-code-duplication.md).
     *
     * @param mixed $ruleValue
     * @return array<string, mixed>
     */
    private static function singleRuleItem(string $ruleType, string $key, string $matchType, mixed $ruleValue, bool $negated): array
    {
        return [
            'id' => 'generic-item-under-test',
            'key' => 'generic-item-under-test',
            'rules' => [
                'OR' => [
                    ['AND' => [
                        ['OR_WHEN' => [[
                            'rule_type' => $ruleType,
                            'matching' => ['match_type' => $matchType, 'negated' => $negated],
                            'value' => $ruleValue,
                            'key' => $key,
                        ]]],
                    ]],
                ],
            ],
        ];
    }
}
