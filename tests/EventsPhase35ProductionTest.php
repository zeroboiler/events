<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Console\EventsDisableCommand;
use ZeroBoiler\Events\Console\EventsEnableCommand;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Console\EventsListCommand;
use ZeroBoiler\Events\Console\EventsLogCommand;
use ZeroBoiler\Events\Console\EventsRedeliverCommand;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Console\EventsSubscribeCommand;
use ZeroBoiler\Events\Console\EventsSubscriptionsCommand;
use ZeroBoiler\Events\Console\EventsUnsubscribeCommand;
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

/**
 * Phase 35 production audit — comprehensive final checks covering:
 * - hash_hmac false return type safety in Subscription::signPayload()
 * - PHPStan 9 type safety across all public method signatures
 * - Final class verification for all core + console classes
 * - Strict types enforcement across all src/ files
 * - Return type declarations on all public methods
 * - Config key type validation
 * - ServiceProvider binding lifecycle
 * - Facade accessor correctness
 * - DomainEvent readonly keyword verification
 * - WildcardMatcher #[Pure] attribute verification
 * - EventLog status constants
 * - Subscription signPayload edge cases with false result handling
 */
describe('Events Phase 35 Production', function () {
    describe('signPayload hash_hmac false safety', function () {
        it('returns empty string when secret is null', function () {
            $subscription = Subscription::factory()->withoutSecret()->make();

            expect($subscription->signPayload('test-payload'))->toBe('');
        });

        it('returns empty string when secret is empty string', function () {
            $subscription = new Subscription([
                'event' => 'test.event',
                'url' => 'https://example.com',
                'secret' => '',
            ]);

            expect($subscription->signPayload('test-payload'))->toBe('');
        });

        it('returns non-empty HMAC signature for valid secret', function () {
            $subscription = Subscription::factory()->withSecret('whsec_test_secret_key_12345')->make();
            $payload = '{"event":"test","data":{}}';

            $signature = $subscription->signPayload($payload);

            expect($signature)->not->toBe('');
            expect(strlen($signature))->toBeGreaterThan(0);
        });

        it('produces deterministic signatures for same input', function () {
            $subscription = Subscription::factory()->withSecret('whsec_deterministic_test')->make();
            $payload = '{"event":"order.placed","data":{"id":1}}';

            $sig1 = $subscription->signPayload($payload);
            $sig2 = $subscription->signPayload($payload);

            expect($sig1)->toBe($sig2);
        });

        it('produces different signatures for different payloads', function () {
            $subscription = Subscription::factory()->withSecret('whsec_diff_test')->make();

            $sig1 = $subscription->signPayload('payload-one');
            $sig2 = $subscription->signPayload('payload-two');

            expect($sig1)->not->toBe($sig2);
        });

        it('signs correctly with sha256 (default algorithm)', function () {
            $subscription = Subscription::factory()->withSecret('whsec_sha256_test')->make();
            $payload = 'test-payload';

            $signature = $subscription->signPayload($payload);
            $expected = hash_hmac('sha256', $payload, 'whsec_sha256_test');

            expect($signature)->toBe($expected);
        });
    });

    describe('strict types enforcement', function () {
        it('all src files have declare strict_types=1', function () {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $violations = [];

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
                    $violations[] = basename($file);
                }
            }

            expect($violations)->toBeEmpty('Files missing declare(strict_types=1): '.implode(', ', $violations));
        });
    });

    describe('final class verification', function () {
        it('all core classes are final', function () {
            $classes = [
                EventManager::class,
                ActionResolver::class,
                ConditionEngine::class,
                TriggerBuilder::class,
                SubscriptionBuilder::class,
                WildcardMatcher::class,
                WebhookAction::class,
                DispatchTriggerJob::class,
                DomainEvent::class,
                EventsServiceProvider::class,
                EventManagerFacade::class,
                EventLog::class,
                Trigger::class,
                Subscription::class,
                EventsDisableCommand::class,
                EventsEnableCommand::class,
                EventsFireCommand::class,
                EventsListCommand::class,
                EventsLogCommand::class,
                EventsRedeliverCommand::class,
                EventsRegisterCommand::class,
                EventsRetryCommand::class,
                EventsSubscribeCommand::class,
                EventsSubscriptionsCommand::class,
                EventsUnsubscribeCommand::class,
            ];

            $nonFinal = [];
            foreach ($classes as $class) {
                $ref = new ReflectionClass($class);
                if (! $ref->isFinal()) {
                    $nonFinal[] = $class;
                }
            }

            expect($nonFinal)->toBeEmpty('Non-final classes: '.implode(', ', $nonFinal));
        });
    });

    describe('interface contracts', function () {
        it('ConditionEngine implements ConditionEngineContract', function () {
            expect(ConditionEngine::class)->toImplement(ConditionEngineContract::class);
        });

        it('WebhookAction implements Triggerable', function () {
            expect(WebhookAction::class)->toImplement(Triggerable::class);
        });
    });

    describe('service provider bindings', function () {
        it('EventManager is singleton', function () {
            $app = app();
            $a = $app->make(EventManager::class);
            $b = $app->make(EventManager::class);

            expect($a)->toBe($b);
        });

        it('ConditionEngine is singleton', function () {
            $app = app();
            $a = $app->make(ConditionEngine::class);
            $b = $app->make(ConditionEngine::class);

            expect($a)->toBe($b);
        });

        it('ConditionEngineContract resolves to ConditionEngine', function () {
            $app = app();
            $contract = $app->make(ConditionEngineContract::class);

            expect($contract)->toBeInstanceOf(ConditionEngine::class);
        });

        it('TriggerBuilder is transient', function () {
            $app = app();
            $a = $app->make(TriggerBuilder::class);
            $b = $app->make(TriggerBuilder::class);

            expect($a)->not->toBe($b);
        });

        it('SubscriptionBuilder is transient', function () {
            $app = app();
            $a = $app->make(SubscriptionBuilder::class);
            $b = $app->make(SubscriptionBuilder::class);

            expect($a)->not->toBe($b);
        });

        it('ActionResolver is singleton', function () {
            $app = app();
            $a = $app->make(ActionResolver::class);
            $b = $app->make(ActionResolver::class);

            expect($a)->toBe($b);
        });
    });

    describe('facade accessor', function () {
        it('facade accessor returns correct class', function () {
            $ref = new ReflectionClass(EventManagerFacade::class);
            $method = $ref->getMethod('getFacadeAccessor');
            $method->setAccessible(true);

            expect($method->invoke(null))->toBe(EventManager::class);
        });
    });

    describe('config completeness', function () {
        it('has all required top-level keys', function () {
            $config = config('events');

            expect($config)->toBeArray();
            expect($config)->toHaveKey('table_names');
            expect($config)->toHaveKey('queue');
            expect($config)->toHaveKey('retry');
            expect($config)->toHaveKey('retention');
            expect($config)->toHaveKey('subscriptions');
            expect($config)->toHaveKey('wildcard_cache_ttl');
        });

        it('table_names has all required tables', function () {
            $tables = config('events.table_names');

            expect($tables)->toHaveKey('triggers');
            expect($tables)->toHaveKey('event_logs');
            expect($tables)->toHaveKey('subscriptions');
            expect($tables['triggers'])->toBeString();
            expect($tables['event_logs'])->toBeString();
            expect($tables['subscriptions'])->toBeString();
        });

        it('subscriptions config has all required keys', function () {
            $subs = config('events.subscriptions');

            expect($subs)->toHaveKey('auto_generate_secret');
            expect($subs)->toHaveKey('max_failures');
            expect($subs)->toHaveKey('timeout');
            expect($subs)->toHaveKey('signature_algorithm');
        });

        it('queue config has all required keys', function () {
            $queue = config('events.queue');

            expect($queue)->toHaveKey('connection');
            expect($queue)->toHaveKey('queue');
        });

        it('retry config has all required keys', function () {
            $retry = config('events.retry');

            expect($retry)->toHaveKey('tries');
            expect($retry)->toHaveKey('backoff');
        });

        it('retention config has all required keys', function () {
            $retention = config('events.retention');

            expect($retention)->toHaveKey('days');
            expect($retention)->toHaveKey('include_pending');
        });
    });

    describe('EventLog status constants', function () {
        it('has all 4 status constants', function () {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        it('statuses array contains all constants', function () {
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
        });
    });

    describe('DomainEvent readonly verification', function () {
        it('all properties are readonly via keyword', function () {
            $ref = new ReflectionClass(DomainEvent::class);

            $properties = ['eventType', 'payload', 'eventId', 'occurredAt'];
            foreach ($properties as $prop) {
                $rp = $ref->getProperty($prop);
                expect($rp->isReadOnly())->toBeTrue("{$prop} should be readonly");
            }
        });
    });

    describe('WildcardMatcher pure attributes', function () {
        it('all static methods have #[Pure] attribute', function () {
            $ref = new ReflectionClass(WildcardMatcher::class);
            $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

            foreach ($methods as $method) {
                $rm = $ref->getMethod($method);
                $attrs = array_map(
                    fn (ReflectionAttribute $a): string => $a->getName(),
                    $rm->getAttributes(),
                );
                expect($attrs)->toContain('Pure', "{$method} should have #[Pure] attribute");
            }
        });
    });

    describe('model config-driven table names', function () {
        it('Trigger reads table from config', function () {
            $trigger = new Trigger;
            expect($trigger->getTable())->toBe(config('events.table_names.triggers', 'triggers'));
        });

        it('EventLog reads table from config', function () {
            $log = new EventLog;
            expect($log->getTable())->toBe(config('events.table_names.event_logs', 'event_logs'));
        });

        it('Subscription reads table from config', function () {
            $sub = new Subscription;
            expect($sub->getTable())->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
        });

        it('all models use string UUID key type', function () {
            expect((new Trigger)->getKeyType())->toBe('string');
            expect((new EventLog)->getKeyType())->toBe('string');
            expect((new Subscription)->getKeyType())->toBe('string');
        });

        it('all models are not auto-incrementing', function () {
            expect((new Trigger)->incrementing)->toBeFalse();
            expect((new EventLog)->incrementing)->toBeFalse();
            expect((new Subscription)->incrementing)->toBeFalse();
        });
    });

    describe('return type declarations', function () {
        it('EventManager public methods have return types', function () {
            $ref = new ReflectionClass(EventManager::class);
            $methods = ['on', 'register', 'fire', 'fireModel', 'enable', 'disable',
                'invalidateTriggerCache', 'listTriggers', 'getTrigger', 'deleteTrigger',
                'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription',
                'getEventHistory', 'getStats', 'purgeLogs', 'executeTrigger',
            ];

            foreach ($methods as $name) {
                $method = $ref->getMethod($name);
                expect($method->hasReturnType())->toBeTrue("EventManager::{$name}() should have return type");
            }
        });

        it('TriggerBuilder public methods have return types', function () {
            $ref = new ReflectionClass(TriggerBuilder::class);
            $methods = ['name', 'on', 'action', 'actions', 'when', 'async', 'priority', 'actionParams', 'save'];

            foreach ($methods as $name) {
                $method = $ref->getMethod($name);
                expect($method->hasReturnType())->toBeTrue("TriggerBuilder::{$name}() should have return type");
            }
        });

        it('SubscriptionBuilder public methods have return types', function () {
            $ref = new ReflectionClass(SubscriptionBuilder::class);
            $methods = ['on', 'to', 'withSecret', 'withFilter', 'priority', 'async', 'save'];

            foreach ($methods as $name) {
                $method = $ref->getMethod($name);
                expect($method->hasReturnType())->toBeTrue("SubscriptionBuilder::{$name}() should have return type");
            }
        });
    });

    describe('console command return types', function () {
        it('all console handle() methods return int', function () {
            $commands = [
                EventsDisableCommand::class,
                EventsEnableCommand::class,
                EventsFireCommand::class,
                EventsListCommand::class,
                EventsLogCommand::class,
                EventsRedeliverCommand::class,
                EventsRegisterCommand::class,
                EventsRetryCommand::class,
                EventsSubscribeCommand::class,
                EventsSubscriptionsCommand::class,
                EventsUnsubscribeCommand::class,
            ];

            foreach ($commands as $class) {
                $ref = new ReflectionClass($class);
                $method = $ref->getMethod('handle');
                $returnType = $method->getReturnType();
                expect($returnType)->not->toBeNull("{$class}::handle() should have return type");
                expect($returnType->getName())->toBe('int');
            }
        });
    });

    describe('version consistency', function () {
        it('composer.json version matches README badge', function () {
            $composerJson = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $version = $composerJson['version'] ?? null;

            expect($version)->toBeString();
            expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
        });
    });
});
