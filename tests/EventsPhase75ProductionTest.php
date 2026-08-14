<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\LogOrderEvent;
use App\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
    Cache::clear();
    Queue::fake();
});

// ─── Config & ServiceProvider ───────────────────────────────────────

test('config has all 7 top-level sections', function (): void {
    $config = Config::get('events');

    expect($config)->toBeArray()
        ->and($config)->toHaveKey('table_names')
        ->and($config)->toHaveKey('queue')
        ->and($config)->toHaveKey('retry')
        ->and($config)->toHaveKey('retention')
        ->and($config)->toHaveKey('subscriptions')
        ->and($config)->toHaveKey('disabled')
        ->and($config)->toHaveKey('wildcard_cache_ttl');
});

test('config table_names has all 3 keys', function (): void {
    $tables = Config::get('events.table_names');

    expect($tables)->toBeArray()
        ->and($tables)->toHaveKey('triggers')
        ->and($tables)->toHaveKey('event_logs')
        ->and($tables)->toHaveKey('subscriptions');
});

test('config subscriptions has all 4 keys', function (): void {
    $subs = Config::get('events.subscriptions');

    expect($subs)->toBeArray()
        ->and($subs)->toHaveKey('auto_generate_secret')
        ->and($subs)->toHaveKey('max_failures')
        ->and($subs)->toHaveKey('timeout')
        ->and($subs)->toHaveKey('signature_algorithm');
});

test('config retry has both keys', function (): void {
    $retry = Config::get('events.retry');

    expect($retry)->toBeArray()
        ->and($retry)->toHaveKey('tries')
        ->and($retry)->toHaveKey('backoff');
});

test('config retention has both keys', function (): void {
    $ret = Config::get('events.retention');

    expect($ret)->toBeArray()
        ->and($ret)->toHaveKey('days')
        ->and($ret)->toHaveKey('include_pending');
});

// ─── ServiceProvider bindings ─────────────────────────────────────────

test('service provider registers EventManager as singleton', function (): void {
    $first = app(EventManager::class);
    $second = app(EventManager::class);

    expect($first)->toBe($second);
});

test('service provider registers ConditionEngine as singleton', function (): void {
    $first = app(ConditionEngine::class);
    $second = app(ConditionEngine::class);

    expect($first)->toBe($second);
});

test('service provider binds ConditionEngineContract to ConditionEngine', function (): void {
    $contract = app(ConditionEngineContract::class);

    expect($contract)->toBeInstanceOf(ConditionEngine::class);
});

test('service provider registers ActionResolver as singleton', function (): void {
    $first = app(ActionResolver::class);
    $second = app(ActionResolver::class);

    expect($first)->toBe($second);
});

test('service provider registers TriggerBuilder as transient', function (): void {
    $first = app(TriggerBuilder::class);
    $second = app(TriggerBuilder::class);

    expect($first)->not->toBe($second);
});

test('service provider registers SubscriptionBuilder as transient', function (): void {
    $first = app(SubscriptionBuilder::class);
    $second = app(SubscriptionBuilder::class);

    expect($first)->not->toBe($second);
});

// ─── Facade accessor ────────────────────────────────────────────────

test('facade accessor returns EventManager class name', function (): void {
    expect(\ZeroBoiler\Events\Facades\EventManager::getFacadeAccessor())
        ->toBe(EventManager::class);
});

// ─── Strict types enforcement ────────────────────────────────────────

