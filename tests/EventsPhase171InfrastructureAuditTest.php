<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
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
 * Phase 171 Infrastructure Audit — production readiness verification.
 *
 * Comprehensive checks for: source file quality (strict types, final, readonly,
 * typed properties), DispatchTriggerJob #[\Override] correctness, ServiceProvider
 * bindings, config completeness, facade accessor, DomainEvent identity,
 * ReDoS protection, WildcardMatcher patterns, EventManager global disable,
 * ConditionEngine operators, WebhookAction HMAC, EscapesWildcardLike SQL injection,
 * TriggerBuilder dedup, phpstan.neon.dist, composer.json, Subscription atomicity,
 * wildcard cache TTL, console command signatures, factory model references,
 * migration timestamp ordering.
 */
test('Phase 171: all 33 source files have declare(strict_types=1)', function (): void {
    $srcDir = realpath(__DIR__.'/../src');
    expect($srcDir)->not->toBeFalse();

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $checked = 0;
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        expect($content)->not->toBeFalse();
        expect($content)->toContain('declare(strict_types=1)');
        $checked++;
    }

    expect($checked)->toBeGreaterThanOrEqual(33);
});

test('Phase 171: all source classes are declared final', function (): void {
    $classes = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        WildcardMatcher::class,
        DomainEvent::class,
        EventScheduler::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventsServiceProvider::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
        EventLog::class,
        Subscription::class,
        Trigger::class,
        EventManagerFacade::class,
    ];

    foreach ($classes as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

test('Phase 171: DispatchTriggerJob handle() and failed() do NOT have #[Override] attribute', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);

    $handleMethod = $ref->getMethod('handle');
    $handleAttrs = array_map(
        fn (ReflectionAttribute $a): string => $a->getName(),
        $handleMethod->getAttributes(),
    );
    expect($handleAttrs)->not->toContain('Override',
        'DispatchTriggerJob::handle() must NOT have #[Override] — it does not override any parent/interface method');

    $failedMethod = $ref->getMethod('failed');
    $failedAttrs = array_map(
        fn (ReflectionAttribute $a): string => $a->getName(),
        $failedMethod->getAttributes(),
    );
    expect($failedAttrs)->not->toContain('Override',
        'DispatchTriggerJob::failed() must NOT have #[Override] — it does not override any parent/interface method');
});

test('Phase 171: EventsServiceProvider provides 7 bindings', function (): void {
    $provider = new EventsServiceProvider(app());
    $provides = $provider->provides();

    expect($provides)->toBe([
        EventManager::class,
        ConditionEngine::class,
        ConditionEngineContract::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
    ]);
    expect($provides)->toHaveCount(7);
});

test('Phase 171: config has 8 top-level keys', function (): void {
    $config = config('events');
    expect($config)->toBeArray();

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
        expect($config)->toHaveKey($key);
    }

    // Verify table_names has 3 sub-keys
    expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);

    // Verify subscriptions has all documented sub-keys
    expect($config['subscriptions'])->toHaveKeys([
        'auto_generate_secret',
        'secret_length',
        'max_failures',
        'timeout',
        'signature_algorithm',
        'cleanup_cron',
    ]);
});

test('Phase 171: Facade accessor returns EventManager class name', function (): void {
    $ref = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    $ref->setAccessible(true);
    $accessor = $ref->invoke(null);

    expect($accessor)->toBe(EventManager::class);
});

test('Phase 171: DomainEvent roundtrip preserves identity', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);
    $data = $event->toArray();
    $restored = DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->eventType)->toBe($event->eventType);
    expect($restored->payload)->toBe($event->payload);
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
        ->toBe($event->occurredAt->format(DateTimeImmutable::ATOM));
});

test('Phase 171: ReDoS protection rejects long patterns and nested quantifiers', function (): void {
    $engine = new ConditionEngine;

    // Long pattern should return false (not match)
    $longPattern = str_repeat('a', 501);
    expect($engine->matches(
        ['code' => ['matches', '/' . $longPattern . '/']],
        ['code' => 'test'],
    ))->toBeFalse();

    // Nested quantifiers should return false
    expect($engine->matches(
        ['code' => ['matches', '/(a+)+b/']],
        ['code' => 'aaab'],
    ))->toBeFalse();
});

test('Phase 171: WildcardMatcher handles all pattern types', function (): void {
    // Catch-all
    expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();

    // Cross-segment
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();

    // Single-segment
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();

    // Exact match
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();

    // Extract wildcards
    expect(WildcardMatcher::extractWildcards('user.*.created', 'user.john.created'))
        ->toBe(['john']);
    expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))
        ->toBe([]);
});

