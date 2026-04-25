# OpenAPIClient-php

Serve and track experiences to your users using Convert APIs and tools



## Installation & Usage

### Requirements

PHP 7.4 and later.
Should also work with PHP 8.0.

### Composer

To install the bindings via [Composer](https://getcomposer.org/), add the following to `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/GIT_USER_ID/GIT_REPO_ID.git"
    }
  ],
  "require": {
    "GIT_USER_ID/GIT_REPO_ID": "*@dev"
  }
}
```

Then run `composer install`

### Manual Installation

Download the files and include `autoload.php`:

```php
<?php
require_once('/path/to/OpenAPIClient-php/vendor/autoload.php');
```

## Getting Started

Please follow the [installation procedure](#installation--usage) and then run the following:

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');




$apiInstance = new OpenAPI\Client\Api\ExperiencesTrackingApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client()
);
$account_id = 56; // int | ID of the account that owns the given project
$project_id = 56; // int | ID of the project to which the events belong to
$send_tracking_events_request_data = new \OpenAPI\Client\Model\SendTrackingEventsRequestData(); // \OpenAPI\Client\Model\SendTrackingEventsRequestData | A JSON object containing the tracking events sent to the Convert tracking servers.

try {
    $result = $apiInstance->sendTrackingEvents($account_id, $project_id, $send_tracking_events_request_data);
    print_r($result);
} catch (Exception $e) {
    echo 'Exception when calling ExperiencesTrackingApi->sendTrackingEvents: ', $e->getMessage(), PHP_EOL;
}

```

## API Endpoints

All URIs are relative to *http://localhost*

