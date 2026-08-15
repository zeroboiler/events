<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

describe('EventManager fire() edge cases with zero-like event names', function (): void {
    it('rejects empty string event name', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Event name cannot be empty.');

        $manager->fire('', ['key' => 'value']);
    });

    it('rejects "0" string event name', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Event name cannot be empty.');

        $manager->fire('0', ['key' => 'value']);
    });

    it('accepts event name containing zero', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        // Should NOT throw — event name "event.phase0" is valid
        $manager->fire('event.phase0', ['key' => 'value']);

        expect(true)->toBeTrue();
    });

    it('accepts numeric string event name that is not "0"', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        // Should NOT throw — event name "123" is a valid non-zero string
        $manager->fire('123', ['key' => 'value']);

        expect(true)->toBeTrue();
    });
});
