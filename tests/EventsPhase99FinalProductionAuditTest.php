<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Database\Factories\EventLogFactory;
use ZeroBoiler\Events\Database\Factories\SubscriptionFactory;
use ZeroBoiler\Events\Database\Factories\TriggerFactory;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    // Fresh DB per test
    $this->app = $this->createApplication();
});

describe('Phase 99 — Final Production Audit', function (): void {
    describe('Factory static model property (Laravel 13+)', function (): void {
        it('TriggerFactory has static $model property', function (): void {
            $reflection = new ReflectionProperty(TriggerFactory::class, 'model');
            expect($reflection->isStatic())->toBeTrue();
        });

        it('EventLogFactory has static $model property', function (): void {
            $reflection = new ReflectionProperty(EventLogFactory::class, 'model');
            expect($reflection->isStatic())->toBeTrue();
        });

        it('SubscriptionFactory has static $model property', function (): void {
            $reflection = new ReflectionProperty(SubscriptionFactory::class, 'model');
            expect($reflection->isStatic())->toBeTrue();
        });

        it('TriggerFactory model points to Trigger', function (): void {
            expect(TriggerFactory::$model)->toBe(Trigger::class);
        });

        it('EventLogFactory model points to EventLog', function (): void {
            expect(EventLogFactory::$model)->toBe(EventLog::class);
        });

        it('SubscriptionFactory model points to Subscription', function (): void {
            expect(SubscriptionFactory::$model)->toBe(Subscription::class);
        });
    });

    describe('EventManager::registerScheduler throws on misconfiguration', function (): void {
        it('throws RuntimeException when EventScheduler binding is missing from container', function (): void {
            $this->app->forgetInstance(EventScheduler::class);
            $this->app->bind(EventScheduler::class, fn (): null => null);

            $eventManager = $this->app->make(EventManager::class);
            $schedule = new Illuminate\Console\Scheduling\Schedule;

            expect(fn (): mixed => $eventManager->registerScheduler($schedule))
                ->toThrow(RuntimeException::class, 'EventScheduler could not be resolved');
        });
    });

    describe('EventManager consistency — all container resolutions throw on failure', function (): void {
        it('on() throws when TriggerBuilder cannot be resolved', function (): void {
            $this->app->bind(\ZeroBoiler\Events\TriggerBuilder::class, fn (): null => null);

            $eventManager = $this->app->make(EventManager::class);

            expect(fn (): mixed => $eventManager->on('test.event'))
                ->toThrow(RuntimeException::class, 'TriggerBuilder could not be resolved');
        });

        it('subscribe() throws when SubscriptionBuilder cannot be resolved', function (): void {
            $this->app->bind(\ZeroBoiler\Events\SubscriptionBuilder::class, fn (): null => null);

            $eventManager = $this->app->make(EventManager::class);

            expect(fn (): mixed => $eventManager->subscribe('test.event', 'https://example.com'))
                ->toThrow(RuntimeException::class, 'SubscriptionBuilder could not be resolved');
        });
    });

    describe('PHPStan config covers migrations and factories', function (): void {
        it('phpstan.neon.dist includes database/migrations in paths', function (): void {
            $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($neon)->toContain('database/migrations');
        });

        it('phpstan.neon.dist includes database/factories in paths', function (): void {
            $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($neon)->toContain('database/factories');
        });

        it('phpstan.neon.dist ignores Schema facade calls', function (): void {
            $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($neon)->toContain('Schema');
        });

        it('phpstan.neon.dist ignores $table property access in closures', function (): void {
            $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($neon)->toContain('$table');
        });
    });

    describe('All source files have declare(strict_types=1)', function (): void {
        it('verifies strict types across all production source files', function (): void {
            $srcDir = __DIR__.'/../src';
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            $violations = [];
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getRealPath());
                $relative = str_replace($srcDir.'/', '', $file->getRealPath());
                if (! str_contains($contents, 'declare(strict_types=1)')) {
                    $violations[] = $relative;
                }
            }

            expect($violations)->toBeEmpty('Files missing strict_types: '.implode(', ', $violations));
        });
    });

    describe('All source classes are final', function (): void {
        it('verifies all classes in src/ are final', function (): void {
            $srcDir = __DIR__.'/../src';
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            $nonFinal = [];
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getRealPath());
                if (! preg_match('/\bclass\s+\w+/', $contents)) {
                    continue;
                }
                // Skip anonymous classes
                preg_match_all('/^class\s+(\w+)/m', $contents, $matches);
                foreach ($matches[1] as $className) {
                    // Check if the class has 'final' keyword before it
                    $pattern = '/\bfinal\s+class\s+'.preg_quote($className, '/').'/';
                    if (! preg_match($pattern, $contents)) {
                        $relative = str_replace($srcDir.'/', '', $file->getRealPath());
                        $nonFinal[] = $relative.':'.$className;
                    }
                }
            }

            expect($nonFinal)->toBeEmpty('Non-final classes: '.implode(', ', $nonFinal));
        });
    });

    describe('DomainEvent immutability', function (): void {
        it('readonly properties cannot be modified after construction', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);

            $ref = new ReflectionClass($event);
            $readonlyProps = ['eventId', 'eventType', 'payload', 'occurredAt'];

            foreach ($readonlyProps as $prop) {
                expect($ref->getProperty($prop)->isReadOnly())->toBeTrue("{$prop} should be readonly");
            }
        });

        it('fromArray preserves original eventId and occurredAt', function (): void {
            $original = DomainEvent::occur('test.event', ['key' => 'value']);
            $data = $original->toArray();

            $restored = DomainEvent::fromArray($data);

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->occurredAt->format('U'))->toBe($original->occurredAt->format('U'));
            expect($restored->payload)->toBe($original->payload);
        });
    });

    describe('EventLog status constants completeness', function (): void {
        it('has exactly 4 statuses', function (): void {
            expect(EventLog::$statuses)->toHaveCount(4);
        });

        it('contains all required statuses', function (): void {
            expect(EventLog::$statuses)->toContain(
                EventLog::STATUS_PENDING,
                EventLog::STATUS_DISPATCHED,
                EventLog::STATUS_COMPLETED,
                EventLog::STATUS_FAILED,
            );
        });
    });

    describe('WildcardMatcher readonly and final', function (): void {
        it('is readonly final class', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });
    });
});
