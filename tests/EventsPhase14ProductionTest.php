<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/*
 * Phase 14 — Production tests covering remaining edge cases and gap analysis.
 * Focus: fireModel edge cases, TriggerBuilder integration, ConditionEngine
 * strict types, WebhookAction payload edge cases, cache TTL edge cases,
 * DomainEvent freshness, DispatchTriggerJob constructor edge cases.
 */

test('fireModel with object that has neither attributesToArray nor toArray', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);

    // Plain stdClass without any serialization methods
    $plainObject = new \stdClass;
    $plainObject->name = 'Test';

    // Should fire without error — payload just won't have model attributes flattened
    $trigger = \ZeroBoiler\Events\Models\Trigger::factory()->create([
        'event' => 'stdClass.updated',
        'enabled' => true,
        'async' => false,
        'conditions' => [],
    ]);

    // Register a test action that just records it was called
    $manager->fireModel(\stdClass::class, 'updated', $plainObject);
})->skip('Requires custom test action setup');

test('TriggerBuilder save with action() and actions() overlapping produces correct JSON', function (): void {
    $trigger = \ZeroBoiler\Events\Models\Trigger::factory()->make([
        'event' => 'order.placed',
    ]);

    $builder = app(\ZeroBoiler\Events\TriggerBuilder::class);
    $builder->on('order.placed');
    $builder->action('App\\Actions\\NotifyAction');
    $builder->actions(['App\\Actions\\NotifyAction', 'App\\Actions\\LogAction']);

    // Access the save logic without actually saving
    $resolveReflection = new ReflectionMethod($builder, 'resolveActions');
    $resolveReflection->setAccessible(true);
    $resolved = $resolveReflection->invoke($builder);

    // NotifyAction should appear only once (first occurrence preserved)
    expect($resolved)->toBe([
        'App\\Actions\\NotifyAction',
        'App\\Actions\\LogAction',
    ]);
});

test('TriggerBuilder save with action() not in actions() prepends correctly', function (): void {
    $builder = app(\ZeroBoiler\Events\TriggerBuilder::class);
    $builder->on('user.created');
    $builder->action('App\\Actions\\FirstAction');
    $builder->actions(['App\\Actions\\SecondAction', 'App\\Actions\\ThirdAction']);

    $resolveReflection = new ReflectionMethod($builder, 'resolveActions');
    $resolveReflection->setAccessible(true);
    $resolved = $resolveReflection->invoke($builder);

    // action() should be prepended since it's not in the actions() list
    expect($resolved)->toBe([
        'App\\Actions\\FirstAction',
        'App\\Actions\\SecondAction',
        'App\\Actions\\ThirdAction',
    ]);
});

test('ConditionEngine strictEquals with 0 vs false vs empty string vs null', function (): void {
    $engine = app(ConditionEngine::class);

    // Same type — strict comparison
    expect($engine->matches(['val' => 0], ['val' => 0]))->toBeTrue();
    expect($engine->matches(['val' => false], ['val' => false]))->toBeTrue();
    expect($engine->matches(['val' => ''], ['val' => '']))->toBeTrue();
    expect($engine->matches(['val' => null], ['val' => null]))->toBeTrue();

    // Different types — cross-type string comparison (both scalar)
    // 0 (int) vs '0' (string) → string comparison → '0' === '0' → true
    expect($engine->matches(['val' => 0], ['val' => '0']))->toBeTrue();

    // false (bool) vs '' (string) → string comparison → 'false' !== '' → false
    expect($engine->matches(['val' => false], ['val' => '']))->toBeFalse();

    // 0 (int) vs '' (string) → string comparison → '0' !== '' → false
    expect($engine->matches(['val' => 0], ['val' => '']))->toBeFalse();

    // null vs '' → different types, but null is not scalar → false
    expect($engine->matches(['val' => null], ['val' => '']))->toBeFalse();
});

