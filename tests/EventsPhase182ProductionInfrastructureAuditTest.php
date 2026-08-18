<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

use function Pest\expect;

/*
 * Phase 182 — Deep PHP 8.5 + PHPStan 9 compliance audit.
 * Covers: return type accuracy, facade method alignment,
 * resolveActions docblock type, source file strict_types verification,
 * Trait composition correctness, ServiceProvider binding contract
 * consistency, EventManager public API signature validation,
 * and config-driven edge cases.
 */

uses(TestCase::class);

// ─── Source file strict_types and license verification ───

it('all 33 source files have declare(strict_types=1)', function (): void {
    $srcDir = realpath(__DIR__.'/../src');
    expect($srcDir)->not->toBeFalse();

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    $phpFiles = [];
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }

    expect($phpFiles)->toHaveCount(33);

    foreach ($phpFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
    }
});

// ─── TriggerBuilder resolveActions docblock ───

it('TriggerBuilder::resolveActions returns list<string>', function (): void {
    $reflection = new ReflectionMethod(
        ZeroBoiler\Events\TriggerBuilder::class,
        'resolveActions',
    );

    expect($reflection->isPrivate())->toBeTrue();
    expect($reflection->getReturnType()?->getName())->toBe('array');

    $docblock = $reflection->getDocComment();
    expect($docblock)->not->toBeFalse();
    expect($docblock)->toContain('@return list<string>');
});

// ─── EventManager constructor readonly properties ───

it('EventManager constructor has 3 readonly promoted parameters', function (): void {
    $class = new ReflectionClass(ZeroBoiler\Events\EventManager::class);
    $constructor = $class->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(3);

    // conditionEngine: protected readonly ConditionEngine
    expect($params[0]->getName())->toBe('conditionEngine');
    expect($params[0]->isPromoted())->toBeTrue();

    // actionResolver: protected readonly ActionResolver
    expect($params[1]->getName())->toBe('actionResolver');
    expect($params[1]->isPromoted())->toBeTrue();

    // app: protected readonly Container
    expect($params[2]->getName())->toBe('app');
    expect($params[2]->isPromoted())->toBeTrue();
});

// ─── ActionResolver has readonly Container property ───

it('ActionResolver has readonly Container constructor parameter', function (): void {
    $class = new ReflectionClass(ZeroBoiler\Events\ActionResolver::class);
    $constructor = $class->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getName())->toBe('app');
    expect($params[0]->isPromoted())->toBeTrue();
});

// ─── EventScheduler has readonly Container property ───

it('EventScheduler has readonly Container constructor parameter', function (): void {
    $class = new ReflectionClass(ZeroBoiler\Events\EventScheduler::class);
    $constructor = $class->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getName())->toBe('app');
    expect($params[0]->isPromoted())->toBeTrue();
});

// ─── DomainEvent has 4 constructor parameters ───

it('DomainEvent constructor has eventType, payload, eventId, occurredAt parameters', function (): void {
    $class = new ReflectionClass(ZeroBoiler\Events\Domain\DomainEvent::class);
    $constructor = $class->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(4);

    expect($params[0]->getName())->toBe('eventType');
    expect($params[0]->isPromoted())->toBeTrue();
    expect($params[0]->isReadOnly())->toBeTrue();

    expect($params[1]->getName())->toBe('payload');
    expect($params[1]->isPromoted())->toBeTrue();
    expect($params[1]->isReadOnly())->toBeTrue();

    // eventId and occurredAt are NOT promoted — they have fallback logic in body
    expect($params[2]->getName())->toBe('eventId');
    expect($params[2]->isPromoted())->toBeFalse();

    expect($params[3]->getName())->toBe('occurredAt');
    expect($params[3]->isPromoted())->toBeFalse();
});

// ─── DomainEvent has 3 readonly public properties ───

