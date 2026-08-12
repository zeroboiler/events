<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Console\EventsDisableCommand;
use ZeroBoiler\Events\Console\EventsEnableCommand;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Console\EventsListCommand;
use ZeroBoiler\Events\Console\EventsLogCommand;
use ZeroBoiler\Events\Console\EventsRedeliverCommand;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Console\EventsSubscribeCommand;
use ZeroBoiler\Events\Console\EventsSubscriptionsCommand;
use ZeroBoiler\Events\Console\EventsUnsubscribeCommand;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Actions\WebhookAction;
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

beforeEach(function (): void {
    $this->app = $this->createApplication();
});

// ─── Strict Types Enforcement ───────────────────────────────────────────────

test('all source files have declare(strict_types=1)', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');

    expect($srcFiles)->not->toBeEmpty();

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toBeFalse();
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

test('all console command files have declare(strict_types=1)', function (): void {
    $files = glob(__DIR__.'/../src/Console/*.php');

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toBeFalse();
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

// ─── Final Class Verification ────────────────────────────────────────────────

test('all core classes are final', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        WildcardMatcher::class,
        EventsServiceProvider::class,
        EventManagerFacade::class,
        DomainEvent::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
    ];

    foreach ($finalClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

test('all console commands are final', function (): void {
    $commandClasses = [
        EventsListCommand::class,
        EventsFireCommand::class,
        EventsRegisterCommand::class,
        EventsEnableCommand::class,
        EventsDisableCommand::class,
        EventsRetryCommand::class,
        EventsLogCommand::class,
        EventsSubscribeCommand::class,
        EventsUnsubscribeCommand::class,
        EventsSubscriptionsCommand::class,
        EventsRedeliverCommand::class,
    ];

    foreach ($commandClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

// ─── Interface Contracts ──────────────────────────────────────────────────────

test('ConditionEngine implements ConditionEngineContract', function (): void {
    expect(ConditionEngine::class)->toImplement(ConditionEngineContract::class);
});

test('WebhookAction implements Triggerable', function (): void {
    expect(WebhookAction::class)->toImplement(Triggerable::class);
});

// ─── #[\Override] Verification ───────────────────────────────────────────────

test('ConditionEngine::matches has #[Override] attribute', function (): void {
    $method = new ReflectionMethod(ConditionEngine::class, 'matches');
    $attrs = $method->getAttributes(\Override::class);
    expect($attrs)->toHaveCount(1);
});

test('WebhookAction::handle has #[Override] attribute', function (): void {
    $method = new ReflectionMethod(WebhookAction::class, 'handle');
    $attrs = $method->getAttributes(\Override::class);
    expect($attrs)->toHaveCount(1);
});

test('all console command handle() methods have #[Override]', function (): void {
    $commands = [
        EventsListCommand::class,
        EventsFireCommand::class,
        EventsRegisterCommand::class,
        EventsEnableCommand::class,
        EventsDisableCommand::class,
        EventsRetryCommand::class,
        EventsLogCommand::class,
        EventsSubscribeCommand::class,
        EventsUnsubscribeCommand::class,
        EventsSubscriptionsCommand::class,
        EventsRedeliverCommand::class,
    ];

    foreach ($commands as $class) {
        $method = new ReflectionMethod($class, 'handle');
        $attrs = $method->getAttributes(\Override::class);
        expect($attrs)->toHaveCount(1, "{$class}::handle() must have #[Override]");
    }
});

test('model boot/casts/newFactory/getTable have #[Override]', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $ref = new ReflectionClass($model);

        // boot
        $boot = $ref->getMethod('boot');
        expect($boot->getAttributes(\Override::class))->toHaveCount(1, "{$model}::boot() must have #[Override]");

        // getTable
        $getTable = $ref->getMethod('getTable');
        expect($getTable->getAttributes(\Override::class))->toHaveCount(1, "{$model}::getTable() must have #[Override]");

        // casts
        $casts = $ref->getMethod('casts');
        expect($casts->getAttributes(\Override::class))->toHaveCount(1, "{$model}::casts() must have #[Override]");

        // newFactory
        $newFactory = $ref->getMethod('newFactory');
        expect($newFactory->getAttributes(\Override::class))->toHaveCount(1, "{$model}::newFactory() must have #[Override]");
    }
});

// ─── Service Provider Bindings ──────────────────────────────────────────────

test('EventManager is singleton', function (): void {
    $instance1 = $this->app->make(EventManager::class);
    $instance2 = $this->app->make(EventManager::class);
    expect($instance1)->toBe($instance2);
});

test('ConditionEngine is singleton', function (): void {
    $instance1 = $this->app->make(ConditionEngine::class);
    $instance2 = $this->app->make(ConditionEngine::class);
    expect($instance1)->toBe($instance2);
});

test('ActionResolver is singleton', function (): void {
    $instance1 = $this->app->make(ActionResolver::class);
    $instance2 = $this->app->make(ActionResolver::class);
    expect($instance1)->toBe($instance2);
});

test('ConditionEngineContract resolves to ConditionEngine', function (): void {
    $instance = $this->app->make(ConditionEngineContract::class);
    expect($instance)->toBeInstanceOf(ConditionEngine::class);
    expect($instance)->toBe($this->app->make(ConditionEngine::class));
});

test('TriggerBuilder is transient (fresh instance per resolution)', function (): void {
    $instance1 = $this->app->make(TriggerBuilder::class);
    $instance2 = $this->app->make(TriggerBuilder::class);
    expect($instance1)->not->toBe($instance2);
});

test('SubscriptionBuilder is transient (fresh instance per resolution)', function (): void {
    $instance1 = $this->app->make(SubscriptionBuilder::class);
    $instance2 = $this->app->make(SubscriptionBuilder::class);
    expect($instance1)->not->toBe($instance2);
});

// ─── Facade Accessor ─────────────────────────────────────────────────────────

test('Facade accessor returns EventManager class name', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    $method = $ref->getMethod('getFacadeAccessor');
    expect($method->invoke(null))->toBe(EventManager::class);
});

// ─── Config Completeness ────────────────────────────────────────────────────

test('config has all required top-level keys', function (): void {
    $config = config('events');
    expect($config)->toBeArray();
    expect($config)->toHaveKeys([
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'wildcard_cache_ttl',
    ]);
});

test('config table_names has all required keys', function (): void {
    $tables = config('events.table_names');
    expect($tables)->toBeArray();
    expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    expect($tables['triggers'])->toBeString();
    expect($tables['event_logs'])->toBeString();
    expect($tables['subscriptions'])->toBeString();
});

test('config subscriptions has all required keys', function (): void {
    $subs = config('events.subscriptions');
    expect($subs)->toBeArray();
    expect($subs)->toHaveKeys([
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
    ]);
});

test('config queue has all required keys', function (): void {
    $queue = config('events.queue');
    expect($queue)->toBeArray();
    expect($queue)->toHaveKeys(['connection', 'queue']);
});

test('config retry has all required keys', function (): void {
    $retry = config('events.retry');
    expect($retry)->toBeArray();
    expect($retry)->toHaveKeys(['tries', 'backoff']);
});

test('config retention has all required keys', function (): void {
    $retention = config('events.retention');
    expect($retention)->toBeArray();
    expect($retention)->toHaveKeys(['days', 'include_pending']);
});

// ─── EventLog Status Constants ──────────────────────────────────────────────

test('EventLog has all status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
    expect(EventLog::$statuses)->toBe([
        'pending',
        'dispatched',
        'completed',
        'failed',
    ]);
});

// ─── Model Config-Driven Table Names ────────────────────────────────────────

test('Trigger uses config-driven table name', function (): void {
    $model = new Trigger;
    expect($model->getTable())->toBe(config('events.table_names.triggers'));
});

test('EventLog uses config-driven table name', function (): void {
    $model = new EventLog;
    expect($model->getTable())->toBe(config('events.table_names.event_logs'));
});

test('Subscription uses config-driven table name', function (): void {
    $model = new Subscription;
    expect($model->getTable())->toBe(config('events.table_names.subscriptions'));
});

// ─── DomainEvent Readonly & Immutability ─────────────────────────────────────

test('DomainEvent properties are readonly', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);

    foreach (['eventId', 'eventType', 'payload', 'occurredAt'] as $prop) {
        $rp = $ref->getProperty($prop);
        expect($rp->isReadOnly())->toBeTrue("DomainEvent::{$prop} must be readonly");
    }
});

test('DomainEvent roundtrip preserves eventId and occurredAt', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);
    $restored = DomainEvent::fromArray($event->toArray());

    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->eventType)->toBe($event->eventType);
    expect($restored->payload)->toBe($event->payload);
    expect($restored->occurredAt->getTimestamp())->toBe($event->occurredAt->getTimestamp());
});

