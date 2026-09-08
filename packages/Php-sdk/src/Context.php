<?php

declare(strict_types=1);

/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright (c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk;

use ConvertSdk\DTO\BucketedFeature;
use ConvertSdk\DTO\BucketedVariation;
use ConvertSdk\DTO\ConversionAttributes;
use ConvertSdk\DTO\GoalData;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\EntityType;
use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Enums\FeatureStatus;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\SystemEvents;
use ConvertSdk\Event\Interfaces\EventManagerInterface;
use ConvertSdk\Exception\InvalidArgumentException;
use ConvertSdk\Interfaces\ApiManagerInterface;
use ConvertSdk\Interfaces\ContextInterface;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\ExperienceManagerInterface;
use ConvertSdk\Interfaces\FeatureManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Interfaces\SegmentsManagerInterface;
use ConvertSdk\Preview\PreviewResolver;
use ConvertSdk\Utils\ObjectUtils;
use OpenAPI\Client\BucketingAttributes;
use OpenAPI\Client\Config;
use Psr\SimpleCache\CacheInterface;

/**
 * Provides visitor context for running experiences, features, and tracking conversions.
 */
final class Context implements ContextInterface
{
    /** @var ?string */
    private ?string $environment;

    /** @var ?array<string, mixed> */
    private ?array $visitorProperties = null;

    /**
     * qs-02 capability (B) preview input — the resolved experience data for the
     * current preview target (set via {@see setPreview()}), or null when no
     * preview is active or the target could not be resolved (bad input →
     * inert, per contract §2). A non-null value here is the SOLE source of
     * truth for "is this context in preview mode" — it gates BOTH the forced
     * decision in {@see runExperience()} AND the zero-trace persistence
     * suppression forwarded to every DataManager call this context makes.
     *
     * @var array<string, mixed>|null
     */
    private ?array $previewExperience = null;

    /**
     * qs-02 capability (B) preview input — the pre-built forced decision for
     * {@see $previewExperience}, computed once in {@see setPreview()} via
     * {@see DataManagerInterface::buildPreviewDecision()} so a bad variationId
     * is caught eagerly (both experience and variation validity are decided
     * once, at setPreview() time — never re-derived per runExperience() call).
     *
     * @var array<string, mixed>|null
     */
    private ?array $previewDecision = null;

    /**
     * @param Config $config SDK configuration
     * @param string $visitorId Unique visitor identifier
     * @param EventManagerInterface $eventManager Event manager instance
     * @param ExperienceManagerInterface $experienceManager Experience manager instance
     * @param FeatureManagerInterface $featureManager Feature manager instance
     * @param DataManagerInterface $dataManager Data manager instance
     * @param SegmentsManagerInterface $segmentsManager Segments manager instance
     * @param ApiManagerInterface $apiManager API manager instance
     * @param LogManagerInterface|null $loggerManager Optional logger manager instance
     * @param array<string, mixed>|null $visitorAttributes Initial visitor attributes for targeting
     * @param CacheInterface|null $cache Optional PSR-16 cache, used for qs-02 preview-target
     *     memoization (`preview_{experienceId}`, 60s TTL) — never the normal config cache
     *
     * @throws InvalidArgumentException If visitorId is empty
     */
    public function __construct(
        private readonly Config $config,
        private readonly string $visitorId,
        private readonly EventManagerInterface $eventManager,
        private readonly ExperienceManagerInterface $experienceManager,
        private readonly FeatureManagerInterface $featureManager,
        private readonly DataManagerInterface $dataManager,
        private readonly SegmentsManagerInterface $segmentsManager,
        private readonly ApiManagerInterface $apiManager,
        private readonly ?LogManagerInterface $loggerManager = null,
        ?array $visitorAttributes = null,
        private readonly ?CacheInterface $cache = null,
    ) {
        if ($visitorId === '') {
            throw new InvalidArgumentException('Visitor ID must not be empty');
        }

        $this->environment = $config->getEnvironment() ?? null;

        if (!empty($visitorAttributes)) {
            $filtered = $this->dataManager->filterReportSegments($visitorAttributes);
            if (isset($filtered['properties'])) {
                $this->visitorProperties = $filtered['properties'];
            }
            $this->segmentsManager->putSegments($visitorId, $visitorAttributes);
        }
    }

    /**
     * qs-02 capability (B) preview input — force this context to decide a
     * specific variation for a specific experience, bypassing audiences,
     * segments, locations, the environment check, experience status, variation
     * status/traffic filters, stored decisions, and the bucketing hash.
     *
     * Resolution and variation validation both happen eagerly, here — not
     * lazily inside runExperience() — so "the context behaves fully normally"
     * on bad input (contract §2) is a single, context-wide decision rather
     * than a per-call fallback. When resolution succeeds, this context
     * becomes zero-trace for its ENTIRE lifetime (contract §2 "Zero-trace"):
     * every runExperience()/runExperiences()/trackConversion() call this
     * context makes afterwards suppresses visitor-state persistence and
     * tracking enqueues, not just calls targeting $experienceId.
     *
     * Single preview target per context — calling this again overwrites the
     * previous target (last-write-wins). Never leaks to other contexts: the
     * resolved state lives entirely on this Context instance, never on the
     * shared DataManager/ApiManager singletons.
     *
     * @param string $experienceId The experience id (numeric string)
     * @param string $variationId The variation id to force (numeric string)
     * @return void
     */
    public function setPreview(string $experienceId, string $variationId): void
    {
        $resolver = new PreviewResolver($this->dataManager, $this->apiManager, $this->cache, $this->loggerManager);
        $experienceData = $resolver->resolveExperience($experienceId);

        if ($experienceData === null) {
            $this->previewExperience = null;
            $this->previewDecision = null;
            return;
        }

        $decision = $this->dataManager->buildPreviewDecision($experienceData, $variationId);
        if ($decision === null) {
            // Inert on bad input (contract §2) — DataManager already warned.
            $this->previewExperience = null;
            $this->previewDecision = null;
            return;
        }

        $this->previewExperience = $experienceData;
        $this->previewDecision = $decision;
    }

    /**
     * Get variation from specific experience.
     *
     * @param string $experienceKey An experience's key that should be activated
     * @param BucketingAttributes|null $attributes Attributes for the visitor
     * @return BucketedVariation|null The bucketed variation DTO, or null for all non-success paths
     */
    public function runExperience(string $experienceKey, ?BucketingAttributes $attributes = null): ?BucketedVariation
    {
        if (empty($this->visitorId)) {
            $this->loggerManager?->error(
                'Context.runExperience()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return null;
        }

        // qs-02 capability (B) preview input — force the resolved decision when
        // this experience key is the active preview target on this context.
        // Remediation (post-qs-02): the previewed decision is forced, not a
        // real bucketing outcome — never notify consumer listeners for it.
        // Mirrors JS SDK's Context.runExperience(), which returns
        // getPreviewDecision() directly with no BUCKETING fire at all.
        if ($this->previewExperience !== null && ($this->previewExperience['key'] ?? null) === $experienceKey) {
            return $this->mapToBucketedVariationDto($this->previewDecision);
        }

        $visitorProperties = $this->getVisitorProperties($attributes?->getVisitorProperties());
        $forwardedData = $attributes ? get_object_vars($attributes) : [];
        $forwardedData['visitorProperties'] = $visitorProperties;
        $forwardedData['environment'] = $forwardedData['environment'] ?? $this->environment;
        // qs-02: zero-trace across the WHOLE context once a preview is active —
        // other experiences still decide normally, but never persist/track.
        if ($this->previewExperience !== null) {
            $forwardedData['suppressPersistence'] = true;
        }
        $result = $this->experienceManager->selectVariation(
            $this->visitorId,
            $experienceKey,
            new BucketingAttributes($forwardedData)
        );

        if ($result === null
            || $result instanceof RuleError
            || $result === BucketingError::VariationNotDecided
        ) {
            return null;
        }

        // Remediation (post-qs-02): suppress the in-process notification for
        // the WHOLE context while a preview is active — mirrors JS SDK's
        // `if (!this._preview)` gate, applied to every experience evaluated
        // on this context, not just the previewed one.
        if ($this->previewExperience === null) {
            $this->eventManager->fire(
                SystemEvents::Bucketing,
                [
                    'visitorId' => $this->visitorId,
                    'experienceKey' => $experienceKey,
                    'variationKey' => $result['key'] ?? null,
                ],
                null,
                true
            );
        }

        return $this->mapToBucketedVariationDto($result);
    }

    /**
     * Get variations across all experiences.
     *
     * @param BucketingAttributes|null $attributes Attributes for the visitor
     * @return BucketedVariation[] Array of bucketed variation DTOs
     */
    public function runExperiences(?BucketingAttributes $attributes = null): array
    {
        if (empty($this->visitorId)) {
            $this->loggerManager?->error(
                'Context.runExperiences()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return [];
        }

        $visitorProperties = $this->getVisitorProperties($attributes?->getVisitorProperties());
        $forwardedData = $attributes ? get_object_vars($attributes) : [];
        $forwardedData['visitorProperties'] = $visitorProperties;
        $forwardedData['environment'] = $forwardedData['environment'] ?? $this->environment;
        // qs-02: zero-trace across the WHOLE context once a preview is active.
        if ($this->previewExperience !== null) {
            $forwardedData['suppressPersistence'] = true;
        }

        $bucketedVariations = $this->experienceManager->selectVariations(
            $this->visitorId,
            new BucketingAttributes($forwardedData)
        );

        // qs-02 capability (B) contract §3 precedence — "preview forcing beats
        // stored decisions and normal bucketing for the target experience" is
        // method-agnostic, so the bulk method must honor it too, not just
        // runExperience(). Scoped to the in-config preview target: when the
        // target experience is present in config and would normally decide
        // (running, environment-matching — i.e. it already appears in
        // $bucketedVariations), replace that entry with the forced decision,
        // mirroring runExperience()'s short-circuit at ~line 184. An experience
        // absent from this bulk result (out-of-config-only via ?exp= fetch, or
        // blocked by a status/environment/traffic gate the preview would
        // otherwise bypass) is intentionally NOT injected here — replicating
        // runExperience()'s full gate bypass for the bulk method would require
        // restructuring how this method sources per-experience decisions, which
        // is out of scope for this fix (see qs-02 decision-audit remediation,
        // Defect 3 note in the PHP SDK decision log).
        if ($this->previewExperience !== null) {
            // Source the key from the decision actually built by
            // DataManager::buildPreviewDecision() (experienceKey ===
            // ConfigExperience::getKey()) rather than the raw config
            // experience — this is the authoritative key used to build the
            // forced decision. Guard against null/empty: without it, a
            // bucketed variation with a missing/null `experienceKey` would
            // spuriously match null === null and be overwritten with the
            // preview decision (mirrors the defensive idiom in
            // ApiManager::redactDebugTokenForLog()'s null/empty-token guard).
            $previewKey = $this->previewDecision['experienceKey'] ?? null;
            if ($previewKey !== null && $previewKey !== '') {
                foreach ($bucketedVariations as $index => $variation) {
                    if (is_array($variation) && ($variation['experienceKey'] ?? null) === $previewKey) {
                        $bucketedVariations[$index] = $this->previewDecision;
                        break;
                    }
                }
            }
        }

        $dtos = [];
        foreach ($bucketedVariations as $variation) {
            if (!is_array($variation)) {
                continue;
            }
            // Remediation (post-qs-02): same context-wide suppression as
            // runExperience() above — applies to every entry in the bulk
            // result, including the previewed experience's forced entry.
            if ($this->previewExperience === null) {
                $this->eventManager->fire(
                    SystemEvents::Bucketing,
                    [
                        'visitorId' => $this->visitorId,
                        'experienceKey' => $variation['experienceKey'] ?? null,
                        'variationKey' => $variation['key'] ?? null,
                    ],
                    null,
                    true
                );
            }
            $dtos[] = $this->mapToBucketedVariationDto($variation);
        }

        return $dtos;
    }

    /**
     * Get feature and its status.
     *
     * @param string $key A feature key
     * @param BucketingAttributes|null $attributes Attributes for the visitor
     * @return BucketedFeature|null The bucketed feature DTO, or null for not-found/error paths
     */
    public function runFeature(string $key, ?BucketingAttributes $attributes = null): ?BucketedFeature
    {
        if (empty($this->visitorId)) {
            $this->loggerManager?->error(
                'Context.runFeature()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return null;
        }

        $visitorProperties = $this->getVisitorProperties($attributes?->getVisitorProperties());

        $forwardedData = [
            'visitorProperties' => $visitorProperties,
            'locationProperties' => $attributes?->getLocationProperties(),
            'updateVisitorProperties' => $attributes?->getUpdateVisitorProperties(),
            'typeCasting' => $attributes !== null && method_exists($attributes, 'getTypeCasting')
                ? $attributes->getTypeCasting()
                : true,
            'environment' => $attributes?->getEnvironment() ?? $this->environment,
            'ignoreLocationProperties' => $attributes?->getIgnoreLocationProperties() ?? false,
        ];
        // qs-02: zero-trace across the WHOLE context once a preview is active —
        // runFeature() buckets every experience in config, not just a named one.
        if ($this->previewExperience !== null) {
            $forwardedData['suppressPersistence'] = true;
        }

        $result = $this->featureManager->runFeature(
            $this->visitorId,
            $key,
            new BucketingAttributes($forwardedData),
            $attributes?->getExperienceKeys()
        );

        // Determine if result is a single feature array or array of feature arrays
        // Single feature: has 'status' key directly; multi: indexed array of feature arrays
        if (isset($result['status'])) {
            // Feature not declared (no 'id') → return null per consumer contract
            if (!isset($result['id'])) {
                return null;
            }

            $dto = $this->mapToBucketedFeatureDto($result);

            // Fire event only for enabled features. Remediation (post-qs-02):
            // also suppressed context-wide while a preview is active.
            if ($dto->status === FeatureStatus::Enabled) {
                if ($this->previewExperience === null) {
                    $this->eventManager->fire(
                        SystemEvents::Bucketing,
                        [
                            'visitorId' => $this->visitorId,
                            'experienceKey' => $result['experienceKey'] ?? null,
                            'featureKey' => $key,
                            'status' => $result['status'] ?? null,
                        ],
                        null,
                        true
                    );
                }
            }

            return $dto;
        }

        // Array of feature arrays (multi-experience) — return first enabled one
        foreach ($result as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $dto = $this->mapToBucketedFeatureDto($feature);
            if ($dto->status === FeatureStatus::Enabled) {
                // Remediation (post-qs-02): suppressed context-wide while a
                // preview is active — the DTO is still returned regardless.
                if ($this->previewExperience === null) {
                    $this->eventManager->fire(
                        SystemEvents::Bucketing,
                        [
                            'visitorId' => $this->visitorId,
                            'experienceKey' => $feature['experienceKey'] ?? null,
                            'featureKey' => $key,
                            'status' => $feature['status'] ?? null,
                        ],
                        null,
                        true
                    );
                }
                return $dto;
            }
        }

        // No enabled features found — return first feature as disabled DTO
        $firstFeature = $result[0] ?? null;
        if (is_array($firstFeature)) {
            return $this->mapToBucketedFeatureDto($firstFeature);
        }

        return null;
    }

    /**
     * Get features and their statuses.
     *
     * @param BucketingAttributes|null $attributes Attributes for the visitor
     * @return BucketedFeature[] Array of bucketed feature DTOs
     */
    public function runFeatures(?BucketingAttributes $attributes = null): array
    {
        if (empty($this->visitorId)) {
            $this->loggerManager?->error(
                'Context.runFeatures()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return [];
        }

        $visitorProperties = $this->getVisitorProperties($attributes?->getVisitorProperties());

        $forwardedData = [
            'visitorProperties' => $visitorProperties,
            'locationProperties' => $attributes?->getLocationProperties(),
            'updateVisitorProperties' => $attributes?->getUpdateVisitorProperties(),
            'typeCasting' => $attributes !== null && method_exists($attributes, 'getTypeCasting')
                ? $attributes->getTypeCasting()
                : true,
            'environment' => $attributes?->getEnvironment() ?? $this->environment,
        ];
        // qs-02: zero-trace across the WHOLE context once a preview is active —
        // runFeatures() buckets every experience in config.
        if ($this->previewExperience !== null) {
            $forwardedData['suppressPersistence'] = true;
        }

        $bucketedFeatures = $this->featureManager->runFeatures($this->visitorId, new BucketingAttributes($forwardedData));

        // Filter out RuleError results
        $matchedErrors = array_filter($bucketedFeatures, function ($match) {
            return $match instanceof RuleError;
        });
        if (!empty($matchedErrors)) {
            return [];
        }

        $dtos = [];
        foreach ($bucketedFeatures as $feature) {
            if (!is_array($feature)) {
                continue;
            }

            $dto = $this->mapToBucketedFeatureDto($feature);

            // Fire event only for enabled features. Remediation (post-qs-02):
            // also suppressed context-wide while a preview is active.
            if ($dto->status === FeatureStatus::Enabled && $this->previewExperience === null) {
                $this->eventManager->fire(
                    SystemEvents::Bucketing,
                    [
                        'visitorId' => $this->visitorId,
                        'experienceKey' => $feature['experienceKey'] ?? null,
                        'featureKey' => $feature['key'] ?? null,
                        'status' => $feature['status'] ?? null,
                    ],
                    null,
                    true
                );
            }

            $dtos[] = $dto;
        }

        return $dtos;
    }

    /**
     * Trigger conversion tracking.
     *
     * @param string $goalKey A goal key
     * @param ConversionAttributes|null $attributes Conversion attributes
     * @return RuleError|bool|null RuleError on rule mismatch, false if goal not found or rule failed, null on success
     */
    public function trackConversion(string $goalKey, ?ConversionAttributes $attributes = null): RuleError|bool|null
    {
        if (empty($this->visitorId)) {
            $this->loggerManager?->error(
                'Context.trackConversion()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return false;
        }

        // Map DTO GoalData objects to plain arrays for DataManager serialization
        $conversionData = $attributes?->conversionData;
        if ($conversionData !== null) {
            $conversionData = array_map(
                fn ($item) => $item instanceof GoalData
                    ? ['key' => $item->key->value, 'value' => $item->value]
                    : $item,
                $conversionData
            );
        }

        $segments = $this->segmentsManager->getSegments($this->visitorId);
        $triggered = $this->dataManager->convert(
            $this->visitorId,
            $goalKey,
            $attributes?->ruleData,
            $conversionData,
            $segments,
            $attributes?->conversionSetting,
            // qs-02: zero-trace across the WHOLE context once a preview is active.
            $this->previewExperience !== null
        );

        if ($triggered instanceof RuleError) {
            return $triggered;
        }
        if ($triggered === false) {
            return false;
        }
        // Remediation (post-qs-02, found via sweep — not in the original
        // Bucketing/Location scope): DataManager::convert() returns `true`
        // regardless of $suppressPersistence (it only gates the goal-write
        // and the sendConversion()/sendTransaction() enqueues), so this fire
        // needs its own context-wide preview gate — the same defect class as
        // the Bucketing fires above.
        if ($triggered && $this->previewExperience === null) {
            $this->eventManager->fire(
                SystemEvents::Conversion,
                [
                    'visitorId' => $this->visitorId,
                    'goalKey' => $goalKey,
                ],
                null,
                true
            );
        }

        return null;
    }

    /**
     * Set default segments for reports.
     *
     * @param array<string, mixed> $segments Segment data
     * @return void
     */
    public function setDefaultSegments(array $segments): void
    {
        $this->segmentsManager->putSegments($this->visitorId, $segments);
    }

    /**
     * To be deprecated.
     *
     * @param array<int, string> $segmentKeys A list of segment keys
     * @param array<string, mixed>|null $attributes Segment attributes
     * @return array<int, mixed>|null
     */
    public function setCustomSegments(array $segmentKeys, ?array $attributes = null): ?array
    {
        return $this->runCustomSegments($segmentKeys, $attributes);
    }

    /**
     * Match custom segments.
     *
     * @param array<int, string> $segmentKeys A list of segment keys
     * @param array<string, mixed>|null $attributes Segment attributes
     * @return array<int, mixed>|null
     */
    public function runCustomSegments(array $segmentKeys, ?array $attributes = null): ?array
    {
        if (empty($this->visitorId)) {
            $this->loggerManager?->error(
                'Context.runCustomSegments()',
                ErrorMessages::VISITOR_ID_REQUIRED
            );
            return null;
        }
        $segmentsRule = $this->getVisitorProperties($attributes['ruleData'] ?? null);
        $result = $this->segmentsManager->selectCustomSegments(
            $this->visitorId,
            $segmentKeys,
            $segmentsRule
        );
        if ($result === null || $result instanceof RuleError) {
            return null;
        }
        return $result->getCustomSegments() ?: null;
    }

    /**
     * Update visitor properties in memory.
     *
     * @param string $visitorId The visitor ID
     * @param array<string, mixed> $visitorProperties Key-value pairs of visitor properties
     * @return void
     */
    public function updateVisitorProperties(string $visitorId, array $visitorProperties): void
    {
        $this->dataManager->putData($visitorId, ['segments' => $visitorProperties]);
    }

    /**
     * Set a single visitor attribute.
     *
     * @param string $key The attribute key
     * @param mixed $value The attribute value
     * @return void
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->visitorProperties = $this->visitorProperties ?? [];
        $this->visitorProperties[$key] = $value;
    }

    /**
     * Set multiple visitor attributes at once (merges with existing).
     *
     * @param array<string, mixed> $attributes Key-value pairs of attributes
     * @return void
     */
    public function setAttributes(array $attributes): void
    {
        $this->visitorProperties = array_merge($this->visitorProperties ?? [], $attributes);
    }

    /**
     * Get all current visitor attributes.
     *
     * @return array<string, mixed> The current visitor attributes
     */
    public function getAttributes(): array
    {
        return $this->visitorProperties ?? [];
    }

    /**
     * Get the visitor ID for this context.
     *
     * @return string The visitor ID
     */
    public function getVisitorId(): string
    {
        return $this->visitorId;
    }

    /**
     * Get config entity by key.
     *
     * @param string $key Entity key
     * @param string $entityType Entity type (EntityType value)
     * @return array<string, mixed> The entity data
     */
    public function getConfigEntity(string $key, string $entityType): array
    {
        if ($entityType === EntityType::Variation->value) {
            $experiences = $this->dataManager->getEntitiesList(EntityType::Experience->value);
            foreach ($experiences as $experience) {
                $variation = $this->dataManager->getSubItem(
                    'experiences',
                    $experience['key'],
                    'variations',
                    $key,
                    'key',
                    'key'
                );
                if ($variation) {
                    return $variation;
                }
            }
        }
        return $this->dataManager->getEntity($key, $entityType);
    }

    /**
     * Get config entity by ID.
     *
     * @param string $id Entity ID
     * @param string $entityType Entity type (EntityType value)
     * @return array<string, mixed> The entity data
     */
    public function getConfigEntityById(string $id, string $entityType): array
    {
        if ($entityType === EntityType::Variation->value) {
            $experiences = $this->dataManager->getEntitiesList(EntityType::Experience->value);
            foreach ($experiences as $experience) {
                $variation = $this->dataManager->getSubItem(
                    'experiences',
                    $experience['id'],
                    'variations',
                    $id,
                    'id',
                    'id'
                );
                if ($variation) {
                    return $variation;
                }
            }
        }
        return $this->dataManager->getEntityById($id, $entityType);
    }

    /**
     * Get visitor data.
     *
     * @return array<string, mixed> The visitor's stored data
     */
    public function getVisitorData(): array
    {
        return $this->dataManager->getData($this->visitorId) ?? [];
    }

    /**
     * Send pending API queue to server.
     *
     * @param string|null $reason Optional reason for releasing queues
     * @return void
     */
    public function releaseQueues(?string $reason = null): void
    {
        $this->apiManager->releaseQueue($reason);
    }

    /**
     * Get visitor properties merged with stored segments.
     *
     * @param array<string, mixed>|null $attributes Visitor attributes to merge
     * @return array<string, mixed> Merged visitor properties
     */
    private function getVisitorProperties(?array $attributes = null): array
    {
        $data = $this->dataManager->getData($this->visitorId);
        $segments = $data && $data['segments'] ? $data['segments'] : [];
        $segments = $segments ? $segments : [];
        $visitorProperties = $attributes
            ? ObjectUtils::objectDeepMerge($this->visitorProperties ?? [], $attributes)
            : $this->visitorProperties;
        return ObjectUtils::objectDeepMerge($segments, $visitorProperties ?? []);
    }

    /**
     * Map internal bucketed feature array to consumer-facing readonly DTO.
     *
     * @param array<string, mixed> $feature The internal bucketed feature array from FeatureManager
     * @return BucketedFeature The readonly consumer DTO
     */
    private function mapToBucketedFeatureDto(array $feature): BucketedFeature
    {
        return new BucketedFeature(
            featureId: (string) ($feature['id'] ?? ''),
            featureKey: (string) ($feature['key'] ?? ''),
            status: FeatureStatus::tryFrom($feature['status'] ?? 'disabled') ?? FeatureStatus::Disabled,
            variables: (array) ($feature['variables'] ?? []),
        );
    }

    /**
     * Map internal bucketed variation array to consumer-facing readonly DTO.
     *
     * @param array<string, mixed> $variation The internal bucketed variation array from DataManager
     * @return BucketedVariation The readonly consumer DTO
     */
    private function mapToBucketedVariationDto(array $variation): BucketedVariation
    {
        return new BucketedVariation(
            experienceId: (string) ($variation['experienceId'] ?? ''),
            experienceKey: (string) ($variation['experienceKey'] ?? ''),
            variationId: (string) ($variation['id'] ?? ''),
            variationKey: (string) ($variation['key'] ?? ''),
            changes: (array) ($variation['changes'] ?? []),
        );
    }
}
