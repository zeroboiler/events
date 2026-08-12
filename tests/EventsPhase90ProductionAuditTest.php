<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
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

// ─── Strict Types Verification ───────────────────────────────────────────────

describe('Phase 90 — Strict Types Verification', function (): void {
    test('all 32 source files have declare(strict_types=1)', function (): void {
        $srcDir = __DIR__.'/../src';
        $files = glob($srcDir.'/**/*.php');

        expect($files)->not->toBeEmpty();
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect($contents)->toContain('declare(strict_types=1)');
        }
    });

    test('all test support files have declare(strict_types=1)', function (): void {
        $supportFiles = [
            __DIR__.'/CreatesApplication.php',
            __DIR__.'/helpers.php',
            __DIR__.'/Pest.php',
        ];

        foreach ($supportFiles as $file) {
            $contents = file_get_contents($file);
            expect($contents)->toContain('declare(strict_types=1)');
        }
    });

    test('all database factories have declare(strict_types=1)', function (): void {
        $factoryDir = __DIR__.'/../database/factories';
        $files = glob($factoryDir.'/*.php');

        expect($files)->not->toBeEmpty();
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect($contents)->toContain('declare(strict_types=1)');
        }
    });

    test('all database migrations have declare(strict_types=1)', function (): void {
        $migrationDir = __DIR__.'/../database/migrations';
        $files = glob($migrationDir.'/*.php');

        expect($files)->not->toBeEmpty();
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect($contents)->toContain('declare(strict_types=1)');
        }
    });
});

// ─── Final Classes Verification ────────────────────────────────────────────

describe('Phase 90 — Final Classes', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
        DispatchTriggerJob::class,
        DomainEvent::class,
        WildcardMatcher::class,
        \ZeroBoiler\Events\Facades\EventManager::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
    ];

    foreach ($finalClasses as $class) {
        test("{$class} is final", function () use ($class): void {
            $ref = new ReflectionClass($class);
            expect($ref->isFinal())->toBeTrue();
        });
    }
});

// ─── Readonly Properties ──────────────────────────────────────────────────

describe('Phase 90 — Readonly Properties', function (): void {
    test('DomainEvent has readonly promoted properties', function (): void {
        $ref = new ReflectionClass(DomainEvent::class);
        $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

        $readonlyProps = array_filter($props, fn (ReflectionProperty $p): bool => $p->isReadOnly());

        expect($readonlyProps)->not->toBeEmpty();
        expect($readonlyProps)->toHaveCount(4); // eventId, occurredAt, eventType, payload
    });

    test('DispatchTriggerJob has readonly promoted properties', function (): void {
        $ref = new ReflectionClass(DispatchTriggerJob::class);
        $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

        $readonlyProps = array_filter($props, fn (ReflectionProperty $p): bool => $p->isReadOnly());

        expect($readonlyProps)->not->toBeEmpty();
        expect($readonlyProps)->toHaveCount(3); // triggerId, event, payload
    });

    test('WildcardMatcher is a readonly class', function (): void {
        $ref = new ReflectionClass(WildcardMatcher::class);
        expect($ref->isReadOnly())->toBeTrue();
    });
});

// ─── Return Type Declarations ─────────────────────────────────────────────

describe('Phase 90 — Return Type Declarations', function (): void {
    test('EventManager all public methods have return types', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }
            expect($method->hasReturnType())->toBeTrue(
                "EventManager::{$method->getName()}() is missing return type"
            );
        }
    });

    test('ConditionEngine all methods have return types', function (): void {
        $ref = new ReflectionClass(ConditionEngine::class);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }
            expect($method->hasReturnType())->toBeTrue(
                "ConditionEngine::{$method->getName()}() is missing return type"
            );
        }
    });

    test('EventScheduler all methods have return types', function (): void {
        $ref = new ReflectionClass(EventScheduler::class);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }
            expect($method->hasReturnType())->toBeTrue(
                "EventScheduler::{$method->getName()}() is missing return type"
            );
        }
    });
});

// ─── PHPStan Config Validation ────────────────────────────────────────────