test('all source files declare strict_types', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');

    expect($srcFiles)->not->toBeEmpty();

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

// ─── Final class verification ───────────────────────────────────────

test('core classes are final', function (): void {
    $coreClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        DomainEvent::class,
        DispatchTriggerJob::class,
    ];

    foreach ($coreClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

// ─── readonly verification ──────────────────────────────────────────

test('DomainEvent properties are readonly', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $props = $ref->getProperties();

    foreach ($props as $prop) {
        expect($prop->isReadOnly())->toBeTrue("DomainEvent::\${$prop->name} must be readonly");
    }
});

test('EventManager constructor properties are readonly', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();

    foreach ($params as $param) {
        if ($param->isPromoted()) {
            $propName = $param->getName();
            $prop = $ref->getProperty($propName);
            expect($prop->isReadOnly())->toBeTrue("EventManager::\${$propName} must be readonly");
        }
    }
});

// ─── #[Override] verification ────────────────────────────────────────

test('ConditionEngine::matches has Override attribute', function (): void {
    $method = new ReflectionMethod(ConditionEngine::class, 'matches');
    $attrs = $method->getAttributes(\Override::class);

    expect($attrs)->toHaveCount(1);
});

test('WildcardMatcher static methods have Pure attribute', function (): void {
    $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

    foreach ($methods as $method) {
        $ref = new ReflectionMethod(WildcardMatcher::class, $method);
        $attrs = $ref->getAttributes(\Pure::class);
        expect($attrs)->toHaveCount(1, "WildcardMatcher::{$method}() must have #[Pure]");
    }
});

// ─── EventLog status constants ──────────────────────────────────────

test('EventLog has 4 status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending')
        ->and(EventLog::STATUS_DISPATCHED)->toBe('dispatched')
        ->and(EventLog::STATUS_COMPLETED)->toBe('completed')
        ->and(EventLog::STATUS_FAILED)->toBe('failed');
});

test('EventLog statuses array has 4 entries', function (): void {
    expect(EventLog::$statuses)->toBe([
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ]);
});

// ─── DomainEvent roundtrip ──────────────────────────────────────────

test('DomainEvent preserves identity through roundtrip', function (): void {
    $original = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);

    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString())
        ->and($restored->eventType)->toBe($original->eventType)
        ->and($restored->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp())
        ->and($restored->payload)->toBe($original->payload);
});

test('DomainEvent fromArray rejects empty eventType', function (): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('eventType is required');
    DomainEvent::fromArray([]);
});

// ─── EscapesWildcardLike ────────────────────────────────────────────

test('EscapesWildcardLike returns null for non-wildcard patterns', function (): void {
    $trait = new class
    {
        use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('order.placed'))->toBeNull();
});

test('EscapesWildcardLike converts * to %', function (): void {
    $trait = new class
    {
        use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('order.*'))->toBe('order.%');
});

test('EscapesWildcardLike escapes percent and underscore', function (): void {
    $trait = new class
    {
        use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('user.50%.*'))->toBe('user.50\\%.%');
    expect($trait->wildcardToLike('test_role.*'))->toBe('test\\_role.%');
});

// ─── ActionResolver error cases ──────────────────────────────────────

test('ActionResolver throws for non-existent class', function (): void {
    $resolver = app(ActionResolver::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('does not exist');
    $resolver->resolve('NonExistent\\ActionClass');
});

test('ActionResolver throws for non-Triggerable class', function (): void {
    $resolver = app(ActionResolver::class);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('must implement');
    $resolver->resolve(\stdClass::class);
});

// ─── ConditionEngine comprehensive operator matrix ────────────────────

test('ConditionEngine evaluates all 19 operators correctly', function (): void {
    $engine = app(ConditionEngine::class);

    // Comparison
    expect($engine->matches(['amount' => ['>', 50]], ['amount' => 100]))->toBeTrue();
    expect($engine->matches(['amount' => ['>=', 100]], ['amount' => 100]))->toBeTrue();
    expect($engine->matches(['amount' => ['<', 50]], ['amount' => 10]))->toBeTrue();
    expect($engine->matches(['amount' => ['<=', 50]], ['amount' => 50]))->toBeTrue();

    // Equality
    expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
    expect($engine->matches(['flag' => ['===', true]], ['flag' => true]))->toBeTrue();
    expect($engine->matches(['status' => ['!=', 'draft']], ['status' => 'active']))->toBeTrue();
    expect($engine->matches(['flag' => ['!==', false]], ['flag' => true]))->toBeTrue();

    // Array operators
    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();
    expect($engine->matches(['role' => ['not_in', ['guest']]], ['role' => 'admin']))->toBeTrue();
    expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['urgent', 'vip']]))->toBeTrue();
    expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['urgent']]))->toBeTrue();

    // Range
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 30]))->toBeTrue();

    // Null checks
    expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
    expect($engine->matches(['email' => ['not_null']], ['email' => 'a@b.com']))->toBeTrue();

    // Empty
    expect($engine->matches(['notes' => ['empty']], ['notes' => '']))->toBeTrue();
    expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'hello']))->toBeTrue();

    // String operators
    expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
    expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();
    expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();

    // AND logic
    expect($engine->matches([
        'amount' => ['>', 50],
        'status' => 'active',
    ], ['amount' => 100, 'status' => 'active']))->toBeTrue();

    expect($engine->matches([
        'amount' => ['>', 50],
        'status' => 'active',
    ], ['amount' => 100, 'status' => 'inactive']))->toBeFalse();
});

