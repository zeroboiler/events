<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\WildcardMatcher;

beforeEach(function (): void {
    //
});

describe('Phase 124 — README Advanced Topics', function (): void {
    test('README contains Advanced Topics section', function (): void {
        $readme = file_get_contents(__DIR__.'/../README.md');
        expect($readme)->toContain('## Advanced Topics');
        expect($readme)->toContain('### Event Sourcing with DomainEvent');
        expect($readme)->toContain('### Custom Triggerable Actions');
        expect($readme)->toContain('### Testing Strategies');
        expect($readme)->toContain('### Performance Considerations');
    });

    test('README Table of Contents includes Advanced Topics sub-headings', function (): void {
        $readme = file_get_contents(__DIR__.'/../README.md');
        expect($readme)->toContain('[Advanced Topics](#advanced-topics)');
        expect($readme)->toContain('[Event Sourcing with DomainEvent](#event-sourcing-with-domainevent)');
        expect($readme)->toContain('[Custom Triggerable Actions](#custom-triggerable-actions)');
        expect($readme)->toContain('[Testing Strategies](#testing-strategies)');
        expect($readme)->toContain('[Performance Considerations](#performance-considerations)');
    });

    test('README Advanced Topics has code examples', function (): void {
        $readme = file_get_contents(__DIR__.'/../README.md');
        // Event sourcing example
        expect($readme)->toContain('DomainEvent::occur');
        expect($readme)->toContain('DomainEvent::fromArray');
        // Custom action example
        expect($readme)->toContain('implements Triggerable');
        // Testing example
        expect($readme)->toContain('ConditionEngine');
        // Performance example
        expect($readme)->toContain('wildcard_cache_ttl');
    });
});

describe('Phase 124 — phpstan-baseline.neon includes directive', function (): void {
    test('phpstan-baseline.neon includes phpstan.neon.dist', function (): void {
        $baseline = file_get_contents(__DIR__.'/../phpstan-baseline.neon');
        expect($baseline)->toContain('includes:');
        expect($baseline)->toContain('phpstan.neon.dist');
    });
});

describe('Phase 124 — Version alignment', function (): void {
    test('composer.json version matches README badge', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        $readme = file_get_contents(__DIR__.'/../README.md');
        $version = $composer['version'];
        expect($version)->toBe('4.51.0');
        expect($readme)->toContain("version-{$version}");
    });

    test('CHANGELOG has 4.51.0 entry', function (): void {
        $changelog = file_get_contents(__DIR__.'/../CHANGELOG.md');
        expect($changelog)->toContain('[4.51.0]');
    });
});

describe('Phase 124 — ConditionEngine ReDoS protection', function (): void {
    test('safeRegexMatch rejects nested quantifier patterns', function (): void {
        $engine = new ConditionEngine();

        // Catastrophic patterns with nested quantifiers
        $payload = ['input' => 'aaaaaaaaaaaaaaaaaaaa'];
        $conditions = ['input' => ['matches', '/(a+)+b/']];

        // This should return false (ReDoS protection kicks in)
        $result = $engine->matches($conditions, $payload);
        // Pattern may be rejected by nested quantifier check OR by backtrack limit
        expect($result)->toBeFalse();
    });

    test('safeRegexMatch rejects patterns exceeding max length', function (): void {
        $engine = new ConditionEngine();

        $longPattern = '/^' . str_repeat('a', 600) . '$/';
        $payload = ['input' => str_repeat('a', 600)];
        $conditions = ['input' => ['matches', $longPattern]];

        $result = $engine->matches($conditions, $payload);
        expect($result)->toBeFalse();
    });

    test('safeRegexMatch allows normal patterns', function (): void {
        $engine = new ConditionEngine();

        $payload = ['code' => 'ABC-1234'];
        $conditions = ['code' => ['matches', '/^[A-Z]{3}-\\d{4}$/']];

        expect($engine->matches($conditions, $payload))->toBeTrue();
    });
});

describe('Phase 124 — EventManager fire() validation', function (): void {
    test('fire() rejects empty string event name', function (): void {
        $app = app();
        $engine = $app->make(ConditionEngine::class);
        $resolver = $app->make(\ZeroBoiler\Events\ActionResolver::class);
        $manager = new EventManager($engine, $resolver, $app);

        expect(fn (): mixed => $manager->fire(''))->toThrow(InvalidArgumentException::class);
    });

    test('fire() rejects zero-string event name', function (): void {
        $app = app();
        $engine = $app->make(ConditionEngine::class);
        $resolver = $app->make(\ZeroBoiler\Events\ActionResolver::class);
        $manager = new EventManager($engine, $resolver, $app);

        expect(fn (): mixed => $manager->fire('0'))->toThrow(InvalidArgumentException::class);
    });
});

