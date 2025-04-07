<?php
namespace ConvertSdk\Enums;

class SegmentsKeys {
    const COUNTRY        = 'country';
    const BROWSER        = 'browser';
    const DEVICES        = 'devices';
    const SOURCE         = 'source';
    const CAMPAIGN       = 'campaign';
    const VISITOR_TYPE   = 'visitor_type';
    const CUSTOM_SEGMENTS = 'custom_segments';

    /**
     * Returns an array of all segment key constants.
     *
     * @return array
     */
    public static function getConstants(): array {
        return [
            self::COUNTRY,
            self::BROWSER,
            self::DEVICES,
            self::SOURCE,
            self::CAMPAIGN,
            self::VISITOR_TYPE,
            self::CUSTOM_SEGMENTS,
        ];
    }
}