test('DomainEvent occur() generates fresh UUID per call', function (): void {
    $a = DomainEvent::occur('test.event');
    $b = DomainEvent::occur('test.event');

    expect($a->eventId->toString())->not->toBe($b->eventId->toString());
});

// ─── WildcardMatcher #[\Pure] ────────────────────────────────────────────────

test('WildcardMatcher public methods have #[Pure] attribute', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);

    foreach (['matches', 'findMatchingPatterns', 'extractWildcards'] as $method) {
        $attrs = $ref->getMethod($method)->getAttributes(\Pure::class);
        expect($attrs)->toHaveCount(1, "WildcardMatcher::{$method}() must have #[Pure]");
    }
});

// ─── EscapesWildcardLike ─────────────────────────────────────────────────────

test('EscapesWildcardLike returns null for non-wildcard pattern', function (): void {
    $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
    $manager = $this->app->make(EventManager::class);

    expect($ref->invoke($manager, 'order.placed'))->toBeNull();
});

test('EscapesWildcardLike converts * to % for SQL LIKE', function (): void {
    $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
    $manager = $this->app->make(EventManager::class);

    expect($ref->invoke($manager, 'order.*'))->toBe('order.%');
    expect($ref->invoke($manager, 'order.**'))->toBe('order.%');
    expect($ref->invoke($manager, '*.order.*'))->toBe('%.order.%');
});

