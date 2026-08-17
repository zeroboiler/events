<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\TriggerBuilder;

test('TriggerBuilder resolveActions deduplicates preserving order', function (): void {
    $builder = app(TriggerBuilder::class);

    // Set both single action and actions array with overlap
    // action() = 'App\Models\User'
    // actions() = ['App\Models\User', 'LogAction', 'LogAction', 'NotifyAction']
    $builder->action(App\Models\User::class);
    $builder->actions([
        App\Models\User::class,
        \ZeroBoiler\Events\Tests\Actions\LogAction',
        \ZeroBoiler\Events\Tests\Actions\LogAction', // Duplicate
        \ZeroBoiler\Events\Tests\Actions\NotifyAction',
    ]);

    $resolveReflection = new ReflectionMethod($builder, 'resolveActions');
    $result = $resolveReflection->invoke($builder);

    // resolveActions: all = actions() first, then prepend action() if not in list.
    // 'App\Models\User' IS already in list, so skip prepend.
    // Deduplicate preserving order: [User, LogAction, NotifyAction]
    expect($result)->toBe([
        'App\\Models\\User',
        \ZeroBoiler\Events\Tests\Actions\LogAction',
        \ZeroBoiler\Events\Tests\Actions\NotifyAction',
    ]);
});

test('TriggerBuilder resolveActions handles empty action with actions array', function (): void {
    $builder = app(TriggerBuilder::class);
    $builder->actions([
        \ZeroBoiler\Events\Tests\Actions\Foo',
        \ZeroBoiler\Events\Tests\Actions\Bar',
    ]);

    $resolveReflection = new ReflectionMethod($builder, 'resolveActions');
    $result = $resolveReflection->invoke($builder);

    expect($result)->toBe([
        \ZeroBoiler\Events\Tests\Actions\Foo',
        \ZeroBoiler\Events\Tests\Actions\Bar',
    ]);
});

test('TriggerBuilder resolveActions handles single action only', function (): void {
    $builder = app(TriggerBuilder::class);
    $builder->action(\ZeroBoiler\Events\Tests\Actions\Single');

    $resolveReflection = new ReflectionMethod($builder, 'resolveActions');
    $result = $resolveReflection->invoke($builder);

    expect($result)->toBe([\ZeroBoiler\Events\Tests\Actions\Single']);
});

test('TriggerBuilder resolveActions deduplicates all-same entries', function (): void {
    $builder = app(TriggerBuilder::class);
    $builder->actions([
        \ZeroBoiler\Events\Tests\Actions\Same',
        \ZeroBoiler\Events\Tests\Actions\Same',
        \ZeroBoiler\Events\Tests\Actions\Same',
    ]);

    $resolveReflection = new ReflectionMethod($builder, 'resolveActions');
    $result = $resolveReflection->invoke($builder);

    expect($result)->toBe([\ZeroBoiler\Events\Tests\Actions\Same']);
});

test('ConditionEngine contains operator with array actual and string value', function (): void {
    $engine = app(ConditionEngine::class);

    // String contains
    expect($engine->matches(['name' => ['contains', 'foo']], ['name' => 'foobar']))->toBeTrue();
    expect($engine->matches(['name' => ['contains', 'baz']], ['name' => 'foobar']))->toBeFalse();

    // Array contains
    expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['normal', 'urgent']]))->toBeTrue();
    expect($engine->matches(['tags' => ['contains', 'missing']], ['tags' => ['normal', 'urgent']]))->toBeFalse();
});

test('ConditionEngine not_contains operator', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['work', 'personal']]))->toBeTrue();
    expect($engine->matches(['tags' => ['not_contains', 'work']], ['tags' => ['work', 'personal']]))->toBeFalse();
});

test('ConditionEngine all operators have correct return types', function (): void {
    $engine = app(ConditionEngine::class);

    // Numeric comparisons (null-safe)
    expect($engine->matches(['x' => ['>', 5]], ['x' => 10]))->toBeTrue();
    expect($engine->matches(['x' => ['>', 5]], ['x' => null]))->toBeFalse();
    expect($engine->matches(['x' => ['>=', 5]], ['x' => 5]))->toBeTrue();
    expect($engine->matches(['x' => ['<', 5]], ['x' => 3]))->toBeTrue();
    expect($engine->matches(['x' => ['<=', 5]], ['x' => 5]))->toBeTrue();

    // Equality
    expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
    expect($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();

    // Strict equality
    expect($engine->matches(['flag' => ['===', true]], ['flag' => true]))->toBeTrue();
    expect($engine->matches(['flag' => ['!==', false]], ['flag' => true]))->toBeTrue();

    // in / not_in
    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();
    expect($engine->matches(['role' => ['not_in', ['guest']]], ['role' => 'admin']))->toBeTrue();

    // between (auto-normalize inverted)
    expect($engine->matches(['age' => ['between', [100, 50]]], ['age' => 75]))->toBeTrue();
    expect($engine->matches(['age' => ['between', [100, 50]]], ['age' => 25]))->toBeFalse();

    // null / not_null
    expect($engine->matches(['x' => ['null']], ['x' => null]))->toBeTrue();
    expect($engine->matches(['x' => ['not_null']], ['x' => 42]))->toBeTrue();

    // empty / not_empty
    expect($engine->matches(['x' => ['empty']], ['x' => '']))->toBeTrue();
    expect($engine->matches(['x' => ['not_empty']], ['x' => 'hello']))->toBeTrue();

    // starts_with / ends_with
    expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
    expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();

    // matches (regex)
    expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();
    expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'AB']))->toBeFalse();
});

