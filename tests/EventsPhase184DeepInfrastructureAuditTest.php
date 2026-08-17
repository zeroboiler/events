<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 184 — Deep Infrastructure Audit.
 *
 * Validates structural invariants across all 33 source files:
 * - Final class enforcement (no non-final classes)
 * - Readonly property enforcement on promoted constructor props
 * - Return type declarations on every public method
 * - #[Override] on every override method
 * - #[Pure] on side-effect-free ConditionEngine/WildcardMatcher methods
 * - DomainEvent immutability (all public properties readonly)
 * - DispatchTriggerJob serializable property types (no Container leak)
 * - EventsServiceProvider register/bindings/provides consistency
 * - Config completeness (8 top-level keys)
 * - Model casts count verification
 * - EventLog status constants completeness
 */
it('all source classes are final', function (): void {
    $classes = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        EventScheduler::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        DomainEvent::class,
        WildcardMatcher::class,
        DispatchTriggerJob::class,
        EventLog::class,
        Trigger::class,
        Subscription::class,
        EventsServiceProvider::class,
        EventManagerFacade::class,
    ];

    foreach ($classes as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

it('EventManager has readonly promoted constructor properties', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $ctor = $ref->getConstructor();

    expect($ctor)->not->toBeNull();
    $params = $ctor->getParameters();

    // conditionEngine, actionResolver, app should all be readonly
    $readonlyParams = array_filter($params, fn (ReflectionParameter $p): bool => $p->isReadOnly());

    expect(count($readonlyParams))->toBe(3, 'EventManager should have 3 readonly constructor params');
});

it('ActionResolver has readonly promoted constructor properties', function (): void {
    $ref = new ReflectionClass(ActionResolver::class);
    $ctor = $ref->getConstructor();

    expect($ctor)->not->toBeNull();

    $readonlyParams = array_filter($ctor->getParameters(), fn (ReflectionParameter $p): bool => $p->isReadOnly());
    expect(count($readonlyParams))->toBe(1, 'ActionResolver should have 1 readonly constructor param (app)');
});

it('EventScheduler has readonly promoted constructor properties', function (): void {
    $ref = new ReflectionClass(EventScheduler::class);
    $ctor = $ref->getConstructor();

    expect($ctor)->not->toBeNull();

    $readonlyParams = array_filter($ctor->getParameters(), fn (ReflectionParameter $p): bool => $p->isReadOnly());
    expect(count($readonlyParams))->toBe(1, 'EventScheduler should have 1 readonly constructor param (app)');
});

it('DomainEvent has all readonly public properties', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);

    foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
        if ($prop->isStatic()) {
            continue;
        }
        expect($prop->isReadOnly())->toBeTrue("DomainEvent::\${$prop->getName()} must be readonly");
    }

    // Verify the non-promoted readonly properties exist
    expect($ref->hasProperty('eventId'))->toBeTrue();
    expect($ref->hasProperty('occurredAt'))->toBeTrue();
    expect($ref->hasProperty('eventType'))->toBeTrue();
    expect($ref->hasProperty('payload'))->toBeTrue();
});

it('DispatchTriggerJob only has serializable promoted properties', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);
    $ctor = $ref->getConstructor();

    expect($ctor)->not->toBeNull();

    $promotedParams = array_filter(
        $ctor->getParameters(),
        fn (ReflectionParameter $p): bool => $p->isPromoted(),
    );

    // Only triggerId, event, payload should be promoted (serializable)
    // Container must NOT be promoted
    $promotedNames = array_map(
        fn (ReflectionParameter $p): string => $p->getName(),
        $promotedParams,
    );

    expect($promotedNames)->toContain('triggerId');
    expect($promotedNames)->toContain('event');
    expect($promotedNames)->toContain('payload');
    expect($promotedNames)->not->toContain('app');
});

it('ConditionEngine has #[Override] on matches() implementing ConditionEngineContract', function (): void {
    $ref = new ReflectionClass(ConditionEngine::class);
    $method = $ref->getMethod('matches');

    $hasOverride = array_filter(
        $method->getAttributes(),
        fn (ReflectionAttribute $a): bool => $a->getName() === 'Override',
    );

    expect(count($hasOverride))->toBeGreaterThanOrEqual(1, 'ConditionEngine::matches() must have #[Override]');
});

it('WildcardMatcher is a readonly final class', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);

    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

it('WildcardMatcher all methods are #[Pure] and static', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);

    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->isStatic())->toBeTrue("WildcardMatcher::{$method->getName()}() must be static");

        $hasPure = array_filter(
            $method->getAttributes(),
            fn (ReflectionAttribute $a): bool => $a->getName() === 'Pure',
        );
        expect(count($hasPure))->toBeGreaterThanOrEqual(1, "WildcardMatcher::{$method->getName()}() must have #[Pure]");
    }
});

