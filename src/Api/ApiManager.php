<?php

namespace Convert\PhpSdk\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

class ApiClient
{
  private Client $client;
  private ?string $apiKey;
  private string $baseUrl = "https://api.convert.com/";

  public function __construct(?string $apiKey = null)
  {
    $this->apiKey = $apiKey;

    // Prevent making requests if no API key is provided
    if (empty($this->apiKey)) {
      echo "[Warning]: No API key provided. Running in offline mode.\n";
      return;
    }

    $this->client = new Client([
        'base_uri' => $this->baseUrl,
        'headers' => [
          'Authorization' => "Bearer {$this->apiKey}",
          'Content-Type' => 'application/json'
        ],
        'timeout' => 10.0,
    ]);
  }

  /**
   * Fetch the configuration from Convert API.
   */
  public function fetchConfig(): array
  {
    // Check if API key is missing
    if (empty($this->apiKey)) {
      return ['error' => 'API key is missing. Cannot fetch configuration.'];
    }

    try {
      $response = $this->client->get("config");
      return json_decode($response->getBody(), true);
    } catch (ConnectException $e) {
      return ['error' => 'Network issue: Unable to reach Convert API.'];
    } catch (RequestException $e) {
      return ['error' => 'Failed to fetch config: ' . $e->getMessage()];
    }
  }

  /**
   * Send an event to Convert API.
   */
  public function sendEvent(string $eventKey, array $eventData): array
  {
    // Check if API key is missing
    if (empty($this->apiKey)) {
      return ['error' => 'API key is missing. Cannot send event.'];
    }

    try {
        $response = $this->client->post("track", [
            'json' => [
              'event_key' => $eventKey,
              'data' => $eventData
            ]
        ]);
        return json_decode($response->getBody(), true);
    } catch (ConnectException $e) {
        return ['error' => 'Network issue: Unable to reach Convert API.'];
    } catch (RequestException $e) {
        return ['error' => 'Failed to send event: ' . $e->getMessage()];
    }
  }
}
