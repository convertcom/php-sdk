<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum LogMethod: string
{
    case Log = 'log';
    case Trace = 'trace';
    case Debug = 'debug';
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
}
