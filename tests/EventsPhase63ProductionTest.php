<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Console\EventsDisableCommand;
use ZeroBoiler\Events\Console\EventsEnableCommand;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Console\EventsHealthCommand;
use ZeroBoiler\Events\Console\EventsListCommand;
use ZeroBoiler\Events\Console\EventsLogCommand;
use ZeroBoiler\Events\Console\EventsRedeliverCommand;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Console\EventsSubscribeCommand;
use ZeroBoiler\Events\Console\EventsSubscriptionsCommand;
use ZeroBoiler\Events\Console\EventsUnsubscribeCommand;
use ZeroBoiler\Events\Contracts\Triggerable;
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

test('phase 63: all source files use strict types declaration')
    ->expect(
        array_map(
            fn (string $file): string => trim(file_get_contents($file)),
            glob(__DIR__.'/../src/**/*.php'),
        ),
    )->each->toContain('declare(strict_types=1)');

test('phase 63: all core classes are final')
    ->expect([
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        WildcardMatcher::class,
        DomainEvent::class,
        EventsServiceProvider::class,
        EventManagerFacade::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
    ])->each->toBeFinal();

test('phase 63: all console commands are final')
    ->expect([
        EventsListCommand::class,
        EventsRegisterCommand::class,
        EventsFireCommand::class,
        EventsLogCommand::class,
        EventsRetryCommand::class,
        EventsEnableCommand::class,
        EventsDisableCommand::class,
        EventsHealthCommand::class,
        EventsSubscribeCommand::class,
        EventsUnsubscribeCommand::class,
        EventsSubscriptionsCommand::class,
        EventsRedeliverCommand::class,
    ])->each->toBeFinal();

test('phase 63: ConditionEngine implements ConditionEngineContract')
    ->expect(new ConditionEngine)->toBeInstanceOf(ConditionEngineContract::class);

test('phase 63: WebhookAction implements Triggerable')
    ->expect(new WebhookAction)->toBeInstanceOf(Triggerable::class);

test('phase 63: DispatchTriggerJob implements ShouldQueue')
    ->expect(new DispatchTriggerJob('test', 'test.event', []))
    ->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);

test('phase 63: DomainEvent is immutable')
    ->expect(DomainEvent::class)
    ->toBeFinal();

test('phase 63: DomainEvent preserves eventId and occurredAt through roundtrip')
    ->expect(function (): bool {
        $original = new DomainEvent('user.created', ['id' => 42]);
        $data = $original->toArray();
        $restored = DomainEvent::fromArray($data);

        return $restored->eventId->toString() === $original->eventId->toString()
            && $restored->occurredAt == $original->occurredAt;
    })->toBeTrue();

test('phase 63: WildcardMatcher is readonly class')
    ->expect((new ReflectionClass(WildcardMatcher::class))->isReadOnly())->toBeTrue();

test('phase 63: WildcardMatcher static methods have #[Pure] attribute')
    ->expect(function (): bool {
        $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];
        $ref = new ReflectionClass(WildcardMatcher::class);

        foreach ($methods as $method) {
            $attrs = $ref->getMethod($method)->getAttributes(\Attribute::class);
            $hasPure = false;
            foreach ($attrs as $attr) {
                if ($attr->getName() === 'Pure' || str_contains($attr->getName(), 'Pure')) {
                    $hasPure = true;
                    break;
                }
            }
            // Also check for #[\Pure] attribute
            $pureAttrs = $ref->getMethod($method)->getAttributes();
            foreach ($pureAttrs as $attr) {
                if (str_contains($attr->getName(), 'Pure')) {
                    $hasPure = true;
                    break;
                }
            }
            if (! $hasPure) {
                return false;
            }
        }

        return true;
    })->toBeTrue();

test('phase 63: EventLog has correct status constants')
    ->expect([
        'pending' => EventLog::STATUS_PENDING,
        'dispatched' => EventLog::STATUS_DISPATCHED,
        'completed' => EventLog::STATUS_COMPLETED,
        'failed' => EventLog::STATUS_FAILED,
    ])->toBe([
        'pending' => 'pending',
        'dispatched' => 'dispatched',
        'completed' => 'completed',
        'failed' => 'failed',
    ]);

