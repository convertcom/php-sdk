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
 * HTTP Request Class
 */
class HttpRequest
{
    private string $baseURL;
    private ?string $path;
    private ?string $method;
    private array $headers;
    private ?string $responseType;
    private array $data;

    /**
     * @param array $config Request configuration
     */
    public function __construct(array $config)
    {
        $this->baseURL = $config['baseURL'] ?? '';
        $this->path = $config['path'] ?? null;
        $this->method = $config['method'] ?? null;
        $this->headers = $config['headers'] ?? [];
        $this->responseType = $config['responseType'] ?? null;
        $this->data = $config['data'] ?? [];
    }

    public function getBaseURL(): string
    {
        return $this->baseURL;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getResponseType(): ?string
    {
        return $this->responseType;
    }

    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Convert to array representation
     *
     * @return array
     */
    public function toArray(): array
    {
        $result = ['baseURL' => $this->baseURL];
        if ($this->path !== null) $result['path'] = $this->path;
        if ($this->method !== null) $result['method'] = $this->method;
        if (!empty($this->headers)) $result['headers'] = $this->headers;
        if ($this->responseType !== null) $result['responseType'] = $this->responseType;
        if (!empty($this->data)) $result['data'] = $this->data;
        return $result;
    }
}