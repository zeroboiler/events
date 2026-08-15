<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\Trigger;

describe('TriggerBuilder action deduplication and merging', function (): void {
    test('save() with both action() and actions() merges and deduplicates', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->on('merge.test')
            ->action('ActionA')
            ->actions(['ActionA', 'ActionB', 'ActionC'])
            ->save();

        $decoded = json_decode($trigger->action, true);

        // Should be a sequential array with 3 unique classes
        expect($decoded)->toBeArray()
            ->and($decoded)->toHaveCount(3)
            ->and($decoded)->toBe(['ActionA', 'ActionB', 'ActionC']);

        $trigger->delete();
    });

    test('save() with action() containing same class as actions() does not duplicate', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->on('dedup.test')
            ->action('SameAction')
            ->actions(['SameAction', 'OtherAction'])
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)->toBeArray()
            ->and($decoded)->toHaveCount(2)
            ->and($decoded[0])->toBe('SameAction')
            ->and($decoded[1])->toBe('OtherAction');

        $trigger->delete();
    });

    test('save() with only action() stores plain class name', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->on('single.test')
            ->action('SingleAction')
            ->save();

        expect($trigger->action)->toBe('SingleAction');

        $trigger->delete();
    });

    test('save() with only actions() stores JSON array', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->on('multi.test')
            ->actions(['FirstAction', 'SecondAction'])
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)->toBeArray()
            ->and($decoded)->toBe(['FirstAction', 'SecondAction']);

        $trigger->delete();
    });

    test('save() with actionParams and single action stores class+params object', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->on('params.single.test')
            ->action('ParamAction')
            ->actionParams(['url' => 'https://example.com'])
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)->toBeArray()
            ->and($decoded['class'])->toBe('ParamAction')
            ->and($decoded['params'])->toBe(['url' => 'https://example.com']);

        $trigger->delete();
    });

    test('save() with actionParams and multiple actions stores classes+params object', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->on('params.multi.test')
            ->actions(['ActionOne', 'ActionTwo'])
            ->actionParams(['webhook_url' => 'https://hook.example.com'])
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)->toBeArray()
            ->and($decoded['classes'])->toBe(['ActionOne', 'ActionTwo'])
            ->and($decoded['params'])->toBe(['webhook_url' => 'https://hook.example.com']);

        $trigger->delete();
    });

    test('save() auto-generates name from event when not provided', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->on('auto.name.test')
            ->action('AutoNameAction')
            ->save();

        expect($trigger->name)->toBe('auto.name.test Trigger');

        $trigger->delete();
    });

    test('save() throws on empty event name', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $manager->on('')
            ->action('SomeAction');
    })->throws(\InvalidArgumentException::class, 'Event name is required');

    test('save() throws when no action provided', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $manager->on('no.action.test')
            ->save();
    })->throws(\InvalidArgumentException::class, 'At least one action is required');
});