describe('Phase 124 — TriggerBuilder validation', function (): void {
    test('save() throws on empty event name', function (): void {
        $app = app();
        $engine = $app->make(ConditionEngine::class);
        $resolver = $app->make(\ZeroBoiler\Events\ActionResolver::class);
        $manager = new EventManager($engine, $resolver, $app);

        $builder = new TriggerBuilder($manager);
        $builder->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class);

        expect(fn (): mixed => $builder->save())->toThrow(InvalidArgumentException::class, 'Event name is required');
    });

    test('save() throws when no action is provided', function (): void {
        $app = app();
        $engine = $app->make(ConditionEngine::class);
        $resolver = $app->make(\ZeroBoiler\Events\ActionResolver::class);
        $manager = new EventManager($engine, $resolver, $app);

        $builder = new TriggerBuilder($manager);
        $builder->on('test.event');

        expect(fn (): mixed => $builder->save())->toThrow(InvalidArgumentException::class, 'At least one action is required');
    });

    test('save() auto-generates name from event when not provided', function (): void {
        $app = app();
        $engine = $app->make(ConditionEngine::class);
        $resolver = $app->make(\ZeroBoiler\Events\ActionResolver::class);
        $manager = new EventManager($engine, $resolver, $app);

        $builder = new TriggerBuilder($manager);
        $builder->on('order.placed')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class);
        $trigger = $builder->save();

        expect($trigger->name)->toBe('order.placed Trigger');
        expect($trigger->event)->toBe('order.placed');

        // Cleanup
        $trigger->delete();
    });
});

describe('Phase 124 — SubscriptionBuilder validation', function (): void {
    test('save() throws on empty event name', function (): void {
        $app = app();
        $engine = $app->make(ConditionEngine::class);
        $resolver = $app->make(\ZeroBoiler\Events\ActionResolver::class);
        $manager = new EventManager($engine, $resolver, $app);

        $builder = new SubscriptionBuilder($manager);
        $builder->to('https://example.com/webhook');

        expect(fn (): mixed => $builder->save())->toThrow(InvalidArgumentException::class);
    });

    test('save() throws on empty URL', function (): void {
        $app = app();
        $engine = $app->make(ConditionEngine::class);
        $resolver = $app->make(\ZeroBoiler\Events\ActionResolver::class);
        $manager = new EventManager($engine, $resolver, $app);

        $builder = new SubscriptionBuilder($manager);
        $builder->on('order.placed');

        expect(fn (): mixed => $builder->save())->toThrow(InvalidArgumentException::class);
    });

    test('save() rejects non-HTTP URL schemes', function (): void {
        $app = app();
        $engine = $app->make(ConditionEngine::class);
        $resolver = $app->make(\ZeroBoiler\Events\ActionResolver::class);
        $manager = new EventManager($engine, $resolver, $app);

        $builder = new SubscriptionBuilder($manager);
        $builder->on('test.event')->to('ftp://evil.com/payload');

        expect(fn (): mixed => $builder->save())->toThrow(InvalidArgumentException::class);
    });
});

describe('Phase 124 — DomainEvent resilience', function (): void {
    test('fromArray() throws on missing eventType', function (): void {
        expect(fn (): mixed => DomainEvent::fromArray([]))
            ->toThrow(InvalidArgumentException::class, 'eventType is required');
    });

    test('fromArray() handles invalid UUID gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => 'not-a-valid-uuid',
        ]);

        // Should not throw — generates fresh UUID
        expect($event->eventType)->toBe('test.event');
        expect($event->eventId)->not->toBeNull();
    });

    test('fromArray() handles invalid date gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'occurredAt' => 'not-a-date',
        ]);

        // Should not throw — uses now()
        expect($event->occurredAt)->not->toBeNull();
    });

    test('fromArray() preserves valid UUID and timestamp', function (): void {
        $original = DomainEvent::occur('order.created', ['id' => 123]);
        $restored = DomainEvent::fromArray($original->toArray());

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
            ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
        expect($restored->eventType)->toBe($original->eventType);
    });

    test('DomainEvent is immutable — all properties are readonly', function (): void {
        $ref = new ReflectionClass(DomainEvent::class);
        $props = $ref->getProperties(ReflectionProperty::IS_READONLY);

        $readonlyNames = array_map(fn (ReflectionProperty $p): string => $p->getName(), $props);
        expect($readonlyNames)->toContain('eventId');
        expect($readonlyNames)->toContain('eventType');
        expect($readonlyNames)->toContain('payload');
        expect($readonlyNames)->toContain('occurredAt');
        expect(count($readonlyNames))->toBe(4);
    });
});

describe('Phase 124 — WebhookAction payload stripping', function (): void {
    test('WebhookAction handle() strips internal keys from payload', function (): void {
        $readme = file_get_contents(__DIR__.'/../src/Actions/WebhookAction.php');
        // Verify that internal keys are stripped before sending to webhook
        expect($readme)->toContain("unset(\$webhookData['url']");
        expect($readme)->toContain("unset(\$webhookData['event']");
        expect($readme)->toContain("unset(\$webhookData['headers']");
        expect($readme)->toContain("unset(\$webhookData['subscription_id']");
    });
});

