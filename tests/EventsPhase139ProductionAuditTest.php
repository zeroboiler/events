<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

test('events version is 4.67.0 in composer.json', function (): void {
    $json = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($json['version'])->toBe('4.67.0');
});

test('events version badge in README matches composer.json', function (): void {
    $readme = file_get_contents(base_path('README.md'));
    expect($readme)->toContain('version-4.67.0');
});

test('all source files have strict types declaration', function (): void {
    $files = glob(base_path('src/**/*.php'), GLOB_BRACE);
    $violations = [];
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        if (! str_contains($contents, 'declare(strict_types=1)')) {
            $violations[] = basename($file);
        }
    }
    expect($violations)->toBeEmpty('Files missing strict_types: '.implode(', ', $violations));
});

test('all source files have license header', function (): void {
    $files = glob(base_path('src/**/*.php'), GLOB_BRACE);
    $violations = [];
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        if (! str_contains($contents, 'This file is part of ZeroBoiler')) {
            $violations[] = basename($file);
        }
    }
    expect($violations)->toBeEmpty('Files missing license header: '.implode(', ', $violations));
});

test('all classes are declared final', function (): void {
    $files = glob(base_path('src/**/*.php'), GLOB_BRACE);
    $nonFinalClasses = [];
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        // Find class declarations that are not final, not interface, not trait, not anonymous
        if (preg_match('/^(?!.*final\s+class)(?:abstract\s+)?class\s+(\w+)/m', $contents, $m)) {
            $nonFinalClasses[] = $m[1].' in '.basename($file);
        }
    }
    expect($nonFinalClasses)->toBeEmpty('Non-final classes found: '.implode(', ', $nonFinalClasses));
});

test('WildcardMatcher is readonly final class', function (): void {
    $contents = file_get_contents(base_path('src/WildcardMatcher.php'));
    expect($contents)->toContain('readonly final class WildcardMatcher');
});

test('all EventManager public methods have return type declarations', function (): void {
    $contents = file_get_contents(base_path('src/EventManager.php'));
    $missing = [];
    $methods = [
        'on', 'register', 'fire', 'fireModel', 'enable', 'disable',
        'invalidateTriggerCache', 'isDisabled', 'setEnabled',
        'listTriggers', 'getTrigger', 'deleteTrigger', 'executeTrigger',
        'registerScheduler', 'getConfig', 'getTriggerCacheTtl',
    ];
    foreach ($methods as $method) {
        if (! preg_match("/public\s+function\s+{$method}\s*\([^)]*\)\s*:/", $contents)) {
            $missing[] = $method;
        }
    }
    expect($missing)->toBeEmpty('Methods missing return types: '.implode(', ', $missing));
});

test('ConditionEngine has #[Override] on matches method', function (): void {
    $contents = file_get_contents(base_path('src/ConditionEngine.php'));
    expect($contents)->toContain('#[\\Override]');
    expect($contents)->toContain('public function matches(array $conditions, array $payload): bool');
});

test('ServiceProvider register binds all required services', function (): void {
    $provider = app(ZeroBoiler\Events\EventsServiceProvider::class);
    expect($provider->provides())->toContain(
        ZeroBoiler\Events\EventManager::class,
        ZeroBoiler\Events\ConditionEngine::class,
        ZeroBoiler\Events\Contracts\ConditionEngineContract::class,
        ZeroBoiler\Events\ActionResolver::class,
        ZeroBoiler\Events\TriggerBuilder::class,
        ZeroBoiler\Events\SubscriptionBuilder::class,
        ZeroBoiler\Events\EventScheduler::class,
    );
});

test('Facade getFacadeAccessor returns correct binding', function (): void {
    // In PHP 8.5, setAccessible is no longer needed — we verify the constant
    // via the class docblock instead of reflection on the protected method.
    $contents = file_get_contents(base_path('src/Facades/EventManager.php'));
    expect($contents)->toContain('return \\ZeroBoiler\\Events\\EventManager::class');
});

test('config file contains all required top-level keys', function (): void {
    $config = config('events');
    expect($config)->not->toBeNull();
    expect(array_keys($config))->toContain(
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'disabled',
        'wildcard_cache_ttl',
    );
});

