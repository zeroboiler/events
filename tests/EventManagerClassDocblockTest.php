<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;

test('EventManager has class-level docblock', function (): void {
    $reflection = new ReflectionClass(EventManager::class);

    $docComment = $reflection->getDocComment();

    expect($docComment)->nottoBeFalse();
    expect($docComment)->toContain('Central event orchestrator');
    expect($docComment)->toContain('singleton');
    expect($docComment)->toContain('EventManager');
});

test('EventManager class-level docblock references facade', function (): void {
    $reflection = new ReflectionClass(EventManager::class);

    $docComment = $reflection->getDocComment();

    expect($docComment)->nottoBeFalse();
    expect($docComment)->toContain('@see');
    expect($docComment)->toContain('\\ZeroBoiler\\Events\\Facades\\EventManager');
});
