<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Rector\CodingStyle\Rector\ClassMethod\NewlineBeforeNewAssignSetRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;
use Rector\Strict\Rector\BooleanNot\BooleanNotIdenticalToNegatedInstanceofRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddReturnTypeDeclarationRector;
use Rector\TypeDeclaration\Rector\FunctionLike\ParamTypeDeclarationRector;
use Rector\TypeDeclaration\Rector\Property\AddPropertyTypeDeclarationRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_85)
    ->withRules([
        // Type declarations
        AddReturnTypeDeclarationRector::class,
        AddPropertyTypeDeclarationRector::class,
        ParamTypeDeclarationRector::class,
        // Code style
        NewlineBeforeNewAssignSetRector::class,
        NewlineAfterStatementRector::class,
        // Strict
        BooleanNotIdenticalToNegatedInstanceofRector::class,
        DisallowedEmptyRuleFixerRector::class,
    ])
    ->withSkip([
        // Skip test fixtures and factories
        __DIR__.'/tests/Fixtures',
    ])
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0);
