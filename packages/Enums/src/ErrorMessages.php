<?php

declare(strict_types=1);

// File: src/Enums/ErrorMessages.php

namespace ConvertSdk\Enums;

final class ErrorMessages
{
    public const SDK_KEY_MISSING = 'SDK key is missing';
    public const DATA_OBJECT_MISSING = 'Data object is missing';
    public const CONFIG_DATA_NOT_VALID = 'Config Data is not valid';
    public const SDK_OR_DATA_OBJECT_REQUIRED = 'SDK key or Data object should be provided';
    public const RULE_NOT_VALID = 'Provided rule is not valid';
    public const RULE_DATA_NOT_VALID = 'Provided rule data is not valid';
    public const RULE_MATCH_TYPE_NOT_SUPPORTED = 'Provided rule matching type "#" is not supported';
    public const RULE_ERROR = 'Rule error';
    public const DATA_STORE_NOT_VALID = 'DataStore object is not valid. It should contain get and set methods';
    public const VISITOR_ID_REQUIRED = 'Visitor string string is not present';
    public const GOAL_DATA_NOT_VALID = 'GoalData object is not valid';
    public const UNABLE_TO_SELECT_BUCKET_FOR_VISITOR = 'Unable to bucket visitor';
    public const UNABLE_TO_PERFORM_NETWORK_REQUEST = 'Unable to perform network request';
    public const UNSUPPORTED_RESPONSE_TYPE = 'Unsupported response type';
}
