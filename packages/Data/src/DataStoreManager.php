<?php

declare(strict_types=1);

namespace ConvertSdk;

use ConvertSdk\Enums\ErrorMessages;
use ConvertSdk\Interfaces\DataStoreManagerInterface;
use ConvertSdk\Interfaces\LogManagerInterface;
use OpenAPI\Client\Config;

/**
 * DataStoreManager wraps a user-provided data store (any object with get/set methods)
 * and delegates persistence operations to it.
 */
final class DataStoreManager implements DataStoreManagerInterface
{
    private ?LogManagerInterface $loggerManager;
    private mixed $dataStore = null;

    /**
     * Constructor
     *
     * @param Config|null $config Optional configuration object.
     * @param array $dependencies
     */
    public function __construct(?Config $config = null, array $dependencies = [])
    {
        $this->loggerManager = $dependencies['loggerManager'] ?? null;

        // Use provided dataStore (invokes setDataStore())
        $this->setDataStore($dependencies['dataStore'] ?? $config->getDataStore());
    }

    /**
     * Stores data in the dataStore.
     *
     * @param string $key
     * @param mixed  $data
     */
    public function set(string $key, mixed $data): void
    {
        try {
            if ($this->dataStore !== null && method_exists($this->dataStore, 'set')) {
                $this->dataStore->set($key, $data);
            }
        } catch (\Exception $error) {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                $this->loggerManager->error('DataStoreManager.set()', ['error' => $error->getMessage()]);
            }
        }
    }

    /**
     * Retrieves data from the dataStore.
     *
     * @param string $key
     * @return mixed|null
     */
    public function get(string $key): mixed
    {
        try {
            if ($this->dataStore !== null && method_exists($this->dataStore, 'get')) {
                return $this->dataStore->get($key);
            }
        } catch (\Exception $error) {
            if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                $this->loggerManager->error('DataStoreManager.get()', ['error' => $error->getMessage()]);
            }
        }
        return null;
    }

    /**
     * Sets the dataStore.
     *
     * @param mixed $dataStore
     */
    public function setDataStore(mixed $dataStore): void
    {
        if ($dataStore) {
            if ($this->isValidDataStore($dataStore)) {
                $this->dataStore = $dataStore;
            } else {
                if ($this->loggerManager !== null && method_exists($this->loggerManager, 'error')) {
                    $this->loggerManager->error(
                        'DataStoreManager.dataStore.set()',
                        ErrorMessages::DATA_STORE_NOT_VALID
                    );
                }
            }
        }
    }

    /**
     * Gets the dataStore.
     *
     * @return mixed
     */
    public function getDataStore(): mixed
    {
        return $this->dataStore;
    }

    /**
     * Validates that the provided dataStore has both get and set methods.
     *
     * @param mixed $dataStore
     * @return bool
     */
    public function isValidDataStore(mixed $dataStore): bool
    {
        return is_object($dataStore) &&
               method_exists($dataStore, 'get') &&
               method_exists($dataStore, 'set');
    }
}