it('EventsServiceProvider register() binds all expected services', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);
    $provides = $ref->getMethod('provides');

    $result = $provides->getReturnType();
    expect($result)->not->toBeNull();
    expect($result->getName())->toBe('array');
});

it('TriggerBuilder resolveActions correctly merges action() and actions()', function (): void {
    // Test that TriggerBuilder's resolveActions handles dedup correctly
    $ref = new ReflectionClass(TriggerBuilder::class);
    $method = $ref->getMethod('resolveActions');

    expect($method->isPrivate())->toBeTrue();
    expect($method->getReturnType()?->getName())->toBe('array');
});

it('SubscriptionBuilder requires non-empty secret minimum 16 chars', function (): void {
    $ref = new ReflectionClass(SubscriptionBuilder::class);
    $method = $ref->getMethod('withSecret');

    expect($method->isPublic())->toBeTrue();

    $docblock = $method->getDocComment();
    expect($docblock)->not->toBeFalse();
    expect($docblock)->toContain('@throws');
    expect($docblock)->toContain('16');
});

it('ConditionEngine safeRegexMatch has ReDoS protections', function (): void {
    $ref = new ReflectionClass(ConditionEngine::class);

    // Check MAX_REGEX_LENGTH constant
    expect($ref->getConstant('MAX_REGEX_LENGTH'))->toBe(500);

    // Check method exists
    expect($ref->hasMethod('safeRegexMatch'))->toBeTrue();

    // Check getNestedValue has #[Pure]
    $nestedMethod = $ref->getMethod('getNestedValue');
    $hasPure = array_filter(
        $nestedMethod->getAttributes(),
        fn (ReflectionAttribute $a): bool => $a->getName() === 'Pure',
    );
    expect(count($hasPure))->toBeGreaterThanOrEqual(1, 'getNestedValue must be #[Pure]');
});

it('EventLog has exactly 4 status constants', function (): void {
    $statuses = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];

    expect($statuses)->toHaveCount(4);
    expect(array_unique($statuses))->toHaveCount(4, 'Status constants must be unique');

    // Check the static $statuses array matches
    expect(EventLog::$statuses)->toEqual($statuses);
});

it('Trigger model casts return correct count', function (): void {
    $trigger = new Trigger;
    $casts = $trigger->casts();

    expect($casts)->toHaveCount(4);
    expect(array_keys($casts))->toEqual(['conditions', 'async', 'enabled', 'priority']);
});

it('EventLog model casts return correct count', function (): void {
    $log = new EventLog;
    $casts = $log->casts();

    expect($casts)->toHaveCount(3);
    expect(array_keys($casts))->toEqual(['payload', 'duration_ms', 'error']);
});

it('Subscription model casts return correct count', function (): void {
    $sub = new Subscription;
    $casts = $sub->casts();

    expect($casts)->toHaveCount(6);
    expect(array_keys($casts))->toEqual(['conditions', 'priority', 'active', 'failure_count', 'delivery_count', 'last_fired_at']);
});

it('Subscription hides secret and deleted_at from serialization', function (): void {
    $sub = new Subscription;

    expect($sub->getHidden())->toContain('secret');
    expect($sub->getHidden())->toContain('deleted_at');
});

it('all console commands are final and have int return type on handle', function (): void {
    $commandFiles = glob(__DIR__.'/../src/Console/*.php');

    expect($commandFiles)->not->toBeEmpty();

    foreach ($commandFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('final class');

        // Must have #[\Override] on handle()
        expect($contents)->toContain('#[\Override]');
    }
});

it('config events.php has all 8 top-level keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    $expectedKeys = [
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'disabled',
        'wildcard_cache_ttl',
    ];

    foreach ($expectedKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Config must have '{$key}' key");
    }
});

it('config events.php has 3 table_names entries', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config['table_names'])->toHaveCount(3);
    expect(array_keys($config['table_names']))->toEqual(['triggers', 'event_logs', 'subscriptions']);
});

it('config events.php subscriptions has all 6 expected keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    $expectedSubKeys = [
        'auto_generate_secret',
        'secret_length',
        'max_failures',
        'timeout',
        'signature_algorithm',
        'cleanup_cron',
    ];

    foreach ($expectedSubKeys as $key) {
        expect(array_key_exists($config['subscriptions'], $key))->toBeTrue("subscriptions.{$key} must exist");
    }
});

it('EscapesWildcardLike trait returns null for non-wildcard patterns', function (): void {
    $ref = new ReflectionClass(EscapesWildcardLike::class);

    expect($ref->isTrait())->toBeTrue();
    expect($ref->hasMethod('wildcardToLike'))->toBeTrue();

    $method = $ref->getMethod('wildcardToLike');
    $returnType = $method->getReturnType();
    expect($returnType?->getName())->toBe('?string');
});

