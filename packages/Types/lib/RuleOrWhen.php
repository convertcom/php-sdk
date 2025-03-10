<?php
namespace OpenApi\Client;

use OpenAPI\Client\Model\RuleElement;

/**
 * Class RuleOrWhen
 *
 * Represents a set of rule elements for the OR_WHEN clause.
 *
 * Example:
 * {
 *   "OR_WHEN": [
 *     {
 *       "rule_type": "cookie",
 *       "matching": { "match_type": "matches", "negated": false },
 *       "value": "value1",
 *       "key": "varName1"
 *     },
 *     {
 *       "rule_type": "cookie",
 *       "matching": { "match_type": "matches", "negated": false },
 *       "value": "value2",
 *       "key": "varName2"
 *     }
 *   ]
 * }
 *
 * @package ConvertSdk\Types
 */
class RuleOrWhen
{
    /**
     * An array of RuleElement objects.
     *
     * @var RuleElement[]
     */
    private array $orWhen;

    /**
     * Constructor.
     *
     * @param RuleElement[] $orWhen Array of RuleElement instances.
     * @throws \InvalidArgumentException if any element is not an instance of RuleElement.
     */
    public function __construct(array $orWhen = [])
    {
        // foreach ($orWhen as $element) {
        //     if (!$element instanceof RuleElement) {
        //         throw new \InvalidArgumentException('Each element in OR_WHEN must be an instance of RuleElement.');
        //     }
        // }
        $this->orWhen = $orWhen;
    }

    /**
     * Gets the OR_WHEN rule elements.
     *
     * @return RuleElement[]
     */
    public function getOrWhen(): array
    {
        return $this->orWhen;
    }

    /**
     * Sets the OR_WHEN rule elements.
     *
     * @param RuleElement[] $orWhen Array of RuleElement instances.
     * @return self
     * @throws \InvalidArgumentException if any element is not an instance of RuleElement.
     */
    public function setOrWhen(array $orWhen): self
    {
        foreach ($orWhen as $element) {
            if (!$element instanceof RuleElement) {
                throw new \InvalidArgumentException('Each element in OR_WHEN must be an instance of RuleElement.');
            }
        }
        $this->orWhen = $orWhen;
        return $this;
    }
}

