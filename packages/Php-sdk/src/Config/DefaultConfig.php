<?php

declare(strict_types=1);

namespace ConvertSdk\Config;

use ConvertSdk\Enums\LogLevel;

final class DefaultConfig
{
    /**
     * Get the default configuration array.
     *
     * @return array
     */
    public static function getDefault(): array
    {
        return [
            'api' => [
                'endpoint' => [
                    'config' => getenv('CONFIG_ENDPOINT') ?: 'https://cdn-4.convertexperiments.com/api/v1',
                    'track'  => getenv('TRACK_ENDPOINT') ?: 'https://[project_id].metrics.convertexperiments.com/v1',
                ],
            ],
            'environment' => 'production',
            'bucketing' => [
                'max_traffic' => 10000,
                'hash_seed'   => 9999,
                'excludeExperienceIdHash' => false,
            ],
            'data' => [],
            'dataStore' => null, // Allows 3rd party data store to be passed.
            'dataRefreshInterval' => 300000, // in milliseconds (5 minutes)
            'events' => [
                'batch_size' => 10,
            ],
            'logger' => [
                'logLevel' => LogLevel::Debug,
                'customLoggers' => [], // Allows 3rd party loggers to be passed.
            ],
            'rules' => [
                'keys_case_sensitive' => true,
                'comparisonProcessor' => null, // Allows 3rd party comparison processor.
                'negation' => '!',
            ],
            'network' => [
                'tracking' => true,
                'cacheLevel' => 'default', // Can be set to 'low' for short-lived cache.
                'source' => 'php-sdk',
            ],
            'sdkKey' => '',
            'sdkKeySecret' => '',
        ];
    }
}