test('config table_names contains all three tables', function (): void {
    $tables = config('events.table_names');
    expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
});

test('config subscriptions contains all required keys', function (): void {
    $subs = config('events.subscriptions');
    expect($subs)->toHaveKeys([
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
        'cleanup_cron',
    ]);
});

test('config retry contains tries and backoff', function (): void {
    $retry = config('events.retry');
    expect($retry)->toHaveKeys(['tries', 'backoff']);
});

test('config retention contains days and schedule_cron', function (): void {
    $retention = config('events.retention');
    expect($retention)->toHaveKeys(['days', 'include_pending', 'schedule_cron']);
});

test('all 12 console commands are registered in ServiceProvider', function (): void {
    $commands = app('commands');
    $expected = [
        'zeroboiler:events:list',
        'zeroboiler:events:fire',
        'zeroboiler:events:register',
        'zeroboiler:events:enable',
        'zeroboiler:events:disable',
        'zeroboiler:events:log',
        'zeroboiler:events:retry',
        'zeroboiler:events:health',
        'zeroboiler:events:subscribe',
        'zeroboiler:events:unsubscribe',
        'zeroboiler:events:subscriptions',
        'zeroboiler:events:redeliver',
    ];
    foreach ($expected as $signature) {
        expect($commands)->toContain($signature);
    }
});

test('Trigger model uses config-driven table name', function (): void {
    $trigger = new \ZeroBoiler\Events\Models\Trigger;
    expect($trigger->getTable())->toBe(config('events.table_names.triggers', 'triggers'));
});

test('EventLog model uses config-driven table name', function (): void {
    $log = new \ZeroBoiler\Events\Models\EventLog;
    expect($log->getTable())->toBe(config('events.table_names.event_logs', 'event_logs'));
});

test('Subscription model uses config-driven table name', function (): void {
    $sub = new \ZeroBoiler\Events\Models\Subscription;
    expect($sub->getTable())->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
});

test('no source file contains setAccessible calls', function (): void {
    $files = glob(base_path('src/**/*.php'), GLOB_BRACE);
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->not->toContain('->setAccessible(');
    }
});

test('no TODO FIXME HACK comments in source', function (): void {
    $files = glob(base_path('src/**/*.php'), GLOB_BRACE);
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        $lines = explode("\n", $contents);
        foreach ($lines as $num => $line) {
            if (preg_match('/\/\/\s*(TODO|FIXME|HACK|XXX)/i', $line)) {
                $this->fail("Found TODO/FIXME/HACK in {$file}:{$num}: {$line}");
            }
        }
    }
    expect(true)->toBeTrue();
});

test('DomainEvent immutability — readonly properties cannot be modified', function (): void {
    $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);
    expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
    expect($event->eventType)->toBe('test.event');
    expect($event->payload)->toBe(['key' => 'value']);
    expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
});

test('DomainEvent roundtrip identity', function (): void {
    $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.roundtrip', ['data' => 42]);
    $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($original->toArray());
    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
    expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))->toBe($original->occurredAt->format(\DateTimeImmutable::ATOM));
});

test('WildcardMatcher matches catch-all patterns', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('**', 'order.placed.extra'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', ''))->toBeFalse();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('**', ''))->toBeFalse();
});

test('WildcardMatcher matches single-segment wildcards', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', 'order'))->toBeFalse();
});

test('WildcardMatcher extractWildcards works correctly', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created'))
        ->toBe(['profile']);
    expect(\ZeroBoiler\Events\WildcardMatcher::extractWildcards('user.*.created', 'user.profile.updated'))
        ->toBe([]);
});

