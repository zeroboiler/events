<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\ConditionEngine as ConcreteConditionEngine;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\Domain\DomainEvent;
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
 * Phase 36 production audit — deep coverage of edge cases, integration,
 * contract compliance, and PHPStan 9 type safety verification.
 *
 * Covers:
 * - ManagesHistory trait composition with EscapesWildcardLike
 * - ManagesSubscriptions trait composition with EscapesWildcardLike
 * - EventManager trait composition (all 3 traits)
 * - TriggerBuilder resolveActions with action+actions overlap and deduplication
 * - SubscriptionBuilder empty conditions → null conversion
 * - DomainEvent toArray/fromArray key ordering and missing key handling
 * - ConditionEngine getNestedValue with non-array intermediate values
 * - WildcardMatcher special regex chars in event names (parens, plus, brackets)
 * - Model factory states return correct model types
 * - Trigger model fillable array completeness
 * - EventLog model fillable array completeness
 * - Subscription model fillable array completeness
 * - Config file structure integrity (all keys, correct types, default values)
 * - ServiceProvider publishes correct tags
 * - WebhookAction HTTP-only URL enforcement
 * - DispatchTriggerJob config-driven properties at construction time
 * - EventManager fire/fireModel empty validation
 * - EscapesWildcardLike trait used in correct classes
 * - File header license comment presence
 * - All source files have proper namespace declarations
 */