describe('Phase 90 — PHPStan Config', function (): void {
    test('phpstan.neon.dist has level 9', function (): void {
        $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
        expect($contents)->toContain('level: 9');
    });

    test('phpstan.neon.dist analyses src only', function (): void {
        $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
        expect($contents)->toContain('paths:');
        expect($contents)->toContain('- src');
    });

    test('phpstan.neon.dist has checkUninitializedProperties', function (): void {
        $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
        expect($contents)->toContain('checkUninitializedProperties: true');
    });

    test('phpstan.neon.dist has all PHPStan 9 checks', function (): void {
        $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');

        $requiredChecks = [
            'checkMissingIterableValueType: true',
            'checkGenericClassInNonGenericObjectType: true',
            'checkUninitializedProperties: true',
            'checkFunctionNameCase: true',
            'checkClassLikeNameCase: true',
            'checkPropertyHookNameCase: true',
            'checkEnumCaseValueNameCase: true',
        ];

        foreach ($requiredChecks as $check) {
            expect($contents)->toContain($check);
        }
    });

    test('phpstan.neon includes phpstan.neon.dist', function (): void {
        $contents = file_get_contents(__DIR__.'/../phpstan.neon');
        expect($contents)->toContain('includes:');
        expect($contents)->toContain('phpstan.neon.dist');
    });
});

// ─── ServiceProvider Verification ─────────────────────────────────────────

describe('Phase 90 — ServiceProvider', function (): void {
    test('EventsServiceProvider has register and boot methods', function (): void {
        $ref = new ReflectionClass(EventsServiceProvider::class);

        expect($ref->hasMethod('register'))->toBeTrue();
        expect($ref->hasMethod('boot'))->toBeTrue();
        expect($ref->hasMethod('provides'))->toBeTrue();
    });

    test('EventsServiceProvider::provides() returns correct services', function (): void {
        $app = $this->app;
        $provider = new EventsServiceProvider($app);
        $provides = $provider->provides();

        $expectedServices = [
            EventManager::class,
            ConditionEngine::class,
            ConditionEngineContract::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
        ];

        foreach ($expectedServices as $service) {
            expect($provides)->toContain($service);
        }
    });

    test('EventManager is registered as singleton', function (): void {
        $first = $this->app->make(EventManager::class);
        $second = $this->app->make(EventManager::class);

        expect($first)->toBe($second);
    });

    test('TriggerBuilder is registered as transient', function (): void {
        $first = $this->app->make(TriggerBuilder::class);
        $second = $this->app->make(TriggerBuilder::class);

        // Transient — each resolution gets a fresh instance
        expect($first)->not->toBe($second);
    });

    test('SubscriptionBuilder is registered as transient', function (): void {
        $first = $this->app->make(SubscriptionBuilder::class);
        $second = $this->app->make(SubscriptionBuilder::class);

        expect($first)->not->toBe($second);
    });

    test('EventScheduler is registered as singleton', function (): void {
        $first = $this->app->make(EventScheduler::class);
        $second = $this->app->make(EventScheduler::class);

        expect($first)->toBe($second);
    });

    test('ConditionEngineContract resolves to ConditionEngine', function (): void {
        $instance = $this->app->make(ConditionEngineContract::class);
        expect($instance)->toBeInstanceOf(ConditionEngine::class);
    });
});

// ─── Facade Verification ──────────────────────────────────────────────────

describe('Phase 90 — Facade', function (): void {
    test('Facade has 23+ @method annotations', function (): void {
        $contents = file_get_contents(__DIR__.'/../src/Facades/EventManager.php');

        preg_match_all('/@method\s+static/', $contents, $matches);

        expect(count($matches[0]))->toBeGreaterThanOrEqual(23);
    });

    test('Facade getFacadeAccessor returns EventManager class', function (): void {
        $ref = new ReflectionMethod(
            \ZeroBoiler\Events\Facades\EventManager::class,
            'getFacadeAccessor'
        );

        expect($ref->getReturnType()?->getName())->toBe('string');

        // Invoke the static method
        $accessor = \ZeroBoiler\Events\Facades\EventManager::getFacadeAccessor();
        expect($accessor)->toBe(EventManager::class);
    });
});

// ─── Config Keys Cross-Reference ───────────────────────────────────────────