describe('Phase 124 — DispatchTriggerJob config-driven initialization', function (): void {
    test('job reads retry tries from config', function (): void {
        $configKey = 'events.retry.tries';
        $source = file_get_contents(__DIR__.'/../src/Jobs/DispatchTriggerJob.php');
        expect($source)->toContain("Config::get('{$configKey}'");
    });

    test('job reads backoff from config', function (): void {
        $configKey = 'events.retry.backoff';
        $source = file_get_contents(__DIR__.'/../src/Jobs/DispatchTriggerJob.php');
        expect($source)->toContain("Config::get('{$configKey}'");
    });

    test('job reads queue config from events config', function (): void {
        $source = file_get_contents(__DIR__.'/../src/Jobs/DispatchTriggerJob.php');
        expect($source)->toContain("Config::get('events.queue.queue'");
        expect($source)->toContain("Config::get('events.queue.connection'");
    });
});

describe('Phase 124 — EventScheduler constructor injection', function (): void {
    test('EventScheduler uses constructor injection for Container', function (): void {
        $ref = new ReflectionClass(EventScheduler::class);
        $constructor = $ref->getConstructor();
        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect(count($params))->toBe(1);
        expect($params[0]->getName())->toBe('app');
        expect($params[0]->getType())->not->toBeNull();
    });

    test('EventScheduler does not use global app() helper', function (): void {
        $source = file_get_contents(__DIR__.'/../src/EventScheduler.php');
        // Should not call the global app() helper directly
        expect($source)->not->toContain('app()');
    });
});

describe('Phase 124 — ManagesHistory getStats() return structure', function (): void {
    test('getStats() has documented return type array shape', function (): void {
        $source = file_get_contents(__DIR__.'/../src/Concerns/ManagesHistory.php');
        // Verify all required keys are returned
        $requiredKeys = [
            'total_logs', 'total_triggers', 'active_triggers',
            'completed', 'failed', 'pending', 'dispatched',
            'success_rate', 'failure_rate', 'avg_duration_ms',
            'top_events', 'top_failed_events',
        ];

        foreach ($requiredKeys as $key) {
            expect($source)->toContain("'{$key}'");
        }
    });
});

describe('Phase 124 — ServiceProvider completeness', function (): void {
    test('all 12 console commands are registered', function (): void {
        $source = file_get_contents(__DIR__.'/../src/EventsServiceProvider.php');

        $commands = [
            'EventsListCommand',
            'EventsRegisterCommand',
            'EventsFireCommand',
            'EventsLogCommand',
            'EventsRetryCommand',
            'EventsEnableCommand',
            'EventsDisableCommand',
            'EventsHealthCommand',
            'EventsSubscribeCommand',
            'EventsUnsubscribeCommand',
            'EventsSubscriptionsCommand',
            'EventsRedeliverCommand',
        ];

        foreach ($commands as $command) {
            expect($source)->toContain($command);
        }
    });

    test('provides() returns all 7 services', function (): void {
        $provider = new EventsServiceProvider(app());
        $provides = $provider->provides();

        $expected = [
            EventManager::class,
            ConditionEngine::class,
            ConditionEngineContract::class,
            \ZeroBoiler\Events\ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
        ];

        foreach ($expected as $service) {
            expect($provides)->toContain($service);
        }
    });

    test('config is merged in register()', function (): void {
        $source = file_get_contents(__DIR__.'/../src/EventsServiceProvider.php');
        expect($source)->toContain('mergeConfigFrom');
        expect($source)->toContain('events.php');
    });

    test('migrations are loaded in boot()', function (): void {
        $source = file_get_contents(__DIR__.'/../src/EventsServiceProvider.php');
        expect($source)->toContain('loadMigrationsFrom');
    });
});

describe('Phase 124 — strict_types across all source files', function (): void {
    test('all source files have declare(strict_types=1)', function (): void {
        $sourceDir = __DIR__.'/../src';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $violations = [];
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                if (! str_contains($content, 'declare(strict_types=1)')) {
                    $violations[] = $file->getPathname();
                }
            }
        }

        expect($violations)->toBeEmpty(
            'Source files missing declare(strict_types=1): ' . implode(', ', $violations)
        );
    });

    test('all migration files have declare(strict_types=1)', function (): void {
        $dir = __DIR__.'/../database/migrations';
        $files = glob($dir.'/*.php');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        }
    });

    test('all factory files have declare(strict_types=1)', function (): void {
        $dir = __DIR__.'/../database/factories';
        $files = glob($dir.'/*.php');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        }
    });
});

describe('Phase 124 — WildcardMatcher immutability', function (): void {
    test('WildcardMatcher is readonly final class', function (): void {
        $ref = new ReflectionClass(WildcardMatcher::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isReadOnly())->toBeTrue();
    });

    test('WildcardMatcher has only static methods', function (): void {
        $ref = new ReflectionClass(WildcardMatcher::class);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            expect($method->isStatic())->toBeTrue(
                "Method {$method->getName()} should be static"
            );
        }
    });
});