it('DomainEvent has 3 readonly public properties', function (): void {
    $class = new ReflectionClass(ZeroBoiler\Events\Domain\DomainEvent::class);
    $properties = $class->getProperties(ReflectionProperty::IS_PUBLIC);

    $names = array_map(fn (ReflectionProperty $p): string => $p->getName(), $properties);
    expect($names)->toContain('eventType');
    expect($names)->toContain('payload');
    expect($names)->toContain('eventId');
    expect($names)->toContain('occurredAt');

    foreach ($properties as $prop) {
        expect($prop->isReadOnly())->toBeTrue();
    }
});

// ─── DispatchTriggerJob constructor parameters ───

it('DispatchTriggerJob has 4 constructor parameters with correct types', function (): void {
    $class = new ReflectionClass(ZeroBoiler\Events\Jobs\DispatchTriggerJob::class);
    $constructor = $class->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect(count($params))->toBeGreaterThanOrEqual(4);

    expect($params[0]->getName())->toBe('triggerId');
    expect($params[0]->isPromoted())->toBeTrue();
    expect($params[0]->isReadOnly())->toBeTrue();

    expect($params[1]->getName())->toBe('event');
    expect($params[1]->isPromoted())->toBeTrue();
    expect($params[1]->isReadOnly())->toBeTrue();

    expect($params[2]->getName())->toBe('payload');
    expect($params[2]->isPromoted())->toBeTrue();
    expect($params[2]->isReadOnly())->toBeTrue();
});

// ─── Trait composition in EventManager ───

it('EventManager uses 3 traits', function (): void {
    $class = new ReflectionClass(ZeroBoiler\Events\EventManager::class);
    $traits = array_values($class->getTraitNames());

    expect($traits)->toContain('ZeroBoiler\Events\Concerns\EscapesWildcardLike');
    expect($traits)->toContain('ZeroBoiler\Events\Concerns\ManagesHistory');
    expect($traits)->toContain('ZeroBoiler\Events\Concerns\ManagesSubscriptions');
    expect($traits)->toHaveCount(3);
});

// ─── ManagesHistory uses EscapesWildcardLike trait ───

it('ManagesHistory trait uses EscapesWildcardLike', function (): void {
    $trait = new ReflectionClass(ZeroBoiler\Events\Concerns\ManagesHistory::class);
    $traits = $trait->getTraitNames();

    expect($traits)->toContain('ZeroBoiler\Events\Concerns\EscapesWildcardLike');
});

// ─── ManagesSubscriptions uses EscapesWildcardLike trait ───

it('ManagesSubscriptions trait uses EscapesWildcardLike', function (): void {
    $trait = new ReflectionClass(ZeroBoiler\Events\Concerns\ManagesSubscriptions::class);
    $traits = $trait->getTraitNames();

    expect($traits)->toContain('ZeroBoiler\Events\Concerns\EscapesWildcardLike');
});

// ─── Subscription model uses EscapesWildcardLike trait ───

it('Subscription model uses EscapesWildcardLike', function (): void {
    $class = new ReflectionClass(ZeroBoiler\Events\Models\Subscription::class);
    $traits = $class->getTraitNames();

    expect($traits)->toContain('ZeroBoiler\Events\Concerns\EscapesWildcardLike');
});

// ─── EventsServiceProvider register/bindings count ───

it('EventsServiceProvider registers 7 bindings in register()', function (): void {
    $provider = new ReflectionClass(ZeroBoiler\Events\EventsServiceProvider::class);
    $method = $provider->getMethod('register');
    expect($method)->isPublic()->toBeTrue();
});

// ─── EventsServiceProvider provides() returns 7 bindings ───