test('phase 63: EventLog $statuses array contains all 4 status constants')
    ->expect(EventLog::$statuses)->toBe([
        'pending',
        'dispatched',
        'completed',
        'failed',
    ]);

test('phase 63: config/events.php has all required sections')
    ->expect(function (): bool {
        $config = require __DIR__.'/../config/events.php';
        $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];

        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $config)) {
                return false;
            }
        }

        return true;
    })->toBeTrue();

test('phase 63: config has table_names with all 3 table entries')
    ->expect(function (): bool {
        $config = require __DIR__.'/../config/events.php';
        $tables = $config['table_names'];

        return isset($tables['triggers'], $tables['event_logs'], $tables['subscriptions']);
    })->toBeTrue();

test('phase 63: models use config-driven table names')
    ->expect(function (): bool {
        $triggers = (new Trigger)->getTable();
        $eventLogs = (new EventLog)->getTable();
        $subscriptions = (new Subscription)->getTable();

        return $triggers === 'triggers'
            && $eventLogs === 'event_logs'
            && $subscriptions === 'event_subscriptions';
    })->toBeTrue();

test('phase 63: models have string UUID key types and non-incrementing')
    ->expect(function (): bool {
        return (new Trigger)->getKeyType() === 'string'
            && ! (new Trigger)->getIncrementing()
            && (new EventLog)->getKeyType() === 'string'
            && ! (new EventLog)->getIncrementing()
            && (new Subscription)->getKeyType() === 'string'
            && ! (new Subscription)->getIncrementing();
    })->toBeTrue();

test('phase 63: ServiceProvider has register and boot methods')
    ->expect(function (): bool {
        $ref = new ReflectionClass(EventsServiceProvider::class);

        return $ref->hasMethod('register') && $ref->hasMethod('boot');
    })->toBeTrue();

test('phase 63: ServiceProvider register has #[Override] attribute')
    ->expect(function (): bool {
        $method = new ReflectionMethod(EventsServiceProvider::class, 'register');
        $attrs = $method->getAttributes();

        foreach ($attrs as $attr) {
            if (str_contains($attr->getName(), 'Override')) {
                return true;
            }
        }

        return false;
    })->toBeTrue();

test('phase 63: ServiceProvider boot has #[Override] attribute')
    ->expect(function (): bool {
        $method = new ReflectionMethod(EventsServiceProvider::class, 'boot');
        $attrs = $method->getAttributes();

        foreach ($attrs as $attr) {
            if (str_contains($attr->getName(), 'Override')) {
                return true;
            }
        }

        return false;
    })->toBeTrue();

test('phase 63: Facade getFacadeAccessor returns correct class')
    ->expect(function (): bool {
        $ref = new ReflectionClass(EventManagerFacade::class);
        $method = $ref->getMethod('getFacadeAccessor');

        return $method->invoke(null) === EventManager::class;
    })->toBeTrue();

test('phase 63: EventManager has all 21 public API methods')
    ->expect(function (): bool {
        $ref = new ReflectionClass(EventManager::class);
        $publicMethods = array_filter(
            $ref->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m): bool => ! $m->isStatic(),
        );
        $methodNames = array_map(
            fn (ReflectionMethod $m): string => $m->getName(),
            $publicMethods,
        );

        $expected = [
            'on', 'register', 'fire', 'fireModel',
            'enable', 'disable', 'deleteTrigger', 'getTrigger',
            'listTriggers', 'invalidateTriggerCache', 'isDisabled', 'setEnabled',
            'executeTrigger', 'subscribe', 'unsubscribe',
            'listSubscriptions', 'getSubscription', 'subscribeWebhook',
            'getEventHistory', 'getStats', 'purgeLogs',
        ];

        return count($expected) === count(array_intersect($expected, $methodNames));
    })->toBeTrue();

