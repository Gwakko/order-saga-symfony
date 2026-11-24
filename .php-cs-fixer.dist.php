<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/phpstan',
        __DIR__ . '/tests',
        __DIR__ . '/gateway-emulator',
    ])
    ->exclude(['Fixtures', 'vendor', 'var'])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
    ])
    ->setFinder($finder)
;