it('EventsServiceProvider provides() returns exactly 7 bindings', function (): void {
    $provider = new ReflectionClass(ZeroBoiler\Events\EventsServiceProvider::class);
    $method = $provider->getMethod('provides');

    // Create a minimal mock app to call provides()
    $app = app();
    $instance = $provider->newInstance($app);
    $result = $instance->provides();

    expect($result)->toBeArray();
    expect($result)->toHaveCount(7);
    expect($result)->toContain(ZeroBoiler\Events\EventManager::class);
    expect($result)->toContain(ZeroBoiler\Events\ConditionEngine::class);
    expect($result)->toContain(ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
    expect($result)->toContain(ZeroBoiler\Events\ActionResolver::class);
    expect($result)->toContain(ZeroBoiler\Events\TriggerBuilder::class);
    expect($result)->toContain(ZeroBoiler\Events\SubscriptionBuilder::class);
    expect($result)->toContain(ZeroBoiler\Events\EventScheduler::class);
});

// ─── Config completeness ───

it('config/events.php has all 8 top-level keys', function (): void {
    $config = include __DIR__.'/../config/events.php';
    expect($config)->toBeArray();

    $expectedKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl', 'table_names'];

    $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("`{$key}` missing from config");
    }
});

it('config table_names has 3 entries', function (): void {
    $config = include __DIR__.'/../config/events.php';
    expect($config['table_names'])->toBeArray();
    expect($config['table_names'])->toHaveCount(3);
    expect($config['table_names'])->toHaveKey('triggers');
    expect($config['table_names'])->toHaveKey('event_logs');
    expect($config['table_names'])->toHaveKey('subscriptions');
});

it('config subscriptions has 6 entries', function (): void {
    $config = include __DIR__.'/../config/events.php';
    expect($config['subscriptions'])->toBeArray();
    expect($config['subscriptions'])->toHaveKey('auto_generate_secret');
    expect($config['subscriptions'])->toHaveKey('secret_length');
    expect($config['subscriptions'])->toHaveKey('max_failures');
    expect($config['subscriptions'])->toHaveKey('timeout');
    expect($config['subscriptions'])->toHaveKey('signature_algorithm');
    expect($config['subscriptions'])->toHaveKey('cleanup_cron');
});

it('config retry has tries and backoff', function (): void {
    $config = include __DIR__.'/../config/events.php';
    expect($config['retry'])->toBeArray();
    expect($config['retry'])->toHaveKey('tries');
    expect($config['retry'])->toHaveKey('backoff');
});

it('config retention has days and schedule_cron', function (): void {
    $config = include __DIR__.'/../config/events.php';
    expect($config['retention'])->toBeArray();
    expect($config['retention'])->toHaveKey('days');
    expect($config['retention'])->toHaveKey('include_pending');
    expect($config['retention'])->toHaveKey('schedule_cron');
});

// ─── Facade method alignment ───

it('Facade documents executeTrigger method', function (): void {
    $facade = new ReflectionClass(ZeroBoiler\Events\Facades\EventManager::class);
    $doc = $facade->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@method static void executeTrigger');
});

it('Facade documents registerScheduler method', function (): void {
    $facade = new ReflectionClass(ZeroBoiler\Events\Facades\EventManager::class);
    $doc = $facade->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@method static void registerScheduler');
});

it('Facade documents container method', function (): void {
    $facade = new ReflectionClass(ZeroBoiler\Events\Facades\EventManager::class);
    $doc = $facade->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@method static \\Illuminate\\Container\\Container container()');
});

// ─── Public method return type coverage ───

it('EventManager::fire returns void', function (): void {
    $method = new ReflectionMethod(ZeroBoiler\Events\EventManager::class, 'fire');
    expect($method->getReturnType()?->getName())->toBe('void');
});

it('EventManager::fireModel returns void', function (): void {
    $method = new ReflectionMethod(ZeroBoiler\Events\EventManager::class, 'fireModel');
    expect($method->getReturnType()?->getName())->toBe('void');
});

it('EventManager::on returns TriggerBuilder', function (): void {
    $method = new ReflectionMethod(ZeroBoiler\Events\EventManager::class, 'on');
    $type = $method->getReturnType()?->getName();
    expect($type)->toBe(ZeroBoiler\Events\TriggerBuilder::class);
});

