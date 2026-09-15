<?php

declare(strict_types=1);

namespace ConvertSdk\Tests\Support;

// Not PSR-4/classmap autoloadable — same constraint as MutualExclusionTestSupport.php
// (composer.json maps `ConvertSdk\Tests\` only to packages/Utils/tests/, and PHPUnit's
// directory collector only requires `*Test.php`). Consumers MUST `require_once` this
// file's absolute path before referencing any class below.

use ConvertSdk\Interfaces\ApiManagerInterface;
use OpenAPI\Client\Model\ConfigResponseData;
use OpenAPI\Client\Model\VisitorSegments;
use OpenAPI\Client\Model\VisitorTrackingEvents;

/**
 * Counts real enqueue() calls while delegating to a real ApiManagerInterface —
 * shared by ContextFeatureTrackingSuppressionTest and
 * ContextFeatureBucketingAttributesTest (CAP-1, SPEC-per-call-bucketing-attributes).
 */
final class FeaturePathCountingApiManager implements ApiManagerInterface
{
    public int $enqueueCalls = 0;

    public function __construct(private readonly ApiManagerInterface $inner)
    {
    }

    public function request(string $method, array $path, array $data = [], array $headers = []): array
    {
        return $this->inner->request($method, $path, $data, $headers);
    }

    public function enqueue(string $visitorId, VisitorTrackingEvents $eventRequest, ?VisitorSegments $segments = null): void
    {
        $this->enqueueCalls++;
        $this->inner->enqueue($visitorId, $eventRequest, $segments);
    }

    public function releaseQueue(?string $reason = null): void
    {
        $this->inner->releaseQueue($reason);
    }

    public function enableTracking(): void
    {
        $this->inner->enableTracking();
    }

    public function disableTracking(): void
    {
        $this->inner->disableTracking();
    }

    public function setData(ConfigResponseData $data): void
    {
        $this->inner->setData($data);
    }

    public function getConfig(): ConfigResponseData
    {
        return $this->inner->getConfig();
    }

    public function getConfigForExperience(string $experienceId): ConfigResponseData
    {
        return $this->inner->getConfigForExperience($experienceId);
    }
}

/** Minimal duck-typed visitor dataStore — DataManager only calls get()/set(). */
final class FeaturePathRecordingDataStore
{
    public int $setCalls = 0;

    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $data): void
    {
        $this->setCalls++;
        $this->data[$key] = $data;
    }
}
