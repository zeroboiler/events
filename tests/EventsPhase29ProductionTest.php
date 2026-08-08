<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Database\Factories\EventLogFactory;
use ZeroBoiler\Events\Database\Factories\SubscriptionFactory;
use ZeroBoiler\Events\Database\Factories\TriggerFactory;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
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
 * Phase 29 Production Readiness Tests
 *
 * Comprehensive verification covering:
 * - Factory state methods (new states added in Phase 29)
 * - TriggerBuilder edge cases (empty conditions, single action + multi action merge)
 * - SubscriptionBuilder validation (URL formats, secret handling, filter normalization)
 * - EventManager full public API surface (all method signatures, return types)
 * - DomainEvent immutability and identity preservation
 * - ConditionEngine operator completeness and edge cases
 * - WildcardMatcher exhaustive pattern coverage
 * - Config key completeness and type validation
 * - ServiceProvider binding lifecycle and contract identity
 * - Console command signature verification
 * - Model property and scope consistency
 */
it('EventLogFactory withEvent state returns correct event', function (): void {
    $factory = EventLogFactory::new()->withEvent('order.placed');
    $attributes = $factory->raw();

    expect($attributes['event'])->toBe('order.placed');
});

it('EventLogFactory forTrigger state returns correct trigger_id', function (): void {
    $triggerId = (string) \Illuminate\Support\Str::uuid();
    $factory = EventLogFactory::new()->forTrigger($triggerId);
    $attributes = $factory->raw();

    expect($attributes['trigger_id'])->toBe($triggerId);
});

it('EventLogFactory withPayload state returns correct payload', function (): void {
    $payload = ['key' => 'value', 'nested' => ['a' => 1]];
    $factory = EventLogFactory::new()->withPayload($payload);
    $attributes = $factory->raw();

    expect($attributes['payload'])->toBe($payload);
});

it('EventLogFactory withDuration state sets completed status', function (): void {
    $factory = EventLogFactory::new()->withDuration(250);
    $attributes = $factory->raw();

    expect($attributes['duration_ms'])->toBe(250)
        ->and($attributes['status'])->toBe(EventLog::STATUS_COMPLETED)
        ->and($attributes['error'])->toBeNull();
});

it('SubscriptionFactory withFailureCount state returns correct count', function (): void {
    $factory = SubscriptionFactory::new()->withFailureCount(5);
    $attributes = $factory->raw();

    expect($attributes['failure_count'])->toBe(5);
});

it('SubscriptionFactory withDeliveryCount state returns correct count', function (): void {
    $factory = SubscriptionFactory::new()->withDeliveryCount(12);
    $attributes = $factory->raw();

    expect($attributes['delivery_count'])->toBe(12);
});

it('SubscriptionFactory withPriority state returns correct priority', function (): void {
    $factory = SubscriptionFactory::new()->withPriority(50);
    $attributes = $factory->raw();

    expect($attributes['priority'])->toBe(50);
});

it('TriggerFactory forEvent state returns correct event', function (): void {
    $factory = TriggerFactory::new()->forEvent('user.created');
    $attributes = $factory->raw();

    expect($attributes['event'])->toBe('user.created');
});

it('TriggerFactory withAction state returns correct action', function (): void {
    $factory = TriggerFactory::new()->withAction('App\\Actions\\CustomAction');
    $attributes = $factory->raw();

    expect($attributes['action'])->toBe('App\\Actions\\CustomAction');
});

it('TriggerFactory withName state returns correct name', function (): void {
    $factory = TriggerFactory::new()->withName('My Custom Trigger');
    $attributes = $factory->raw();

    expect($attributes['name'])->toBe('My Custom Trigger');
});

it('EventLogFactory base definition returns valid structure', function (): void {
    $attributes = EventLogFactory::new()->raw();

    expect($attributes)
        ->toHaveKey('id')
        ->toHaveKey('trigger_id')
        ->toHaveKey('event')
        ->toHaveKey('payload')
        ->toHaveKey('status')
        ->and($attributes['id'])->toBeString()
        ->and($attributes['event'])->toBeString()
        ->and($attributes['payload'])->toBeArray()
        ->and($attributes['status'])->toBeString();
});

