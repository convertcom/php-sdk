<?php

namespace App\Http\Controllers;

use ConvertSdk\Enums\FeatureStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OpenAPI\Client\BucketingAttributes;

class StatisticsController extends Controller
{
    public function index(Request $request): View
    {
        $sdkContext = $request->attributes->get('sdkContext');
        $data = [
            'title' => 'Statistics',
            'variations' => [],
            'feature' => null,
        ];

        if ($sdkContext) {
            // [ConvertSDK] Run all applicable experiences
            $data['variations'] = $sdkContext->runExperiences(
                new BucketingAttributes(['locationProperties' => ['location' => 'statistics']])
            );

            // [ConvertSDK] Run feature flag
            $feature = $sdkContext->runFeature(
                config('convert.feature_key_stats'),
                new BucketingAttributes(['locationProperties' => ['location' => 'statistics']])
            );

            if ($feature !== null && $feature->status === FeatureStatus::Enabled) {
                $data['feature'] = $feature;
            }
        }

        return view('statistics', $data);
    }
}
