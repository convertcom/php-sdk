<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

final class DataEntities
{
    public const DATA_ENTITIES = [
        'events',
        'goals',
        'audiences',
        'locations',
        'segments',
        'experiences',
        'archived_experiences',
        'experiences.variations',
        'features',
        'features.variables',
    ];

    public const DATA_ENTITIES_MAP = [
        'goal' => 'goals',
        'audience' => 'audiences',
        'location' => 'locations',
        'segment' => 'segments',
        'experience' => 'experiences',
        'variation' => 'experiences.variations',
        'feature' => 'features',
    ];
}