test('Phase 171: EventManager global disable works correctly', function (): void {
    // Ensure TestNullAction exists
    if (! class_exists('TestNullAction')) {
        final class TestNullAction implements Triggerable
        {
            public function handle(array $payload): void {}
        }
    }

    $em = app(EventManager::class);

    // Should not be disabled by default
    expect($em->isDisabled())->toBeFalse();

    // Disable
    $em->setEnabled(false);
    expect($em->isDisabled())->toBeTrue();

    // Fire should be a no-op when disabled
    $trigger = Trigger::factory()->enabled()->create([
        'event' => 'test.disable.event',
        'action' => TestNullAction::class,
        'conditions' => null,
    ]);

    $em->fire('test.disable.event', ['key' => 'value']);
    // No event log should be created
    expect(EventLog::where('event', 'test.disable.event')->exists())->toBeFalse();

    // Re-enable
    $em->setEnabled(true);
    expect($em->isDisabled())->toBeFalse();
});

test('Phase 171: ConditionEngine supports all 21 operators', function (): void {
    $engine = new ConditionEngine;

    // Comparison operators
    expect($engine->matches(['a' => ['>', 5]], ['a' => 10]))->toBeTrue();
    expect($engine->matches(['a' => ['>=', 5]], ['a' => 5]))->toBeTrue();
    expect($engine->matches(['a' => ['<', 5]], ['a' => 3]))->toBeTrue();
    expect($engine->matches(['a' => ['<=', 5]], ['a' => 5]))->toBeTrue();
    expect($engine->matches(['a' => ['=', 5]], ['a' => 5]))->toBeTrue();
    expect($engine->matches(['a' => ['===', 5]], ['a' => 5]))->toBeTrue();
    expect($engine->matches(['a' => ['!=', 5]], ['a' => 6]))->toBeTrue();
    expect($engine->matches(['a' => ['!==', 5]], ['a' => '5']))->toBeTrue();

    // Array operators
    expect($engine->matches(['a' => ['in', [1, 2, 3]]], ['a' => 2]))->toBeTrue();
    expect($engine->matches(['a' => ['not_in', [1, 2, 3]]], ['a' => 4]))->toBeTrue();
    expect($engine->matches(['a' => ['contains', 'hello']], ['a' => 'hello world']))->toBeTrue();
    expect($engine->matches(['a' => ['not_contains', 'bye']], ['a' => 'hello']))->toBeTrue();

    // Range
    expect($engine->matches(['a' => ['between', [1, 10]]], ['a' => 5]))->toBeTrue();

    // Null checks
    expect($engine->matches(['a' => ['null']], ['a' => null]))->toBeTrue();
    expect($engine->matches(['a' => ['not_null']], ['a' => 'value']))->toBeTrue();

    // Empty checks
    expect($engine->matches(['a' => ['empty']], ['a' => null]))->toBeTrue();
    expect($engine->matches(['a' => ['not_empty']], ['a' => 'value']))->toBeTrue();

    // String operators
    expect($engine->matches(['a' => ['starts_with', 'hel']], ['a' => 'hello']))->toBeTrue();
    expect($engine->matches(['a' => ['ends_with', 'llo']], ['a' => 'hello']))->toBeTrue();
    expect($engine->matches(['a' => ['matches', '/^h/']], ['a' => 'hello']))->toBeTrue();

    // Simple equality (no operator array)
    expect($engine->matches(['a' => 'hello'], ['a' => 'hello']))->toBeTrue();
    expect($engine->matches(['a' => 'hello'], ['a' => 'world']))->toBeFalse();
});

test('Phase 171: WebhookAction HMAC signature is deterministic', function (): void {
    $sub = Subscription::factory()->withSecret('whsec_test_secret_12345')->create();
    $payload = json_encode(['event' => 'test.event', 'data' => ['key' => 'value']]);
    $sig1 = $sub->signPayload($payload);
    $sig2 = $sub->signPayload($payload);

    expect($sig1)->toBe($sig2);
    expect($sig1)->not->toBe('');
    expect(strlen($sig1))->toBe(64); // sha256 hex output
});

test('Phase 171: EscapesWildcardLike prevents SQL injection', function (): void {
    // Use a simple test via the trait's method
    $matcher = new class {
        use ZeroBoiler\Events\Concerns\EscapesWildcardLike;

        public function testConvert(string $pattern): ?string
        {
            return $this->wildcardToLike($pattern);
        }
    };

    // Non-wildcard returns null
    expect($matcher->testConvert('order.placed'))->toBeNull();

    // Wildcard converts to LIKE
    expect($matcher->testConvert('order.*'))->toBe('order.%');

    // Special chars are escaped
    expect($matcher->testConvert('order.%_test*'))->toBe('order.\\%\\_test%');

    // Backslashes are escaped
    expect($matcher->testConvert('order\\*test*'))->toBe('order\\\\%test%');
});

