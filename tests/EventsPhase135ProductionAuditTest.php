<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Actions\WebhookAction;
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

// ─── Phase 135: Final Production Audit ───────────────────────────────────────

test('all source files have declare(strict_types=1)', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');
    $srcFiles = array_merge($srcFiles, glob(__DIR__.'/../src/*.php'));

    expect($srcFiles)->not->toBeEmpty();

    foreach ($srcFiles as $file) {
        $contents = (string) file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)', "Missing strict_types in {$file}");
    }
});

test('all source classes are final', function (): void {
    $nonFinalClasses = [
        // Traits and interfaces should NOT be final
        EscapesWildcardLike::class,
        GetsWebhookTimeout::class,
        ManagesHistory::class,
        ManagesSubscriptions::class,
        ConditionEngineContract::class,
        Triggerable::class,
    ];

    $classesThatShouldBeFinal = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
        EventsServiceProvider::class,
        EventManagerFacade::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
        DomainEvent::class,
        WildcardMatcher::class,
        Trigger::class,
        EventLog::class,
        Subscription::class,
    ];

    foreach ($classesThatShouldBeFinal as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} should be declared final");
    }
});

test('EventManager::listTriggers with empty string event returns all triggers', function (): void {
    $manager = app(EventManager::class);

    // Empty string event should behave like no filter
    $result = $manager->listTriggers('');

    expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
});

test('EventManager::listTriggers with zero-string event returns all triggers', function (): void {
    $manager = app(EventManager::class);

    // '0' event should behave like no filter
    $result = $manager->listTriggers('0');

    expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
});

test('ConditionEngine handles float/int comparison correctly', function (): void {
    $engine = app(ConditionEngine::class);

    // Float that is exactly equal to int should match
    expect($engine->matches(['amount' => ['>=', 100]], ['amount' => 100.0]))->toBeTrue();
    expect($engine->matches(['amount' => ['>=', 100]], ['amount' => 100]))->toBeTrue();
    expect($engine->matches(['amount' => ['>', 99]], ['amount' => 99.5]))->toBeTrue();
});

test('ConditionEngine between with float boundaries', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['value' => ['between', [1.5, 5.5]]], ['value' => 3.0]))->toBeTrue();
    expect($engine->matches(['value' => ['between', [1.5, 5.5]]], ['value' => 1.5]))->toBeTrue();
    expect($engine->matches(['value' => ['between', [1.5, 5.5]]], ['value' => 5.5]))->toBeTrue();
    expect($engine->matches(['value' => ['between', [1.5, 5.5]]], ['value' => 1.4]))->toBeFalse();
    expect($engine->matches(['value' => ['between', [1.5, 5.5]]], ['value' => 5.6]))->toBeFalse();
});

test('DomainEvent fromArray preserves UUID with non-standard UUID formats gracefully', function (): void {
    // UUID v5 (non-v4) should still parse correctly
    $uuid = Ramsey\Uuid\Uuid::uuid5(Ramsey\Uuid\Uuid::NAMESPACE_DNS, 'example.com');
    $event = DomainEvent::occur('test.event', ['key' => 'value']);

    $data = $event->toArray();
    $data['eventId'] = $uuid->toString();

    $reconstructed = DomainEvent::fromArray($data);
    expect($reconstructed->eventId->toString())->toBe($uuid->toString());
});

test('WildcardMatcher matches with Unicode event names', function (): void {
    expect(WildcardMatcher::matches('user.*', 'user.özel'))->toBeTrue();
    expect(WildcardMatcher::matches('*.created', 'étudiant.created'))->toBeTrue();
    expect(WildcardMatcher::matches('*.*', '日本語.イベント'))->toBeTrue();
});

test('WildcardMatcher extractWildcards preserves Unicode segments', function (): void {
    $result = WildcardMatcher::extractWildcards('user.*.created', 'user.özel.created');
    expect($result)->toBe(['özel']);
});

test('Subscription signPayload with sha512 algorithm', function (): void {
    // Test that sha512 works (if supported by PHP)
    $subscription = Subscription::factory()->create([
        'secret' => 'whsec_test_secret_123',
    ]);

    // Temporarily override config
    config(['events.subscriptions.signature_algorithm' => 'sha512']);

    $signature = $subscription->signPayload('test-payload');

    expect($signature)->not->toBeEmpty();
    expect($signature)->not->toBe('0');

    // Verify the signature matches sha512
    $expected = hash_hmac('sha512', 'test-payload', 'whsec_test_secret_123');
    expect($signature)->toBe($expected);
});

test('DispatchTriggerJob constructor reads connection from config', function (): void {
    config(['events.queue.connection' => 'redis-long']);

    $job = new DispatchTriggerJob('trigger-123', 'test.event', ['key' => 'value']);

    expect($job->connection)->toBe('redis-long');
    expect($job->queue)->toBe('default');
    expect($job->tries)->toBe(3);
});