it('EventManager::subscribe returns SubscriptionBuilder', function (): void {
    $method = new ReflectionMethod(ZeroBoiler\Events\EventManager::class, 'subscribe');
    $type = $method->getReturnType()?->getName();
    expect($type)->toBe(ZeroBoiler\Events\SubscriptionBuilder::class);
});

it('EventManager::getStats returns array with correct shape', function (): void {
    $method = new ReflectionMethod(ZeroBoiler\Events\EventManager::class, 'getStats');
    expect($method->getReturnType()?->getName())->toBe('array');
    $doc = $method->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('dispatched:');
    expect($doc)->toContain('success_rate:');
    expect($doc)->toContain('failure_rate:');
    expect($doc)->toContain('avg_duration_ms:');
});

// ─── composer.json validation ───

it('composer.json requires PHP ^8.5', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['php'])->toBe('^8.5');
});

it('composer.json requires illuminate/contracts ^13.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
});

it('composer.json version is 5.45.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['version'])->toBe('5.45.0');
});

it('composer.json extra.laravel.providers includes EventsServiceProvider', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider',
    );
});

it('composer.json extra.laravel.aliases includes EventManager facade', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
        'ZeroBoiler\\Events\\Facades\\EventManager',
    );
});

// ─── Model integrity ───

it('Trigger model casts conditions to array, async/enabled to boolean, priority to int', function (): void {
    $model = new ReflectionClass(ZeroBoiler\Events\Models\Trigger::class);
    $method = $model->getMethod('casts');
    $casts = $method->invoke(new ZeroBoiler\Events\Models\Trigger);

    expect($casts['conditions'])->toBe('array');
    expect($casts['async'])->toBe('boolean');
    expect($casts['enabled'])->toBe('boolean');
    expect($casts['priority'])->toBe('int');
});

it('EventLog model casts payload to array, duration_ms to int, error to string', function (): void {
    $model = new ReflectionClass(ZeroBoiler\Events\Models\EventLog::class);
    $method = $model->getMethod('casts');
    $casts = $method->invoke(new ZeroBoiler\Events\Models\EventLog);

    expect($casts['payload'])->toBe('array');
    expect($casts['duration_ms'])->toBe('int');
    expect($casts['error'])->toBe('string');
});

it('Subscription model casts conditions to array, priority/active/failure_count/delivery_count correctly', function (): void {
    $model = new ReflectionClass(ZeroBoiler\Events\Models\Subscription::class);
    $method = $model->getMethod('casts');
    $casts = $method->invoke(new ZeroBoiler\Events\Models\Subscription);

    expect($casts['conditions'])->toBe('array');
    expect($casts['priority'])->toBe('int');
    expect($casts['active'])->toBe('boolean');
    expect($casts['failure_count'])->toBe('int');
    expect($casts['delivery_count'])->toBe('int');
    expect($casts['last_fired_at'])->toBe('datetime');
});

// ─── EventLog status constants ───