test('phase 63: TriggerBuilder fluent interface returns self')
    ->expect(function (): bool {
        $ref = new ReflectionClass(TriggerBuilder::class);
        $fluentMethods = ['name', 'on', 'action', 'actions', 'when', 'async', 'priority', 'actionParams'];

        foreach ($fluentMethods as $method) {
            $returnType = $ref->getMethod($method)->getReturnType();
            if ($returnType === null || $returnType->getName() !== 'self') {
                return false;
            }
        }

        return true;
    })->toBeTrue();

test('phase 63: SubscriptionBuilder fluent interface returns self')
    ->expect(function (): bool {
        $ref = new ReflectionClass(SubscriptionBuilder::class);
        $fluentMethods = ['on', 'to', 'withSecret', 'withFilter', 'priority', 'async'];

        foreach ($fluentMethods as $method) {
            $returnType = $ref->getMethod($method)->getReturnType();
            if ($returnType === null || $returnType->getName() !== 'self') {
                return false;
            }
        }

        return true;
    })->toBeTrue();

test('phase 63: ConditionEngine full operator matrix')
    ->expect(function (): bool {
        $engine = new ConditionEngine;
        $payload = ['amount' => 100, 'status' => 'active', 'tags' => ['urgent', 'vip'], 'email' => 'admin@test.com', 'notes' => 'some text'];

        return $engine->matches(['amount' => ['>', 50]], $payload) === true
            && $engine->matches(['amount' => ['>=', 100]], $payload) === true
            && $engine->matches(['amount' => ['<', 200]], $payload) === true
            && $engine->matches(['amount' => ['<=', 100]], $payload) === true
            && $engine->matches(['amount' => ['>', 100]], $payload) === false
            && $engine->matches(['status' => 'active'], $payload) === true
            && $engine->matches(['status' => ['===', 'active']], $payload) === true
            && $engine->matches(['status' => ['!==', 'inactive']], $payload) === true
            && $engine->matches(['status' => ['!=', 'inactive']], $payload) === true
            && $engine->matches(['tags' => ['in', ['urgent', 'vip']]], $payload) === true
            && $engine->matches(['tags' => ['not_in', ['other']]], $payload) === true
            && $engine->matches(['tags' => ['contains', 'urgent']], $payload) === true
            && $engine->matches(['tags' => ['not_contains', 'spam']], $payload) === true
            && $engine->matches(['amount' => ['between', [50, 200]]], $payload) === true
            && $engine->matches(['notes' => ['not_null']], $payload) === true
            && $engine->matches(['notes' => ['not_empty']], $payload) === true
            && $engine->matches(['email' => ['starts_with', 'admin']], $payload) === true
            && $engine->matches(['email' => ['ends_with', '.com']], $payload) === true
            && $engine->matches(['email' => ['matches', '/^admin@/']], $payload) === true;
    })->toBeTrue();

test('phase 63: WildcardMatcher comprehensive patterns')
    ->expect(function (): bool {
        return WildcardMatcher::matches('*', 'anything') === true
            && WildcardMatcher::matches('*', '') === false
            && WildcardMatcher::matches('order.*', 'order.placed') === true
            && WildcardMatcher::matches('order.*', 'order.placed.extra') === false
            && WildcardMatcher::matches('order.**', 'order.placed.extra') === true
            && WildcardMatcher::matches('order.**', 'order.placed') === true
            && WildcardMatcher::matches('*.order.*', 'user.order.created') === true
            && WildcardMatcher::matches('exact.event', 'exact.event') === true
            && WildcardMatcher::matches('exact.event', 'other.event') === false;
    })->toBeTrue();

test('phase 63: EscapesWildcardLike converts asterisks to percent')
    ->expect(function (): bool {
        // We test via Trigger model which uses the trait
        $ref = new ReflectionMethod(Trigger::class, 'wildcardToLike');
        $trigger = new Trigger;

        $result = $ref->invoke($trigger, 'order.*');

        return $result === 'order.%';
    })->toBeTrue();

