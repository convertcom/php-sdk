<?php

declare(strict_types=1);

namespace ConvertSdk\Tests;

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
use PHPUnit\Framework\TestCase;

/**
 * Acceptance-criteria tests for the anchored bucketing layout (bucketing contract v12).
 *
 * Every test below drives DataManager::getBucketingById() — the real fresh-bucketing
 * entry point — through a fresh DataManager instance built from a literal experience
 * config (id/version/variations copied verbatim from tests/CrossSdk/cross-sdk-bucketing
 * -vectors.json, cited by vector index in each test's docblock for traceability). No test
 * reimplements the anchored/packed algorithm; each asserts on the SDK's own output.
 *
 * RED-phase note (qs-01, TDD RED): anchored-specific assertions (AC1's version>11 branch,
 * AC2's per-sliver admission at version 12, AC3, AC4, AC5, AC8's version-12 sub-case, AC9's
 * anchored not-bucketed sub-case) are EXPECTED to fail until BucketingManager / DataManager's
 * fresh-bucketing branch implement the version gate and anchored algorithm. Packed-path
 * assertions (AC1's version<=11/missing/non-numeric branches, AC6) are expected to pass now.
 *
 * Spec: _bmad-output/planning-artifacts/2026-03-13-convert-php-sdk/qs-01-anchored-bucketing-layout.md
 */
class AnchoredBucketingLayoutTest extends TestCase
{
    private const EXPERIENCE_ID = '900000001';

