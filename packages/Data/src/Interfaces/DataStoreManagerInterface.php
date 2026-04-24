<?php

declare(strict_types=1);

namespace ConvertSdk\Interfaces;

/**
 * DataStoreManagerInterface defines methods for interacting with a data store.
 *
 * @package ConvertSdk
 */
interface DataStoreManagerInterface
{
    /**
     * Set data for a specific key.
     *
     * @param string $key The key to store the data under.
     * @param mixed $data The data to store.
     */
    public function set(string $key, mixed $data): void;

    /**
     * Get data for a specific key.
     *
     * @param string $key The key to retrieve the data for.
     * @return mixed
     */
    public function get(string $key): mixed;
}
