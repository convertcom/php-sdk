<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ConvertContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // [ConvertSDK] Read or generate visitor ID cookie (mirrors JS demo's convertcontext.js)
        $userId = $request->cookie('userId');
        $newVisitor = false;

        if (!$userId) {
            $userId = time() . '-' . microtime(true);
            $newVisitor = true;
        }

        // [ConvertSDK] Resolve SDK singleton from container
        try {
            $sdk = app('convert.sdk');

            if ($sdk->isReady()) {
                // [ConvertSDK] Create visitor context with attributes matching JS demo
                $context = $sdk->createContext($userId, ['mobile' => true]);

                if ($context) {
                    // [ConvertSDK] Set default segments matching JS demo
                    $context->setDefaultSegments(['country' => 'US']);
                    $request->attributes->set('sdkContext', $context);
                }
            } else {
                Log::warning('[ConvertSDK] SDK is not ready — pages will render without experiment data');
            }
        } catch (\Throwable $e) {
            Log::warning('[ConvertSDK] SDK initialization failed: ' . $e->getMessage());
        }

        $response = $next($request);

        // Set visitor ID cookie on response if newly generated (1-hour expiry)
        if ($newVisitor) {
            $response->headers->setCookie(
                cookie('userId', $userId, 60) // 60 minutes
            );
        }

        return $response;
    }
}