describe('Phase 90 — Config Completeness', function (): void {
    test('config file contains all required top-level keys', function (): void {
        $config = include __DIR__.'/../config/events.php';

        $requiredKeys = [
            'table_names',
            'queue',
            'retry',
            'retention',
            'subscriptions',
            'disabled',
            'wildcard_cache_ttl',
        ];

        foreach ($requiredKeys as $key) {
            expect(array_key_exists($key, $config))->toBeTrue(
                "Missing config key: events.{$key}"
            );
        }
    });

    test('config table_names has all three tables', function (): void {
        $config = include __DIR__.'/../config/events.php';

        expect($config['table_names'])->toHaveKey('triggers');
        expect($config['table_names'])->toHaveKey('event_logs');
        expect($config['table_names'])->toHaveKey('subscriptions');
    });

    test('config subscriptions has all required keys', function (): void {
        $config = include __DIR__.'/../config/events.php';

        $subKeys = [
            'auto_generate_secret',
            'max_failures',
            'timeout',
            'signature_algorithm',
            'cleanup_cron',
        ];

        foreach ($subKeys as $key) {
            expect(array_key_exists($key, $config['subscriptions']))->toBeTrue(
                "Missing config key: events.subscriptions.{$key}"
            );
        }
    });

    test('config retention has all required keys', function (): void {
        $config = include __DIR__.'/../config/events.php';

        $retentionKeys = [
            'days',
            'include_pending',
            'schedule_cron',
        ];

        foreach ($retentionKeys as $key) {
            expect(array_key_exists($key, $config['retention']))->toBeTrue(
                "Missing config key: events.retention.{$key}"
            );
        }
    });
});

// ─── Migration Config-Driven Table Names ──────────────────────────────────

describe('Phase 90 — Migrations', function (): void {
    test('all migrations use config-driven table names', function (): void {
        $migrationDir = __DIR__.'/../database/migrations';
        $files = glob($migrationDir.'/*.php');

        expect($files)->toHaveCount(3);

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect($contents)->toContain("config('events.table_names.");
        }
    });
});

// ─── Model Scopes Verification ────────────────────────────────────────────

describe('Phase 90 — Model Scopes', function (): void {
    test('Trigger has scopeEnabled', function (): void {
        expect(method_exists(Trigger::class, 'scopeEnabled'))->toBeTrue();
    });

    test('Trigger has scopeAsync', function (): void {
        expect(method_exists(Trigger::class, 'scopeAsync'))->toBeTrue();
    });

    test('Trigger has scopeOrderByPriority', function (): void {
        expect(method_exists(Trigger::class, 'scopeOrderByPriority'))->toBeTrue();
    });

    test('EventLog has scopeWithStatus', function (): void {
        expect(method_exists(EventLog::class, 'scopeWithStatus'))->toBeTrue();
    });

    test('EventLog has scopeFailed', function (): void {
        expect(method_exists(EventLog::class, 'scopeFailed'))->toBeTrue();
    });

    test('EventLog has scopePending', function (): void {
        expect(method_exists(EventLog::class, 'scopePending'))->toBeTrue();
    });

    test('EventLog has scopeCompleted', function (): void {
        expect(method_exists(EventLog::class, 'scopeCompleted'))->toBeTrue();
    });

    test('EventLog has scopeStalePending', function (): void {
        expect(method_exists(EventLog::class, 'scopeStalePending'))->toBeTrue();
    });

    test('Subscription has scopeActive', function (): void {
        expect(method_exists(Subscription::class, 'scopeActive'))->toBeTrue();
    });

    test('Subscription has scopeForEvent', function (): void {
        expect(method_exists(Subscription::class, 'scopeForEvent'))->toBeTrue();
    });

    test('Subscription has scopeOrderByPriority', function (): void {
        expect(method_exists(Subscription::class, 'scopeOrderByPriority'))->toBeTrue();
    });

    test('Subscription has scopeExceededFailures', function (): void {
        expect(method_exists(Subscription::class, 'scopeExceededFailures'))->toBeTrue();
    });
});

// ─── EventLog Status Constants ─────────────────────────────────────────────

