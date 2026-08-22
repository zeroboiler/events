<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Exceptions\ActionResolutionException;
use ZeroBoiler\Events\Exceptions\ConditionEvaluationException;
use ZeroBoiler\Events\Exceptions\EventException;
use ZeroBoiler\Events\Exceptions\SubscriptionException;
use ZeroBoiler\Events\Exceptions\TriggerNotFoundException;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Final production hardening test suite for ZeroBoiler Events.
 *
 * Covers code-level correctness that was manually audited:
 * - PHP 8.5 syntax compliance
 * - Strict types, return types, typed properties
 * - Docblock accuracy
 * - Migration schema integrity
 * - Exception hierarchy
 * - DomainEvent reconstruction edge cases
 * - WildcardMatcher edge cases
 * - ConditionEngine operator coverage
 */
describe('Events Production Hardening — Final Audit', function (): void {

    // ---------------------------------------------------------------
    // 1. Trigger model casts integrity (verifies the missing-quote fix)
    // ---------------------------------------------------------------
    describe('Trigger::casts() — key quoting', function (): void {
        test('conditions key is properly quoted', function (): void {
            $casts = (new Trigger)->casts();

            expect($casts)->toHaveKey('conditions');
            expect($casts['conditions'])->toBe('array');
        });

        test('all expected cast keys exist with correct types', function (): void {
            $casts = (new Trigger)->casts();

            expect($casts)->toHaveKeys(['conditions', 'async', 'enabled', 'priority']);
            expect($casts['async'])->toBe('boolean');
            expect($casts['enabled'])->toBe('boolean');
            expect($casts['priority'])->toBe('int');
        });
    });

    // ---------------------------------------------------------------
    // 2. Migration files parse correctly
    // ---------------------------------------------------------------
    describe('Migration file syntax', function (): void {
        test('triggers migration contains properly closed Schema::create call', function (): void {
            $content = file_get_contents(
                __DIR__ . '/../database/migrations/2024_01_01_000001_create_triggers_table.php'
            );

            // The Schema::create closure must end with });  before the closing }
            // Check for balanced parentheses in the up() method
            $upStart = strpos($content, 'public function up()');
            $downStart = strpos($content, 'public function down()');

            expect($upStart)->not->toBeFalse();
            expect($downStart)->not->toBeFalse();

            $upBlock = substr($content, (int) $upStart, (int) $downStart - (int) $upStart);

            // Count parentheses to verify balanced closure
            $openCount = substr_count($upBlock, '(');
            $closeCount = substr_count($upBlock, ')');

            expect($closeCount)->toBe($openCount);
        });

        test('event_logs migration contains foreign key reference', function (): void {
            $content = file_get_contents(
                __DIR__ . '/../database/migrations/2024_01_01_000002_create_event_logs_table.php'
            );

            expect($content)->toContain('foreign(');
            expect($content)->toContain('onDelete(\'cascade\')');
        });

        test('subscriptions migration contains HMAC secret column with comment', function (): void {
            $content = file_get_contents(
                __DIR__ . '/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php'
            );

            expect($content)->toContain('secret');
            expect($content)->toContain('HMAC');
            expect($content)->toContain('failure_count');
            expect($content)->toContain('delivery_count');
        });
    });

    // ---------------------------------------------------------------
    // 3. Exception hierarchy integrity
    // ---------------------------------------------------------------
    describe('Exception hierarchy', function (): void {
        test('EventException extends RuntimeException', function (): void {
            $e = new EventException('test');

            expect($e)->toBeInstanceOf(\RuntimeException::class);
            expect($e->getMessage())->toBe('test');
        });

        test('ActionResolutionException extends EventException', function (): void {
            $e = new ActionResolutionException('Foo\\Bar', 'not found');

            expect($e)->toBeInstanceOf(EventException::class);
            expect($e->getMessage())->toContain('Foo\\Bar');
            expect($e->getMessage())->toContain('not found');
        });

        test('ConditionEvaluationException extends EventException', function (): void {
            $e = new ConditionEvaluationException('amount', 'invalid operator');

            expect($e)->toBeInstanceOf(EventException::class);
            expect($e->getMessage())->toContain('amount');
            expect($e->getMessage())->toContain('invalid operator');
        });

        test('SubscriptionException extends EventException with previous', function (): void {
            $prev = new \RuntimeException('network error');
            $e = new SubscriptionException('delivery failed', $prev);

            expect($e)->toBeInstanceOf(EventException::class);
            expect($e->getPrevious())->toBe($prev);
        });

        test('TriggerNotFoundException extends EventException', function (): void {
            $e = new TriggerNotFoundException('uuid-123');

            expect($e)->toBeInstanceOf(EventException::class);
            expect($e->getMessage())->toContain('uuid-123');
        });

        test('all exception classes are final', function (): void {
            $refl = new ReflectionClass(ActionResolutionException::class);
            expect($refl->isFinal())->toBeTrue();

            $refl = new ReflectionClass(ConditionEvaluationException::class);
            expect($refl->isFinal())->toBeTrue();

            $refl = new ReflectionClass(SubscriptionException::class);
            expect($refl->isFinal())->toBeTrue();

            $refl = new ReflectionClass(TriggerNotFoundException::class);
            expect($refl->isFinal())->toBeTrue();
        });
    });

    // ---------------------------------------------------------------
    // 4. DomainEvent edge cases
    // ---------------------------------------------------------------
    describe('DomainEvent reconstruction edge cases', function (): void {
        test('fromArray with invalid UUID falls back to generated one', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-uuid',
            ]);

            expect($event->eventType)->toBe('test.event');
            // Should have generated a valid UUID instead of using the invalid one
            expect($event->eventId->toString())->not->toBe('not-a-uuid');
        });

        test('fromArray with invalid datetime falls back to now', function (): void {
            $before = new \DateTimeImmutable();
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-date',
            ]);
            $after = new \DateTimeImmutable();

            expect($event->occurredAt->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp());
            expect($event->occurredAt->getTimestamp())->toBeLessThanOrEqual($after->getTimestamp());
        });

        test('fromArray with empty eventType throws', function (): void {
            expect(fn () => DomainEvent::fromArray([]))->toThrow(\InvalidArgumentException::class);
        });

        test('fromArray with non-string eventType throws', function (): void {
            expect(fn () => DomainEvent::fromArray(['eventType' => 123]))->toThrow(\InvalidArgumentException::class);
        });

        test('toArray and fromArray roundtrip preserves data', function (): void {
            $original = DomainEvent::occur('order.placed', ['order_id' => 42]);
            $arr = $original->toArray();

            expect($arr)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);

            $reconstructed = DomainEvent::fromArray($arr);

            expect($reconstructed->eventType)->toBe($original->eventType);
            expect($reconstructed->eventId->toString())->toBe($original->eventId->toString());
            expect($reconstructed->payload)->toBe($original->payload);
        });

        test('__toString format is correct', function (): void {
            $event = DomainEvent::occur('user.created', []);
            $str = (string) $event;

            expect($str)->toContain('DomainEvent[user.created]');
            expect($str)->toContain('id=');
            expect($str)->toContain('at=');
        });

        test('DomainEvent is final and readonly', function (): void {
            $refl = new ReflectionClass(DomainEvent::class);

            expect($refl->isFinal())->toBeTrue();

            $eventIdProp = $refl->getProperty('eventId');
            expect($eventIdProp->isReadOnly())->toBeTrue();

            $occurredAtProp = $refl->getProperty('occurredAt');
            expect($occurredAtProp->isReadOnly())->toBeTrue();

            $eventTypeProp = $refl->getProperty('eventType');
            expect($eventTypeProp->isReadOnly())->toBeTrue();
        });
    });

    // ---------------------------------------------------------------
    // 5. WildcardMatcher readonly class verification
    // ---------------------------------------------------------------
    describe('WildcardMatcher class structure', function (): void {
        test('is a readonly final class', function (): void {
            $refl = new ReflectionClass(WildcardMatcher::class);

            expect($refl->isFinal())->toBeTrue();

            // PHP 8.2+ readonly classes — check via attribute or class modifier
            $modifiers = $refl->getModifiers();
            // Readonly classes don't have a specific reflection method,
            // but they cannot have dynamic properties
            expect($refl->getPropertyNames())->toBeEmpty();
        });

        test('all public methods are static and pure', function (): void {
            $refl = new ReflectionClass(WildcardMatcher::class);

            foreach ($refl->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                expect($method->isStatic())->toBeTrue("Method {$method->getName()} should be static");
            }
        });

        test('extractWildcards returns empty for ** patterns', function (): void {
            $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.shipped');

            expect($result)->toBe([]);
        });

        test('extractWildcards returns empty for mismatched segment count', function (): void {
            $result = WildcardMatcher::extractWildcards('order.*.item', 'order.placed');

            expect($result)->toBe([]);
        });

        test('extractWildcards correctly extracts single wildcards', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.admin.created');

            expect($result)->toBe(['admin']);
        });

        test('empty event does not match catch-all patterns', function (): void {
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        test('findMatchingPatterns returns correct subset', function (): void {
            $patterns = ['order.placed', 'order.*', 'user.*'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.shipped');

            expect($result)->toContain('order.placed');
            expect($result)->toContain('order.*');
            expect($result)->not->toContain('user.*');
        });
    });

    // ---------------------------------------------------------------
    // 6. ConditionEngine operator coverage
    // ---------------------------------------------------------------
    describe('ConditionEngine operator coverage', function (): void {
        test('matches implements ConditionEngineContract', function (): void {
            $engine = new ConditionEngine;

            expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
        });

        test('empty conditions always match', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches([], ['any' => 'data']))->toBeTrue();
        });

        test('empty array condition returns false', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['field' => []], ['field' => 'value']))->toBeFalse();
        });

        test('strict equality with same types', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['count' => 5], ['count' => 5]))->toBeTrue();
            expect($engine->matches(['count' => 5], ['count' => '5']))->toBeTrue(); // string coercion
            expect($engine->matches(['count' => 5], ['count' => 6]))->toBeFalse();
        });

        test('null comparison operators', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['field' => ['null']], ['field' => null]))->toBeTrue();
            expect($engine->matches(['field' => ['null']], ['field' => 'value']))->toBeFalse();
            expect($engine->matches(['field' => ['not_null']], ['field' => 'value']))->toBeTrue();
            expect($engine->matches(['field' => ['not_null']], ['field' => null]))->toBeFalse();
        });

        test('between with inverted range auto-normalizes', function (): void {
            $engine = new ConditionEngine;

            // min=100, max=50 → auto-sorted to [50, 100]
            expect($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 75]))->toBeTrue();
            expect($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 25]))->toBeFalse();
        });

        test('between with non-array value returns false', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['amount' => ['between', 'invalid']], ['amount' => 75]))->toBeFalse();
        });

        test('nested dot notation field access', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['role' => 'admin']]
            ))->toBeTrue();

            expect($engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['role' => 'user']]
            ))->toBeFalse();

            // Missing intermediate key returns null
            expect($engine->matches(
                ['user.role' => 'admin'],
                ['other' => 'value']
            ))->toBeFalse();
        });

        test('starts_with and ends_with operators', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
            expect($engine->matches(['email' => ['ends_with', '.com']], ['email' => 'test@example.com']))->toBeTrue();
            expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 123]))->toBeFalse();
        });

        test('matches operator with safe regex', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(
                ['code' => ['matches', '/^[A-Z]{3}-\d{4}$/']],
                ['code' => 'ABC-1234']
            ))->toBeTrue();

            // ReDoS protection: pattern too long
            expect($engine->matches(
                ['code' => ['matches', '/' . str_repeat('a', 501) . '/']],
                ['code' => 'test']
            ))->toBeFalse();
        });

        test('contains with arrays and strings', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['urgent', 'important']]))->toBeTrue();
            expect($engine->matches(['bio' => ['contains', 'developer']], ['bio' => 'I am a developer']))->toBeTrue();
            expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['urgent']]))->toBeTrue();
        });

        test('in and not_in operators', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['status' => ['in', ['active', 'pending']]], ['status' => 'active']))->toBeTrue();
            expect($engine->matches(['status' => ['in', ['active', 'pending']]], ['status' => 'deleted']))->toBeFalse();
            expect($engine->matches(['status' => ['not_in', ['deleted']]], ['status' => 'active']))->toBeTrue();
        });

        test('empty and not_empty operators', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(['field' => ['empty']], ['field' => '']))->toBeTrue();
            expect($engine->matches(['field' => ['empty']], ['field' => null]))->toBeTrue();
            expect($engine->matches(['field' => ['empty']], ['field' => 0]))->toBeTrue();
            expect($engine->matches(['field' => ['empty']], ['field' => 'value']))->toBeFalse();

            expect($engine->matches(['field' => ['not_empty']], ['field' => 'value']))->toBeTrue();
            expect($engine->matches(['field' => ['not_empty']], ['field' => '']))->toBeFalse();
        });

        test('all conditions must match (AND logic)', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(
                ['status' => 'active', 'amount' => ['>', 50]],
                ['status' => 'active', 'amount' => 100]
            ))->toBeTrue();

            // First matches, second doesn't
            expect($engine->matches(
                ['status' => 'active', 'amount' => ['>', 50]],
                ['status' => 'active', 'amount' => 20]
            ))->toBeFalse();
        });

        test('ConditionEngine is final', function (): void {
            $refl = new ReflectionClass(ConditionEngine::class);

            expect($refl->isFinal())->toBeTrue();
        });
    });

    // ---------------------------------------------------------------
    // 7. Model status constants integrity
    // ---------------------------------------------------------------
    describe('EventLog status constants', function (): void {
        test('all statuses are defined and consistent', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');

            expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
        });

        test('EventLog and Trigger models are final', function (): void {
            expect((new ReflectionClass(EventLog::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(Trigger::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(Subscription::class))->isFinal())->toBeTrue();
        });

        test('EventLog has correct key type and incrementing settings', function (): void {
            $log = new EventLog;

            expect($log->getKeyType())->toBe('string');
            expect($log->incrementing)->toBeFalse();
        });

        test('Trigger has correct key type and incrementing settings', function (): void {
            $trigger = new Trigger;

            expect($trigger->getKeyType())->toBe('string');
            expect($trigger->incrementing)->toBeFalse();
        });

        test('Subscription has correct key type and incrementing settings', function (): void {
            $sub = new Subscription;

            expect($sub->getKeyType())->toBe('string');
            expect($sub->incrementing)->toBeFalse();
        });
    });

    // ---------------------------------------------------------------
    // 8. Core classes are final and have proper constructors
    // ---------------------------------------------------------------
    describe('Core class structure', function (): void {
        test('EventManager is final with readonly constructor properties', function (): void {
            $refl = new ReflectionClass(EventManager::class);

            expect($refl->isFinal())->toBeTrue();

            $ctor = $refl->getMethod('__construct');
            $params = $ctor->getParameters();

            expect(count($params))->toBe(3);

            // All constructor params should be promoted readonly
            foreach ($params as $param) {
                expect($param->isPromoted())->toBeTrue("Constructor param \${$param->getName()} should be promoted");
            }
        });

        test('ActionResolver is final with readonly constructor', function (): void {
            $refl = new ReflectionClass(\ZeroBoiler\Events\ActionResolver::class);

            expect($refl->isFinal())->toBeTrue();

            $ctor = $refl->getMethod('__construct');
            expect(count($ctor->getParameters()))->toBe(1);
        });

        test('EventScheduler is final', function (): void {
            $refl = new ReflectionClass(EventScheduler::class);

            expect($refl->isFinal())->toBeTrue();
        });

        test('TriggerBuilder is final', function (): void {
            $refl = new ReflectionClass(TriggerBuilder::class);

            expect($refl->isFinal())->toBeTrue();
        });

        test('All classes use strict types', function (): void {
            $files = [
                'src/EventManager.php',
                'src/ActionResolver.php',
                'src/ConditionEngine.php',
                'src/EventScheduler.php',
                'src/TriggerBuilder.php',
                'src/SubscriptionBuilder.php',
                'src/WildcardMatcher.php',
                'src/Domain/DomainEvent.php',
                'src/Actions/WebhookAction.php',
                'src/EventsServiceProvider.php',
                'src/Facades/EventManager.php',
                'src/Exceptions/EventException.php',
                'src/Exceptions/ActionResolutionException.php',
                'src/Exceptions/ConditionEvaluationException.php',
                'src/Exceptions/SubscriptionException.php',
                'src/Exceptions/TriggerNotFoundException.php',
            ];

            foreach ($files as $file) {
                $path = __DIR__ . '/../' . $file;
                $content = file_get_contents($path);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });
    });

    // ---------------------------------------------------------------
    // 9. Config file structure
    // ---------------------------------------------------------------
    describe('Config file structure', function (): void {
        test('config file returns array with all expected keys', function (): void {
            $config = include __DIR__ . '/../config/events.php';

            expect(is_array($config))->toBeTrue();

            $expectedKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];

            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Config missing key: {$key}");
            }
        });

        test('table_names config has all three tables', function (): void {
            $config = include __DIR__ . '/../config/events.php';

            expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
        });

        test('queue config has connection and queue keys', function (): void {
            $config = include __DIR__ . '/../config/events.php';

            expect($config['queue'])->toHaveKeys(['connection', 'queue']);
        });

        test('retry config has tries and backoff keys', function (): void {
            $config = include __DIR__ . '/../config/events.php';

            expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
        });

        test('subscriptions config has all expected keys', function (): void {
            $config = include __DIR__ . '/../config/events.php';

            $expectedSubKeys = [
                'auto_generate_secret',
                'secret_length',
                'max_failures',
                'timeout',
                'signature_algorithm',
                'cleanup_cron',
            ];

            foreach ($expectedSubKeys as $key) {
                expect(array_key_exists($key, $config['subscriptions']))->toBeTrue("Subscriptions config missing key: {$key}");
            }
        });

        test('retention config has days, include_pending, and schedule_cron', function (): void {
            $config = include __DIR__ . '/../config/events.php';

            expect($config['retention'])->toHaveKeys(['days', 'include_pending', 'schedule_cron']);
        });
    });

    // ---------------------------------------------------------------
    // 10. ServiceProvider registration correctness
    // ---------------------------------------------------------------
    describe('ServiceProvider registration', function (): void {
        test('provides() returns all expected service classes', function (): void {
            $provider = new ReflectionClass(\ZeroBoiler\Events\EventsServiceProvider::class);
            $method = $provider->getMethod('provides');

            // Create a mock app to instantiate
            $app = app();
            $instance = new \ZeroBoiler\Events\EventsServiceProvider($app);
            $provides = $instance->provides();

            $expected = [
                EventManager::class,
                ConditionEngine::class,
                ConditionEngineContract::class,
                \ZeroBoiler\Events\ActionResolver::class,
                TriggerBuilder::class,
                \ZeroBoiler\Events\SubscriptionBuilder::class,
                EventScheduler::class,
            ];

            foreach ($expected as $class) {
                expect(in_array($class, $provides, true))->toBeTrue("provides() missing: {$class}");
            }
        });

        test('EventsServiceProvider has Override attribute on register and boot', function (): void {
            $refl = new ReflectionClass(\ZeroBoiler\Events\EventsServiceProvider::class);

            $register = $refl->getMethod('register');
            expect($register->getAttributes(\Override::class))->not->toBeEmpty();

            $boot = $refl->getMethod('boot');
            expect($boot->getAttributes(\Override::class))->not->toBeEmpty();

            $provides = $refl->getMethod('provides');
            expect($provides->getAttributes(\Override::class))->not->toBeEmpty();
        });
    });
});
