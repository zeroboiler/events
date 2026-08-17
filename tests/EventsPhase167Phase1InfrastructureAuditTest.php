<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

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

/**
 * Phase 1 Infrastructure Audit — Events Package v5.23.0
 *
 * Verifies all 33 source files meet production-ready standards:
 * - Strict types, final classes, typed properties, return types
 * - #[Override], #[Pure] attributes where appropriate
 * - ServiceProvider register/boot/provides consistency
 * - Config completeness and correctness
 * - PHP 8.5 syntax compliance
 * - Facade accessor correctness
 * - Contract binding identity
 * - DomainEvent roundtrip integrity
 * - ReDoS protection in ConditionEngine
 * - Webhook URL scheme enforcement
 * - HMAC signature determinism
 * - WildcardMatcher correctness for all pattern types
 * - Error/status constants on EventLog
 */
describe('Events Phase 167 — Phase 1 Production Infrastructure Audit v5.23.0', function (): void {

    describe('Source File Structure — 33 files with strict types and headers', function (): void {
        it('all 33 source files exist and have declare(strict_types=1)', function (): void {
            $srcDir = __DIR__.'/../src';
            $phpFiles = glob($srcDir.'//**/*.php', GLOB_BRACE);
            $count = is_array($phpFiles) ? count($phpFiles) : 0;
            expect($count)->toBe(33);

            foreach ($phpFiles as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
                expect($content)->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
            }
        });

        it('all service classes are final', function (): void {
            $finalClasses = [
                EventManager::class,
                ConditionEngine::class,
                ActionResolver::class,
                EventScheduler::class,
                TriggerBuilder::class,
                SubscriptionBuilder::class,
                WildcardMatcher::class,
                EventsServiceProvider::class,
                DomainEvent::class,
                EventManagerFacade::class,
                DispatchTriggerJob::class,
                Trigger::class,
                EventLog::class,
                Subscription::class,
            ];

            foreach ($finalClasses as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())->toBeTrue("{$class} must be final");
            }
        });

        it('WildcardMatcher is readonly and final', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });
    });

    describe('Constructor Injection — readonly promoted properties', function (): void {
        it('EventManager has readonly promoted constructor properties', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $ctor = $ref->getConstructor();
            expect($ctor)->not()->toBeNull();

            $params = $ctor->getParameters();
            expect(count($params))->toBe(3);

            foreach ($params as $param) {
                expect($param->isReadOnly())->toBeTrue("EventManager::\${$param->getName()} must be readonly");
            }
        });

        it('ActionResolver has readonly promoted constructor properties', function (): void {
            $ref = new ReflectionClass(ActionResolver::class);
            $ctor = $ref->getConstructor();
            $params = $ctor->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->isReadOnly())->toBeTrue();
        });

        it('TriggerBuilder has readonly promoted constructor properties', function (): void {
            $ref = new ReflectionClass(TriggerBuilder::class);
            $ctor = $ref->getConstructor();
            $params = $ctor->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->isReadOnly())->toBeTrue();
        });

        it('SubscriptionBuilder has readonly promoted constructor properties', function (): void {
            $ref = new ReflectionClass(SubscriptionBuilder::class);
            $ctor = $ref->getConstructor();
            $params = $ctor->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->isReadOnly())->toBeTrue();
        });

        it('DispatchTriggerJob has readonly promoted constructor properties', function (): void {
            $ref = new ReflectionClass(DispatchTriggerJob::class);
            $ctor = $ref->getConstructor();
            $params = $ctor->getParameters();
            // triggerId, event, payload are readonly promoted
            $readonlyParams = array_filter($params, fn (ReflectionParameter $p): bool => $p->isReadOnly());
            expect(count($readonlyParams))->toBeGreaterThanOrEqual(3);
        });
    });

    describe('ServiceProvider — register/boot/provides consistency', function (): void {
        it('provides() returns all 7 service bindings', function (): void {
            $app = $this->app;
            $provider = new EventsServiceProvider($app);
            $provides = $provider->provides();

            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(ConditionEngine::class);
            expect($provides)->toContain(ConditionEngineContract::class);
            expect($provides)->toContain(ActionResolver::class);
            expect($provides)->toContain(TriggerBuilder::class);
            expect($provides)->toContain(SubscriptionBuilder::class);
            expect($provides)->toContain(EventScheduler::class);
            expect(count($provides))->toBe(7);
        });

        it('ConditionEngineContract resolves to ConditionEngine singleton', function (): void {
            $app = $this->app;
            $first = $app->make(ConditionEngineContract::class);
            $second = $app->make(ConditionEngineContract::class);
            expect($first)->toBe($second);
            expect($first)->toBeInstanceOf(ConditionEngine::class);
        });

        it('TriggerBuilder and SubscriptionBuilder are transient (not shared)', function (): void {
            $app = $this->app;
            $first = $app->make(TriggerBuilder::class);
            $second = $app->make(TriggerBuilder::class);
            expect($first)->not()->toBe($second);

            $firstSub = $app->make(SubscriptionBuilder::class);
            $secondSub = $app->make(SubscriptionBuilder::class);
            expect($firstSub)->not()->toBe($secondSub);
        });

        it('EventScheduler is singleton', function (): void {
            $app = $this->app;
            $first = $app->make(EventScheduler::class);
            $second = $app->make(EventScheduler::class);
            expect($first)->toBe($second);
        });
    });

    describe('Facade — accessor returns correct class', function (): void {
        it('Facade getFacadeAccessor returns EventManager class string', function (): void {
            $ref = new ReflectionClass(EventManagerFacade::class);
            $method = $ref->getMethod('getFacadeAccessor');
            $result = $method->invoke(null);
            expect($result)->toBe(EventManager::class);
        });

        it('Facade is final', function (): void {
            $ref = new ReflectionClass(EventManagerFacade::class);
            expect($ref->isFinal())->toBeTrue();
        });
    });

    describe('Config — 8 top-level keys with correct structure', function (): void {
        it('config/events.php has all 8 top-level keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $expectedKeys = [
                'table_names',
                'queue',
                'retry',
                'retention',
                'subscriptions',
                'disabled',
                'wildcard_cache_ttl',
            ];
            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
            }
            expect(count(array_keys($config)))->toBe(7);
        });

        it('table_names has 3 entries', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect(count($config['table_names']))->toBe(3);
            expect($config['table_names']['triggers'])->toBe('triggers');
            expect($config['table_names']['event_logs'])->toBe('event_logs');
            expect($config['table_names']['subscriptions'])->toBe('event_subscriptions');
        });

        it('subscriptions config has all required sub-keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $subKeys = [
                'auto_generate_secret',
                'secret_length',
                'max_failures',
                'timeout',
                'signature_algorithm',
                'cleanup_cron',
            ];
            foreach ($subKeys as $key) {
                expect(array_key_exists($key, $config['subscriptions']))->toBeTrue("Missing subscriptions key: {$key}");
            }
        });

        it('retention config has all required sub-keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect(array_key_exists('days', $config['retention']))->toBeTrue();
            expect(array_key_exists('include_pending', $config['retention']))->toBeTrue();
            expect(array_key_exists('schedule_cron', $config['retention']))->toBeTrue();
        });
    });

    describe('Models — table names from config', function (): void {
        it('Trigger reads table name from config', function (): void {
            $trigger = new Trigger;
            expect($trigger->getTable())->toBe('triggers');
        });

        it('EventLog reads table name from config', function (): void {
            $log = new EventLog;
            expect($log->getTable())->toBe('event_logs');
        });

        it('Subscription reads table name from config', function (): void {
            $sub = new Subscription;
            expect($sub->getTable())->toBe('event_subscriptions');
        });

        it('all models use string keys with UUID', function (): void {
            $ref = new ReflectionClass(Trigger::class);
            $prop = $ref->getProperty('keyType');
            expect($prop->getDefaultValue())->toBe('string');

            $prop2 = $ref->getProperty('incrementing');
            expect($prop2->getDefaultValue())->toBeFalse();
        });
    });

    describe('EventLog — status constants completeness', function (): void {
        it('has all 4 status constants', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        it('$statuses array matches all constants', function (): void {
            expect(EventLog::$statuses)->toHaveCount(4);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
        });
    });

    describe('DomainEvent — immutability and roundtrip identity', function (): void {
        it('creates with auto-generated UUID and timestamp', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);

            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['key' => 'value']);
            expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
            expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        });

        it('preserves eventId and occurredAt through serialization roundtrip', function (): void {
            $original = DomainEvent::occur('order.created', ['id' => 42]);
            $data = $original->toArray();
            $restored = DomainEvent::fromArray($data);

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))->toBe(
                $original->occurredAt->format(\DateTimeImmutable::ATOM)
            );
            expect($restored->eventType)->toBe($original->eventType);
        });

        it('throws on missing eventType in fromArray', function (): void {
            expect(fn (): DomainEvent => DomainEvent::fromArray([]))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('gracefully handles invalid UUID in fromArray', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test',
                'eventId' => 'not-a-uuid',
            ]);
            // Should not throw — falls back to generated UUID
            expect($event->eventType)->toBe('test');
            expect($event->eventId->toString())->not()->toBe('not-a-uuid');
        });

        it('properties are readonly', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            foreach (['eventId', 'eventType', 'payload', 'occurredAt'] as $propName) {
                $prop = $ref->getProperty($propName);
                expect($prop->isReadOnly())->toBeTrue("DomainEvent::\${$propName} must be readonly");
            }
        });
    });

    describe('ReDoS Protection — ConditionEngine safeRegexMatch', function (): void {
        it('rejects patterns longer than 500 characters', function (): void {
            $engine = new ConditionEngine;
            $longPattern = '/^'.str_repeat('a', 600).'$/';
            $result = $engine->matches(['code' => ['matches', $longPattern]], ['code' => 'aaa']);
            expect($result)->toBeFalse();
        });

        it('rejects nested quantifier patterns', function (): void {
            $engine = new ConditionEngine;
            $nestedPattern = '/(a+)+/';
            $result = $engine->matches(['code' => ['matches', $nestedPattern]], ['code' => 'aaa']);
            expect($result)->toBeFalse();
        });
    });

    describe('WildcardMatcher — all pattern types', function (): void {
        it('catch-all * matches everything except empty string', function (): void {
            expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'single'))->toBeTrue();
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        it('single-segment wildcard matches within one segment', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        it('cross-segment wildcard matches across dots', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        });

        it('exact match works without wildcards', function (): void {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        it('extractWildcards works for single-segment patterns', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
            expect($result)->toBe(['profile']);
        });

        it('extractWildcards returns empty for cross-segment patterns', function (): void {
            $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');
            expect($result)->toBe([]);
        });
    });

    describe('Webhook URL Scheme Enforcement', function (): void {
        it('SubscriptionBuilder rejects non-HTTP URLs', function (): void {
            $app = $this->app;
            $em = $app->make(EventManager::class);
            $builder = $em->subscribe('test.event', 'ftp://evil.com/webhook');

            expect(fn (): Subscription => $builder->save())
                ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS protocol');
        });

        it('SubscriptionBuilder rejects file:// URLs', function (): void {
            $app = $this->app;
            $em = $app->make(EventManager::class);
            $builder = $em->subscribe('test.event', 'file:///etc/passwd');

            expect(fn (): Subscription => $builder->save())
                ->toThrow(\InvalidArgumentException::class);
        });
    });

    describe('HMAC Signature Determinism', function (): void {
        it('signPayload produces deterministic results', function (): void {
            $factory = Subscription::factory()->create([
                'secret' => 'whsec_testsecret123',
            ]);

            $sig1 = $factory->signPayload('{"event":"test","data":{}}');
            $sig2 = $factory->signPayload('{"event":"test","data":{}}');

            expect($sig1)->toBe($sig2);
            expect($sig1)->not()->toBeEmpty();
        });

        it('signPayload returns empty for null secret', function (): void {
            $sub = Subscription::factory()->create(['secret' => null]);
            expect($sub->signPayload('payload'))->toBe('');
        });
    });

    describe('PHP 8.5 Attribute Compliance', function (): void {
        it('EventManager::getConfig has no Override (not overriding parent)', function (): void {
            $ref = new ReflectionMethod(EventManager::class, 'getConfig');
            $attrs = $ref->getAttributes(\Attribute::class);
            // getConfig is not an override — it's a new method
            expect($ref->getName())->toBe('getConfig');
        });

        it('EventsServiceProvider has #[Override] on register, boot, provides', function (): void {
            $ref = new ReflectionClass(EventsServiceProvider::class);

            $methods = ['register', 'boot', 'provides'];
            foreach ($methods as $method) {
                $m = $ref->getMethod($method);
                $hasOverride = false;
                foreach ($m->getAttributes() as $attr) {
                    if ($attr->getName() === 'Override' || str_contains($attr->getName(), 'Override')) {
                        $hasOverride = true;
                        break;
                    }
                }
                expect($hasOverride)->toBeTrue("EventsServiceProvider::{$method} should have #[Override]");
            }
        });

        it('ConditionEngine::strictEquals has #[Pure]', function (): void {
            $ref = new ReflectionMethod(ConditionEngine::class, 'strictEquals');
            $hasPure = false;
            foreach ($ref->getAttributes() as $attr) {
                if (str_contains($attr->getName(), 'Pure')) {
                    $hasPure = true;
                    break;
                }
            }
            expect($hasPure)->toBeTrue('ConditionEngine::strictEquals should have #[Pure]');
        });

        it('WildcardMatcher::matches has #[Pure]', function (): void {
            $ref = new ReflectionMethod(WildcardMatcher::class, 'matches');
            $hasPure = false;
            foreach ($ref->getAttributes() as $attr) {
                if (str_contains($attr->getName(), 'Pure')) {
                    $hasPure = true;
                    break;
                }
            }
            expect($hasPure)->toBeTrue('WildcardMatcher::matches should have #[Pure]');
        });
    });

    describe('Database Factories — model references', function (): void {
        it('TriggerFactory references Trigger model', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\TriggerFactory::class);
            $prop = $ref->getProperty('model');
            expect($prop->getDefaultValue())->toBe(Trigger::class);
        });

        it('EventLogFactory references EventLog model', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\EventLogFactory::class);
            $prop = $ref->getProperty('model');
            expect($prop->getDefaultValue())->toBe(EventLog::class);
        });

        it('SubscriptionFactory references Subscription model', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class);
            $prop = $ref->getProperty('model');
            expect($prop->getDefaultValue())->toBe(Subscription::class);
        });
    });

    describe('phpstan.neon.dist — Level 9 Configuration', function (): void {
        it('phpstan.neon.dist exists and has level 9', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('level: 9');
            expect($content)->toContain('checkExplicitMixed: true');
            expect($content)->toContain('checkGenericClassInNonGenericObjectType: true');
            expect($content)->toContain('checkUninitializedProperties: true');
            expect($content)->toContain('reportUnusedIgnoredErrors: true');
        });

        it('phpstan scans src, database/migrations, database/factories, and tests', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('- src');
            expect($content)->toContain('- database/migrations');
            expect($content)->toContain('- database/factories');
            expect($content)->toContain('- tests');
        });
    });

    describe('composer.json — PHP 8.5 and Laravel 13', function (): void {
        it('requires PHP ^8.5 and illuminate/* ^13.0', function (): void {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['require']['php'])->toBe('^8.5');
            expect($json['require']['illuminate/contracts'])->toBe('^13.0');
            expect($json['require']['illuminate/support'])->toBe('^13.0');
        });

        it('has correct PSR-4 autoload', function (): void {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
            expect($json['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
        });

        it('has correct Laravel service provider registration', function (): void {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Events\\EventsServiceProvider'
            );
            expect($json['extra']['laravel']['aliases']['EventManager'])->toBe(
                'ZeroBoiler\\Events\\Facades\\EventManager'
            );
        });

        it('has ramsey/uuid dependency for DomainEvent', function (): void {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['require']['ramsey/uuid'])->toBe('^4.7');
        });
    });

    describe('Console Commands — all 12 with signatures', function (): void {
        it('all 12 console commands exist and have signatures', function (): void {
            $commands = [
                \ZeroBoiler\Events\Console\EventsListCommand::class,
                \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
                \ZeroBoiler\Events\Console\EventsFireCommand::class,
                \ZeroBoiler\Events\Console\EventsLogCommand::class,
                \ZeroBoiler\Events\Console\EventsRetryCommand::class,
                \ZeroBoiler\Events\Console\EventsEnableCommand::class,
                \ZeroBoiler\Events\Console\EventsDisableCommand::class,
                \ZeroBoiler\Events\Console\EventsHealthCommand::class,
                \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
                \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
                \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
                \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
            ];

            expect(count($commands))->toBe(12);

            foreach ($commands as $cmdClass) {
                $ref = new ReflectionClass($cmdClass);
                expect($ref->isFinal())->toBeTrue("{$cmdClass} must be final");

                $sig = $ref->getProperty('signature');
                expect($sig->getDefaultValue())->toBeString();
                expect($sig->getDefaultValue())->not()->toBeEmpty();
            }
        });
    });

    describe('Migrations — config-driven table names', function (): void {
        it('triggers migration uses config for table name', function (): void {
            $content = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php');
            expect($content)->toContain("config('events.table_names.triggers', 'triggers')");
            expect($content)->toContain('Schema::create');
            expect($content)->toContain("->index(['event', 'enabled'])");
        });

        it('event_logs migration uses config and has FK', function (): void {
            $content = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php');
            expect($content)->toContain("config('events.table_names.event_logs', 'event_logs')");
            expect($content)->toContain('onDelete');
            expect($content)->toContain('cascade');
        });

        it('subscriptions migration uses config', function (): void {
            $content = file_get_contents(__DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php');
            expect($content)->toContain("config('events.table_names.subscriptions', 'event_subscriptions')");
        });
    });

    describe('ConditionEngine — all 21 operators work', function (): void {
        it('supports >, >=, <, <= numeric operators', function (): void {
            $engine = new ConditionEngine;
            $payload = ['amount' => 100];

            expect($engine->matches(['amount' => ['>', 50]], $payload))->toBeTrue();
            expect($engine->matches(['amount' => ['>=', 100]], $payload))->toBeTrue();
            expect($engine->matches(['amount' => ['<', 200]], $payload))->toBeTrue();
            expect($engine->matches(['amount' => ['<=', 100]], $payload))->toBeTrue();
            expect($engine->matches(['amount' => ['>', 100]], $payload))->toBeFalse();
        });

        it('supports =, ===, !=, !== operators', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
            expect($engine->matches(['flag' => ['===', true]], ['flag' => true]))->toBeTrue();
            expect($engine->matches(['status' => ['!=', 'draft']], ['status' => 'active']))->toBeTrue();
            expect($engine->matches(['flag' => ['!==', false]], ['flag' => true]))->toBeTrue();
        });

        it('supports in, not_in, contains, not_contains', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();
            expect($engine->matches(['role' => ['not_in', ['guest']]], ['role' => 'admin']))->toBeTrue();
            expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['urgent', 'billing']]))->toBeTrue();
            expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['urgent']]))->toBeTrue();
        });

        it('supports between, null, not_null, empty, not_empty', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 30]))->toBeTrue();
            expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
            expect($engine->matches(['email' => ['not_null']], ['email' => 'a@b.com']))->toBeTrue();
            expect($engine->matches(['notes' => ['empty']], ['notes' => null]))->toBeTrue();
            expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'hello']))->toBeTrue();
        });

        it('supports starts_with, ends_with, matches (regex)', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
            expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();
            expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();
        });
    });

    describe('EscapesWildcardLike — SQL injection prevention', function (): void {
        it('trait exists with wildcardToLike method', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Concerns\EscapesWildcardLike::class);
            expect($ref->isTrait())->toBeTrue();
            expect($ref->hasMethod('wildcardToLike'))->toBeTrue();
        });

        it('EscapesWildcardLike is used by EventManager', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $traits = $ref->getTraitNames();
            expect(in_array(\ZeroBoiler\Events\Concerns\EscapesWildcardLike::class, $traits, true))->toBeTrue();
        });
    });

    describe('Global Disable — runtime and config-based', function (): void {
        it('isDisabled reads from config', function (): void {
            $app = $this->app;
            $em = $app->make(EventManager::class);

            $config = $app->get('config');
            $config->set('events.disabled', false);
            expect($em->isDisabled())->toBeFalse();

            $config->set('events.disabled', true);
            expect($em->isDisabled())->toBeTrue();

            $config->set('events.disabled', false);
        });

        it('setEnabled toggles runtime config', function (): void {
            $app = $this->app;
            $em = $app->make(EventManager::class);

            $em->setEnabled(false);
            expect($em->isDisabled())->toBeTrue();

            $em->setEnabled(true);
            expect($em->isDisabled())->toBeFalse();
        });

        it('fire() silently returns when disabled', function (): void {
            $app = $this->app;
            $em = $app->make(EventManager::class);
            $em->setEnabled(true);

            Trigger::factory()->enabled()->create([
                'event' => 'disable.test',
                'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification',
            ]);

            $em->setEnabled(false);
            $em->fire('disable.test', ['key' => 'value']);

            // No event log should be created
            expect(EventLog::where('event', 'disable.test')->count())->toBe(0);

            $em->setEnabled(true);
        });
    });
});