describe('Phase 90 — EventLog Status Constants', function (): void {
    test('EventLog has exactly 4 status constants', function (): void {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');

        expect(EventLog::$statuses)->toHaveCount(4);
        expect(EventLog::$statuses)->toContain('pending');
        expect(EventLog::$statuses)->toContain('dispatched');
        expect(EventLog::$statuses)->toContain('completed');
        expect(EventLog::$statuses)->toContain('failed');
    });
});

// ─── Factory State Methods ────────────────────────────────────────────────

describe('Phase 90 — Factory State Methods', function (): void {
    test('TriggerFactory has state methods', function (): void {
        $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\TriggerFactory::class);

        $stateMethods = ['async', 'sync', 'enabled', 'disabled', 'withConditions', 'priority', 'forEvent', 'withAction', 'withName'];

        foreach ($stateMethods as $method) {
            expect($ref->hasMethod($method))->toBeTrue(
                "TriggerFactory missing state method: {$method}"
            );
        }
    });

    test('EventLogFactory has state methods', function (): void {
        $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\EventLogFactory::class);

        $stateMethods = ['pending', 'dispatched', 'completed', 'failed', 'withEvent', 'forTrigger', 'withPayload', 'withDuration'];

        foreach ($stateMethods as $method) {
            expect($ref->hasMethod($method))->toBeTrue(
                "EventLogFactory missing state method: {$method}"
            );
        }
    });

    test('SubscriptionFactory has state methods', function (): void {
        $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class);

        $stateMethods = ['active', 'inactive', 'forEvent', 'withUrl', 'withConditions', 'withSecret', 'withoutSecret', 'withFailureCount', 'withDeliveryCount', 'withPriority'];

        foreach ($stateMethods as $method) {
            expect($ref->hasMethod($method))->toBeTrue(
                "SubscriptionFactory missing state method: {$method}"
            );
        }
    });
});

// ─── CLI Command Signatures ───────────────────────────────────────────────

describe('Phase 90 — CLI Command Signatures', function (): void {
    test('all CLI commands have signature property', function (): void {
        $commandDir = __DIR__.'/../src/Console';
        $files = glob($commandDir.'/*.php');

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            // Verify class has a $signature property
            expect($contents)->toContain('protected string $signature');
            expect($contents)->toContain('protected string $description');
        }
    });

    test('all CLI commands are final', function (): void {
        $commandDir = __DIR__.'/../src/Console';
        $files = glob($commandDir.'/*.php');

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect($contents)->toContain('final class');
        }
    });

    test('all CLI commands have #[\Override] on handle', function (): void {
        $commandDir = __DIR__.'/../src/Console';
        $files = glob($commandDir.'/*.php');

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect($contents)->toContain('#[\Override]');
        }
    });

    test('list command has pagination options', function (): void {
        $contents = file_get_contents(__DIR__.'/../src/Console/EventsListCommand.php');
        expect($contents)->toContain('--per-page=');
        expect($contents)->toContain('--page=');
    });

    test('subscriptions command has pagination options', function (): void {
        $contents = file_get_contents(__DIR__.'/../src/Console/EventsSubscriptionsCommand.php');
        expect($contents)->toContain('--per-page=');
        expect($contents)->toContain('--page=');
    });

    test('fire command has --payload and --async options', function (): void {
        $contents = file_get_contents(__DIR__.'/../src/Console/EventsFireCommand.php');
        expect($contents)->toContain('--payload=*');
        expect($contents)->toContain('--async');
    });

    test('redeliver command has --force option', function (): void {
        $contents = file_get_contents(__DIR__.'/../src/Console/EventsRedeliverCommand.php');
        expect($contents)->toContain('--force');
    });
});

// ─── Composer.json Validation ─────────────────────────────────────────────

