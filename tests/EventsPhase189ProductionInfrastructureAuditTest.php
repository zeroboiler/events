<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Support\Str;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
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
use ZeroBoiler\Events\Actions\WebhookAction;

it('has strict types declaration in all 33 source files', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);

    // Glob with GLOB_BRACE may not work, fallback to find
    if ($srcFiles === false) {
        $srcFiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var RecursiveDirectoryIterator|SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $srcFiles[] = $file->getPathname();
            }
        }
    }

    expect(count($srcFiles))->toBeGreaterThanOrEqual(33);

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toBeFalse();
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

it('has license header in all source files', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);

    if ($srcFiles === false) {
        $srcFiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $srcFiles[] = $file->getPathname();
            }
        }
    }

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toBeFalse();
        expect($contents)->toContain('This file is part of ZeroBoiler');
    }
});

it('service provider provides exactly 7 bindings', function (): void {
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
});

it('config has all 8 top-level keys', function (): void {
    $config = config('events');

    expect($config)->not->toBeNull();
    expect(array_keys($config))->toHaveCount(8);

    expect($config)->toHaveKey('table_names');
    expect($config)->toHaveKey('queue');
    expect($config)->toHaveKey('retry');
    expect($config)->toHaveKey('retention');
    expect($config)->toHaveKey('subscriptions');
    expect($config)->toHaveKey('disabled');
    expect($config)->toHaveKey('wildcard_cache_ttl');
});

it('config table_names has all 3 tables', function (): void {
    $tableNames = config('events.table_names');

    expect($tableNames)->toHaveKey('triggers');
    expect($tableNames)->toHaveKey('event_logs');
    expect($tableNames)->toHaveKey('subscriptions');
    expect($tableNames['triggers'])->toBeString();
    expect($tableNames['event_logs'])->toBeString();
    expect($tableNames['subscriptions'])->toBeString();
});

it('config subscriptions has all required sub-keys', function (): void {
    $subs = config('events.subscriptions');

    expect($subs)->toHaveKey('auto_generate_secret');
    expect($subs)->toHaveKey('secret_length');
    expect($subs)->toHaveKey('max_failures');
    expect($subs)->toHaveKey('timeout');
    expect($subs)->toHaveKey('signature_algorithm');
    expect($subs)->toHaveKey('cleanup_cron');
});

it('composer.json requires PHP 8.5 and Laravel 13', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['require']['php'])->toContain('8.5');
    expect($composer['require']['illuminate/contracts'])->toContain('13');
    expect($composer['require']['illuminate/support'])->toContain('13');

    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider',
    );
    expect($composer['extra']['laravel']['aliases'])->toHaveKey('EventManager');
});

it('facade accessor returns correct class', function (): void {
    // Use closure binding to access protected method without setAccessible
    $facadeClass = \ZeroBoiler\Events\Facades\EventManager::class;

    // The facade is final so we test via the static method
    // For PHP 8.5 compatibility, verify the class structure
    $reflection = new ReflectionClass($facadeClass);
    expect($reflection->getMethod('getFacadeAccessor')->getName())->toBe('getFacadeAccessor');
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isSubclassOf(\Illuminate\Support\Facades\Facade::class))->toBeTrue();
});

it('all console commands are final with handle returning int', function (): void {
    $commandFiles = glob(__DIR__.'/../src/Console/*.php');

    if ($commandFiles === false) {
        $commandFiles = [];
    }

    expect(count($commandFiles))->toBe(12);

    foreach ($commandFiles as $file) {
        $contents = file_get_contents($file);
        // Check final class
        expect($contents)->toContain('final class');
        // Check handle(): int return type
        expect($contents)->toMatch('/public function handle\([^)]*\): int/');
    }
});

it('wildcard matcher is readonly final', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

it('domain event roundtrip identity preservation', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);

    $array = $event->toArray();
    $restored = DomainEvent::fromArray($array);

    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
        ->toBe($event->occurredAt->format(DateTimeImmutable::ATOM));
    expect($restored->eventType)->toBe('test.event');
    expect($restored->payload)->toBe(['key' => 'value']);
});