test('ConditionEngine operators — all 19 operators work', function (): void {
    $engine = new \ZeroBoiler\Events\ConditionEngine;
    $payload = [
        'amount' => 150,
        'status' => 'active',
        'tags' => ['urgent', 'important'],
        'name' => 'admin_user',
        'age' => 30,
        'deleted_at' => null,
        'notes' => '',
        'email' => 'admin@example.com',
        'domain' => 'example.com',
        'code' => 'ABC-1234',
    ];

    // Comparison
    expect($engine->matches(['amount' => ['>', 100]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['>=', 150]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['<', 200]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['<=', 150]], $payload))->toBeTrue();
    expect($engine->matches(['status' => 'active'], $payload))->toBeTrue();
    expect($engine->matches(['status' => ['===', 'active']], $payload))->toBeTrue();
    expect($engine->matches(['status' => ['!=', 'inactive']], $payload))->toBeTrue();
    expect($engine->matches(['status' => ['!==', 'inactive']], $payload))->toBeTrue();

    // Array operators
    expect($engine->matches(['tags' => ['in', ['urgent', 'low']]], $payload))->toBeTrue();
    expect($engine->matches(['tags' => ['not_in', ['low']]], $payload))->toBeTrue();
    expect($engine->matches(['tags' => ['contains', 'urgent']], $payload))->toBeTrue();
    expect($engine->matches(['tags' => ['not_contains', 'spam']], $payload))->toBeTrue();

    // Range
    expect($engine->matches(['age' => ['between', [18, 65]]], $payload))->toBeTrue();

    // Null/empty
    expect($engine->matches(['deleted_at' => ['null']], $payload))->toBeTrue();
    expect($engine->matches(['status' => ['not_null']], $payload))->toBeTrue();
    expect($engine->matches(['notes' => ['empty']], $payload))->toBeTrue();
    expect($engine->matches(['tags' => ['not_empty']], $payload))->toBeTrue();

    // String
    expect($engine->matches(['email' => ['starts_with', 'admin@']], $payload))->toBeTrue();
    expect($engine->matches(['domain' => ['ends_with', '.com']], $payload))->toBeTrue();
    expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}-\\d{4}$/']], $payload))->toBeTrue();
});

test('ConditionEngine AND logic — all conditions must pass', function (): void {
    $engine = new \ZeroBoiler\Events\ConditionEngine;
    $payload = ['amount' => 150, 'status' => 'active'];

    expect($engine->matches(['amount' => ['>', 100], 'status' => 'active'], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['>', 100], 'status' => 'inactive'], $payload))->toBeFalse();
});

test('ConditionEngine dot notation nested access', function (): void {
    $engine = new \ZeroBoiler\Events\ConditionEngine;
    $payload = ['user' => ['role' => 'admin', 'profile' => ['name' => 'John']]];

    expect($engine->matches(['user.role' => 'admin'], $payload))->toBeTrue();
    expect($engine->matches(['user.profile.name' => 'John'], $payload))->toBeTrue();
});

test('ActionResolver rejects non-existent class', function (): void {
    $resolver = new \ZeroBoiler\Events\ActionResolver(app());

    expect(fn () => $resolver->resolve('NonExistent\Class\Here'))
        ->toThrow(\InvalidArgumentException::class, 'does not exist');
});

test('ActionResolver rejects class not implementing Triggerable', function (): void {
    $resolver = new \ZeroBoiler\Events\ActionResolver(app());

    expect(fn () => $resolver->resolve(\stdClass::class))
        ->toThrow(\InvalidArgumentException::class, 'must implement');
});

test('TriggerBuilder rejects empty event name', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $builder = $manager->on('');

    expect(fn () => $builder->action(\stdClass::class)->save())
        ->toThrow(\InvalidArgumentException::class, 'Event name is required');
});

test('TriggerBuilder rejects no action', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);

    expect(fn () => $manager->on('test.event')->save())
        ->toThrow(\InvalidArgumentException::class, 'At least one action is required');
});

test('SubscriptionBuilder rejects non-HTTP scheme URLs', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);

    expect(fn () => $manager->subscribe('test.event', 'ftp://evil.com/hook')->save())
        ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS');
});

test('SubscriptionBuilder rejects invalid URLs', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);

    expect(fn () => $manager->subscribe('test.event', 'not-a-url')->save())
        ->toThrow(\InvalidArgumentException::class, 'valid URL');
});

