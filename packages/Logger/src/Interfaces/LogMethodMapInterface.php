<?php
/**
 * Convert Php SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Interfaces;

use ConvertSdk\Enums\LogMethod;

/**
 * In TypeScript, the LogMethodMapInterface is defined as a mapping type—essentially an object
 * that can have keys (based on the LogMethod enum) and corresponding string values (which are optional).
 * PHP doesn't support that exact pattern (index signatures) natively.
 * To simulate a similar "dictionary" or "map" behavior in PHP, the built-in ArrayAccess is extended
 * This requires any implementing class to define these four methods: offsetGet, offsetSet, offsetExists, and offsetUnset.
 * By doing this, any class that implements LogMethodMapInterface can be used like an array (e.g., $map[LogMethod::LOG] = 'logMethodName';)
 * and will enforce the expected behavior similar to the TypeScript interface. 
 */

interface LogMethodMapInterface extends \ArrayAccess {
    /**
     * Retrieve the mapped method name for the given log method.
     *
     * @param LogMethod|string $offset
     * @return string|null
     */
    public function offsetGet($offset);

    /**
     * Set the mapped method name for the given log method.
     *
     * @param LogMethod|string $offset
     * @param string|null $value
     * @return void
     */
    public function offsetSet($offset, $value): void;

    /**
     * Check if a mapping exists for the given log method.
     *
     * @param LogMethod|string $offset
     * @return bool
     */
    public function offsetExists($offset): bool;

    /**
     * Remove the mapping for the given log method.
     *
     * @param LogMethod|string $offset
     * @return void
     */
    public function offsetUnset($offset): void;
}