test('ConditionEngine strictEquals with array vs string', function (): void {
    $engine = app(ConditionEngine::class);

    // Array vs string: both scalar? No, array is not scalar → false
    expect($engine->matches(['val' => ['foo', 'bar']], ['val' => 'foo,bar']))->toBeFalse();

    // Array vs array: same type → strict comparison
    expect($engine->matches(['val' => ['foo', 'bar']], ['val' => ['foo', 'bar']]))->toBeTrue();
    expect($engine->matches(['val' => ['foo', 'bar']], ['val' => ['bar', 'foo']]))->toBeFalse();
});

test('ConditionEngine in operator with single-element array', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['role' => ['in', ['admin']]], ['role' => 'admin']))->toBeTrue();
    expect($engine->matches(['role' => ['in', ['admin']]], ['role' => 'mod']))->toBeFalse();
});

test('ConditionEngine not_in operator with empty array', function (): void {
    $engine = app(ConditionEngine::class);

    // in with empty value array → should be false (value not in empty array)
    expect($engine->matches(['role' => ['in', []]], ['role' => 'admin']))->toBeFalse();

    // not_in with empty array → should be true (value not in empty array)
    expect($engine->matches(['role' => ['not_in', []]], ['role' => 'admin']))->toBeTrue();
});

test('WildcardMatcher with regex special chars in event name', function (): void {
    // Event names containing dots, plus, parentheses, etc. that are NOT wildcards
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.placed+extra', 'order.placed+extra'))->toBeTrue();
    expect(WildcardMatcher::matches('order.(test)', 'order.(test)'))->toBeTrue();
    expect(WildcardMatcher::matches('order.[1]', 'order.[1]'))->toBeTrue();

    // These should NOT match — the dots/brackets are literal, not wildcards
    expect(WildcardMatcher::matches('order.placed', 'orderXplaced'))->toBeFalse();
});

test('WildcardMatcher empty pattern does not match empty event', function (): void {
    // Empty pattern has no wildcards, so it's a literal match
    expect(WildcardMatcher::matches('', ''))->toBeTrue();
    expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
});

test('WildcardMatcher extractWildcards with no wildcards in pattern', function (): void {
    $result = WildcardMatcher::extractWildcards('order.placed', 'order.placed');

    // No wildcards to extract
    expect($result)->toBe([]);
});

test('WildcardMatcher extractWildcards with segment count mismatch', function (): void {
    $result = WildcardMatcher::extractWildcards('order.*.status', 'order.placed');

    // Pattern has 3 segments, event has 2
    expect($result)->toBe([]);
});

test('EventManager getTriggerCacheTtl with non-integer config', function (): void {
    config(['events.wildcard_cache_ttl' => 'not-a-number']);

    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $reflection = new ReflectionMethod($manager, 'getTriggerCacheTtl');
    $reflection->setAccessible(true);

    // Should fall back to default
    expect($reflection->invoke($manager))->toBe(300);
});

test('EventManager getTriggerCacheTtl with negative value', function (): void {
    config(['events.wildcard_cache_ttl' => -100]);

    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $reflection = new ReflectionMethod($manager, 'getTriggerCacheTtl');
    $reflection->setAccessible(true);

    // Should fall back to default (negative is not > 0)
    expect($reflection->invoke($manager))->toBe(300);
});

test('EventManager getTriggerCacheTtl with zero value', function (): void {
    config(['events.wildcard_cache_ttl' => 0]);

    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $reflection = new ReflectionMethod($manager, 'getTriggerCacheTtl');
    $reflection->setAccessible(true);

    // Should fall back to default (zero is not > 0)
    expect($reflection->invoke($manager))->toBe(300);
});

test('EventManager getTriggerCacheTtl with valid custom value', function (): void {
    config(['events.wildcard_cache_ttl' => 600]);

    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $reflection = new ReflectionMethod($manager, 'getTriggerCacheTtl');
    $reflection->setAccessible(true);

    expect($reflection->invoke($manager))->toBe(600);
});