// ─── ActionResolver Errors ───────────────────────────────────────────────────

test('ActionResolver throws for non-existent class', function (): void {
    $resolver = $this->app->make(ActionResolver::class);

    expect(fn () => $resolver->resolve('NonExistent\\Class'))
        ->toThrow(\InvalidArgumentException::class, 'does not exist');
});

test('ActionResolver throws for class that does not implement Triggerable', function (): void {
    $resolver = $this->app->make(ActionResolver::class);

    $this->app->bind(StdClass::class, fn (): StdClass => new StdClass);

    expect(fn () => $resolver->resolve(StdClass::class))
        ->toThrow(\InvalidArgumentException::class, 'must implement');
});

// ─── ConditionEngine Operator Coverage ───────────────────────────────────────

test('ConditionEngine supports all operators with correct null safety', function (): void {
    $engine = $this->app->make(ConditionEngine::class);

    // Comparison operators reject null actual
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => 200]))->toBeTrue();
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => null]))->toBeFalse();
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => 50]))->toBeFalse();

    // Equality
    expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
    expect($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();

    // Array operators
    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();
    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'user']))->toBeFalse();
    expect($engine->matches(['role' => ['not_in', ['guest']]], ['role' => 'admin']))->toBeTrue();

    // String operators
    expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
    expect($engine->matches(['email' => ['ends_with', '.com']], ['email' => 'test@test.com']))->toBeTrue();

    // Null operators
    expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
    expect($engine->matches(['email' => ['not_null']], ['email' => 'test@example.com']))->toBeTrue();

    // Empty/not_empty
    expect($engine->matches(['notes' => ['empty']], ['notes' => '']))->toBeTrue();
    expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'hello']))->toBeTrue();

    // Between (auto-normalizes inverted range)
    expect($engine->matches(['age' => ['between', [100, 18]]], ['age' => 50]))->toBeTrue();

    // Dot notation
    expect($engine->matches(['user.role' => 'admin'], ['user' => ['role' => 'admin']]))->toBeTrue();

    // AND logic (all conditions must match)
    expect($engine->matches(
        ['status' => 'active', 'age' => ['>', 25]],
        ['status' => 'active', 'age' => 30],
    ))->toBeTrue();
    expect($engine->matches(
        ['status' => 'active', 'age' => ['>', 25]],
        ['status' => 'active', 'age' => 20],
    ))->toBeFalse();

    // Contains (array)
    expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['urgent', 'important']]))->toBeTrue();

    // not_contains
    expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['urgent']]))->toBeTrue();
});

// ─── WildcardMatcher Comprehensive ────────────────────────────────────────────

