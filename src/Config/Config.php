<?php

namespace Convert\PhpSdk\Config;

class Config
{
  private array $configData = [];

  public function __construct()
  {
    echo "Config initialized!\n";
  }

  public function loadConfig(array $config): void
  {
    $this->configData = $config;
  }

  public function getConfig(): array
  {
    return $this->configData;
  }

  public function getExperimentConfig(string $experimentKey): ?array
  {
    foreach ($this->configData['experiments'] ?? [] as $experiment) {
      if ($experiment['key'] === $experimentKey) {
        return $experiment;
      }
    }
    return null;
  }
}