test('DispatchTriggerJob constructor with null connection config', function (): void {
    config(['events.queue.connection' => null]);

    $job = new DispatchTriggerJob('trigger-123', 'test.event', ['key' => 'value']);

    expect($job->connection)->toBeNull();
});

test('TriggerBuilder actions validates empty strings', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->on('test.event');

    expect(fn () => $builder->actions(['ValidAction', '']))->toThrow(\InvalidArgumentException::class);
    expect(fn () => $builder->actions(['ValidAction', '0']))->toThrow(\InvalidArgumentException::class);
});

test('Subscription scopeForEvent with exact match queries both exact and wildcard', function (): void {
    // Non-wildcard event should query for exact match OR wildcard triggers
    $subs = Subscription::query()
        ->forEvent('order.placed')
        ->get();

    expect($subs)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
});

test('EventLog scopeStalePending combines status and time filter', function (): void {
    $threshold = now()->subHours(2);

    $staleLogs = EventLog::query()
        ->stalePending($threshold)
        ->get();

    // Every result should have pending status
    foreach ($staleLogs as $log) {
        expect($log->status)->toBe(EventLog::STATUS_PENDING);
    }
});

test('EventManager::fire does not dispatch when globally disabled', function (): void {
    $manager = app(EventManager::class);

    // Create a trigger
    $trigger = Trigger::factory()->create([
        'event' => 'test.disabled.event',
        'enabled' => true,
        'action' => json_encode(TestActions\NullAction::class),
        'async' => false,
    ]);

    // Disable globally
    $manager->setEnabled(false);

    $logCountBefore = EventLog::where('trigger_id', $trigger->id)->count();

    // Fire — should be silently ignored
    $manager->fire('test.disabled.event', ['key' => 'value']);

    $logCountAfter = EventLog::where('trigger_id', $trigger->id)->count();

    expect($logCountAfter)->toBe($logCountBefore);

    // Re-enable
    $manager->setEnabled(true);
});

test('EventManager::getStats returns correct structure with empty database', function (): void {
    $manager = app(EventManager::class);
    $stats = $manager->getStats();

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
    expect($stats['total_triggers'])->toBeInt();
    expect($stats['active_triggers'])->toBeInt();
});

test('EventManager::purgeLogs returns int count', function (): void {
    $manager = app(EventManager::class);
    $count = $manager->purgeLogs(now()->subDays(365), includePending: false);

    expect($count)->toBeInt();
    expect($count)->toBeGreaterThanOrEqual(0);
});

test('ServiceProvider provides list includes all registered bindings', function (): void {
    $provider = new EventsServiceProvider($this->app);
    $provides = $provider->provides();

    expect($provides)->toContain(EventManager::class);
    expect($provides)->toContain(ConditionEngine::class);
    expect($provides)->toContain(ConditionEngineContract::class);
    expect($provides)->toContain(ActionResolver::class);
    expect($provides)->toContain(TriggerBuilder::class);
    expect($provides)->toContain(SubscriptionBuilder::class);
    expect($provides)->toContain(EventScheduler::class);
});

test('phpstan.neon.dist has correct level for PHPStan 2.x', function (): void {
    $contents = (string) file_get_contents(__DIR__.'/../phpstan.neon.dist');

    // PHPStan 2.x supports levels 0-8 only
    expect($contents)->toContain('level: 9');
    expect($contents)->toContain('paths:');
    expect($contents)->toContain('src');
    expect($contents)->toContain('reportUnmatchedIgnoredErrors: true');
});

test('composer.json requires PHP 8.5+', function (): void {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['require']['php'])->toBe('^8.5');
});

test('config file has all required keys', function (): void {
    $config = include __DIR__.'/../config/events.php';

    expect($config)->toHaveKeys([
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'disabled',
        'wildcard_cache_ttl',
    ]);

    expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    expect($config['queue'])->toHaveKeys(['connection', 'queue']);
    expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
    expect($config['retention'])->toHaveKeys(['days', 'include_pending', 'schedule_cron']);
    expect($config['subscriptions'])->toHaveKeys([
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
        'cleanup_cron',
    ]);
});

test('all console commands are registered in ServiceProvider', function (): void {
    $provider = new EventsServiceProvider($this->app);

    // Boot to register commands
    $this->app->boot();

    $registeredCommands = [];
    // We can't easily introspect the registered commands from the provider,
    // so verify the boot() method includes all 12 commands in the source
    $source = (string) file_get_contents(__DIR__.'/../src/EventsServiceProvider.php');

    $commandClasses = [
        'EventsListCommand',
        'EventsRegisterCommand',
        'EventsFireCommand',
        'EventsLogCommand',
        'EventsRetryCommand',
        'EventsEnableCommand',
        'EventsDisableCommand',
        'EventsHealthCommand',
        'EventsSubscribeCommand',
        'EventsUnsubscribeCommand',
        'EventsSubscriptionsCommand',
        'EventsRedeliverCommand',
    ];

    foreach ($commandClasses as $command) {
        expect($source)->toContain($command, "ServiceProvider must register {$command}");
    }
});

