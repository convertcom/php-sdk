<?php
// File: src/Enums/Messages.php
namespace ConvertSdk\Enums;

class Messages {
    public const CONFIG_DATA_UPDATED = 'Config Data updated';
    public const CORE_CONSTRUCTOR = 'Core Manager constructor has been called';
    public const CORE_INITIALIZED = 'Core Manager has been initialized';
    public const EXPERIENCE_CONSTRUCTOR = 'Experience Manager constructor has been called';
    public const EXPERIENCE_NOT_FOUND = 'Experience not found';
    public const EXPERIENCE_ARCHIVED = 'Experience archived';
    public const EXPERIENCE_ENVIRONMENT_NOT_MATCH = 'Experience environment does not match';
    public const EXPERIENCE_RULES_MATCHED = 'Experience rules matched';
    public const VARIATIONS_NOT_FOUND = 'Variations not found';
    public const VARIATION_CHANGE_NOT_SUPPORTED = 'Variation change not supported';
    public const FEATURE_CONSTRUCTOR = 'Feature Manager constructor has been called';
    public const FEATURE_NOT_FOUND = 'Fullstack Feature not found';
    public const FEATURE_VARIABLES_NOT_FOUND = 'Fullstack Feature Variables not found';
    public const FEATURE_VARIABLES_TYPE_NOT_FOUND = 'Fullstack Feature Variables Type not found';
    public const BUCKETING_CONSTRUCTOR = 'Bucketing Manager constructor has been called';
    public const DATA_CONSTRUCTOR = 'Data Manager constructor has been called';
    public const RULE_CONSTRUCTOR = 'Rule Manager constructor has been called';
    public const PROCESSING_ENTITY = 'Processing #';
    public const LOCATION_MATCH = 'Location # rule matched';
    public const LOCATION_NOT_MATCH = 'Location does not match';
    public const LOCATION_NOT_RESTRICTED = 'Location not restricted';
    public const AUDIENCE_MATCH = 'Audience # rule matched';
    public const AUDIENCE_NOT_MATCH = 'Audience not match';
    public const NON_PERMANENT_AUDIENCE_NOT_RESTRICTED = 'Non-Permanent Audience not restricted';
    public const AUDIENCE_NOT_RESTRICTED = 'Audience not restricted';
    public const SEGMENTATION_MATCH = 'Segmentation # rule matched';
    public const SEGMENTATION_NOT_RESTRICTED = 'Segmentation not restricted';
    public const RULE_NOT_MATCH = 'Rule does not match';
    public const RULE_MATCH = 'Found matched rule at OR block #';
    public const RULE_MATCH_AND = 'AND block rule macthed';
    public const RULE_MATCH_START = 'About to evaluate rule #';
    public const LOCATION_ACTIVATED = 'Location # activated';
    public const LOCATION_DEACTIVATED = 'Location # deactivated';
    public const BUCKETED_VISITOR_FOUND = 'Visitor is already bucketed for variation #';
    public const BUCKETED_VISITOR_FORCED = 'Forcing variation #';
    public const BUCKETED_VISITOR = 'Visitor is bucketed for variation #';
    public const GOAL_NOT_FOUND = 'Goal not found';
    public const GOAL_RULE_NOT_MATCH = 'Goal rule do not match';
    public const GOAL_FOUND = 'Goal # already triggered';
    public const SEGMENTS_NOT_FOUND = 'Segments not found';
    public const SEGMENTS_RULE_NOT_MATCH = 'Segments rule do not match';
    public const CUSTOM_SEGMENTS_KEY_FOUND = 'Custom segments key already set';
    public const SEND_BEACON_SUCCESS = 'The user agent successfully queued the data for transfer';
    public const RELEASING_QUEUE = 'Releasing event queue...';
}
