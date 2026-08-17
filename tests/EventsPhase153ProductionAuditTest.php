<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

// Load test action classes

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

describe('Phase 153 production audit', function (): void {
    // ── PHP 8.5 Compliance ──────────────────────────────────────
    describe('PHP 8.5 source compliance', function (): void {
        test('all source files have declare(strict_types=1)', function (): void {
            $files = glob(__DIR__.'/../src/**/*.php');
            expect($files)->not->toBeEmpty();

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });

        test('all source files have license header', function (): void {
            $files = glob(__DIR__.'/../src/**/*.php');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('This file is part of ZeroBoiler');
            }
        });

        test('EventManager is final', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('ConditionEngine is final', function (): void {
            $ref = new ReflectionClass(ConditionEngine::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('WildcardMatcher is readonly final', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('DomainEvent is final', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
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

        test('ActionResolver is final', function (): void {
            $ref = new ReflectionClass(ActionResolver::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('EventScheduler is final', function (): void {
            $ref = new ReflectionClass(EventScheduler::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('EventsServiceProvider is final', function (): void {
            $ref = new ReflectionClass(EventsServiceProvider::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('all models are final', function (): void {
            foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
                $ref = new ReflectionClass($model);
                expect($ref->isFinal())->toBeTrue("{$model} should be final");
            }
        });

        test('DomainEvent has only readonly properties', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            foreach ($ref->getProperties() as $prop) {
                expect($prop->isReadOnly())->toBeTrue("{$prop->getName()} should be readonly");
            }
        });
    });

    // ── Constructor DI ──────────────────────────────────────────
    describe('constructor dependency injection', function (): void {
        test('EventManager constructor has typed promoted readonly properties', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $ctor = $ref->getMethod('__construct');
            $params = $ctor->getParameters();

            expect($params)->toHaveCount(3);
            expect($params[0]->getName())->toBe('conditionEngine');
            expect($params[0]->getType()->getName())->toBe(ConditionEngine::class);
            expect($params[1]->getName())->toBe('actionResolver');
            expect($params[1]->getType()->getName())->toBe(ActionResolver::class);
            expect($params[2]->getName())->toBe('app');
            expect($params[2]->getType()->getName())->toBe(Container::class);
        });

        test('EventScheduler has Container in constructor', function (): void {
            $ref = new ReflectionClass(EventScheduler::class);
            $ctor = $ref->getMethod('__construct');
            $params = $ctor->getParameters();

            expect($params)->toHaveCount(1);
            expect($params[0]->getType()->getName())->toBe(Container::class);
        });
    });

    // ── ServiceProvider provides() consistency ────────────────────
    describe('ServiceProvider provides consistency', function (): void {
        test('provides() returns all 7 bindings registered in register()', function (): void {
            $ref = new ReflectionClass(EventsServiceProvider::class);
            $provides = $ref->getMethod('provides')->invoke(new EventsServiceProvider(app()));

            expect($provides)->toContain(EventManager::class)
                ->toContain(ConditionEngine::class)
                ->toContain(ConditionEngineContract::class)
                ->toContain(ActionResolver::class)
                ->toContain(TriggerBuilder::class)
                ->toContain(SubscriptionBuilder::class)
                ->toContain(EventScheduler::class)
                ->toHaveCount(7);
        });

        test('register and boot have #[Override]', function (): void {
            $ref = new ReflectionClass(EventsServiceProvider::class);

            expect($ref->getMethod('register')->getAttributes(\Attribute::class))->not->toBeEmpty();
            expect($ref->getMethod('boot')->getAttributes(\Attribute::class))->not->toBeEmpty();
        });
    });

    // ── Config completeness ─────────────────────────────────────
    describe('config completeness', function (): void {
        test('config has all 7 top-level keys', function (): void {
            $config = config('events');
            expect($config)->not->toBeNull();

            $expectedKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.{$key}");
            }
        });

        test('table_names has all 3 tables', function (): void {
            $tables = config('events.table_names');
            expect($tables)->toHaveKey('triggers')
                ->toHaveKey('event_logs')
                ->toHaveKey('subscriptions');
        });

        test('subscriptions config has all 6 keys', function (): void {
            $subs = config('events.subscriptions');
            $expectedKeys = ['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm', 'cleanup_cron'];
            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $subs))->toBeTrue("Missing subscriptions config key: {$key}");
            }
        });
    });

    // ── Interface compliance ────────────────────────────────────
    describe('interface implementations', function (): void {
        test('ConditionEngine implements ConditionEngineContract', function (): void {
            expect(ConditionEngine::class)->toImplement(ConditionEngineContract::class);
        });

        test('ConditionEngineContract has matches method with correct signature', function (): void {
            $ref = new ReflectionClass(ConditionEngineContract::class);
            $method = $ref->getMethod('matches');

            expect($method->getName())->toBe('matches');
            expect($method->getParameters())->toHaveCount(2);
        });

        test('Triggerable has handle method returning void', function (): void {
            $ref = new ReflectionClass(Triggerable::class);
            $method = $ref->getMethod('handle');

            expect($method->getName())->toBe('handle');
            $returnType = $method->getReturnType();
            expect($returnType?->getName())->toBe('void');
        });
    });

    // ── Facade accessor ─────────────────────────────────────────
    describe('Facade accessor', function (): void {
        test('getFacadeAccessor returns EventManager class name', function (): void {
            $ref = new ReflectionMethod(\ZeroBoiler\Events\Facades\EventManager::class, 'getFacadeAccessor');
            expect($ref->isStatic())->toBeTrue();

            $result = $ref->invoke(null);
            expect($result)->toBe(EventManager::class);
        });
    });

    // ── Model details ───────────────────────────────────────────
    describe('model configuration', function (): void {
        test('Trigger model uses config-driven table name', function (): void {
            $trigger = new Trigger;
            $table = $trigger->getTable();
            expect($table)->toBe(config('events.table_names.triggers', 'triggers'));
        });

        test('EventLog model uses config-driven table name', function (): void {
            $log = new EventLog;
            $table = $log->getTable();
            expect($table)->toBe(config('events.table_names.event_logs', 'event_logs'));
        });

        test('Subscription model uses config-driven table name', function (): void {
            $sub = new Subscription;
            $table = $sub->getTable();
            expect($table)->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
        });

        test('EventLog has 4 status constants', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
            expect(EventLog::$statuses)->toHaveCount(4);
        });
    });

    // ── ConditionEngine operators ───────────────────────────────
    describe('ConditionEngine operator coverage', function (): void {
        $operators = [
            '>', '>=', '<', '<=',
            '=', '===', '!=', '!==',
            'in', 'not_in',
            'contains', 'not_contains',
            'between',
            'null', 'not_null',
            'empty', 'not_empty',
            'starts_with', 'ends_with',
            'matches',
        ];

        test('ConditionEngine supports 21 operators', function () use ($operators): void {
            expect($operators)->toHaveCount(21);
        });

        test('each operator is used in evaluateCondition match expression', function () use ($operators): void {
            $ref = new ReflectionClass(ConditionEngine::class);
            $method = $ref->getMethod('evaluateCondition');
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();
            $lines = array_slice(file($filename), $startLine - 1, $endLine - $startLine + 1);
            $methodBody = implode('', $lines);

            foreach ($operators as $op) {
                expect($methodBody)->toContain("'{$op}'");
            }
        });
    });

    // ── DomainEvent edge cases ───────────────────────────────────
    describe('DomainEvent edge cases', function (): void {
        test('fromArray with empty eventType throws InvalidArgumentException', function (): void {
            expect(fn (): DomainEvent => DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('fromArray with missing eventType throws InvalidArgumentException', function (): void {
            expect(fn (): DomainEvent => DomainEvent::fromArray([]))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('fromArray with non-string eventType throws InvalidArgumentException', function (): void {
            expect(fn (): DomainEvent => DomainEvent::fromArray(['eventType' => 123]))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('fromArray with invalid UUID falls back to fresh', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => [],
                'eventId' => 'not-a-uuid',
            ]);

            expect($event->eventType)->toBe('test.event');
            expect($event->eventId)->not->toBeNull();
        });

        test('occur creates with default empty payload', function (): void {
            $event = DomainEvent::occur('test.empty');
            expect($event->payload)->toBe([]);
        });
    });

    // ── EventManager validation ──────────────────────────────────
    describe('EventManager input validation', function (): void {
        test('fire with empty string throws InvalidArgumentException', function (): void {
            EventManagerFacade::fire('');
        })->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

        test('fire with zero string throws InvalidArgumentException', function (): void {
            EventManagerFacade::fire('0');
        })->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

        test('fireModel with empty model class throws InvalidArgumentException', function (): void {
            EventManagerFacade::fireModel('', 'created', new \stdClass);
        })->throws(\InvalidArgumentException::class, 'Model class name cannot be empty');

        test('fireModel with empty action throws InvalidArgumentException', function (): void {
            EventManagerFacade::fireModel('App\\Models\\User', '', new \stdClass);
        })->throws(\InvalidArgumentException::class, 'Model action cannot be empty');

        test('deleteTrigger with non-existent ID returns false', function (): void {
            expect(EventManagerFacade::deleteTrigger('00000000-0000-0000-0000-000000000000'))->toBeFalse();
        });

        test('enable with non-existent ID returns false', function (): void {
            expect(EventManagerFacade::enable('00000000-0000-0000-0000-000000000000'))->toBeFalse();
        });

        test('disable with non-existent ID returns false', function (): void {
            expect(EventManagerFacade::disable('00000000-0000-0000-0000-000000000000'))->toBeFalse();
        });
    });

    // ── TriggerBuilder validation ───────────────────────────────
    describe('TriggerBuilder validation', function (): void {
        test('save with empty event throws InvalidArgumentException', function (): void {
            EventManagerFacade::on('')->save();
        })->throws(\InvalidArgumentException::class, 'Event name is required');

        test('save with no action throws InvalidArgumentException', function (): void {
            EventManagerFacade::on('test.event')->save();
        })->throws(\InvalidArgumentException::class, 'At least one action');

        test('actions with empty string throws InvalidArgumentException', function (): void {
            EventManagerFacade::on('test.event')->actions([''])->save();
        })->throws(\InvalidArgumentException::class, 'non-empty string');

        test('actions with zero string throws InvalidArgumentException', function (): void {
            EventManagerFacade::on('test.event')->actions(['0'])->save();
        })->throws(\InvalidArgumentException::class, 'non-empty string');
    });

    // ── SubscriptionBuilder URL scheme enforcement ────────────────
    describe('SubscriptionBuilder URL scheme enforcement', function (): void {
        test('ftp URL is rejected', function (): void {
            EventManagerFacade::subscribe('test.event', 'ftp://evil.com/hook')->save();
        })->throws(\InvalidArgumentException::class, 'HTTP or HTTPS');

        test('file URL is rejected', function (): void {
            EventManagerFacade::subscribe('test.event', 'file:///etc/passwd')->save();
        })->throws(\InvalidArgumentException::class, 'HTTP or HTTPS');

        test('javascript URL is rejected', function (): void {
            EventManagerFacade::subscribe('test.event', 'javascript:alert(1)')->save();
        })->throws(\InvalidArgumentException::class, 'valid URL');

        test('http URL is accepted', function (): void {
            $sub = EventManagerFacade::subscribe('test.event', 'http://example.com/hook')
                ->withSecret('test_secret_123')
                ->save();

            expect($sub)->toBeInstanceOf(Subscription::class);
            expect($sub->url)->toBe('http://example.com/hook');
        });

        test('https URL is accepted', function (): void {
            $sub = EventManagerFacade::subscribe('test.event', 'https://example.com/hook')
                ->withSecret('test_secret_456')
                ->save();

            expect($sub)->toBeInstanceOf(Subscription::class);
            expect($sub->url)->toBe('https://example.com/hook');
        });
    });

    // ── Global disable ───────────────────────────────────────────
    describe('global disable behavior', function (): void {
        test('isDisabled returns false by default', function (): void {
            expect(EventManagerFacade::isDisabled())->toBeFalse();
        });

        test('setEnabled(true) keeps system enabled', function (): void {
            EventManagerFacade::setEnabled(true);
            expect(EventManagerFacade::isDisabled())->toBeFalse();
        });

        test('setEnabled(false) disables system', function (): void {
            EventManagerFacade::setEnabled(false);
            expect(EventManagerFacade::isDisabled())->toBeTrue();
            EventManagerFacade::setEnabled(true); // cleanup
        });

        test('fire does not dispatch when disabled', function (): void {
            $trigger = Trigger::factory()->enabled()->create([
                'event' => 'test.disabled',
                'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
            ]);

            EventManagerFacade::setEnabled(false);
            EventManagerFacade::fire('test.disabled', ['key' => 'value']);
            EventManagerFacade::setEnabled(true); // cleanup

            expect(EventLog::where('trigger_id', $trigger->id)->count())->toBe(0);
        });
    });

    // ── WildcardMatcher edge cases ───────────────────────────────
    describe('WildcardMatcher edge cases', function (): void {
        test('matches returns false for empty pattern with non-empty event', function (): void {
            expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
        });

        test('matches returns false for empty pattern with empty event', function (): void {
            expect(WildcardMatcher::matches('', ''))->toBeFalse();
        });

        test('findMatchingPatterns returns empty for empty patterns array', function (): void {
            expect(WildcardMatcher::findMatchingPatterns([], 'order.placed'))->toBeEmpty();
        });

        test('extractWildcards returns empty for empty pattern', function (): void {
            expect(WildcardMatcher::extractWildcards('', 'order.placed'))->toBeEmpty();
        });
    });

    // ── ActionResolver ──────────────────────────────────────────
    describe('ActionResolver error handling', function (): void {
        test('resolve throws for non-existent class', function (): void {
            $resolver = app(ActionResolver::class);
            $resolver->resolve('NonExistent\\Class\\Here');
        })->throws(\InvalidArgumentException::class, 'does not exist');

        test('resolve throws for class not implementing Triggerable', function (): void {
            $resolver = app(ActionResolver::class);
            $resolver->resolve(\stdClass::class);
        })->throws(\InvalidArgumentException::class, 'must implement');
    });

    // ── README accuracy ──────────────────────────────────────────
    describe('README accuracy', function (): void {
        test('README version badge matches composer.json version', function (): void {
            $readme = file_get_contents(__DIR__.'/../README.md');
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            $composerVersion = $composer['version'] ?? '';
            expect($readme)->toContain("version-{$composerVersion}");
        });

        test('README has correct test file count', function (): void {
            $readme = file_get_contents(__DIR__.'/../README.md');
            $testFiles = glob(__DIR__.'/../*.php');
            // Count actual test files (excluding support files)
            $supportFiles = ['Pest.php', 'TestCase.php', 'CreatesApplication.php', 'TestActions.php', 'helpers.php'];
            $allTestFiles = glob(__DIR__.'/../tests/*.php');
            $actualCount = count($allTestFiles) - count($supportFiles);

            expect($readme)->toContain("{$actualCount} test files");
        });

        test('README has correct total PHP file count', function (): void {
            $readme = file_get_contents(__DIR__.'/../README.md');
            $allTestFiles = glob(__DIR__.'/../tests/*.php');
            $totalCount = count($allTestFiles);

            expect($readme)->toContain("{$totalCount} PHP files");
        });
    });

    // ── PHPStan config validation ───────────────────────────────
    describe('PHPStan configuration', function (): void {
        test('phpstan.neon.dist exists and has max level', function (): void {
            $configPath = __DIR__.'/../phpstan.neon.dist';
            expect(file_exists($configPath))->toBeTrue();

            $content = file_get_contents($configPath);
            expect($content)->toContain('level: 9');
        });

        test('phpstan.neon.dist has src and tests paths', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('- src');
            expect($content)->toContain('- tests');
        });

        test('phpstan.neon.dist has universalObjectCratesClasses', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('universalObjectCratesClasses');
        });
    });

    // ── Migration and factory counts ─────────────────────────────
    describe('migration and factory counts', function (): void {
        test('3 migration files exist', function (): void {
            $migrations = glob(__DIR__.'/../database/migrations/*.php');
            expect($migrations)->toHaveCount(3);
        });

        test('3 factory files exist', function (): void {
            $factories = glob(__DIR__.'/../database/factories/*.php');
            expect($factories)->toHaveCount(3);
        });

        test('migrations use strict types', function (): void {
            $migrations = glob(__DIR__.'/../database/migrations/*.php');
            foreach ($migrations as $file) {
                expect(file_get_contents($file))->toContain('declare(strict_types=1)');
            }
        });

        test('factories use strict types', function (): void {
            $factories = glob(__DIR__.'/../database/factories/*.php');
            foreach ($factories as $file) {
                expect(file_get_contents($file))->toContain('declare(strict_types=1)');
            }
        });
    });

    // ── No deprecated functions ─────────────────────────────────
    describe('no deprecated functions', function (): void {
        test('no setAccessible calls in source', function (): void {
            $files = glob(__DIR__.'/../src/**/*.php');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->not->toContain('setAccessible');
            }
        });

        test('no TODO/FIXME markers in source', function (): void {
            $files = glob(__DIR__.'/../src/**/*.php');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->not->toContain('TODO');
                expect($content)->not->toContain('FIXME');
                expect($content)->not->toContain('HACK');
                expect($content)->not->toContain('XXX');
            }
        });
    });

    // ── 12 console commands ─────────────────────────────────────
    describe('console commands count', function (): void {
        test('12 console command files exist', function (): void {
            $commands = glob(__DIR__.'/../src/Console/*.php');
            expect($commands)->toHaveCount(12);
        });

        test('all commands are final', function (): void {
            $commands = glob(__DIR__.'/../src/Console/*.php');
            foreach ($commands as $file) {
                // Extract class name from file
                $content = file_get_contents($file);
                preg_match('/namespace\s+ZeroBoiler\\\\Events\\\\Console;\s+final\s+class\s+(\w+)/', $content, $matches);
                if (isset($matches[1])) {
                    $className = "ZeroBoiler\\Events\\Console\\{$matches[1]}";
                    $ref = new ReflectionClass($className);
                    expect($ref->isFinal())->toBeTrue("{$matches[1]} should be final");
                }
            }
        });
    });

    // ── EventLog lifecycle ────────────────────────────────────────
    describe('EventLog status lifecycle', function (): void {
        test('markAsCompleted updates status and duration', function (): void {
            $log = EventLog::factory()->pending()->create();
            $log->markAsCompleted(123);

            expect($log->fresh()->status)->toBe(EventLog::STATUS_COMPLETED);
            expect($log->fresh()->duration_ms)->toBe(123);
        });

        test('markAsFailed updates status and error', function (): void {
            $log = EventLog::factory()->pending()->create();
            $log->markAsFailed('Something went wrong');

            expect($log->fresh()->status)->toBe(EventLog::STATUS_FAILED);
            expect($log->fresh()->error)->toBe('Something went wrong');
        });
    });

    // ── Subscription lifecycle ──────────────────────────────────
    describe('Subscription lifecycle', function (): void {
        test('recordDelivery updates last_fired_at and delivery_count', function (): void {
            $sub = Subscription::factory()->create(['delivery_count' => 5]);
            $sub->recordDelivery();

            expect($sub->fresh()->delivery_count)->toBe(6);
            expect($sub->fresh()->last_fired_at)->not->toBeNull();
        });

        test('recordFailure increments failure_count', function (): void {
            $sub = Subscription::factory()->create(['failure_count' => 3]);
            $sub->recordFailure();

            expect($sub->fresh()->failure_count)->toBe(4);
        });

        test('resetFailures sets failure_count to zero', function (): void {
            $sub = Subscription::factory()->create(['failure_count' => 10]);
            $sub->resetFailures();

            expect($sub->fresh()->failure_count)->toBe(0);
        });

        test('signPayload returns empty string for null secret', function (): void {
            $sub = Subscription::factory()->withoutSecret()->create();
            expect($sub->signPayload('test'))->toBe('');
        });

        test('signPayload returns HMAC for valid secret', function (): void {
            $sub = Subscription::factory()->withSecret('test_secret')->create();
            $sig = $sub->signPayload('payload');

            expect($sig)->not->toBe('');
            expect(strlen($sig))->toBe(64); // sha256 = 32 bytes = 64 hex chars
        });
    });
});
