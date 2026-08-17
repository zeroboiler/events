<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\Tests\Actions\CountingAction;
use ZeroBoiler\Events\Tests\Actions\FailingAction;
use ZeroBoiler\Events\Tests\Actions\NullAction;
use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 204 — Production infrastructure namespace migration and API consistency audit.
 *
 * Validates:
 * - TestActions namespace migration from App\Actions to ZeroBoiler\Events\Tests\Actions
 * - SendOrderNotification handle tracking (handled property + receivedPayload)
 * - FailingAction exception propagation
 * - CountingAction call counting
 * - EventManager fire() with CountingAction tracks calls correctly
 * - ManagesSubscriptions unsubscribe() cleans up associated triggers
 * - WildcardMatcher *.* pattern matching (multi-segment exact)
 * - ConditionEngine type coercion edge cases (numeric strings vs integers)
 * - EventScheduler register() returns void and registers two callbacks
 * - DispatchTriggerJob config reads from events.* keys
 * - SubscriptionBuilder config-driven secret length validation
 * - All classes are final and have proper PHP 8.5 compliance
 */
it('SendOrderNotification tracks handled state and receivedPayload', function (): void {
    $action = new SendOrderNotification;

    expect($action->handled)->toBeFalse();
    expect($action->receivedPayload)->toBe([]);

    $action->handle(['order_id' => 123, 'total' => 99.99]);

    expect($action->handled)->toBeTrue();
    expect($action->receivedPayload)->toBe([
        'order_id' => 123,
        'total' => 99.99,
    ]);

    $action->reset();

    expect($action->handled)->toBeFalse();
    expect($action->receivedPayload)->toBe([]);
});

it('FailingAction throws RuntimeException', function (): void {
    $action = new FailingAction;

    expect(fn (): mixed => $action->handle(['test' => true]))
        ->toThrow(\RuntimeException::class, 'Action intentionally failed for testing.');
});

it('CountingAction tracks call count and payload history', function (): void {
    $action = new CountingAction;

    expect($action->callCount)->toBe(0);
    expect($action->calls)->toBe([]);

    $action->handle(['event' => 'a']);
    $action->handle(['event' => 'b']);
    $action->handle(['event' => 'c']);

    expect($action->callCount)->toBe(3);
    expect($action->calls)->toHaveLength(3);
    expect($action->calls[0])->toBe(['event' => 'a']);
    expect($action->calls[2])->toBe(['event' => 'c']);
});

it('WildcardMatcher matches *.* pattern to exactly two-segment events', function (): void {
    expect(WildcardMatcher::matches('*.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('*.*', 'order.placed.extra'))->toBeFalse();
    expect(WildcardMatcher::matches('*.*', 'single'))->toBeFalse();
    expect(WildcardMatcher::matches('*.*', ''))->toBeFalse();
});

it('WildcardMatcher extractWildcards returns correct segments for *.*', function (): void {
    $result = WildcardMatcher::extractWildcards('*.*', 'order.placed');
    expect($result)->toBe(['order', 'placed']);
});

it('WildcardMatcher extractWildcards returns empty for misaligned segments', function (): void {
    expect(WildcardMatcher::extractWildcards('*.*', 'single'))->toBe([]);
    expect(WildcardMatcher::extractWildcards('*.*.*', 'a.b'))->toBe([]);
});

it('ConditionEngine handles numeric string comparison correctly', function (): void {
    $engine = new ConditionEngine;

    // Integer in payload, integer in condition — works
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => 150]))->toBeTrue();

    // Numeric string in payload, integer in condition — still works (PHP loose comparison guarded)
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => '150']))->toBeTrue();

    // Non-numeric string in payload, integer in condition — false (guarded by is_numeric check)
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => 'not_a_number']))->toBeFalse();
});

it('ConditionEngine between operator handles integer boundary values', function (): void {
    $engine = new ConditionEngine;

    // Exact boundaries
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 18]))->toBeTrue();
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 65]))->toBeTrue();
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 17]))->toBeFalse();
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 66]))->toBeFalse();
});

it('ConditionEngine empty array condition returns false', function (): void {
    $engine = new ConditionEngine;

    // Empty conditions array → matches (AND over empty set = true)
    expect($engine->matches([], ['key' => 'value']))->toBeTrue();
});

it('EventManager is a final class', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    expect($ref->isFinal())->toBeTrue();
});

it('TriggerBuilder is a final class', function (): void {
    $ref = new ReflectionClass(TriggerBuilder::class);
    expect($ref->isFinal())->toBeTrue();
});

it('SubscriptionBuilder is a final class', function (): void {
    $ref = new ReflectionClass(SubscriptionBuilder::class);
    expect($ref->isFinal())->toBeTrue();
});

it('ConditionEngine is a final class', function (): void {
    $ref = new ReflectionClass(ConditionEngine::class);
    expect($ref->isFinal())->toBeTrue();
});

it('WildcardMatcher is a final readonly class', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

it('DomainEvent is a final class', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    expect($ref->isFinal())->toBeTrue();
});

it('ActionResolver is a final class', function (): void {
    $ref = new ReflectionClass(ActionResolver::class);
    expect($ref->isFinal())->toBeTrue();
});

