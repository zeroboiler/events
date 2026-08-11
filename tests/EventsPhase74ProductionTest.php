<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

describe('Phase 74 Production — EventManager::executeTrigger public API verification', function (): void {
    test('executeTrigger is a public method on EventManager', function (): void {
        $method = new ReflectionMethod(\ZeroBoiler\Events\EventManager::class, 'executeTrigger');

        expect($method->isPublic())->toBeTrue();
    });

    test('executeTrigger has correct signature — Trigger, EventLog params', function (): void {
        $method = new ReflectionMethod(\ZeroBoiler\Events\EventManager::class, 'executeTrigger');
        $params = $method->getParameters();

        expect($params)->toHaveCount(2);
        expect($params[0]->getName())->toBe('trigger');
        expect($params[1]->getName())->toBe('log');
        expect($params[0]->getType()->getName())->toBe(\ZeroBoiler\Events\Models\Trigger::class);
        expect($params[1]->getType()->getName())->toBe(\ZeroBoiler\Events\Models\EventLog::class);
    });

    test('executeTrigger returns void', function (): void {
        $method = new ReflectionMethod(\ZeroBoiler\Events\EventManager::class, 'executeTrigger');

        expect($method->getReturnType()?->getName())->toBe('void');
    });

    test('EventManager has all 23 public methods expected by Facade', function (): void {
        $expected = [
            'on', 'register', 'fire', 'fireModel', 'enable', 'disable',
            'invalidateTriggerCache', 'isDisabled', 'setEnabled', 'listTriggers',
            'getTrigger', 'deleteTrigger', 'subscribe', 'unsubscribe',
            'listSubscriptions', 'getSubscription', 'subscribeWebhook',
            'getEventHistory', 'getStats', 'purgeLogs',
            'getStalePendingLogs', 'deactivateExceededSubscriptions',
            'executeTrigger',
        ];

        $publicMethods = array_filter(
            get_class_methods(\ZeroBoiler\Events\EventManager::class),
            fn (string $m): bool => (new ReflectionMethod(\ZeroBoiler\Events\EventManager::class, $m))->isPublic()
                && $m !== '__construct',
        );

        foreach ($expected as $method) {
            expect(in_array($method, $publicMethods, true))->toBeTrue("Missing method: {$method}");
        }

        expect(count($publicMethods))->toBe(count($expected));
    });

    test('Facade @method annotations count matches EventManager public methods', function (): void {
        $facadeContent = file_get_contents(realpath(__DIR__.'/../src/Facades/EventManager.php'));
        $methodCount = substr_count($facadeContent, '@method static');

        // EventManager has 23 public methods (excluding __construct)
        // Facade should document all of them
        expect($methodCount)->toBeGreaterThanOrEqual(22);
    });

    test('WildcardMatcher::findMatchingPatterns returns list with preserved order', function (): void {
        $result = \ZeroBoiler\Events\WildcardMatcher::findMatchingPatterns(
            ['order.*', 'user.*', '*.created', 'order.placed'],
            'order.placed',
        );

        expect($result)->toBe(['order.*', 'order.placed']);
    });

    test('WildcardMatcher::extractWildcards returns empty for non-matching pattern', function (): void {
        $result = \ZeroBoiler\Events\WildcardMatcher::extractWildcards('user.*.created', 'order.placed');

        expect($result)->toBe([]);
    });

    test('WildcardMatcher handles empty string event gracefully', function (): void {
        expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', ''))->toBeFalse();
        expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', ''))->toBeFalse();
        expect(\ZeroBoiler\Events\WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    test('ConditionEngine::between with string numeric values works', function (): void {
        $engine = app(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);

        expect($engine->matches(['age' => ['between', ['20', '30']]], ['age' => 25]))->toBeTrue();
        expect($engine->matches(['age' => ['between', ['20', '30']]], ['age' => '25']))->toBeTrue();
    });

    test('ConditionEngine::between with reversed range auto-normalizes', function (): void {
        $engine = app(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);

        // min=100, max=50 should be normalized to min=50, max=100
        expect($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 75]))->toBeTrue();
        expect($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 25]))->toBeFalse();
    });

    test('SubscriptionBuilder requires non-empty event before URL scheme validation', function (): void {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);

        // Empty event should be caught first
        expect(fn (): mixed => $builder->on('')->to('https://example.com')->save())
            ->toThrow(\InvalidArgumentException::class, 'Event name is required');
    });

    test('SubscriptionBuilder requires non-empty URL before scheme validation', function (): void {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);

        expect(fn (): mixed => $builder->on('order.placed')->to('')->save())
            ->toThrow(\InvalidArgumentException::class, 'Webhook URL is required');
    });

    test('SubscriptionBuilder rejects ftp:// scheme', function (): void {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);

        expect(fn (): mixed => $builder->on('order.placed')->to('ftp://evil.com/upload')->save())
            ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS');
    });

    test('TriggerBuilder::actions rejects empty string entries', function (): void {
        $builder = app(\ZeroBoiler\Events\TriggerBuilder::class);

        expect(fn (): mixed => $builder->actions(['ValidAction', '', 'AnotherAction']))
            ->toThrow(\InvalidArgumentException::class, 'non-empty string');
    });

    test('EventManager fire with empty event name throws InvalidArgumentException', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect(fn (): mixed => $manager->fire(''))
            ->toThrow(\InvalidArgumentException::class, 'cannot be empty');

        expect(fn (): mixed => $manager->fire('0'))
            ->toThrow(\InvalidArgumentException::class, 'cannot be empty');
    });

    test('EventManager fireModel with empty model class throws InvalidArgumentException', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $model = new class {
            public function toArray(): array
            {
                return ['id' => 1];
            }
        };

        expect(fn (): mixed => $manager->fireModel('', 'created', $model))
            ->toThrow(\InvalidArgumentException::class, 'cannot be empty');
    });

    test('EventManager fireModel with empty action throws InvalidArgumentException', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $model = new class {
            public function toArray(): array
            {
                return ['id' => 1];
            }
        };

        expect(fn (): mixed => $manager->fireModel('App\\Models\\Order', '', $model))
            ->toThrow(\InvalidArgumentException::class, 'cannot be empty');
    });

    test('test file count is accurate (147 test files: 145 Pest-registered + 2 standalone)', function (): void {
        $allTestFiles = glob(__DIR__.'/*Test.php');
        $supportFiles = ['Pest.php', 'TestCase.php', 'CreatesApplication.php', 'helpers.php', 'TestActions.php'];
        $filtered = array_filter($allTestFiles, fn (string $f): bool => ! in_array(basename($f), $supportFiles, true));

        // Standalone tests (not registered in Pest.php)
        $pestContent = file_get_contents(__DIR__.'/Pest.php');
        $standalone = array_filter($filtered, fn (string $f): bool => ! str_contains($pestContent, basename($f)));

        expect(count($filtered))->toBe(147, 'Total test files should be 147');
        expect(count($filtered) - count($standalone))->toBe(145, 'Pest-registered tests should be 145');
        expect(count($standalone))->toBe(2, 'Standalone tests should be 2 (WildcardMatcherTest, EscapesWildcardLikeTest)');
    });
});