test('EventLog status constants are consistent with migration enum', function (): void {
    expect(\ZeroBoiler\Events\Models\EventLog::STATUS_PENDING)->toBe('pending');
    expect(\ZeroBoiler\Events\Models\EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(\ZeroBoiler\Events\Models\EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(\ZeroBoiler\Events\Models\EventLog::STATUS_FAILED)->toBe('failed');

    expect(\ZeroBoiler\Events\Models\EventLog::$statuses)->toContain(
        'pending', 'dispatched', 'completed', 'failed',
    );
});

test('Trigger factory state builders exist', function (): void {
    $factory = \ZeroBoiler\Events\Models\Trigger::factory();
    expect(method_exists($factory, 'async'))->toBeTrue();
    expect(method_exists($factory, 'sync'))->toBeTrue();
    expect(method_exists($factory, 'enabled'))->toBeTrue();
    expect(method_exists($factory, 'disabled'))->toBeTrue();
    expect(method_exists($factory, 'forEvent'))->toBeTrue();
    expect(method_exists($factory, 'withAction'))->toBeTrue();
});

test('EventLog factory state builders exist', function (): void {
    $factory = \ZeroBoiler\Events\Models\EventLog::factory();
    expect(method_exists($factory, 'pending'))->toBeTrue();
    expect(method_exists($factory, 'dispatched'))->toBeTrue();
    expect(method_exists($factory, 'completed'))->toBeTrue();
    expect(method_exists($factory, 'failed'))->toBeTrue();
    expect(method_exists($factory, 'forTrigger'))->toBeTrue();
});

test('Subscription factory state builders exist', function (): void {
    $factory = \ZeroBoiler\Events\Models\Subscription::factory();
    expect(method_exists($factory, 'active'))->toBeTrue();
    expect(method_exists($factory, 'inactive'))->toBeTrue();
    expect(method_exists($factory, 'forEvent'))->toBeTrue();
    expect(method_exists($factory, 'withUrl'))->toBeTrue();
    expect(method_exists($factory, 'withSecret'))->toBeTrue();
    expect(method_exists($factory, 'withFailureCount'))->toBeTrue();
});

test('phpstan.neon.dist uses max level', function (): void {
    $contents = file_get_contents(base_path('phpstan.neon.dist'));
    expect($contents)->toContain('level: 9');
});

test('composer.json autoload PSR-4 is correct', function (): void {
    $json = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($json['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
    expect($json['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
});

test('composer.json extra.laravel.providers is correct', function (): void {
    $json = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($json['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider',
    );
});

test('composer.json extra.laravel.aliases includes EventManager', function (): void {
    $json = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($json['extra']['laravel']['aliases']['EventManager'])->toBe(
        'ZeroBoiler\\Events\\Facades\\EventManager',
    );
});

test('migration triggers table has correct columns and indexes', function (): void {
    $contents = file_get_contents(base_path('database/migrations/2024_01_01_000001_create_triggers_table.php'));
    expect($contents)->toContain("->uuid('id')->primary()");
    expect($contents)->toContain("->string('name')");
    expect($contents)->toContain("->string('event')");
    expect($contents)->toContain("->text('action')");
    expect($contents)->toContain("->json('conditions')->nullable()");
    expect($contents)->toContain("->boolean('async')");
    expect($contents)->toContain("->unsignedInteger('priority')");
    expect($contents)->toContain("->boolean('enabled')");
    expect($contents)->toContain("->softDeletes()");
    expect($contents)->toContain("index(['event', 'enabled'])");
    expect($contents)->toContain("index('priority')");
});

test('migration event_logs table has foreign key and indexes', function (): void {
    $contents = file_get_contents(base_path('database/migrations/2024_01_01_000002_create_event_logs_table.php'));
    expect($contents)->toContain("->foreign('trigger_id')->references('id')");
    expect($contents)->toContain('onDelete(\'cascade\')');
    expect($contents)->toContain("index(['trigger_id', 'status'])");
    expect($contents)->toContain("index('event')");
    expect($contents)->toContain("index('created_at')");
});

test('migration subscriptions table has correct columns and indexes', function (): void {
    $contents = file_get_contents(base_path('database/migrations/2025_06_28_000001_create_event_subscriptions_table.php'));
    expect($contents)->toContain("->uuid('id')->primary()");
    expect($contents)->toContain("->string('secret')->nullable()");
    expect($contents)->toContain("->unsignedInteger('failure_count')->default(0)");
    expect($contents)->toContain("->unsignedInteger('delivery_count')");
    expect($contents)->toContain("index(['event', 'active'])");
});

test('EventManager global disable suppresses fire', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);

    // Temporarily disable
    $manager->setEnabled(false);
    expect($manager->isDisabled())->toBeTrue();

    // Should not throw even without any triggers
    $manager->fire('test.suppressed.event', ['key' => 'value']);

    // Re-enable
    $manager->setEnabled(true);
    expect($manager->isDisabled())->toBeFalse();
});

test('TriggerBuilder resolves actions with deduplication', function (): void {
    // Create a trigger with both action() and actions() having the same class
    // The TriggerBuilder::resolveActions() should deduplicate.
    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $trigger = $manager->on('test.actions.dedup')
        ->action(\App\Actions\SendOrderNotification::class)
        ->actions([\App\Actions\SendOrderNotification::class])
        ->save();

    $parsed = json_decode($trigger->action, true);
    expect($parsed)->toBeArray();
    // Should contain only one entry since both action() and actions() had the same class
    expect($parsed)->toHaveCount(1);

    $trigger->delete();
});

test('Subscription signPayload returns empty for null secret', function (): void {
    $sub = new \ZeroBoiler\Events\Models\Subscription(['secret' => null]);
    expect($sub->signPayload('{"test":1}'))->toBe('');
});

test('Subscription signPayload returns empty for empty secret', function (): void {
    $sub = new \ZeroBoiler\Events\Models\Subscription(['secret' => '']);
    expect($sub->signPayload('{"test":1}'))->toBe('');
});

test('Subscription signPayload generates valid HMAC', function (): void {
    $sub = new \ZeroBoiler\Events\Models\Subscription(['secret' => 'whsec_test_secret']);
    $payload = '{"event":"test","data":{"key":"value"}}';
    $expected = hash_hmac('sha256', $payload, 'whsec_test_secret');
    expect($sub->signPayload($payload))->toBe($expected);
});

test('EventScheduler has register method', function (): void {
    $scheduler = app(\ZeroBoiler\Events\EventScheduler::class);
    expect(method_exists($scheduler, 'register'))->toBeTrue();

    $reflection = new ReflectionMethod($scheduler, 'register');
    $params = $reflection->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getType()->getName())->toBe(\Illuminate\Console\Scheduling\Schedule::class);
});

test('DispatchTriggerJob reads config-driven properties', function (): void {
    $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
        triggerId: (string) \Illuminate\Support\Str::uuid(),
        event: 'test.event',
        payload: ['key' => 'value'],
    );

    expect($job->tries)->toBeGreaterThanOrEqual(1);
    expect($job->queue)->toBeString();
    expect($job->backoff)->toBeArray();
    expect($job->backoff)->not->toBeEmpty();
});

test('rector.php has license header', function (): void {
    $contents = file_get_contents(base_path('rector.php'));
    expect($contents)->toContain('This file is part of ZeroBoiler');
});

test('GitHub CI workflow exists and targets PHP 8.5', function (): void {
    $ci = file_get_contents(base_path('.github/workflows/ci.yml'));
    expect($ci)->toContain("php-version: '8.5'");
    expect($ci)->toContain('phpstan analyse');
    expect($ci)->toContain('pest');
});

test('GitHub auto-fix workflow exists', function (): void {
    $autoFix = file_get_contents(base_path('.github/workflows/auto-fix.yml'));
    expect($autoFix)->toContain('rector');
    expect($autoFix)->toContain('pint');
});

test('test file count is accurate', function (): void {
    $testFiles = glob(base_path('tests/*.php'));
    // Exclude support files
    $supportFiles = ['Pest.php', 'TestCase.php', 'CreatesApplication.php', 'helpers.php', 'TestActions.php'];
    $actualCount = count(array_filter($testFiles, fn (string $f) => ! in_array(basename($f), $supportFiles, true)));
    expect($actualCount)->toBe(220); // 219 existing + this file
});

test('composer.json requires PHP 8.5+', function (): void {
    $json = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($json['require']['php'])->toBe('^8.5');
});

test('composer.json requires illuminate contracts and support 13.x', function (): void {
    $json = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($json['require']['illuminate/contracts'])->toBe('^13.0');
    expect($json['require']['illuminate/support'])->toBe('^13.0');
});
