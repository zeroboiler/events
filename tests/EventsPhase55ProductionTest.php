<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

beforeEach(function (): void {
    //
});

// ─── Strict Types ───────────────────────────────────────────────────────────

test('all source files have declare(strict_types=1)', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);
    expect($srcFiles)->not->toBeEmpty();

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in: {$file}");
    }
});

// ─── Final Classes ──────────────────────────────────────────────────────────

test('core classes are final', function (): void {
    $finalClasses = [
        \ZeroBoiler\Events\EventManager::class,
        ConditionEngine::class,
        WildcardMatcher::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        DomainEvent::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
        EventsServiceProvider::class,
    ];

    foreach ($finalClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} should be final");
    }
});

test('all console commands are final', function (): void {
    $commandFiles = glob(__DIR__.'/../src/Console/*Command.php');
    foreach ($commandFiles as $file) {
        require_once $file;
    }

    $commands = [
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
    ];

    foreach ($commands as $cmd) {
        $ref = new ReflectionClass($cmd);
        expect($ref->isFinal())->toBeTrue("{$cmd} should be final");
    }
});

// ─── Interface Contracts ─────────────────────────────────────────────────────

test('ConditionEngine implements ConditionEngineContract', function (): void {
    expect(ConditionEngine::class)->toImplement(ConditionEngineContract::class);
});

test('WebhookAction implements Triggerable', function (): void {
    expect(WebhookAction::class)->toImplement(\ZeroBoiler\Events\Contracts\Triggerable::class);
});

// ─── WildcardMatcher readonly + #[Pure] ─────────────────────────────────────

test('WildcardMatcher is readonly class', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    expect($ref->isReadOnly())->toBeTrue();
});

test('WildcardMatcher public methods have #[Pure] attribute', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

    foreach ($methods as $method) {
        $m = $ref->getMethod($method);
        $hasPure = false;
        foreach ($m->getAttributes() as $attr) {
            if ($attr->getName() === 'Pure') {
                $hasPure = true;
                break;
            }
        }
        expect($hasPure)->toBeTrue("WildcardMatcher::{$method} should have #[Pure]");
    }
});

// ─── DomainEvent readonly ────────────────────────────────────────────────────

test('DomainEvent readonly properties', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $props = ['eventId', 'occurredAt', 'eventType', 'payload'];

    foreach ($props as $prop) {
        $p = $ref->getProperty($prop);
        expect($p->isReadOnly())->toBeTrue("DomainEvent::\${$prop} should be readonly");
    }
});

// ─── ServiceProvider Bindings ────────────────────────────────────────────────

test('EventManager is singleton', function (): void {
    $app = app();
    $first = $app->make(\ZeroBoiler\Events\EventManager::class);
    $second = $app->make(\ZeroBoiler\Events\EventManager::class);
    expect($first)->toBe($second);
});

test('ConditionEngine is singleton', function (): void {
    $app = app();
    $first = $app->make(ConditionEngine::class);
    $second = $app->make(ConditionEngine::class);
    expect($first)->toBe($second);
});

test('ActionResolver is singleton', function (): void {
    $app = app();
    $first = $app->make(ActionResolver::class);
    $second = $app->make(ActionResolver::class);
    expect($first)->toBe($second);
});

test('ConditionEngineContract resolves to ConditionEngine', function (): void {
    $contract = app()->make(ConditionEngineContract::class);
    expect($contract)->toBeInstanceOf(ConditionEngine::class);
});

test('TriggerBuilder is transient', function (): void {
    $app = app();
    $first = $app->make(TriggerBuilder::class);
    $second = $app->make(TriggerBuilder::class);
    expect($first)->not->toBe($second);
});

test('SubscriptionBuilder is transient', function (): void {
    $app = app();
    $first = $app->make(SubscriptionBuilder::class);
    $second = $app->make(SubscriptionBuilder::class);
    expect($first)->not->toBe($second);
});

// ─── Config Completeness ────────────────────────────────────────────────────

test('config has all required top-level keys', function (): void {
    $config = config('events');
    $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'wildcard_cache_ttl'];

    foreach ($requiredKeys as $key) {
        expect($config)->toHaveKey($key);
    }
});

test('table_names config has all 3 tables', function (): void {
    $tables = config('events.table_names');
    expect($tables)->toHaveKey('triggers');
    expect($tables)->toHaveKey('event_logs');
    expect($tables)->toHaveKey('subscriptions');
    expect($tables['triggers'])->toBeString();
    expect($tables['event_logs'])->toBeString();
    expect($tables['subscriptions'])->toBeString();
});

test('retry config has tries and backoff', function (): void {
    $retry = config('events.retry');
    expect($retry)->toHaveKey('tries');
    expect($retry)->toHaveKey('backoff');
});

test('subscriptions config has all keys', function (): void {
    $subs = config('events.subscriptions');
    $requiredKeys = ['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm'];

    foreach ($requiredKeys as $key) {
        expect($subs)->toHaveKey($key);
    }
});

