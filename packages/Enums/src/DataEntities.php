<?php
namespace ConvertSdk\Enums;

class DataEntities {
    const DATA_ENTITIES = [
        'events',
        'goals',
        'audiences',
        'locations',
        'segments',
        'experiences',
        'archived_experiences',
        'experiences.variations',
        'features',
        'features.variables'
    ];

    const DATA_ENTITIES_MAP = [
        'goal' => 'goals',
        'audience' => 'audiences',
        'location' => 'locations',
        'segment' => 'segments',
        'experience' => 'experiences',
        'variation' => 'experiences.variations',
        'feature' => 'features'
    ];
}
