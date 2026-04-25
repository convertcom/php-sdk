<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum VariableTypes: string
{
    case Boolean = 'boolean';
    case Float = 'float';
    case Json = 'json';
    case Integer = 'integer';
    case String = 'string';
}
