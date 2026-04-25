<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum SegmentsKeys: string
{
    case Country = 'country';
    case Browser = 'browser';
    case Devices = 'devices';
    case Source = 'source';
    case Campaign = 'campaign';
    case VisitorType = 'visitor_type';
    case CustomSegments = 'custom_segments';
}
