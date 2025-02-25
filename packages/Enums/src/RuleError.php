<?php
namespace ConvertSdk\Enums;

class RuleError {
    const NO_DATA_FOUND = 'convert.com_no_data_found';
    const NEED_MORE_DATA = 'convert.com_need_more_data';

    /**
     * Get all rule error constants.
     *
     * @return array
     */
    public static function getConstants(): array {
        return [
            self::NO_DATA_FOUND,
            self::NEED_MORE_DATA,
        ];
    }
}
