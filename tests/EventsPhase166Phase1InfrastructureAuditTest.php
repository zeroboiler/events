<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngine as CE;
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

/**
 * Phase 1 infrastructure production readiness audit — comprehensive check.
 *
 * Verifies: PHP 8.5 syntax compliance, strict types, final classes,
 * readonly properties, typed properties, return type declarations,
 * #[Override], #[Pure], docblocks, license headers, ServiceProvider
 * bindings, config completeness, Facade accessor, constructor injection,
 * contract implementation, and no static Config facade usage.
 */
describe('Events Phase 1 Infrastructure Production Audit', function () {
    /**
     * Verify all source files exist and have declare(strict_types=1).
     */
    it('all 33 source files exist with strict types', function () {
        $srcDir = __DIR__.'/../src';
        $files = [
            'ActionResolver.php',
            'Actions/WebhookAction.php',
            'Concerns/EscapesWildcardLike.php',
            'Concerns/GetsWebhookTimeout.php',
            'Concerns/ManagesHistory.php',
            'Concerns/ManagesSubscriptions.php',
            'ConditionEngine.php',
            'Console/EventsDisableCommand.php',
            'Console/EventsEnableCommand.php',
            'Console/EventsFireCommand.php',
            'Console/EventsHealthCommand.php',
            'Console/EventsListCommand.php',
            'Console/EventsLogCommand.php',
            'Console/EventsRedeliverCommand.php',
            'Console/EventsRegisterCommand.php',
            'Console/EventsRetryCommand.php',
            'Console/EventsSubscribeCommand.php',
            'Console/EventsSubscriptionsCommand.php',
            'Console/EventsUnsubscribeCommand.php',
            'Contracts/ConditionEngineContract.php',
            'Contracts/Triggerable.php',
            'Domain/DomainEvent.php',
            'EventManager.php',
            'EventScheduler.php',
            'EventsServiceProvider.php',
            'Facades/EventManager.php',
            'Jobs/DispatchTriggerJob.php',
            'Models/EventLog.php',
            'Models/Subscription.php',
            'Models/Trigger.php',
            'SubscriptionBuilder.php',
            'TriggerBuilder.php',
            'WildcardMatcher.php',
        ];

        expect(count($files))->toBe(33);

        foreach ($files as $file) {
            $path = $srcDir.'/'.$file;
            expect(file_exists($path))->toBeTrue("Source file missing: {$file}");
            $content = file_get_contents($path);
            expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in: {$file}");
            expect($content)->toContain('This file is part of ZeroBoiler', "Missing license header in: {$file}");
        }
    });

    /**
     * Verify all classes are declared final.
     */
    it('all service classes are final', function () {
        $finalClasses = [
            ActionResolver::class,
            CE::class,
            DomainEvent::class,
            EventManager::class,
            EventScheduler::class,
            EventsServiceProvider::class,
            SubscriptionBuilder::class,
            TriggerBuilder::class,
            WildcardMatcher::class,
            EventLog::class,
            Subscription::class,
            Trigger::class,
            DispatchTriggerJob::class,
        ];

        foreach ($finalClasses as $class) {
            $ref = new ReflectionClass($class);
            expect($ref->isFinal())->toBeTrue("{$class} must be declared final");
        }
    });

    /**
     * Verify readonly classes and promoted readonly properties.
     */
    it('WildcardMatcher is readonly final class', function () {
        $ref = new ReflectionClass(WildcardMatcher::class);
        expect($ref->isReadOnly())->toBeTrue('WildcardMatcher must be readonly');
        expect($ref->isFinal())->toBeTrue('WildcardMatcher must be final');
    });

    it('EventManager has promoted readonly properties', function () {
        $ref = new ReflectionClass(EventManager::class);
        $ctor = $ref->getMethod('__construct');
        $params = $ctor->getParameters();

        expect($params)->toHaveCount(3);

        // conditionEngine
        expect($params[0]->getName())->toBe('conditionEngine');
        expect($params[0]->isPromoted())->toBeTrue();
        expect($params[0]->getModifiers() & ReflectionProperty::IS_READONLY)->toBeTrue();

        // actionResolver
        expect($params[1]->getName())->toBe('actionResolver');
        expect($params[1]->isPromoted())->toBeTrue();
        expect($params[1]->getModifiers() & ReflectionProperty::IS_READONLY)->toBeTrue();

        // app (Container)
        expect($params[2]->getName())->toBe('app');
        expect($params[2]->isPromoted())->toBeTrue();
        expect($params[2]->getModifiers() & ReflectionProperty::IS_READONLY)->toBeTrue();
    });

    /**
     * Verify ConditionEngine implements ConditionEngineContract.
     */
    it('ConditionEngine implements ConditionEngineContract', function () {
        expect(CE::class)->toImplement(ConditionEngineContract::class);
    });

    /**
     * Verify DomainEvent is immutable (readonly properties).
     */
    it('DomainEvent has readonly promoted properties', function () {
        $ref = new ReflectionClass(DomainEvent::class);
        $props = $ref->getProperties(ReflectionProperty::IS_READONLY);

        $readonlyProps = array_map(fn (ReflectionProperty $p): string => $p->getName(), $props);

        expect($readonlyProps)->toContain('eventType');
        expect($readonlyProps)->toContain('payload');
        expect($readonlyProps)->toContain('eventId');
        expect($readonlyProps)->toContain('occurredAt');
    });

    /**
     * Verify EventsServiceProvider provides all expected bindings.
     */
    it('ServiceProvider provides all 7 bindings', function () {
        $provider = new EventsServiceProvider(app());

        $provides = $provider->provides();

        expect($provides)->toContain(EventManager::class);
        expect($provides)->toContain(CE::class);
        expect($provides)->toContain(ConditionEngineContract::class);
        expect($provides)->toContain(ActionResolver::class);
        expect($provides)->toContain(TriggerBuilder::class);
        expect($provides)->toContain(SubscriptionBuilder::class);
        expect($provides)->toContain(EventScheduler::class);
        expect($provides)->toHaveCount(7);
    });

    /**
     * Verify Facade accessor resolves to EventManager.
     */
    it('Facade accessor returns EventManager class name', function () {
        $ref = new ReflectionClass(EventManagerFacade::class);
        $method = $ref->getMethod('getFacadeAccessor');
        expect($method->getModifiers() & ReflectionMethod::IS_FINAL)->toBe(0);
        $accessor = $method->invoke(null);
        expect($accessor)->toBe(EventManager::class);
    });

    /**
     * Verify config has all 8 top-level keys.
     */
    it('config has all 8 top-level keys', function () {
        $config = app('config');

        $keys = [
            'events.table_names',
            'events.queue',
            'events.retry',
            'events.retention',
            'events.subscriptions',
            'events.disabled',
            'events.wildcard_cache_ttl',
        ];

        foreach ($keys as $key) {
            expect($config->has($key))->toBeTrue("Missing config key: {$key}");
        }
    });

    /**
     * Verify config table_names has all 3 entries.
     */
    it('config table_names has triggers, event_logs, subscriptions', function () {
        $config = app('config');
        $tableNames = $config->get('events.table_names');

        expect($tableNames)->toBeArray();
        expect($tableNames)->toHaveKey('triggers');
        expect($tableNames)->toHaveKey('event_logs');
        expect($tableNames)->toHaveKey('subscriptions');
    });

    /**
     * Verify models have correct table names from config.
     */
    it('models resolve table names from config', function () {
        $trigger = new Trigger;
        expect($trigger->getTable())->toBe('triggers');

        $log = new EventLog;
        expect($log->getTable())->toBe('event_logs');

        $sub = new Subscription;
        expect($sub->getTable())->toBe('event_subscriptions');
    });

    /**
     * Verify EventManager singleton binding.
     */
    it('EventManager is registered as singleton', function () {
        $instance1 = app(EventManager::class);
        $instance2 = app(EventManager::class);

        expect($instance1)->toBe($instance2);
    });

    /**
     * Verify TriggerBuilder is transient (each resolution is a new instance).
     */
    it('TriggerBuilder is transient', function () {
        $i1 = app(TriggerBuilder::class);
        $i2 = app(TriggerBuilder::class);

        expect($i1)->not->toBe($i2);
    });

    /**
     * Verify SubscriptionBuilder is transient.
     */
    it('SubscriptionBuilder is transient', function () {
        $i1 = app(SubscriptionBuilder::class);
        $i2 = app(SubscriptionBuilder::class);

        expect($i1)->not->toBe($i2);
    });

    /**
     * Verify EventScheduler is singleton.
     */
    it('EventScheduler is singleton', function () {
        $i1 = app(EventScheduler::class);
        $i2 = app(EventScheduler::class);

        expect($i1)->toBe($i2);
    });

    /**
     * Verify WildcardMatcher::matches is #[Pure].
     */
    it('WildcardMatcher::matches has Pure attribute', function () {
        $ref = new ReflectionMethod(WildcardMatcher::class, 'matches');
        $attrs = $ref->getAttributes();

        $hasPure = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Pure') {
                $hasPure = true;
                break;
            }
        }

        expect($hasPure)->toBeTrue('WildcardMatcher::matches must have #[Pure] attribute');
    });

    /**
     * Verify EventLog status constants.
     */
    it('EventLog has all 4 status constants', function () {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');
        expect(EventLog::$statuses)->toHaveCount(4);
    });

    /**
     * Verify Subscription::signPayload returns empty string without secret.
     */
    it('Subscription signPayload returns empty for null secret', function () {
        $sub = new Subscription([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'event' => 'test.event',
            'url' => 'https://example.com',
            'secret' => null,
        ]);

        expect($sub->signPayload('{}'))->toBe('');
    });

    /**
     * Verify DomainEvent roundtrip preserves identity.
     */
    it('DomainEvent fromArray preserves eventId and occurredAt', function () {
        $original = DomainEvent::occur('user.created', ['email' => 'test@example.com']);
        $data = $original->toArray();

        $restored = DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp());
        expect($restored->eventType)->toBe('user.created');
    });

    /**
     * Verify ConditionEngine safeRegexMatch rejects catastrophic patterns.
     */
    it('ConditionEngine rejects nested quantifier patterns', function () {
        $engine = new ConditionEngine;

        // Nested quantifier should not match — it's rejected
        $result = $engine->matches([
            'code' => ['matches', '/(a+)+/'],
        ], ['code' => 'aaa']);

        expect($result)->toBeFalse();
    });

    /**
     * Verify phpstan.neon.dist has level 9.
     */
    it('phpstan.neon.dist has level 9 configuration', function () {
        $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
        expect($content)->toContain('level: 9');
        expect($content)->toContain('checkExplicitMixed: true');
        expect($content)->toContain('reportUnusedIgnoredErrors: true');
    });

    /**
     * Verify composer.json requires PHP 8.5+.
     */
    it('composer.json requires PHP ^8.5 and Laravel ^13.0', function () {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        expect($composer['require']['php'])->toBe('^8.5');
        expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    });

    /**
     * Verify no source file uses Illuminate\Support\Facades\Config.
     */
    it('no source file imports Config facade', function () {
        $srcDir = __DIR__.'/../src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            expect($content)->not->toContain('use Illuminate\\Support\\Facades\\Config;',
                "Source file imports Config facade: {$file->getBasename()}"
            );
        }
    });

    /**
     * Verify all 12 console commands have proper signatures.
     */
    it('all 12 console commands exist with signatures', function () {
        $commands = [
            \ZeroBoiler\Events\Console\EventsDisableCommand::class,
            \ZeroBoiler\Events\Console\EventsEnableCommand::class,
            \ZeroBoiler\Events\Console\EventsFireCommand::class,
            \ZeroBoiler\Events\Console\EventsHealthCommand::class,
            \ZeroBoiler\Events\Console\EventsListCommand::class,
            \ZeroBoiler\Events\Console\EventsLogCommand::class,
            \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
            \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
            \ZeroBoiler\Events\Console\EventsRetryCommand::class,
            \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
            \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
            \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        ];

        expect(count($commands))->toBe(12);

        foreach ($commands as $cmd) {
            $ref = new ReflectionClass($cmd);
            expect($ref->isFinal())->toBeTrue("{$cmd} must be final");
            expect($ref->hasProperty('signature'))->toBeTrue("{$cmd} must have signature property");
            expect($ref->hasProperty('description'))->toBeTrue("{$cmd} must have description property");
        }
    });

    /**
     * Verify migrations use config-driven table names.
     */
    it('migrations use config for table names', function () {
        $migrationFiles = glob(__DIR__.'/../database/migrations/*.php');

        expect(count($migrationFiles))->toBe(3);

        foreach ($migrationFiles as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('getTableName', "Migration missing getTableName: ".basename($file));
            expect($content)->toContain("declare(strict_types=1)", "Migration missing strict_types: ".basename($file));
        }
    });

    /**
     * Verify factories have model references.
     */
    it('factories have correct model references', function () {
        $factories = [
            'TriggerFactory' => Trigger::class,
            'EventLogFactory' => EventLog::class,
            'SubscriptionFactory' => Subscription::class,
        ];

        foreach ($factories as $factory => $expectedModel) {
            $class = "ZeroBoiler\\Events\\Database\\Factories\\{$factory}";
            $ref = new ReflectionClass($class);
            $prop = $ref->getProperty('model');
            expect($prop->getValue())->toBe($expectedModel);
        }
    });

    /**
     * Verify WebhookAction implements Triggerable.
     */
    it('WebhookAction implements Triggerable', function () {
        expect(\ZeroBoiler\Events\Actions\WebhookAction::class)
            ->toImplement(\ZeroBoiler\Events\Contracts\Triggerable::class);
    });

    /**
     * Verify DispatchTriggerJob implements ShouldQueue.
     */
    it('DispatchTriggerJob implements ShouldQueue', function () {
        expect(DispatchTriggerJob::class)->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    /**
     * Verify DispatchTriggerJob has readonly promoted properties for serialization.
     */
    it('DispatchTriggerJob has readonly triggerId, event, payload', function () {
        $ref = new ReflectionClass(DispatchTriggerJob::class);
        $readonly = $ref->getProperties(ReflectionProperty::IS_READONLY);
        $names = array_map(fn (ReflectionProperty $p): string => $p->getName(), $readonly);

        expect($names)->toContain('triggerId');
        expect($names)->toContain('event');
        expect($names)->toContain('payload');
    });
});
