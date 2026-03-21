<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum BrowserType: string
{
    case Ie = 'IE';
    case Ch = 'CH';
    case Ff = 'FF';
    case Op = 'OP';
    case Sf = 'SF';
    case Edg = 'EDG';
    case Mo = 'MO';
    case Ns = 'NS';
    case Oth = 'OTH';
}
