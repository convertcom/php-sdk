<?php
declare(strict_types=1);

use ConvertSdk\Api\ApiManager;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\FulfilledPromise;
use ConvertSdk\Event\EventManager;
use ConvertSdk\Enums\SystemEvents;

/**
 * TestableApiManager extends ApiManager to simulate responses and queue behavior
 * without requiring a real HTTP server.
 */
class TestableApiManager extends ApiManager
{
    public bool $released = false;
    public $releasedPayload = null;
    
    // Disable auto-timeout by default, so only batch release triggers.
    public bool $disableAutoTimeout = true;
    
    public function __construct(?array $config = null, array $dependencies = [])
    {
        parent::__construct($config, $dependencies);
        // Reinitialize trackingEvent using parent's protected properties.
        $this->trackingEvent = [
            'enrichData' => $this->enrichData,
            'accountId'  => $this->accountId,
            'projectId'  => $this->projectId,
            'visitors'   => []
        ];
    }
    
    // Override enqueue() to control auto-timeout behavior.
    public function enqueue(string $visitorId, array $eventRequest, ?array $segments = null): void
    {
        // Use the parent's pushQueue() method (which should be protected).
        $this->pushQueue($visitorId, $eventRequest, $segments);
        if ($this->trackingEnabled) {
            if ($this->disableAutoTimeout) {
                // Only trigger release if the queue length equals the batch size.
                if ($this->getQueueLength() === $this->batchSize) {
                    $this->releaseQueue('size');
                }
            } else {
                // Normal behavior (not used in this test).
                if ($this->getQueueLength() === $this->batchSize) {
                    $this->releaseQueue('size');
                } else {
                    if ($this->getQueueLength() === 1) {
                        $this->startQueue();
                    }
                }
            }
        }
    }
    
    // Override request() to simulate an HTTP response.
    public function request(string $method, array $path, array $data = [], array $headers = []): PromiseInterface
    {
        $response = [
            'data'       => $data,
            'status'     => 200,
            'statusText' => 'OK',
            'headers'    => [],
        ];
        return new FulfilledPromise($response);
    }
    
    // Override releaseQueue() to simulate immediate queue release and fire the event once.
    public function releaseQueue(?string $reason = null): ?PromiseInterface
    {
        // Mark the queue as released.
        $this->released = true;
        $payload = $this->trackingEvent;
        $payload['visitors'] = $this->requestsQueue['items'];
        $this->releasedPayload = $payload;
        // Fire the event (should be called exactly once).
        if ($this->eventManager && method_exists($this->eventManager, 'fire')) {
            $this->eventManager->fire(SystemEvents::API_QUEUE_RELEASED, [
                'reason'   => $reason,
                'visitors' => $payload['visitors']
            ]);
        }
        // Reset the queue so that no further releases occur.
        $this->resetQueue();
        return new FulfilledPromise(['data' => 'released']);
    }
    
    // Override startQueue() to immediately trigger a release.
    public function startQueue(): void
    {
        $this->releaseQueue('timeout');
    }
    
    // Helper method to reset the requests queue.
    protected function resetQueue(): void
    {
        $this->requestsQueue = ['length' => 0, 'items' => []];
    }
}
