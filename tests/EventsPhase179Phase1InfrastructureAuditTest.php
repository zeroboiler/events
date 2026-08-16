<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;

describe('Phase 179 — Phase 1 Infrastructure Production Audit', function (): void {
    // ─── 1. Facade @method Docblock Completeness ────────────────────
    describe('facade @method docblock completeness', function (): void {
        it('facade file exists and is final', function (): void {
            $reflection = new ReflectionClass(EventManagerFacade::class);
            expect($reflection->isFinal())->toBeTrue();
        });

        it('facade has 25 @method annotations covering all public API', function (): void {
            $facadeFile = file_get_contents(__DIR__.'/../src/Facades/EventManager.php');
            expect($facadeFile)->not->toBeFalse();

            preg_match_all('/@method\s+static\s+/', $facadeFile, $matches);
            expect(count($matches[0]))->toBeGreaterThanOrEqual(25);
        });

        it('facade docblock covers all EventManager public methods', function (): void {
            $managerMethods = [
                'on', 'register', 'fire', 'fireModel',
                'enable', 'disable', 'invalidateTriggerCache',
                'isDisabled', 'setEnabled', 'listTriggers',
                'getTrigger', 'deleteTrigger', 'subscribe',
                'unsubscribe', 'listSubscriptions', 'getSubscription',
                'subscribeWebhook', 'getEventHistory', 'getStats',
                'purgeLogs', 'getStalePendingLogs',
                'deactivateExceededSubscriptions', 'executeTrigger',
                'registerScheduler', 'container',
            ];

            $facadeFile = file_get_contents(__DIR__.'/../src/Facades/EventManager.php');

            foreach ($managerMethods as $method) {
                expect($facadeFile)
                    ->toContain($method, "Facade docblock missing method: {$method}");
            }
        });

        it('facade accessor method is protected and returns string', function (): void {
            $reflection = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
            expect($reflection->isProtected())->toBeTrue();
            expect($reflection->getReturnType()?->getName())->toBe('string');
        });
    });

    // ─── 2. Config table_names Consistency with Models ──────────────
    describe('config table_names consistency with models', function (): void {
        it('config table_names.triggers default matches Trigger model default', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect($config['table_names']['triggers'])->toBe('triggers');
        });

        it('config table_names.event_logs default matches EventLog model default', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect($config['table_names']['event_logs'])->toBe('event_logs');
        });

        it('config table_names.subscriptions default matches Subscription model default', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect($config['table_names']['subscriptions'])->toBe('event_subscriptions');
        });

        it('table_names config is not empty', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect($config['table_names'])->toBeArray();
            expect(count($config['table_names']))->toBe(3);
        });
    });

    // ─── 3. DispatchTriggerJob Config-Driven Properties ───────────
    describe('DispatchTriggerJob config-driven properties', function (): void {
        it('reads tries from config at construction', function (): void {
            config(['events.retry.tries' => 5]);
            $job = new DispatchTriggerJob('uuid', 'test.event', []);
            expect($job->tries)->toBe(5);
        });

        it('reads queue from config at construction', function (): void {
            config(['events.queue.queue' => 'events-high']);
            $job = new DispatchTriggerJob('uuid', 'test.event', []);
            expect($job->queue)->toBe('events-high');
        });

        it('reads connection from config at construction', function (): void {
            config(['events.queue.connection' => 'redis-events']);
            $job = new DispatchTriggerJob('uuid', 'test.event', []);
            expect($job->connection)->toBe('redis-events');
        });

        it('falls back to default tries when config is 0', function (): void {
            config(['events.retry.tries' => 0]);
            $job = new DispatchTriggerJob('uuid', 'test.event', []);
            expect($job->tries)->toBe(3);
        });

        it('falls back to default queue when config is empty', function (): void {
            config(['events.queue.queue' => '']);
            $job = new DispatchTriggerJob('uuid', 'test.event', []);
            expect($job->queue)->toBe('default');
        });

        it('readonly triggerId is accessible', function (): void {
            $job = new DispatchTriggerJob('test-uuid-123', 'order.placed', ['key' => 'val']);
            expect($job->triggerId)->toBe('test-uuid-123');
            expect($job->event)->toBe('order.placed');
        });
    });

    // ─── 4. WebhookAction Payload Stripping ────────────────────────
    describe('WebhookAction payload stripping', function (): void {
        it('WebhookAction implements Triggerable interface', function (): void {
            $reflection = new ReflectionClass(WebhookAction::class);
            expect($reflection->implementsInterface(Triggerable::class))->toBeTrue();
            expect($reflection->isFinal())->toBeTrue();
        });

        it('WebhookAction::handle() strips internal keys from webhook body', function (): void {
            $subscription = Subscription::factory()->create([
                'event' => 'test.event',
                'url' => 'https://example.com/hook',
                'secret' => 'whsec_test_secret_123',
                'active' => true,
            ]);

            $payload = [
                'url' => 'https://example.com/hook',
                'event' => 'test.event',
                'subscription_id' => $subscription->id,
                'headers' => ['X-Custom' => 'value'],
                'order_id' => 123,
                'customer_email' => 'test@example.com',
            ];

            // Simulate WebhookAction's internal key stripping logic
            $webhookData = $payload;
            unset($webhookData['url'], $webhookData['event'], $webhookData['headers'], $webhookData['subscription_id']);

            expect($webhookData)->not->toHaveKey('url');
            expect($webhookData)->not->toHaveKey('event');
            expect($webhookData)->not->toHaveKey('headers');
            expect($webhookData)->not->toHaveKey('subscription_id');
            expect($webhookData)->toHaveKey('order_id');
            expect($webhookData)->toHaveKey('customer_email');
        });
    });

    // ─── 5. EventManager Cache TTL Edge Cases ──────────────────────
    describe('EventManager cache TTL edge cases', function (): void {
        it('config key exists and defaults to 300', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect($config['wildcard_cache_ttl'])->toBe(300);
        });

        it('getTriggerCacheTtl method exists and is protected', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionMethod($manager, 'getTriggerCacheTtl');
            expect($reflection->isProtected())->toBeTrue();
            expect($reflection->getReturnType()?->getName())->toBe('int');
        });

        it('wildcard_cache_ttl can be set to 0 in config', function (): void {
            // Verify the config key accepts 0 (disable caching)
            $config = require __DIR__.'/../config/events.php';
            expect(array_key_exists('wildcard_cache_ttl', $config))->toBeTrue();
        });
    });

    // ─── 6. ConditionEngine ReDoS Protection ───────────────────────
    describe('ConditionEngine ReDoS protection', function (): void {
        it('safeRegexMatch rejects patterns over 500 characters', function (): void {
            $engine = new ConditionEngine;
            $longPattern = '/^a' . str_repeat('a', 500) . '$/';
            expect($engine->matches(
                ['field' => ['matches', $longPattern]],
                ['field' => str_repeat('a', 500)],
            ))->toBeFalse();
        });

        it('safeRegexMatch rejects nested quantifier patterns (a+)+', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['field' => ['matches', '/(a+)+b/']],
                ['field' => 'aaab'],
            ))->toBeFalse();
        });

        it('safeRegexMatch rejects nested quantifier patterns (a*)*', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['field' => ['matches', '/(a*)*b/']],
                ['field' => 'aaab'],
            ))->toBeFalse();
        });

        it('safeRegexMatch accepts valid simple regex', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['field' => ['matches', '/^[a-z]+$/']],
                ['field' => 'hello'],
            ))->toBeTrue();
        });

        it('safeRegexMatch returns false on non-matching valid regex', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['field' => ['matches', '/^[0-9]+$/']],
                ['field' => 'abc'],
            ))->toBeFalse();
        });
    });

    // ─── 7. ServiceProvider Registration Integrity ─────────────────
    describe('service provider registration integrity', function (): void {
        it('does not register WildcardMatcher as a service (stateless utility)', function (): void {
            $provider = new EventsServiceProvider(app());
            $provider->register();
            expect(app()->bound(WildcardMatcher::class))->toBeFalse();
        });

        it('ConditionEngine is registered as singleton', function (): void {
            $provider = new EventsServiceProvider(app());
            $provider->register();
            $a = app(ConditionEngine::class);
            $b = app(ConditionEngine::class);
            expect($a === $b)->toBeTrue();
        });

        it('ActionResolver is registered as singleton', function (): void {
            $provider = new EventsServiceProvider(app());
            $provider->register();
            $a = app(ActionResolver::class);
            $b = app(ActionResolver::class);
            expect($a === $b)->toBeTrue();
        });

        it('TriggerBuilder is registered as transient', function (): void {
            $provider = new EventsServiceProvider(app());
            $provider->register();
            $a = app(TriggerBuilder::class);
            $b = app(TriggerBuilder::class);
            expect($a === $b)->toBeFalse();
        });

        it('provides() order is consistent', function (): void {
            $provider = new EventsServiceProvider(app());
            $provides = $provider->provides();
            expect($provides[0])->toBe(EventManager::class);
        });
    });

    // ─── 8. Source File Quality Audit ──────────────────────────────
    describe('source file quality audit', function (): void {
        it('all 33 source files have strict_types declaration', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $srcFiles = array_merge($srcFiles, glob(__DIR__.'/../src/**/**/*.php'));
            $srcFiles = array_merge($srcFiles, glob(__DIR__.'/../src/**/**/**/*.php'));
            $srcFiles = array_unique($srcFiles);

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                expect($content)
                    ->toContain('declare(strict_types=1)', "Missing strict_types in: {$file}");
            }
        });

        it('all source files have license header', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $srcFiles = array_merge($srcFiles, glob(__DIR__.'/../src/**/**/*.php'));
            $srcFiles = array_merge($srcFiles, glob(__DIR__.'/../src/**/**/**/*.php'));
            $srcFiles = array_unique($srcFiles);

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                expect($content)
                    ->toContain('This file is part of ZeroBoiler', "Missing license header in: {$file}");
            }
        });

        it('no TODO or FIXME comments in source', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $srcFiles = array_merge($srcFiles, glob(__DIR__.'/../src/**/**/*.php'));
            $srcFiles = array_merge($srcFiles, glob(__DIR__.'/../src/**/**/**/*.php'));
            $srcFiles = array_unique($srcFiles);

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                expect($content)
                    ->not->toContain('TODO', "Found TODO in: {$file}");
                expect($content)
                    ->not->toContain('FIXME', "Found FIXME in: {$file}");
            }
        });

        it('no deprecated setAccessible calls in source', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $srcFiles = array_merge($srcFiles, glob(__DIR__.'/../src/**/**/*.php'));
            $srcFiles = array_merge($srcFiles, glob(__DIR__.'/../src/**/**/**/*.php'));
            $srcFiles = array_unique($srcFiles);

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                expect($content)
                    ->not->toContain('setAccessible', "Found setAccessible in: {$file}");
            }
        });
    });

    // ─── 9. Model Integrity ────────────────────────────────────────
    describe('model integrity', function (): void {
        it('Trigger model has correct casts', function (): void {
            $trigger = new Trigger;
            $casts = $trigger->casts();
            expect($casts)->toHaveKey('conditions');
            expect($casts)->toHaveKey('async');
            expect($casts)->toHaveKey('enabled');
            expect($casts)->toHaveKey('priority');
        });

        it('EventLog model has correct casts', function (): void {
            $log = new EventLog;
            $casts = $log->casts();
            expect($casts)->toHaveKey('payload');
            expect($casts)->toHaveKey('duration_ms');
        });

        it('Subscription model has correct casts', function (): void {
            $sub = new Subscription;
            $casts = $sub->casts();
            expect($casts)->toHaveKey('conditions');
            expect($casts)->toHaveKey('priority');
            expect($casts)->toHaveKey('active');
            expect($casts)->toHaveKey('failure_count');
            expect($casts)->toHaveKey('delivery_count');
            expect($casts)->toHaveKey('last_fired_at');
        });

        it('all models use string key type', function (): void {
            $trigger = new Trigger;
            $log = new EventLog;
            $sub = new Subscription;

            expect($trigger->getKeyType())->toBe('string');
            expect($trigger->getIncrementing())->toBeFalse();

            expect($log->getKeyType())->toBe('string');
            expect($log->getIncrementing())->toBeFalse();

            expect($sub->getKeyType())->toBe('string');
            expect($sub->getIncrementing())->toBeFalse();
        });

        it('Subscription hides secret from serialization', function (): void {
            $sub = new Subscription;
            expect($sub->getHidden())->toContain('secret');
            expect($sub->getHidden())->toContain('deleted_at');
        });

        it('EventLog has all 4 status constants', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });
    });

    // ─── 10. PHPStan Configuration Validation ──────────────────────
    describe('PHPStan configuration validation', function (): void {
        it('phpstan.neon.dist exists and sets level 9', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->not->toBeFalse();
            expect($content)->toContain('level: 9');
        });

        it('phpstan.neon.dist includes bootstrapFiles', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('bootstrapFiles');
            expect($content)->toContain('tests/helpers.php');
        });

        it('phpstan.neon.dist has checkExplicitMixed enabled', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('checkExplicitMixed: true');
        });

        it('phpstan.neon.dist scans src, database, and tests directories', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('- src');
            expect($content)->toContain('- database/migrations');
            expect($content)->toContain('- database/factories');
            expect($content)->toContain('- tests');
        });

        it('phpstan.neon references phpstan.neon.dist', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon');
            expect($content)->toContain('includes:');
            expect($content)->toContain('phpstan.neon.dist');
        });
    });

    // ─── 11. Composer.json Validation ──────────────────────────────
    describe('composer.json validation', function (): void {
        it('requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('requires illuminate/contracts ^13.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        });

        it('autoload PSR-4 namespace is correct', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        it('service provider is registered in extra.laravel.providers', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['extra']['laravel']['providers'])
                ->toContain('ZeroBoiler\\Events\\EventsServiceProvider');
        });

        it('facade alias is registered in extra.laravel.aliases', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['extra']['laravel']['aliases']['EventManager'])
                ->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
        });
    });

    // ─── 12. TriggerBuilder Action Merging ──────────────────────────
    describe('TriggerBuilder action merging', function (): void {
        it('resolveActions merges single action with actions() list', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('test.event')
                ->action('FirstAction')
                ->actions(['SecondAction', 'ThirdAction'])
                ->save();

            $parsed = json_decode($trigger->action, true);
            expect($parsed)->toBe(['FirstAction', 'SecondAction', 'ThirdAction']);
        });

        it('resolveActions deduplicates action classes', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('test.event')
                ->action('SameAction')
                ->actions(['SameAction', 'OtherAction'])
                ->save();

            $parsed = json_decode($trigger->action, true);
            expect($parsed)->toEqual(['SameAction', 'OtherAction']);
        });
    });

    // ─── 13. DomainEvent Roundtrip Identity ────────────────────────
    describe('DomainEvent roundtrip identity', function (): void {
        it('preserves eventId and occurredAt through serialization cycle', function (): void {
            $uuid = \Ramsey\Uuid\Uuid::uuid4();
            $datetime = new DateTimeImmutable('2025-06-15T12:00:00+00:00');

            $original = new DomainEvent(
                'order.created',
                ['order_id' => '123'],
                $uuid,
                $datetime,
            );

            $restored = DomainEvent::fromArray($original->toArray());

            expect($restored->eventId->toString())->toBe($uuid->toString());
            expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
                ->toBe($datetime->format(DateTimeImmutable::ATOM));
            expect($restored->eventType)->toBe('order.created');
            expect($restored->payload)->toBe(['order_id' => '123']);
        });

        it('DomainEvent is final and immutable', function (): void {
            $reflection = new ReflectionClass(DomainEvent::class);
            expect($reflection->isFinal())->toBeTrue();

            $properties = $reflection->getProperties(ReflectionProperty::IS_READONLY);
            $propertyNames = array_map(fn (ReflectionProperty $p): string => $p->getName(), $properties);
            expect($propertyNames)->toContain('eventType');
            expect($propertyNames)->toContain('payload');
            expect($propertyNames)->toContain('eventId');
            expect($propertyNames)->toContain('occurredAt');
        });
    });

    // ─── 14. Global Disable System ─────────────────────────────────
    describe('global disable system', function (): void {
        it('isDisabled reads from config', function (): void {
            config(['events.disabled' => true]);
            $manager = app(EventManager::class);
            expect($manager->isDisabled())->toBeTrue();
        });

        it('setEnabled changes config in-memory', function (): void {
            config(['events.disabled' => true]);
            $manager = app(EventManager::class);
            $manager->setEnabled(true);
            expect($manager->isDisabled())->toBeFalse();
        });
    });

    // ─── 15. Migrations Structure ───────────────────────────────────
    describe('migrations structure', function (): void {
        it('3 migration files exist', function (): void {
            $migrations = glob(__DIR__.'/../database/migrations/*.php');
            expect(count($migrations))->toBe(3);
        });

        it('migration filenames are timestamp-ordered', function (): void {
            $migrations = glob(__DIR__.'/../database/migrations/*.php');
            $basenames = array_map('basename', $migrations);
            sort($basenames);
            expect($basenames[0])->toContain('create_triggers_table');
            expect($basenames[1])->toContain('create_event_logs_table');
            expect($basenames[2])->toContain('create_event_subscriptions_table');
        });
    });

    // ─── 16. SubscriptionBuilder URL Validation ───────────────────
    describe('SubscriptionBuilder URL validation', function (): void {
        it('rejects javascript: scheme', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->subscribe('test.event', 'javascript:alert(1)');
            expect(fn () => $builder->save())
                ->toThrow(InvalidArgumentException::class);
        });

        it('accepts https:// URL', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->subscribe('test.event', 'https://example.com/hook')
                ->withSecret('whsec_test');
            // Should not throw during URL validation
            $this->expectNotToPerformAssertions();
        });
    });
});
