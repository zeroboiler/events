<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    $this->app = $this->createApplication();
    $this->eventManager = $this->app->make(EventManager::class);
});

describe('TriggerBuilder classes+params JSON format', function (): void {
    test('single action with params produces correct JSON structure', function (): void {
        $trigger = $this->eventManager->on('classes.params.single')
            ->action(ClassesParamsNoOpAction::class)
            ->actionParams(['url' => 'https://example.com/webhook', 'api_key' => 'secret123'])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray()
            ->and($decoded['class'])->toBe(ClassesParamsNoOpAction::class)
            ->and($decoded['params'])->toBe(['url' => 'https://example.com/webhook', 'api_key' => 'secret123']);
    });

    test('multiple actions with params produces classes+params JSON structure', function (): void {
        $trigger = $this->eventManager->on('classes.params.multi')
            ->actions([
                ClassesParamsNoOpAction::class,
                'App\\Actions\\SecondAction',
            ])
            ->actionParams(['shared_param' => 'value1'])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray()
            ->and($decoded['classes'])->toBe([
                ClassesParamsNoOpAction::class,
                'App\\Actions\\SecondAction',
            ])
            ->and($decoded['params'])->toBe(['shared_param' => 'value1']);
    });

    test('multiple actions without params produces JSON array of strings', function (): void {
        $trigger = $this->eventManager->on('classes.params.no-params')
            ->actions([
                ClassesParamsNoOpAction::class,
                'App\\Actions\\AnotherAction',
            ])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray()
            ->and($decoded)->toBe([
                ClassesParamsNoOpAction::class,
                'App\\Actions\\AnotherAction',
            ]);
    });

    test('action() and actions() merged with params uses classes key', function (): void {
        $trigger = $this->eventManager->on('classes.params.merged')
            ->action(ClassesParamsNoOpAction::class)
            ->actions(['App\\Actions\\ExtraAction'])
            ->actionParams(['url' => 'https://example.com'])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray()
            ->and($decoded['classes'])->toBe([
                ClassesParamsNoOpAction::class,
                'App\\Actions\\ExtraAction',
            ])
            ->and($decoded['params'])->toBe(['url' => 'https://example.com']);
    });

    test('single action without params produces plain string', function (): void {
        $trigger = $this->eventManager->on('classes.params.plain')
            ->action(ClassesParamsNoOpAction::class)
            ->save();

        expect($trigger->action)->toBe(ClassesParamsNoOpAction::class);
    });
});

/**
 * No-op action for classes+params JSON format tests.
 */
final class ClassesParamsNoOpAction implements \ZeroBoiler\Events\Contracts\Triggerable
{
    #[\Override]
    public function handle(array $payload): void {}
}