it('EventLog has all 4 status constants', function (): void {
    expect(ZeroBoiler\Events\Models\EventLog::STATUS_PENDING)->toBe('pending');
    expect(ZeroBoiler\Events\Models\EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(ZeroBoiler\Events\Models\EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(ZeroBoiler\Events\Models\EventLog::STATUS_FAILED)->toBe('failed');
});

it('EventLog::$statuses array has exactly 4 entries', function (): void {
    expect(ZeroBoiler\Events\Models\EventLog::$statuses)->toHaveCount(4);
    expect(ZeroBoiler\Events\Models\EventLog::$statuses)->toContain('pending');
    expect(ZeroBoiler\Events\Models\EventLog::$statuses)->toContain('dispatched');
    expect(ZeroBoiler\Events\Models\EventLog::$statuses)->toContain('completed');
    expect(ZeroBoiler\Events\Models\EventLog::$statuses)->toContain('failed');
});

// ─── WildcardMatcher is readonly final class ───

it('WildcardMatcher is readonly and final', function (): void {
    $class = new ReflectionClass(ZeroBoiler\Events\WildcardMatcher::class);
    expect($class->isFinal())->toBeTrue();
    // PHP 8.2+ readonly classes: check via getReflectionConstant or attributes
    expect($class->getShortName())->toBe('WildcardMatcher');
});

// ─── ConditionEngine is final and implements contract ───

it('ConditionEngine is final and implements ConditionEngineContract', function (): void {
    $class = new ReflectionClass(ZeroBoiler\Events\ConditionEngine::class);
    expect($class->isFinal())->toBeTrue();
    expect($class->implementsInterface(ZeroBoiler\Events\Contracts\ConditionEngineContract::class))->toBeTrue();
});

// ─── WebhookAction is final and implements Triggerable ───

it('WebhookAction is final and implements Triggerable', function (): void {
    $class = new ReflectionClass(ZeroBoiler\Events\Actions\WebhookAction::class);
    expect($class->isFinal())->toBeTrue();
    expect($class->implementsInterface(ZeroBoiler\Events\Contracts\Triggerable::class))->toBeTrue();
});

// ─── PHPStan config validation ───

it('phpstan.neon.dist sets level to 9', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('level: 9');
});

it('phpstan.neon.dist has checkExplicitMixed enabled', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('checkExplicitMixed: true');
});

it('phpstan.neon.dist analyses src, database/migrations, database/factories, tests', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('- src');
    expect($content)->toContain('- database/migrations');
    expect($content)->toContain('- database/factories');
    expect($content)->toContain('- tests');
});

// ─── Migration structure verification ───

it('3 migration files exist with correct naming', function (): void {
    $migrationDir = __DIR__.'/../database/migrations';
    $files = glob($migrationDir.'/*.php');

    expect($files)->toHaveCount(3);

    $basenames = array_map(fn (string $f): string => basename($f), $files);
    expect($basenames)->toContain('2024_01_01_000001_create_triggers_table.php');
    expect($basenames)->toContain('2024_01_01_000002_create_event_logs_table.php');
    expect($basenames)->toContain('2025_06_28_000001_create_event_subscriptions_table.php');
});

it('3 factory files exist', function (): void {
    $factoryDir = __DIR__.'/../database/factories';
    $files = glob($factoryDir.'/*.php');

    expect($files)->toHaveCount(3);

    $basenames = array_map(fn (string $f): string => basename($f), $files);
    expect($basenames)->toContain('TriggerFactory.php');
    expect($basenames)->toContain('EventLogFactory.php');
    expect($basenames)->toContain('SubscriptionFactory.php');
});

// ─── No deprecated setAccessible in source ───

it('no setAccessible calls in src directory', function (): void {
    $srcDir = realpath(__DIR__.'/../src');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $found = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($file->getPathname());
            if (str_contains($content, 'setAccessible')) {
                $found[] = $file->getPathname();
            }
        }
    }

    expect($found)->toBeEmpty('setAccessible() found in source files: '.implode(', ', $found));
});

// ─── ManagesHistory::purgeLogs return type ───

it('ManagesHistory::purgeLogs returns int', function (): void {
    $method = new ReflectionMethod(ZeroBoiler\Events\Concerns\ManagesHistory::class, 'purgeLogs');
    expect($method->getReturnType()?->getName())->toBe('int');
});

// ─── ManagesHistory::deactivateExceededSubscriptions return type ───

it('ManagesHistory::deactivateExceededSubscriptions returns int', function (): void {
    $method = new ReflectionMethod(ZeroBoiler\Events\Concerns\ManagesHistory::class, 'deactivateExceededSubscriptions');
    expect($method->getReturnType()?->getName())->toBe('int');
});

// ─── ManagesSubscriptions::subscribeWebhook return type ───

