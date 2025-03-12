<?php

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
    public function set(string $key, $data): void;

    /**
     * Get data for a specific key.
     *
     * @param string $key The key to retrieve the data for.
     * @return mixed
     */
    public function get(string $key);

    /**
     * Enqueue data for a specific key.
     *
     * @param string $key The key to enqueue the data under.
     * @param mixed $data The data to enqueue.
     */
    public function enqueue(string $key, $data): void;

    /**
     * Release the queue with an optional reason.
     *
     * @param string|null $reason Optional reason for releasing the queue.
     * @return mixed
     */
    public function releaseQueue(?string $reason = null);

    /**
     * Stop the queue processing.
     */
    public function stopQueue(): void;

    /**
     * Start the queue processing.
     */
    public function startQueue(): void;
}