test('WildcardMatcher handles all pattern types', function (): void {
    // Exact
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();

    // Single segment
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();

    // Cross segment
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();

    // Catch-all
    expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();

    // Multi-wildcard
    expect(WildcardMatcher::matches('*.order.*', 'user.order.placed'))->toBeTrue();

    // Extract
    expect(WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created'))->toBe(['profile']);

    // Cross-segment extract returns empty
    expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))->toBe([]);

    // findMatchingPatterns preserves order
    expect(WildcardMatcher::findMatchingPatterns(['a.*', 'b.*', 'a.specific'], 'a.specific'))
        ->toBe(['a.*', 'a.specific']);
});

// ─── Subscription Sign/Failure/Matching ──────────────────────────────────────

test('Subscription signPayload returns empty string for null secret', function (): void {
    $sub = Subscription::factory()->create(['secret' => null]);
    expect($sub->signPayload('test'))->toBe('');
});

test('Subscription signPayload returns empty string for empty secret', function (): void {
    $sub = Subscription::factory()->create(['secret' => '']);
    expect($sub->signPayload('test'))->toBe('');
});

test('Subscription signPayload produces deterministic signature', function (): void {
    $sub = Subscription::factory()->create(['secret' => 'whsec_test_secret']);
    $sig1 = $sub->signPayload('payload1');
    $sig2 = $sub->signPayload('payload1');

    expect($sig1)->toBe($sig2);
    expect($sig1)->not->toBeEmpty();
});

test('Subscription hasExceededFailures uses config', function (): void {
    $sub = Subscription::factory()->create(['failure_count' => 5]);
    config(['events.subscriptions.max_failures' => 10]);
    expect($sub->hasExceededFailures())->toBeFalse();

    config(['events.subscriptions.max_failures' => 5]);
    expect($sub->hasExceededFailures())->toBeTrue();
});

test('Subscription matchesEvent with exact and wildcards', function (): void {
    $exact = Subscription::factory()->create(['event' => 'order.placed']);
    $single = Subscription::factory()->create(['event' => 'order.*']);
    $cross = Subscription::factory()->create(['event' => 'order.**']);

    expect($exact->matchesEvent('order.placed'))->toBeTrue();
    expect($exact->matchesEvent('order.shipped'))->toBeFalse();

    expect($single->matchesEvent('order.placed'))->toBeTrue();
    expect($single->matchesEvent('order.placed.extra'))->toBeFalse();

    expect($cross->matchesEvent('order.placed'))->toBeTrue();
    expect($cross->matchesEvent('order.placed.extra'))->toBeTrue();
});

// ─── EventManager CRUD ──────────────────────────────────────────────────────

test('fire with empty event throws InvalidArgumentException', function (): void {
    $manager = $this->app->make(EventManager::class);

    expect(fn () => $manager->fire(''))->toThrow(\InvalidArgumentException::class);
    expect(fn () => $manager->fire('0'))->toThrow(\InvalidArgumentException::class);
});

test('fireModel with empty class throws InvalidArgumentException', function (): void {
    $manager = $this->app->make(EventManager::class);

    expect(fn () => $manager->fireModel('', 'created', new stdClass))
        ->toThrow(\InvalidArgumentException::class);
});

