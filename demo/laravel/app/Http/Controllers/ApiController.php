<?php

namespace App\Http\Controllers;

use ConvertSdk\DTO\ConversionAttributes;
use ConvertSdk\DTO\GoalData;
use ConvertSdk\Enums\GoalDataKey;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiController extends Controller
{
    public function buy(Request $request): View
    {
        $sdkContext = $request->attributes->get('sdkContext');
        $goalKey = $request->input('goalKey', config('convert.goal_key'));

        if ($sdkContext) {
            // [ConvertSDK] Track conversion with goal data
            $sdkContext->trackConversion($goalKey, new ConversionAttributes(
                ruleData: ['action' => 'buy'],
                conversionData: [
                    new GoalData(GoalDataKey::Amount, 10.3),
                    new GoalData(GoalDataKey::ProductsCount, 2),
                ],
            ));
        }

        return view('buy', ['title' => 'Purchase Confirmation']);
    }
}
