<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum VisitorType: string
{
    case New = 'new';
    case Returning = 'returning';
}