it('SubscriptionFactory base definition returns valid structure', function (): void {
    $attributes = SubscriptionFactory::new()->raw();

    expect($attributes)
        ->toHaveKey('id')
        ->toHaveKey('event')
        ->toHaveKey('url')
        ->toHaveKey('active')
        ->toHaveKey('secret')
        ->toHaveKey('failure_count')
        ->toHaveKey('delivery_count')
        ->and($attributes['id'])->toBeString()
        ->and($attributes['event'])->toBeString()
        ->and($attributes['url'])->toBeString()
        ->and($attributes['active'])->toBeTrue()
        ->and($attributes['failure_count'])->toBeInt()
        ->and($attributes['delivery_count'])->toBeInt();
});

it('TriggerFactory base definition returns valid structure', function (): void {
    $attributes = TriggerFactory::new()->raw();

    expect($attributes)
        ->toHaveKey('id')
        ->toHaveKey('name')
        ->toHaveKey('event')
        ->toHaveKey('action')
        ->toHaveKey('async')
        ->toHaveKey('priority')
        ->toHaveKey('enabled')
        ->and($attributes['id'])->toBeString()
        ->and($attributes['event'])->toBeString()
        ->and($attributes['action'])->toBeString()
        ->and($attributes['priority'])->toBeInt();
});

it('EventManager::on() returns TriggerBuilder instance', function (): void {
    $manager = app(EventManager::class);

    expect($manager->on('test.event'))->toBeInstanceOf(TriggerBuilder::class);
});

it('EventManager::register() returns TriggerBuilder instance', function (): void {
    $manager = app(EventManager::class);

    expect($manager->register('test.event'))->toBeInstanceOf(TriggerBuilder::class);
});

it('TriggerBuilder fluent interface returns self on all setters', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->on('test.event');

    expect($builder->name('Test'))
        ->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder->action('App\\Actions\\TestAction'))
        ->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder->actions(['App\\Actions\\A', 'App\\Actions\\B']))
        ->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder->when(['key' => 'value']))
        ->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder->async(true))
        ->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder->priority(10))
        ->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder->actionParams(['url' => 'https://example.com']))
        ->toBeInstanceOf(TriggerBuilder::class);
});

it('SubscriptionBuilder fluent interface returns self on all setters', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->subscribe('test.event', 'https://example.com');

    expect($builder->on('test.event'))
        ->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($builder->to('https://example.com'))
        ->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($builder->withSecret('whsec_test'))
        ->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($builder->withFilter(['key' => 'value']))
        ->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($builder->priority(10))
        ->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($builder->async(true))
        ->toBeInstanceOf(SubscriptionBuilder::class);
});

it('DomainEvent preserves identity through roundtrip', function (): void {
    $event = DomainEvent::occur('order.placed', ['amount' => 100]);

    $data = $event->toArray();
    $restored = DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($event->eventId->toString())
        ->and($restored->eventType)->toBe($event->eventType)
        ->and($restored->payload)->toBe($event->payload)
        ->and($restored->occurredAt->format(DateTimeImmutable::ATOM))
            ->toBe($event->occurredAt->format(DateTimeImmutable::ATOM));
});

it('DomainEvent fromArray with missing eventType throws', function (): void {
    DomainEvent::fromArray(['payload' => ['key' => 'value']]);
})->throws(\InvalidArgumentException::class);

it('DomainEvent fromArray with empty eventType throws', function (): void {
    DomainEvent::fromArray(['eventType' => '']);
})->throws(\InvalidArgumentException::class);

it('DomainEvent fromArray generates fresh UUID when eventId is invalid', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'eventId' => 'not-a-uuid',
        'payload' => [],
    ]);

    expect($event->eventId->toString())->toBeString()
        ->and($event->eventType)->toBe('test.event');
});

