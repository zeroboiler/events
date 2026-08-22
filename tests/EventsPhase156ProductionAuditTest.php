<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\WildcardMatcher;

describe('EventManager parseActions Edge Cases', function (): void {
    test('empty string action returns empty array', function (): void {
        $manager = app(EventManager::class);
        $reflection = new ReflectionMethod($manager, 'parseActions');
        $result = $reflection->invoke($manager, '');

        expect($result)->toBe([]);
    });

    test('zero string action returns empty array', function (): void {
        $manager = app(EventManager::class);
        $reflection = new ReflectionMethod($manager, 'parseActions');
        $result = $reflection->invoke($manager, '0');

        expect($result)->toBe([]);
    });

    test('whitespace-only action returns empty array', function (): void {
        $manager = app(EventManager::class);
        $reflection = new ReflectionMethod($manager, 'parseActions');
        $result = $reflection->invoke($manager, '   ');

        expect($result)->toBe([]);
    });

    test('single class name string returns single-element array', function (): void {
        $manager = app(EventManager::class);
        $reflection = new ReflectionMethod($manager, 'parseActions');
        $result = $reflection->invoke($manager, '\ZeroBoiler\Events\Tests\Actions\SendEmail');

        expect($result)->toBe(['\ZeroBoiler\Events\Tests\Actions\SendEmail']);
    });

    test('JSON array of class names is returned as-is when sequential', function (): void {
        $manager = app(EventManager::class);
        $reflection = new ReflectionMethod($manager, 'parseActions');
        $json = json_encode(['\ZeroBoiler\Events\Tests\Actions\Foo', '\ZeroBoiler\Events\Tests\Actions\Bar']);
        $result = $reflection->invoke($manager, $json);

        expect($result)->toBe(['\ZeroBoiler\Events\Tests\Actions\Foo', '\ZeroBoiler\Events\Tests\Actions\Bar']);
    });

    test('JSON object with class key returns single-element array', function (): void {
        $manager = app(EventManager::class);
        $reflection = new ReflectionMethod($manager, 'parseActions');
        $json = json_encode(['class' => '\ZeroBoiler\Events\Tests\Actions\Webhook', 'params' => ['url' => 'https://example.com']]);
        $result = $reflection->invoke($manager, $json);

        expect($result)->toBe([
            ['class' => '\ZeroBoiler\Events\Tests\Actions\Webhook', 'params' => ['url' => 'https://example.com']],
        ]);
    });

    test('JSON classes+params format returns multi-action with shared params', function (): void {
        $manager = app(EventManager::class);
        $reflection = new ReflectionMethod($manager, 'parseActions');
        $json = json_encode([
            'classes' => ['\ZeroBoiler\Events\Tests\Actions\Log', '\ZeroBoiler\Events\Tests\Actions\Notify'],
            'params' => ['channel' => 'slack'],
        ]);
        $result = $reflection->invoke($manager, $json);

        expect($result)->toBe([
            ['class' => '\ZeroBoiler\Events\Tests\Actions\Log', 'params' => ['channel' => 'slack']],
            ['class' => '\ZeroBoiler\Events\Tests\Actions\Notify', 'params' => ['channel' => 'slack']],
        ]);
    });
});

describe('WildcardMatcher Pure Edge Cases', function (): void {
    test('pattern with only dots does not match event with different dots', function (): void {
        expect(WildcardMatcher::matches('a.b.c', 'x.y.z'))->toBeFalse();
    });

    test('empty pattern does not match empty event', function (): void {
        expect(WildcardMatcher::matches('', ''))->toBeFalse();
    });

    test('single star does not match empty event', function (): void {
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    });

    test('double star does not match empty event', function (): void {
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    test('exact match without wildcards works', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    });

    test('cross-segment wildcard matches zero additional segments', function (): void {
        expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.**', 'order.placed.extra.info'))->toBeTrue();
    });

    test('findMatchingPatterns preserves order', function (): void {
        $patterns = ['order.*', 'user.*', '*.created', 'order.placed'];
        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toBe(['order.*', 'order.placed']);
    });

    test('findMatchingPatterns returns empty for no matches', function (): void {
        $result = WildcardMatcher::findMatchingPatterns(['order.*', 'user.*'], 'payment.received');

        expect($result)->toBe([]);
    });

    test('extractWildcards returns empty for non-matching event', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'order.profile.created');

        expect($result)->toBe([]);
    });

    test('extractWildcards returns empty for empty pattern', function (): void {
        $result = WildcardMatcher::extractWildcards('', 'anything');

        expect($result)->toBe([]);
    });
});