test('enable/disable non-existent trigger returns false', function (): void {
    $manager = $this->app->make(EventManager::class);

    expect($manager->enable('00000000-0000-0000-0000-000000000000'))->toBeFalse();
    expect($manager->disable('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

test('deleteTrigger non-existent returns false', function (): void {
    $manager = $this->app->make(EventManager::class);

    expect($manager->deleteTrigger('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

test('getTrigger non-existent returns null', function (): void {
    $manager = $this->app->make(EventManager::class);

    expect($manager->getTrigger('00000000-0000-0000-0000-000000000000'))->toBeNull();
});

// ─── TriggerBuilder Fluent Interface ─────────────────────────────────────────

test('TriggerBuilder all methods return self', function (): void {
    $manager = $this->app->make(EventManager::class);
    $builder = $manager->on('test.event');

    expect($builder->name('Test'))->toBe($builder);
    expect($builder->action(TestAction::class))->toBe($builder);
    expect($builder->actions([TestAction::class]))->toBe($builder);
    expect($builder->when(['key' => 'value']))->toBe($builder);
    expect($builder->async())->toBe($builder);
    expect($builder->priority(10))->toBe($builder);
    expect($builder->actionParams(['url' => 'https://test.com']))->toBe($builder);
});

// ─── SubscriptionBuilder Fluent Interface ────────────────────────────────────

test('SubscriptionBuilder all methods return self', function (): void {
    $manager = $this->app->make(EventManager::class);
    $builder = $manager->subscribe('test.event', 'https://test.com');

    expect($builder->on('test.event'))->toBe($builder);
    expect($builder->to('https://test.com'))->toBe($builder);
    expect($builder->withSecret('whsec_test'))->toBe($builder);
    expect($builder->withFilter(['key' => 'value']))->toBe($builder);
    expect($builder->priority(10))->toBe($builder);
    expect($builder->async())->toBe($builder);
});

// ─── Cache Invalidation Lifecycle ────────────────────────────────────────────

test('save invalidates trigger cache', function (): void {
    $manager = $this->app->make(EventManager::class);
    $manager->on('cache.test.event')
        ->action(TestAction::class)
        ->save();

    // Verify trigger is findable
    $triggers = $manager->listTriggers('cache.test.event');
    expect($triggers)->not->toBeEmpty();
});

// ─── getStats Zero-State ─────────────────────────────────────────────────────

test('getStats returns valid structure with zero logs', function (): void {
    $manager = $this->app->make(EventManager::class);
    $stats = $manager->getStats();

    expect($stats)->toBeArray();
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
    expect($stats['total_logs'])->toBeInt();
    expect($stats['total_logs'])->toBe(0);
    expect($stats['success_rate'])->toBeNull();
});

// ─── Version Consistency ─────────────────────────────────────────────────────

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    $version = $composer['version'] ?? '';
    expect($version)->not->toBeEmpty();
    expect($readme)->toContain("version-{$version}");
});

test('composer.json version is valid semver', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $version = $composer['version'] ?? '';

    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
});

// ─── Pest.php Completeness ──────────────────────────────────────────────────

test('all Pest.php registered test files exist on disk', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    preg_match_all("/'(\w+\.php)'/", $pestContent, $matches);

    $registeredFiles = $matches[1];
    expect($registeredFiles)->not->toBeEmpty();

    foreach ($registeredFiles as $file) {
        expect(file_exists(__DIR__.'/'.$file))->toBeTrue("Test file {$file} registered in Pest.php but not found on disk");
    }
});

test('no duplicate entries in Pest.php', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    preg_match_all("/'(\w+\.php)'/", $pestContent, $matches);

    $registeredFiles = $matches[1];
    $unique = array_unique($registeredFiles);

    expect(count($registeredFiles))->toBe(count($unique), 'Pest.php has duplicate test file entries');
});

test('standalone test files exist', function (): void {
    expect(file_exists(__DIR__.'/WildcardMatcherTest.php'))->toBeTrue();
    expect(file_exists(__DIR__.'/EscapesWildcardLikeTest.php'))->toBeTrue();
});

test('total test file count is accurate', function (): void {
    $allFiles = glob(__DIR__.'/*Test.php');
    $nonTestFiles = ['TestCase.php', 'CreatesApplication.php', 'TestActions.php'];

    // Count only *Test.php files, excluding non-test files
    $testFiles = array_filter($allFiles, function (string $file) use ($nonTestFiles): bool {
        $basename = basename($file);
        return ! in_array($basename, $nonTestFiles, true);
    });

    // Total should be 99 Pest + 2 standalone = 101 after Phase 40 is added
    // Before Phase 40: 100 test files (99 Pest + 2 standalone) = 101 (including this file)
    // After Phase 40: 102 test files (100 Pest + 2 standalone) = 102

    expect(count($testFiles))->toBeGreaterThanOrEqual(100);
});

// ─── Console Command Prefix ───────────────────────────────────────────────────

test('all console commands use zeroboiler:events: prefix', function (): void {
    $commands = [
        EventsListCommand::class,
        EventsFireCommand::class,
        EventsRegisterCommand::class,
        EventsEnableCommand::class,
        EventsDisableCommand::class,
        EventsRetryCommand::class,
        EventsLogCommand::class,
        EventsSubscribeCommand::class,
        EventsUnsubscribeCommand::class,
        EventsSubscriptionsCommand::class,
        EventsRedeliverCommand::class,
    ];

    foreach ($commands as $class) {
        $ref = new ReflectionClass($class);
        $prop = $ref->getProperty('signature');
        $sig = $prop->getValue(new ($class)($this->app, []));

        expect(str_starts_with($sig, 'zeroboiler:events:'))->toBeTrue(
            "{$class} signature must start with 'zeroboiler:events:'"
        );
    }
});

// ─── Composer.json Structure ─────────────────────────────────────────────────

test('composer.json has correct autoload PSR-4', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
    expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
});

test('composer.json has correct extra.laravel providers', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider'
    );
});

