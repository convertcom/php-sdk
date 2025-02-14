<?php

namespace Convert\PhpSdk;

use Convert\PhpSdk\Api\ApiClient;
use Convert\PhpSdk\Experiment\Experiment;
use Convert\PhpSdk\Events\EventTracker;

class Client
{
    private ?ApiClient $apiClient = null;
    private Experiment $experiment;
    private EventTracker $eventTracker;
    private bool $offlineMode = false;

    public function __construct(?string $apiKey = null)
    {
      if (!$apiKey) {
        echo "[Warning]: No API key provided. Running in offline mode.\n";
        $this->offlineMode = true;
      } else {
        $this->apiClient = new ApiClient($apiKey);
      }

      $this->experiment = new Experiment($this->apiClient);
      $this->eventTracker = new EventTracker($this->apiClient);
    }

    public function runExperiment(string $experimentKey, string $visitorId): string
    {
      return $this->experiment->getVariation($experimentKey, $visitorId);
    }

    public function trackEvent(string $eventKey, array $eventData = []): void
    {
      $this->eventTracker->track($eventKey, $eventData);
    }
}
