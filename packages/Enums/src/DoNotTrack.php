<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum DoNotTrack: string
{
    case Off = 'OFF';
    case EuOnly = 'EU ONLY';
    case EeaOnly = 'EEA ONLY';
    case Worldwide = 'Worldwide';
}