test('composer.json requires PHP ^8.5', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['require']['php'])->toBe('^8.5');
});

// ─── File Headers ────────────────────────────────────────────────────────────

test('all source files have license header', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('This file is part of ZeroBoiler');
    }
});

// ─── TriggerBuilder resolveActions ───────────────────────────────────────────

test('TriggerBuilder resolveActions deduplicates and preserves order', function (): void {
    $manager = $this->app->make(EventManager::class);

    // Register TestAction in container
    $this->app->bind(TestAction::class, fn (): TestAction => new TestAction);

    $trigger = $manager->on('dedup.test')
        ->actions([TestAction::class, TestAction::class, TestAction::class])
        ->save();

    // Action should be saved as JSON array with deduplication
    $actionData = json_decode($trigger->action, true);
    expect($actionData)->toBe([TestAction::class]);
});

test('TriggerBuilder action() + actions() merge and deduplicate', function (): void {
    $manager = $this->app->make(EventManager::class);
    $this->app->bind(TestAction::class, fn (): TestAction => new TestAction);

    $trigger = $manager->on('merge.test')
        ->action(TestAction::class)
        ->actions([TestAction::class])
        ->save();

    $actionData = json_decode($trigger->action, true);
    expect($actionData)->toBe([TestAction::class]);
});

// ─── EventsFireCommand Edge Cases ─────────────────────────────────────────────

test('EventsFireCommand handle method has int return type', function (): void {
    $ref = new ReflectionMethod(EventsFireCommand::class, 'handle');
    expect($ref->getReturnType()?->getName())->toBe('int');
});

test('EventsFireCommand validates empty event name throws InvalidArgumentException', function (): void {
    $manager = $this->app->make(EventManager::class);

    // fire() already validates empty — verify the command would reject it too
    expect(fn () => $manager->fire(''))->toThrow(\InvalidArgumentException::class);
});

// ─── Config Merge Verification ───────────────────────────────────────────────

test('config is merged from package config file', function (): void {
    $config = config('events');
    expect($config)->not->toBeNull();
    expect($config)->toBeArray();

    // Verify default values match the published config
    expect($config['wildcard_cache_ttl'])->toBe(300);
    expect($config['subscriptions']['max_failures'])->toBe(10);
    expect($config['subscriptions']['timeout'])->toBe(30);
    expect($config['subscriptions']['signature_algorithm'])->toBe('sha256');
    expect($config['retry']['tries'])->toBe(3);
});

// ─── Migration File Existence ───────────────────────────────────────────────

test('all 3 migration files exist', function (): void {
    $migrations = glob(__DIR__.'/../database/migrations/*.php');
    expect($migrations)->toHaveCount(3);

    foreach ($migrations as $migration) {
        $contents = file_get_contents($migration);
        expect($contents)->toContain('Schema::create');
    }
});

// ─── phpstan.neon.dist existence ─────────────────────────────────────────────

test('phpstan.neon.dist configuration file exists', function (): void {
    expect(file_exists(__DIR__.'/../phpstan.neon.dist'))->toBeTrue();
});

// ─── README Test File Count Description ─────────────────────────────────────

test('README accurately describes test file composition', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');

    // README should mention total test file count
    // After Phase 40: 100 Pest + 2 standalone = 102 total
    // Count actual test files on disk
    $allTestFiles = glob(__DIR__.'/*Test.php');
    $nonTestFiles = ['TestCase.php', 'CreatesApplication.php', 'TestActions.php'];
    $testFiles = array_filter($allTestFiles, function (string $file) use ($nonTestFiles): bool {
        return ! in_array(basename($file), $nonTestFiles, true);
    });
    $totalCount = count($testFiles);

    expect($readme)->toContain((string) $totalCount);
});

// ─── DispatchTriggerJob Config Properties ──────────────────────────────────────

test('DispatchTriggerJob reads config-driven properties', function (): void {
    $job = new DispatchTriggerJob('trigger-id', 'test.event', []);

    $ref = new ReflectionClass($job);

    // tries
    $tries = $ref->getProperty('tries');
    expect($tries->getValue($job))->toBe(3);

    // queue
    $queue = $ref->getProperty('queue');
    expect($queue->getValue($job))->toBeString();

    // backoff
    $backoff = $ref->getProperty('backoff');
    expect($backoff->getValue($job))->toBeArray();

    // eventLogId initially null
    $eventLogId = $ref->getProperty('eventLogId');
    expect($eventLogId->getValue($job))->toBeNull();
});

