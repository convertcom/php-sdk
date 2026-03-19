<?php

declare(strict_types=1);

namespace ConvertSdk;

use OpenAPI\Client\Config;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\ConfigSegment;
use OpenAPI\Client\Model\VisitorSegments;
use OpenAPI\Client\Model\RuleObject;
use OpenAPI\Client\StoreData;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Enums\SegmentsKeys;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Interfaces\LogManagerInterface;
use ConvertSdk\Interfaces\DataManagerInterface;
use ConvertSdk\Interfaces\RuleManagerInterface;
use ConvertSdk\Interfaces\SegmentsManagerInterface;
use ConvertSdk\Utils\ObjectUtils;

/**
 * Provides segments specific logic
 * @category Modules
 */
class SegmentsManager implements SegmentsManagerInterface
{
    /** @var ?ConfigResponseData */
    private ?ConfigResponseData $data;

    /** @var DataManagerInterface */
    private DataManagerInterface $dataManager;

    /** @var RuleManagerInterface */
    private RuleManagerInterface $ruleManager;

    /** @var LogManagerInterface|null */
    private ?LogManagerInterface $loggerManager;

    /**
     * SegmentsManager constructor.
     *
     * @param Config $config
     * @param DataManagerInterface $dataManager
     * @param RuleManagerInterface $ruleManager
     * @param LogManagerInterface|null $loggerManager
     */
    public function __construct(
        Config $config,
        DataManagerInterface $dataManager,
        RuleManagerInterface $ruleManager,
        ?LogManagerInterface $loggerManager = null
    ) {
        $this->dataManager = $dataManager;
        $this->ruleManager = $ruleManager;
        $this->loggerManager = $loggerManager;
        $this->data = $config ? $config->getData() : null;
    }

    /**
     * Get segments in DataStore
     *
     * @param string $visitorId
     * @return VisitorSegments
     */
    public function getSegments(string $visitorId): VisitorSegments
    {
        $storeData = $this->dataManager->getData($visitorId) ?? [];
        $storeData = (array)$storeData;
        $segments = $this->dataManager->filterReportSegments($storeData['segments'] ?? []);
        return new VisitorSegments($segments['segments'] ?? []);
    }

    /**
     * Update segments in DataStore
     *
     * @param string $visitorId
     * @param VisitorSegments $segments
     * @return void
     */
    public function putSegments(string $visitorId, ?array $segments): void
    {
        $reportSegments = $this->dataManager->filterReportSegments($segments);
        if ($reportSegments['segments'] ?? false) {
            $this->dataManager->putData($visitorId, ['segments' => $reportSegments['segments']]);
        }
    }

    /**
     * Set custom segments for a visitor
     *
     * @param string $visitorId
     * @param array<ConfigSegment> $segments
     * @param array|null $segmentRule
     * @return mixed VisitorSegments or RuleError
     */
    private function setCustomSegments(
        string $visitorId,
        array $segments,
        ?array $segmentRule = null
    ): VisitorSegments|RuleError|null {
        $storeData = $this->dataManager->getData($visitorId) ?? [];
        $visitorSegments = $storeData["segments"] ?? [];
        $customSegments = $visitorSegments["custom_segments"] ?? [];
        $segmentIds = [];
        $segmentsMatched = false;
    
        foreach ($segments as $segment) {
            if ($segmentRule && !$segmentsMatched) {
                $segmentsMatched = $this->ruleManager->isRuleMatched(
                    $segmentRule,
                    new RuleObject($segment['rules'] ?? []),
                    "ConfigSegment #{$segment['id']}"
                );
                if ($segmentsMatched instanceof RuleError) {
                    return $segmentsMatched;
                }
            }
    
            if (!$segmentRule || $segmentsMatched) {
                $segmentId = (string)$segment['id'];
                if (in_array($segmentId, $customSegments)) {
                    if ($this->loggerManager !== null) {
                        $this->loggerManager->warn(
                            'SegmentsManager.setCustomSegments()',
                            Messages::CUSTOM_SEGMENTS_KEY_FOUND
                        );
                    }
                } else {
                    $segmentIds[] = $segmentId;
                }
            }
        }
    
        if (!empty($segmentIds)) {
            $segmentsData = array_merge(
                json_decode(json_encode($visitorSegments), true),
                [SegmentsKeys::CustomSegments->value => array_merge($customSegments, $segmentIds)]
            );
            $this->putSegments($visitorId, $segmentsData);
            return new VisitorSegments($segmentsData);
        }
    
        return null;
    }

    /**
     * Update custom segments for specific visitor by segment keys
     *
     * @param string $visitorId
     * @param array<string> $segmentKeys A list of segment keys
     * @param array|null $segmentRule An object of key-value pairs for segments matching
     * @return mixed VisitorSegments or RuleError
     */
    public function selectCustomSegments(
        string $visitorId,
        array $segmentKeys,
        ?array $segmentRule = null
    ): VisitorSegments|RuleError|null {
        $segments = $this->dataManager->getEntities($segmentKeys, 'segments');
        return $this->setCustomSegments($visitorId, $segments, $segmentRule);
    }

    /**
     * Update custom segments for specific visitor by segment IDs
     *
     * @param string $visitorId
     * @param array<string> $segmentIds A list of segment IDs
     * @param array|null $segmentRule An object of key-value pairs for segments matching
     * @return mixed VisitorSegments or RuleError
     */
    public function selectCustomSegmentsByIds(
        string $visitorId,
        array $segmentIds,
        ?array $segmentRule = null
    ): VisitorSegments|RuleError|null {
        $segments = $this->dataManager->getEntitiesByIds($segmentIds, 'segments');
        return $this->setCustomSegments($visitorId, $segments, $segmentRule);
    }
}