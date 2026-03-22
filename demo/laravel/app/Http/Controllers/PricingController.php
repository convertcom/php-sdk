<?php

namespace App\Http\Controllers;

use ConvertSdk\Enums\FeatureStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OpenAPI\Client\BucketingAttributes;

class PricingController extends Controller
{
    public function index(Request $request): View
    {
        $sdkContext = $request->attributes->get('sdkContext');
        $data = [
            'title' => 'Pricing',
            'variations' => [],
            'feature' => null,
            'goalKey' => config('convert.goal_key'),
        ];

        if ($sdkContext) {
            // [ConvertSDK] Run all applicable experiences
            $data['variations'] = $sdkContext->runExperiences(
                new BucketingAttributes(['locationProperties' => ['location' => 'pricing']])
            );

            // [ConvertSDK] Run feature flag
            $feature = $sdkContext->runFeature(
                config('convert.feature_key_pricing'),
                new BucketingAttributes(['locationProperties' => ['location' => 'pricing']])
            );

            if ($feature !== null && $feature->status === FeatureStatus::Enabled) {
                $data['feature'] = $feature;
            }
        }

        return view('pricing', $data);
    }
}
