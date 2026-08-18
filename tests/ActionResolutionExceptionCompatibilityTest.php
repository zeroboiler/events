<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Exceptions\ActionResolutionException;

describe('ActionResolutionException', function (): void {
    test('is catchable as EventException', function (): void {
        $e = new ActionResolutionException('App\\Actions\\Foo');
        expect($e)->toBeInstanceOf(\ZeroBoiler\Events\Exceptions\EventException::class);
    });

    test('is catchable as RuntimeException', function (): void {
        $e = new ActionResolutionException('App\\Actions\\Foo');
        expect($e)->toBeInstanceOf(\RuntimeException::class);
    });

    test('is catchable by generic Throwable handler', function (): void {
        $thrown = false;
        try {
            throw new ActionResolutionException('App\\Actions\\Missing');
        } catch (\Throwable $t) {
            $thrown = ($t instanceof ActionResolutionException);
        }
        expect($thrown)->toBeTrue();
    });

    test('preserves error message from ActionResolver', function (): void {
        $e = new ActionResolutionException('App\\Actions\\Foo', 'Class does not exist');
        expect($e->getMessage())->toContain('App\\Actions\\Foo');
        expect($e->getMessage())->toContain('Class does not exist');
    });
});