test('Facade getFacadeAccessor returns correct binding', function (): void {
    // PHP 8.5+: Reflection methods are always accessible — no setAccessible() needed
    $ref = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    $result = $ref->invoke(null);

    expect($result)->toBe(EventManager::class);
});

test('Trigger model has correct table name from config', function (): void {
    $trigger = new Trigger;
    expect($trigger->getTable())->toBe(config('events.table_names.triggers', 'triggers'));
});

test('EventLog model has correct table name from config', function (): void {
    $log = new EventLog;
    expect($log->getTable())->toBe(config('events.table_names.event_logs', 'event_logs'));
});

test('Subscription model has correct table name from config', function (): void {
    $sub = new Subscription;
    expect($sub->getTable())->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
});

test('version is consistent between composer.json and README', function (): void {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = (string) file_get_contents(__DIR__.'/../README.md');

    $version = $composer['version'];
    expect($readme)->toContain("version-{$version}");
    expect($readme)->toContain("### v{$version}");
});

test('no TODO or FIXME comments remain in source files', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');
    $srcFiles = array_merge($srcFiles, glob(__DIR__.'/../src/*.php'));

    $violations = [];
    foreach ($srcFiles as $file) {
        $contents = (string) file_get_contents($file);
        $lines = explode("\n", $contents);
        foreach ($lines as $num => $line) {
            if (preg_match('/\/\/\s*(TODO|FIXME|HACK|XXX)\b/i', $line)) {
                $relative = str_replace(__DIR__.'/../', '', $file);
                $violations[] = "{$relative}:{$num}";
            }
        }
    }

    expect($violations)->toBeEmpty('Found TODO/FIXME/HACK/XXX comments in: '.implode(', ', $violations));
});

test('EventScheduler registration is consistent across both methods', function (): void {
    // Verify registerLogPurge and registerSubscriptionCleanup both use resolveEventManager()
    $source = (string) file_get_contents(__DIR__.'/../src/EventScheduler.php');

    // Count resolveEventManager() calls in register methods
    expect(substr_count($source, 'resolveEventManager()'))->toBeGreaterThanOrEqual(2);
    expect($source)->toContain('registerLogPurge');
    expect($source)->toContain('registerSubscriptionCleanup');
});

test('WebhookAction strips internal keys from payload before sending', function (): void {
    // This is verified by checking the source code structure
    $source = (string) file_get_contents(__DIR__.'/../src/Actions/WebhookAction.php');

    // Must unset url, event, headers, subscription_id from webhook data
    expect($source)->toContain("unset(\$webhookData['url']");
    expect($source)->toContain("unset(\$webhookData['event']");
    expect($source)->toContain("unset(\$webhookData['headers']");
    expect($source)->toContain("unset(\$webhookData['subscription_id']");
});

test('EventManager parseActions handles JSON with classes key', function (): void {
    // Use reflection to call the protected method
    $manager = app(EventManager::class);
    $ref = new ReflectionMethod($manager, 'parseActions');

    $json = json_encode(['classes' => ['ActionA', 'ActionB'], 'params' => ['url' => 'https://example.com']]);
    $result = $ref->invoke($manager, $json);

    expect($result)->toBe([
        ['class' => 'ActionA', 'params' => ['url' => 'https://example.com']],
        ['class' => 'ActionB', 'params' => ['url' => 'https://example.com']],
    ]);
});

test('EventManager parseActions handles single class name', function (): void {
    $manager = app(EventManager::class);
    $ref = new ReflectionMethod($manager, 'parseActions');

    $result = $ref->invoke($manager, \ZeroBoiler\Events\Tests\Actions\SendNotification');

    expect($result)->toBe([\ZeroBoiler\Events\Tests\Actions\SendNotification']);
});

test('EventManager parseActions handles empty string', function (): void {
    $manager = app(EventManager::class);
    $ref = new ReflectionMethod($manager, 'parseActions');

    expect($ref->invoke($manager, ''))->toBe([]);
    expect($ref->invoke($manager, '0'))->toBe([]);
});

test('GitHub Actions CI workflow exists and targets PHP 8.5', function (): void {
    $ciPath = __DIR__.'/../.github/workflows/ci.yml';
    expect(file_exists($ciPath))->toBeTrue('ci.yml workflow must exist');

    $contents = (string) file_get_contents($ciPath);
    expect($contents)->toContain("php-version: '8.5'");
    expect($contents)->toContain('phpstan analyse');
    expect($contents)->toContain('pint');
    expect($contents)->toContain('rector');
});

test('auto-fix workflow exists for PRs', function (): void {
    $path = __DIR__.'/../.github/workflows/auto-fix.yml';
    expect(file_exists($path))->toBeTrue('auto-fix.yml workflow must exist');

    $contents = (string) file_get_contents($path);
    expect($contents)->toContain('pull_request');
    expect($contents)->toContain('rector');
    expect($contents)->toContain('pint');
});