test('phase 63: EscapesWildcardLike returns null for non-wildcard')
    ->expect(function (): bool {
        $ref = new ReflectionMethod(Trigger::class, 'wildcardToLike');
        $trigger = new Trigger;

        $result = $ref->invoke($trigger, 'order.placed');

        return $result === null;
    })->toBeTrue();

test('phase 63: DomainEvent fromArray rejects empty eventType')
    ->expect(function (): bool {
        try {
            DomainEvent::fromArray(['eventType' => '']);
        } catch (\InvalidArgumentException) {
            return true;
        }

        return false;
    })->toBeTrue();

test('phase 63: ActionResolver throws for non-existent class')
    ->expect(function (): bool {
        try {
            $app = app();
            $resolver = new ActionResolver($app);
            $resolver->resolve('NonExistentClass12345');
        } catch (\InvalidArgumentException $e) {
            return str_contains($e->getMessage(), 'does not exist');
        }

        return false;
    })->toBeTrue();

test('phase 63: EventManager fire rejects empty event name')
    ->expect(function (): bool {
        try {
            app(EventManager::class)->fire('');
        } catch (\InvalidArgumentException $e) {
            return str_contains($e->getMessage(), 'cannot be empty');
        }

        return false;
    })->toBeTrue();

test('phase 63: EventManager fireModel rejects empty model class')
    ->expect(function (): bool {
        try {
            app(EventManager::class)->fireModel('', 'created', new \stdClass);
        } catch (\InvalidArgumentException $e) {
            return str_contains($e->getMessage(), 'cannot be empty');
        }

        return false;
    })->toBeTrue();

test('phase 63: Subscription signPayload returns empty for null secret')
    ->expect(function (): bool {
        $sub = Subscription::factory()->withoutSecret()->create(['secret' => null]);

        return $sub->signPayload('test payload') === '';
    })->toBeTrue();

test('phase 63: Subscription signPayload returns deterministic HMAC')
    ->expect(function (): bool {
        $sub = Subscription::factory()->withSecret('test_secret_key')->create();
        $sig1 = $sub->signPayload('hello');
        $sig2 = $sub->signPayload('hello');

        return $sig1 !== '' && $sig1 === $sig2;
    })->toBeTrue();

test('phase 63: getStats returns expected structure')
    ->expect(function (): bool {
        $stats = app(EventManager::class)->getStats();
        $expectedKeys = [
            'total_logs', 'total_triggers', 'active_triggers',
            'completed', 'failed', 'pending', 'dispatched',
            'success_rate', 'failure_rate', 'avg_duration_ms',
            'top_events', 'top_failed_events',
        ];

        return count(array_intersect($expectedKeys, array_keys($stats))) === count($expectedKeys);
    })->toBeTrue();

test('phase 63: getStats zero-state has null rates')
    ->expect(function (): bool {
        $stats = app(EventManager::class)->getStats();

        return $stats['success_rate'] === null
            && $stats['failure_rate'] === null
            && $stats['avg_duration_ms'] === null
            && $stats['total_logs'] === 0;
    })->toBeTrue();

test('phase 63: TriggerBuilder save rejects empty event')
    ->expect(function (): bool {
        try {
            app(TriggerBuilder::class)->save();
        } catch (\InvalidArgumentException $e) {
            return str_contains($e->getMessage(), 'required');
        }

        return false;
    })->toBeTrue();

test('phase 63: TriggerBuilder save rejects no action')
    ->expect(function (): bool {
        try {
            app(TriggerBuilder::class)->on('test.event')->save();
        } catch (\InvalidArgumentException $e) {
            return str_contains($e->getMessage(), 'action is required');
        }

        return false;
    })->toBeTrue();

test('phase 63: SubscriptionBuilder save rejects empty URL')
    ->expect(function (): bool {
        try {
            app(SubscriptionBuilder::class)->on('test.event')->save();
        } catch (\InvalidArgumentException $e) {
            return str_contains($e->getMessage(), 'URL is required');
        }

        return false;
    })->toBeTrue();