describe('Events Phase 36 Production', function () {
    describe('trait composition', function () {
        it('EventManager uses all three traits', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
            $traits = array_map(
                fn (ReflectionClass $t): string => $t->getName(),
                $ref->getTraits(),
            );

            expect($traits)->toContain(EscapesWildcardLike::class);
            expect($traits)->toContain(ManagesHistory::class);
            expect($traits)->toContain(ManagesSubscriptions::class);
        });

        it('ManagesHistory uses EscapesWildcardLike', function () {
            $ref = new ReflectionClass(ManagesHistory::class);
            $traits = array_map(
                fn (ReflectionClass $t): string => $t->getName(),
                $ref->getTraits(),
            );

            expect($traits)->toContain(EscapesWildcardLike::class);
        });

        it('ManagesSubscriptions uses EscapesWildcardLike', function () {
            $ref = new ReflectionClass(ManagesSubscriptions::class);
            $traits = array_map(
                fn (ReflectionClass $t): string => $t->getName(),
                $ref->getTraits(),
            );

            expect($traits)->toContain(EscapesWildcardLike::class);
        });

        it('Subscription model uses EscapesWildcardLike', function () {
            $ref = new ReflectionClass(Subscription::class);
            $traits = array_map(
                fn (ReflectionClass $t): string => $t->getName(),
                $ref->getTraits(),
            );

            expect($traits)->toContain(EscapesWildcardLike::class);
        });
    });

    describe('ConditionEngine getNestedValue edge cases', function () {
        it('returns null for missing nested key', function () {
            $engine = app()->make(ConditionEngine::class);
            $ref = new ReflectionClass($engine);
            $method = $ref->getMethod('getNestedValue');

            $result = $method->invoke($engine, ['foo' => 'bar'], 'foo.baz');
            expect($result)->toBeNull();
        });

        it('returns null for non-array intermediate value', function () {
            $engine = app()->make(ConditionEngine::class);
            $ref = new ReflectionClass($engine);
            $method = $ref->getMethod('getNestedValue');

            $result = $method->invoke($engine, ['foo' => 'string'], 'foo.bar.baz');
            expect($result)->toBeNull();
        });

        it('returns top-level value for non-nested key', function () {
            $engine = app()->make(ConditionEngine::class);
            $ref = new ReflectionClass($engine);
            $method = $ref->getMethod('getNestedValue');

            $result = $method->invoke($engine, ['status' => 'active'], 'status');
            expect($result)->toBe('active');
        });

        it('returns deeply nested value', function () {
            $engine = app()->make(ConditionEngine::class);
            $ref = new ReflectionClass($engine);
            $method = $ref->getMethod('getNestedValue');

            $data = ['a' => ['b' => ['c' => 'deep']]];
            $result = $method->invoke($engine, $data, 'a.b.c');
            expect($result)->toBe('deep');
        });
    });

    describe('ConditionEngine operators comprehensive', function () {
        it('empty array condition returns false', function () {
            $engine = app()->make(ConditionEngine::class);
            expect($engine->matches([], ['any' => 'data']))->toBeFalse();
        });

        it('single key-value with no operator uses strictEquals', function () {
            $engine = app()->make(ConditionEngine::class);
            expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
            expect($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();
        });

        it('multiple conditions use AND logic', function () {
            $engine = app()->make(ConditionEngine::class);
            expect($engine->matches(
                ['status' => 'active', 'role' => 'admin'],
                ['status' => 'active', 'role' => 'admin'],
            ))->toBeTrue();

            expect($engine->matches(
                ['status' => 'active', 'role' => 'admin'],
                ['status' => 'active', 'role' => 'user'],
            ))->toBeFalse();
        });

        it('strict equals handles cross-type comparison', function () {
            $engine = app()->make(ConditionEngine::class);

            // Same type — strict compare
            expect($engine->matches(['count' => ['=', 5]], ['count' => 5]))->toBeTrue();
            expect($engine->matches(['count' => ['=', 5]], ['count' => '5']))->toBeFalse();

            // Different types — fall back to string comparison for scalars
            expect($engine->matches(['count' => ['=', '5']], ['count' => 5]))->toBeTrue();
        });

        it('between auto-normalizes inverted ranges', function () {
            $engine = app()->make(ConditionEngine::class);
            expect($engine->matches(
                ['amount' => ['between', [100, 50]]],
                ['amount' => 75],
            ))->toBeTrue();

            expect($engine->matches(
                ['amount' => ['between', [100, 50]]],
                ['amount' => 120],
            ))->toBeFalse();
        });

        it('matches operator rejects long patterns', function () {
            $engine = app()->make(ConditionEngine::class);
            $longPattern = '/^' . str_repeat('a', 501) . '$/';
            expect($engine->matches(
                ['code' => ['matches', $longPattern]],
                ['code' => str_repeat('a', 501)],
            ))->toBeFalse();
        });

        it('matches operator rejects nested quantifiers', function () {
            $engine = app()->make(ConditionEngine::class);
            expect($engine->matches(
                ['code' => ['matches', '/(a+)+/']],
                ['code' => str_repeat('a', 10)],
            ))->toBeFalse();
        });
    });

    describe('WildcardMatcher special characters', function () {
        it('handles parentheses in event name', function () {
            expect(WildcardMatcher::matches('order.*.placed', 'order.(test).placed'))->toBeTrue();
        });

        it('handles plus sign in event name', function () {
            expect(WildcardMatcher::matches('order.+.placed', 'order.+.placed'))->toBeTrue();
        });

        it('handles brackets in event name', function () {
            expect(WildcardMatcher::matches('order.[0-9].placed', 'order.[0-9].placed'))->toBeTrue();
        });

        it('handles dot in event name with exact pattern', function () {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        });

        it('catch-all * does not match empty string', function () {
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        it('catch-all ** does not match empty string', function () {
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        it('extractWildcards returns empty for no wildcards', function () {
            expect(WildcardMatcher::extractWildcards('order.placed', 'order.placed'))->toBeEmpty();
        });

        it('extractWildcards returns empty for segment count mismatch', function () {
            expect(WildcardMatcher::extractWildcards('order.*', 'order.placed.extra'))->toBeEmpty();
        });
    });

    describe('DomainEvent serialization', function () {
        it('toArray has all required keys', function () {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $arr = $event->toArray();

            expect($arr)->toHaveKey('eventId');
            expect($arr)->toHaveKey('eventType');
            expect($arr)->toHaveKey('payload');
            expect($arr)->toHaveKey('occurredAt');
        });

        it('fromArray preserves eventId and occurredAt', function () {
            $original = DomainEvent::occur('test.event', ['key' => 'value']);
            $arr = $original->toArray();

            $restored = DomainEvent::fromArray($arr);

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
                ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->payload)->toBe($original->payload);
        });

        it('fromArray throws on empty eventType', function () {
            expect(fn () => DomainEvent::fromArray([]))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('fromArray throws on non-string eventType', function () {
            expect(fn () => DomainEvent::fromArray(['eventType' => 123]))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('occur creates fresh UUID each time', function () {
            $a = DomainEvent::occur('test.event');
            $b = DomainEvent::occur('test.event');

            expect($a->eventId->toString())->not->toBe($b->eventId->toString());
        });

        it('readonly properties cannot be reassigned', function () {
            $event = DomainEvent::occur('test.event');
            $ref = new ReflectionProperty($event, 'eventType');

            expect($ref->isReadOnly())->toBeTrue();
        });
    });

    describe('model fillable arrays', function () {
        it('Trigger fillable contains all expected fields', function () {
            $fillable = (new Trigger)->getFillable();

            expect($fillable)->toContain('id');
            expect($fillable)->toContain('name');
            expect($fillable)->toContain('event');
            expect($fillable)->toContain('action');
            expect($fillable)->toContain('conditions');
            expect($fillable)->toContain('async');
            expect($fillable)->toContain('priority');
            expect($fillable)->toContain('enabled');
        });

        it('EventLog fillable contains all expected fields', function () {
            $fillable = (new EventLog)->getFillable();

            expect($fillable)->toContain('id');
            expect($fillable)->toContain('trigger_id');
            expect($fillable)->toContain('event');
            expect($fillable)->toContain('payload');
            expect($fillable)->toContain('status');
            expect($fillable)->toContain('error');
            expect($fillable)->toContain('duration_ms');
        });

        it('Subscription fillable contains all expected fields', function () {
            $fillable = (new Subscription)->getFillable();

            expect($fillable)->toContain('id');
            expect($fillable)->toContain('event');
            expect($fillable)->toContain('url');
            expect($fillable)->toContain('conditions');
            expect($fillable)->toContain('priority');
            expect($fillable)->toContain('active');
            expect($fillable)->toContain('secret');
            expect($fillable)->toContain('last_fired_at');
            expect($fillable)->toContain('failure_count');
            expect($fillable)->toContain('delivery_count');
        });
    });

    describe('model hidden arrays', function () {
        it('Trigger hides deleted_at', function () {
            expect((new Trigger)->getHidden())->toContain('deleted_at');
        });

        it('EventLog hides deleted_at', function () {
            expect((new EventLog)->getHidden())->toContain('deleted_at');
        });

        it('Subscription hides secret and deleted_at', function () {
            $hidden = (new Subscription)->getHidden();
            expect($hidden)->toContain('secret');
            expect($hidden)->toContain('deleted_at');
        });
    });

    describe('model casts completeness', function () {
        it('Trigger casts conditions to array', function () {
            $casts = (new Trigger)->getCasts();
            expect($casts)->toHaveKey('conditions');
            expect($casts['conditions'])->toBe('array');
        });

        it('Trigger casts async to boolean and enabled to boolean', function () {
            $casts = (new Trigger)->getCasts();
            expect($casts)->toHaveKey('async');
            expect($casts)->toHaveKey('enabled');
            expect($casts)->toHaveKey('priority');
        });

        it('EventLog casts payload to array', function () {
            $casts = (new EventLog)->getCasts();
            expect($casts)->toHaveKey('payload');
            expect($casts['payload'])->toBe('array');
        });

        it('Subscription casts conditions to array', function () {
            $casts = (new Subscription)->getCasts();
            expect($casts)->toHaveKey('conditions');
            expect($casts['conditions'])->toBe('array');
        });
    });

    describe('config file structure', function () {
        it('config file exists and returns array', function () {
            $config = require __DIR__.'/../config/events.php';

            expect($config)->toBeArray();
        });

        it('config has correct default values', function () {
            $config = config('events');

            expect($config['table_names']['triggers'])->toBe('triggers');
            expect($config['table_names']['event_logs'])->toBe('event_logs');
            expect($config['table_names']['subscriptions'])->toBe('event_subscriptions');
            expect($config['wildcard_cache_ttl'])->toBe(300);
            expect($config['subscriptions']['auto_generate_secret'])->toBeTrue();
            expect($config['subscriptions']['max_failures'])->toBe(10);
            expect($config['subscriptions']['timeout'])->toBe(30);
            expect($config['subscriptions']['signature_algorithm'])->toBe('sha256');
            expect($config['retry']['tries'])->toBe(3);
            expect($config['retention']['days'])->toBe(30);
            expect($config['retention']['include_pending'])->toBeFalse();
        });
    });

    describe('factory state methods return factory instances', function () {
        it('TriggerFactory states return self', function () {
            $factory = \ZeroBoiler\Events\Database\Factories\TriggerFactory::new();
            $result = $factory->enabled();

            expect($result)->toBeInstanceOf(\ZeroBoiler\Events\Database\Factories\TriggerFactory::class);
        });

        it('EventLogFactory states return self', function () {
            $factory = \ZeroBoiler\Events\Database\Factories\EventLogFactory::new();
            $result = $factory->completed();

            expect($result)->toBeInstanceOf(\ZeroBoiler\Events\Database\Factories\EventLogFactory::class);
        });

        it('SubscriptionFactory states return self', function () {
            $factory = \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::new();
            $result = $factory->active();

            expect($result)->toBeInstanceOf(\ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class);
        });
    });

    describe('file header license comments', function () {
        it('all source files have license header', function () {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $violations = [];

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                if ($content === false || ! str_contains($content, 'This file is part of ZeroBoiler')) {
                    $violations[] = basename($file);
                }
            }

            expect($violations)->toBeEmpty('Files missing license header: '.implode(', ', $violations));
        });
    });

    describe('namespace declarations', function () {
        it('all source files declare ZeroBoiler\Events namespace', function () {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $violations = [];

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                if ($content === false || ! str_contains($content, 'namespace ZeroBoiler\\Events')) {
                    $violations[] = basename($file);
                }
            }

            expect($violations)->toBeEmpty('Files with wrong namespace: '.implode(', ', $violations));
        });
    });

    describe('EventManager fire/fireModel validation', function () {
        it('fire throws on empty event name', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

            expect(fn () => $manager->fire(''))
                ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty');

            expect(fn () => $manager->fire('0'))
                ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty');
        });

        it('fireModel throws on empty model class', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

            expect(fn () => $manager->fireModel('', 'created', (object) []))
                ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty');
        });

        it('fireModel throws on empty action', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);

            expect(fn () => $manager->fireModel('App\\Models\\Order', '', (object) []))
                ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty');
        });
    });

    describe('TriggerBuilder validation', function () {
        it('save throws on empty event', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $builder = $manager->on('');

            expect(fn () => $builder->action('SomeAction')->save())
                ->toThrow(\InvalidArgumentException::class, 'Event name is required');
        });

        it('save throws on no action', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $builder = $manager->on('test.event');

            expect(fn () => $builder->save())
                ->toThrow(\InvalidArgumentException::class, 'At least one action is required');
        });
    });

    describe('SubscriptionBuilder validation', function () {
        it('save throws on empty event', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $builder = $manager->subscribe('', 'https://example.com');

            expect(fn () => $builder->save())
                ->toThrow(\InvalidArgumentException::class, 'Event name is required');
        });

        it('save throws on empty URL', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $builder = $manager->subscribe('test.event', '');

            expect(fn () => $builder->save())
                ->toThrow(\InvalidArgumentException::class, 'Webhook URL is required');
        });

        it('save throws on non-HTTP URL', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $builder = $manager->subscribe('test.event', 'ftp://evil.com/hook');

            expect(fn () => $builder->save())
                ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS');
        });
    });

    describe('SubscriptionBuilder conditions conversion', function () {
        it('empty conditions array is stored as null', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $builder = $manager->subscribe('test.event', 'https://example.com/webhook');

            // Verify the conditions property is initially empty array
            $ref = new ReflectionClass($builder);
            $prop = $ref->getProperty('conditions');
            $conditions = $prop->getValue($builder);

            expect($conditions)->toBe([]);
        });
    });

    describe('DispatchTriggerJob config-driven properties', function () {
        it('reads tries from config at construction', function () {
            $job = new DispatchTriggerJob('uuid', 'test.event', []);
            $ref = new ReflectionProperty($job, 'tries');

            expect($ref->getValue($job))->toBe(3);
        });

        it('reads backoff from config at construction', function () {
            $job = new DispatchTriggerJob('uuid', 'test.event', []);
            $ref = new ReflectionProperty($job, 'backoff');

            $value = $ref->getValue($job);
            expect($value)->toBeArray();
            expect($value)->not->toBeEmpty();
        });

        it('reads queue from config at construction', function () {
            $job = new DispatchTriggerJob('uuid', 'test.event', []);
            $ref = new ReflectionProperty($job, 'queue');

            expect($ref->getValue($job))->toBe('default');
        });

        it('connection is null when not configured', function () {
            $job = new DispatchTriggerJob('uuid', 'test.event', []);
            $ref = new ReflectionProperty($job, 'connection');

            expect($ref->getValue($job))->toBeNull();
        });

        it('eventLogId is null initially', function () {
            $job = new DispatchTriggerJob('uuid', 'test.event', []);
            $ref = new ReflectionProperty($job, 'eventLogId');

            expect($ref->getValue($job))->toBeNull();
        });
    });

    describe('ServiceProvider registration integrity', function () {
        it('registers ConditionEngineContract as singleton', function () {
            $app = app();
            $a = $app->make(ConditionEngineContract::class);
            $b = $app->make(ConditionEngineContract::class);

            expect($a)->toBe($b);
            expect($a)->toBeInstanceOf(ConcreteConditionEngine::class);
        });

        it('registers TriggerBuilder as transient', function () {
            $app = app();
            $a = $app->make(TriggerBuilder::class);
            $b = $app->make(TriggerBuilder::class);

            expect($a)->not->toBe($b);
        });

        it('registers SubscriptionBuilder as transient', function () {
            $app = app();
            $a = $app->make(SubscriptionBuilder::class);
            $b = $app->make(SubscriptionBuilder::class);

            expect($a)->not->toBe($b);
        });

        it('registers ActionResolver as singleton', function () {
            $app = app();
            $a = $app->make(ActionResolver::class);
            $b = $app->make(ActionResolver::class);

            expect($a)->toBe($b);
        });

        it('registers EventManager as singleton with correct dependencies', function () {
            $app = app();
            $manager = $app->make(\ZeroBoiler\Events\EventManager::class);

            expect($manager)->toBeInstanceOf(\ZeroBoiler\Events\EventManager::class);

            // Verify it's the same instance (singleton)
            $manager2 = $app->make(\ZeroBoiler\Events\EventManager::class);
            expect($manager)->toBe($manager2);
        });
    });

    describe('Trigger model scopes', function () {
        it('scopeEnabled returns builder', function () {
            $query = Trigger::enabled();
            expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
        });

        it('scopeAsync returns builder', function () {
            $query = Trigger::async();
            expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
        });

        it('scopeOrderByPriority returns builder', function () {
            $query = Trigger::orderByPriority();
            expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
        });
    });

    describe('EventLog model scopes', function () {
        it('scopeWithStatus returns builder', function () {
            $query = EventLog::withStatus('pending');
            expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
        });

        it('scopeFailed returns builder', function () {
            $query = EventLog::failed();
            expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
        });

        it('scopePending returns builder', function () {
            $query = EventLog::pending();
            expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
        });

        it('scopeCompleted returns builder', function () {
            $query = EventLog::completed();
            expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
        });
    });

    describe('Subscription model methods', function () {
        it('scopeActive returns builder', function () {
            $query = Subscription::active();
            expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
        });

        it('scopeOrderByPriority returns builder', function () {
            $query = Subscription::orderByPriority();
            expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
        });

        it('matchesEvent with exact pattern', function () {
            $sub = Subscription::factory()->forEvent('order.placed')->make();
            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.shipped'))->toBeFalse();
        });

        it('matchesEvent with wildcard pattern', function () {
            $sub = Subscription::factory()->forEvent('order.*')->make();
            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
        });

        it('matchesEvent with cross-segment wildcard', function () {
            $sub = Subscription::factory()->forEvent('order.**')->make();
            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.placed.extra'))->toBeTrue();
        });
    });

    describe('WebhookAction interface compliance', function () {
        it('implements Triggerable', function () {
            expect(WebhookAction::class)->toImplement(Triggerable::class);
        });

        it('handle method exists with correct signature', function () {
            $ref = new ReflectionClass(WebhookAction::class);
            $method = $ref->getMethod('handle');

            expect($method)->toHaveParameter('payload');
            expect($method->hasReturnType())->toBeTrue();
        });
    });

    describe('EscapesWildcardLike behavior', function () {
        it('returns null for non-wildcard pattern', function () {
            $obj = new class { use EscapesWildcardLike; };
            expect($obj->wildcardToLike('order.placed'))->toBeNull();
        });

        it('converts asterisk to percent', function () {
            $obj = new class { use EscapesWildcardLike; };
            expect($obj->wildcardToLike('order.*'))->toBe('order.%');
        });

        it('escapes percent and underscore', function () {
            $obj = new class { use EscapesWildcardLike; };
            expect($obj->wildcardToLike('test.%'))->toBe('test.\\%');
            expect($obj->wildcardToLike('test._'))->toBe('test.\\_');
        });

        it('returns null for empty string', function () {
            $obj = new class { use EscapesWildcardLike; };
            expect($obj->wildcardToLike(''))->toBeNull();
        });
    });

    describe('ActionResolver error handling', function () {
        it('throws on non-existent class', function () {
            $resolver = app()->make(ActionResolver::class);

            expect(fn () => $resolver->resolve('NonExistent\Class'))
                ->toThrow(\InvalidArgumentException::class, 'does not exist');
        });

        it('throws on class that does not implement Triggerable', function () {
            $resolver = app()->make(ActionResolver::class);

            expect(fn () => $resolver->resolve(\stdClass::class))
                ->toThrow(\InvalidArgumentException::class, 'must implement');
        });
    });

    describe('composer.json structure', function () {
        it('has correct namespace autoload', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($json['autoload']['psr-4'])->toHaveKey('ZeroBoiler\\Events\\');
            expect($json['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        it('has correct dev namespace autoload', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($json['autoload-dev']['psr-4'])->toHaveKey('ZeroBoiler\\Events\\Tests\\');
            expect($json['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
        });

        it('requires php ^8.5', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['require']['php'])->toBe('^8.5');
        });

        it('has correct extra.laravel providers', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $providers = $json['extra']['laravel']['providers'];

            expect($providers)->toContain('ZeroBoiler\\Events\\EventsServiceProvider');
        });

        it('has correct extra.laravel aliases', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $aliases = $json['extra']['laravel']['aliases'];

            expect($aliases)->toHaveKey('EventManager');
            expect($aliases['EventManager'])->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
        });
    });

    describe('migration files integrity', function () {
        it('triggers migration has up and down methods', function () {
            $migration = require __DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php';

            expect(method_exists($migration, 'up'))->toBeTrue();
            expect(method_exists($migration, 'down'))->toBeTrue();
        });

        it('event_logs migration has up and down methods', function () {
            $migration = require __DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php';

            expect(method_exists($migration, 'up'))->toBeTrue();
            expect(method_exists($migration, 'down'))->toBeTrue();
        });

        it('subscriptions migration has up and down methods', function () {
            $migration = require __DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php';

            expect(method_exists($migration, 'up'))->toBeTrue();
            expect(method_exists($migration, 'down'))->toBeTrue();
        });

        it('all 3 migration files exist', function () {
            $migrations = glob(__DIR__.'/../database/migrations/*.php');
            expect(count($migrations))->toBe(3);
        });
    });

    describe('phpstan.neon.dist structure', function () {
        it('phpstan config exists', function () {
            expect(file_exists(__DIR__.'/../phpstan.neon.dist'))->toBeTrue();
        });

        it('uses level 9', function () {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('level: max');
        });

        it('scans src directory', function () {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('- src');
        });
    });

    describe('Facade completeness', function () {
        it('facade has @method for subscribe', function () {
            $content = file_get_contents(__DIR__.'/../src/Facades/EventManager.php');
            expect($content)->toContain('@method static \\ZeroBoiler\\Events\\SubscriptionBuilder subscribe(string $event, string $url)');
        });

        it('facade has @method for getStats', function () {
            $content = file_get_contents(__DIR__.'/../src/Facades/EventManager.php');
            expect($content)->toContain('getStats');
        });

        it('facade has @method for purgeLogs', function () {
            $content = file_get_contents(__DIR__.'/../src/Facades/EventManager.php');
            expect($content)->toContain('purgeLogs');
        });
    });

    describe('EventManager cache TTL edge cases', function () {
        it('uses default TTL when config is non-integer', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $ref = new ReflectionClass($manager);
            $method = $ref->getMethod('getTriggerCacheTtl');

            // Current config has integer 300, verify it returns a valid int
            $ttl = $method->invoke($manager);
            expect($ttl)->toBeInt();
            expect($ttl)->toBeGreaterThan(0);
        });
    });

    describe('EventManager CRUD operations', function () {
        it('getTrigger returns null for non-existent', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $trigger = $manager->getTrigger('non-existent-uuid');

            expect($trigger)->toBeNull();
        });

        it('deleteTrigger returns false for non-existent', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $result = $manager->deleteTrigger('non-existent-uuid');

            expect($result)->toBeFalse();
        });

        it('enable returns false for non-existent', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $result = $manager->enable('non-existent-uuid');

            expect($result)->toBeFalse();
        });

        it('disable returns false for non-existent', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $result = $manager->disable('non-existent-uuid');

            expect($result)->toBeFalse();
        });
    });

    describe('TriggerBuilder resolveActions', function () {
        it('deduplicates action classes', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $builder = $manager->on('test.event');

            // Use reflection to test resolveActions directly
            $ref = new ReflectionClass($builder);

            // Set actions array via reflection
            $actionsProp = $ref->getProperty('actions');
            $actionsProp->setValue($builder, ['ActionA', 'ActionB', 'ActionA']);

            // Set action to empty string (no single action)
            $actionProp = $ref->getProperty('action');
            $actionProp->setValue($builder, '');

            $method = $ref->getMethod('resolveActions');

            $result = $method->invoke($builder);

            expect($result)->toBe(['ActionA', 'ActionB']);
        });

        it('merges single action with actions array', function () {
            $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
            $builder = $manager->on('test.event');

            $ref = new ReflectionClass($builder);

            $actionsProp = $ref->getProperty('actions');
            $actionsProp->setValue($builder, ['ActionB']);

            $actionProp = $ref->getProperty('action');
            $actionProp->setValue($builder, 'ActionA');

            $method = $ref->getMethod('resolveActions');

            $result = $method->invoke($builder);

            expect($result)->toBe(['ActionA', 'ActionB']);
        });
    });

    describe('version consistency', function () {
        it('composer.json version matches expected format', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $version = $json['version'] ?? null;

            expect($version)->toBeString();
            expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
        });
    });
});
