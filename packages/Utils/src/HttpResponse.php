<?php

declare(strict_types=1);
/**
 * Convert PHP SDK
 * Version 1.0.0
 * Copyright(c) 2020 Convert Insights, Inc
 * License Apache-2.0
 */

namespace ConvertSdk\Utils;

/**
 * HTTP Response Class
 */
class HttpResponse
{
    private mixed $data;
    private int $status;
    private string $statusText;
    private array $headers;

    /**
     * @param mixed $data Response data
     * @param int $status HTTP status code
     * @param string $statusText Status text
     * @param array $headers Response headers
     */
    public function __construct(mixed $data, int $status, string $statusText, array $headers = [])
    {
        $this->data = $data;
        $this->status = $status;
        $this->statusText = $statusText;
        $this->headers = $headers;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getStatusText(): string
    {
        return $this->statusText;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Convert to array representation
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'status' => $this->status,
            'statusText' => $this->statusText,
            'headers' => $this->headers
        ];
    }
}