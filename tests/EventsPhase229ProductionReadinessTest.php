<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\Tests\Actions\CountingAction;
use ZeroBoiler\Events\Tests\Actions\NullAction;

describe('Phase 229 — Production Readiness', function (): void {
    describe('TriggerBuilder multi-action with params', function (): void {
        test('save() with multiple actions and actionParams uses classes+params JSON format', function (): void {
            $this->app->bind(NullAction::class, fn (): NullAction => new NullAction);
            $this->app->bind(CountingAction::class, fn (): CountingAction => new CountingAction);

            $trigger = $this->eventManager->on('multi.action.test')
                ->actions([NullAction::class, CountingAction::class])
                ->actionParams(['webhook_url' => 'https://example.com/hook'])
                ->save();

            expect($trigger)->not->toBeNull();
            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toBeArray();
            expect($decoded)->toHaveKey('classes');
            expect($decoded['classes'])->toBe([
                'ZeroBoiler\\Events\\Tests\\Actions\\NullAction',
                'ZeroBoiler\\Events\\Tests\\Actions\\CountingAction',
            ]);
            expect($decoded['params'])->toBe(['webhook_url' => 'https://example.com/hook']);
        });

        test('save() with single action and actionParams uses class+params JSON format', function (): void {
            $this->app->bind(NullAction::class, fn (): NullAction => new NullAction);

            $trigger = $this->eventManager->on('single.action.params')
                ->action(NullAction::class)
                ->actionParams(['url' => 'https://example.com'])
                ->save();

            expect($trigger)->not->toBeNull();
            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toBeArray();
            expect($decoded['class'])->toBe('ZeroBoiler\\Events\\Tests\\Actions\\NullAction');
            expect($decoded['params'])->toBe(['url' => 'https://example.com']);
        });

        test('save() with multiple actions but no params uses plain JSON array', function (): void {
            $this->app->bind(NullAction::class, fn (): NullAction => new NullAction);
            $this->app->bind(CountingAction::class, fn (): CountingAction => new CountingAction);

            $trigger = $this->eventManager->on('multi.no.params')
                ->actions([NullAction::class, CountingAction::class])
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toBe([
                'ZeroBoiler\\Events\\Tests\\Actions\\NullAction',
                'ZeroBoiler\\Events\\Tests\\Actions\\CountingAction',
            ]);
        });
    });

    describe('EventManager parseActions multi-action with params', function (): void {
        test('fire() dispatches multiple actions and merges params into payload', function (): void {
            $countingAction = new CountingAction;
            $nullAction = new NullAction;
            $this->app->bind(CountingAction::class, fn (): CountingAction => $countingAction);
            $this->app->bind(NullAction::class, fn (): NullAction => $nullAction);

            // Create trigger with classes+params JSON
            $trigger = Trigger::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'name' => 'Multi Action Params',
                'event' => 'multi.dispatch.params',
                'action' => json_encode([
                    'classes' => [NullAction::class, CountingAction::class],
                    'params' => ['extra_key' => 'extra_value'],
                ]),
                'conditions' => [],
                'async' => false,
                'priority' => 0,
                'enabled' => true,
            ]);

            $this->eventManager->fire('multi.dispatch.params', ['original_key' => 'original_value']);

            expect($countingAction->callCount)->toBe(1);
            expect($countingAction->calls[0])->toHaveKey('extra_key');
            expect($countingAction->calls[0]['extra_key'])->toBe('extra_value');
            expect($countingAction->calls[0])->toHaveKey('original_key');
        });
    });

    describe('WebhookAction missing URL edge case', function (): void {
        test('handle() throws InvalidArgumentException when payload has no url key', function (): void {
            $action = $this->app->make(\ZeroBoiler\Events\Actions\WebhookAction::class);

            expect(fn (): mixed => $action->handle(['data' => 'test']))
                ->toThrow(\InvalidArgumentException::class, 'non-empty "url"');
        });

        test('handle() throws InvalidArgumentException when url is empty string', function (): void {
            $action = $this->app->make(\ZeroBoiler\Events\Actions\WebhookAction::class);

            expect(fn (): mixed => $action->handle(['url' => '']))
                ->toThrow(\InvalidArgumentException::class, 'non-empty "url"');
        });

        test('handle() throws InvalidArgumentException when url is non-string', function (): void {
            $action = $this->app->make(\ZeroBoiler\Events\Actions\WebhookAction::class);

            expect(fn (): mixed => $action->handle(['url' => 12345]))
                ->toThrow(\InvalidArgumentException::class, 'non-empty "url"');
        });
    });

    describe('SubscriptionBuilder edge cases', function (): void {
        test('save() rejects FTP URL scheme', function (): void {
            $builder = $this->app->make(SubscriptionBuilder::class);

            expect(fn (): mixed => $builder
                ->on('test.event')
                ->to('ftp://evil.com/upload')
                ->save())
                ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS');
        });

        test('save() rejects file:// URL scheme', function (): void {
            $builder = $this->app->make(SubscriptionBuilder::class);

            expect(fn (): mixed => $builder
                ->on('test.event')
                ->to('file:///etc/passwd')
                ->save())
                ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS');
        });

        test('save() rejects javascript: URL scheme', function (): void {
            $builder = $this->app->make(SubscriptionBuilder::class);

            expect(fn (): mixed => $builder
                ->on('test.event')
                ->to('javascript:alert(1)')
                ->save())
                ->toThrow(\InvalidArgumentException::class);
        });

        test('save() accepts HTTPS URL', function (): void {
            $builder = $this->app->make(SubscriptionBuilder::class);

            // We can't actually save without DB, so just test URL validation passes
            expect(fn (): mixed => $builder
                ->on('test.event')
                ->to('https://example.com/webhook')
                ->save())->not->toThrow(\InvalidArgumentException::class);
        });
    });

    describe('EventLog status constants completeness', function (): void {
        test('all statuses are in the $statuses array', function (): void {
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
            expect(EventLog::$statuses)->toHaveCount(4);
        });
    });

    describe('WildcardMatcher findMatchingPatterns', function (): void {
        test('returns matching patterns from a list', function (): void {
            $patterns = ['order.*', 'user.created', '*.deleted', 'payment.**'];

            $result = \ZeroBoiler\Events\WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');
            expect($result)->toBe(['order.*']);
        });

        test('returns multiple matching patterns', function (): void {
            $patterns = ['order.*', 'order.**', '*.placed'];

            $result = \ZeroBoiler\Events\WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');
            expect($result)->toContain('order.*');
            expect($result)->toContain('order.**');
            expect($result)->toContain('*.placed');
        });

        test('returns empty array when no patterns match', function (): void {
            $patterns = ['user.*', 'payment.**'];

            $result = \ZeroBoiler\Events\WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');
            expect($result)->toBe([]);
        });
    });
});