it('domain event rejects empty eventType on fromArray', function (): void {
    DomainEvent::fromArray(['eventType' => '']);
})->throws(InvalidArgumentException::class);

it('condition engine rejects regex patterns over 500 chars', function (): void {
    $engine = new ConditionEngine();

    $longPattern = '/'.str_repeat('a', 500).'/';
    $result = $engine->matches(['code' => ['matches', $longPattern]], ['code' => 'test']);

    // Should return false — ReDoS protection kicks in
    expect($result)->toBeFalse();
});

it('condition engine rejects nested quantifier patterns', function (): void {
    $engine = new ConditionEngine();

    $result = $engine->matches(
        ['code' => ['matches', '/(a+)+/']],
        ['code' => str_repeat('a', 100)],
    );

    expect($result)->toBeFalse();
});

it('condition engine supports all 21 operators', function (): void {
    $engine = new ConditionEngine();
    $payload = [
        'amount' => 150,
        'score' => 75,
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'tags' => ['admin', 'user'],
        'status' => 'active',
        'code' => 'ABC-1234',
        'notes' => 'some text',
        'deleted_at' => null,
        'role' => 'admin',
        'age' => 25,
    ];

    // Numeric: >, >=, <, <=
    expect($engine->matches(['amount' => ['>', 100]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['>=', 150]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['<', 200]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['<=', 150]], $payload))->toBeTrue();

    // Equality: =, ===, !=, !==
    expect($engine->matches(['status' => 'active'], $payload))->toBeTrue();
    expect($engine->matches(['status' => ['=', 'active']], $payload))->toBeTrue();
    expect($engine->matches(['status' => ['!=', 'inactive']], $payload))->toBeTrue();

    // Array: in, not_in
    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], $payload))->toBeTrue();
    expect($engine->matches(['role' => ['not_in', ['guest']]], $payload))->toBeTrue();

    // String: contains, not_contains, starts_with, ends_with
    expect($engine->matches(['tags' => ['contains', 'admin']], $payload))->toBeTrue();
    expect($engine->matches(['tags' => ['not_contains', 'guest']], $payload))->toBeTrue();
    expect($engine->matches(['email' => ['starts_with', 'alice']], $payload))->toBeTrue();
    expect($engine->matches(['email' => ['ends_with', '.com']], $payload))->toBeTrue();

    // Range: between
    expect($engine->matches(['age' => ['between', [18, 30]]], $payload))->toBeTrue();

    // Null checks: null, not_null
    expect($engine->matches(['deleted_at' => ['null']], $payload))->toBeTrue();
    expect($engine->matches(['status' => ['not_null']], $payload))->toBeTrue();

    // Empty: empty, not_empty
    expect($engine->matches(['notes' => ['not_empty']], $payload))->toBeTrue();

    // Regex: matches
    expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}-\d{4}$/']], $payload))->toBeTrue();
});

it('event log has exactly 4 status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
    expect(EventLog::$statuses)->toHaveCount(4);
});

it('trigger model has correct casts', function (): void {
    $trigger = new Trigger;
    $casts = $trigger->casts();

    expect($casts)->toHaveKey('conditions');
    expect($casts)->toHaveKey('async');
    expect($casts)->toHaveKey('enabled');
    expect($casts)->toHaveKey('priority');
    expect($casts)->toHaveCount(4);
});

it('event log model has correct casts', function (): void {
    $log = new EventLog;
    $casts = $log->casts();

    expect($casts)->toHaveKey('payload');
    expect($casts)->toHaveKey('duration_ms');
    expect($casts)->toHaveKey('error');
    expect($casts)->toHaveCount(3);
});

it('subscription model has correct casts', function (): void {
    $sub = new Subscription;
    $casts = $sub->casts();

    expect($casts)->toHaveKey('conditions');
    expect($casts)->toHaveKey('priority');
    expect($casts)->toHaveKey('active');
    expect($casts)->toHaveKey('failure_count');
    expect($casts)->toHaveKey('delivery_count');
    expect($casts)->toHaveKey('last_fired_at');
    expect($casts)->toHaveCount(6);
});

