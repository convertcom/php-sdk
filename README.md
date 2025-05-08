# Laravel Project Setup with Convert PHP SDK Integration

This document provides a comprehensive guide to setting up a Laravel project. It explains how to integrate the Convert PHP SDK, configure middleware, and register service providers.

---

## Prerequisites

Before starting, ensure you have the following installed:

- **PHP**: Version 7.3 or higher
- **Composer**: Dependency manager for PHP

---

## Project Setup

### 1. Create a New Laravel Project

Run the following command to create a new Laravel project:

```bash
composer create-project laravel/laravel convert-sdk-demo
cd convert-sdk-demo
```

### 2. Add the Convert PHP SDK
Since the Convert PHP SDK is not published and is available as a local folder, you need to add it to your composer.json file. Update the repositories section to include the local packages folder:
```bash
"repositories": [
    { "type": "path", "url": "../php-sdk/packages/*" }
]
```
Then, require the SDK:
```bash
composer require convertcom/php-sdk:@dev
```

### 3. Install Dependencies
Install the required dependencies:
```bash
composer install
npm install
```

### 4. Configure Environment Variables
Update the .env file with your database credentials and Convert SDK configuration:
```bash
CONVERT_SDK_KEY= your-sdk-key
CONFIG_ENDPOINT=https://cdn-4.convertexperiments.com/api/v1/
TRACK_ENDPOINT=https://100416320.metrics.convertexperiments.com/v1/
```
Generate the application key:
```bash
php artisan key:generate
```

### Integrating the Convert PHP SDK
### 1. Create a Configuration File
Create a configuration file for the Convert SDK at `config/convert.php`:
```bash
<?php

return [
    'sdkKey' => env('CONVERT_SDK_KEY', env('CONVERT_SDK_KEY')),
    'logLevel' => 'DEBUG',
];
```

### 2. Create a Service Provider
Create a service provider to configure the Convert PHP SDK:

```bash
php artisan make:provider ConvertServiceProvider
```
Update the ConvertServiceProvider `(app/Providers/ConvertServiceProvider.php)`:
```bash
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use ConvertSdk\ConvertSDK;

class ConvertServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConvertSDK::class, function ($app) {
            $config = [
                'sdkKey' => config('convert.sdkKey'),
                'logger' => [
                    'logLevel' => config('convert.logLevel', 'DEBUG'),
                ],
            ];
            return new ConvertSDK($config);
        });
    }

    public function boot(): void
    {
        //
    }
}
```

### 3. Register the Service Provider
Add the ConvertServiceProvider to the providers array in `config/app.php`:
```bash
<?php
'providers' => [
    // Other service providers...
    App\Providers\ConvertServiceProvider::class,
],
```

### Adding Middleware
### 1. Create a middleware to initialize the Convert SDK context:
```bash
php artisan make:middleware ConvertContext
```
Update the ConvertContext middleware `(app/Http/Middleware/ConvertContext.php)`:
```bash
<?php

namespace App\Http\Middleware;

use Closure;
use ConvertSdk\ConvertSDK;
use Illuminate\Http\Request;
use App\Services\DataStore;

class ConvertContext
{
    protected $sdk;
    protected $dataStore;

    public function __construct(ConvertSDK $sdk, DataStore $dataStore)
    {
        $this->sdk = $sdk;
        $this->dataStore = $dataStore;
    }

    public function handle(Request $request, Closure $next)
    {
        $this->dataStore->setResponse($request);

        if ($this->dataStore->getDriver() === 'cookie' && empty($this->dataStore->get())) {
            $this->dataStore->setData($request->cookies->all());
        }

        $visitorId = $request->cookie('visitorId') ?? time() . '-' . microtime(true);
        $this->dataStore->set('visitorId', $visitorId);

        try {
            $this->sdk->onReady()->wait();
            $context = $this->sdk->createContext($visitorId, ['mobile' => true]);
            $context->setDefaultSegments(['country' => 'US']);
            $request->attributes->add(['sdkContext' => $context]);
        } catch (\Exception $e) {
            \Log::error('SDK Error: ' . $e->getMessage());
        }

        return $next($request);
    }
}
```