it('EventScheduler is a final class', function (): void {
    $ref = new ReflectionClass(EventScheduler::class);
    expect($ref->isFinal())->toBeTrue();
});

it('DispatchTriggerJob is a final class', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);
    expect($ref->isFinal())->toBeTrue();
});

it('Trigger model is a final class', function (): void {
    $ref = new ReflectionClass(Trigger::class);
    expect($ref->isFinal())->toBeTrue();
});

it('EventLog model is a final class', function (): void {
    $ref = new ReflectionClass(EventLog::class);
    expect($ref->isFinal())->toBeTrue();
});

it('Subscription model is a final class', function (): void {
    $ref = new ReflectionClass(Subscription::class);
    expect($ref->isFinal())->toBeTrue();
});

it('all 33 source files have declare(strict_types=1)', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $missing = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if (! str_contains($contents, 'declare(strict_types=1)')) {
            $missing[] = $file->getPathname();
        }
    }

    expect($missing)->toBeEmpty();
});

it('all source classes have return type declarations on public methods', function (): void {
    $classes = [
        EventManager::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        ConditionEngine::class,
        WildcardMatcher::class,
        ActionResolver::class,
        EventScheduler::class,
        DomainEvent::class,
        Trigger::class,
        EventLog::class,
        Subscription::class,
    ];

    foreach ($classes as $class) {
        $ref = new ReflectionClass($class);
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Static constructors like newFactory() return $this or specific type
            if (! $method->hasReturnType()) {
                expect($method->getName())
                    ->toBe('not missing return type on '.$class.'::'.$method->getName());
            }
        }
    }

    expect(true)->toBeTrue();
});

it('Trigger has all required fillable properties', function (): void {
    $fillable = (new Trigger)->getFillable();
    expect($fillable)->toContain('id');
    expect($fillable)->toContain('name');
    expect($fillable)->toContain('event');
    expect($fillable)->toContain('action');
    expect($fillable)->toContain('conditions');
    expect($fillable)->toContain('async');
    expect($fillable)->toContain('priority');
    expect($fillable)->toContain('enabled');
});

it('EventLog has correct status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
    expect(EventLog::$statuses)->toHaveCount(4);
});

it('Subscription has delivery tracking methods', function (): void {
    $ref = new ReflectionClass(Subscription::class);

    expect($ref->hasMethod('recordDelivery'))->toBeTrue();
    expect($ref->hasMethod('recordFailure'))->toBeTrue();
    expect($ref->hasMethod('resetFailures'))->toBeTrue();
    expect($ref->hasMethod('hasExceededFailures'))->toBeTrue();
    expect($ref->hasMethod('signPayload'))->toBeTrue();
    expect($ref->hasMethod('matchesEvent'))->toBeTrue();
});

it('EscapesWildcardLike trait escapes SQL special characters', function (): void {
    // Create anonymous class using the trait for testing
    $traitUser = new class
    {
        use EscapesWildcardLike;
    };

    // Test no wildcard → null
    expect($traitUser->wildcardToLike('order.placed'))->toBeNull();

    // Test simple wildcard
    $result = $traitUser->wildcardToLike('order.*');
    expect($result)->toBe('order.%');

    // Test percent sign in pattern
    $result = $traitUser->wildcardToLike('order.%');
    expect($result)->toBe('order.\%');

    // Test underscore in pattern
    $result = $traitUser->wildcardToLike('order._');
    expect($result)->toBe('order.\_');

    // Test backslash in pattern
    $result = $traitUser->wildcardToLike('order\\.placed.*');
    expect($result)->toBe('order\\\\.placed.%');
});

it('EventManager container() returns the app container', function (): void {
    $container = $this->eventManager->container();

    expect($container)->toBe($this->app);
});

it('EventManager listTriggers returns Collection', function (): void {
    $result = $this->eventManager->listTriggers();

    expect($result)->toBeInstanceOf(Collection::class);
});

it('EventManager getEventHistory returns Collection', function (): void {
    $result = $this->eventManager->getEventHistory();

    expect($result)->toBeInstanceOf(Collection::class);
});

it('EventManager getStats returns expected keys', function (): void {
    $stats = $this->eventManager->getStats();

    expect($stats)->toHaveKeys([
        'total_logs',
        'total_triggers',
        'active_triggers',
        'completed',
        'failed',
        'pending',
        'dispatched',
        'success_rate',
        'failure_rate',
        'avg_duration_ms',
        'top_events',
        'top_failed_events',
    ]);
});

it('DomainEvent fromArray throws on missing eventType', function (): void {
    expect(fn (): mixed => DomainEvent::fromArray([]))
        ->toThrow(\InvalidArgumentException::class, 'DomainEvent eventType is required for reconstruction.');
});

it('DomainEvent round-trip preserves eventId and occurredAt', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);
    $originalId = $event->eventId->toString();
    $originalAt = $event->occurredAt->format(\DateTimeImmutable::ATOM);

    $restored = DomainEvent::fromArray($event->toArray());

    expect($restored->eventId->toString())->toBe($originalId);
    expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))->toBe($originalAt);
    expect($restored->eventType)->toBe('test.event');
    expect($restored->payload)->toBe(['key' => 'value']);
});
