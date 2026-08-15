<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Models\Subscription;

uses(TestCase::class);

describe('Model Boot Docblock Consistency', function (): void {
    it('EventLog has a docblock on its boot() method', function (): void {
        $reflection = new ReflectionMethod(EventLog::class, 'boot');
        $docComment = $reflection->getDocComment();

        expect($docComment)->not->toBeFalse();
        expect($docComment)->toContain('booted');
    });

    it('Trigger has a docblock on its boot() method', function (): void {
        $reflection = new ReflectionMethod(Trigger::class, 'boot');
        $docComment = $reflection->getDocComment();

        expect($docComment)->not->toBeFalse();
        expect($docComment)->toContain('booted');
    });

    it('Subscription has a docblock on its boot() method', function (): void {
        $reflection = new ReflectionMethod(Subscription::class, 'boot');
        $docComment = $reflection->getDocComment();

        expect($docComment)->not->toBeFalse();
        expect($docComment)->toContain('booted');
    });

    it('EventLog boot() has #[Override] attribute', function (): void {
        $reflection = new ReflectionMethod(EventLog::class, 'boot');
        $attributes = $reflection->getAttributes(\Override::class);

        expect($attributes)->toHaveCount(1);
    });

    it('Trigger boot() has #[Override] attribute', function (): void {
        $reflection = new ReflectionMethod(Trigger::class, 'boot');
        $attributes = $reflection->getAttributes(\Override::class);

        expect($attributes)->toHaveCount(1);
    });

    it('Subscription boot() has #[Override] attribute', function (): void {
        $reflection = new ReflectionMethod(Subscription::class, 'boot');
        $attributes = $reflection->getAttributes(\Override::class);

        expect($attributes)->toHaveCount(1);
    });

    it('all three boot methods are protected and static', function (): void {
        foreach ([EventLog::class, Trigger::class, Subscription::class] as $model) {
            $reflection = new ReflectionMethod($model, 'boot');

            expect($reflection->isPublic())->toBeFalse();
            expect($reflection->isProtected())->toBeTrue();
            expect($reflection->isStatic())->toBeTrue();
        }
    });

    it('all three boot methods have void return type', function (): void {
        foreach ([EventLog::class, Trigger::class, Subscription::class] as $model) {
            $reflection = new ReflectionMethod($model, 'boot');
            $returnType = $reflection->getReturnType();

            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('void');
        }
    });
});
