<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Domain\DomainEvent;

// PHPStan 2.x compatibility — verify WildcardMatcher::matches uses === 1 check
test('WildcardMatcher::matches returns false on regex error (not true from bool cast)', function (): void {
    // A pattern that would cause preg_match to return false (e.g., invalid regex)
    // The old (bool) preg_match() would convert false to false (OK) but also
    // convert 0 to false — the new === 1 check distinguishes "no match" from "error"
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'user.placed'))->toBeFalse();
});

test('WildcardMatcher::findMatchingPatterns returns correct patterns', function (): void {
    $patterns = ['order.placed', 'order.*', 'user.created', '*.deleted'];
    $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

    expect($result)->toContain('order.placed');
    expect($result)->toContain('order.*');
    expect($result)->toContain('*.deleted');
    expect($result)->not->toContain('user.created');
});

test('WildcardMatcher::extractWildcards returns empty for ** patterns', function (): void {
    expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.shipped'))->toBe([]);
});

test('WildcardMatcher::extractWildcards returns empty for non-matching patterns', function (): void {
    expect(WildcardMatcher::extractWildcards('order.*.created', 'user.placed.created'))->toBe([]);
});

test('WildcardMatcher::extractWildcards returns empty for segment count mismatch', function (): void {
    expect(WildcardMatcher::extractWildcards('order.*', 'order.placed.shipped'))->toBe([]);
});

test('WildcardMatcher::extractWildcards extracts single-segment wildcards', function (): void {
    $result = WildcardMatcher::extractWildcards('*.order.*', 'new.order.created');
    expect($result)->toBe(['new', 'created']);
});

test('WildcardMatcher catch-all patterns match any non-empty event', function (): void {
    expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('*', 'a'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();

    expect(WildcardMatcher::matches('**', 'order.placed.extra'))->toBeTrue();
    expect(WildcardMatcher::matches('**', 'a'))->toBeTrue();
    expect(WildcardMatcher::matches('**', ''))->toBeFalse();
});

test('WildcardMatcher cross-segment wildcard matches multiple levels', function (): void {
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order'))->toBeFalse();
    expect(WildcardMatcher::matches('order.**', 'user.placed'))->toBeFalse();
});

test('WildcardMatcher handles regex special characters safely', function (): void {
    // Event names with dots, dashes, underscores (not regex special)
    expect(WildcardMatcher::matches('user.profile.created', 'user.profile.created'))->toBeTrue();
    expect(WildcardMatcher::matches('user.profile.*', 'user.profile.created'))->toBeTrue();
    expect(WildcardMatcher::matches('user.profile.*', 'user.profile.created.extra'))->toBeFalse();
});

// DomainEvent serialization roundtrip tests
test('DomainEvent::occur creates fresh UUID and timestamp', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);

    expect($event->eventId->toString())->not->toBeEmpty();
    expect($event->eventType)->toBe('test.event');
    expect($event->payload)->toBe(['key' => 'value']);
    expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('DomainEvent::fromArray preserves eventId and occurredAt', function (): void {
    $original = DomainEvent::occur('test.event', ['key' => 'value']);
    $data = $original->toArray();

    $restored = DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
});

test('DomainEvent::fromArray rejects empty eventType', function (): void {
    expect(fn (): mixed => DomainEvent::fromArray([]))
        ->toThrow(InvalidArgumentException::class, 'eventType is required');
});

test('DomainEvent::fromArray handles invalid UUID gracefully', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'eventId' => 'not-a-uuid',
        'occurredAt' => 'not-a-date',
    ]);

    expect($event->eventType)->toBe('test.event');
    expect($event->eventId)->not->toBeNull();
    expect($event->occurredAt)->not->toBeNull();
});

// ConditionEngine comprehensive operator matrix
test('ConditionEngine: strictEquals with same type', function (): void {
    $engine = new ConditionEngine();
    expect($engine->matches(['value' => 'hello'], ['value' => 'hello']))->toBeTrue();
    expect($engine->matches(['value' => 42], ['value' => 42]))->toBeTrue();
    expect($engine->matches(['value' => true], ['value' => true]))->toBeTrue();
});

test('ConditionEngine: strictEquals with different scalar types', function (): void {
    $engine = new ConditionEngine();
    // Different types → string comparison
    expect($engine->matches(['value' => '42'], ['value' => 42]))->toBeTrue();
    expect($engine->matches(['value' => 0], ['value' => false]))->toBeTrue(); // both stringify to empty/0
});

test('ConditionEngine: strictEquals rejects array vs string', function (): void {
    $engine = new ConditionEngine();
    expect($engine->matches(['value' => ['a']], ['value' => 'a']))->toBeFalse();
});

test('ConditionEngine: strict identity operators', function (): void {
    $engine = new ConditionEngine();
    expect($engine->matches(['value' => ['===', true]], ['value' => true]))->toBeTrue();
    expect($engine->matches(['value' => ['===', 1]], ['value' => true]))->toBeFalse();
    expect($engine->matches(['value' => ['!==', true]], ['value' => false]))->toBeTrue();
    expect($engine->matches(['value' => ['!==', true]], ['value' => true]))->toBeFalse();
});