describe('ConditionEngine Strict Types', function (): void {
    test('matches returns false for empty conditions and empty payload', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches([], []))->toBeTrue();
    });

    test('strict equals with same types works', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
        expect($engine->matches(['count' => 5], ['count' => 5]))->toBeTrue();
        expect($engine->matches(['count' => 5], ['count' => '5']))->toBeTrue(); // cross-type string comparison
    });

    test('strict equals with different non-scalar types returns false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['data' => 'hello'], ['data' => ['hello']]))->toBeFalse();
    });

    test('between operator normalizes inverted ranges', function (): void {
        $engine = app(ConditionEngine::class);

        // min > max → auto-normalizes
        expect($engine->matches(['value' => ['between', [100, 50]]], ['value' => 75]))->toBeTrue();
        expect($engine->matches(['value' => ['between', [100, 50]]], ['value' => 25]))->toBeFalse();
        expect($engine->matches(['value' => ['between', [100, 50]]], ['value' => 125]))->toBeFalse();
    });

    test('nested dot notation returns null for missing path', function (): void {
        $engine = app(ConditionEngine::class);

        // 'user.profile.name' does not exist in payload → null → 'null' operator matches
        expect($engine->matches(
            ['user.profile.name' => ['null']],
            ['user' => ['email' => 'test@example.com']],
        ))->toBeTrue();
    });

    test('not_empty operator handles non-empty arrays', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['tags' => ['not_empty']], ['tags' => ['a', 'b']]))->toBeTrue();
        expect($engine->matches(['tags' => ['not_empty']], ['tags' => []]))->toBeFalse();
    });

    test('matches operator rejects patterns over 500 chars', function (): void {
        $engine = app(ConditionEngine::class);
        $longPattern = '/^' . str_repeat('a', 500) . '$/';

        expect($engine->matches(
            ['code' => ['matches', $longPattern]],
            ['code' => str_repeat('a', 500)],
        ))->toBeFalse();
    });

    test('AND logic: all conditions must match', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(
            ['status' => 'active', 'amount' => ['>', 100], 'role' => ['in', ['admin', 'mod']]],
            ['status' => 'active', 'amount' => 150, 'role' => 'admin'],
        ))->toBeTrue();

        // One condition fails → false
        expect($engine->matches(
            ['status' => 'active', 'amount' => ['>', 100], 'role' => ['in', ['admin', 'mod']]],
            ['status' => 'active', 'amount' => 50, 'role' => 'admin'],
        ))->toBeFalse();
    });
});

describe('EventScheduler Config Resilience', function (): void {
    test('scheduler resolves null EventManager gracefully', function (): void {
        $scheduler = app(\ZeroBoiler\Events\EventScheduler::class);
        $reflection = new ReflectionMethod($scheduler, 'resolveEventManager');
        $result = $reflection->invoke($scheduler);

        expect($result)->toBeInstanceOf(\ZeroBoiler\Events\EventManager::class);
    });
});

describe('Wildcard Trigger Cache TTL Edge Cases', function (): void {
    test('cache TTL 0 disables caching', function (): void {
        config(['events.wildcard_cache_ttl' => 0]);
        $manager = app(EventManager::class);
        $reflection = new ReflectionMethod($manager, 'getTriggerCacheTtl');
        $result = $reflection->invoke($manager);

        expect($result)->toBe(0);
    });

    test('cache TTL negative falls back to default 300', function (): void {
        config(['events.wildcard_cache_ttl' => -1]);
        $manager = app(EventManager::class);
        $reflection = new ReflectionMethod($manager, 'getTriggerCacheTtl');
        $result = $reflection->invoke($manager);

        expect($result)->toBe(300);
    });

    test('cache TTL string falls back to default 300', function (): void {
        config(['events.wildcard_cache_ttl' => 'abc']);
        $manager = app(EventManager::class);
        $reflection = new ReflectionMethod($manager, 'getTriggerCacheTtl');
        $result = $reflection->invoke($manager);

        expect($result)->toBe(300);
    });

    test('cache TTL valid integer is respected', function (): void {
        config(['events.wildcard_cache_ttl' => 600]);
        $manager = app(EventManager::class);
        $reflection = new ReflectionMethod($manager, 'getTriggerCacheTtl');
        $result = $reflection->invoke($manager);

        expect($result)->toBe(600);
    });
});