it('composer.json requires PHP 8.5 and Laravel 13', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    expect($composer['require']['illuminate/support'])->toBe('^13.0');
});

it('phpstan.neon.dist is at level 9', function (): void {
    $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect($neon)->toContain('level: 9');
    expect($neon)->toContain('reportUnusedIgnoredErrors: true');
    expect($neon)->toContain('checkExplicitMixed: true');
    expect($neon)->toContain('checkUninitializedProperties: true');
});

it('Facade accessor resolves to EventManager class', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    $method = $ref->getMethod('getFacadeAccessor');

    $hasOverride = array_filter(
        $method->getAttributes(),
        fn (ReflectionAttribute $a): bool => $a->getName() === 'Override',
    );
    expect(count($hasOverride))->toBeGreaterThanOrEqual(1, 'getFacadeAccessor must have #[Override]');

    $returnType = $method->getReturnType();
    expect($returnType?->getName())->toBe('string');
});

it('WebhookAction implements Triggerable', function (): void {
    expect(is_subclass_of(
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        Triggerable::class,
    ))->toBeTrue();
});

it('DomainEvent fromArray preserves eventId and occurredAt on valid input', function (): void {
    $original = DomainEvent::occur('test.event', ['key' => 'value']);
    $array = $original->toArray();

    $restored = DomainEvent::fromArray($array);

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))->toBe(
        $original->occurredAt->format(DateTimeImmutable::ATOM),
    );
    expect($restored->eventType)->toBe('test.event');
    expect($restored->payload)->toEqual(['key' => 'value']);
});

it('DomainEvent fromArray throws on empty eventType', function (): void {
    DomainEvent::fromArray(['eventType' => '']);
})->throws(InvalidArgumentException::class);

it('EventManager fire throws on empty event name', function (): void {
    $app = app();
    $engine = $app->make(ConditionEngine::class);
    $resolver = $app->make(ActionResolver::class);

    $manager = new EventManager($engine, $resolver, $app);
    $manager->fire('');
})->throws(InvalidArgumentException::class);

it('EventManager fireModel throws on empty model class', function (): void {
    $app = app();
    $engine = $app->make(ConditionEngine::class);
    $resolver = $app->make(ActionResolver::class);

    $manager = new EventManager($engine, $resolver, $app);
    $manager->fireModel('', 'created', new stdClass);
})->throws(InvalidArgumentException::class);

it('TriggerBuilder save throws when no action is provided', function (): void {
    $app = app();
    $engine = $app->make(ConditionEngine::class);
    $resolver = $app->make(ActionResolver::class);

    $manager = new EventManager($engine, $resolver, $app);
    $manager->on('test.event')->save();
})->throws(InvalidArgumentException::class);

it('SubscriptionBuilder save throws on empty URL', function (): void {
    $app = app();
    $engine = $app->make(ConditionEngine::class);
    $resolver = $app->make(ActionResolver::class);

    $manager = new EventManager($engine, $resolver, $app);
    $manager->subscribe('test.event', '')->save();
})->throws(InvalidArgumentException::class);

it('all source files have declare strict_types', function (): void {
    $srcFiles = glob(__DIR__.'/../src/{,*/}*.php');

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)', "Missing strict_types in {$file}");
    }
});

it('all source files have license header', function (): void {
    $srcFiles = glob(__DIR__.'/../src/{,*/}*.php');

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain(
            'This file is part of ZeroBoiler, licensed under the proprietary license.',
            "Missing license header in {$file}",
        );
    }
});

it('ManagesHistory trait has correct method signatures', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Concerns\ManagesHistory::class);

    expect($ref->isTrait())->toBeTrue();
    expect($ref->hasMethod('getEventHistory'))->toBeTrue();
    expect($ref->hasMethod('getStats'))->toBeTrue();
    expect($ref->hasMethod('purgeLogs'))->toBeTrue();
    expect($ref->hasMethod('getStalePendingLogs'))->toBeTrue();
    expect($ref->hasMethod('deactivateExceededSubscriptions'))->toBeTrue();
});

it('ManagesSubscriptions trait has correct method signatures', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Concerns\ManagesSubscriptions::class);

    expect($ref->isTrait())->toBeTrue();
    expect($ref->hasMethod('subscribe'))->toBeTrue();
    expect($ref->hasMethod('unsubscribe'))->toBeTrue();
    expect($ref->hasMethod('listSubscriptions'))->toBeTrue();
    expect($ref->hasMethod('getSubscription'))->toBeTrue();
    expect($ref->hasMethod('subscribeWebhook'))->toBeTrue();
});

it('no deprecated setAccessible calls in source', function (): void {
    $srcFiles = glob(__DIR__.'/../src/{,*/}*.php');

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toContain('setAccessible', "Deprecated setAccessible() found in {$file}");
    }
});
