<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventsFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

require_once __DIR__.'/TestActions.php';

uses(\ZeroBoiler\Events\Tests\TestCase::class);

beforeEach(function (): void {
    // Ensure events are enabled before each test
    config(['events.disabled' => false]);
});

describe('EventManager::isDisabled', function (): void {
    it('returns false when events.disabled config is not set', function (): void {
        config()->offsetUnset('events.disabled');

        $manager = app(EventManager::class);
        expect($manager->isDisabled())->toBeFalse();
    });

    it('returns false when events.disabled is false', function (): void {
        config(['events.disabled' => false]);

        $manager = app(EventManager::class);
        expect($manager->isDisabled())->toBeFalse();
    });

    it('returns false when events.disabled is a non-true value', function (): void {
        config(['events.disabled' => 'yes']);
        config(['events.disabled' => 1]);

        $manager = app(EventManager::class);
        expect($manager->isDisabled())->toBeFalse();
    });

    it('returns true when events.disabled is true', function (): void {
        config(['events.disabled' => true]);

        $manager = app(EventManager::class);
        expect($manager->isDisabled())->toBeTrue();
    });
});

describe('EventManager::setEnabled', function (): void {
    it('enables the event system', function (): void {
        config(['events.disabled' => true]);

        $manager = app(EventManager::class);
        $manager->setEnabled(true);

        expect($manager->isDisabled())->toBeFalse();
    });

    it('disables the event system', function (): void {
        config(['events.disabled' => false]);

        $manager = app(EventManager::class);
        $manager->setEnabled(false);

        expect($manager->isDisabled())->toBeTrue();
    });
});

describe('EventManager::fire with global disable', function (): void {
    it('dispatches triggers when not disabled', function (): void {
        config(['events.disabled' => false]);

        $trigger = Trigger::factory()->create([
            'event' => 'test.global',
            'action' => json_encode(\App\Actions\SendOrderNotification::class),
            'enabled' => true,
        ]);

        app(EventManager::class)->fire('test.global', ['key' => 'value']);

        expect(EventLog::where('trigger_id', $trigger->id)->exists())->toBeTrue();
    });

    it('skips dispatch when globally disabled', function (): void {
        config(['events.disabled' => true]);

        Trigger::factory()->create([
            'event' => 'test.global.disabled',
            'action' => json_encode(\App\Actions\SendOrderNotification::class),
            'enabled' => true,
        ]);

        app(EventManager::class)->fire('test.global.disabled', ['key' => 'value']);

        expect(EventLog::where('event', 'test.global.disabled')->exists())->toBeFalse();
    });

    it('still throws on empty event name even when disabled', function (): void {
        config(['events.disabled' => true]);

        $manager = app(EventManager::class);

        expect(fn (): mixed => $manager->fire(''))
            ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty.');
    });

    it('still throws on zero event name even when disabled', function (): void {
        config(['events.disabled' => true]);

        $manager = app(EventManager::class);

        expect(fn (): mixed => $manager->fire('0'))
            ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty.');
    });
});

describe('Facade proxy for global disable', function (): void {
    it('isDisabled returns correct state via facade', function (): void {
        config(['events.disabled' => true]);

        expect(EventsFacade::isDisabled())->toBeTrue();
    });

    it('setEnabled works via facade', function (): void {
        config(['events.disabled' => true]);

        EventsFacade::setEnabled(true);

        expect(EventsFacade::isDisabled())->toBeFalse();
    });
});