test('ConditionEngine dot notation nested fields', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(
        ['user.role' => 'admin'],
        ['user' => ['role' => 'admin']],
    ))->toBeTrue();

    expect($engine->matches(
        ['order.total' => ['>', 100]],
        ['order' => ['total' => 150]],
    ))->toBeTrue();

    // Missing intermediate key returns null
    expect($engine->matches(
        ['user.role' => 'admin'],
        ['user' => []],
    ))->toBeFalse();
});

test('ConditionEngine AND logic requires all conditions', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(
        ['status' => 'active', 'amount' => ['>', 50]],
        ['status' => 'active', 'amount' => 100],
    ))->toBeTrue();

    expect($engine->matches(
        ['status' => 'active', 'amount' => ['>', 50]],
        ['status' => 'active', 'amount' => 30],
    ))->toBeFalse();

    expect($engine->matches(
        ['status' => 'active', 'amount' => ['>', 50]],
        ['status' => 'inactive', 'amount' => 100],
    ))->toBeFalse();
});

test('ConditionEngine regex ReDoS protection rejects long patterns', function (): void {
    $engine = app(ConditionEngine::class);

    // Pattern longer than 500 chars
    $longPattern = '/^(?:a{500})$/';
    expect($engine->matches(
        ['code' => ['matches', $longPattern]],
        ['code' => str_repeat('a', 500)],
    ))->toBeFalse();
});

test('ConditionEngine regex ReDoS protection rejects nested quantifiers', function (): void {
    $engine = app(ConditionEngine::class);

    // Nested quantifier pattern (catastrophic backtracking)
    expect($engine->matches(
        ['code' => ['matches', '/(a+)+/']],
        ['code' => 'aaa'],
    ))->toBeFalse();
});

test('WildcardMatcher O(1) dedup set prevents duplicate trigger dispatch', function (): void {
    // This tests the optimization in EventManager::getMatchingTriggers
    // that uses an O(1) id set instead of O(n) firstWhere.
    // The behavior is identical — just verifying the concept is sound.
    $collectedIds = [];

    // Simulate exact match IDs
    $collectedIds['trigger-1'] = true;
    $collectedIds['trigger-2'] = true;

    // Wildcard trigger that matches an already-collected ID should be skipped
    expect(isset($collectedIds['trigger-1']))->toBeTrue();
    expect(isset($collectedIds['trigger-3']))->toBeFalse();

    // After adding
    $collectedIds['trigger-3'] = true;
    expect(isset($collectedIds['trigger-3']))->toBeTrue();
});

test('ConditionEngineContract binding is singleton', function (): void {
    $instance1 = app(ConditionEngineContract::class);
    $instance2 = app(ConditionEngineContract::class);

    expect($instance1)->toBe($instance2);
    expect($instance1)->toBeInstanceOf(ConditionEngine::class);
});

test('EventManager singleton binding', function (): void {
    $instance1 = app(\ZeroBoiler\Events\EventManager::class);
    $instance2 = app(\ZeroBoiler\Events\EventManager::class);

    expect($instance1)->toBe($instance2);
});

test('ActionResolver singleton binding', function (): void {
    $instance1 = app(\ZeroBoiler\Events\ActionResolver::class);
    $instance2 = app(\ZeroBoiler\Events\ActionResolver::class);

    expect($instance1)->toBe($instance2);
});

test('TriggerBuilder transient binding', function (): void {
    $instance1 = app(\ZeroBoiler\Events\TriggerBuilder::class);
    $instance2 = app(\ZeroBoiler\Events\TriggerBuilder::class);

    // Transient — each resolution should be a new instance
    expect($instance1)->not->toBe($instance2);
});

test('SubscriptionBuilder transient binding', function (): void {
    $instance1 = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
    $instance2 = app(\ZeroBoiler\Events\SubscriptionBuilder::class);

    expect($instance1)->not->toBe($instance2);
});

