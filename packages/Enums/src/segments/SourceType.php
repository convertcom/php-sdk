<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum SourceType: string
{
    case Campaign = 'campaign';
    case Search = 'search';
    case Referral = 'referral';
    case Direct = 'direct';
}