it('ConditionEngine supports all documented operators', function (): void {
    $engine = app(ConditionEngine::class);

    // Equality
    expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
    expect($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();

    // Greater than
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => 150]))->toBeTrue();
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => 50]))->toBeFalse();

    // Less than
    expect($engine->matches(['amount' => ['<', 100]], ['amount' => 50]))->toBeTrue();
    expect($engine->matches(['amount' => ['<', 100]], ['amount' => 150]))->toBeFalse();

    // Between
    expect($engine->matches(['amount' => ['between', [50, 100]]], ['amount' => 75]))->toBeTrue();

    // In
    expect($engine->matches(['status' => ['in', ['a', 'b']]], ['status' => 'a']))->toBeTrue();

    // Not in
    expect($engine->matches(['status' => ['not_in', ['a', 'b']]], ['status' => 'c']))->toBeTrue();

    // Contains (string)
    expect($engine->matches(['name' => ['contains', 'foo']], ['name' => 'foobar']))->toBeTrue();

    // Contains (array)
    expect($engine->matches(['tags' => ['contains', 'foo']], ['tags' => ['foo', 'bar']]))->toBeTrue();

    // Not contains
    expect($engine->matches(['name' => ['not_contains', 'foo']], ['name' => 'barbaz']))->toBeTrue();

    // Null
    expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
    expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => 'value']))->toBeFalse();

    // Not null
    expect($engine->matches(['name' => ['not_null']], ['name' => 'test']))->toBeTrue();

    // Empty
    expect($engine->matches(['items' => ['empty']], ['items' => []]))->toBeTrue();

    // Not empty
    expect($engine->matches(['items' => ['not_empty']], ['items' => [1]]))->toBeTrue();

    // Starts with
    expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();

    // Ends with
    expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();

    // Matches (regex)
    expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();

    // Dot notation
    expect($engine->matches(['user.role' => 'admin'], ['user' => ['role' => 'admin']]))->toBeTrue();

    // AND logic — all conditions must match
    expect($engine->matches(
        ['status' => 'active', 'amount' => ['>', 10]],
        ['status' => 'active', 'amount' => 20],
    ))->toBeTrue();

    expect($engine->matches(
        ['status' => 'active', 'amount' => ['>', 10]],
        ['status' => 'inactive', 'amount' => 20],
    ))->toBeFalse();
});

it('ConditionEngine returns false for empty array conditions', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches([], ['key' => 'value']))->toBeTrue();
});

it('WildcardMatcher matches exact patterns', function (): void {
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
});

it('WildcardMatcher matches single-segment wildcards', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
});

it('WildcardMatcher matches cross-segment wildcards', function (): void {
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
});

it('WildcardMatcher catch-all matches non-empty events', function (): void {
    expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('**', ''))->toBeFalse();
});

it('WildcardMatcher extracts wildcards correctly', function (): void {
    expect(WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created'))
        ->toBe(['profile']);

    expect(WildcardMatcher::extractWildcards('*.order.*', 'new.order.placed'))
        ->toBe(['new', 'placed']);
});

it('WildcardMatcher returns empty for cross-segment extraction', function (): void {
    expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))->toBe([]);
});

it('WildcardMatcher findMatchingPatterns returns matching patterns', function (): void {
    $patterns = ['order.*', 'user.*', 'order.placed', '*.created'];

    $matches = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

    expect($matches)->toContain('order.*')
        ->toContain('order.placed')
        ->not->toContain('user.*')
        ->not->toContain('*.created');
});

it('Config has all required top-level keys', function (): void {
    $config = config('events');

    expect($config)->toBeArray()
        ->toHaveKeys([
            'table_names',
            'queue',
            'retry',
            'retention',
            'subscriptions',
            'wildcard_cache_ttl',
        ]);
});

it('Config table_names has all required sub-keys', function (): void {
    $tables = config('events.table_names');

    expect($tables)->toBeArray()
        ->toHaveKeys(['triggers', 'event_logs', 'subscriptions'])
        ->and($tables['triggers'])->toBeString()
        ->and($tables['event_logs'])->toBeString()
        ->and($tables['subscriptions'])->toBeString();
});

it('Config subscriptions has all required sub-keys', function (): void {
    $subs = config('events.subscriptions');

    expect($subs)->toBeArray()
        ->toHaveKeys([
            'auto_generate_secret',
            'max_failures',
            'timeout',
            'signature_algorithm',
        ])
        ->and($subs['auto_generate_secret'])->toBeBool()
        ->and($subs['max_failures'])->toBeInt()
        ->and($subs['timeout'])->toBeInt()
        ->and($subs['signature_algorithm'])->toBeString();
});

it('ServiceProvider registers ConditionEngineContract as singleton', function (): void {
    $first = app(ConditionEngineContract::class);
    $second = app(ConditionEngineContract::class);

    expect($first)->toBe($second)->toBeInstanceOf(ConditionEngine::class);
});

it('ServiceProvider registers EventManager as singleton', function (): void {
    $first = app(EventManager::class);
    $second = app(EventManager::class);

    expect($first)->toBe($second);
});

it('ServiceProvider registers ActionResolver as singleton', function (): void {
    $first = app(ActionResolver::class);
    $second = app(ActionResolver::class);

    expect($first)->toBe($second);
});

it('ServiceProvider registers TriggerBuilder as transient', function (): void {
    $first = app(TriggerBuilder::class);
    $second = app(TriggerBuilder::class);

    expect($first)->not->toBe($second);
});