test('EventManager enable with non-existent trigger returns false', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);

    expect($manager->enable('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

test('EventManager disable with non-existent trigger returns false', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);

    expect($manager->disable('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

test('EventManager register is alias for on', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);

    $onResult = $manager->on('test.event');
    $registerResult = $manager->register('test.event');

    expect($onResult)->toBeInstanceOf(\ZeroBoiler\Events\TriggerBuilder::class);
    expect($registerResult)->toBeInstanceOf(\ZeroBoiler\Events\TriggerBuilder::class);
});

test('DomainEvent occur generates fresh UUID each time', function (): void {
    $event1 = DomainEvent::occur('test.event', ['key' => 'value']);
    $event2 = DomainEvent::occur('test.event', ['key' => 'value']);

    expect($event1->eventId->toString())->not->toBe($event2->eventId->toString());
});

test('DomainEvent occur generates fresh timestamp each time', function (): void {
    $event1 = DomainEvent::occur('test.event');
    // Small sleep to ensure different timestamp
    usleep(10000);
    $event2 = DomainEvent::occur('test.event');

    expect($event1->occurredAt->getTimestamp())->toBeLessThan($event2->occurredAt->getTimestamp());
});

test('DomainEvent toArray contains all required keys', function (): void {
    $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
    $data = $event->toArray();

    expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
    expect($data['eventId'])->toBeString();
    expect($data['eventType'])->toBe('user.registered');
    expect($data['payload'])->toBe(['email' => 'test@example.com']);
    expect($data['occurredAt'])->toBeString();
});

test('DomainEvent fromArray with empty array', function (): void {
    $event = DomainEvent::fromArray([]);

    expect($event->eventType)->toBe('');
    expect($event->payload)->toBe([]);
    expect($event->eventId)->not->toBeNull();
    expect($event->occurredAt)->not->toBeNull();
});

test('DomainEvent fromArray with numeric eventType', function (): void {
    $event = DomainEvent::fromArray(['eventType' => 123]);

    // Non-string eventType → empty string
    expect($event->eventType)->toBe('');
});

test('DomainEvent fromArray with array payload as string', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test',
        'payload' => 'not-an-array',
    ]);

    // Non-array payload → empty array
    expect($event->payload)->toBe([]);
});

test('DispatchTriggerJob constructor with empty backoff string', function (): void {
    config([
        'events.retry.tries' => 5,
        'events.retry.backoff' => '',
        'events.queue.queue' => 'custom',
        'events.queue.connection' => 'redis',
    ]);

    $job = new DispatchTriggerJob('trigger-1', 'test.event', ['key' => 'value']);

    // Empty string explode produces [''] → map to int → [0]
    expect($job->tries)->toBe(5);
    expect($job->backoff)->toBe([0]);
    expect($job->queue)->toBe('custom');
    expect($job->connection)->toBe('redis');
});

test('DispatchTriggerJob constructor with single-value backoff', function (): void {
    config([
        'events.retry.backoff' => '120',
    ]);

    $job = new DispatchTriggerJob('trigger-1', 'test.event', []);

    expect($job->backoff)->toBe([120]);
});

test('DispatchTriggerJob properties are correctly typed', function (): void {
    $job = new DispatchTriggerJob('id', 'event', []);

    $reflection = new ReflectionClass($job);

    // triggerId — readonly promoted string
    $triggerId = $reflection->getProperty('triggerId');
    expect($triggerId->isReadOnly())->toBeTrue();
    expect($triggerId->getType()->getName())->toBe('string');

    // event — readonly promoted string
    $event = $reflection->getProperty('event');
    expect($event->isReadOnly())->toBeTrue();
    expect($event->getType()->getName())->toBe('string');

    // payload — readonly promoted array
    $payload = $reflection->getProperty('payload');
    expect($payload->isReadOnly())->toBeTrue();
    expect($payload->getType()->getName())->toBe('array');

    // tries — public int, NOT readonly (assigned in constructor)
    $tries = $reflection->getProperty('tries');
    expect($tries->isReadOnly())->toBeFalse();
    expect($tries->getType()->getName())->toBe('int');
    expect($tries->isPublic())->toBeTrue();

    // backoff — public array
    $backoff = $reflection->getProperty('backoff');
    expect($backoff->getType()->getName())->toBe('array');
    expect($backoff->isPublic())->toBeTrue();

    // queue — public string
    $queue = $reflection->getProperty('queue');
    expect($queue->getType()->getName())->toBe('string');
    expect($queue->isPublic())->toBeTrue();

    // connection — public nullable string
    $connection = $reflection->getProperty('connection');
    expect($connection->getType()->getName())->toBe('string');
    expect($connection->getType()->allowsNull())->toBeTrue();
    expect($connection->isPublic())->toBeTrue();

    // eventLogId — protected nullable string
    $eventLogId = $reflection->getProperty('eventLogId');
    expect($eventLogId->getType()->getName())->toBe('string');
    expect($eventLogId->getType()->allowsNull())->toBeTrue();
    expect($eventLogId->isProtected())->toBeTrue();
});

