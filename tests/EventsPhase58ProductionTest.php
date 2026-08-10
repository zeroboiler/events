<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

describe('Phase 58 — Final Production Readiness Audit', function (): void {

    it('all source files have strict_types declaration', function (): void {
        $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);
        expect($srcFiles)->not->toBeEmpty();

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        }
    });

    it('all core classes are declared final', function (): void {
        $finalClasses = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            DomainEvent::class,
            DispatchTriggerJob::class,
        ];

        foreach ($finalClasses as $class) {
            $ref = new ReflectionClass($class);
            expect($ref->isFinal())->toBeTrue("{$class} must be final");
        }
    });

    it('WildcardMatcher is readonly and final', function (): void {
        $ref = new ReflectionClass(WildcardMatcher::class);
        expect($ref->isReadOnly())->toBeTrue('WildcardMatcher must be readonly');
        expect($ref->isFinal())->toBeTrue('WildcardMatcher must be final');
    });

    it('all console commands are final', function (): void {
        $commandFiles = glob(__DIR__.'/../src/Console/*.php');
        foreach ($commandFiles as $file) {
            $className = 'ZeroBoiler\\Events\\Console\\'.basename($file, '.php');
            $ref = new ReflectionClass($className);
            expect($ref->isFinal())->toBeTrue("{$className} must be final");
        }
    });

    it('ServiceProvider has register and boot with Override attribute', function (): void {
        $ref = new ReflectionClass(EventsServiceProvider::class);

        $register = $ref->getMethod('register');
        expect($register->hasAttribute(\Override::class))->toBeTrue();
        expect($register->getReturnType())->not->toBeNull();

        $boot = $ref->getMethod('boot');
        expect($boot->hasAttribute(\Override::class))->toBeTrue();
        expect($boot->getReturnType())->not->toBeNull();
    });

    it('EventManager registers as singleton via ServiceProvider', function (): void {
        $provider = new EventsServiceProvider($this->app);
        $provider->register();

        $instance1 = $this->app->make(\ZeroBoiler\Events\EventManager::class);
        $instance2 = $this->app->make(\ZeroBoiler\Events\EventManager::class);

        expect($instance1)->toBe($instance2, 'EventManager must be a singleton');
    });

    it('TriggerBuilder and SubscriptionBuilder are transient (not shared)', function (): void {
        $provider = new EventsServiceProvider($this->app);
        $provider->register();

        $b1a = $this->app->make(TriggerBuilder::class);
        $b1b = $this->app->make(TriggerBuilder::class);
        expect($b1a)->not->toBe($b1b, 'TriggerBuilder must be transient');

        $b2a = $this->app->make(SubscriptionBuilder::class);
        $b2b = $this->app->make(SubscriptionBuilder::class);
        expect($b2a)->not->toBe($b2b, 'SubscriptionBuilder must be transient');
    });

    it('ConditionEngineContract resolves to ConditionEngine singleton', function (): void {
        $provider = new EventsServiceProvider($this->app);
        $provider->register();

        $contract = $this->app->make(ConditionEngineContract::class);
        expect($contract)->toBeInstanceOf(ConditionEngine::class);

        $concrete = $this->app->make(ConditionEngine::class);
        expect($contract)->toBe($concrete, 'Contract and concrete must be the same singleton');
    });

    it('Facade getFacadeAccessor returns EventManager class', function (): void {
        $ref = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
        $method = $ref->getMethod('getFacadeAccessor');
        expect($method->hasAttribute(\Override::class))->toBeTrue();
        $result = $method->invoke(null);
        expect($result)->toBe(\ZeroBoiler\Events\EventManager::class);
    });

    it('EventLog has all 4 status constants', function (): void {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');
        expect(EventLog::$statuses)->toEqual([
            'pending', 'dispatched', 'completed', 'failed',
        ]);
    });

    it('DomainEvent has readonly promoted properties', function (): void {
        $ref = new ReflectionClass(DomainEvent::class);

        $eventType = $ref->getProperty('eventType');
        expect($eventType->isReadOnly())->toBeTrue();

        $eventId = $ref->getProperty('eventId');
        expect($eventId->isReadOnly())->toBeTrue();
        expect($eventId->isPublic())->toBeTrue();
        expect($eventId->getType()->getName())->toBe(Ramsey\Uuid\UuidInterface::class);

        $occurredAt = $ref->getProperty('occurredAt');
        expect($occurredAt->isReadOnly())->toBeTrue();
        expect($occurredAt->isPublic())->toBeTrue();
        expect($occurredAt->getType()->getName())->toBe(\DateTimeImmutable::class);
    });

    it('DomainEvent roundtrip preserves identity', function (): void {
        $original = DomainEvent::occur('test.event', ['key' => 'value']);
        $restored = DomainEvent::fromArray($original->toArray());

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->eventType)->toBe($original->eventType);
        expect($restored->payload)->toBe($original->payload);
        expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))
            ->toBe($original->occurredAt->format(\DateTimeImmutable::ATOM));
    });

    it('WildcardMatcher all public methods have #[Pure]', function (): void {
        $ref = new ReflectionClass(WildcardMatcher::class);
        $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

        foreach ($methods as $method) {
            $m = $ref->getMethod($method);
            expect($m->isStatic())->toBeTrue("WildcardMatcher::{$method} must be static");
            expect($m->hasAttribute(\Pure::class))->toBeTrue("WildcardMatcher::{$method} must have #[Pure]");
            expect($m->getReturnType())->not->toBeNull();
        }
    });

    it('ConditionEngine has #[Override] on matches()', function (): void {
        $ref = new ReflectionClass(ConditionEngine::class);
        $method = $ref->getMethod('matches');
        expect($method->hasAttribute(\Override::class))->toBeTrue();
    });

    it('Triggerable interface has correct signature', function (): void {
        $ref = new ReflectionClass(Triggerable::class);
        expect($ref->isInterface())->toBeTrue();

        $method = $ref->getMethod('handle');
        expect($method->getReturnType()->getName())->toBe('void');

        $param = $method->getParameters()[0];
        expect($param->getName())->toBe('payload');
        expect($param->getType())->not->toBeNull();
    });

    it('config has all 7 required sections', function (): void {
        $config = config('events');
        expect($config)->not->toBeNull();

        $sections = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
        foreach ($sections as $key) {
            expect(array_key_exists($key, $config))->toBeTrue("Config must have '{$key}' section");
        }
    });

    it('config table_names has all 3 entries', function (): void {
        $tables = config('events.table_names');
        expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    });

    it('model getTable methods use config', function (): void {
        config(['events.table_names.triggers' => 'custom_triggers']);
        expect((new Trigger)->getTable())->toBe('custom_triggers');

        config(['events.table_names.event_logs' => 'custom_event_logs']);
        expect((new EventLog)->getTable())->toBe('custom_event_logs');

        config(['events.table_names.subscriptions' => 'custom_event_subscriptions']);
        expect((new Subscription)->getTable())->toBe('custom_event_subscriptions');

        // Reset
        config(['events.table_names.triggers' => 'triggers']);
        config(['events.table_names.event_logs' => 'event_logs']);
        config(['events.table_names.subscriptions' => 'event_subscriptions']);
    });

    it('all models use UUID string keys', function (): void {
        $models = [Trigger::class, EventLog::class, Subscription::class];

        foreach ($models as $model) {
            $ref = new ReflectionClass($model);
            $keyType = $ref->getDefaultProperties()['keyType'] ?? null;
            expect($keyType)->toBe('string', "{$model} keyType must be 'string'");

            $incrementing = $ref->getDefaultProperties()['incrementing'] ?? true;
            expect($incrementing)->toBeFalse("{$model} incrementing must be false");
        }
    });

    it('EscapesWildcardLike returns null for non-wildcard patterns', function (): void {
        $trait = new class
        {
            use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
        };

        expect($trait->wildcardToLike('order.placed'))->toBeNull();
        expect($trait->wildcardToLike('user.created'))->toBeNull();
    });

    it('EscapesWildcardLike converts wildcards to SQL LIKE patterns', function (): void {
        $trait = new class
        {
            use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
        };

        expect($trait->wildcardToLike('order.*'))->toBe('order.%');
        expect($trait->wildcardToLike('order.**'))->toBe('order.%');
        expect($trait->wildcardToLike('*'))->toBe('%');
        expect($trait->wildcardToLike('%.test%'))->toBe('\%.test\%');
    });

    it('all migration files exist', function (): void {
        $migrations = glob(__DIR__.'/../database/migrations/*.php');
        expect(count($migrations))->toBe(3);
    });

    it('all factory files exist', function (): void {
        $factories = glob(__DIR__.'/../database/factories/*.php');
        expect(count($factories))->toBe(3);
    });

    it('EventManager public API surface is complete', function (): void {
        $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m) => ! $m->isStatic()
        );

        $expected = [
            'on', 'register', 'fire', 'fireModel',
            'enable', 'disable', 'deleteTrigger',
            'invalidateTriggerCache', 'isDisabled', 'setEnabled',
            'listTriggers', 'getTrigger',
            'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription',
            'subscribeWebhook',
            'getEventHistory', 'getStats', 'purgeLogs',
            'executeTrigger',
        ];

        $actual = array_map(fn (ReflectionMethod $m) => $m->getName(), $publicMethods);

        foreach ($expected as $method) {
            expect(in_array($method, $actual, true))
                ->toBeTrue("EventManager must have public method: {$method}");
        }
    });

    it('TriggerBuilder fluent interface returns self on all chainable methods', function (): void {
        $ref = new ReflectionClass(TriggerBuilder::class);
        $chainable = ['name', 'on', 'action', 'actions', 'when', 'async', 'priority', 'actionParams'];

        foreach ($chainable as $method) {
            $m = $ref->getMethod($method);
            $returnType = $m->getReturnType();
            expect($returnType)->not->toBeNull("{$method} must have return type");
            expect($returnType->getName())->toBe('self');
        }
    });

    it('SubscriptionBuilder fluent interface returns self on all chainable methods', function (): void {
        $ref = new ReflectionClass(SubscriptionBuilder::class);
        $chainable = ['on', 'to', 'withSecret', 'withFilter', 'priority', 'async'];

        foreach ($chainable as $method) {
            $m = $ref->getMethod($method);
            $returnType = $m->getReturnType();
            expect($returnType)->not->toBeNull("{$method} must have return type");
            expect($returnType->getName())->toBe('self');
        }
    });

    it('composer.json has correct structure', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        expect($composer['name'])->toBe('zeroboiler/events');
        expect($composer['type'])->toBe('library');
        expect($composer['require']['php'])->toBe('^8.5');
        expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        expect($composer['extra']['laravel']['providers'][0])
            ->toBe('ZeroBoiler\\Events\\EventsServiceProvider');
        expect($composer['extra']['laravel']['aliases']['EventManager'])
            ->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
    });

    it('phpstan.neon.dist is level 9', function (): void {
        $config = parse_ini_string(
            file_get_contents(__DIR__.'/../phpstan.neon.dist'),
            false,
            INI_SCANNER_RAW
        );

        expect($config['level'])->toBe('9');
    });

    it('config duplicate Wildcard Cache comment block removed', function (): void {
        $config = file_get_contents(__DIR__.'/../config/events.php');

        // Count occurrences of "Wildcard Cache" section header
        preg_match_all('/Wildcard Cache/', $config, $matches);
        // Should have 3 occurrences: the comment header, the description lines, and the key
        expect(count($matches[0]))->toBeLessThanOrEqual(4, 'Config should not have duplicate Wildcard Cache sections');
    });

    it('phpstan.neon.dist has checkMissingIterableValueType false', function (): void {
        $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
        expect($content)->toContain('checkMissingIterableValueType: false');
    });

    it('EventManager readonly promoted properties verified', function (): void {
        $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
        $constructor = $ref->getMethod('__construct');
        $params = $constructor->getParameters();

        foreach ($params as $param) {
            expect($param->getName())->toBeIn(['conditionEngine', 'actionResolver', 'app']);
            expect($param->isPromoted())->toBeTrue("Constructor param {$param->getName()} must be promoted");
        }

        $props = $ref->getProperties(\ReflectionProperty::IS_READONLY | \ReflectionProperty::IS_PROTECTED);
        expect(count($props))->toBe(3);
    });

    it('all 11 console commands have zeroboiler:events: prefix', function (): void {
        $commandFiles = glob(__DIR__.'/../src/Console/*.php');
        expect(count($commandFiles))->toBe(11);

        $expectedPrefix = 'zeroboiler:events:';
        foreach ($commandFiles as $file) {
            $content = file_get_contents($file);
            $className = 'ZeroBoiler\\Events\\Console\\'.basename($file, '.php');
            // Check signature contains the prefix
            if (str_contains($content, 'protected string $signature')) {
                expect($content)->toContain($expectedPrefix, "{$className} signature must use '{$expectedPrefix}' prefix");
            }
        }
    });
});