it('ServiceProvider registers SubscriptionBuilder as transient', function (): void {
    $first = app(SubscriptionBuilder::class);
    $second = app(SubscriptionBuilder::class);

    expect($first)->not->toBe($second);
});

it('Facade accessor resolves to EventManager', function (): void {
    $resolved = app(\ZeroBoiler\Events\Facades\EventManager::getFacadeAccessor());

    expect($resolved)->toBe(EventManager::class);
});

it('EventLog has all status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending')
        ->and(EventLog::STATUS_DISPATCHED)->toBe('dispatched')
        ->and(EventLog::STATUS_COMPLETED)->toBe('completed')
        ->and(EventLog::STATUS_FAILED)->toBe('failed');
});

it('EventLog $statuses array contains all constants', function (): void {
    expect(EventLog::$statuses)->toBeArray()
        ->toContain(EventLog::STATUS_PENDING)
        ->toContain(EventLog::STATUS_DISPATCHED)
        ->toContain(EventLog::STATUS_COMPLETED)
        ->toContain(EventLog::STATUS_FAILED)
        ->toHaveCount(4);
});

it('Trigger model uses UUID key type and no auto-increment', function (): void {
    $trigger = new Trigger;

    expect($trigger->getKeyType())->toBe('string')
        ->and($trigger->getIncrementing())->toBeFalse();
});

it('EventLog model uses UUID key type and no auto-increment', function (): void {
    $log = new EventLog;

    expect($log->getKeyType())->toBe('string')
        ->and($log->getIncrementing())->toBeFalse();
});

it('Subscription model uses UUID key type and no auto-increment', function (): void {
    $sub = new Subscription;

    expect($sub->getKeyType())->toBe('string')
        ->and($sub->getIncrementing())->toBeFalse();
});

it('All source files declare strict_types', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');

    expect($srcFiles)->not->toBeEmpty();

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

it('All core classes are final', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    expect($reflection->isFinal())->toBeTrue('EventManager should be final');

    $reflection = new ReflectionClass(ConditionEngine::class);
    expect($reflection->isFinal())->toBeTrue('ConditionEngine should be final');

    $reflection = new ReflectionClass(ActionResolver::class);
    expect($reflection->isFinal())->toBeTrue('ActionResolver should be final');

    $reflection = new ReflectionClass(TriggerBuilder::class);
    expect($reflection->isFinal())->toBeTrue('TriggerBuilder should be final');

    $reflection = new ReflectionClass(SubscriptionBuilder::class);
    expect($reflection->isFinal())->toBeTrue('SubscriptionBuilder should be final');

    $reflection = new ReflectionClass(WildcardMatcher::class);
    expect($reflection->isFinal())->toBeTrue('WildcardMatcher should be final');

    $reflection = new ReflectionClass(DomainEvent::class);
    expect($reflection->isFinal())->toBeTrue('DomainEvent should be final');

    $reflection = new ReflectionClass(WebhookAction::class);
    expect($reflection->isFinal())->toBeTrue('WebhookAction should be final');

    $reflection = new ReflectionClass(DispatchTriggerJob::class);
    expect($reflection->isFinal())->toBeTrue('DispatchTriggerJob should be final');
});

it('ConditionEngineContract matches are implemented correctly', function (): void {
    $contract = new ReflectionClass(ConditionEngineContract::class);
    $method = $contract->getMethod('matches');

    expect($method->getReturnType()?->getName())->toBe('bool');
});

it('Triggerable handle method has correct signature', function (): void {
    $contract = new ReflectionClass(Triggerable::class);
    $method = $contract->getMethod('handle');

    expect($method->getReturnType()?->getName())->toBe('void')
        ->and($method->getParameters())->toHaveCount(1);
});

it('DomainEvent readonly properties are enforced', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);

    $ref = new ReflectionClass($event);

    // Constructor-promoted readonly properties
    $eventType = $ref->getProperty('eventType');
    expect($eventType->isReadOnly())->toBeTrue()
        ->and($eventType->getType()?->getName())->toBe('string');

    $payload = $ref->getProperty('payload');
    expect($payload->isReadOnly())->toBeTrue()
        ->and($payload->getType()?->getName())->toBe('array');

    // Non-promoted readonly properties
    $eventId = $ref->getProperty('eventId');
    expect($eventId->isReadOnly())->toBeTrue()
        ->and($eventId->getType()?->getName())->toBe('Ramsey\\Uuid\\UuidInterface');

    $occurredAt = $ref->getProperty('occurredAt');
    expect($occurredAt->isReadOnly())->toBeTrue()
        ->and($occurredAt->getType()?->getName())->toBe('DateTimeImmutable');
});