Class | Method | HTTP request | Description
------------ | ------------- | ------------- | -------------
*ExperiencesTrackingApi* | [**sendTrackingEvents**](docs/Api/ExperiencesTrackingApi.md#sendtrackingevents) | **POST** /track/{account_id}/{project_id} | Send Tracking
*ExperiencesTrackingApi* | [**sendTrackingEventsSdkKey**](docs/Api/ExperiencesTrackingApi.md#sendtrackingeventssdkkey) | **POST** /track/{sdk_key} | Sdk-Key Send Tracking
*ProjectConfigApi* | [**getProjectConfig**](docs/Api/ProjectConfigApi.md#getprojectconfig) | **GET** /config/{account_id}/{project_id} | Default Get Project Config
*ProjectConfigApi* | [**getProjectConfigBySdkKey**](docs/Api/ProjectConfigApi.md#getprojectconfigbysdkkey) | **GET** /config/{sdk_key} | Sdk-Key Get Project Config
*ProjectConfigApi* | [**getProjectSettings**](docs/Api/ProjectConfigApi.md#getprojectsettings) | **GET** /project-settings/{account_id}/{project_id} | Minimal Project Settings

## Models

- [Base64Image](docs/Model/Base64Image.md)
- [BaseMatch](docs/Model/BaseMatch.md)
- [BaseRule](docs/Model/BaseRule.md)
- [BaseRuleWithBooleanValue](docs/Model/BaseRuleWithBooleanValue.md)
- [BaseRuleWithBrowserNameValue](docs/Model/BaseRuleWithBrowserNameValue.md)
- [BaseRuleWithCountryCodeValue](docs/Model/BaseRuleWithCountryCodeValue.md)
- [BaseRuleWithDayOfWeekValue](docs/Model/BaseRuleWithDayOfWeekValue.md)
- [BaseRuleWithExperienceBucketedValue](docs/Model/BaseRuleWithExperienceBucketedValue.md)
- [BaseRuleWithGoalTriggeredValue](docs/Model/BaseRuleWithGoalTriggeredValue.md)
- [BaseRuleWithHourOfDayValue](docs/Model/BaseRuleWithHourOfDayValue.md)
- [BaseRuleWithJsCodeValue](docs/Model/BaseRuleWithJsCodeValue.md)
- [BaseRuleWithLanguageCodeValue](docs/Model/BaseRuleWithLanguageCodeValue.md)
- [BaseRuleWithMinuteOfHourValue](docs/Model/BaseRuleWithMinuteOfHourValue.md)
- [BaseRuleWithNumericValue](docs/Model/BaseRuleWithNumericValue.md)
- [BaseRuleWithOsValue](docs/Model/BaseRuleWithOsValue.md)
- [BaseRuleWithSegmentBucketedValue](docs/Model/BaseRuleWithSegmentBucketedValue.md)
- [BaseRuleWithStringValue](docs/Model/BaseRuleWithStringValue.md)
- [BaseRuleWithVisitorTypeValue](docs/Model/BaseRuleWithVisitorTypeValue.md)
- [BaseRuleWithWeatherConditionValue](docs/Model/BaseRuleWithWeatherConditionValue.md)
- [BoolMatchRulesTypes](docs/Model/BoolMatchRulesTypes.md)
- [BrowserNameMatchRule](docs/Model/BrowserNameMatchRule.md)
- [BrowserNameMatchRuleAllOfMatching](docs/Model/BrowserNameMatchRuleAllOfMatching.md)
- [BrowserNameMatchRulesTypes](docs/Model/BrowserNameMatchRulesTypes.md)
- [BucketingEvent](docs/Model/BucketingEvent.md)
- [BulkEntityError](docs/Model/BulkEntityError.md)
- [BulkSuccessData](docs/Model/BulkSuccessData.md)
- [ChoiceContainsOptions](docs/Model/ChoiceContainsOptions.md)
- [ChoiceMatchingOptions](docs/Model/ChoiceMatchingOptions.md)
- [ClicksElementGoal](docs/Model/ClicksElementGoal.md)
- [ClicksElementGoalSettings](docs/Model/ClicksElementGoalSettings.md)
- [ClicksLinkGoal](docs/Model/ClicksLinkGoal.md)
- [ClicksLinkGoalSettings](docs/Model/ClicksLinkGoalSettings.md)
- [ConfigAudience](docs/Model/ConfigAudience.md)
- [ConfigAudienceTypes](docs/Model/ConfigAudienceTypes.md)
- [ConfigExperience](docs/Model/ConfigExperience.md)
- [ConfigExperienceIntegrationsInner](docs/Model/ConfigExperienceIntegrationsInner.md)
- [ConfigExperienceSettings](docs/Model/ConfigExperienceSettings.md)
- [ConfigExperienceSettingsMatchingOptions](docs/Model/ConfigExperienceSettingsMatchingOptions.md)
- [ConfigExperienceSettingsOutliers](docs/Model/ConfigExperienceSettingsOutliers.md)
- [ConfigFeature](docs/Model/ConfigFeature.md)
- [ConfigGoal](docs/Model/ConfigGoal.md)
- [ConfigGoalBase](docs/Model/ConfigGoalBase.md)
- [ConfigLocation](docs/Model/ConfigLocation.md)
- [ConfigMinimalResponseData](docs/Model/ConfigMinimalResponseData.md)
- [ConfigProject](docs/Model/ConfigProject.md)
- [ConfigProjectCustomDomain](docs/Model/ConfigProjectCustomDomain.md)
- [ConfigProjectDomainsInner](docs/Model/ConfigProjectDomainsInner.md)
- [ConfigProjectEnvironmentsValue](docs/Model/ConfigProjectEnvironmentsValue.md)
- [ConfigProjectMinimalSettings](docs/Model/ConfigProjectMinimalSettings.md)
- [ConfigProjectSettings](docs/Model/ConfigProjectSettings.md)
- [ConfigProjectSettingsAllOfIntegrations](docs/Model/ConfigProjectSettingsAllOfIntegrations.md)
- [ConfigProjectSettingsAllOfIntegrationsKissmetrics](docs/Model/ConfigProjectSettingsAllOfIntegrationsKissmetrics.md)
- [ConfigResponseData](docs/Model/ConfigResponseData.md)
- [ConfigSegment](docs/Model/ConfigSegment.md)
- [ConversionEvent](docs/Model/ConversionEvent.md)
- [ConversionEventGoalDataInner](docs/Model/ConversionEventGoalDataInner.md)
- [ConversionEventGoalDataInnerValue](docs/Model/ConversionEventGoalDataInnerValue.md)
- [CookieMatchRule](docs/Model/CookieMatchRule.md)
- [CookieMatchRuleAllOfMatching](docs/Model/CookieMatchRuleAllOfMatching.md)
- [CookieMatchRulesTypes](docs/Model/CookieMatchRulesTypes.md)
- [CountryMatchRule](docs/Model/CountryMatchRule.md)
- [CountryMatchRuleAllOfMatching](docs/Model/CountryMatchRuleAllOfMatching.md)
- [CountryMatchRulesTypes](docs/Model/CountryMatchRulesTypes.md)
- [DateRange](docs/Model/DateRange.md)
- [DayOfWeekMatchRule](docs/Model/DayOfWeekMatchRule.md)
- [DayOfWeekMatchRuleAllOfMatching](docs/Model/DayOfWeekMatchRuleAllOfMatching.md)
- [DayOfWeekMatchRulesTypes](docs/Model/DayOfWeekMatchRulesTypes.md)
- [DomInteractionGoal](docs/Model/DomInteractionGoal.md)
- [DomInteractionGoalSettings](docs/Model/DomInteractionGoalSettings.md)
- [DomInteractionGoalSettingsTrackedItemsInner](docs/Model/DomInteractionGoalSettingsTrackedItemsInner.md)
- [ErrorData](docs/Model/ErrorData.md)
- [ExperienceBucketedMatchRule](docs/Model/ExperienceBucketedMatchRule.md)
- [ExperienceBucketedMatchRuleAllOfMatching](docs/Model/ExperienceBucketedMatchRuleAllOfMatching.md)
- [ExperienceChange](docs/Model/ExperienceChange.md)
- [ExperienceChangeAdd](docs/Model/ExperienceChangeAdd.md)
- [ExperienceChangeBase](docs/Model/ExperienceChangeBase.md)
- [ExperienceChangeCustomCodeData](docs/Model/ExperienceChangeCustomCodeData.md)
- [ExperienceChangeCustomCodeDataAdd](docs/Model/ExperienceChangeCustomCodeDataAdd.md)
- [ExperienceChangeCustomCodeDataBase](docs/Model/ExperienceChangeCustomCodeDataBase.md)
- [ExperienceChangeCustomCodeDataBaseAllOfData](docs/Model/ExperienceChangeCustomCodeDataBaseAllOfData.md)
- [ExperienceChangeCustomCodeDataUpdate](docs/Model/ExperienceChangeCustomCodeDataUpdate.md)
- [ExperienceChangeCustomCodeDataUpdateNoId](docs/Model/ExperienceChangeCustomCodeDataUpdateNoId.md)
- [ExperienceChangeDefaultCodeData](docs/Model/ExperienceChangeDefaultCodeData.md)
- [ExperienceChangeDefaultCodeDataAdd](docs/Model/ExperienceChangeDefaultCodeDataAdd.md)
- [ExperienceChangeDefaultCodeDataBase](docs/Model/ExperienceChangeDefaultCodeDataBase.md)
- [ExperienceChangeDefaultCodeDataBaseAllOfData](docs/Model/ExperienceChangeDefaultCodeDataBaseAllOfData.md)
- [ExperienceChangeDefaultCodeDataUpdate](docs/Model/ExperienceChangeDefaultCodeDataUpdate.md)
- [ExperienceChangeDefaultCodeDataUpdateNoId](docs/Model/ExperienceChangeDefaultCodeDataUpdateNoId.md)
- [ExperienceChangeDefaultCodeMultipageData](docs/Model/ExperienceChangeDefaultCodeMultipageData.md)
- [ExperienceChangeDefaultCodeMultipageDataAdd](docs/Model/ExperienceChangeDefaultCodeMultipageDataAdd.md)
- [ExperienceChangeDefaultCodeMultipageDataBase](docs/Model/ExperienceChangeDefaultCodeMultipageDataBase.md)
- [ExperienceChangeDefaultCodeMultipageDataBaseAllOfData](docs/Model/ExperienceChangeDefaultCodeMultipageDataBaseAllOfData.md)
- [ExperienceChangeDefaultCodeMultipageDataUpdate](docs/Model/ExperienceChangeDefaultCodeMultipageDataUpdate.md)
- [ExperienceChangeDefaultCodeMultipageDataUpdateNoId](docs/Model/ExperienceChangeDefaultCodeMultipageDataUpdateNoId.md)
- [ExperienceChangeDefaultRedirectData](docs/Model/ExperienceChangeDefaultRedirectData.md)
- [ExperienceChangeDefaultRedirectDataAdd](docs/Model/ExperienceChangeDefaultRedirectDataAdd.md)
- [ExperienceChangeDefaultRedirectDataBase](docs/Model/ExperienceChangeDefaultRedirectDataBase.md)
- [ExperienceChangeDefaultRedirectDataBaseAllOfData](docs/Model/ExperienceChangeDefaultRedirectDataBaseAllOfData.md)
- [ExperienceChangeDefaultRedirectDataUpdate](docs/Model/ExperienceChangeDefaultRedirectDataUpdate.md)
- [ExperienceChangeDefaultRedirectDataUpdateNoId](docs/Model/ExperienceChangeDefaultRedirectDataUpdateNoId.md)
- [ExperienceChangeFullStackFeature](docs/Model/ExperienceChangeFullStackFeature.md)
- [ExperienceChangeFullStackFeatureAdd](docs/Model/ExperienceChangeFullStackFeatureAdd.md)
- [ExperienceChangeFullStackFeatureBase](docs/Model/ExperienceChangeFullStackFeatureBase.md)
- [ExperienceChangeFullStackFeatureBaseAllOfData](docs/Model/ExperienceChangeFullStackFeatureBaseAllOfData.md)
- [ExperienceChangeFullStackFeatureUpdate](docs/Model/ExperienceChangeFullStackFeatureUpdate.md)
- [ExperienceChangeFullStackFeatureUpdateNoId](docs/Model/ExperienceChangeFullStackFeatureUpdateNoId.md)
- [ExperienceChangeId](docs/Model/ExperienceChangeId.md)
- [ExperienceChangeIdReadOnly](docs/Model/ExperienceChangeIdReadOnly.md)
- [ExperienceChangeRichStructureData](docs/Model/ExperienceChangeRichStructureData.md)
- [ExperienceChangeRichStructureDataAdd](docs/Model/ExperienceChangeRichStructureDataAdd.md)
- [ExperienceChangeRichStructureDataBase](docs/Model/ExperienceChangeRichStructureDataBase.md)
- [ExperienceChangeRichStructureDataBaseAllOfData](docs/Model/ExperienceChangeRichStructureDataBaseAllOfData.md)
- [ExperienceChangeRichStructureDataUpdate](docs/Model/ExperienceChangeRichStructureDataUpdate.md)
- [ExperienceChangeRichStructureDataUpdateNoId](docs/Model/ExperienceChangeRichStructureDataUpdateNoId.md)
- [ExperienceChangeUpdate](docs/Model/ExperienceChangeUpdate.md)
- [ExperienceChangeUpdateNoId](docs/Model/ExperienceChangeUpdateNoId.md)
- [ExperienceIntegrationBaidu](docs/Model/ExperienceIntegrationBaidu.md)
- [ExperienceIntegrationBase](docs/Model/ExperienceIntegrationBase.md)
- [ExperienceIntegrationClicktale](docs/Model/ExperienceIntegrationClicktale.md)
- [ExperienceIntegrationClicky](docs/Model/ExperienceIntegrationClicky.md)
- [ExperienceIntegrationCnzz](docs/Model/ExperienceIntegrationCnzz.md)
- [ExperienceIntegrationCrazyegg](docs/Model/ExperienceIntegrationCrazyegg.md)
- [ExperienceIntegrationEconda](docs/Model/ExperienceIntegrationEconda.md)
- [ExperienceIntegrationEulerian](docs/Model/ExperienceIntegrationEulerian.md)
- [ExperienceIntegrationGA3](docs/Model/ExperienceIntegrationGA3.md)
- [ExperienceIntegrationGA4](docs/Model/ExperienceIntegrationGA4.md)
- [ExperienceIntegrationGA4Base](docs/Model/ExperienceIntegrationGA4Base.md)
- [ExperienceIntegrationGAServing](docs/Model/ExperienceIntegrationGAServing.md)
- [ExperienceIntegrationGoogleAnalytics](docs/Model/ExperienceIntegrationGoogleAnalytics.md)
- [ExperienceIntegrationGosquared](docs/Model/ExperienceIntegrationGosquared.md)
- [ExperienceIntegrationHeapanalytics](docs/Model/ExperienceIntegrationHeapanalytics.md)
- [ExperienceIntegrationHotjar](docs/Model/ExperienceIntegrationHotjar.md)
- [ExperienceIntegrationMixpanel](docs/Model/ExperienceIntegrationMixpanel.md)
- [ExperienceIntegrationMouseflow](docs/Model/ExperienceIntegrationMouseflow.md)
- [ExperienceIntegrationPiwik](docs/Model/ExperienceIntegrationPiwik.md)
- [ExperienceIntegrationSegmentio](docs/Model/ExperienceIntegrationSegmentio.md)
- [ExperienceIntegrationSitecatalyst](docs/Model/ExperienceIntegrationSitecatalyst.md)
- [ExperienceIntegrationWoopra](docs/Model/ExperienceIntegrationWoopra.md)
- [ExperienceIntegrationYsance](docs/Model/ExperienceIntegrationYsance.md)
- [ExperienceStatuses](docs/Model/ExperienceStatuses.md)
- [ExperienceTypes](docs/Model/ExperienceTypes.md)
- [ExperienceVariationConfig](docs/Model/ExperienceVariationConfig.md)
- [Extra](docs/Model/Extra.md)
- [FeatureVariableItemData](docs/Model/FeatureVariableItemData.md)
- [GASettings](docs/Model/GASettings.md)
- [GASettingsBase](docs/Model/GASettingsBase.md)
- [GaGoal](docs/Model/GaGoal.md)
- [GaGoalSettings](docs/Model/GaGoalSettings.md)
- [GenericBoolKeyValueMatchRule](docs/Model/GenericBoolKeyValueMatchRule.md)
- [GenericBoolKeyValueMatchRuleAllOfMatching](docs/Model/GenericBoolKeyValueMatchRuleAllOfMatching.md)
- [GenericBoolKeyValueMatchRulesTypes](docs/Model/GenericBoolKeyValueMatchRulesTypes.md)
- [GenericBoolMatchRule](docs/Model/GenericBoolMatchRule.md)
- [GenericBoolMatchRuleAllOfMatching](docs/Model/GenericBoolMatchRuleAllOfMatching.md)
- [GenericKey](docs/Model/GenericKey.md)
- [GenericListMatchingOptions](docs/Model/GenericListMatchingOptions.md)
- [GenericNumericKeyValueMatchRule](docs/Model/GenericNumericKeyValueMatchRule.md)
- [GenericNumericKeyValueMatchRuleAllOfMatching](docs/Model/GenericNumericKeyValueMatchRuleAllOfMatching.md)
- [GenericNumericKeyValueMatchRulesTypes](docs/Model/GenericNumericKeyValueMatchRulesTypes.md)
- [GenericNumericMatchRule](docs/Model/GenericNumericMatchRule.md)
- [GenericNumericMatchRuleAllOfMatching](docs/Model/GenericNumericMatchRuleAllOfMatching.md)
- [GenericSetMatchRule](docs/Model/GenericSetMatchRule.md)
- [GenericSetMatchRuleAllOfMatching](docs/Model/GenericSetMatchRuleAllOfMatching.md)
- [GenericTextKeyValueMatchRule](docs/Model/GenericTextKeyValueMatchRule.md)
- [GenericTextKeyValueMatchRuleAllOfMatching](docs/Model/GenericTextKeyValueMatchRuleAllOfMatching.md)
- [GenericTextKeyValueMatchRulesTypes](docs/Model/GenericTextKeyValueMatchRulesTypes.md)
- [GenericTextMatchRule](docs/Model/GenericTextMatchRule.md)
- [GenericTextMatchRuleAllOfMatching](docs/Model/GenericTextMatchRuleAllOfMatching.md)
- [GoalTriggeredMatchRule](docs/Model/GoalTriggeredMatchRule.md)
- [GoalTriggeredMatchRuleAllOfMatching](docs/Model/GoalTriggeredMatchRuleAllOfMatching.md)
- [GoalTriggeredMatchRulesTypes](docs/Model/GoalTriggeredMatchRulesTypes.md)
- [GoalTypes](docs/Model/GoalTypes.md)
- [HourOfDayMatchRule](docs/Model/HourOfDayMatchRule.md)
- [HourOfDayMatchRuleAllOfMatching](docs/Model/HourOfDayMatchRuleAllOfMatching.md)
- [HourOfDayMatchRulesTypes](docs/Model/HourOfDayMatchRulesTypes.md)
- [ImportProjectDataSuccess](docs/Model/ImportProjectDataSuccess.md)
- [ImportProjectDataSuccessAllOfImported](docs/Model/ImportProjectDataSuccessAllOfImported.md)
- [IntegrationGA3](docs/Model/IntegrationGA3.md)
- [IntegrationGA4](docs/Model/IntegrationGA4.md)
- [IntegrationGA4Base](docs/Model/IntegrationGA4Base.md)
- [IntegrationProvider](docs/Model/IntegrationProvider.md)
- [JsConditionMatchRule](docs/Model/JsConditionMatchRule.md)
- [JsConditionMatchRuleAllOfMatching](docs/Model/JsConditionMatchRuleAllOfMatching.md)
- [JsConditionMatchRulesTypes](docs/Model/JsConditionMatchRulesTypes.md)
- [KeyValueMatchRulesTypes](docs/Model/KeyValueMatchRulesTypes.md)
- [LanguageMatchRule](docs/Model/LanguageMatchRule.md)
- [LanguageMatchRuleAllOfMatching](docs/Model/LanguageMatchRuleAllOfMatching.md)
- [LanguageMatchRulesTypes](docs/Model/LanguageMatchRulesTypes.md)
- [LocationDomTriggerEvents](docs/Model/LocationDomTriggerEvents.md)
- [LocationTrigger](docs/Model/LocationTrigger.md)
- [LocationTriggerBase](docs/Model/LocationTriggerBase.md)
- [LocationTriggerCallback](docs/Model/LocationTriggerCallback.md)
- [LocationTriggerDomElement](docs/Model/LocationTriggerDomElement.md)
- [LocationTriggerManual](docs/Model/LocationTriggerManual.md)
- [LocationTriggerTypes](docs/Model/LocationTriggerTypes.md)
- [LocationTriggerUponRun](docs/Model/LocationTriggerUponRun.md)
- [MinuteOfHourMatchRule](docs/Model/MinuteOfHourMatchRule.md)
- [MinuteOfHourMatchRuleAllOfMatching](docs/Model/MinuteOfHourMatchRuleAllOfMatching.md)
- [MinuteOfHourMatchRulesTypes](docs/Model/MinuteOfHourMatchRulesTypes.md)
- [MultipageExperiencePage](docs/Model/MultipageExperiencePage.md)
- [NoSettingsGoal](docs/Model/NoSettingsGoal.md)
- [NumericMatchRulesTypes](docs/Model/NumericMatchRulesTypes.md)
- [NumericMatchingOptions](docs/Model/NumericMatchingOptions.md)
- [NumericOutlier](docs/Model/NumericOutlier.md)
- [NumericOutlierBase](docs/Model/NumericOutlierBase.md)
- [NumericOutlierMinMax](docs/Model/NumericOutlierMinMax.md)
- [NumericOutlierNone](docs/Model/NumericOutlierNone.md)
- [NumericOutlierPercentile](docs/Model/NumericOutlierPercentile.md)
- [NumericOutlierPercentileAllOfMax](docs/Model/NumericOutlierPercentileAllOfMax.md)
- [NumericOutlierPercentileAllOfMin](docs/Model/NumericOutlierPercentileAllOfMin.md)
- [NumericOutlierTypes](docs/Model/NumericOutlierTypes.md)
- [OnlyCount](docs/Model/OnlyCount.md)
- [OsMatchRule](docs/Model/OsMatchRule.md)
- [OsMatchRuleAllOfMatching](docs/Model/OsMatchRuleAllOfMatching.md)
- [OsMatchRulesTypes](docs/Model/OsMatchRulesTypes.md)
- [PageNumber](docs/Model/PageNumber.md)
- [Pagination](docs/Model/Pagination.md)
- [Percentiles](docs/Model/Percentiles.md)
- [ProjectGASettingsBase](docs/Model/ProjectGASettingsBase.md)
- [ProjectIntegrationGA3](docs/Model/ProjectIntegrationGA3.md)
- [ProjectIntegrationGA4](docs/Model/ProjectIntegrationGA4.md)
- [ResultsPerPage](docs/Model/ResultsPerPage.md)
- [RevenueGoal](docs/Model/RevenueGoal.md)
- [RevenueGoalSettings](docs/Model/RevenueGoalSettings.md)
- [RuleElement](docs/Model/RuleElement.md)
- [RuleElementNoUrl](docs/Model/RuleElementNoUrl.md)
- [RuleObject](docs/Model/RuleObject.md)
- [RuleObjectNoUrl](docs/Model/RuleObjectNoUrl.md)
- [RuleObjectNoUrlORInner](docs/Model/RuleObjectNoUrlORInner.md)
- [RuleObjectNoUrlORInnerANDInner](docs/Model/RuleObjectNoUrlORInnerANDInner.md)
- [RuleObjectORInner](docs/Model/RuleObjectORInner.md)
- [RuleObjectORInnerANDInner](docs/Model/RuleObjectORInnerANDInner.md)
- [RulesTypes](docs/Model/RulesTypes.md)
- [ScrollPercentageGoal](docs/Model/ScrollPercentageGoal.md)
- [ScrollPercentageGoalSettings](docs/Model/ScrollPercentageGoalSettings.md)
- [SegmentBucketedMatchRule](docs/Model/SegmentBucketedMatchRule.md)
- [SegmentBucketedMatchRuleAllOfMatching](docs/Model/SegmentBucketedMatchRuleAllOfMatching.md)
- [SegmentBucketedMatchRulesTypes](docs/Model/SegmentBucketedMatchRulesTypes.md)
- [SendTrackingEventsRequestData](docs/Model/SendTrackingEventsRequestData.md)
- [SendTrackingEventsRequestDataVisitorsInner](docs/Model/SendTrackingEventsRequestDataVisitorsInner.md)
- [SetMatchingOptions](docs/Model/SetMatchingOptions.md)
- [SortDirection](docs/Model/SortDirection.md)
- [SubmitsFormGoal](docs/Model/SubmitsFormGoal.md)
- [SubmitsFormGoalSettings](docs/Model/SubmitsFormGoalSettings.md)
- [SuccessData](docs/Model/SuccessData.md)
- [TextMatchRulesTypes](docs/Model/TextMatchRulesTypes.md)
- [TextMatchingOptions](docs/Model/TextMatchingOptions.md)
- [TrackingScriptReleaseBase](docs/Model/TrackingScriptReleaseBase.md)
- [UpdateExperienceChangeRequestData](docs/Model/UpdateExperienceChangeRequestData.md)
- [VariationStatuses](docs/Model/VariationStatuses.md)
- [VisitorInsightsData](docs/Model/VisitorInsightsData.md)
- [VisitorSegments](docs/Model/VisitorSegments.md)
- [VisitorTrackingEvents](docs/Model/VisitorTrackingEvents.md)
- [VisitorTrackingEventsData](docs/Model/VisitorTrackingEventsData.md)
- [VisitorTypeMatchRule](docs/Model/VisitorTypeMatchRule.md)
- [VisitorTypeMatchRuleAllOfMatching](docs/Model/VisitorTypeMatchRuleAllOfMatching.md)
- [VisitorTypeMatchRulesTypes](docs/Model/VisitorTypeMatchRulesTypes.md)
- [WeatherConditionMatchRule](docs/Model/WeatherConditionMatchRule.md)
- [WeatherConditionMatchRuleAllOfMatching](docs/Model/WeatherConditionMatchRuleAllOfMatching.md)
- [WeatherConditionMatchRulesTypes](docs/Model/WeatherConditionMatchRulesTypes.md)
- [WeatherConditions](docs/Model/WeatherConditions.md)

## Authorization

Authentication schemes defined for the API:
### sdkKeyAuth

- **Type**: API key
- **API key parameter name**: Authorization
- **Location**: HTTP header


### debuggingTokenAuth

- **Type**: API key
- **API key parameter name**: convert-debug-token
- **Location**: HTTP header


## Tests

To run the tests, use:

```bash
composer install
vendor/bin/phpunit
```

## Author



## About this package

This PHP package is automatically generated by the [OpenAPI Generator](https://openapi-generator.tech) project:

- API version: `1.1.0`
    - Generator version: `7.13.0-SNAPSHOT`
- Build package: `org.openapitools.codegen.languages.PhpClientCodegen`