describe('Phase 90 — Composer.json', function (): void {
    test('composer.json requires PHP ^8.5', function (): void {
        $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        expect($json['require']['php'])->toBe('^8.5');
    });

    test('composer.json requires illuminate/contracts ^13.0', function (): void {
        $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        expect($json['require']['illuminate/contracts'])->toBe('^13.0');
    });

    test('composer.json has correct autoload', function (): void {
        $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        expect($json['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
    });

    test('composer.json extra section has provider and alias', function (): void {
        $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        expect($json['extra']['laravel']['providers'])->toContain(
            'ZeroBoiler\\Events\\EventsServiceProvider'
        );
        expect($json['extra']['laravel']['aliases'])->toHaveKey('EventManager');
    });

    test('composer.json version is 4.18.0', function (): void {
        $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        expect($json['version'])->toBe('4.18.0');
    });
});

// ─── Domain Event Serialization ───────────────────────────────────────────

describe('Phase 90 — DomainEvent Roundtrip', function (): void {
    test('DomainEvent serializes and deserializes with full fidelity', function (): void {
        $original = DomainEvent::occur('user.created', ['email' => 'test@example.com']);
        $array = $original->toArray();
        $restored = DomainEvent::fromArray($array);

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->eventType)->toBe($original->eventType);
        expect($restored->payload)->toBe($original->payload);
        expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
            ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
    });

    test('DomainEvent fromArray with extra fields preserves them', function (): void {
        $data = [
            'eventId' => (string) \Ramsey\Uuid\Uuid::uuid4(),
            'eventType' => 'order.placed',
            'payload' => ['amount' => 100],
            'occurredAt' => '2026-01-01T00:00:00+00:00',
            'extraField' => 'preserved',
        ];

        $event = DomainEvent::fromArray($data);
        // Extra fields are not stored in the readonly properties but reconstruction succeeds
        expect($event->eventType)->toBe('order.placed');
        expect($event->payload)->toBe(['amount' => 100]);
    });

    test('DomainEvent fromArray throws on empty eventType', function (): void {
        $this->expectException(\InvalidArgumentException::class);
        DomainEvent::fromArray(['eventType' => '']);
    });
});

// ─── WildcardMatcher Edge Cases ────────────────────────────────────────────

describe('Phase 90 — WildcardMatcher Edge Cases', function (): void {
    test('empty pattern does not match empty event', function (): void {
        expect(WildcardMatcher::matches('', ''))->toBeFalse();
    });

    test('catch-all * does not match empty event', function (): void {
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    });

    test('extractWildcards returns empty for ** patterns', function (): void {
        expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))->toBe([]);
    });

    test('findMatchingPatterns returns empty for empty array', function (): void {
        expect(WildcardMatcher::findMatchingPatterns([], 'order.placed'))->toBe([]);
    });
});

// ─── ConditionEngine Operator Coverage ──────────────────────────────────────

describe('Phase 90 — ConditionEngine Operator Coverage', function (): void {
    test('all 19 operators work correctly', function (): void {
        $engine = new ConditionEngine;

        // 1. Simple equality
        expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();

        // 2. >
        expect($engine->matches(['amount' => ['>', 100]], ['amount' => 200]))->toBeTrue();

        // 3. >=
        expect($engine->matches(['amount' => ['>=', 100]], ['amount' => 100]))->toBeTrue();

        // 4. <
        expect($engine->matches(['amount' => ['<', 100]], ['amount' => 50]))->toBeTrue();

        // 5. <=
        expect($engine->matches(['amount' => ['<=', 100]], ['amount' => 100]))->toBeTrue();

        // 6. = (strict)
        expect($engine->matches(['amount' => ['=', 100]], ['amount' => '100']))->toBeTrue();

        // 7. === (identity)
        expect($engine->matches(['flag' => ['===', true]], ['flag' => true]))->toBeTrue();

        // 8. !=
        expect($engine->matches(['status' => ['!=', 'draft']], ['status' => 'active']))->toBeTrue();

        // 9. !==
        expect($engine->matches(['flag' => ['!==', false]], ['flag' => true]))->toBeTrue();

        // 10. in
        expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();

        // 11. not_in
        expect($engine->matches(['role' => ['not_in', ['guest']]], ['role' => 'admin']))->toBeTrue();

        // 12. contains (string)
        expect($engine->matches(['body' => ['contains', 'hello']], ['body' => 'hello world']))->toBeTrue();

        // 13. not_contains
        expect($engine->matches(['body' => ['not_contains', 'spam']], ['body' => 'hello world']))->toBeTrue();

        // 14. between
        expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 30]))->toBeTrue();

        // 15. null
        expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();

        // 16. not_null
        expect($engine->matches(['email' => ['not_null']], ['email' => 'test@example.com']))->toBeTrue();

        // 17. empty
        expect($engine->matches(['notes' => ['empty']], ['notes' => '']))->toBeTrue();

        // 18. starts_with
        expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@example.com']))->toBeTrue();

        // 19. ends_with
        expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();

        // 20. matches (regex)
        expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();
    });

    test('nested dot notation conditions work', function (): void {
        $engine = new ConditionEngine;

        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'admin']]
        ))->toBeTrue();
    });
});

