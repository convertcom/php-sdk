<?php

declare(strict_types=1);

namespace ConvertSdk\Exception;

class ConfigFetchException extends ConvertException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly string $url,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getUrl(): string
    {
        return $this->url;
    }
}
