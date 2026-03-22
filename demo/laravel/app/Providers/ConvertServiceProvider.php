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

            return ConvertSDK::create([
                'sdkKey' => config('convert.sdk_key'),       // [ConvertSDK]
                'cache' => $cache,                            // [ConvertSDK]
                'environment' => config('convert.environment'), // [ConvertSDK]
                'logger' => [                                  // [ConvertSDK]
                    'logLevel' => LogLevel::Trace,
                    'customLoggers' => [$app->make(LoggerInterface::class)],
                ],
            ]);
        });
    }
}
