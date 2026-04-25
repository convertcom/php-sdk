<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum EntityType: string
{
    case Audience = 'audience';
    case Location = 'location';
    case Segment = 'segment';
    case Feature = 'feature';
    case Goal = 'goal';
    case Experience = 'experience';
    case Variation = 'variation';
}