// ─── Null safety ─────────────────────────────────────────────────────

test('ConditionEngine null-safe comparison operators', function (): void {
    $engine = app(ConditionEngine::class);

    // null actual with comparison returns false
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => null]))->toBeFalse();
    expect($engine->matches(['amount' => ['<', 100]], ['amount' => null]))->toBeFalse();

    // null value in comparison returns false
    expect($engine->matches(['amount' => ['>', null]], ['amount' => 100]))->toBeFalse();
});

test('ConditionEngine handles empty conditions', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches([], ['anything' => 'here']))->toBeTrue();
});

// ─── WildcardMatcher comprehensive ────────────────────────────────────

test('WildcardMatcher handles all documented patterns', function (): void {
    // Exact match
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();

    // Single wildcard
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();

    // Cross-segment wildcard
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();

    // Catch-all
    expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();

    // Multi-wildcard
    expect(WildcardMatcher::matches('*.order.*', 'user.order.created'))->toBeTrue();
    expect(WildcardMatcher::matches('*.*.created', 'user.order.created'))->toBeTrue();
    expect(WildcardMatcher::matches('*.*.created', 'user.order.shipped'))->toBeFalse();

    // Extract
    expect(WildcardMatcher::extractWildcards('*.order.*', 'user.order.created'))
        ->toBe(['user', 'created']);

    // findMatchingPatterns
    expect(WildcardMatcher::findMatchingPatterns(['order.*', 'user.*', 'invoice.*'], 'order.placed'))
        ->toBe(['order.*']);
});

// ─── Subscription signPayload ─────────────────────────────────────────

test('Subscription signPayload returns empty for null secret', function (): void {
    $sub = Subscription::factory()->create(['secret' => null]);

    expect($sub->signPayload('test'))->toBe('');
});

test('Subscription signPayload returns empty for empty secret', function (): void {
    $sub = Subscription::factory()->create(['secret' => '']);

    expect($sub->signPayload('test'))->toBe('');
});

test('Subscription signPayload is deterministic', function (): void {
    $sub = Subscription::factory()->create(['secret' => 'test_secret']);

    $sig1 = $sub->signPayload('payload');
    $sig2 = $sub->signPayload('payload');

    expect($sig1)->toBe($sig2)
        ->and($sig1)->not->toBeEmpty();
});

// ─── Model config-driven table names ────────────────────────────────

test('Trigger model uses config-driven table name', function (): void {
    Config::set('events.table_names.triggers', 'custom_triggers');

    $trigger = new Trigger;
    expect($trigger->getTable())->toBe('custom_triggers');

    // Reset
    Config::set('events.table_names.triggers', 'triggers');
});

test('EventLog model uses config-driven table name', function (): void {
    Config::set('events.table_names.event_logs', 'custom_logs');

    $log = new EventLog;
    expect($log->getTable())->toBe('custom_logs');

    Config::set('events.table_names.event_logs', 'event_logs');
});

test('Subscription model uses config-driven table name', function (): void {
    Config::set('events.table_names.subscriptions', 'custom_subs');

    $sub = new Subscription;
    expect($sub->getTable())->toBe('custom_subs');

    Config::set('events.table_names.subscriptions', 'event_subscriptions');
});

// ─── Model UUID key types ────────────────────────────────────────────

