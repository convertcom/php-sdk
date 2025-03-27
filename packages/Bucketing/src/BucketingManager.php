<?php

namespace ConvertSdk;

use ConvertSdk\Interfaces\BucketingManagerInterface;
use ConvertSdk\Utils\StringUtils;
use ConvertSdk\Logger\LogManagerInterface;
use ConvertSdk\Enums\Messages;
use OpenAPI\Client\Config;

class BucketingManager implements BucketingManagerInterface
{
    private $_max_traffic = 10000;
    private $_hash_seed = 9999;
    private $_loggerManager;
    private const DEFAULT_MAX_HASH = 4294967296;

    /**
     * Constructor for BucketingManager
     *
     * @param array|null $config
     * @param array $dependencies
     * @param LogManagerInterface|null $dependencies['loggerManager']
     */
    public function __construct(?Config $config = null, $dependencies = [])
    {
        $this->_loggerManager = $dependencies['loggerManager'] ?? null;
    
        // Initialize max_traffic and hash_seed from config if available
        if ($config) {
            $bucketingConfig = $config->getBucketing();
            $this->_max_traffic = $bucketingConfig['max_traffic'] ?? $this->_max_traffic;
            $this->_hash_seed = $bucketingConfig['hash_seed'] ?? $this->_hash_seed;
        }
    
        if ($this->_loggerManager) {
            $this->_loggerManager->trace('BucketingManager()', Messages::BUCKETING_CONSTRUCTOR, $this);
        }
    }

    /**
     * Select variation based on its percentages and value provided.
     *
     * @param array $buckets Key-value array with variation IDs as keys and percentages as values.
     * @param float $value A bucket value.
     * @param float|null $redistribute Amount to redistribute (defaults to 0).
     * @return string|null
     */
    public function selectBucket(array $buckets, $value, $redistribute = 0)
    {
        $variation = null;
        $prev = 0;

        foreach ($buckets as $id => $percentage) {
            $prev += ($percentage * 100) + $redistribute;
            if ($value < $prev) {
                $variation = $id;
                break;
            }
        }

        if ($this->_loggerManager) {
            $this->_loggerManager->debug('BucketingManager.selectBucket()', [
                'buckets' => $buckets,
                'value' => $value,
                'redistribute' => $redistribute
            ], ['variation' => $variation]);
        }

        return $variation ?? null;
    }

    /**
     * Get a value based on hash from visitor ID to use for bucket selecting.
     *
     * @param string $visitorId
     * @param array|null $options
     * @param float|null $options['seed'] Optional custom seed.
     * @param string|null $options['experienceId'] Optional experience ID.
     * @return float
     */
    public function getValueVisitorBased($visitorId, $options = null)
    {
        $seed = $options['seed'] ?? $this->_hash_seed;
        $experienceId = $options['experienceId'] ?? '';
        $hash = StringUtils::GenerateHash($experienceId . strval($visitorId), $seed);
        $val = ($hash / self::DEFAULT_MAX_HASH) * $this->_max_traffic;
        $result = intval($val);

        if ($this->_loggerManager) {
            $this->_loggerManager->debug('BucketingManager.getValueVisitorBased()', [
                'visitorId' => $visitorId,
                'seed' => $seed,
                'experienceId' => $experienceId,
                'val' => $val,
                'result' => $result
            ]);
        }

        return $result;
    }

    /**
     * Get the bucket for the visitor.
     *
     * @param array $buckets Key-value array with variation IDs as keys and percentages as values.
     * @param string $visitorId
     * @param array|null $options
     * @param float|null $options['redistribute'] Optional redistribute value.
     * @param float|null $options['seed'] Optional custom seed.
     * @param string|null $options['experienceId'] Optional experience ID.
     * @return array|null
     */
    public function getBucketForVisitor(array $buckets, $visitorId, $options = null)
    {
        $value = $this->getValueVisitorBased($visitorId, $options);
        $selectedBucket = $this->selectBucket($buckets, $value, $options['redistribute'] ?? 0);

        if (!$selectedBucket) {
            return null;
        }

        return [
            'variationId' => $selectedBucket,
            'bucketingAllocation' => $value
        ];
    }
}