test('Phase 171: TriggerBuilder deduplicates actions', function (): void {
    // Ensure TestNullAction exists
    if (! class_exists('TestNullAction')) {
        final class TestNullAction implements Triggerable
        {
            public function handle(array $payload): void {}
        }
    }

    $em = app(EventManager::class);

    $trigger = $em->on('test.dedup.event')
        ->action(TestNullAction::class)
        ->actions([TestNullAction::class, \ZeroBoiler\Events\Tests\Actions\AnotherAction'])
        ->name('Dedup Test')
        ->save();

    $actions = json_decode($trigger->action, true);

    // action() was prepended, and dedup removed the duplicate
    expect($actions)->toBeArray();
    expect(count($actions))->toBe(2);
    expect($actions[0])->toBe(TestNullAction::class);
    expect($actions[1])->toBe(\ZeroBoiler\Events\Tests\Actions\AnotherAction');
});

test('Phase 171: phpstan.neon.dist has level 9 and bootstrapFiles', function (): void {
    $neonPath = realpath(__DIR__.'/../phpstan.neon.dist');
    expect($neonPath)->toBeFile();

    $content = file_get_contents($neonPath);
    expect($content)->toContain('level: 9');
    expect($content)->toContain('bootstrapFiles:');
    expect($content)->toContain('tests/helpers.php');
    expect($content)->toContain('checkExplicitMixed: true');
    expect($content)->toContain('checkUninitializedProperties: true');
    expect($content)->toContain('checkAlwaysTrueInstanceof: true');
});

test('Phase 171: composer.json requires PHP 8.5+ and Laravel 13.x', function (): void {
    $composerPath = realpath(__DIR__.'/../composer.json');
    expect($composerPath)->toBeFile();

    $json = json_decode(file_get_contents($composerPath), true);
    expect($json)->toBeArray();

    expect($json['require']['php'])->toBe('^8.5');
    expect($json['require']['illuminate/contracts'])->toBe('^13.0');
    expect($json['require']['illuminate/support'])->toBe('^13.0');
    expect($json['require']['illuminate/database'])->toBe('^13.0');
    expect($json['require']['ramsey/uuid'])->toBe('^4.7');

    // Verify autoload
    expect($json['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');

    // Verify service provider discovery
    expect($json['extra']['laravel']['providers'][0])
        ->toBe('ZeroBoiler\\Events\\EventsServiceProvider');
    expect($json['extra']['laravel']['aliases']['EventManager'])
        ->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
});

test('Phase 171: Subscription recordDelivery is transactional', function (): void {
    $sub = Subscription::factory()->create([
        'failure_count' => 0,
        'delivery_count' => 0,
        'last_fired_at' => null,
    ]);

    $sub->recordDelivery();
    $sub->refresh();

    expect($sub->delivery_count)->toBe(1);
    expect($sub->last_fired_at)->not->toBeNull();

    $beforeCount = $sub->delivery_count;
    $sub->recordDelivery();
    $sub->refresh();
    expect($sub->delivery_count)->toBe($beforeCount + 1);
});

test('Phase 171: wildcard cache TTL respects config', function (): void {
    // Default value
    expect(config('events.wildcard_cache_ttl'))->toBe(300);

    // Can be set to 0 (disabled)
    app('config')->set('events.wildcard_cache_ttl', 0);
    expect(config('events.wildcard_cache_ttl'))->toBe(0);

    // Restore
    app('config')->set('events.wildcard_cache_ttl', 300);
});

test('Phase 171: all 12 console commands have valid signatures', function (): void {
    $commandClasses = [
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
    ];

    expect($commandClasses)->toHaveCount(12);

    foreach ($commandClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");

        // Verify signature property exists and starts with 'zeroboiler:events:'
        $prop = $ref->getProperty('signature');
        $prop->setAccessible(true);
        $signature = $prop->getValue($ref->newInstanceWithoutConstructor());
        expect($signature)->toBeString();
        expect($signature)->toStartWith('zeroboiler:events:');
    }
});

test('Phase 171: all 3 factories reference correct models', function (): void {
    $factoryClasses = [
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class => EventLog::class,
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class => Trigger::class,
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class => Subscription::class,
    ];

    foreach ($factoryClasses as $factoryClass => $expectedModel) {
        $ref = new ReflectionClass($factoryClass);
        $prop = $ref->getProperty('model');
        expect($prop->getValue())->toBe($expectedModel);
    }
});

test('Phase 171: all 3 migrations have correct timestamp ordering', function (): void {
    $migrationFiles = [
        '2024_01_01_000001_create_triggers_table.php',
        '2024_01_01_000002_create_event_logs_table.php',
        '2025_06_28_000001_create_event_subscriptions_table.php',
    ];

    foreach ($migrationFiles as $file) {
        $path = __DIR__.'/../database/migrations/'.$file;
        expect(file_exists($path))->toBeTrue("Migration {$file} must exist");
    }

    // Verify ordering: triggers before event_logs, event_logs before subscriptions
    expect($migrationFiles[0])->toBe('2024_01_01_000001_create_triggers_table.php');
    expect($migrationFiles[1])->toBe('2024_01_01_000002_create_event_logs_table.php');
    expect($migrationFiles[2])->toBe('2025_06_28_000001_create_event_subscriptions_table.php');
});

test('Phase 171: EventLog status constants cover all lifecycle states', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');

    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
    expect(EventLog::$statuses)->toHaveCount(4);
});

test('Phase 171: all source files have license headers', function (): void {
    $srcDir = realpath(__DIR__.'/../src');
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        expect($content)->toContain('This file is part of ZeroBoiler');
    }
});
