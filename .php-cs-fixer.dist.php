<?php

declare(strict_types=1);

$directories = [
    __DIR__ . '/packages/Api/src',
    __DIR__ . '/packages/Api/tests',
    __DIR__ . '/packages/Bucketing/src',
    __DIR__ . '/packages/Bucketing/tests',
    __DIR__ . '/packages/Data/src',
    __DIR__ . '/packages/Data/tests',
    __DIR__ . '/packages/Enums/src',
    __DIR__ . '/packages/Event/src',
    __DIR__ . '/packages/Event/tests',
    __DIR__ . '/packages/Experience/src',
    __DIR__ . '/packages/Experience/tests',
    __DIR__ . '/packages/Logger/src',
    __DIR__ . '/packages/Logger/tests',
    __DIR__ . '/packages/Php-sdk/src',
    __DIR__ . '/packages/Php-sdk/tests',
    __DIR__ . '/packages/Rules/src',
    __DIR__ . '/packages/Rules/tests',
    __DIR__ . '/packages/Segments/src',
    __DIR__ . '/packages/Segments/tests',
    __DIR__ . '/packages/Utils/src',
    __DIR__ . '/packages/Utils/tests',
    __DIR__ . '/tests/CrossSdk',
];

$finder = PhpCsFixer\Finder::create()
    ->in(array_filter($directories, 'is_dir'))
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'strict_param' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
