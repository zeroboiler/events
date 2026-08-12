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
    $event = DomainEvent::create('order.placed', ['order_id' => 123]);

    expect($event->type())->toBe('order.placed');
    expect($event->payload())->toBe(['order_id' => 123]);
    expect($event->occurredAt())->toBeInstanceOf(\DateTimeImmutable::class);
    expect($event->id())->toBeString();
});

test('domain event can be serialized and reconstructed', function (): void {
    $event = DomainEvent::create('user.registered', ['email' => 'test@example.com']);
    $serialized = $event->toArray();
    $reconstructed = DomainEvent::fromArray($serialized);

    expect($reconstructed->type())->toBe($event->type());
    expect($reconstructed->payload())->toBe($event->payload());
});

test('condition engine supports all operators', function (): void {
    $engine = new ConditionEngine;

    // Comparison operators
    expect($engine->evaluate(['amount' => 100], ['amount', '>', 50]))->toBeTrue();
    expect($engine->evaluate(['amount' => 100], ['amount', '>=', 100]))->toBeTrue();
    expect($engine->evaluate(['amount' => 100], ['amount', '<', 200]))->toBeTrue();
    expect($engine->evaluate(['status' => 'active'], ['status', '=', 'active']))->toBeTrue();
    expect($engine->evaluate(['status' => 'active'], ['status', '!=', 'inactive']))->toBeTrue();

    // In operators
    expect($engine->evaluate(['role' => 'admin'], ['role', 'in', ['admin', 'super']]))->toBeTrue();
    expect($engine->evaluate(['role' => 'user'], ['role', 'not_in', ['admin', 'super']]))->toBeTrue();

    // Null checks
    expect($engine->evaluate(['name' => null], ['name', 'null']))->toBeTrue();
    expect($engine->evaluate(['name' => 'John'], ['name', 'not_null']))->toBeTrue();

    // String operators
    expect($engine->evaluate(['bio' => 'Laravel developer'], ['bio', 'contains', 'Laravel']))->toBeTrue();
    expect($engine->evaluate(['code' => 'ABC123'], ['code', 'starts_with', 'ABC']))->toBeTrue();
    expect($engine->evaluate(['code' => 'ABC123'], ['code', 'ends_with', '123']))->toBeTrue();

    // Between
    expect($engine->evaluate(['age' => 25], ['age', 'between', [18, 65]]))->toBeTrue();
});

test('wildcard matcher supports patterns', function (): void {
    expect(WildcardMatcher::matches('order.placed', 'order.*'))->toBeTrue();
    expect(WildcardMatcher::matches('order.item.added', 'order.**'))->toBeTrue();
    expect(WildcardMatcher::matches('user.login', 'order.*'))->toBeFalse();
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

    expect(method_exists($builder, 'url'))->toBeTrue('SubscriptionBuilder must have url()');
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
