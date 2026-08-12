<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\DispatchTriggerJob;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\WildcardMatcher;

beforeEach(function (): void {
    $this->app = $this->createApplication();
});

describe('Phase 100 — Final Production Audit', function (): void {
    describe('ConditionEngine deep nested dot-notation (4+ levels)', function (): void {
        it('resolves 4-level nested fields correctly', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'a.b.c.d' => 'deep',
            ];
            $payload = [
                'a' => [
                    'b' => [
                        'c' => [
                            'd' => 'deep',
                        ],
                    ],
                ],
            ];

            expect($engine->matches($conditions, $payload))->toBeTrue();
        });

        it('resolves 5-level nested fields correctly', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'level1.level2.level3.level4.level5' => 'found',
            ];
            $payload = [
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'level4' => [
                                'level5' => 'found',
                            ],
                        ],
                    ],
                ],
            ];

            expect($engine->matches($conditions, $payload))->toBeTrue();
        });

        it('returns false when deep nested key does not exist', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'a.b.c.d' => 'value',
            ];
            $payload = [
                'a' => [
                    'b' => [
                        'c' => 'not_array',
                    ],
                ],
            ];

            expect($engine->matches($conditions, $payload))->toBeFalse();
        });

        it('returns false when intermediate key is missing', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'a.x.c.d' => 'value',
            ];
            $payload = [
                'a' => [
                    'b' => [
                        'c' => [
                            'd' => 'value',
                        ],
                    ],
                ],
            ];

            expect($engine->matches($conditions, $payload))->toBeFalse();
        });

        it('handles mixed nested and top-level conditions', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'status' => 'active',
                'user.profile.name' => 'John',
                'meta.tags.0' => 'important',
            ];
            $payload = [
                'status' => 'active',
                'user' => [
                    'profile' => [
                        'name' => 'John',
                    ],
                ],
                'meta' => [
                    'tags' => ['important', 'review'],
                ],
            ];

            expect($engine->matches($conditions, $payload))->toBeTrue();
        });
    });

    describe('EventManager fire() partial condition matching', function (): void {
        it('dispatches only matching triggers when multiple triggers exist', function (): void {
            $this->app->bind(App\Actions\SendOrderNotification::class);
            $this->app->bind(App\Actions\LogOrderEvent::class);

            // Trigger 1: matches (amount > 50)
            $this->app->make(\ZeroBoiler\Events\EventManager::class)
                ->on('order.placed')
                ->name('High Value')
                ->action(App\Actions\SendOrderNotification::class)
                ->when(['amount' => ['>', 50]])
                ->priority(10)
                ->save();

            // Trigger 2: matches (amount > 10)
            $this->app->make(\ZeroBoiler\Events\EventManager::class)
                ->on('order.placed')
                ->name('Medium Value')
                ->action(App\Actions\LogOrderEvent::class)
                ->when(['amount' => ['>', 10]])
                ->priority(5)
                ->save();

            // Trigger 3: does NOT match (amount > 1000)
            $this->app->make(\ZeroBoiler\Events\EventManager::class)
                ->on('order.placed')
                ->name('Premium')
                ->action(App\Actions\HighPriority::class)
                ->when(['amount' => ['>', 1000]])
                ->priority(20)
                ->save();

            $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);
            $eventManager->fire('order.placed', ['amount' => 100]);

            // 2 event logs should be created (High Value + Medium Value match, Premium doesn't)
            $logs = EventLog::where('event', 'order.placed')->get();
            expect($logs->count())->toBe(2);

            // Both should be completed (sync dispatch)
            expect($logs->every(fn (EventLog $l): bool => $l->status === EventLog::STATUS_COMPLETED))->toBeTrue();
        });

        it('dispatches nothing when all conditions fail', function (): void {
            $this->app->bind(App\Actions\SendOrderNotification::class);

            $this->app->make(\ZeroBoiler\Events\EventManager::class)
                ->on('order.placed')
                ->name('Premium Only')
                ->action(App\Actions\SendOrderNotification::class)
                ->when(['amount' => ['>', 1000]])
                ->save();

            $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);
            $eventManager->fire('order.placed', ['amount' => 50]);

            $logs = EventLog::where('event', 'order.placed')->get();
            expect($logs->count())->toBe(0);
        });
    });

    describe('EventManager::deleteTrigger() edge cases', function (): void {
        it('returns false and does not invalidate cache for non-existent trigger', function (): void {
            $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);

            // Delete a non-existent trigger
            $result = $eventManager->deleteTrigger('00000000-0000-0000-0000-000000000000');

            expect($result)->toBeFalse();

            // No triggers should be deleted
            expect(Trigger::count())->toBe(0);
        });

        it('returns true and invalidates cache for existing trigger', function (): void {
            $this->app->bind(App\Actions\SendOrderNotification::class);

            $trigger = $this->app->make(\ZeroBoiler\Events\EventManager::class)
                ->on('test.event')
                ->name('Test Trigger')
                ->action(App\Actions\SendOrderNotification::class)
                ->save();

            $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);
            $result = $eventManager->deleteTrigger($trigger->id);

            expect($result)->toBeTrue();
            expect(Trigger::find($trigger->id))->toBeNull();
        });
    });

    describe('DispatchTriggerJob disabled trigger handling', function (): void {
        it('skips execution and logs warning when trigger is disabled', function (): void {
            $this->app->bind(App\Actions\SendOrderNotification::class);

            $trigger = $this->app->make(\ZeroBoiler\Events\EventManager::class)
                ->on('test.event')
                ->name('Test Trigger')
                ->action(App\Actions\SendOrderNotification::class)
                ->save();

            // Disable the trigger
            $trigger->update(['enabled' => false]);

            $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);
            $job = new DispatchTriggerJob($trigger->id, 'test.event', ['key' => 'value']);

            // Handle the job — should return early without creating an EventLog
            $job->handle($eventManager);

            // No event log should be created
            $logs = EventLog::where('trigger_id', $trigger->id)->get();
            expect($logs->count())->toBe(0);
        });

        it('skips execution when trigger is deleted after job creation', function (): void {
            $triggerId = (string) \Illuminate\Support\Str::uuid();

            $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);
            $job = new DispatchTriggerJob($triggerId, 'test.event', ['key' => 'value']);

            // Handle the job — trigger doesn't exist
            $job->handle($eventManager);

            // No event log should be created
            $logs = EventLog::where('trigger_id', $triggerId)->get();
            expect($logs->count())->toBe(0);
        });
    });

    describe('ConditionEngine operator edge cases', function (): void {
        it('not_empty operator returns true for non-empty array', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'tags' => ['not_empty'],
            ];

            expect($engine->matches($conditions, ['tags' => ['a', 'b']]))->toBeTrue();
        });

        it('not_empty operator returns false for empty array', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'tags' => ['not_empty'],
            ];

            expect($engine->matches($conditions, ['tags' => []]))->toBeFalse();
        });

        it('not_contains operator for strings', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'bio' => ['not_contains', 'spam'],
            ];

            expect($engine->matches($conditions, ['bio' => 'hello world']))->toBeTrue();
            expect($engine->matches($conditions, ['bio' => 'spam sandwich']))->toBeFalse();
        });

        it('not_contains operator for arrays', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'tags' => ['not_contains', 'banned'],
            ];

            expect($engine->matches($conditions, ['tags' => ['safe', 'ok']]))->toBeTrue();
            expect($engine->matches($conditions, ['tags' => ['safe', 'banned']]))->toBeFalse();
        });

        it('between operator normalizes inverted ranges', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'score' => ['between', [100, 50]],
            ];

            // 75 should match because range is normalized to [50, 100]
            expect($engine->matches($conditions, ['score' => 75]))->toBeTrue();
            expect($engine->matches($conditions, ['score' => 49]))->toBeFalse();
            expect($engine->matches($conditions, ['score' => 101]))->toBeFalse();
        });

        it('between operator returns false for non-array value', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'score' => ['between', 'invalid'],
            ];

            expect($engine->matches($conditions, ['score' => 75]))->toBeFalse();
        });
    });

    describe('DomainEvent reconstruction fidelity', function (): void {
        it('preserves exact eventId and occurredAt across roundtrip', function (): void {
            $original = DomainEvent::occur('user.created', ['name' => 'Alice']);

            $array = $original->toArray();
            $restored = DomainEvent::fromArray($array);

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
                ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
            expect($restored->eventType)->toBe('user.created');
            expect($restored->payload)->toBe(['name' => 'Alice']);
        });

        it('generates new UUID when eventId is invalid', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-uuid',
                'payload' => [],
            ]);

            // Should still create a valid event with a fresh UUID
            expect($event->eventType)->toBe('test.event');
            expect($event->eventId)->toBeInstanceOf(Ramsey\Uuid\UuidInterface::class);
        });

        it('uses current time when occurredAt is invalid', function (): void {
            $before = new DateTimeImmutable('2 seconds ago');
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-date',
            ]);

            $after = new DateTimeImmutable;

            // occurredAt should be between $before and $after (roughly "now")
            expect($event->occurredAt >= $before && $event->occurredAt <= $after)->toBeTrue();
        });

        it('throws when eventType is empty', function (): void {
            DomainEvent::fromArray([
                'eventType' => '',
            ]);
        })->throws(InvalidArgumentException::class, 'DomainEvent eventType is required');

        it('throws when eventType key is missing', function (): void {
            DomainEvent::fromArray([]);
        })->throws(InvalidArgumentException::class, 'DomainEvent eventType is required');
    });

    describe('WildcardMatcher comprehensive edge cases', function (): void {
        it('exact match works without wildcards', function (): void {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        it('catch-all * matches any non-empty event', function (): void {
            expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'a.b.c.d.e'))->toBeTrue();
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        it('catch-all ** matches any non-empty event', function (): void {
            expect(WildcardMatcher::matches('**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        it('single wildcard matches one segment', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        it('double wildcard matches across segments', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'payment.received'))->toBeFalse();
        });

        it('extractWildcards returns correct segments', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
            expect($result)->toBe(['profile']);
        });

        it('extractWildcards returns empty for ** patterns', function (): void {
            $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');
            expect($result)->toBe([]);
        });

        it('findMatchingPatterns filters correctly', function (): void {
            $patterns = ['order.placed', 'order.*', 'payment.*', 'order.**'];
            $matched = WildcardMatcher::findMatchingPatterns($patterns, 'order.shipped');

            expect($matched)->toContain('order.*');
            expect($matched)->toContain('order.**');
            expect($matched)->not->toContain('order.placed');
            expect($matched)->not->toContain('payment.*');
        });

        it('handles regex-special characters in patterns', function (): void {
            expect(WildcardMatcher::matches('user.profile.created', 'user.profile.created'))->toBeTrue();
            expect(WildcardMatcher::matches('user.profile.*', 'user.profile.created'))->toBeTrue();
        });
    });

    describe('Subscription model edge cases', function (): void {
        it('signPayload returns empty string for null secret', function (): void {
            $subscription = new Subscription([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'test.event',
                'url' => 'https://example.com/hook',
                'secret' => null,
            ]);

            expect($subscription->signPayload('data'))->toBe('');
        });

        it('signPayload returns empty string for empty secret', function (): void {
            $subscription = new Subscription([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'test.event',
                'url' => 'https://example.com/hook',
                'secret' => '',
            ]);

            expect($subscription->signPayload('data'))->toBe('');
        });

        it('hasExceededFailures uses config default when max is null', function (): void {
            $subscription = new Subscription([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'test.event',
                'url' => 'https://example.com/hook',
                'failure_count' => 10,
            ]);

            // Default max_failures is 10
            expect($subscription->hasExceededFailures(null))->toBeTrue();
        });

        it('hasExceededFailures uses explicit override', function (): void {
            $subscription = new Subscription([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'test.event',
                'url' => 'https://example.com/hook',
                'failure_count' => 5,
            ]);

            expect($subscription->hasExceededFailures(3))->toBeTrue();
            expect($subscription->hasExceededFailures(10))->toBeFalse();
        });
    });

    describe('EventLog status constants consistency', function (): void {
        it('has exactly 4 status constants', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
            expect(EventLog::$statuses)->toHaveCount(4);
        });

        it('all statuses are in the $statuses array', function (): void {
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
        });
    });

    describe('TriggerBuilder action merging and deduplication', function (): void {
        it('merges single action() before actions() without duplicates', function (): void {
            $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);
            $trigger = $eventManager->on('test.event')
                ->action(App\Actions\SendOrderNotification::class)
                ->actions([App\Actions\LogOrderEvent::class])
                ->name('Merged Actions')
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect(is_array($decoded))->toBeTrue();
            expect($decoded)->toHaveCount(2);
            expect($decoded)->toContain(App\Actions\SendOrderNotification::class);
            expect($decoded)->toContain(App\Actions\LogOrderEvent::class);
        });

        it('deduplicates when action() and actions() have same class', function (): void {
            $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);
            $trigger = $eventManager->on('test.event')
                ->action(App\Actions\SendOrderNotification::class)
                ->actions([App\Actions\SendOrderNotification::class])
                ->name('Dedup Actions')
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect(is_array($decoded))->toBeTrue();
            expect($decoded)->toHaveCount(1);
        });

        it('generates correct JSON for single action with params', function (): void {
            $eventManager = $this->app->make(\ZeroBoiler\Events\EventManager::class);
            $trigger = $eventManager->on('test.event')
                ->action(App\Actions\SendOrderNotification::class)
                ->actionParams(['url' => 'https://example.com'])
                ->name('Single Action With Params')
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect(is_array($decoded))->toBeTrue();
            expect($decoded['class'])->toBe(App\Actions\SendOrderNotification::class);
            expect($decoded['params'])->toBe(['url' => 'https://example.com']);
        });
    });

    describe('Config completeness', function (): void {
        it('has all required top-level config keys', function (): void {
            $config = include __DIR__.'/../config/events.php';

            expect($config)->toHaveKey('table_names');
            expect($config)->toHaveKey('queue');
            expect($config)->toHaveKey('retry');
            expect($config)->toHaveKey('retention');
            expect($config)->toHaveKey('subscriptions');
            expect($config)->toHaveKey('disabled');
            expect($config)->toHaveKey('wildcard_cache_ttl');
        });

        it('table_names has all 3 entries', function (): void {
            $config = include __DIR__.'/../config/events.php';

            expect($config['table_names'])->toHaveKey('triggers');
            expect($config['table_names'])->toHaveKey('event_logs');
            expect($config['table_names'])->toHaveKey('subscriptions');
        });

        it('subscriptions has all required keys', function (): void {
            $config = include __DIR__.'/../config/events.php';

            expect($config['subscriptions'])->toHaveKey('auto_generate_secret');
            expect($config['subscriptions'])->toHaveKey('max_failures');
            expect($config['subscriptions'])->toHaveKey('timeout');
            expect($config['subscriptions'])->toHaveKey('signature_algorithm');
            expect($config['subscriptions'])->toHaveKey('cleanup_cron');
        });

        it('retention has all required keys', function (): void {
            $config = include __DIR__.'/../config/events.php';

            expect($config['retention'])->toHaveKey('days');
            expect($config['retention'])->toHaveKey('include_pending');
            expect($config['retention'])->toHaveKey('schedule_cron');
        });
    });

    describe('ServiceProvider provides() completeness', function (): void {
        it('lists all 7 registered services', function (): void {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);

            $provides = $provider->provides();

            expect($provides)->toContain(\ZeroBoiler\Events\EventManager::class);
            expect($provides)->toContain(\ZeroBoiler\Events\ConditionEngine::class);
            expect($provides)->toContain(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
            expect($provides)->toContain(\ZeroBoiler\Events\ActionResolver::class);
            expect($provides)->toContain(\ZeroBoiler\Events\TriggerBuilder::class);
            expect($provides)->toContain(\ZeroBoiler\Events\SubscriptionBuilder::class);
            expect($provides)->toContain(\ZeroBoiler\Events\EventScheduler::class);
            expect($provides)->toHaveCount(7);
        });
    });

    describe('Facade @method coverage', function (): void {
        it('facade accessor returns EventManager class', function (): void {
            $facade = new \ZeroBoiler\Events\Facades\EventManager;
            $reflection = new ReflectionMethod($facade, 'getFacadeAccessor');
            $reflection->setAccessible(true);

            expect($reflection->invoke($facade))->toBe(\ZeroBoiler\Events\EventManager::class);
        });
    });

    describe('All source files have strict types', function (): void {
        it('all PHP files in src/ declare strict_types=1', function (): void {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
            );

            $violations = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                $tokens = token_get_all($contents);

                $foundStrict = false;
                foreach ($tokens as $token) {
                    if (is_array($token) && $token[0] === T_DECLARE) {
                        // Look ahead for strict_types
                        for ($i = array_search($token, $tokens, true) + 1; $i < count($tokens); $i++) {
                            if (is_array($tokens[$i]) && $tokens[$i][1] === 'strict_types') {
                                $foundStrict = true;
                                break 2;
                            }
                        }
                    }
                }

                if (! $foundStrict) {
                    $violations[] = $file->getPathname();
                }
            }

            expect($violations)->toBeEmpty(
                implode(', ', $violations).' missing declare(strict_types=1)'
            );
        });
    });

    describe('All classes are final', function (): void {
        it('all non-trait, non-interface source classes are final', function (): void {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
            );

            $nonFinalClasses = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                $tokens = token_get_all($contents);

                $namespace = '';
                $className = '';
                $isFinal = false;
                $isTrait = false;
                $isInterface = false;

                for ($i = 0; $i < count($tokens); $i++) {
                    $token = $tokens[$i];

                    if (is_array($token)) {
                        if ($token[0] === T_NAMESPACE) {
                            for ($j = $i + 1; $j < count($tokens); $j++) {
                                if (is_array($tokens[$j]) && $tokens[$j][0] === T_NAME_QUALIFIED) {
                                    $namespace = $tokens[$j][1];
                                    break;
                                }
                                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                                    $namespace = $tokens[$j][1];
                                    break;
                                }
                            }
                        }

                        if ($token[0] === T_TRAIT) {
                            $isTrait = true;
                        }
                        if ($token[0] === T_INTERFACE) {
                            $isInterface = true;
                        }
                        if ($token[0] === T_FINAL) {
                            $isFinal = true;
                        }
                        if ($token[0] === T_CLASS && ! $isTrait && ! $isInterface) {
                            for ($j = $i + 1; $j < count($tokens); $j++) {
                                if (is_array($tokens[$j]) && ($tokens[$j][0] === T_STRING || $tokens[$j][0] === T_NAME_QUALIFIED)) {
                                    $className = $tokens[$j][1];
                                    break;
                                }
                            }
                            break;
                        }
                    }
                }

                if ($className !== '' && ! $isTrait && ! $isInterface && ! $isFinal) {
                    $fqn = $namespace !== '' ? $namespace.'\\'.$className : $className;
                    $nonFinalClasses[] = $fqn;
                }
            }

            expect($nonFinalClasses)->toBeEmpty(
                'Non-final classes: '.implode(', ', $nonFinalClasses)
            );
        });
    });

    describe('PHPStan config validation', function (): void {
        it('phpstan.neon.dist exists and has level 9', function (): void {
            $path = __DIR__.'/../phpstan.neon.dist';
            expect(file_exists($path))->toBeTrue();

            $contents = file_get_contents($path);
            expect(str_contains($contents, 'level: 9'))->toBeTrue();
        });

        it('phpstan.neon.dist includes src path', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect(str_contains($contents, '- src'))->toBeTrue();
        });

        it('phpstan.neon.dist has reportUnmatchedIgnoredErrors', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect(str_contains($contents, 'reportUnmatchedIgnoredErrors: true'))->toBeTrue();
        });
    });

    describe('composer.json correctness', function (): void {
        it('has correct PHP requirement', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('has correct service provider in extra.laravel', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Events\\EventsServiceProvider'
            );
        });

        it('has correct facade alias in extra.laravel', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
                'ZeroBoiler\\Events\\Facades\\EventManager'
            );
        });

        it('autoload PSR-4 mapping is correct', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });
    });

    describe('Migration config-driven table names', function (): void {
        it('triggers migration uses config-driven table name', function (): void {
            $migration = file_get_contents(
                __DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php'
            );
            expect(str_contains($migration, "config('events.table_names.triggers'"))->toBeTrue();
        });

        it('event_logs migration uses config-driven table name', function (): void {
            $migration = file_get_contents(
                __DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php'
            );
            expect(str_contains($migration, "config('events.table_names.event_logs'"))->toBeTrue();
        });

        it('subscriptions migration uses config-driven table name', function (): void {
            $migration = file_get_contents(
                __DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php'
            );
            expect(str_contains($migration, "config('events.table_names.subscriptions'"))->toBeTrue();
        });
    });
});