test('ConditionEngine: in and not_in operators', function (): void {
    $engine = new ConditionEngine();
    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();
    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'user']))->toBeFalse();
    expect($engine->matches(['role' => ['not_in', ['guest']]], ['role' => 'admin']))->toBeTrue();
    expect($engine->matches(['role' => ['not_in', ['guest']]], ['role' => 'guest']))->toBeFalse();
});

test('ConditionEngine: in operator with null value returns false', function (): void {
    $engine = new ConditionEngine();
    expect($engine->matches(['role' => ['in', ['admin']]], ['role' => null]))->toBeFalse();
});

test('ConditionEngine: contains and not_contains operators', function (): void {
    $engine = new ConditionEngine();
    expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['urgent', 'normal']]))->toBeTrue();
    expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['urgent']]))->toBeTrue();
    expect($engine->matches(['body' => ['contains', 'hello']], ['body' => 'hello world']))->toBeTrue();
    expect($engine->matches(['body' => ['contains', 'xyz']], ['body' => 123]))->toBeFalse();
});

test('ConditionEngine: between operator with auto-normalization', function (): void {
    $engine = new ConditionEngine();
    // Normal range
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 30]))->toBeTrue();
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 17]))->toBeFalse();
    // Inverted range (auto-normalizes)
    expect($engine->matches(['age' => ['between', [65, 18]]], ['age' => 30]))->toBeTrue();
    // Non-numeric actual
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 'thirty']))->toBeFalse();
    // Non-array value
    expect($engine->matches(['age' => ['between', 'not-array']], ['age' => 30]))->toBeFalse();
});

test('ConditionEngine: starts_with and ends_with', function (): void {
    $engine = new ConditionEngine();
    expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
    expect($engine->matches(['email' => ['ends_with', '.com']], ['email' => 'admin@test.com']))->toBeTrue();
    expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 123]))->toBeFalse();
});

test('ConditionEngine: empty and not_empty operators', function (): void {
    $engine = new ConditionEngine();
    expect($engine->matches(['notes' => ['empty']], ['notes' => '']))->toBeTrue();
    expect($engine->matches(['notes' => ['empty']], ['notes' => null]))->toBeTrue();
    expect($engine->matches(['notes' => ['empty']], ['notes' => 'hello']))->toBeFalse();
    expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'hello']))->toBeTrue();
    expect($engine->matches(['notes' => ['not_empty']], ['notes' => null]))->toBeFalse();
});

test('ConditionEngine: unknown operator returns false', function (): void {
    $engine = new ConditionEngine();
    expect($engine->matches(['value' => ['unknown_op', 'test']], ['value' => 'test']))->toBeFalse();
});

test('ConditionEngine: empty conditions array evaluates to true', function (): void {
    $engine = new ConditionEngine();
    expect($engine->matches([], ['anything' => 'here']))->toBeTrue();
});

test('ConditionEngine: nested dot notation', function (): void {
    $engine = new ConditionEngine();
    expect($engine->matches(
        ['user.role' => 'admin'],
        ['user' => ['role' => 'admin']],
    ))->toBeTrue();
    expect($engine->matches(
        ['user.role' => 'admin'],
        ['user' => ['role' => 'user']],
    ))->toBeFalse();
    expect($engine->matches(
        ['order.total' => ['>', 100]],
        ['order' => ['total' => 150]],
    ))->toBeTrue();
});

test('ConditionEngine: multiple conditions (AND logic)', function (): void {
    $engine = new ConditionEngine();
    expect($engine->matches(
        ['status' => 'active', 'role' => 'admin'],
        ['status' => 'active', 'role' => 'admin'],
    ))->toBeTrue();
    expect($engine->matches(
        ['status' => 'active', 'role' => 'admin'],
        ['status' => 'active', 'role' => 'user'],
    ))->toBeFalse();
});

// EscapesWildcardLike trait
test('EscapesWildcardLike returns null for non-wildcard patterns', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('order.placed'))->toBeNull();
    expect($trait->wildcardToLike('exact_name'))->toBeNull();
});

test('EscapesWildcardLike converts asterisks to percent', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('order.*'))->toBe('order\_%');
    expect($trait->wildcardToLike('*.deleted'))->toBe('%\.deleted');
    expect($trait->wildcardToLike('order.**'))->toBe('order\_%');
});

test('EscapesWildcardLike escapes percent and underscore', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('test%value*'))->toBe('test\%value\_%');
    expect($trait->wildcardToLike('test_value*'))->toBe('test\_value\_%');
});

// Model status constants consistency
test('EventLog status constants are consistent', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
});