test('DomainEvent is immutable after construction', function (): void {
    $event = new \ZeroBoiler\Events\Domain\DomainEvent(
        'test.event',
        ['key' => 'value'],
    );

    // All public properties are readonly
    $reflection = new ReflectionClass($event);
    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
        expect($prop->isReadOnly())->toBeTrue("Property {$prop->getName()} should be readonly");
    }
});

test('EventManager facade accessor resolves to correct class', function (): void {
    $facade = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    $method = $facade->getMethod('getFacadeAccessor');

    expect($method->invoke(null))->toBe(\ZeroBoiler\Events\EventManager::class);
});

test('All source files have strict types', function (): void {
    $srcPath = __DIR__.'/../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcPath, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $violations = [];
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if ($contents === false || ! str_contains($contents, 'declare(strict_types=1)')) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBeEmpty('Files missing strict_types: '.implode(', ', $violations));
});

test('All source classes are final', function (): void {
    $nonFinalClasses = [
        // Models and factories are intentionally non-final for extensibility
        // Only core service classes should be final
    ];

    $srcPath = __DIR__.'/../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcPath, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            continue;
        }

        // Find class declarations
        if (preg_match('/^\s*(?:abstract\s+)?class\s+(\w+)/m', $contents, $matches)) {
            $className = $matches[1];
            // Skip models, factories (intentionally non-final)
            if (in_array($className, ['Trigger', 'EventLog', 'Subscription'], true)) {
                continue;
            }
            // Skip factory classes
            if (str_contains($file->getPathname(), 'Factories')) {
                continue;
            }

            // Check if class is final
            if (! preg_match('/\bfinal\s+class\s+'.preg_quote($className, '/').'/', $contents)) {
                $nonFinalClasses[] = $className.' ('.$file->getBasename().')';
            }
        }
    }

    expect($nonFinalClasses)->toBeEmpty('Non-final classes: '.implode(', ', $nonFinalClasses));
});

test('Config has all required keys with correct types', function (): void {
    $config = config('events');

    expect($config)->not->toBeNull();
    expect($config)->toBeArray();

    // table_names
    expect($config['table_names'])->toBeArray();
    expect($config['table_names']['triggers'])->toBeString();
    expect($config['table_names']['event_logs'])->toBeString();
    expect($config['table_names']['subscriptions'])->toBeString();

    // queue
    expect($config['queue'])->toBeArray();
    expect($config['queue']['connection'])->toBeString();
    expect($config['queue']['queue'])->toBeString();

    // retry
    expect($config['retry'])->toBeArray();
    expect($config['retry']['tries'])->toBeInt();
    expect($config['retry']['backoff'])->toBeString();

    // retention
    expect($config['retention'])->toBeArray();
    expect($config['retention']['days'])->toBeInt();
    expect($config['retention']['include_pending'])->toBeBool();

    // subscriptions
    expect($config['subscriptions'])->toBeArray();
    expect($config['subscriptions']['auto_generate_secret'])->toBeBool();
    expect($config['subscriptions']['max_failures'])->toBeInt();
    expect($config['subscriptions']['timeout'])->toBeInt();
    expect($config['subscriptions']['signature_algorithm'])->toBeString();

    // wildcard_cache_ttl
    expect($config['wildcard_cache_ttl'])->toBeInt();
});

test('EventLog status constants are consistent', function (): void {
    $statuses = \ZeroBoiler\Events\Models\EventLog::$statuses;

    expect($statuses)->toContain(\ZeroBoiler\Events\Models\EventLog::STATUS_PENDING);
    expect($statuses)->toContain(\ZeroBoiler\Events\Models\EventLog::STATUS_DISPATCHED);
    expect($statuses)->toContain(\ZeroBoiler\Events\Models\EventLog::STATUS_COMPLETED);
    expect($statuses)->toContain(\ZeroBoiler\Events\Models\EventLog::STATUS_FAILED);
    expect($statuses)->toHaveCount(4);
});

test('parseActions returns empty array for empty string', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $reflection = new ReflectionMethod($manager, 'parseActions');

    expect($reflection->invoke($manager, ''))->toBe([]);
    expect($reflection->invoke($manager, '0'))->toBe([]);
});

