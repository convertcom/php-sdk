<?php

namespace Convert\PhpSdk\Experiment;

use Convert\PhpSdk\Api\ApiClient;

class Experiment
{
  private ?ApiClient $apiClient;

  public function __construct(?ApiClient $apiClient = null)
  {
    if (!$apiClient) {
      echo "[Warning]: Running Experiment in offline mode.\n";
    }
    $this->apiClient = $apiClient;
  }

  public function getVariation(string $experimentKey, string $visitorId): string
  {
    if (!$this->apiClient) {
      echo "[Info]: No API client, returning default variation.\n";
      return "default";
    }

    $config = $this->apiClient->fetchConfig();
    if (!isset($config['experiments'][$experimentKey])) {
      echo "[Warning]: Experiment not found, using default.\n";
      return "default";
    }

    return "variation_A";
  }
}
