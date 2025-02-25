<?php
namespace ConvertSdk\Enums;

class SystemEvents
{
    public const READY = 'ready';
    public const CONFIG_UPDATED = 'config.updated';
    public const API_QUEUE_RELEASED = 'api.queue.released';
    public const BUCKETING = 'bucketing';
    public const CONVERSION = 'conversion';
    public const SEGMENTS = 'segments';
    public const LOCATION_ACTIVATED = 'location.activated';
    public const LOCATION_DEACTIVATED = 'location.deactivated';
    public const AUDIENCES = 'audiences';
    public const DATA_STORE_QUEUE_RELEASED = 'datastore.queue.released';
}
