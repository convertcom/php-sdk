<?php

declare(strict_types=1);

namespace ConvertSdk\Config;

use ConvertSdk\Enums\LogLevel;
use ConvertSdk\Utils\ObjectUtils;

final class Config
{
    /**
     * Create and merge configuration settings.
     *
     * @param array $config Optional custom configuration.
     * @return array The merged configuration.
     */

    public static function create(array $config = []): array
    {
        $defaultLoggerSettings = [
            'logger' => [
                'logLevel' => LogLevel::Warn,
                'customLoggers' => []
            ]
        ];

        $defaultEnvironmentSettings = [
            'environment' => 'production'
        ];

        // Retrieve the default configuration.
        $defaultConfig = DefaultConfig::getDefault();

        // Merge all configuration arrays deeply.
        $configuration = ObjectUtils::objectDeepMerge(
            $defaultLoggerSettings,
            $defaultEnvironmentSettings,
            $defaultConfig,
            $config
        );

        return $configuration;
    }
}
