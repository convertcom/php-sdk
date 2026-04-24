<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use OpenAPI\Client\BucketingAttributes;

class EventsController extends Controller
{
    public function index(Request $request): View
    {
        $sdkContext = $request->attributes->get('sdkContext');
        $data = [
            'title' => 'Events',
            'variation' => null,
            'feature' => null,
            'callForActionLabel' => null,
        ];

        if ($sdkContext) {
            // [ConvertSDK] Run single experience
            $data['variation'] = $sdkContext->runExperience(
                config('convert.experience_key'),
                new BucketingAttributes(['locationProperties' => ['location' => 'events']])
            );

            // [ConvertSDK] Run feature rollout (uses runExperience, not runFeature — mirrors JS demo)
            $featureRollout = $sdkContext->runExperience(
                config('convert.feature_rollout_key'),
                new BucketingAttributes(['locationProperties' => ['location' => 'events']])
            );
            $data['feature'] = $featureRollout;

            // [ConvertSDK] Extract feature variables from changes
            if ($featureRollout !== null && !empty($featureRollout->changes)) {
                $data['callForActionLabel'] = $featureRollout->changes[0]['data']['variables_data']['caption'] ?? null;
            }

            // [ConvertSDK] Set custom segments
            $sdkContext->setCustomSegments([config('convert.segment_key')], [
                'ruleData' => ['enabled' => false],
            ]);
        }

        return view('events', $data);
    }
}