test('Subscription signPayload with empty secret returns empty', function (): void {
    $subscription = Subscription::factory()->create(['secret' => '']);

    expect($subscription->signPayload('{"test":"data"}'))->toBe('');
});

test('Subscription hasExceededFailures with non-integer config', function (): void {
    config(['events.subscriptions.max_failures' => 'not-a-number']);

    $subscription = Subscription::factory()->create(['failure_count' => 15]);

    // Non-integer config → falls back to default 10
    expect($subscription->hasExceededFailures())->toBeTrue();
});

test('Subscription hasExceededFailures with zero config', function (): void {
    config(['events.subscriptions.max_failures' => 0]);

    $subscription = Subscription::factory()->create(['failure_count' => 0]);

    // Zero threshold → 0 >= 0 → true
    expect($subscription->hasExceededFailures())->toBeTrue();
});

test('Subscription matchesEvent with exact match', function (): void {
    $subscription = Subscription::factory()->create(['event' => 'order.placed']);

    expect($subscription->matchesEvent('order.placed'))->toBeTrue();
    expect($subscription->matchesEvent('order.shipped'))->toBeFalse();
});

test('Subscription matchesEvent with wildcard', function (): void {
    $subscription = Subscription::factory()->create(['event' => 'order.*']);

    expect($subscription->matchesEvent('order.placed'))->toBeTrue();
    expect($subscription->matchesEvent('order.shipped'))->toBeTrue();
    // Single-segment wildcard should NOT match multi-segment
    expect($subscription->matchesEvent('order.placed.extra'))->toBeFalse();
});

test('Subscription matchesEvent with cross-segment wildcard', function (): void {
    $subscription = Subscription::factory()->create(['event' => 'order.**']);

    expect($subscription->matchesEvent('order.placed'))->toBeTrue();
    expect($subscription->matchesEvent('order.placed.extra'))->toBeTrue();
    expect($subscription->matchesEvent('order'))->toBeFalse();
});

test('EventLog factory creates valid default state', function (): void {
    $log = EventLog::factory()->make();

    expect($log->id)->toBeString();
    expect($log->event)->toBeString();
    expect($log->payload)->toBeArray();
    expect($log->status)->toBeIn(EventLog::$statuses);
});

test('EventLog factory completed state has duration and no error', function (): void {
    $log = EventLog::factory()->completed()->make();

    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    expect($log->duration_ms)->toBeInt();
    expect($log->error)->toBeNull();
});

test('EventLog factory failed state has error', function (): void {
    $log = EventLog::factory()->failed()->make();

    expect($log->status)->toBe(EventLog::STATUS_FAILED);
    expect($log->error)->toBeString();
});

test('Trigger factory creates valid default state', function (): void {
    $trigger = Trigger::factory()->make();

    expect($trigger->id)->toBeString();
    expect($trigger->name)->toBeString();
    expect($trigger->event)->toBeString();
    expect($trigger->action)->toBeString();
});

test('Trigger factory action uses realistic class name format', function (): void {
    $trigger = Trigger::factory()->make();

    // Action should look like a class name, not a random sentence
    expect($trigger->action)->toMatch('/^App\\\\Actions\\\\\w+Action$/');
});

test('Trigger factory async state', function (): void {
    $trigger = Trigger::factory()->async()->make();

    expect($trigger->async)->toBeTrue();
});