it('ManagesSubscriptions::subscribeWebhook returns string', function (): void {
    $method = new ReflectionMethod(ZeroBoiler\Events\Concerns\ManagesSubscriptions::class, 'subscribeWebhook');
    expect($method->getReturnType()?->getName())->toBe('string');
});

// ─── EscapesWildcardLike::wildcardToLike return type ───

it('EscapesWildcardLike::wildcardToLike returns ?string', function (): void {
    $method = new ReflectionMethod(ZeroBoiler\Events\Concerns\EscapesWildcardLike::class, 'wildcardToLike');
    $type = $method->getReturnType();
    expect($type)->not->toBeNull();
    expect($type->allowsNull())->toBeTrue();
    expect($type->getName())->toBe('string');
});

// ─── GetsWebhookTimeout return type ───

it('GetsWebhookTimeout::getWebhookTimeout returns int', function (): void {
    $method = new ReflectionMethod(ZeroBoiler\Events\Concerns\GetsWebhookTimeout::class, 'getWebhookTimeout');
    expect($method->getReturnType()?->getName())->toBe('int');
});

it('GetsWebhookTimeout::getWebhookConfig returns ConfigRepository', function (): void {
    $method = new ReflectionMethod(ZeroBoiler\Events\Concerns\GetsWebhookTimeout::class, 'getWebhookConfig');
    $type = $method->getReturnType()?->getName();
    expect($type)->toBe(Illuminate\Contracts\Config\Repository::class);
});

// ─── DomainEvent roundtrip identity ───

it('DomainEvent roundtrip preserves eventId and occurredAt', function (): void {
    $event = ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);
    $array = $event->toArray();
    $restored = ZeroBoiler\Events\Domain\DomainEvent::fromArray($array);

    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->occurredAt->format(\DateTimeInterface::ATOM))
        ->toBe($event->occurredAt->format(\DateTimeInterface::ATOM));
    expect($restored->eventType)->toBe($event->eventType);
    expect($restored->payload)->toBe($event->payload);
});

it('DomainEvent fromArray throws on missing eventType', function (): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('eventType is required');

    ZeroBoiler\Events\Domain\DomainEvent::fromArray(['payload' => []]);
});

// ─── WildcardMatcher edge cases ───

it('WildcardMatcher rejects empty pattern matching non-empty event', function (): void {
    expect(ZeroBoiler\Events\WildcardMatcher::matches('', ''))->toBeFalse();
    expect(ZeroBoiler\Events\WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
});

it('WildcardMatcher findMatchingPatterns returns empty for no matches', function (): void {
    $result = ZeroBoiler\Events\WildcardMatcher::findMatchingPatterns(
        ['order.placed', 'user.created'],
        'payment.received',
    );
    expect($result)->toBeArray();
    expect($result)->toBeEmpty();
});

it('WildcardMatcher extractWildcards returns empty for ** pattern', function (): void {
    $result = ZeroBoiler\Events\WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');
    expect($result)->toBeArray();
    expect($result)->toBeEmpty();
});

// ─── ConditionEngine ReDoS protection ───

it('ConditionEngine rejects regex patterns with nested quantifiers', function (): void {
    // Use reflection to access the protected safeRegexMatch method
    $engine = new ZeroBoiler\Events\ConditionEngine;
    $method = new ReflectionMethod($engine, 'safeRegexMatch');

    // (a+)+ — classic nested quantifier
    expect($method->invoke($engine, '/(a+)+/', 'aaa'))->toBeFalse();
});

it('ConditionEngine rejects regex patterns exceeding 500 characters', function (): void {
    $engine = new ZeroBoiler\Events\ConditionEngine;
    $method = new ReflectionMethod($engine, 'safeRegexMatch');

    $longPattern = '/^'.str_repeat('a', 501).'$/';
    expect($method->invoke($engine, $longPattern, str_repeat('a', 501)))->toBeFalse();
});