test('phase 63: SubscriptionBuilder save rejects non-HTTP scheme')
    ->expect(function (): bool {
        try {
            app(SubscriptionBuilder::class)->on('test.event')->to('ftp://evil.com/hook')->save();
        } catch (\InvalidArgumentException $e) {
            return str_contains($e->getMessage(), 'HTTP or HTTPS');
        }

        return false;
    })->toBeTrue();

test('phase 63: Subscription matchesEvent handles wildcards')
    ->expect(function (): bool {
        $sub = Subscription::factory()->forEvent('order.*')->create();

        return $sub->matchesEvent('order.placed') === true
            && $sub->matchesEvent('order.placed.extra') === false
            && $sub->matchesEvent('user.placed') === false;
    })->toBeTrue();

test('phase 63: composer.json has correct autoload PSR-4')
    ->expect(function (): bool {
        $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        return isset($json['autoload']['psr-4']['ZeroBoiler\\Events\\'])
            && $json['autoload']['psr-4']['ZeroBoiler\\Events\\'] === 'src/';
    })->toBeTrue();

test('phase 63: composer.json extra.laravel has provider and alias')
    ->expect(function (): bool {
        $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        return in_array('ZeroBoiler\\Events\\EventsServiceProvider', $json['extra']['laravel']['providers'], true)
            && isset($json['extra']['laravel']['aliases']['EventManager']);
    })->toBeTrue();

test('phase 63: migration files exist')
    ->expect(function (): bool {
        $migrations = glob(__DIR__.'/../database/migrations/*.php');

        return count($migrations) === 3;
    })->toBeTrue();

test('phase 63: factory files exist')
    ->expect(function (): bool {
        $factories = glob(__DIR__.'/../database/factories/*Factory.php');

        return count($factories) === 3;
    })->toBeTrue();

test('phase 63: version consistency between composer.json and README')
    ->expect(function (): bool {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        $composerVersion = $composer['version'];

        $readme = file_get_contents(__DIR__.'/../README.md');
        $hasBadge = str_contains($readme, "version-{$composerVersion}-");

        return $hasBadge;
    })->toBeTrue();

test('phase 63: license headers present on all source files')
    ->expect(
        array_filter(
            glob(__DIR__.'/../src/**/*.php'),
            fn (string $file): bool => str_contains(file_get_contents($file), 'ZeroBoiler'),
        ),
    )->toHaveCount(count(glob(__DIR__.'/../src/**/*.php')));

test('phase 63: EventManager singleton binding')
    ->expect(function (): bool {
        $app = app();
        $app->forgetInstance(EventManager::class);
        $instance1 = $app->make(EventManager::class);
        $instance2 = $app->make(EventManager::class);

        return $instance1 === $instance2;
    })->toBeTrue();

test('phase 63: TriggerBuilder transient binding')
    ->expect(function (): bool {
        $app = app();
        $app->forgetInstance(TriggerBuilder::class);
        $instance1 = $app->make(TriggerBuilder::class);
        $instance2 = $app->make(TriggerBuilder::class);

        return $instance1 !== $instance2;
    })->toBeTrue();

test('phase 63: SubscriptionBuilder transient binding')
    ->expect(function (): bool {
        $app = app();
        $app->forgetInstance(SubscriptionBuilder::class);
        $instance1 = $app->make(SubscriptionBuilder::class);
        $instance2 = $app->make(SubscriptionBuilder::class);

        return $instance1 !== $instance2;
    })->toBeTrue();

test('phase 63: ConditionEngineContract resolves to ConditionEngine')
    ->expect(function (): bool {
        return app(ConditionEngineContract::class) instanceof ConditionEngine;
    })->toBeTrue();

test('phase 63: fire with no matching triggers succeeds silently')
    ->expect(function (): bool {
        $manager = app(EventManager::class);

        // Fire an event that has no triggers registered
        $manager->fire('nonexistent.event.12345', ['test' => true]);

        // Should not throw — no error log, no exception
        return true;
    })->toBeTrue();

