<?php

namespace ConvertSdk\Experience;

use ConvertSdk\Data\DataManagerInterface;
use ConvertSdk\Logger\LogManagerInterface;
use ConvertSdk\Enums\BucketingError;
use ConvertSdk\Enums\RuleError;
use ConvertSdk\Enums\Messages;
use ConvertSdk\Config\Config;
use ConvertSdk\Interfaces\ExperienceManagerInterface;

class ExperienceManager implements ExperienceManagerInterface
{
    private $_dataManager;
    private $_loggerManager;

    /**
     * Constructor to initialize ExperienceManager with required dependencies.
     *
     * @param Config $config
     * @param array $dependencies
     * @param DataManagerInterface $dataManager
     * @param LogManagerInterface $loggerManager
     */
    public function __construct(
        Config $config,
        array $dependencies
    ) {
        $this->_dataManager = $dependencies['dataManager'];
        $this->_loggerManager = $dependencies['loggerManager'] ?? null;

        if ($this->_loggerManager) {
            $this->_loggerManager->trace('ExperienceManager()', Messages::EXPERIENCE_CONSTRUCTOR);
        }
    }

    /**
     * Get a list of all experiences
     *
     * @return array List of experiences
     */
    public function getList(): array
    {
        return $this->_dataManager->getEntitiesList('experiences');
    }

    /**
     * Get the experience by key
     *
     * @param string $key Experience key
     * @return mixed Experience data
     */
    public function getExperience(string $key)
    {
        return $this->_dataManager->getEntity($key, 'experiences');
    }

    /**
     * Get the experience by ID
     *
     * @param string $id Experience ID
     * @return mixed Experience data
     */
    public function getExperienceById(string $id)
    {
        return $this->_dataManager->getEntityById($id, 'experiences');
    }

    /**
     * Get experiences by a list of keys
     *
     * @param array $keys List of experience keys
     * @return array List of experiences
     */
    public function getExperiences(array $keys): array
    {
        return $this->_dataManager->getItemsByKeys($keys, 'experiences');
    }

    /**
     * Select a variation for a specific visitor
     *
     * @param string $visitorId Visitor ID
     * @param string $experienceKey Experience key
     * @param array $attributes Attributes for bucketing (no type)
     * @return mixed Selected variation or error
     */
    public function selectVariation(string $visitorId, string $experienceKey, array $attributes)
    {
        return $this->_dataManager->getBucketing($visitorId, $experienceKey, $attributes);
    }

    /**
     * Select a variation for a specific visitor using experience ID
     *
     * @param string $visitorId Visitor ID
     * @param string $experienceId Experience ID
     * @param array $attributes Attributes for bucketing (no type)
     * @return mixed Selected variation or error
     */
    public function selectVariationById(string $visitorId, string $experienceId, array $attributes)
    {
        return $this->_dataManager->getBucketingById($visitorId, $experienceId, $attributes);
    }

    /**
     * Select all variations across all experiences for a specific visitor
     *
     * @param string $visitorId Visitor ID
     * @param array $attributes Attributes for bucketing (no type)
     * @return array List of selected variations or errors
     */
    public function selectVariations(string $visitorId, array $attributes): array
    {
        return array_filter(
            array_map(
                function ($experience) use ($visitorId, $attributes) {
                    return $this->selectVariation($visitorId, $experience['key'], $attributes);
                },
                $this->getList()
            ),
            function ($variation) {
                return !in_array($variation, [RuleError::class, BucketingError::class], true);
            }
        );
    }

    /**
     * Get a variation by experience key and variation key
     *
     * @param string $experienceKey Experience key
     * @param string $variationKey Variation key
     * @return mixed Variation data
     */
    public function getVariation(string $experienceKey, string $variationKey)
    {
        return $this->_dataManager->getSubItem(
            'experiences',
            $experienceKey,
            'variations',
            $variationKey,
            'key',
            'key'
        );
    }

    /**
     * Get a variation by experience ID and variation ID
     *
     * @param string $experienceId Experience ID
     * @param string $variationId Variation ID
     * @return mixed Variation data
     */
    public function getVariationById(string $experienceId, string $variationId)
    {
        return $this->_dataManager->getSubItem(
            'experiences',
            $experienceId,
            'variations',
            $variationId,
            'id',
            'id'
        );
    }
}
