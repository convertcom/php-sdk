<?php

declare(strict_types=1);

namespace ConvertSdk\Enums;

enum VariationChangeType: string
{
    case RichStructure = 'richStructure';
    case CustomCode = 'customCode';
    case DefaultCode = 'defaultCode';
    case DefaultCodeMultipage = 'defaultCodeMultipage';
    case DefaultRedirect = 'defaultRedirect';
    case FullstackFeature = 'fullStackFeature';
}
