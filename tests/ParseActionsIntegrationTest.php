<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use ZeroBoiler\Events\Tests\Actions\LogOrderEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

test('multi-action trigger with shared params dispatches all actions via parseActions classes key', function (): void {
    $eventManager = app(EventManager::class);

    // Register a trigger with multiple actions and shared params using the 'classes' format
    $trigger = $eventManager->on('order.multi')
        ->name('Multi Action Test')
        ->actions([SendOrderNotification::class, LogOrderEvent::class])
        ->actionParams(['webhook_url' => 'https://example.com/hooks'])
        ->priority(5)
        ->save();

    expect($trigger)->toBeInstanceOf(Trigger::class);
    expect($trigger->action)->toBeJson();

    $decoded = json_decode($trigger->action, true);
    expect($decoded)->toHaveKey('classes');
    expect($decoded['classes'])->toContain(SendOrderNotification::class);
    expect($decoded['classes'])->toContain(LogOrderEvent::class);
    expect($decoded)->toHaveKey('params');
    expect($decoded['params'])->toBe(['webhook_url' => 'https://example.com/hooks']);
});

test('parseActions handles classes+params JSON format via trigger save', function (): void {
    $eventManager = app(EventManager::class);

    // Build a trigger with classes + params and save it
    $trigger = $eventManager->on('parse.test')
        ->name('Parse Test')
        ->actions([\ZeroBoiler\Events\Tests\Actions\Foo', \ZeroBoiler\Events\Tests\Actions\Bar'])
        ->actionParams(['url' => 'https://example.com'])
        ->save();

    // Reload from DB to verify persistence
    $reloaded = Trigger::find($trigger->id);
    expect($reloaded)->not->toBeNull();

    $decoded = json_decode($reloaded->action, true);
    expect($decoded)->toHaveKey('classes');
    expect($decoded['classes'])->toBe([\ZeroBoiler\Events\Tests\Actions\Foo', \ZeroBoiler\Events\Tests\Actions\Bar']);
    expect($decoded['params'])->toBe(['url' => 'https://example.com']);
});

test('parseActions handles single class with params via trigger save', function (): void {
    $eventManager = app(EventManager::class);

    $trigger = $eventManager->on('parse.single')
        ->name('Parse Single Test')
        ->action(\ZeroBoiler\Events\Tests\Actions\Single')
        ->actionParams(['key' => 'value'])
        ->save();

    $reloaded = Trigger::find($trigger->id);
    expect($reloaded)->not->toBeNull();

    $decoded = json_decode($reloaded->action, true);
    expect($decoded)->toHaveKey('class');
    expect($decoded['class'])->toBe(\ZeroBoiler\Events\Tests\Actions\Single');
    expect($decoded['params'])->toBe(['key' => 'value']);
});

test('parseActions handles plain class name via trigger save', function (): void {
    $eventManager = app(EventManager::class);

    $trigger = $eventManager->on('parse.plain')
        ->name('Parse Plain Test')
        ->action(\ZeroBoiler\Events\Tests\Actions\Plain')
        ->save();

    $reloaded = Trigger::find($trigger->id);
    expect($reloaded)->not->toBeNull();
    expect($reloaded->action)->toBe(\ZeroBoiler\Events\Tests\Actions\Plain');
});

test('parseActions handles JSON array of class names via trigger save', function (): void {
    $eventManager = app(EventManager::class);

    $trigger = $eventManager->on('parse.array')
        ->name('Parse Array Test')
        ->actions([\ZeroBoiler\Events\Tests\Actions\Foo', \ZeroBoiler\Events\Tests\Actions\Bar'])
        ->save();

    $reloaded = Trigger::find($trigger->id);
    expect($reloaded)->not->toBeNull();

    $decoded = json_decode($reloaded->action, true);
    expect($decoded)->toBe([\ZeroBoiler\Events\Tests\Actions\Foo', \ZeroBoiler\Events\Tests\Actions\Bar']);
});

test('trigger save with both action() and actions() merges without duplicates', function (): void {
    $eventManager = app(EventManager::class);

    $trigger = $eventManager->on('merge.test')
        ->name('Merge Test')
        ->action(\ZeroBoiler\Events\Tests\Actions\First')
        ->actions([\ZeroBoiler\Events\Tests\Actions\Second', \ZeroBoiler\Events\Tests\Actions\Third'])
        ->save();

    $reloaded = Trigger::find($trigger->id);
    expect($reloaded)->not->toBeNull();

    $decoded = json_decode($reloaded->action, true);
    expect($decoded)->toBe([
        \ZeroBoiler\Events\Tests\Actions\First',
        \ZeroBoiler\Events\Tests\Actions\Second',
        \ZeroBoiler\Events\Tests\Actions\Third',
    ]);
});

test('trigger save with duplicate action classes deduplicates', function (): void {
    $eventManager = app(EventManager::class);

    $trigger = $eventManager->on('dedup.test')
        ->name('Dedup Test')
        ->action(\ZeroBoiler\Events\Tests\Actions\First')
        ->actions([\ZeroBoiler\Events\Tests\Actions\First', \ZeroBoiler\Events\Tests\Actions\Second'])
        ->save();

    $reloaded = Trigger::find($trigger->id);
    expect($reloaded)->not->toBeNull();

    $decoded = json_decode($reloaded->action, true);
    expect($decoded)->toBe([
        \ZeroBoiler\Events\Tests\Actions\First',
        \ZeroBoiler\Events\Tests\Actions\Second',
    ]);
});
