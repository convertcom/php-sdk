<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum DeviceType: string
{
    case Allph = 'ALLPH';
    case Iph = 'IPH';
    case Othph = 'OTHPH';
    case Alltab = 'ALLTAB';
    case Ipad = 'IPAD';
    case Othtab = 'OTHTAB';
    case Desk = 'DESK';
    case Othdev = 'OTHDEV';
}