### 2. Register Middleware
Add the middleware to the global middleware stack in `app/Http/Kernel.php`:
```bash
<?php
protected $middleware = [
    // Other middleware...
    \App\Http\Middleware\ConvertContext::class,
];
```

### Adding a Custom Data Store
If you need to use a custom data store for the SDK, create a service class like `app/Services/DataStore.php`:

```bash
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\File;

class DataStore
{
    protected $driver;
    protected $response;
    protected $expire;
    protected $store;
    protected $data = [];

    public function __construct($options = [])
    {
        $this->driver = $options['driver'] ?? 'cookie';
        $this->expire = $options['expire'] ?? 360000;
        $this->store = $options['store'] ?? storage_path('app/demo.json');

        if ($this->driver === 'fs' && !File::exists($this->store)) {
            File::put($this->store, '{}');
        }
    }

    public function getDriver()
    {
        return $this->driver;
    }

    public function getData()
    {
        return $this->data;
    }

    public function setData($data)
    {
        $this->data = $data;
    }

    public function setResponse($response)
    {
        $this->response = $response;
    }

    public function get($key = null)
    {
        if ($this->driver === 'fs') {
            $this->data = json_decode(File::get($this->store), true) ?? [];
        }
        return $key === null ? $this->data : ($this->data[$key] ?? null);
    }

    public function set($key, $value)
    {
        if (!$key) {
            throw new \Exception('Invalid DataStore key!');
        }
        $this->data[$key] = $value;
        if ($this->driver === 'fs') {
            File::put($this->store, json_encode($this->data));
        } else {
            Cookie::queue($key, $value, $this->expire / 60000);
        }
    }
}
```

Register the DataStore service in `AppServiceProvider.php`:

```bash
<?php
$this->app->singleton(DataStore::class, function ($app) {
    return new DataStore([
        'driver' => 'cookie',
        'expire' => 360000,
        'store' => storage_path('app/demo.json'),
    ]);
});
```

### Using the Convert PHP SDK in Controllers
You can access the SDK context in your controllers via the request attributes. For example:
create `HomeController` in controllers/
### HomeController
```bash
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use OpenAPI\Client\BucketingAttributes;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $context = $request->attributes->get('sdkContext');

        $experienceKey = 'test-exp';
        $featureKey = 'test-feature';
        $attributes = new BucketingAttributes(['locationProperties' => ['screen' => 'home']]);
        $variation = $context->runExperience($experienceKey, $attributes);
        $feature = $context->runFeature($featureKey, $attributes);

        $content = $variation && end($variation)['key'] === 'original' ? 'Welcome (Original)' : 'Welcome (Variation)';
        $featureEnabled = $feature && $feature['status'] === 'enabled';
        $featureVariable = $featureEnabled ? ($feature['variables']['test'] ?? 'default') : 'default';

        return view('home', compact('content', 'featureEnabled', 'featureVariable'));
    }
}
```

Also add its view file `views/home.blad.php`:
```bash
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Home</title>
</head>
<body>
    <h1>{{ $content }}</h1>
    @if ($featureEnabled)
        <p>Feature enabled with value: {{ $featureVariable }}</p>
    @else
        <p>Feature disabled</p>
    @endif
    <form action="/thankyou" method="get">
        <button type="submit">Submit</button>
    </form>
</body>
</html>
```

### ThankYou Controller
create thank you controller to track conversion:
```bash
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ThankYouController extends Controller
{
    public function index(Request $request)
    {
        $context = $request->attributes->get('sdkContext');
        $context->trackConversion('thank-you-screen', ['ruleData' => ['screen' => 'thank-you']]);
        return view('thankyou');
    }
}
```
and its view file `views/thankyou.blade.php: 
```
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Thank You</title>
</head>
<body>
    <h1>Thank You!</h1>
    <p>Your action has been recorded.</p>
</body>
</html>
```

### Running the Application
1. Start the development server:
```bash
php artisan serve
```
2. Visit the application in your browser at `http://localhost:8000` or whichever port that you have run your application.
