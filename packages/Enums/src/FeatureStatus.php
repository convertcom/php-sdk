<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum FeatureStatus: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
}