    /**
     * Builds a fresh DataManager wired with exactly one experience (id = EXPERIENCE_ID)
     * carrying the given $variations and $version. Fresh per call so no visitor ever
     * carries a stored decision across assertions unless the test deliberately calls
     * putData() on the returned instance first (see the AC8 test).
     *
     * @param array<int, array<string, mixed>> $variations
     */
    private function makeDataManager(array $variations, int|float|string|null $version, string $experienceId = self::EXPERIENCE_ID): DataManager
    {
        return new DataManager(
            new Config([
                'environment' => 'production',
                'data' => new ConfigResponseData([
                    'account_id' => 'test-account',
                    'project' => ['id' => 'test-project'],
                    'experiences' => [[
                        'id' => $experienceId,
                        'key' => $experienceId . '-key',
                        'name' => 'Anchored Bucketing AC Test Experience',
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
    }

    /**
     * @param array<int, array<string, mixed>> $variations
     */
    private function bucketFreshVisitor(
        array $variations,
        string $visitorId,
        int|float|string|null $version,
        string $experienceId = self::EXPERIENCE_ID
    ): array|RuleError|BucketingError|null {
        return $this->makeDataManager($variations, $version, $experienceId)->getBucketingById(
            $visitorId,
            $experienceId,
            new BucketingAttributes([
                'ignoreLocationProperties' => true,
                'enableTracking' => false,
            ])
        );
    }

    private function assertVariation(string $expectedVariationId, array|RuleError|BucketingError|null $result, string $message = ''): void
    {
        $this->assertIsArray($result, $message);
        $this->assertSame($expectedVariationId, $result['id'], $message);
    }

    private function assertNotBucketed(array|RuleError|BucketingError|null $result, string $message = ''): void
    {
        $this->assertSame(BucketingError::VariationNotDecided, $result, $message);
    }

    /**
     * Three equal-share running arms (O/V1/V2), each carrying $trafficAllocation. Covers
     * both the 15% (5/5/5) and 25% (8.333.../each) configs used throughout the AC2/AC3/AC6/
     * AC8 tests — only the per-arm share differs between call sites.
     *
     * @return array<int, array<string, mixed>>
     */
    private function thirds(float $trafficAllocation): array
    {
        return [
            ['id' => 'O', 'traffic_allocation' => $trafficAllocation, 'status' => 'running'],
            ['id' => 'V1', 'traffic_allocation' => $trafficAllocation, 'status' => 'running'],
            ['id' => 'V2', 'traffic_allocation' => $trafficAllocation, 'status' => 'running'],
        ];
    }

    /**
     * The O=10/V1=80/V2=10 config used across AC4/AC5/AC9. $statusOverrides maps a
     * variation id to a status override (e.g. ['V1' => 'stopped']); any id not present
     * defaults to 'running'.
     *
     * @param array<string, string> $statusOverrides
     * @return array<int, array<string, mixed>>
     */
    private function tenEightyTen(array $statusOverrides = []): array
    {
        $allocations = ['O' => 10, 'V1' => 80, 'V2' => 10];

        $variations = [];
        foreach ($allocations as $id => $trafficAllocation) {
            $variations[] = [
                'id' => $id,
                'traffic_allocation' => $trafficAllocation,
                'status' => $statusOverrides[$id] ?? 'running',
            ];
        }

        return $variations;
    }

    // --- AC1: gate branching ----------------------------------------------------------

    /**
     * Vectors #1 (v11 -> V1) and #19 (v12, IDENTICAL variations/visitor -> not bucketed).
     * Only the `version` field differs; only the routed layout should explain the
     * different outcome.
     */
    public function testAc1Version12RoutesToAnchoredAndVersion11RoutesToPacked(): void
    {
        $variations = $this->thirds(5);
        $visitorId = 'thirds-flip-V1-to-O-66'; // raw bucket value 601, per vector #1/#19

        $this->assertVariation('V1', $this->bucketFreshVisitor($variations, $visitorId, 11), 'version 11 (packed) must still select V1 per vector #1');
        $this->assertNotBucketed($this->bucketFreshVisitor($variations, $visitorId, 12), 'version 12 (anchored) must NOT bucket this visitor per vector #19');
    }

    /**
     * Vector #0 data (v11 -> O) reused at a missing and a non-numeric version: both must
     * behave exactly as version 11 (packed), per AC1's "inert-on-ship" guarantee.
     */
    public function testAc1MissingOrNonNumericVersionRoutesToPacked(): void
    {
        $variations = $this->thirds(5);
        $visitorId = 'thirds-core-O-1'; // raw bucket value 293, per vector #0

        $this->assertVariation('O', $this->bucketFreshVisitor($variations, $visitorId, null), 'missing version must route to packed (vector #0 outcome)');
        $this->assertVariation('O', $this->bucketFreshVisitor($variations, $visitorId, 'not-a-number'), 'non-numeric version must route to packed (vector #0 outcome)');
    }

    // --- AC2: raise is a superset -------------------------------------------------------

    /**
     * Vectors #13/#14, #15/#16, #17/#18: visitors already inside an arm at 15% (5/5/5)
     * keep the SAME arm at 25% (8.333.../each) under anchored.
     */
    public function testAc2RaiseKeepsAlreadyBucketedVisitorsInTheSameArm(): void
    {
        $fifteenPercent = $this->thirds(5);
        $twentyFivePercent = $this->thirds(8.333333333333334);

        $cases = [
            'thirds-core-O-1' => 'O',            // vectors #13/#14, raw value 293
            'thirds-anchored-V1-core-1' => 'V1',  // vectors #15/#16, raw value 3617
            'thirds-anchored-V2-core-24' => 'V2', // vectors #17/#18, raw value 6871
        ];

        foreach ($cases as $visitorId => $expectedArm) {
            $this->assertVariation($expectedArm, $this->bucketFreshVisitor($fifteenPercent, $visitorId, 12), "$visitorId must be in $expectedArm at 15%");
            $this->assertVariation($expectedArm, $this->bucketFreshVisitor($twentyFivePercent, $visitorId, 12), "$visitorId must STAY in $expectedArm at 25% (superset, AC2)");
        }
    }

    /**
     * Vectors #19/#20, #21/#22, #23/#24: visitors NOT bucketed at 15% are newly admitted
     * into the raised arm's growth sliver at 25% — without disturbing any other arm.
     */
    public function testAc2RaiseAdmitsNewVisitorsIntoTheGrowthSliverOnly(): void
    {
        $fifteenPercent = $this->thirds(5);
        $twentyFivePercent = $this->thirds(8.333333333333334);

        $cases = [
            'thirds-flip-V1-to-O-66' => 'O',        // vectors #19/#20, raw value 601
            'thirds-anchored-V1-sliver-15' => 'V1', // vectors #21/#22, raw value 3899
            'thirds-anchored-V2-sliver-14' => 'V2', // vectors #23/#24, raw value 7353
        ];

        foreach ($cases as $visitorId => $expectedArm) {
            $this->assertNotBucketed($this->bucketFreshVisitor($fifteenPercent, $visitorId, 12), "$visitorId must NOT be bucketed at 15%");
            $this->assertVariation($expectedArm, $this->bucketFreshVisitor($twentyFivePercent, $visitorId, 12), "$visitorId must be admitted into $expectedArm's growth sliver at 25%");
        }
    }

    // --- AC3: lower ejects evenly and never flips ---------------------------------------

    /**
     * Vectors #25/#26: a visitor that is idle (not bucketed) at 25% stays idle when
     * lowered to 15% — it is never incorrectly admitted or flipped into an arm.
     */
    public function testAc3LowerNeverFlipsAnIdleVisitorIntoAnArm(): void
    {
        $twentyFivePercent = $this->thirds(8.333333333333334);
        $fifteenPercent = $this->thirds(5);
        $visitorId = 'thirds-flip-V2-to-V1-5'; // raw bucket value 1213

        $this->assertNotBucketed($this->bucketFreshVisitor($twentyFivePercent, $visitorId, 12), 'must be idle at 25% per vector #26');
        $this->assertNotBucketed($this->bucketFreshVisitor($fifteenPercent, $visitorId, 12), 'must STILL be idle at 15% (never flips into an arm), per vector #25');
    }

    /**
     * Vectors #19-#24 read in the lowering direction: a visitor admitted at 25% is
     * ejected to not-bucketed at 15% — never reassigned to a different arm.
     */
    public function testAc3LowerEjectsAdmittedVisitorsWithoutReassigningThem(): void
    {
        $twentyFivePercent = $this->thirds(8.333333333333334);
        $fifteenPercent = $this->thirds(5);

        $visitorIds = ['thirds-flip-V1-to-O-66', 'thirds-anchored-V1-sliver-15', 'thirds-anchored-V2-sliver-14'];

        foreach ($visitorIds as $visitorId) {
            $atHighCoverage = $this->bucketFreshVisitor($twentyFivePercent, $visitorId, 12);
            $this->assertIsArray($atHighCoverage, "$visitorId must be bucketed at 25%");
            $this->assertNotBucketed($this->bucketFreshVisitor($fifteenPercent, $visitorId, 12), "$visitorId must be EJECTED (not reassigned) at 15%");
        }
    }

    // --- AC4: stops don't move anchors --------------------------------------------------

    /**
     * Vectors #31-#36: stopping V1 (ta preserved) must not affect O's or V2's anchors,
     * and must zero-width V1 itself (never selected while stopped).
     */
    public function testAc4StoppingOneArmDoesNotMoveOtherArmsAnchorsAndZeroWidthsTheStoppedArm(): void
    {
        $allRunning = $this->tenEightyTen();
        $v1Stopped = $this->tenEightyTen(['V1' => 'stopped']);

        // vectors #31/#32: O unaffected by V1's stop
        $this->assertVariation('O', $this->bucketFreshVisitor($allRunning, 'anchor-gate-visitor-106', 12));
        $this->assertVariation('O', $this->bucketFreshVisitor($v1Stopped, 'anchor-gate-visitor-106', 12), 'O must be byte-identical whether V1 runs or is stopped');

        // vectors #33/#34: V2's anchor (9000) is byte-identical whether V1 runs or is stopped
        $this->assertVariation('V2', $this->bucketFreshVisitor($allRunning, 'anchor-gate-visitor-162', 12));
        $this->assertVariation('V2', $this->bucketFreshVisitor($v1Stopped, 'anchor-gate-visitor-162', 12), "V2's anchor must not move when V1 stops");

        // vectors #35/#36: V1 itself becomes zero-width (not bucketed) once stopped, anchor preserved
        $this->assertVariation('V1', $this->bucketFreshVisitor($allRunning, 'anchor-gate-visitor-17', 12));
        $this->assertNotBucketed($this->bucketFreshVisitor($v1Stopped, 'anchor-gate-visitor-17', 12), 'stopped V1 keeps its weight/anchor but has zero width');
    }

    /**
     * Vectors #37-#39: an explicit traffic_allocation=0 arm (Z) is zero-width — never
     * treated as 100% default — and never perturbs its sibling arms' anchors.
     */
    public function testAc4ExplicitZeroTrafficAllocationIsNeverTreatedAs100Percent(): void
    {
        $variations = [
            ['id' => 'O', 'traffic_allocation' => 2, 'status' => 'running'],
            ['id' => 'V1', 'traffic_allocation' => 47, 'status' => 'running'],
            ['id' => 'Z', 'traffic_allocation' => 0, 'status' => 'running'],
            ['id' => 'V2', 'traffic_allocation' => 1, 'status' => 'running'],
        ];

        $this->assertVariation('O', $this->bucketFreshVisitor($variations, 'anchor-gate-visitor-106', 12));
        $this->assertVariation('V1', $this->bucketFreshVisitor($variations, 'anchor-gate-visitor-17', 12), "Z's explicit zero allocation must never be defaulted to 100 nor perturb V1's anchor");
        $this->assertVariation('V2', $this->bucketFreshVisitor($variations, 'anchor-gate-visitor-162', 12), 'Z must never be selected and must not shift V2\'s anchor');
    }

    // --- AC5: defaults & boundaries ------------------------------------------------------

    /**
     * Vectors #40, #42, #43, #44, #45: NaN/absent traffic_allocation defaults to a 100.0
     * weight (never zero, never excluded from the total).
     */
    public function testAc5MissingTrafficAllocationDefaultsToOneHundredWeight(): void
    {
        // vector #40: single arm, ta omitted -> full traffic space
        $this->assertVariation('DEFAULT', $this->bucketFreshVisitor(
            [['id' => 'DEFAULT', 'status' => 'running']],
            'nan-default-visitor',
            12
        ));

        // vectors #42/#43: B=5, A omitted (defaults to 100) - A's defaulted weight must not
        // swallow values that clearly belong inside B's own explicit band.
        $twoArms = [
            ['id' => 'B', 'traffic_allocation' => 5, 'status' => 'running'],
            ['id' => 'A', 'status' => 'running'],
        ];
        $this->assertVariation('B', $this->bucketFreshVisitor($twoArms, 'anchor-gate-visitor-106', 12), "B's own band must win for values inside it");
        $this->assertVariation('A', $this->bucketFreshVisitor($twoArms, 'anchor-gate-visitor-162', 12), "A's defaulted 100-weight band must cover the rest");

        // vectors #44/#45: single full-allocation arm is identical under v11 and v12
        $single = [['id' => 'ONLY', 'traffic_allocation' => 100, 'status' => 'running']];
        $this->assertVariation('ONLY', $this->bucketFreshVisitor($single, 'single-arm-visitor', 11));
        $this->assertVariation('ONLY', $this->bucketFreshVisitor($single, 'single-arm-visitor', 12));
    }

    /**
     * Vectors #57/#58: total weight <= 0 (all arms zero-allocation) is never bucketed,
     * regardless of visitor, under either layout.
     */
    public function testAc5TotalWeightZeroIsNeverBucketed(): void
    {
        $variations = [
            ['id' => 'A', 'traffic_allocation' => 0, 'status' => 'running'],
            ['id' => 'B', 'traffic_allocation' => 0, 'status' => 'stopped'],
        ];

        $this->assertNotBucketed($this->bucketFreshVisitor($variations, 'anchor-gate-visitor-106', 12), 'totalWeight <= 0 must never bucket (anchored)');
        $this->assertNotBucketed($this->bucketFreshVisitor($variations, 'anchor-gate-visitor-106', 11), 'totalWeight <= 0 must never bucket (packed)');
    }

    /**
     * Vectors #52-#56: an anchor is INCLUSIVE (`value == anchor` is IN) while the far edge
     * of a band is EXCLUSIVE (`value == anchor + width` is OUT, landing in the next arm).
     */
    public function testAc5BoundaryValuesAreInclusiveAtAnchorAndExclusiveAtAnchorPlusWidth(): void
    {
        $variations = $this->tenEightyTen();

        $this->assertVariation('O', $this->bucketFreshVisitor($variations, 'boundary-999-25207', 12), 'value 999 is just below V1\'s anchor (1000) -> stays in O');
        $this->assertVariation('V1', $this->bucketFreshVisitor($variations, 'boundary-1000-1145', 12), 'value 1000 EQUALS V1\'s anchor -> anchor is inclusive');
        $this->assertVariation('V1', $this->bucketFreshVisitor($variations, 'boundary-8999-359', 12), 'value 8999 is just below V2\'s anchor (9000) -> stays in V1');
        $this->assertVariation('V2', $this->bucketFreshVisitor($variations, 'boundary-9000-9598', 12), 'value 9000 EQUALS V2\'s anchor -> anchor is inclusive');
        $this->assertVariation('V2', $this->bucketFreshVisitor($variations, 'boundary-9999-5699', 12), 'value 9999 is the maximum representable traffic value, still inside V2');
    }

    // --- AC6: packed regression lock -----------------------------------------------------

    /**
     * Vectors #0, #3, #5, #7, #9, #11 (v11, unchanged packed table): the packed walk must
     * remain bit-identical for version <= 11 — this is expected to PASS right now (no src
     * change has been made).
     */
    public function testAc6PackedPathIsUnchangedForVersion11(): void
    {
        $fifteenPercent = $this->thirds(5);

        $cases = [
            'thirds-core-O-1' => 'O',                             // vector #0, raw value 293
            'thirds-flip-V1-to-O-66' => 'V1',                     // vector #1, raw value 601
            'thirds-flip-V2-to-V1-5' => 'V2',                     // vector #3, raw value 1213
            'thirds-stable-V1-77' => 'V1',                        // vector #5, raw value 877
        ];

        foreach ($cases as $visitorId => $expectedArm) {
            $this->assertVariation($expectedArm, $this->bucketFreshVisitor($fifteenPercent, $visitorId, 11), "packed v11 regression: $visitorId -> $expectedArm");
        }

        // vector #11: exceeds the 15% total allocation -> not bucketed under packed
        $this->assertNotBucketed($this->bucketFreshVisitor($fifteenPercent, 'thirds-idle-both-packed-3', 11));
    }

    // --- AC8: stored decision wins over both layouts --------------------------------------

    /**
     * A visitor with an existing stored decision must keep it regardless of whether the
     * experience routes to packed (version 11) or anchored (version 12) — even when a
     * fresh computation for that visitor would produce a DIFFERENT (or no) arm.
     */
    public function testAc8StoredDecisionWinsOverFreshComputationForBothLayouts(): void
    {
        $variations = $this->thirds(5);
        $visitorId = 'thirds-flip-V1-to-O-66'; // fresh compute: V1 at v11 (vector #1), not-bucketed at v12 (vector #19)

        foreach ([11, 12] as $version) {
            $dataManager = $this->makeDataManager($variations, $version);
            $dataManager->putData($visitorId, ['bucketing' => [self::EXPERIENCE_ID => 'V2']]);

            $result = $dataManager->getBucketingById(
                $visitorId,
                self::EXPERIENCE_ID,
                new BucketingAttributes(['ignoreLocationProperties' => true, 'enableTracking' => false])
            );

            $this->assertVariation('V2', $result, "stored decision must win over fresh computation at version $version");
        }
    }

    // --- AC9: no event/API drift ----------------------------------------------------------

    /**
     * The bucketed-variation array shape (keys) and the not-bucketed sentinel type must be
     * identical regardless of which layout (packed or anchored) produced the result.
     */
    public function testAc9ReturnShapeAndNotBucketedSentinelAreUnchangedRegardlessOfLayout(): void
    {
        $expectedKeys = [
            'experienceId', 'experienceName', 'experienceKey', 'bucketingAllocation',
            'id', 'name', 'key', 'traffic_allocation', 'status', 'changes',
        ];

        // 100%-total config: packed and anchored provably coincide (vectors #46/#47), so
        // this isolates the SHAPE assertion from any layout-correctness concern.
        $fullyAllocated = $this->tenEightyTen();

        foreach ([11, 12] as $version) {
            $result = $this->bucketFreshVisitor($fullyAllocated, 'anchor-gate-visitor-106', $version);
            $this->assertIsArray($result, "version $version must return a bucketed array for this fully-allocated config");
            $this->assertSame($expectedKeys, array_keys($result), "return shape must be identical regardless of layout (version $version)");
        }

        // Not-bucketed sentinel must stay BucketingError::VariationNotDecided under anchored too.
        $v1Stopped = $this->tenEightyTen(['V1' => 'stopped']);
        $this->assertNotBucketed(
            $this->bucketFreshVisitor($v1Stopped, 'anchor-gate-visitor-17', 12),
            'not-bucketed sentinel type must be unchanged under anchored (vector #36)'
        );
    }
}