test('queue config has connection and queue', function (): void {
    $queue = config('events.queue');
    expect($queue)->toHaveKey('connection');
    expect($queue)->toHaveKey('queue');
});

test('retention config has days and include_pending', function (): void {
    $retention = config('events.retention');
    expect($retention)->toHaveKey('days');
    expect($retention)->toHaveKey('include_pending');
});

// ─── EventLog Status Constants ───────────────────────────────────────────────

test('EventLog has all 4 status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
});

test('EventLog $statuses array contains all constants', function (): void {
    $expected = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];
    expect(EventLog::$statuses)->toBe($expected);
});

// ─── Facade Accessor ────────────────────────────────────────────────────────

test('Facade accessor returns EventManager class name', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    $method = $ref->getMethod('getFacadeAccessor');
    $method->setAccessible(true); // @phpstan-ignore method.deprecated — needed for protected method
    expect($method->invoke(null))->toBe(\ZeroBoiler\Events\EventManager::class);
});

// ─── Model Config-Driven Table Names ────────────────────────────────────────

test('Trigger model reads table from config', function (): void {
    $model = new Trigger;
    expect($model->getTable())->toBe(config('events.table_names.triggers', 'triggers'));
});

test('EventLog model reads table from config', function (): void {
    $model = new EventLog;
    expect($model->getTable())->toBe(config('events.table_names.event_logs', 'event_logs'));
});

test('Subscription model reads table from config', function (): void {
    $model = new Subscription;
    expect($model->getTable())->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
});

// ─── Model Key Types ─────────────────────────────────────────────────────────

test('all 3 models use UUID string keys', function (): void {
    foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
        $ref = new ReflectionClass($model);
        $prop = $ref->getProperty('keyType');
        expect($prop->getValue(new $model))->toBe('string');
    }
});

test('all 3 models are non-incrementing', function (): void {
    foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
        $ref = new ReflectionClass($model);
        $prop = $ref->getProperty('incrementing');
        expect($prop->getValue(new $model))->toBeFalse();
    }
});

// ─── Version Consistency ────────────────────────────────────────────────────

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');
    $version = $composer['version'];

    expect($readme)->toContain("version-{$version}");
    expect($readme)->toContain("PHP-8.5%2B");
    expect($readme)->toContain("Laravel-13.x");
    expect($readme)->toContain("PHPStan-Level%209");
});

// ─── Migrations Exist ───────────────────────────────────────────────────────

test('all 3 migration files exist', function (): void {
    $migrationDir = __DIR__.'/../database/migrations';
    expect(file_exists($migrationDir.'/2024_01_01_000001_create_triggers_table.php'))->toBeTrue();
    expect(file_exists($migrationDir.'/2024_01_01_000002_create_event_logs_table.php'))->toBeTrue();
    expect(file_exists($migrationDir.'/2025_06_28_000001_create_event_subscriptions_table.php'))->toBeTrue();
});

// ─── CHANGELOG.md ───────────────────────────────────────────────────────────

test('CHANGELOG.md exists and has latest version', function (): void {
    $changelog = file_get_contents(__DIR__.'/../CHANGELOG.md');
    expect($changelog)->toContain('[1.96.0]');
});

// ─── DomainEvent Roundtrip ──────────────────────────────────────────────────

test('DomainEvent roundtrip preserves all fields', function (): void {
    $original = DomainEvent::occur('test.event', ['key' => 'value']);
    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
    expect($restored->occurredAt->format('U'))->toBe($original->occurredAt->format('U'));
});

// ─── EscapesWildcardLike SQL Escaping ───────────────────────────────────────

test('EscapesWildcardLike returns null for non-wildcard', function (): void {
    $trait = new class {
        use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('order.placed'))->toBeNull();
});

test('EscapesWildcardLike converts asterisk to percent', function (): void {
    $trait = new class {
        use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('order.*'))->toBe('order.%');
    expect($trait->wildcardToLike('order.**'))->toBe('order.%');
});

test('EscapesWildcardLike escapes SQL special chars', function (): void {
    $trait = new class {
        use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
    };

    // Percent and underscore should be escaped
    expect($trait->wildcardToLike('order.%*'))->toBe('order.\%%');
    expect($trait->wildcardToLike('order._*'))->toBe('order.\_%');
});

// ─── ActionResolver Errors ───────────────────────────────────────────────────

test('ActionResolver throws on non-existent class', function (): void {
    $resolver = new ActionResolver(app());

    expect(fn (): mixed => $resolver->resolve('NonExistentClass'))
        ->toThrow(\InvalidArgumentException::class, 'does not exist');
});

test('ActionResolver throws on non-Triggerable class', function (): void {
    $resolver = new ActionResolver(app());

    expect(fn (): mixed => $resolver->resolve(\stdClass::class))
        ->toThrow(\InvalidArgumentException::class, 'must implement');
});

// ─── Composer Autoload ───────────────────────────────────────────────────────

test('composer.json has correct PSR-4 autoload', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('ZeroBoiler\\Events\\');
    expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
    expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
    expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Database\\Factories\\'])->toBe('database/factories/');
});

