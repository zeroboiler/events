<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\LogOrderEvent;
use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

describe('TriggerBuilder resolveActions merge logic', function (): void {
    it('deduplicates when action() and actions() contain same class', function (): void {
        $trigger = app(\ZeroBoiler\Events\EventManager::class)
            ->on('test.dedup')
            ->name('Dedup Test')
            ->action(SendOrderNotification::class)
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->save();

        expect($trigger->action)->toBeJson();
        $decoded = json_decode($trigger->action, true);
        // Both should be present but deduplicated — SendOrderNotification only once
        expect($decoded)->toHaveCount(2);
        expect($decoded[0])->toBe(SendOrderNotification::class);
        expect($decoded[1])->toBe(LogOrderEvent::class);
    });

    it('preserves insertion order when all classes are unique', function (): void {
        $trigger = app(\ZeroBoiler\Events\EventManager::class)
            ->on('test.order')
            ->name('Order Test')
            ->action(SendOrderNotification::class)
            ->actions([LogOrderEvent::class])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toHaveCount(2);
        expect($decoded[0])->toBe(SendOrderNotification::class);
        expect($decoded[1])->toBe(LogOrderEvent::class);
    });

    it('handles action() only without actions() call as plain string', function (): void {
        $trigger = app(\ZeroBoiler\Events\EventManager::class)
            ->on('test.single')
            ->name('Single Test')
            ->action(SendOrderNotification::class)
            ->save();

        // Single action without params is stored as plain string (not JSON)
        expect($trigger->action)->toBe(SendOrderNotification::class);
    });

    it('handles actions() only without action() call as JSON array', function (): void {
        $trigger = app(\ZeroBoiler\Events\EventManager::class)
            ->on('test.multi-only')
            ->name('Multi Only Test')
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBe([SendOrderNotification::class, LogOrderEvent::class]);
    });

    it('rejects empty string in actions() array', function (): void {
        app(\ZeroBoiler\Events\EventManager::class)
            ->on('test.empty-action')
            ->name('Empty Action Test')
            ->actions(['', SendOrderNotification::class])
            ->save();
    })->throws(\InvalidArgumentException::class, 'Each action class must be a non-empty string.');

    it('handles three duplicates from actions() while action() is different', function (): void {
        $trigger = app(\ZeroBoiler\Events\EventManager::class)
            ->on('test.triple-dedup')
            ->name('Triple Dedup Test')
            ->action(SendOrderNotification::class)
            ->actions([LogOrderEvent::class, LogOrderEvent::class, LogOrderEvent::class])
            ->save();

        $decoded = json_decode($trigger->action, true);
        // SendOrderNotification + LogOrderEvent (deduped from 3 to 1)
        expect($decoded)->toHaveCount(2);
        expect($decoded[0])->toBe(SendOrderNotification::class);
        expect($decoded[1])->toBe(LogOrderEvent::class);
    });
});
