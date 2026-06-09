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
 *
 * Belt-and-braces status (qs-13): this helper is STILL load-bearing today. On the current
 * generated types the discriminator bases (RuleElement::rule_type, RuleElementNoUrl::rule_type)
 * are still narrowed to single-value enums, so bypassing ObjectSerializer here is what prevents
 * the "Invalid value for enum" crash when logging real-world rule_type values. It becomes
 * redundant FOR THE CRASH only once the backend regenerates the discriminator bases to `string`
 * (qs-13 Option-A root-cause fix in backend PR #6340: the dist-php post-process retypes every
 * discriminator-base property to `string`, which lands them in ObjectSerializer's scalar
 * allowlist and makes the enum-validation throw structurally unreachable). Even after that, this
 * helper is retained as belt-and-braces: it keeps log serialization safe against any future
 * re-narrowing or newly-introduced discriminator enum, and yields plain-array log payloads that
 * never depend on the serializer's enum validation. Do not remove it.
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

        // JsonSerializable intent takes precedence over raw iteration for non-model objects
        // that explicitly declare how they want to be serialised.
        if ($value instanceof JsonSerializable) {
            return self::toLoggable($value->jsonSerialize());
        }

        if ($value instanceof Traversable) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::toLoggable($item);
            }
            return $out;
        }

        if (is_object($value)) {
            return self::toLoggable(get_object_vars($value));
        }

        return $value;
    }
}
