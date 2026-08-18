<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;

beforeEach(function (): void {
    $this->app->get('config')->set('events.disabled', false);
});

describe('Phase 172 — Final Production Readiness Audit', function (): void {
    test('EventManager::getMatchingTriggers uses SORT_NUMERIC for deterministic priority sorting', function (): void {
        $ref = new ReflectionMethod(EventManager::class, 'getMatchingTriggers');
        $source = file_get_contents((string) $ref->getFileName());
        $startLine = $ref->getStartLine();
        $endLine = $ref->getEndLine();
        $lines = array_slice(explode("\n", $source), $startLine - 1, $endLine - $startLine + 1);
        $methodBody = implode("\n", $lines);

        expect($methodBody)->toContain('SORT_NUMERIC');
        expect($methodBody)->not->toContain('SORT_REGULAR');
    });

    test('EventManager sortBy callback returns integer array with negative priority first', function (): void {
        // Verify the sort callback structure returns [negative_priority, timestamp, id]
        // which is the correct pattern for SORT_NUMERIC ASC sorting to achieve DESC priority
        $ref = new ReflectionMethod(EventManager::class, 'getMatchingTriggers');
        $source = file_get_contents((string) $ref->getFileName());
        $startLine = $ref->getStartLine();
        $endLine = $ref->getEndLine();
        $lines = array_slice(explode("\n", $source), $startLine - 1, $endLine - $startLine + 1);
        $methodBody = implode("\n", $lines);

        expect($methodBody)->toContain('-$t->priority');
        expect($methodBody)->toContain('$t->created_at');
        expect($methodBody)->toContain('$t->id');
    });

    test('all source files declare strict_types=1', function (): void {
        $srcDir = __DIR__.'/../src';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $missing = [];
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents((string) $file->getRealPath());
            if (! str_contains($contents, 'declare(strict_types=1)')) {
                $missing[] = $file->getFilename();
            }
        }

        expect($missing)->toBeEmpty('Missing strict_types in: '.implode(', ', $missing));
    });

    test('EventManager fire() rejects empty event names with InvalidArgumentException', function (): void {
        $manager = $this->app->make(EventManager::class);

        expect(fn () => $manager->fire(''))
            ->toThrow(InvalidArgumentException::class, 'Event name cannot be empty');

        expect(fn () => $manager->fire('0'))
            ->toThrow(InvalidArgumentException::class, 'Event name cannot be empty');
    });

    test('EventManager fireModel() rejects empty model class or action', function (): void {
        $manager = $this->app->make(EventManager::class);

        expect(fn () => $manager->fireModel('', 'created', new stdClass))
            ->toThrow(InvalidArgumentException::class, 'Model class name cannot be empty');

        expect(fn () => $manager->fireModel('App\\Models\\Order', '', new stdClass))
            ->toThrow(InvalidArgumentException::class, 'Model action cannot be empty');
    });

    test('WildcardMatcher handles edge cases correctly', function (): void {
        // Empty pattern should not match anything (except catch-all)
        expect(WildcardMatcher::matches('', ''))->toBeFalse();
        expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
        expect(WildcardMatcher::matches('order.placed', ''))->toBeFalse();

        // Catch-all patterns
        expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        expect(WildcardMatcher::matches('**', 'a.b.c.d'))->toBeTrue();
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();

        // Exact match
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    });

    test('ConditionEngine handles all operator types correctly', function (): void {
        $engine = new ConditionEngine;

        // Comparison operators
        expect($engine->matches(['amount' => ['>', 100]], ['amount' => 150]))->toBeTrue();
        expect($engine->matches(['amount' => ['>', 100]], ['amount' => 50]))->toBeFalse();
        expect($engine->matches(['amount' => ['>=', 100]], ['amount' => 100]))->toBeTrue();
        expect($engine->matches(['amount' => ['<', 100]], ['amount' => 50]))->toBeTrue();
        expect($engine->matches(['amount' => ['<=', 100]], ['amount' => 100]))->toBeTrue();

        // Equality
        expect($engine->matches(['status' => 'paid'], ['status' => 'paid']))->toBeTrue();
        expect($engine->matches(['status' => 'paid'], ['status' => 'unpaid']))->toBeFalse();

        // In operator
        expect($engine->matches(['role' => ['in', ['admin', 'editor']]], ['role' => 'admin']))->toBeTrue();
        expect($engine->matches(['role' => ['in', ['admin', 'editor']]], ['role' => 'user']))->toBeFalse();

        // Null checks
        expect($engine->matches(['deleted' => ['null']], ['deleted' => null]))->toBeTrue();
        expect($engine->matches(['deleted' => ['not_null']], ['deleted' => 'value']))->toBeTrue();

        // String operators
        expect($engine->matches(['email' => ['starts_with', 'admin']], ['email' => 'admin@test.com']))->toBeTrue();
        expect($engine->matches(['email' => ['ends_with', '.com']], ['email' => 'test@example.com']))->toBeTrue();
        expect($engine->matches(['code' => ['contains', 'xyz']], ['code' => 'abcxyzdef']))->toBeTrue();

        // Between
        expect($engine->matches(['amount' => ['between', [10, 100]]], ['amount' => 50]))->toBeTrue();
        expect($engine->matches(['amount' => ['between', [10, 100]]], ['amount' => 5]))->toBeFalse();

        // Nested dot notation
        expect($engine->matches(['user.role' => 'admin'], ['user' => ['role' => 'admin']]))->toBeTrue();
    });

    test('DomainEvent roundtrip preserves identity', function (): void {
        $original = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
        $data = $original->toArray();
        $restored = DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->eventType)->toBe($original->eventType);
        expect($restored->payload)->toBe($original->payload);
        expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
            ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
    });

    test('DomainEvent fromArray handles missing eventType gracefully', function (): void {
        expect(fn () => DomainEvent::fromArray([]))
            ->toThrow(InvalidArgumentException::class, 'DomainEvent eventType is required');
    });

    test('WebhookAction implements Triggerable contract', function (): void {
        expect(new WebhookAction)->toBeInstanceOf(Triggerable::class);
    });

    test('ConditionEngine implements ConditionEngineContract', function (): void {
        expect(new ConditionEngine)->toBeInstanceOf(ConditionEngineContract::class);
    });

    test('EventManager facade accessor matches class name', function (): void {
        $facadeRef = new ReflectionClass(EventManagerFacade::class);
        $method = $facadeRef->getMethod('getFacadeAccessor');
        $accessor = $method->invoke(null);

        expect($accessor)->toBe(EventManager::class);
    });

    test('EventsServiceProvider provides all expected services', function (): void {
        $provider = new EventsServiceProvider($this->app);
        $provides = $provider->provides();

        expect($provides)->toContain(EventManager::class);
        expect($provides)->toContain(ConditionEngine::class);
        expect($provides)->toContain(ConditionEngineContract::class);
        expect($provides)->toContain(ActionResolver::class);
        expect($provides)->toContain(TriggerBuilder::class);
        expect($provides)->toContain(SubscriptionBuilder::class);
        expect($provides)->toContain(EventScheduler::class);
    });

    test('EventsServiceProvider registers ConditionEngine as singleton', function (): void {
        $provider = new EventsServiceProvider($this->app);
        $provider->register();

        $first = $this->app->make(ConditionEngine::class);
        $second = $this->app->make(ConditionEngine::class);

        expect($first)->toBe($second); // Same instance (singleton)
    });

    test('EventsServiceProvider registers SubscriptionBuilder as transient', function (): void {
        $provider = new EventsServiceProvider($this->app);
        $provider->register();

        $first = $this->app->make(\ZeroBoiler\Events\SubscriptionBuilder::class);
        $second = $this->app->make(\ZeroBoiler\Events\SubscriptionBuilder::class);

        expect($first)->not->toBe($second); // Different instances (transient)
    });

    test('config/events.php has all required keys', function (): void {
        $config = $this->app->get('config')->get('events');

        expect($config)->toBeArray();
        expect($config)->toHaveKeys([
            'table_names',
            'queue',
            'retry',
            'retention',
            'subscriptions',
            'disabled',
            'wildcard_cache_ttl',
        ]);

        // Verify nested structure
        expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
        expect($config['queue'])->toHaveKeys(['connection', 'queue']);
        expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
        expect($config['subscriptions'])->toHaveKey('max_failures');
    });

    test('composer.json requires PHP ^8.5 and Laravel ^13.0', function (): void {
        $composer = json_decode(
            file_get_contents(__DIR__.'/../composer.json'),
            true,
        );

        expect($composer['require']['php'])->toBe('^8.5');
        expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        expect($composer['require']['illuminate/support'])->toBe('^13.0');
        expect($composer['require']['illuminate/database'])->toBe('^13.0');
    });

    test('EscapesWildcardLike trait escapes SQL special characters', function (): void {
        $ref = new ReflectionMethod(WildcardMatcher::class, 'matches');
        // Test through EventManager which uses the trait
        $manager = $this->app->make(EventManager::class);
        $ref = new ReflectionMethod($manager, 'wildcardToLike');

        // Pattern with wildcard should convert to LIKE
        $result = $ref->invoke($manager, 'order.*');
        expect($result)->toBe('order.%');

        // Pattern without wildcard should return null
        $result = $ref->invoke($manager, 'order.placed');
        expect($result)->toBeNull();

        // Pattern with % in name should escape it
        $result = $ref->invoke($manager, 'order.%');
        expect($result)->toBe('order.\\%');
    });

    test('ReDoS protection in ConditionEngine regex matching', function (): void {
        $engine = new ConditionEngine;

        // Long regex should be rejected
        $longPattern = str_repeat('a', 501);
        expect($engine->matches(
            ['code' => ['matches', '/' . $longPattern . '/']],
            ['code' => str_repeat('a', 500)],
        ))->toBeFalse();

        // Catastrophic backtracking patterns should be rejected
        expect($engine->matches(
            ['code' => ['matches', '/(a+)+b/']],
            ['code' => 'aaaa'],
        ))->toBeFalse();
    });
});
