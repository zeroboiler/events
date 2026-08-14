<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Laravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
    ])
    ->withSets([
        LaravelSetList::LARAVEL_130,
    ])
    ->withSkip([
        // Skip specific files if needed
    ])
    ->withPreparedness(
        deadCode: false,
        codeQuality: true,
        typeDeclarations: true,
        naming: false,
        privatization: false,
        instanceOf: true,
        strictTypes: true,
        earlyReturn: true,
        codeLength: false,
    );
