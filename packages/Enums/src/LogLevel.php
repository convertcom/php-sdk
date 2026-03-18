<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum LogLevel: int
{
    case Trace = 0;
    case Debug = 1;
    case Info = 2;
    case Warn = 3;
    case Error = 4;
    case Silent = 5;
}