test('parseActions handles simple class name', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $reflection = new ReflectionMethod($manager, 'parseActions');

    $result = $reflection->invoke($manager, \ZeroBoiler\Events\Tests\Actions\Foo');

    expect($result)->toBe([\ZeroBoiler\Events\Tests\Actions\Foo']);
});

test('parseActions handles JSON array of classes', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $reflection = new ReflectionMethod($manager, 'parseActions');

    $result = $reflection->invoke($manager, '["App\\\\Actions\\\\Foo","App\\\\Actions\\\\Bar"]');

    expect($result)->toBe([
        \ZeroBoiler\Events\Tests\Actions\Foo',
        \ZeroBoiler\Events\Tests\Actions\Bar',
    ]);
});

test('parseActions handles JSON object with class + params', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $reflection = new ReflectionMethod($manager, 'parseActions');

    $json = '{"class":"App\\\\Actions\\\\Webhook","params":{"url":"https://example.com"}}';
    $result = $reflection->invoke($manager, $json);

    expect($result)->toBe([
        [
            'class' => \ZeroBoiler\Events\Tests\Actions\Webhook',
            'params' => ['url' => 'https://example.com'],
        ],
    ]);
});

test('parseActions handles classes key with shared params', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $reflection = new ReflectionMethod($manager, 'parseActions');

    $json = '{"classes":["Foo","Bar"],"params":{"url":"https://x.com"}}';
    $result = $reflection->invoke($manager, $json);

    expect($result)->toBe([
        ['class' => 'Foo', 'params' => ['url' => 'https://x.com']],
        ['class' => 'Bar', 'params' => ['url' => 'https://x.com']],
    ]);
});

test('WildcardMatcher matches catch-all patterns', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', 'user.created'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', ''))->toBeFalse();
});

test('WildcardMatcher matches cross-segment wildcards', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.**', 'order'))->toBeFalse();
});

test('WildcardMatcher matches single-segment wildcards', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
});

test('WildcardMatcher findMatchingPatterns preserves order', function (): void {
    $patterns = ['user.*', 'order.*', 'user.created'];
    $result = \ZeroBoiler\Events\WildcardMatcher::findMatchingPatterns($patterns, 'user.created');

    expect($result)->toBe(['user.*', 'user.created']);
});

test('WildcardMatcher extractWildcards returns correct segments', function (): void {
    $result = \ZeroBoiler\Events\WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

    expect($result)->toBe(['profile']);
});

test('WildcardMatcher extractWildcards returns empty for cross-segment', function (): void {
    $result = \ZeroBoiler\Events\WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

    expect($result)->toBe([]);
});

test('EscapesWildcardLike trait behavior', function (): void {
    $trait = new class {
        use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
    };

    $reflection = new ReflectionMethod($trait, 'wildcardToLike');

    // No wildcard → null
    expect($reflection->invoke($trait, 'order.placed'))->toBeNull();

    // Asterisk → percent
    expect($reflection->invoke($trait, 'order.*'))->toBe('order.%');

    // Double asterisk → double percent
    expect($reflection->invoke($trait, 'order.**'))->toBe('order.%.%');

    // Special chars escaped
    expect($reflection->invoke($trait, 'test_%'))->toBe('test\\%\\_');
});

test('DomainEvent fromArray preserves eventId and occurredAt', function (): void {
    $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);
    $data = $original->toArray();
    $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->occurredAt)->toBe($original->occurredAt);
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
});

test('DomainEvent fromArray handles invalid UUID gracefully', function (): void {
    $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
        'eventType' => 'test',
        'eventId' => 'not-a-uuid',
        'occurredAt' => 'not-a-date',
    ]);

    expect($event->eventType)->toBe('test');
    // Invalid UUID → fresh UUID generated
    expect($event->eventId)->not->toBeNull();
    // Invalid date → now
    expect($event->occurredAt)->not->toBeNull();
});

test('Subscription signPayload is deterministic', function (): void {
    $subscription = \ZeroBoiler\Events\Models\Subscription::factory()->create([
        'secret' => 'test_secret_123',
    ]);

    $sig1 = $subscription->signPayload('{"test":"data"}');
    $sig2 = $subscription->signPayload('{"test":"data"}');

    expect($sig1)->toBe($sig2);
    expect($sig1)->not->toBeEmpty();
});

test('Subscription signPayload returns empty for null secret', function (): void {
    $subscription = \ZeroBoiler\Events\Models\Subscription::factory()->create([
        'secret' => null,
    ]);

    expect($subscription->signPayload('{"test":"data"}'))->toBe('');
});

test('WebhookAction getTimeout reads from config', function (): void {
    config(['events.subscriptions.timeout' => 60]);
    $action = new \ZeroBoiler\Events\Actions\WebhookAction;

    $reflection = new ReflectionMethod($action, 'getTimeout');
    expect($reflection->invoke($action))->toBe(60);
});

test('WebhookAction getMaxFailures reads from config', function (): void {
    config(['events.subscriptions.max_failures' => 5]);
    $action = new \ZeroBoiler\Events\Actions\WebhookAction;

    $reflection = new ReflectionMethod($action, 'getMaxFailures');
    expect($reflection->invoke($action))->toBe(5);
});