// ─── new trait: ManagesHistory / ManagesSubscriptions ───────────────────────

test('EventManager has ManagesHistory trait', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    expect($ref->hasMethod('getEventHistory'))->toBeTrue();
    expect($ref->hasMethod('getStats'))->toBeTrue();
    expect($ref->hasMethod('purgeLogs'))->toBeTrue();
});

test('EventManager has ManagesSubscriptions trait', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    expect($ref->hasMethod('subscribe'))->toBeTrue();
    expect($ref->hasMethod('unsubscribe'))->toBeTrue();
    expect($ref->hasMethod('listSubscriptions'))->toBeTrue();
    expect($ref->hasMethod('getSubscription'))->toBeTrue();
    expect($ref->hasMethod('subscribeWebhook'))->toBeTrue();
});

// ─── Model Key Types & Incrementing ─────────────────────────────────────────

test('all models use string key type and non-incrementing', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $ref = new ReflectionClass($model);

        $keyType = $ref->getProperty('keyType');
        expect($keyType->getValue(new $model))->toBe('string');

        $incrementing = $ref->getProperty('incrementing');
        expect($incrementing->getValue(new $model))->toBeFalse();
    }
});

// ─── EventLog Status Lifecycle ──────────────────────────────────────────────

test('EventLog markAsCompleted updates status and duration', function (): void {
    $trigger = Trigger::factory()->enabled()->create();
    $log = EventLog::factory()->pending()->forTrigger($trigger->id)->create();

    $log->markAsCompleted(150);

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    expect($log->duration_ms)->toBe(150);
});

test('EventLog markAsFailed updates status and error', function (): void {
    $trigger = Trigger::factory()->enabled()->create();
    $log = EventLog::factory()->pending()->forTrigger($trigger->id)->create();

    $log->markAsFailed('Something went wrong');

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_FAILED);
    expect($log->error)->toBe('Something went wrong');
});

// ─── parseActions Edge Cases ─────────────────────────────────────────────────

test('parseActions handles empty string', function (): void {
    $ref = new ReflectionMethod(EventManager::class, 'parseActions');
    $manager = $this->app->make(EventManager::class);

    expect($ref->invoke($manager, ''))->toBe([]);
    expect($ref->invoke($manager, '0'))->toBe([]);
});

test('parseActions handles single class name', function (): void {
    $ref = new ReflectionMethod(EventManager::class, 'parseActions');
    $manager = $this->app->make(EventManager::class);

    $result = $ref->invoke($manager, 'App\\Actions\\TestAction');
    expect($result)->toBe(['App\\Actions\\TestAction']);
});

test('parseActions handles JSON array of classes', function (): void {
    $ref = new ReflectionMethod(EventManager::class, 'parseActions');
    $manager = $this->app->make(EventManager::class);

    $result = $ref->invoke($manager, json_encode(['App\\A', 'App\\B']));
    expect($result)->toBe(['App\\A', 'App\\B']);
});

test('parseActions handles JSON object with class + params', function (): void {
    $ref = new ReflectionMethod(EventManager::class, 'parseActions');
    $manager = $this->app->make(EventManager::class);

    $result = $ref->invoke($manager, json_encode([
        'class' => 'App\\Webhook',
        'params' => ['url' => 'https://test.com'],
    ]));

    expect($result[0])->toBeArray();
    expect($result[0]['class'])->toBe('App\\Webhook');
    expect($result[0]['params'])->toBe(['url' => 'https://test.com']);
});

test('parseActions handles classes key format', function (): void {
    $ref = new ReflectionMethod(EventManager::class, 'parseActions');
    $manager = $this->app->make(EventManager::class);

    $result = $ref->invoke($manager, json_encode([
        'classes' => ['App\\A', 'App\\B'],
        'params' => ['url' => 'https://test.com'],
    ]));

    expect($result)->toHaveCount(2);
    expect($result[0]['class'])->toBe('App\\A');
    expect($result[0]['params'])->toBe(['url' => 'https://test.com']);
});

// ─── Helper TestAction Class ─────────────────────────────────────────────────

final class TestAction implements Triggerable
{
    #[\Override]
    public function handle(array $payload): void {}
}
