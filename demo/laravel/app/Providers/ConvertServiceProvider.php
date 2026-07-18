<?php

namespace App\Providers;

use ConvertSdk\ConvertSDK;
use ConvertSdk\Enums\LogLevel;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

class ConvertServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // [ConvertSDK] Register SDK as singleton — initialized once per application lifecycle
        $this->app->singleton('convert.sdk', function ($app) {
            $cache = new Psr16Cache(new FilesystemAdapter(
                namespace: 'convert_sdk',
                defaultLifetime: 3600,
                directory: storage_path('framework/cache/convert'),
            ));

            $sdkConfig = [
                'sdkKey' => config('convert.sdk_key'),       // [ConvertSDK]
                'cache' => $cache,                            // [ConvertSDK]
                'environment' => config('convert.environment'), // [ConvertSDK]
                'logger' => [                                  // [ConvertSDK]
                    'logLevel' => LogLevel::Trace,
                    'customLoggers' => [$app->make(LoggerInterface::class)],
                ],
            ];

            // [ConvertSDK] qs-16 QA capability — only pass debugToken when a non-empty
            // token is configured; passing null/empty would needlessly disable the
            // config cache (Core::fetchConfig() treats any non-empty string as "skip cache").
            $debugToken = config('convert.debug_token');
            if (is_string($debugToken) && $debugToken !== '') {
                $sdkConfig['debugToken'] = $debugToken; // [ConvertSDK]
            }

            return ConvertSDK::create($sdkConfig);
        });
    }
}
