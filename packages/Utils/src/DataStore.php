<?php
namespace ConvertSdk\Utils;

/**
 * Mimics data-store.ts
 * A simple JSON file-based key-value store.
 */
class DataStore
{
    private $file;

    public function __construct(string $file)
    {
        $this->file = $file;
        try {
            if (!file_exists($this->file)) {
                file_put_contents($this->file, '{}');
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }
    }

    /**
     * Get value by key
     */
    public function get(string $key)
    {
        try {
            $contents = file_get_contents($this->file);
            $data = json_decode($contents, true);
            return $data[$key] ?? null;
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }
        return null;
    }

    /**
     * Store value by key
     */
    public function set(string $key, $value): void
    {
        try {
            $contents = file_get_contents($this->file);
            $data = json_decode($contents, true);
            $data[$key] = $value;
            file_put_contents($this->file, json_encode($data));
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }
    }

    /**
     * Delete value by key
     */
    public function delete(string $key): void
    {
        try {
            $contents = file_get_contents($this->file);
            $data = json_decode($contents, true);
            unset($data[$key]);
            file_put_contents($this->file, json_encode($data));
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }
    }
}