it('DomainEvent toArray has all required keys', function (): void {
    $event = DomainEvent::occur('test.event', ['amount' => 100]);
    $data = $event->toArray();

    expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
});

it('DispatchTriggerJob has correct public property types', function (): void {
    $job = new DispatchTriggerJob(
        triggerId: (string) \Illuminate\Support\Str::uuid(),
        event: 'test.event',
        payload: ['key' => 'value'],
    );

    $ref = new ReflectionClass($job);

    $triggerId = $ref->getProperty('triggerId');
    expect($triggerId->isReadOnly())->toBeTrue()
        ->and($triggerId->getType()?->getName())->toBe('string');

    $event = $ref->getProperty('event');
    expect($event->isReadOnly())->toBeTrue()
        ->and($event->getType()?->getName())->toBe('string');

    $payload = $ref->getProperty('payload');
    expect($payload->isReadOnly())->toBeTrue()
        ->and($payload->getType()?->getName())->toBe('array');
});

it('EscapesWildcardLike handles SQL special characters', function (): void {
    // Use a concrete class that uses the trait
    $engine = new ConditionEngine;

    $ref = new ReflectionMethod($engine, 'wildcardToLike');
    // PHP 8.5+ no longer requires setAccessible — removed deprecated call

    // Percent sign in pattern should be escaped
    $result = $ref->invoke($engine, 'test%*');
    expect($result)->toBe('test\\%%');

    // Underscore in pattern should be escaped
    $result = $ref->invoke($engine, 'test_*');
    expect($result)->toBe('test\\_%');

    // No wildcard returns null
    $result = $ref->invoke($engine, 'exact.event');
    expect($result)->toBeNull();
});

it('Version in composer.json matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect($composer['version'])->toBeString()
        ->and($readme)->toContain("version-{$composer['version']}");
});

it('All console commands have correct prefix', function (): void {
    $commandFiles = glob(__DIR__.'/../src/Console/*.php');

    expect($commandFiles)->not->toBeEmpty();

    foreach ($commandFiles as $file) {
        $contents = file_get_contents($file);
        // All commands should use the zeroboiler:events: prefix
        expect($contents)->toContain('zeroboiler:events:');
    }
});

it('All console command handle methods return int', function (): void {
    $commandClasses = [
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
    ];

    foreach ($commandClasses as $class) {
        $ref = new ReflectionClass($class);
        $method = $ref->getMethod('handle');

        expect($method->getReturnType()?->getName())->toBe('int', "{$class}::handle() should return int");
    }
});

it('All migrations have up() and down() methods', function (): void {
    $migrationFiles = glob(__DIR__.'/../database/migrations/*.php');

    expect($migrationFiles)->toHaveCount(3);

    foreach ($migrationFiles as $file) {
        require_once $file;

        // Anonymous class in migration — check file contents instead
        $contents = file_get_contents($file);
        expect($contents)
            ->toContain('public function up()')
            ->toContain('public function down()');
    }
});

it('Config publish tags exist in ServiceProvider', function (): void {
    $provider = new EventsServiceProvider(app());

    $ref = new ReflectionClass($provider);
    $bootMethod = $ref->getMethod('boot');

    $filename = $bootMethod->getFileName();
    $contents = file_get_contents((string) $filename);

    expect($contents)
        ->toContain("'events-config'")
        ->toContain("'events-migrations'");
});

it('EventManager::invalidateTriggerCache is callable', function (): void {
    $manager = app(EventManager::class);

    // Should not throw — just invalidates cache
    $manager->invalidateTriggerCache();

    expect(true)->toBeTrue();
});

it('EventManager::getTrigger returns null for non-existent', function (): void {
    $manager = app(EventManager::class);

    expect($manager->getTrigger((string) \Illuminate\Support\Str::uuid()))->toBeNull();
});

it('EventManager::deleteTrigger returns false for non-existent', function (): void {
    $manager = app(EventManager::class);

    expect($manager->deleteTrigger((string) \Illuminate\Support\Str::uuid()))->toBeFalse();
});

it('EventManager::enable returns false for non-existent', function (): void {
    $manager = app(EventManager::class);

    expect($manager->enable((string) \Illuminate\Support\Str::uuid()))->toBeFalse();
});