test('Trigger model has UUID string key', function (): void {
    $trigger = Trigger::factory()->create();
    $ref = new ReflectionClass(Trigger::class);

    expect($trigger->getKeyName())->toBe('id')
        ->and($trigger->getKeyType())->toBe('string')
        ->and($trigger->incrementing)->toBeFalse()
        ->and($ref->getProperty('keyType')->isInitialized($trigger))->toBeTrue();
});

test('EventLog model has UUID string key', function (): void {
    $log = EventLog::factory()->create();

    expect($log->getKeyName())->toBe('id')
        ->and($log->getKeyType())->toBe('string')
        ->and($log->incrementing)->toBeFalse();
});

test('Subscription model has UUID string key', function (): void {
    $sub = Subscription::factory()->create();

    expect($sub->getKeyName())->toBe('id')
        ->and($sub->getKeyType())->toBe('string')
        ->and($sub->incrementing)->toBeFalse();
});

// ─── Composer.json structure ────────────────────────────────────────

test('composer.json has correct autoload PSR-4', function (): void {
    $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($json['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
    expect($json['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
});

test('composer.json has Laravel extra', function (): void {
    $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($json['extra']['laravel']['providers'])->toContain('ZeroBoiler\\Events\\EventsServiceProvider');
    expect($json['extra']['laravel']['aliases'])->toHaveKey('EventManager');
});

test('composer.json requires PHP ^8.5 and Laravel ^13.0', function (): void {
    $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($json['require']['php'])->toBe('^8.5');
    expect($json['require']['illuminate/contracts'])->toBe('^13.0');
});

// ─── File headers ────────────────────────────────────────────────────

test('all source files have license header', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');
    $header = 'This file is part of ZeroBoiler';

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain($header, "{$file} missing license header");
    }
});

// ─── Migration existence ─────────────────────────────────────────────

test('all 3 migration files exist with up/down methods', function (): void {
    $migrations = glob(__DIR__.'/../database/migrations/*.php');

    expect($migrations)->toHaveCount(3);

    foreach ($migrations as $file) {
        $contents = file_get_contents($file);
        $class = require $file;
        expect($class)->toHaveMethod('up');
        expect($class)->toHaveMethod('down');
    }
});

// ─── Factory existence ───────────────────────────────────────────────

test('all 3 factory files exist with definition method', function (): void {
    $factories = glob(__DIR__.'/../database/factories/*.php');

    expect($factories)->toHaveCount(3);

    foreach ($factories as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('public function definition()');
    }
});

// ─── Version consistency ────────────────────────────────────────────

test('composer.json version matches README badge', function (): void {
    $composerJson = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $version = $composerJson['version'];

    $readme = file_get_contents(__DIR__.'/../README.md');
    expect($readme)->toContain("version-{$version}");
});

// ─── PhpStan config ─────────────────────────────────────────────────

test('phpstan.neon.dist is level 9', function (): void {
    $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect($contents)->toContain('level: 9')
        ->and($contents)->toContain('paths:')
        ->and($contents)->toContain('- src');
});

// ─── ServiceProvider commands ────────────────────────────────────────

test('service provider provides all services for lazy loading', function (): void {
    $provider = new EventsServiceProvider(app());
    $provides = $provider->provides();

    expect($provides)->toContain(EventManager::class)
        ->and($provides)->toContain(ConditionEngine::class)
        ->and($provides)->toContain(ConditionEngineContract::class)
        ->and($provides)->toContain(ActionResolver::class)
        ->and($provides)->toContain(TriggerBuilder::class)
        ->and($provides)->toContain(SubscriptionBuilder::class);
});

// ─── Test file count accuracy ────────────────────────────────────────

test('test file count is accurate', function (): void {
    $pestFiles = glob(__DIR__.'/*.php');
    // Exclude support files
    $supportFiles = ['Pest.php', 'TestCase.php', 'helpers.php', 'TestActions.php', 'CreatesApplication.php'];
    $testFiles = array_values(array_filter($pestFiles, function (string $f) use ($supportFiles): bool {
        return ! in_array(basename($f), $supportFiles, true);
    }));

    expect($testFiles)->toHaveCount(149);
});
