<?php
namespace OpenApi\Client;

use OpenAPI\Client\Model\RuleOrWhen;
use InvalidArgumentException;

/**
 * Class RuleAnd
 *
 * Represents a group of OR_WHEN groups combined with an AND.
 *
 * Example:
 * {
 *   "AND": [
 *     {
 *       "OR_WHEN": [ ... ]
 *     },
 *     {
 *       "OR_WHEN": [ ... ]
 *     }
 *   ]
 * }
 *
 * @package ConvertSdk\Types
 */
class RuleAnd
{
    /**
     * An array of RuleOrWhen objects.
     *
     * @var RuleOrWhen[]
     */
    private array $and;

    /**
     * Constructor.
     *
     * @param RuleOrWhen[] $and Array of RuleOrWhen instances.
     * @throws \InvalidArgumentException if any element is not an instance of RuleOrWhen.
     */
    public function __construct(array $and = [])
    {
        // foreach ($and as $orWhen) {
        //     if (!$orWhen instanceof RuleOrWhen) {
        //         throw new \InvalidArgumentException('Each element in AND must be an instance of RuleOrWhen.');
        //     }
        // }
        $this->and = $and;
    }

    /**
     * Gets the AND group.
     *
     * @return RuleOrWhen[]
     */
    public function getAnd(): array
    {
        return $this->and;
    }

    /**
     * Sets the AND group.
     *
     * @param RuleOrWhen[] $and Array of RuleOrWhen instances.
     * @return self
     * @throws \InvalidArgumentException if any element is not an instance of RuleOrWhen.
     */
    public function setAnd(array $and): self
    {
        foreach ($and as $orWhen) {
            if (!$orWhen instanceof RuleOrWhen) {
                throw new \InvalidArgumentException('Each element in AND must be an instance of RuleOrWhen.');
            }
        }
        $this->and = $and;
        return $this;
    }
}