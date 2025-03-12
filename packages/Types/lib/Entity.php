<?php
namespace OpenApi\Client;

use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\ConfigExperience;
use OpenAPI\Client\Model\ConfigFeature;
use OpenAPI\Client\Model\ConfigAudience;
use OpenAPI\Client\Model\ConfigLocation;
use OpenAPI\Client\Model\ConfigSegment;
use OpenAPI\Client\Model\ConfigGoal;
use OpenAPI\Client\Model\ExperienceVariationConfig;
use InvalidArgumentException;

class Entity
{
    private $entity;

    /**
     * EntityDTO constructor.
     * 
     * @param mixed $entity The entity to be assigned to this DTO.
     * 
     * @throws \InvalidArgumentException If the entity is not an instance of the allowed types.
     */
    public function __construct($entity)
    {
        // Check if the entity is an instance of any of the allowed types
        if (
            $entity instanceof ConfigExperience ||
            $entity instanceof ConfigFeature ||
            $entity instanceof ConfigAudience ||
            $entity instanceof ConfigLocation ||
            $entity instanceof ConfigSegment ||
            $entity instanceof ConfigGoal ||
            $entity instanceof ExperienceVariationConfig
        ) {
            $this->entity = $entity;
        } else {
            throw new \InvalidArgumentException('Invalid entity type provided');
        }
    }

    /**
     * Get the entity.
     * 
     * @return mixed The entity associated with this DTO.
     */
    public function getEntity()
    {
        return $this->entity;
    }
}