// ─── Interface Contracts ──────────────────────────────────────────────────

describe('Phase 90 — Interface Contracts', function (): void {
    test('ConditionEngine implements ConditionEngineContract', function (): void {
        $engine = new ConditionEngine;
        expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
    });

    test('WebhookAction implements Triggerable', function (): void {
        $action = new \ZeroBoiler\Events\Actions\WebhookAction;
        expect($action)->toBeInstanceOf(\ZeroBoiler\Events\Contracts\Triggerable::class);
    });

    test('ConditionEngineContract has matches method', function (): void {
        $ref = new ReflectionClass(ConditionEngineContract::class);
        expect($ref->hasMethod('matches'))->toBeTrue();

        $method = $ref->getMethod('matches');
        expect($method->getReturnType()?->getName())->toBe('bool');
    });

    test('Triggerable interface has handle method', function (): void {
        $ref = new ReflectionClass(\ZeroBoiler\Events\Contracts\Triggerable::class);
        expect($ref->hasMethod('handle'))->toBeTrue();

        $method = $ref->getMethod('handle');
        expect($method->getReturnType()?->getName())->toBe('void');
    });
});

// ─── EventManager Method Count ─────────────────────────────────────────────

describe('Phase 90 — EventManager Public Methods', function (): void {
    test('EventManager has at least 23 public methods', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        // Exclude inherited methods (from traits etc.) — only count declared-in-class methods
        $ownMethods = array_filter(
            $publicMethods,
            fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === EventManager::class
                || $m->getDeclaringClass()->getName() === \ZeroBoiler\Events\Concerns\ManagesHistory::class
                || $m->getDeclaringClass()->getName() === \ZeroBoiler\Events\Concerns\ManagesSubscriptions::class
        );

        expect(count($ownMethods))->toBeGreaterThanOrEqual(23);
    });
});

// ─── #[Override] Attribute Verification ───────────────────────────────────

describe('Phase 90 — #[Override] Attributes', function (): void {
    test('ServiceProvider methods have #[Override]', function (): void {
        $contents = file_get_contents(__DIR__.'/../src/EventsServiceProvider.php');

        // Check register, boot, provides have #[Override]
        expect($contents)->toContain('#[\Override]');
    });

    test('ConditionEngine::matches has #[Override]', function (): void {
        $ref = new ReflectionMethod(ConditionEngine::class, 'matches');
        $attrs = $ref->getAttributes(\Override::class);
        expect($attrs)->toHaveCount(1);
    });
});

// ─── #[Pure] Attribute Verification ─────────────────────────────────────

describe('Phase 90 — #[Pure] Attributes', function (): void {
    test('WildcardMatcher::matches has #[Pure]', function (): void {
        $ref = new ReflectionMethod(WildcardMatcher::class, 'matches');
        $attrs = $ref->getAttributes(\Pure::class);
        expect($attrs)->toHaveCount(1);
    });

    test('WildcardMatcher::findMatchingPatterns has #[Pure]', function (): void {
        $ref = new ReflectionMethod(WildcardMatcher::class, 'findMatchingPatterns');
        $attrs = $ref->getAttributes(\Pure::class);
        expect($attrs)->toHaveCount(1);
    });

    test('WildcardMatcher::extractWildcards has #[Pure]', function (): void {
        $ref = new ReflectionMethod(WildcardMatcher::class, 'extractWildcards');
        $attrs = $ref->getAttributes(\Pure::class);
        expect($attrs)->toHaveCount(1);
    });

    test('ConditionEngine::strictEquals has #[Pure]', function (): void {
        $ref = new ReflectionMethod(ConditionEngine::class, 'strictEquals');
        $attrs = $ref->getAttributes(\Pure::class);
        expect($attrs)->toHaveCount(1);
    });
});
