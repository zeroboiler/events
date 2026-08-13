<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
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

describe('Phase 127 — Production Audit', function (): void {
    describe('Strict types and declarations', function (): void {
        test('all source files declare strict_types=1', function (): void {
            $srcDir = __DIR__.'/../src';
            $files = glob($srcDir.'/{,**/}*.php') ?: [];
            $violations = [];

            foreach ($files as $file) {
                $content = file_get_contents($file);
                if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
                    $violations[] = str_replace($srcDir.'/', '', $file);
                }
            }

            expect($violations)->toBeEmpty(
                'Files missing declare(strict_types=1): '.implode(', ', $violations)
            );
        });

        test('EventManager is final', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('ConditionEngine is final', function (): void {
            $ref = new ReflectionClass(ConditionEngine::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('TriggerBuilder is final', function (): void {
            $ref = new ReflectionClass(TriggerBuilder::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('SubscriptionBuilder is final', function (): void {
            $ref = new ReflectionClass(SubscriptionBuilder::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('WildcardMatcher is readonly final', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('WebhookAction is final', function (): void {
            $ref = new ReflectionClass(WebhookAction::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('EventsServiceProvider is final', function (): void {
            $ref = new ReflectionClass(EventsServiceProvider::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('DomainEvent is final', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('DispatchTriggerJob is final', function (): void {
            $ref = new ReflectionClass(DispatchTriggerJob::class);
            expect($ref->isFinal())->toBeTrue();
        });
    });

    describe('Interface contracts', function (): void {
        test('ConditionEngine implements ConditionEngineContract', function (): void {
            expect((new ConditionEngine) instanceof ConditionEngineContract)->toBeTrue();
        });

        test('ConditionEngineContract has matches() method signature', function (): void {
            $ref = new ReflectionClass(ConditionEngineContract::class);
            $method = $ref->getMethod('matches');
            expect($method->hasReturnType())->toBeTrue();
            $returnType = $method->getReturnType();
            expect($returnType?->getName())->toBe('bool');
            expect($method->getParameters())->toHaveCount(2);
        });

        test('Triggerable has handle() method signature', function (): void {
            $ref = new ReflectionClass(Triggerable::class);
            $method = $ref->getMethod('handle');
            expect($method->hasReturnType())->toBeTrue();
            expect($method->getReturnType()?->getName())->toBe('void');
        });
    });

    describe('EventManager constructor injection', function (): void {
        test('constructor has typed readonly promoted properties', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $ctor = $ref->getConstructor();
            expect($ctor)->not->toBeNull();

            $params = $ctor->getParameters();
            expect($params)->toHaveCount(3);

            // conditionEngine: ConditionEngine
            expect($params[0]->getType()?->getName())->toBe(ConditionEngine::class);
            expect($params[0]->isPromoted())->toBeTrue();

            // actionResolver: ActionResolver
            expect($params[1]->getType()?->getName())->toBe(\ZeroBoiler\Events\ActionResolver::class);
            expect($params[1]->isPromoted())->toBeTrue();

            // app: Container
            expect($params[2]->getType()?->getName())->toBe(\Illuminate\Container\Container::class);
            expect($params[2]->isPromoted())->toBeTrue();
        });
    });

    describe('EventLog status constants', function (): void {
        test('all status constants are defined', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        test('static $statuses contains all constants', function (): void {
            $expected = [
                EventLog::STATUS_PENDING,
                EventLog::STATUS_DISPATCHED,
                EventLog::STATUS_COMPLETED,
                EventLog::STATUS_FAILED,
            ];
            expect(EventLog::$statuses)->toBe($expected);
        });
    });

    describe('DomainEvent value object', function (): void {
        test('constructs with minimal args', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);

            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['key' => 'value']);
            expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
            expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        });

        test('toArray roundtrip preserves data', function (): void {
            $event = DomainEvent::occur('order.placed', ['amount' => 100]);
            $array = $event->toArray();

            expect($array)->toHaveKey('eventId');
            expect($array)->toHaveKey('eventType');
            expect($array)->toHaveKey('payload');
            expect($array)->toHaveKey('occurredAt');
            expect($array['eventType'])->toBe('order.placed');
            expect($array['payload'])->toBe(['amount' => 100]);
        });

        test('fromArray reconstructs event', function (): void {
            $original = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
            $array = $original->toArray();
            $reconstructed = DomainEvent::fromArray($array);

            expect($reconstructed->eventType)->toBe('user.registered');
            expect($reconstructed->payload)->toBe(['email' => 'test@example.com']);
            expect($reconstructed->eventId->toString())->toBe($original->eventId->toString());
        });

        test('fromArray throws on empty eventType', function (): void {
            $this->expectException(\InvalidArgumentException::class);
            DomainEvent::fromArray(['eventType' => '']);
        });

        test('fromArray handles missing eventType gracefully', function (): void {
            $this->expectException(\InvalidArgumentException::class);
            DomainEvent::fromArray([]);
        });

        test('fromArray generates new UUID for invalid eventId', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test',
                'eventId' => 'not-a-uuid',
                'payload' => [],
                'occurredAt' => '2025-01-01T00:00:00+00:00',
            ]);

            expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
            expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        });

        test('immutable — properties are readonly', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);

            expect($ref->getProperty('eventId')->isReadOnly())->toBeTrue();
            expect($ref->getProperty('eventType')->isReadOnly())->toBeTrue();
            expect($ref->getProperty('payload')->isReadOnly())->toBeTrue();
            expect($ref->getProperty('occurredAt')->isReadOnly())->toBeTrue();
        });
    });

    describe('WildcardMatcher edge cases', function (): void {
        test('empty pattern does not match empty event', function (): void {
            expect(WildcardMatcher::matches('', ''))->toBeFalse();
        });

        test('literal pattern with no wildcard matches exactly', function (): void {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        test('single segment wildcard works', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        test('cross-segment wildcard works', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
        });

        test('extractWildcards returns correct segments', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.admin.created');
            expect($result)->toBe(['admin']);
        });

        test('extractWildcards returns empty for mismatched segment count', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.admin.profile.created');
            expect($result)->toBe([]);
        });

        test('findMatchingPatterns returns correct subset', function (): void {
            $patterns = ['order.*', 'user.*', 'order.**', 'static.event'];
            $matched = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

            expect($matched)->toContain('order.*');
            expect($matched)->toContain('order.**');
            expect($matched)->not->toContain('user.*');
            expect($matched)->not->toContain('static.event');
        });
    });

    describe('Config completeness', function (): void {
        test('events config has all expected keys', function (): void {
            $config = include __DIR__.'/../config/events.php';

            expect($config)->toHaveKey('table_names');
            expect($config)->toHaveKey('queue');
            expect($config)->toHaveKey('retry');
            expect($config)->toHaveKey('retention');
            expect($config)->toHaveKey('subscriptions');
            expect($config)->toHaveKey('disabled');
            expect($config)->toHaveKey('wildcard_cache_ttl');

            expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
            expect($config['queue'])->toHaveKeys(['connection', 'queue']);
            expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
            expect($config['subscriptions'])->toHaveKeys([
                'auto_generate_secret',
                'max_failures',
                'timeout',
                'signature_algorithm',
                'cleanup_cron',
            ]);
        });
    });

    describe('ServiceProvider completeness', function (): void {
        test('provides() returns all core bindings', function (): void {
            $provider = new EventsServiceProvider(app());
            $provides = $provider->provides();

            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(ConditionEngine::class);
            expect($provides)->toContain(ConditionEngineContract::class);
            expect($provides)->toContain(\ZeroBoiler\Events\ActionResolver::class);
            expect($provides)->toContain(TriggerBuilder::class);
            expect($provides)->toContain(SubscriptionBuilder::class);
            expect($provides)->toContain(EventScheduler::class);
        });

        test('register() binds singletons correctly', function (): void {
            $provider = new EventsServiceProvider($this->app);
            $provider->register();

            expect($this->app->bound(EventManager::class))->toBeTrue();
            expect($this->app->bound(ConditionEngine::class))->toBeTrue();
            expect($this->app->bound(ConditionEngineContract::class))->toBeTrue();
            expect($this->app->bound(\ZeroBoiler\Events\ActionResolver::class))->toBeTrue();
            expect($this->app->bound(EventScheduler::class))->toBeTrue();
        });
    });
});
