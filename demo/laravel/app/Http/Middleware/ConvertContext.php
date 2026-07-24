<?php

namespace App\Http\Middleware;

use Closure;
use ConvertSdk\Preview\PreviewParam;
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

        $previewActive = false;

        // [ConvertSDK] Resolve SDK singleton from container
        try {
            $sdk = app('convert.sdk');

            if ($sdk->isReady()) {
                // [ConvertSDK] Create visitor context with attributes matching JS demo
                $context = $sdk->createContext($userId, ['mobile' => true]);

                if ($context) {
                    // [ConvertSDK] Set default segments matching JS demo
                    $context->setDefaultSegments(['country' => 'US']);

                    // [ConvertSDK] qs-16 preview link — ?convert_preview={experienceId}.{variationId}
                    // forces that exact variation server-side with zero tracking/persistence for
                    // the rest of this context's lifetime. Inert (no-op) on a missing/malformed
                    // param — PreviewParam::parse() returns null and we simply skip setPreview().
                    $previewParam = $request->query('convert_preview');

                    if (is_string($previewParam)) {
                        $parsed = PreviewParam::parse($previewParam);

                        if ($parsed !== null) {
                            $context->setPreview($parsed['experienceId'], $parsed['variationId']);
                            $previewActive = true;

                            Log::info(sprintf(
                                '[ConvertSDK] Preview active — experienceId=%s variationId=%s (zero-trace context)',
                                $parsed['experienceId'],
                                $parsed['variationId']
                            ));
                        }
                    }

                    $request->attributes->set('sdkContext', $context);
                }
            } else {
                Log::warning('[ConvertSDK] SDK is not ready — pages will render without experiment data');
            }
        } catch (\Throwable $e) {
            Log::warning('[ConvertSDK] SDK initialization failed: ' . $e->getMessage());
        }

        $response = $next($request);

        // Set visitor ID cookie on response if newly generated (1-hour expiry).
        // [ConvertSDK] Skip the cookie write while previewing so a stakeholder preview
        // request stays stateless on the demo side too — the SDK-level zero-trace
        // guarantee already covers cache/dataStore; this just avoids issuing a new
        // visitor identity cookie for a request that was never really "visited".
        if ($newVisitor && !$previewActive) {
            $response->headers->setCookie(
                cookie('userId', $userId, 60) // 60 minutes
            );
        }

        return $response;
    }
}
