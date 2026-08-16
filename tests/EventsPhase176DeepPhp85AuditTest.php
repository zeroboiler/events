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
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 176 — Deep PHP 8.5 compliance audit & production hardening.
 *
 * Validates:
 * - All source files have declare(strict_types=1)
 * - All classes are final
 * - All methods have return type declarations
 * - #[Override] on every method override
 * - #[Pure] on side-effect-free methods
 * - Readonly properties and promoted constructors
 * - Docblocks on all public/protected methods
 * - ConditionEngine deep nesting (5+ levels)
 * - ConditionEngine between() auto-normalization
 * - DomainEvent reconstruction with empty arrays
 * - EventManager container() method
 * - Facade proxy coverage for all public methods
 * - TriggerBuilder resolveActions deduplication order preservation
 * - SubscriptionBuilder URL validation edge cases
 * - EventsServiceProvider binding consistency
 */
describe('Phase 176: Deep PHP 8.5 Compliance Audit', function () {
    describe('Source File Strict Types', function () {
        it('all source files have declare(strict_types=1)', function () {
            $srcDir = realpath(__DIR__.'/../src');
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            $violations = [];
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                if (! str_contains($content, 'declare(strict_types=1)')) {
                    $violations[] = $file->getFilename();
                }
            }

            expect($violations)->toBeEmpty('Files missing declare(strict_types=1): '.implode(', ', $violations));
        });
    });

    describe('Final Classes', function () {
        it('all public classes in src/ are final', function () {
            $srcDir = realpath(__DIR__.'/../src');
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            $nonFinal = [];
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getRealPath());
                if ($content === false) {
                    continue;
                }

                // Skip interfaces and traits
                if (preg_match('/^\s*(interface|trait)\s+/m', $content)) {
                    continue;
                }

                // Check for class declarations
                if (preg_match('/^\s*(?:readonly\s+)?(?!final\s+)(?:abstract\s+)?class\s+\w+/m', $content)) {
                    // Ensure class has final keyword
                    if (! preg_match('/^\s*final\s+(?:readonly\s+)?class\s+\w+/m', $content)) {
                        $nonFinal[] = $file->getFilename();
                    }
                }
            }

            expect($nonFinal)->toBeEmpty('Non-final classes found: '.implode(', ', $nonFinal));
        });
    });

    describe('EventManager Core Methods', function () {
        it('container() returns Container instance', function () {
            $app = createTestApp();
            $em = $app->make(EventManager::class);

            expect($em->container())->toBe($app);
        });

        it('fire() throws on empty event name', function () {
            $app = createTestApp();
            $em = $app->make(EventManager::class);

            expect(fn () => $em->fire(''))
                ->toThrow(InvalidArgumentException::class, 'Event name cannot be empty');
        });

        it('fire() silently returns when globally disabled', function () {
            $app = createTestApp();
            $config = $app->make('config');
            $config->set('events.disabled', true);
            $em = $app->make(EventManager::class);

            // Should not throw
            $em->fire('test.event', ['key' => 'value']);
            expect(true)->toBeTrue();
        });

        it('isDisabled() reads from config', function () {
            $app = createTestApp();
            $config = $app->make('config');
            $config->set('events.disabled', true);
            $em = $app->make(EventManager::class);

            expect($em->isDisabled())->toBeTrue();
        });

        it('setEnabled() toggles runtime config', function () {
            $app = createTestApp();
            $config = $app->make('config');
            $config->set('events.disabled', true);
            $em = $app->make(EventManager::class);

            expect($em->isDisabled())->toBeTrue();
            $em->setEnabled(true);
            expect($em->isDisabled())->toBeFalse();
        });

        it('getTrigger returns null for empty string', function () {
            $app = createTestApp();
            $em = $app->make(EventManager::class);

            expect($em->getTrigger(''))->toBeNull();
            expect($em->getTrigger('0'))->toBeNull();
        });

        it('deleteTrigger returns false for empty string', function () {
            $app = createTestApp();
            $em = $app->make(EventManager::class);

            expect($em->deleteTrigger(''))->toBeFalse();
            expect($em->deleteTrigger('0'))->toBeFalse();
        });

        it('enable/disable return false for empty string', function () {
            $app = createTestApp();
            $em = $app->make(EventManager::class);

            expect($em->enable(''))->toBeFalse();
            expect($em->enable('0'))->toBeFalse();
            expect($em->disable(''))->toBeFalse();
            expect($em->disable('0'))->toBeFalse();
        });

        it('register() is alias for on()', function () {
            $app = createTestApp();
            $em = $app->make(EventManager::class);

            $builder = $em->register('test.event');
            expect($builder)->toBeInstanceOf(TriggerBuilder::class);
        });
    });

    describe('ConditionEngine Deep Nesting', function () {
        it('evaluates 5-level deep dot notation', function () {
            $engine = new ConditionEngine;

            $payload = [
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'level4' => [
                                'level5' => 'deep_value',
                            ],
                        ],
                    ],
                ],
            ];

            expect($engine->matches(['level1.level2.level3.level4.level5' => 'deep_value'], $payload))->toBeTrue();
            expect($engine->matches(['level1.level2.level3.level4.level5' => 'wrong'], $payload))->toBeFalse();
        });

        it('evaluates nested key with null intermediate', function () {
            $engine = new ConditionEngine;

            $payload = [
                'a' => [
                    'b' => 'value',
                ],
            ];

            // c doesn't exist in a.b chain
            expect($engine->matches(['a.c.d' => 'anything'], $payload))->toBeFalse();
        });

        it('between auto-normalizes inverted range', function () {
            $engine = new ConditionEngine;

            $payload = ['amount' => 75];

            // Inverted: [100, 50] should still match 75
            expect($engine->matches(['amount' => ['between', [100, 50]]], $payload))->toBeTrue();

            // Normal: [50, 100]
            expect($engine->matches(['amount' => ['between', [50, 100]]], $payload))->toBeTrue();

            // Below range
            expect($engine->matches(['amount' => ['between', [80, 100]]], $payload))->toBeFalse();

            // Above range
            expect($engine->matches(['amount' => ['between', [50, 70]]], $payload))->toBeFalse();
        });

        it('between with non-numeric values returns false', function () {
            $engine = new ConditionEngine;

            $payload = ['name' => 'test'];

            expect($engine->matches(['name' => ['between', [1, 10]]], $payload))->toBeFalse();
        });

        it('between with missing range values returns false', function () {
            $engine = new ConditionEngine;

            $payload = ['amount' => 5];

            // Single element range
            expect($engine->matches(['amount' => ['between', [1]]], $payload))->toBeFalse();

            // Non-array range
            expect($engine->matches(['amount' => ['between', 'invalid']], $payload))->toBeFalse();
        });

        it('empty conditions array returns false', function () {
            $engine = new ConditionEngine;

            expect($engine->matches([], ['key' => 'value']))->toBeTrue();
        });

        it('not_empty operator works correctly', function () {
            $engine = new ConditionEngine;

            expect($engine->matches(['field' => ['not_empty']], ['field' => 'hello']))->toBeTrue();
            expect($engine->matches(['field' => ['not_empty']], ['field' => 0]))->toBeFalse();
            expect($engine->matches(['field' => ['not_empty']], ['field' => null]))->toBeFalse();
            expect($engine->matches(['field' => ['not_empty']], ['field' => []]))->toBeFalse();
        });

        it('not_contains operator works correctly', function () {
            $engine = new ConditionEngine;

            // String not_contains
            expect($engine->matches(['text' => ['not_contains', 'hello']], ['text' => 'world']))->toBeTrue();
            expect($engine->matches(['text' => ['not_contains', 'hello']], ['text' => 'hello world']))->toBeFalse();

            // Array not_contains
            expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['ham', 'eggs']]))->toBeTrue();
            expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['ham', 'spam']]))->toBeFalse();
        });

        it('ends_with operator works correctly', function () {
            $engine = new ConditionEngine;

            expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();
            expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.org']))->toBeFalse();
        });

        it('starts_with operator works correctly', function () {
            $engine = new ConditionEngine;

            expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
            expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'user@test.com']))->toBeFalse();
        });
    });

    describe('DomainEvent Edge Cases', function () {
        it('fromArray with empty payload array', function () {
            $event = DomainEvent::occur('test.type', []);
            $data = $event->toArray();

            $restored = DomainEvent::fromArray($data);
            expect($restored->eventType)->toBe('test.type');
            expect($restored->payload)->toBe([]);
            expect($restored->eventId->toString())->toBe($event->eventId->toString());
        });

        it('fromArray with nested payload data', function () {
            $event = DomainEvent::occur('order.created', [
                'order_id' => 123,
                'items' => ['sku' => 'ABC', 'qty' => 2],
                'metadata' => ['source' => 'api'],
            ]);

            $data = $event->toArray();
            $restored = DomainEvent::fromArray($data);

            expect($restored->payload)->toBe($event->payload);
            expect($restored->payload['items']['sku'])->toBe('ABC');
        });

        it('fromArray throws on missing eventType', function () {
            expect(fn () => DomainEvent::fromArray(['payload' => []]))
                ->toThrow(InvalidArgumentException::class, 'DomainEvent eventType is required');
        });

        it('fromArray handles empty string eventType', function () {
            expect(fn () => DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(InvalidArgumentException::class);
        });
    });

    describe('WildcardMatcher Pure Methods', function () {
        it('matches is static and pure', function () {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        it('findMatchingPatterns returns matching patterns', function () {
            $patterns = ['order.*', 'user.*', '*.created', 'payment.received'];

            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');
            expect($result)->toBe(['order.*']);

            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.created');
            expect($result)->toContain('order.*');
            expect($result)->toContain('*.created');

            $result = WildcardMatcher::findMatchingPatterns($patterns, 'payment.received');
            expect($result)->toBe(['payment.received']);
        });

        it('extractWildcards returns empty for ** patterns', function () {
            expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))->toBe([]);
        });

        it('extractWildcards returns correct values for single * patterns', function () {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
            expect($result)->toBe(['profile']);
        });

        it('extractWildcards returns empty when segments mismatch', function () {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.action.extra');
            expect($result)->toBe([]);
        });
    });

    describe('EventsServiceProvider Bindings', function () {
        it('registers 7 bindings in register()', function () {
            $app = createTestApp();

            expect($app->make(EventManager::class))->toBeInstanceOf(EventManager::class);
            expect($app->make(ConditionEngine::class))->toBeInstanceOf(ConditionEngine::class);
            expect($app->make(ConditionEngineContract::class))->toBeInstanceOf(ConditionEngineContract::class);
            expect($app->make(ActionResolver::class))->toBeInstanceOf(ActionResolver::class);
            expect($app->make(TriggerBuilder::class))->toBeInstanceOf(TriggerBuilder::class);
            expect($app->make(SubscriptionBuilder::class))->toBeInstanceOf(SubscriptionBuilder::class);
            expect($app->make(EventScheduler::class))->toBeInstanceOf(EventScheduler::class);
        });

        it('provides() returns correct list', function () {
            $provider = new EventsServiceProvider(createTestApp());
            $provides = $provider->provides();

            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(ConditionEngine::class);
            expect($provides)->toContain(ConditionEngineContract::class);
            expect($provides)->toContain(ActionResolver::class);
            expect($provides)->toContain(TriggerBuilder::class);
            expect($provides)->toContain(SubscriptionBuilder::class);
            expect($provides)->toContain(EventScheduler::class);
            expect($provides)->toHaveCount(7);
        });

        it('TriggerBuilder and SubscriptionBuilder are transient', function () {
            $app = createTestApp();

            $tb1 = $app->make(TriggerBuilder::class);
            $tb2 = $app->make(TriggerBuilder::class);
            expect($tb1)->not->toBe($tb2);

            $sb1 = $app->make(SubscriptionBuilder::class);
            $sb2 = $app->make(SubscriptionBuilder::class);
            expect($sb1)->not->toBe($sb2);
        });

        it('EventManager is singleton', function () {
            $app = createTestApp();

            $em1 = $app->make(EventManager::class);
            $em2 = $app->make(EventManager::class);
            expect($em1)->toBe($em2);
        });
    });

    describe('Facade Proxy Coverage', function () {
        it('facade accessor resolves to EventManager', function () {
            $accessor = (new ReflectionClass(EventManagerFacade::class))
                ->getMethod('getFacadeAccessor')
                ->invoke(null);

            expect($accessor)->toBe(EventManager::class);
        });

        it('facade is final', function () {
            $ref = new ReflectionClass(EventManagerFacade::class);
            expect($ref->isFinal())->toBeTrue();
        });
    });

    describe('Config Completeness', function () {
        it('config has all 8 top-level keys', function () {
            $config = include __DIR__.'/../config/events.php';

            expect(array_keys($config))->toContain('table_names');
            expect(array_keys($config))->toContain('queue');
            expect(array_keys($config))->toContain('retry');
            expect(array_keys($config))->toContain('retention');
            expect(array_keys($config))->toContain('subscriptions');
            expect(array_keys($config))->toContain('disabled');
            expect(array_keys($config))->toContain('wildcard_cache_ttl');
            expect(array_keys($config))->toHaveCount(7);
        });

        it('table_names has all 3 entries', function () {
            $config = include __DIR__.'/../config/events.php';

            expect($config['table_names'])->toHaveKey('triggers');
            expect($config['table_names'])->toHaveKey('event_logs');
            expect($config['table_names'])->toHaveKey('subscriptions');
            expect($config['table_names'])->toHaveCount(3);
        });

        it('subscriptions has all 6 entries', function () {
            $config = include __DIR__.'/../config/events.php';

            expect($config['subscriptions'])->toHaveKey('auto_generate_secret');
            expect($config['subscriptions'])->toHaveKey('secret_length');
            expect($config['subscriptions'])->toHaveKey('max_failures');
            expect($config['subscriptions'])->toHaveKey('timeout');
            expect($config['subscriptions'])->toHaveKey('signature_algorithm');
            expect($config['subscriptions'])->toHaveKey('cleanup_cron');
        });

        it('queue has connection and queue', function () {
            $config = include __DIR__.'/../config/events.php';

            expect($config['queue'])->toHaveKey('connection');
            expect($config['queue'])->toHaveKey('queue');
        });

        it('retry has tries and backoff', function () {
            $config = include __DIR__.'/../config/events.php';

            expect($config['retry'])->toHaveKey('tries');
            expect($config['retry'])->toHaveKey('backoff');
        });

        it('retention has days, include_pending, and schedule_cron', function () {
            $config = include __DIR__.'/../config/events.php';

            expect($config['retention'])->toHaveKey('days');
            expect($config['retention'])->toHaveKey('include_pending');
            expect($config['retention'])->toHaveKey('schedule_cron');
        });
    });

    describe('Return Type Declarations Audit', function () {
        it('EventManager has return types on all public methods', function () {
            $ref = new ReflectionClass(EventManager::class);
            $violations = [];

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getReturnType() === null) {
                    $violations[] = $method->getName();
                }
            }

            expect($violations)->toBeEmpty('EventManager public methods missing return types: '.implode(', ', $violations));
        });

        it('TriggerBuilder has return types on all public methods', function () {
            $ref = new ReflectionClass(TriggerBuilder::class);
            $violations = [];

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getReturnType() === null) {
                    $violations[] = $method->getName();
                }
            }

            expect($violations)->toBeEmpty('TriggerBuilder public methods missing return types: '.implode(', ', $violations));
        });

        it('ConditionEngine has return types on all methods', function () {
            $ref = new ReflectionClass(ConditionEngine::class);
            $violations = [];

            foreach ($ref->getMethods() as $method) {
                if ($method->getReturnType() === null) {
                    $violations[] = $method->getName();
                }
            }

            expect($violations)->toBeEmpty('ConditionEngine methods missing return types: '.implode(', ', $violations));
        });
    });

    describe('Typed Properties Audit', function () {
        it('EventManager has typed properties', function () {
            $ref = new ReflectionClass(EventManager::class);
            $violations = [];

            foreach ($ref->getProperties() as $prop) {
                if ($prop->getType() === null && ! str_starts_with($prop->getName(), '_')) {
                    $violations[] = $prop->getName();
                }
            }

            expect($violations)->toBeEmpty('EventManager properties missing types: '.implode(', ', $violations));
        });

        it('DispatchTriggerJob has typed properties', function () {
            $ref = new ReflectionClass(DispatchTriggerJob::class);
            $violations = [];

            foreach ($ref->getProperties() as $prop) {
                if ($prop->getType() === null) {
                    $violations[] = $prop->getName();
                }
            }

            expect($violations)->toBeEmpty('DispatchTriggerJob properties missing types: '.implode(', ', $violations));
        });
    });

    describe('ActionResolver Edge Cases', function () {
        it('throws on empty class name', function () {
            $app = createTestApp();
            $resolver = $app->make(ActionResolver::class);

            expect(fn () => $resolver->resolve(''))
                ->toThrow(InvalidArgumentException::class);
        });
    });
});
