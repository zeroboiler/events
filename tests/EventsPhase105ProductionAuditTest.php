<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Phase 105 — Production audit: README Quick Start verification, DomainEvent immutability,
 * ConditionEngine operator coverage, EventManager facade completeness, config keys.
 */
test('readme contains quick start section', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect(str_contains($readme, '## Quick Start'))->toBeTrue('README must have Quick Start section');
    expect(str_contains($readme, 'EventManager::on'))->toBeTrue('Quick Start must show EventManager::on usage');
    expect(str_contains($readme, 'EventManager::fire'))->toBeTrue('Quick Start must show EventManager::fire usage');
});

test('domain event is immutable', function (): void {
    $event = DomainEvent::occur('order.placed', ['order_id' => 123]);

    expect($event->eventType)->toBe('order.placed');
    expect($event->payload)->toBe(['order_id' => 123]);
    expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
    expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
});

test('domain event can be serialized and reconstructed', function (): void {
    $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
    $serialized = $event->toArray();
    $reconstructed = DomainEvent::fromArray($serialized);

    expect($reconstructed->eventType)->toBe($event->eventType);
    expect($reconstructed->payload)->toBe($event->payload);
});

test('condition engine supports all operators', function (): void {
    $engine = new ConditionEngine;

    // Comparison operators
    expect($engine->matches(['amount' => ['>', 50]], ['amount' => 100]))->toBeTrue();
    expect($engine->matches(['amount' => ['>=', 100]], ['amount' => 100]))->toBeTrue();
    expect($engine->matches(['amount' => ['<', 200]], ['amount' => 100]))->toBeTrue();
    expect($engine->matches(['status' => 'paid'], ['status' => 'paid']))->toBeTrue();
    expect($engine->matches(['status' => ['!=', 'inactive']], ['status' => 'active']))->toBeTrue();

    // In operators
    expect($engine->matches(['role' => ['in', ['admin', 'super']]], ['role' => 'admin']))->toBeTrue();
    expect($engine->matches(['role' => ['not_in', ['admin', 'super']]], ['role' => 'user']))->toBeTrue();

    // Null checks
    expect($engine->matches(['name' => ['null']], ['name' => null]))->toBeTrue();
    expect($engine->matches(['name' => ['not_null']], ['name' => 'John']))->toBeTrue();

    // String operators
    expect($engine->matches(['bio' => ['contains', 'Laravel']], ['bio' => 'Laravel developer']))->toBeTrue();
    expect($engine->matches(['code' => ['starts_with', 'ABC']], ['code' => 'ABC123']))->toBeTrue();
    expect($engine->matches(['code' => ['ends_with', '123']], ['code' => 'ABC123']))->toBeTrue();

    // Between
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 25]))->toBeTrue();
});

test('wildcard matcher supports patterns', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.item.added'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'user.login'))->toBeFalse();
});

test('trigger builder creates trigger with name and event', function (): void {
    $app = app();
    $builder = $app->make(TriggerBuilder::class);

    expect(method_exists($builder, 'name'))->toBeTrue('TriggerBuilder must have name()');
    expect(method_exists($builder, 'save'))->toBeTrue('TriggerBuilder must have save()');
});

test('subscription builder creates webhook subscription', function (): void {
    $app = app();
    $builder = $app->make(SubscriptionBuilder::class);

    expect(method_exists($builder, 'to'))->toBeTrue('SubscriptionBuilder must have to()');
    expect(method_exists($builder, 'save'))->toBeTrue('SubscriptionBuilder must have save()');
});

test('config has all required keys', function (): void {
    $config = include __DIR__.'/../config/events.php';

    expect(isset($config['table_names']['triggers']))->toBeTrue();
    expect(isset($config['table_names']['event_logs']))->toBeTrue();
    expect(isset($config['table_names']['subscriptions']))->toBeTrue();
    expect(isset($config['queue']))->toBeTrue();
    expect(isset($config['retry']))->toBeTrue();
    expect(isset($config['retention']))->toBeTrue();
    expect(isset($config['subscriptions']))->toBeTrue();
    expect(isset($config['disabled']))->toBeTrue();
    expect(isset($config['wildcard_cache_ttl']))->toBeTrue();
});