test('composer.json has Laravel extra section', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['extra']['laravel']['providers'])->toContain(EventsServiceProvider::class);
    expect($composer['extra']['laravel']['aliases'])->toHaveKey('EventManager');
});

// ─── License Headers ─────────────────────────────────────────────────────────

test('all source files have license header', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        // First 6 lines should contain the license comment
        $header = implode("\n", array_slice($lines, 0, 6));
        expect($header)->toContain('ZeroBoiler', "Missing license header in: {$file}");
    }
});

// ─── WildcardMatcher Comprehensive ───────────────────────────────────────────

test('WildcardMatcher: exact match', function (): void {
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
});

test('WildcardMatcher: single-segment wildcard', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
});

test('WildcardMatcher: cross-segment wildcard', function (): void {
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
});

test('WildcardMatcher: catch-all', function (): void {
    expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
});

test('WildcardMatcher: empty pattern does not match non-empty', function (): void {
    expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
});

// ─── ConditionEngine Full Operator Matrix ──────────────────────────────────────

test('ConditionEngine: all operators work', function (): void {
    $engine = new ConditionEngine;

    // Comparison
    expect($engine->matches(['val' => ['>', 5]], ['val' => 10]))->toBeTrue();
    expect($engine->matches(['val' => ['<', 5]], ['val' => 3]))->toBeTrue();
    expect($engine->matches(['val' => ['>=', 5]], ['val' => 5]))->toBeTrue();
    expect($engine->matches(['val' => ['<=', 5]], ['val' => 5]))->toBeTrue();

    // Equality
    expect($engine->matches(['val' => 'test'], ['val' => 'test']))->toBeTrue();
    expect($engine->matches(['val' => ['===', true]], ['val' => true]))->toBeTrue();
    expect($engine->matches(['val' => ['!=', 'other']], ['val' => 'test']))->toBeTrue();
    expect($engine->matches(['val' => ['!==', 'test']], ['val' => 'other']))->toBeTrue();

    // Array operators
    expect($engine->matches(['role' => ['in', ['a', 'b']]], ['role' => 'a']))->toBeTrue();
    expect($engine->matches(['role' => ['not_in', ['a', 'b']]], ['role' => 'c']))->toBeTrue();

    // String operators
    expect($engine->matches(['name' => ['contains', 'test']], ['name' => 'my test value']))->toBeTrue();
    expect($engine->matches(['name' => ['starts_with', 'my']], ['name' => 'my test']))->toBeTrue();
    expect($engine->matches(['name' => ['ends_with', 'st']], ['name' => 'test']))->toBeTrue();

    // Null operators
    expect($engine->matches(['val' => ['null']], ['val' => null]))->toBeTrue();
    expect($engine->matches(['val' => ['not_null']], ['val' => 'x']))->toBeTrue();

    // Between
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 30]))->toBeTrue();

    // Regex
    expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();

    // Negations
    expect($engine->matches(['tag' => ['not_contains', 'spam']], ['tag' => 'important']))->toBeTrue();
    expect($engine->matches(['note' => ['not_empty']], ['note' => 'text']))->toBeTrue();

    // Dot notation
    expect($engine->matches(['user.role' => 'admin'], ['user' => ['role' => 'admin']]))->toBeTrue();

    // AND logic
    expect($engine->matches(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2]))->toBeTrue();
    expect($engine->matches(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 99]))->toBeFalse();
});

// ─── Subscription Signing Edge Cases ────────────────────────────────────────

test('Subscription signPayload with null secret returns empty', function (): void {
    $sub = new Subscription(['secret' => null]);
    expect($sub->signPayload('test'))->toBe('');
});

test('Subscription signPayload with empty secret returns empty', function (): void {
    $sub = new Subscription(['secret' => '']);
    expect($sub->signPayload('test'))->toBe('');
});

test('Subscription signPayload is deterministic', function (): void {
    $sub = new Subscription(['secret' => 'test_secret']);
    $sig1 = $sub->signPayload('payload');
    $sig2 = $sub->signPayload('payload');
    expect($sig1)->toBe($sig2);
});

// ─── phpstan.neon.dist ─────────────────────────────────────────────────────

test('phpstan.neon.dist exists with level 9', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('level: 9');
    expect($content)->toContain('paths:');
    expect($content)->toContain('- src');
});

// ─── .gitignore ──────────────────────────────────────────────────────────────

test('.gitignore has vendor and phpstan files', function (): void {
    $gitignore = file_get_contents(__DIR__.'/../.gitignore');
    expect($gitignore)->toContain('/vendor/');
    expect($gitignore)->toContain('phpstan.neon');
});

// ─── Command Prefix ─────────────────────────────────────────────────────────

test('all console commands have zeroboiler:events: prefix', function (): void {
    $commandFiles = glob(__DIR__.'/../src/Console/*Command.php');

    foreach ($commandFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('zeroboiler:events:', "Missing command prefix in: {$file}");
    }
});
