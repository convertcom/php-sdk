<?php

namespace Convert\PhpSdk\Variation;

class Variation
{
  private string $key;
  private int $trafficAllocation;

  public function __construct(string $key, int $trafficAllocation)
  {
    $this->key = $key;
    $this->trafficAllocation = $trafficAllocation;
  }

  public function getKey(): string
  {
    return $this->key;
  }

  public function getTrafficAllocation(): int
  {
    return $this->trafficAllocation;
  }
}
