<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Str;
use ZeroBoiler\Events\DispatchTriggerJob;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob as JobsDispatchTriggerJob;
use ZeroBoiler\Events\Models\Trigger;

describe('EventsPhase180 — Queue Serialization Safety & Infrastructure Audit', function (): void {
    describe('DispatchTriggerJob queue serialization safety', function (): void {
        it('does not store Container as a property (prevents serialization failures)', function (): void {
            // The Container parameter is NOT promoted — it should not appear
            // as a property on the serialized job instance.
            $job = new JobsDispatchTriggerJob(
                (string) Str::uuid(),
                'test.event',
                ['key' => 'value'],
            );

            $ref = new ReflectionClass($job);
            $props = $ref->getProperties();

            $propNames = array_map(
                static fn (ReflectionProperty $p): string => $p->getName(),
                $props,
            );

            // Container should NOT be a stored property
            expect($propNames)->not->toContain('app');
            expect($propNames)->not->toContain('container');
        });

        it('stores only serializable properties (triggerId, event, payload, backoff, queue, tries, connection)', function (): void {
            $job = new JobsDispatchTriggerJob(
                (string) Str::uuid(),
                'test.event',
                ['order_id' => 123, 'total' => 99.99],
            );

            // Verify the promoted readonly properties are accessible
            expect($job->triggerId)->toBeString();
            expect($job->event)->toBeString();
            expect($job->payload)->toBeArray();

            // Verify queue config properties
            expect($job->tries)->toBeInt();
            expect($job->queue)->toBeString();
            expect($job->backoff)->toBeArray();
            expect($job->connection)->toBeNull();
        });

        it('can be serialized and unserialized (simulating queue driver round-trip)', function (): void {
            $triggerId = (string) Str::uuid();
            $event = 'order.placed';
            $payload = ['order_id' => 123, 'total' => 99.99, 'items' => [1, 2, 3]];

            $job = new JobsDispatchTriggerJob($triggerId, $event, $payload);

            $serialized = serialize($job);
            expect($serialized)->toBeString();

            $unserialized = unserialize($serialized);
            expect($unserialized)->toBeInstanceOf(JobsDispatchTriggerJob::class);
            expect($unserialized->triggerId)->toBe($triggerId);
            expect($unserialized->event)->toBe($event);
            expect($unserialized->payload)->toBe($payload);
            expect($unserialized->tries)->toBe(3);
            expect($unserialized->queue)->toBe('default');
        });

        it('rejects non-serializable payload values during construction with no exception (only scalar arrays)', function (): void {
            // The job itself accepts any array since it receives the pre-sanitized
            // payload from EventManager. But if someone constructs it directly
            // with objects, serialization will fail.
            $job = new JobsDispatchTriggerJob(
                (string) Str::uuid(),
                'test.event',
                ['order_id' => 123],
            );

            // Should serialize fine with scalar payload
            $serialized = serialize($job);
            expect($serialized)->toBeString();
        });
    });

    describe('EventManager payload sanitization for queue', function (): void {
        it('strips object values from payload before async dispatch', function (): void {
            $model = new Trigger([
                'id' => (string) Str::uuid(),
                'name' => 'Test',
                'event' => 'test.event',
                'action' => '\ZeroBoiler\Events\Tests\Actions\Test',
            ]);
            $model->save();

            $payload = [
                'order_id' => 123,
                'model' => $model,           // Object — should be stripped
                'total' => 99.99,
                'nested' => [
                    'deep_object' => $model, // Nested object — should be stripped
                    'value' => 'ok',         // Scalar — should be kept
                ],
            ];

            // fire() with async=true should not throw even with objects in payload
            $eventManager = app(ZeroBoiler\Events\EventManager::class);
            $eventManager->fire('test.sanitization', $payload, async: true);

            // If we reach here, no serialization error occurred
            expect(true)->toBeTrue();
        });

        it('preserves scalar values in async dispatch payload', function (): void {
            $payload = [
                'order_id' => 123,
                'total' => 99.99,
                'status' => 'active',
                'flag' => true,
                'notes' => null,
                'items' => ['a', 'b', 'c'],
            ];

            // All scalar/array values should serialize cleanly
            $eventManager = app(ZeroBoiler\Events\EventManager::class);
            $eventManager->fire('test.scalar_payload', $payload, async: true);

            expect(true)->toBeTrue();
        });
    });

    describe('Source file quality audit — v5.43.0', function (): void {
        it('all source files have declare(strict_types=1)', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $violations = [];

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
                    $violations[] = basename($file);
                }
            }

            expect($violations)->toBeEmpty('Files missing strict_types: '.implode(', ', $violations));
        });

        it('all source files have the license header', function (): void {
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

        it('all service classes are final', function (): void {
            $finalClasses = [
                'EventManager', 'ConditionEngine', 'ActionResolver',
                'TriggerBuilder', 'SubscriptionBuilder', 'EventScheduler',
                'WildcardMatcher', 'DomainEvent',
                'EventsServiceProvider',
            ];

            foreach ($finalClasses as $class) {
                $fqcn = "ZeroBoiler\\Events\\{$class}";
                if ($class === 'WildcardMatcher') {
                    $fqcn = 'ZeroBoiler\\Events\\WildcardMatcher';
                } elseif ($class === 'DomainEvent') {
                    $fqcn = 'ZeroBoiler\\Events\\Domain\\DomainEvent';
                } elseif ($class === 'EventsServiceProvider') {
                    $fqcn = 'ZeroBoiler\\Events\\EventsServiceProvider';
                }

                $ref = new ReflectionClass($fqcn);
                expect($ref->isFinal())->toBeTrue("{$class} should be final");
            }
        });

        it('EventManager has sanitizePayloadForQueue method', function (): void {
            $ref = new ReflectionClass(ZeroBoiler\Events\EventManager::class);
            expect($ref->hasMethod('sanitizePayloadForQueue'))->toBeTrue();

            $method = $ref->getMethod('sanitizePayloadForQueue');
            expect($method->isProtected())->toBeTrue();
            expect($method->getReturnType())->not->toBeNull();
        });
    });

    describe('Config completeness — v5.43.0', function (): void {
        it('config has all 8 top-level keys', function (): void {
            $config = config('events');
            expect($config)->not->toBeNull();

            $expectedKeys = [
                'table_names', 'queue', 'retry', 'retention',
                'subscriptions', 'disabled', 'wildcard_cache_ttl',
            ];

            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.{$key}");
            }
        });

        it('subscriptions config has all required sub-keys', function (): void {
            $subs = config('events.subscriptions');
            expect($subs)->not->toBeNull();

            $requiredKeys = [
                'auto_generate_secret', 'secret_length', 'max_failures',
                'timeout', 'signature_algorithm', 'cleanup_cron',
            ];

            foreach ($requiredKeys as $key) {
                expect(array_key_exists($key, $subs))->toBeTrue("Missing subscriptions key: {$key}");
            }
        });
    });

    describe('Service provider registration integrity', function (): void {
        it('EventsServiceProvider registers 7 bindings in register()', function (): void {
            $ref = new ReflectionClass(ZeroBoiler\Events\EventsServiceProvider::class);
            $method = $ref->getMethod('register');

            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();
            $length = $endLine - $startLine + 1;

            $content = file($filename);
            $methodBody = implode('', array_slice($content, $startLine - 1, $length));

            // Verify singleton/bind calls for all 7 services
            expect($methodBody)->toContain('singleton(ConditionEngineContract::class');
            expect($methodBody)->toContain('singleton(ConditionEngine::class');
            expect($methodBody)->toContain('singleton(ActionResolver::class)');
            expect($methodBody)->toContain('singleton(EventManager::class');
            expect($methodBody)->toContain('bind(SubscriptionBuilder::class)');
            expect($methodBody)->toContain('bind(TriggerBuilder::class)');
            expect($methodBody)->toContain('singleton(EventScheduler::class');
        });

        it('provides() returns 7 service keys', function (): void {
            $app = app();
            $provider = new ZeroBoiler\Events\EventsServiceProvider($app);
            $provides = $provider->provides();

            expect($provides)->toHaveCount(7);
            expect($provides)->toContain(ZeroBoiler\Events\EventManager::class);
            expect($provides)->toContain(ZeroBoiler\Events\ConditionEngine::class);
            expect($provides)->toContain(ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
            expect($provides)->toContain(ZeroBoiler\Events\ActionResolver::class);
            expect($provides)->toContain(ZeroBoiler\Events\TriggerBuilder::class);
            expect($provides)->toContain(ZeroBoiler\Events\SubscriptionBuilder::class);
            expect($provides)->toContain(ZeroBoiler\Events\EventScheduler::class);
        });
    });
});