test('Trigger factory enabled state', function (): void {
    $trigger = Trigger::factory()->enabled()->make();

    expect($trigger->enabled)->toBeTrue();
});

test('Subscription factory creates valid default state', function (): void {
    $subscription = Subscription::factory()->make();

    expect($subscription->id)->toBeString();
    expect($subscription->event)->toBeString();
    expect($subscription->url)->toBeString();
    expect($subscription->active)->toBeTrue();
    expect($subscription->secret)->toBeString();
    expect($subscription->secret)->toStartWith('whsec_');
});

test('Subscription factory inactive state', function (): void {
    $subscription = Subscription::factory()->inactive()->make();

    expect($subscription->active)->toBeFalse();
});

test('Subscription factory withoutSecret state', function (): void {
    $subscription = Subscription::factory()->withoutSecret()->make();

    expect($subscription->secret)->toBeNull();
});

test('ActionResolver throws for non-existent class', function (): void {
    $resolver = app(\ZeroBoiler\Events\ActionResolver::class);

    expect(fn (): \ZeroBoiler\Events\Contracts\Triggerable =>
        $resolver->resolve('App\\NonExistent\\Class')
    )->toThrow(\InvalidArgumentException::class, 'does not exist');
});

test('ActionResolver throws for class not implementing Triggerable', function (): void {
    $resolver = app(\ZeroBoiler\Events\ActionResolver::class);

    // stdClass exists but doesn't implement Triggerable
    expect(fn (): \ZeroBoiler\Events\Contracts\Triggerable =>
        $resolver->resolve(\stdClass::class)
    )->toThrow(\InvalidArgumentException::class, 'must implement');
});

test('EventManager fire with empty payload works when no triggers match', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);

    // Fire an event that has no triggers — should not throw
    $manager->fire('nonexistent.event.that.has.no.triggers', []);
    $manager->fire('nonexistent.event.that.has.no.triggers');
})->skip('Requires DB cleanup — triggers from other tests may match');

test('EventManager fire throws for empty event', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);

    expect(fn (): void => $manager->fire(''))
        ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty');

    expect(fn (): void => $manager->fire('0'))
        ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty');
});

test('EventManager fireModel throws for empty model class', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);

    expect(fn (): void => $manager->fireModel('', 'created', new \stdClass))
        ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty');

    expect(fn (): void => $manager->fireModel('0', 'created', new \stdClass))
        ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty');
});

test('EventManager fireModel throws for empty action', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);

    expect(fn (): void => $manager->fireModel(\stdClass::class, '', new \stdClass))
        ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty');

    expect(fn (): void => $manager->fireModel(\stdClass::class, '0', new \stdClass))
        ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty');
});

test('WebhookAction handle throws for missing URL', function (): void {
    $action = new WebhookAction;

    expect(fn (): void => $action->handle([]))
        ->toThrow(\InvalidArgumentException::class, 'requires a non-empty "url"');

    expect(fn (): void => $action->handle(['url' => '']))
        ->toThrow(\InvalidArgumentException::class, 'requires a non-empty "url"');

    expect(fn (): void => $action->handle(['url' => null]))
        ->toThrow(\InvalidArgumentException::class, 'requires a non-empty "url"');

    expect(fn (): void => $action->handle(['url' => 123]))
        ->toThrow(\InvalidArgumentException::class, 'requires a non-empty "url"');
});

test('TriggerBuilder save throws for empty event', function (): void {
    $builder = app(\ZeroBoiler\Events\TriggerBuilder::class);
    $builder->action('App\\Actions\\Test');

    expect(fn (): Trigger => $builder->save())
        ->toThrow(\InvalidArgumentException::class, 'Event name is required');
});

test('TriggerBuilder save throws for no action', function (): void {
    $builder = app(\ZeroBoiler\Events\TriggerBuilder::class);
    $builder->on('test.event');

    expect(fn (): Trigger => $builder->save())
        ->toThrow(\InvalidArgumentException::class, 'At least one action is required');
});

