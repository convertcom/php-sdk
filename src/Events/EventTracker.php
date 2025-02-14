<?php

namespace Convert\PhpSdk\Events;

use Convert\PhpSdk\Api\ApiClient;

class EventTracker
{
  private ?ApiClient $apiClient;

  public function __construct(?ApiClient $apiClient = null)
  {
    if (!$apiClient) {
      echo "[Warning]: Running EventTracker in offline mode.\n";
    }
    $this->apiClient = $apiClient;
  }

  public function track(string $eventKey, array $eventData = []): void
  {
    if (!$this->apiClient) {
      echo "[Info]: Offline mode enabled, skipping event tracking for event: $eventKey\n";
      return;
    }

    $this->apiClient->sendEvent($eventKey, $eventData);
  }
}
