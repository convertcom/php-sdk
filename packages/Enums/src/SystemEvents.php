<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum SystemEvents: string
{
    case Ready = 'ready';
    case ConfigUpdated = 'config.updated';
    case ApiQueueReleased = 'api.queue.released';
    case Bucketing = 'bucketing';
    case Conversion = 'conversion';
    case Segments = 'segments';
    case LocationActivated = 'location.activated';
    case LocationDeactivated = 'location.deactivated';
    case Audiences = 'audiences';
    case DataStoreQueueReleased = 'datastore.queue.released';
}
