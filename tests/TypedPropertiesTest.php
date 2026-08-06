<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\\Events\\Database\\Factories\\EventLogFactory;
use ZeroBoiler\\Events\\Database\\Factories\\SubscriptionFactory;
use ZeroBoiler\\Events\\Database\\Factories\\TriggerFactory;
use ZeroBoiler\\Events\\Models\\EventLog;
use ZeroBoiler\\Events\\Models\\Subscription;
use ZeroBoiler\\Events\\Models\\Trigger;

// ── Model Typed Properties ──────────────────────────────────────────────

test('Trigger model has typed properties', function (): void {
    $reflection = new ReflectionClass(Trigger::class);

    $table = $reflection->getProperty('table');
    expect($table->hasType())->toBeTrue()
        ->and($table->getType()?->getName())->toBe('string');

    $keyType = $reflection->getProperty('keyType');
    expect($keyType->hasType())->toBeTrue()
        ->and($keyType->getType()?->getName())->toBe('string');

    $incrementing = $reflection->getProperty('incrementing');
    expect($incrementing->hasType())->toBeTrue()
        ->and($incrementing->getType()?->getName())->toBe('bool');

    $fillable = $reflection->getProperty('fillable');
    expect($fillable->hasType())->toBeTrue()
        ->and($fillable->getType()?->getName())->toBe('array');

    $hidden = $reflection->getProperty('hidden');
    expect($hidden->hasType())->toBeTrue()
        ->and($hidden->getType()?->getName())->toBe('array');
});

test('EventLog model has typed properties', function (): void {
    $reflection = new ReflectionClass(EventLog::class);

    $table = $reflection->getProperty('table');
    expect($table->hasType())->toBeTrue()
        ->and($table->getType()?->getName())->toBe('string');

    $keyType = $reflection->getProperty('keyType');
    expect($keyType->hasType())->toBeTrue()
        ->and($keyType->getType()?->getName())->toBe('string');

    $incrementing = $reflection->getProperty('incrementing');
    expect($incrementing->hasType())->toBeTrue()
        ->and($incrementing->getType()?->getName())->toBe('bool');

    $fillable = $reflection->getProperty('fillable');
    expect($fillable->hasType())->toBeTrue()
        ->and($fillable->getType()?->getName())->toBe('array');

    $hidden = $reflection->getProperty('hidden');
    expect($hidden->hasType())->toBeTrue()
        ->and($hidden->getType()?->getName())->toBe('array');

    $statuses = $reflection->getProperty('statuses');
    expect($statuses->hasType())->toBeTrue()
        ->and($statuses->getType()?->getName())->toBe('array');
});

test('Subscription model has typed properties', function (): void {
    $reflection = new ReflectionClass(Subscription::class);

    $table = $reflection->getProperty('table');
    expect($table->hasType())->toBeTrue()
        ->and($table->getType()?->getName())->toBe('string');

    $keyType = $reflection->getProperty('keyType');
    expect($keyType->hasType())->toBeTrue()
        ->and($keyType->getType()?->getName())->toBe('string');

    $incrementing = $reflection->getProperty('incrementing');
    expect($incrementing->hasType())->toBeTrue()
        ->and($incrementing->getType()?->getName())->toBe('bool');

    $fillable = $reflection->getProperty('fillable');
    expect($fillable->hasType())->toBeTrue()
        ->and($fillable->getType()?->getName())->toBe('array');

    $hidden = $reflection->getProperty('hidden');
    expect($hidden->hasType())->toBeTrue()
        ->and($hidden->getType()?->getName())->toBe('array');
});

// ── Factory Typed Properties ──────────────────────────────────────────────

test('all factories have typed $model property', function (): void {
    $factories = [
        EventLogFactory::class,
        TriggerFactory::class,
        SubscriptionFactory::class,
    ];

    foreach ($factories as $factory) {
        $reflection = new ReflectionClass($factory);
        $model = $reflection->getProperty('model');
        expect($model->hasType())->toBeTrue("{$factory}::$model should have a typed property")
            ->and($model->getType()?->getName())->toBe('string');
    }
});

// ── Console Command Typed Properties ─────────────────────────────────────

test('all console commands have typed $signature and $description', function (): void {
    $commands = [
        \\ZeroBoiler\\Events\\Console\\EventsListCommand::class,
        \\ZeroBoiler\\Events\\Console\\EventsFireCommand::class,
        \\ZeroBoiler\\Events\\Console\\EventsRegisterCommand::class,
        \\ZeroBoiler\\Events\\Console\\EventsEnableCommand::class,
        \\ZeroBoiler\\Events\\Console\\EventsDisableCommand::class,
        \\ZeroBoiler\\Events\\Console\\EventsRetryCommand::class,
        \\ZeroBoiler\\Events\\Console\\EventsRedeliverCommand::class,
        \\ZeroBoiler\\Events\\Console\\EventsLogCommand::class,
        \\ZeroBoiler\\Events\\Console\\EventsSubscribeCommand::class,
        \\ZeroBoiler\\Events\\Console\\EventsUnsubscribeCommand::class,
        \\ZeroBoiler\\Events\\Console\\EventsSubscriptionsCommand::class,
    ];

    foreach ($commands as $command) {
        $reflection = new ReflectionClass($command);

        $signature = $reflection->getProperty('signature');
        expect($signature->hasType())->toBeTrue("{$command}::$signature should have a typed property")
            ->and($signature->getType()?->getName())->toBe('string');

        $description = $reflection->getProperty('description');
        expect($description->hasType())->toBeTrue("{$command}::$description should have a typed property")
            ->and($description->getType()?->getName())->toBe('string');
    }
});

