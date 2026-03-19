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

    /**
     * Enqueue data for a specific key.
     *
     * @param string $key The key to enqueue the data under.
     * @param mixed $data The data to enqueue.
     */
    public function enqueue(string $key, mixed $data): void;

    /**
     * Release the queue with an optional reason.
     *
     * @param string|null $reason Optional reason for releasing the queue.
     * @return void
     */
    public function releaseQueue(?string $reason = null): void;

    /**
     * Stop the queue processing.
     */
    public function stopQueue(): void;

    /**
     * Start the queue processing.
     */
    public function startQueue(): void;
}