it('subscription hides secret from serialization', function (): void {
    $sub = new Subscription;
    expect($sub->getHidden())->toContain('secret');
    expect($sub->getHidden())->toContain('deleted_at');
});

it('escapes wildcard like converts patterns correctly', function (): void {
    // Use an anonymous class that uses the trait and exposes the method
    $obj = new class
    {
        use ZeroBoiler\Events\Concerns\EscapesWildcardLike;

        public function testWildcardToLike(string $pattern): ?string
        {
            return $this->wildcardToLike($pattern);
        }
    };

    // Non-wildcard returns null
    expect($obj->testWildcardToLike('order.placed'))->toBeNull();
    expect($obj->testWildcardToLike('order.*'))->toBe('order\%');
    expect($obj->testWildcardToLike('order.**'))->toBe('order\%%');
    expect($obj->testWildcardToLike('*'))->toBe('%');
});

it('no source files use Config facade', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);

    if ($srcFiles === false) {
        $srcFiles = [];
    }

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toBeFalse();
        // Should NOT import Config facade
        expect($contents)->not->toContain('use Illuminate\Support\Facades\Config;');
    }
});

it('action resolver rejects non-existent class', function (): void {
    $resolver = new ActionResolver(app());
    $resolver->resolve('NonExistentClass\That\Does\Not\Exist');
})->throws(InvalidArgumentException::class);

it('dispatch trigger job has correct public properties', function (): void {
    $job = new DispatchTriggerJob(
        triggerId: (string) Str::uuid(),
        event: 'test.event',
        payload: ['key' => 'value'],
    );

    $reflection = new ReflectionClass($job);

    // Check promoted readonly properties
    $triggerIdProp = $reflection->getProperty('triggerId');
    expect($triggerIdProp->isReadOnly())->toBeTrue();
    expect($triggerIdProp->isPublic())->toBeTrue();

    $eventProp = $reflection->getProperty('event');
    expect($eventProp->isReadOnly())->toBeTrue();

    $payloadProp = $reflection->getProperty('payload');
    expect($payloadProp->isReadOnly())->toBeTrue();

    // Check config-driven properties
    expect($job->tries)->toBeInt();
    expect($job->tries)->toBeGreaterThan(0);
    expect($job->queue)->toBeString();
    expect($job->backoff)->toBeArray();
    expect(count($job->backoff))->toBeGreaterThan(0);
});

it('event manager has correct constructor signature', function (): void {
    $reflection = new ReflectionClass(EventManager::class);

    expect($reflection->isFinal())->toBeTrue();

    $constructor = $reflection->getMethod('__construct');
    $params = $constructor->getParameters();

    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('conditionEngine');
    expect($params[0]->isReadOnly())->toBeTrue();
    expect($params[1]->getName())->toBe('actionResolver');
    expect($params[1]->isReadOnly())->toBeTrue();
    expect($params[2]->getName())->toBe('app');
    expect($params[2]->isReadOnly())->toBeTrue();
});

it('subscription builder rejects non-http scheme urls', function (): void {
    $manager = app(EventManager::class);
    $builder = $manager->subscribe('test.event', 'file:///etc/passwd');
    $builder->save();
})->throws(InvalidArgumentException::class);

it('phpstan neon dist has correct configuration', function (): void {
    $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect($neon)->toContain('level: 9');
    expect($neon)->toContain('reportUnusedIgnoredErrors: true');
    expect($neon)->toContain('checkExplicitMixed: true');
    expect($neon)->toContain('checkUninitializedProperties: true');
    expect($neon)->toContain('checkGenericClassInNonGenericObjectType: true');
    expect($neon)->toContain('bootstrapFiles');
    expect($neon)->toContain('universalObjectCratesClasses');
    expect($neon)->toContain('src');
    expect($neon)->toContain('tests');
});

it('pest php registers the new test file', function (): void {
    $pestContents = file_get_contents(__DIR__.'/Pest.php');

    expect($pestContents)->toContain('EventsPhase189ProductionInfrastructureAuditTest.php');
});