// ── DomainEvent Readonly Properties ──────────────────────────────────────

test('DomainEvent has readonly properties', function (): void {
    $reflection = new ReflectionClass(\\ZeroBoiler\\Events\\Domain\\DomainEvent::class);

    $eventId = $reflection->getProperty('eventId');
    expect($eventId->isReadOnly(ReflectionProperty::IS_PRIVATE))->toBeTrue()
        ->and($eventId->hasType())->toBeTrue()
        ->and($eventId->getType()?->getName())->toBe('Ramsey\\Uuid\\UuidInterface');

    $occurredAt = $reflection->getProperty('occurredAt');
    expect($occurredAt->isReadOnly(ReflectionProperty::IS_PRIVATE))->toBeTrue()
        ->and($occurredAt->hasType())->toBeTrue()
        ->and($occurredAt->getType()?->getName())->toBe('DateTimeImmutable');

    $eventType = $reflection->getProperty('eventType');
    expect($eventType->hasType())->toBeTrue()
        ->and($eventType->getType()?->getName())->toBe('string');

    $payload = $reflection->getProperty('payload');
    expect($payload->hasType())->toBeTrue()
        ->and($payload->getType()?->getName())->toBe('array');
});

// ── WildcardMatcher Pure Attribute ──────────────────────────────────────

test('WildcardMatcher::matches has Pure attribute', function (): void {
    $method = new ReflectionMethod(\\ZeroBoiler\\Events\\WildcardMatcher::class, 'matches');
    $attributes = $method->getAttributes();

    $hasPure = false;
    foreach ($attributes as $attr) {
        if ($attr->getName() === 'Pure') {
            $hasPure = true;
            break;
        }
    }

    expect($hasPure)->toBeTrue('WildcardMatcher::matches should have #[\\Pure] attribute');
});

test('WildcardMatcher::findMatchingPatterns has Pure attribute', function (): void {
    $method = new ReflectionMethod(\\ZeroBoiler\\Events\\WildcardMatcher::class, 'findMatchingPatterns');
    $attributes = $method->getAttributes();

    $hasPure = false;
    foreach ($attributes as $attr) {
        if ($attr->getName() === 'Pure') {
            $hasPure = true;
            break;
        }
    }

    expect($hasPure)->toBeTrue('WildcardMatcher::findMatchingPatterns should have #[\\Pure] attribute');
});

test('WildcardMatcher::extractWildcards has Pure attribute', function (): void {
    $method = new ReflectionMethod(\\ZeroBoiler\\Events\\WildcardMatcher::class, 'extractWildcards');
    $attributes = $method->getAttributes();

    $hasPure = false;
    foreach ($attributes as $attr) {
        if ($attr->getName() === 'Pure') {
            $hasPure = true;
            break;
        }
    }

    expect($hasPure)->toBeTrue('WildcardMatcher::extractWildcards should have #[\\Pure] attribute');
});

// ── EventsRedeliverCommand Config-Driven Timeout ──────────────────────────

test('EventsRedeliverCommand reads timeout from config', function (): void {
    $reflection = new ReflectionClass(\\ZeroBoiler\\Events\\Console\\EventsRedeliverCommand::class);
    expect($reflection->hasMethod('getTimeout'))->toBeTrue();

    $method = $reflection->getMethod('getTimeout');
    expect($method->isPrivate())->toBeTrue()
        ->and($method->hasReturnType())->toBeTrue()
        ->and($method->getReturnType()?->getName())->toBe('int');
});

// ── Model Functionality Still Works After Typed Properties ──────────────

test('Trigger model CRUD still works with typed properties', function (): void {
    $trigger = Trigger::factory()->enabled()->create([
        'event' => 'test.typed',
        'action' => 'App\\Actions\\TestAction',
    ]);

    expect($trigger->id)->toBeString()
        ->and($trigger->event)->toBe('test.typed')
        ->and($trigger->enabled)->toBeTrue();

    $found = Trigger::find($trigger->id);
    expect($found)->not->toBeNull()
        ->and($found->event)->toBe('test.typed');
});

test('EventLog model CRUD still works with typed properties', function (): void {
    $trigger = Trigger::factory()->enabled()->create();
    $log = EventLog::factory()->completed()->for($trigger)->create([
        'event' => 'test.typed.log',
    ]);

    expect($log->id)->toBeString()
        ->and($log->event)->toBe('test.typed.log')
        ->and($log->status)->toBe(EventLog::STATUS_COMPLETED);

    $found = EventLog::find($log->id);
    expect($found)->not->toBeNull()
        ->and($found->event)->toBe('test.typed.log');
});

test('Subscription model CRUD still works with typed properties', function (): void {
    $sub = Subscription::factory()->active()->create([
        'event' => 'test.typed.sub',
        'url' => 'https://example.com/webhook',
    ]);

    expect($sub->id)->toBeString()
        ->and($sub->event)->toBe('test.typed.sub')
        ->and($sub->active)->toBeTrue();

    $found = Subscription::find($sub->id);
    expect($found)->not->toBeNull()
        ->and($found->event)->toBe('test.typed.sub');
});