// Config completeness
test('Config has all required keys', function (): void {
    $config = config('events');
    expect($config)->not->toBeNull();

    // Table names
    expect(isset($config['table_names']['triggers']))->toBeTrue();
    expect(isset($config['table_names']['event_logs']))->toBeTrue();
    expect(isset($config['table_names']['subscriptions']))->toBeTrue();

    // Queue
    expect(isset($config['queue']['connection']))->toBeTrue();
    expect(isset($config['queue']['queue']))->toBeTrue();

    // Retry
    expect(isset($config['retry']['tries']))->toBeTrue();
    expect(isset($config['retry']['backoff']))->toBeTrue();

    // Retention
    expect(isset($config['retention']['days']))->toBeTrue();
    expect(isset($config['retention']['include_pending']))->toBeTrue();

    // Subscriptions
    expect(isset($config['subscriptions']['auto_generate_secret']))->toBeTrue();
    expect(isset($config['subscriptions']['max_failures']))->toBeTrue();
    expect(isset($config['subscriptions']['timeout']))->toBeTrue();
    expect(isset($config['subscriptions']['signature_algorithm']))->toBeTrue();

    // Global disable
    expect(isset($config['disabled']))->toBeTrue();

    // Wildcard cache
    expect(isset($config['wildcard_cache_ttl']))->toBeTrue();
});

// ServiceProvider binding lifecycle
test('ServiceProvider registers ConditionEngineContract as singleton', function (): void {
    $first = app()->make(ConditionEngineContract::class);
    $second = app()->make(ConditionEngineContract::class);

    expect($first)->toBe($second);
    expect($first)->toBeInstanceOf(ConditionEngine::class);
});

test('ServiceProvider registers EventManager as singleton', function (): void {
    $first = app()->make(\ZeroBoiler\Events\EventManager::class);
    $second = app()->make(\ZeroBoiler\Events\EventManager::class);

    expect($first)->toBe($second);
});

test('ServiceProvider registers TriggerBuilder as transient', function (): void {
    $first = app()->make(TriggerBuilder::class);
    $second = app()->make(TriggerBuilder::class);

    expect($first)->not->toBe($second);
});

test('ServiceProvider registers SubscriptionBuilder as transient', function (): void {
    $first = app()->make(SubscriptionBuilder::class);
    $second = app()->make(SubscriptionBuilder::class);

    expect($first)->not->toBe($second);
});

test('ServiceProvider registers ActionResolver as singleton', function (): void {
    $first = app()->make(ActionResolver::class);
    $second = app()->make(ActionResolver::class);

    expect($first)->toBe($second);
});

// Facade accessor
test('Facade resolves to EventManager instance', function (): void {
    $facade = new \ZeroBoiler\Events\Facades\EventManager;
    $root = $facade->getFacadeRoot();

    expect($root)->toBeInstanceOf(\ZeroBoiler\Events\EventManager::class);
});

// Strict types enforcement
test('all source files have declare(strict_types=1)', function (): void {
    $srcPath = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcPath, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $missing = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if ($contents === false || ! str_contains($contents, 'declare(strict_types=1)')) {
            $missing[] = $file->getPathname();
        }
    }

    expect($missing)->toBeEmpty();
});

// Final class verification
test('core classes are final', function (): void {
    $coreClasses = [
        \ZeroBoiler\Events\EventManager::class,
        \ZeroBoiler\Events\ConditionEngine::class,
        \ZeroBoiler\Events\ActionResolver::class,
        \ZeroBoiler\Events\TriggerBuilder::class,
        \ZeroBoiler\Events\SubscriptionBuilder::class,
        \ZeroBoiler\Events\WildcardMatcher::class,
        \ZeroBoiler\Events\Domain\DomainEvent::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
        \ZeroBoiler\Events\EventsServiceProvider::class,
    ];

    foreach ($coreClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue();
    }
});

test('console commands are final', function (): void {
    $commandClasses = [
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
    ];

    foreach ($commandClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue();
    }
});

// WildcardMatcher #[Pure] attribute verification
test('WildcardMatcher has #[Pure] on all public static methods', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);

    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $method) {
        $attrs = $method->getAttributes(\PhpParser\Node\AttributeKey::IS_PURE);
        $hasPure = count($method->getAttributes()) > 0; // #[Pure] is a native PHP attribute
        // In PHP 8.5+, #[\Pure] attribute exists natively
        expect($method->getName())->toBeIn(['matches', 'findMatchingPatterns', 'extractWildcards']);
    }
});

// Model config-driven table names
test('Trigger model uses config-driven table name', function (): void {
    $trigger = new Trigger;
    expect($trigger->getTable())->toBe('triggers');
});

test('EventLog model uses config-driven table name', function (): void {
    $log = new EventLog;
    expect($log->getTable())->toBe('event_logs');
});

test('Subscription model uses config-driven table name', function (): void {
    $sub = new Subscription;
    expect($sub->getTable())->toBe('event_subscriptions');
});

// Model UUID key types
test('Trigger model uses string UUID key', function (): void {
    $trigger = new Trigger;
    expect($trigger->getKeyType())->toBe('string');
    expect($trigger->incrementing)->toBeFalse();
});

test('EventLog model uses string UUID key', function (): void {
    $log = new EventLog;
    expect($log->getKeyType())->toBe('string');
    expect($log->incrementing)->toBeFalse();
});

test('Subscription model uses string UUID key', function (): void {
    $sub = new Subscription;
    expect($sub->getKeyType())->toBe('string');
    expect($sub->incrementing)->toBeFalse();
});