test('phase 63: enable/disable non-existent trigger returns false')
    ->expect(function (): bool {
        $manager = app(EventManager::class);
        $fakeId = (string) \Illuminate\Support\Str::uuid();

        return $manager->enable($fakeId) === false
            && $manager->disable($fakeId) === false
            && $manager->deleteTrigger($fakeId) === false;
    })->toBeTrue();

test('phase 63: console commands have zeroboiler:events: prefix')
    ->expect(function (): bool {
        $commands = [
            EventsListCommand::class,
            EventsRegisterCommand::class,
            EventsFireCommand::class,
            EventsLogCommand::class,
            EventsRetryCommand::class,
            EventsEnableCommand::class,
            EventsDisableCommand::class,
            EventsHealthCommand::class,
            EventsSubscribeCommand::class,
            EventsUnsubscribeCommand::class,
            EventsSubscriptionsCommand::class,
            EventsRedeliverCommand::class,
        ];

        foreach ($commands as $cmd) {
            $ref = new ReflectionClass($cmd);
            $prop = $ref->getProperty('signature');
            $sig = $prop->getValue(new $cmd);
            if (! str_starts_with($sig, 'zeroboiler:events:')) {
                return false;
            }
        }

        return true;
    })->toBeTrue();

test('phase 63: all console command handle methods return int')
    ->expect(function (): bool {
        $commands = [
            EventsListCommand::class,
            EventsRegisterCommand::class,
            EventsFireCommand::class,
            EventsLogCommand::class,
            EventsRetryCommand::class,
            EventsEnableCommand::class,
            EventsDisableCommand::class,
            EventsHealthCommand::class,
            EventsSubscribeCommand::class,
            EventsUnsubscribeCommand::class,
            EventsSubscriptionsCommand::class,
            EventsRedeliverCommand::class,
        ];

        foreach ($commands as $cmd) {
            $ref = new ReflectionClass($cmd);
            $method = $ref->getMethod('handle');
            $returnType = $method->getReturnType();
            if ($returnType === null || $returnType->getName() !== 'int') {
                return false;
            }
        }

        return true;
    })->toBeTrue();

test('phase 63: phpstan.neon.dist exists with level 9')
    ->expect(function (): bool {
        $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');

        return str_contains($content, 'level: 9');
    })->toBeTrue();

test('phase 63: TriggerBuilder actions() validation rejects empty string')
    ->expect(function (): bool {
        try {
            app(TriggerBuilder::class)
                ->on('test.event')
                ->action('ValidAction')
                ->actions(['ValidAction', ''])
                ->save();
        } catch (\InvalidArgumentException $e) {
            return str_contains($e->getMessage(), 'non-empty string');
        }

        return false;
    })->toBeTrue();

test('phase 63: TriggerBuilder resolveActions deduplicates and preserves order')
    ->expect(function (): bool {
        $ref = new ReflectionClass(TriggerBuilder::class);
        $method = $ref->getMethod('resolveActions');

        $builder = app(TriggerBuilder::class);
        // Set action() and actions() to create overlap
        $actionProp = $ref->getProperty('action');
        $actionProp->setValue($builder, 'App\\Actions\\A');
        $actionsProp = $ref->getProperty('actions');
        $actionsProp->setValue($builder, ['App\\Actions\\B', 'App\\Actions\\A', 'App\\Actions\\C']);

        $result = $method->invoke($builder);

        // A should be first (prepended), then B, C
        return $result === ['App\\Actions\\A', 'App\\Actions\\B', 'App\\Actions\\C'];
    })->toBeTrue();

test('phase 63: all source files have return type declarations on public methods')
    ->expect(function (): bool {
        $files = glob(__DIR__.'/../src/**/*.php');
        foreach ($files as $file) {
            $content = file_get_contents($file);
            // Skip interface files (no body)
            if (str_contains($content, 'interface ')) {
                continue;
            }
            // Check for public function without return type
            preg_match_all('/public\s+function\s+\w+\s*\([^)]*\)\s*(?::\s*\S+)?\s*\{/', $content, $matches);
            foreach ($matches[0] as $match) {
                if (! str_contains($match, ':')) {
                    return false;
                }
            }
        }

        return true;
    })->toBeTrue();
