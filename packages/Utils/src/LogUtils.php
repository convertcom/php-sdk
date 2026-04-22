<?php

declare(strict_types=1);

namespace ConvertSdk\Utils;

use DateTimeInterface;
use JsonSerializable;
use OpenAPI\Client\Model\ModelInterface;
use Traversable;

/**
 * Safe normalisation of log-context values.
 *
 * The PHP OpenAPI generator narrows polymorphic enum properties (e.g. RuleElement::rule_type)
 * to single-value enums. json_encode() on a graph containing such a model invokes
 * ModelInterface::jsonSerialize() -> ObjectSerializer::sanitizeForSerialization(), which throws
 * InvalidArgumentException for any real-world value outside the narrowed set. LogManager catches
 * the throw and substitutes "[log serialization error: …]" for the real payload.
 *
 * LogUtils::toLoggable() converts any OpenAPI ModelInterface into a plain associative array via
 * the model's own ::attributeMap() + ::getters() surface — bypassing ObjectSerializer entirely.
 * Recurses into arrays, Traversable and nested models. Scalars and null pass through unchanged.
 *
 * Note: OpenAPI-generated models are tree-shaped by construction (no cycles in spec schemas),
 * so no visited-set guard is required. If a future schema introduces a cycle, wrap $value in
 * a SplObjectStorage visited-set before calling this method.
 */
final class LogUtils
{
    public static function toLoggable(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::toLoggable($item);
            }
            return $out;
        }

        if ($value instanceof ModelInterface) {
            $out = [];
            $getters = $value::getters();
            foreach ($value::attributeMap() as $property => $serializedName) {
                $getter = $getters[$property] ?? null;
                if ($getter === null || !method_exists($value, $getter)) {
                    continue;
                }
                $out[$serializedName] = self::toLoggable($value->$getter());
            }
            return $out;
        }

        if ($value instanceof Traversable) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::toLoggable($item);
            }
            return $out;
        }

        if ($value instanceof JsonSerializable) {
            return self::toLoggable($value->jsonSerialize());
        }

        if (is_object($value)) {
            return self::toLoggable(get_object_vars($value));
        }

        return $value;
    }
}