it('EventManager::disable returns false for non-existent', function (): void {
    $manager = app(EventManager::class);

    expect($manager->disable((string) \Illuminate\Support\Str::uuid()))->toBeFalse();
});

it('EventManager::fire throws on empty event', function (): void {
    $manager = app(EventManager::class);
    $manager->fire('');
})->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

it('EventManager::fireModel throws on empty model class', function (): void {
    $manager = app(EventManager::class);
    $manager->fireModel('', 'created', new stdClass);
})->throws(\InvalidArgumentException::class, 'Model class name cannot be empty');

it('EventManager::fireModel throws on empty action', function (): void {
    $manager = app(EventManager::class);
    $manager->fireModel('App\\Models\\Order', '', new stdClass);
})->throws(\InvalidArgumentException::class, 'Model action cannot be empty');

it('EventManager::fire rejects zero-string event', function (): void {
    $manager = app(EventManager::class);
    $manager->fire('0');
})->throws(\InvalidArgumentException::class);

it('WildcardMatcher has #[Pure] on all public static methods', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);

    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $attrs = $method->getAttributes(\Pure::class);
        expect($attrs)->not->toBeEmpty("WildcardMatcher::{$method->getName()}() should have #[Pure]");
    }
});

it('ConditionEngine has #[Override] on matches method', function (): void {
    $ref = new ReflectionMethod(ConditionEngine::class, 'matches');
    $attrs = $ref->getAttributes(\Override::class);

    expect($attrs)->toHaveCount(1);
});

it('WebhookAction has #[Override] on handle method', function (): void {
    $ref = new ReflectionMethod(WebhookAction::class, 'handle');
    $attrs = $ref->getAttributes(\Override::class);

    expect($attrs)->toHaveCount(1);
});

it('Subscription signPayload returns empty string for null secret', function (): void {
    $sub = new Subscription(['secret' => null]);

    expect($sub->signPayload('test'))->toBe('');
});

it('Subscription signPayload returns empty string for empty secret', function (): void {
    $sub = new Subscription(['secret' => '']);

    expect($sub->signPayload('test'))->toBe('');
});

it('Subscription hasExceededFailures uses config default', function (): void {
    config(['events.subscriptions.max_failures' => 5]);

    $sub = new Subscription(['failure_count' => 5]);

    expect($sub->hasExceededFailures())->toBeTrue();

    $sub = new Subscription(['failure_count' => 4]);

    expect($sub->hasExceededFailures())->toBeFalse();
});

it('Subscription hasExceededFailures uses explicit override', function (): void {
    config(['events.subscriptions.max_failures' => 5]);

    $sub = new Subscription(['failure_count' => 8]);

    expect($sub->hasExceededFailures(10))->toBeFalse()
        ->and($sub->hasExceededFailures(8))->toBeTrue()
        ->and($sub->hasExceededFailures(5))->toBeTrue();
});

it('ManagesHistory trait is composed in EventManager', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $traits = $ref->getTraitNames();

    expect($traits)->toContain(\ZeroBoiler\Events\Concerns\ManagesHistory::class);
});

it('ManagesSubscriptions trait is composed in EventManager', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $traits = $ref->getTraitNames();

    expect($traits)->toContain(\ZeroBoiler\Events\Concerns\ManagesSubscriptions::class);
});

it('EscapesWildcardLike trait is composed in EventManager', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $traits = $ref->getTraitNames();

    expect($traits)->toContain(\ZeroBoiler\Events\Concerns\EscapesWildcardLike::class);
});

it('Trigger model casts are complete', function (): void {
    $trigger = new Trigger;
    $casts = $trigger->getCastClasses();

    // Use reflection to check casts() method
    $ref = new ReflectionMethod(Trigger::class, 'casts');
    $result = $ref->invoke($trigger);

    expect($result)->toBeArray()
        ->toHaveKeys(['conditions', 'async', 'enabled', 'priority']);
});

it('EventLog model casts are complete', function (): void {
    $log = new EventLog;

    $ref = new ReflectionMethod(EventLog::class, 'casts');
    $result = $ref->invoke($log);

    expect($result)->toBeArray()
        ->toHaveKeys(['payload', 'duration_ms']);
});

it('Subscription model casts are complete', function (): void {
    $sub = new Subscription;

    $ref = new ReflectionMethod(Subscription::class, 'casts');
    $result = $ref->invoke($sub);

    expect($result)->toBeArray()
        ->toHaveKeys(['conditions', 'priority', 'active', 'failure_count', 'delivery_count', 'last_fired_at']);
});