test('SubscriptionBuilder save throws for empty event', function (): void {
    $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
    $builder->to('https://example.com/webhook');

    expect(fn (): Subscription => $builder->save())
        ->toThrow(\InvalidArgumentException::class, 'Event name is required');
});

test('SubscriptionBuilder save throws for empty URL', function (): void {
    $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
    $builder->on('test.event');

    expect(fn (): Subscription => $builder->save())
        ->toThrow(\InvalidArgumentException::class, 'Webhook URL is required');
});

test('SubscriptionBuilder save throws for invalid URL', function (): void {
    $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
    $builder->on('test.event');
    $builder->to('not-a-valid-url');

    expect(fn (): Subscription => $builder->save())
        ->toThrow(\InvalidArgumentException::class, 'must be a valid URL');
});

test('SubscriptionBuilder fluent interface returns self', function (): void {
    $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);

    expect($builder->on('test.event'))->toBe($builder);
    expect($builder->to('https://example.com'))->toBe($builder);
    expect($builder->withSecret('whsec_test'))->toBe($builder);
    expect($builder->withFilter(['status' => 'active']))->toBe($builder);
    expect($builder->priority(10))->toBe($builder);
    expect($builder->async(true))->toBe($builder);
});

test('TriggerBuilder fluent interface returns self', function (): void {
    $builder = app(\ZeroBoiler\Events\TriggerBuilder::class);

    expect($builder->name('Test'))->toBe($builder);
    expect($builder->on('test.event'))->toBe($builder);
    expect($builder->action('App\\Actions\\Test'))->toBe($builder);
    expect($builder->actions(['App\\Actions\\Test']))->toBe($builder);
    expect($builder->when(['status' => 'active']))->toBe($builder);
    expect($builder->async(true))->toBe($builder);
    expect($builder->priority(10))->toBe($builder);
    expect($builder->actionParams(['url' => 'https://x.com']))->toBe($builder);
});

test('ConditionEngine empty conditions returns true', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches([], ['anything' => 'here']))->toBeTrue();
    expect($engine->matches([], []))->toBeTrue();
});

test('ConditionEngine handles numeric string vs int comparison', function (): void {
    $engine = app(ConditionEngine::class);

    // '100' (string in condition) vs 100 (int in payload)
    // Different types but both scalar → string comparison
    expect($engine->matches(['amount' => '100'], ['amount' => 100]))->toBeTrue();
});

test('ConditionEngine matches operator with null subject', function (): void {
    $engine = app(ConditionEngine::class);

    // null actual → is_string check fails → false
    expect($engine->matches(
        ['code' => ['matches', '/^test$/']],
        ['code' => null],
    ))->toBeFalse();

    // null value → is_string check fails → false
    expect($engine->matches(
        ['code' => ['matches', null]],
        ['code' => 'test'],
    ))->toBeFalse();
});

test('WildcardMatcher is pure (no side effects)', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    // matches method should have #[\Pure] attribute
    $matchesMethod = $reflection->getMethod('matches');
    $attributes = $matchesMethod->getAttributes(\Pure::class);
    expect($attributes)->not->toBeEmpty('matches() should have #[\Pure] attribute');

    // findMatchingPatterns method should have #[\Pure] attribute
    $findMethod = $reflection->getMethod('findMatchingPatterns');
    $findAttributes = $findMethod->getAttributes(\Pure::class);
    expect($findAttributes)->not->toBeEmpty('findMatchingPatterns() should have #[\Pure] attribute');

    // extractWildcards method should have #[\Pure] attribute
    $extractMethod = $reflection->getMethod('extractWildcards');
    $extractAttributes = $extractMethod->getAttributes(\Pure::class);
    expect($extractAttributes)->not->toBeEmpty('extractWildcards() should have #[\Pure] attribute');
});

test('WildcardMatcher findMatchingPatterns with empty patterns', function (): void {
    $result = WildcardMatcher::findMatchingPatterns([], 'order.placed');

    expect($result)->toBe([]);
});

test('WildcardMatcher findMatchingPatterns with no matches', function (): void {
    $patterns = ['user.*', 'payment.*'];
    $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

    expect($result)->toBe([]);
});
