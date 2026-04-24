<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum RuleError: string
{
    case NoDataFound = 'convert.com_no_data_found';
    case NeedMoreData = 'convert.com_need_more_data';
}
