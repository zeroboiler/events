<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;

// ─── Phase 24: Final Polish ─────────────────────────────────────────────

describe('Phase 24 — Production Readiness', function () {

    // ─── Trait property annotations for PHPStan 9 ──────────────────────

    test('ManagesHistory trait docblock has @property-read Container $app annotation', function () {
        $reflection = new ReflectionClass(ManagesHistory::class);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@property-read');
        expect($doc)->toContain('Container');
        expect($doc)->toContain('$app');
    });

    test('ManagesSubscriptions trait docblock has @property-read Container $app annotation', function () {
        $reflection = new ReflectionClass(ManagesSubscriptions::class);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@property-read');
        expect($doc)->toContain('Container');
        expect($doc)->toContain('$app');
    });

    test('EscapesWildcardLike trait has proper docblock', function () {
        $reflection = new ReflectionClass(EscapesWildcardLike::class);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse();
    });

    // ─── Pest.php completeness ────────────────────────────────────────────

    test('Pest.php includes all Phase tests up to Phase 23', function () {
        $pestContent = file_get_contents(base_path('tests/Pest.php'));
        expect($pestContent)->not->toBeFalse();

        // All phases from 4 through 23 should be listed
        for ($i = 4; $i <= 23; $i++) {
            expect($pestContent)->toContain("EventsPhase{$i}ProductionTest.php");
        }
    });

    test('Pest.php includes wildcard and edge case tests', function () {
        $pestContent = file_get_contents(base_path('tests/Pest.php'));
        expect($pestContent)->not->toBeFalse();

        $required = [
            'WildcardMatcherEdgeCasesTest.php',
            'WildcardIntegrationTest.php',
            'EdgeCasesTest.php',
            'EdgeCasesPhase2Test.php',
            'EdgeCasesPhase3Test.php',
            'EscapesWildcardLikeTest.php',
        ];

        foreach ($required as $file) {
            expect($pestContent)->toContain($file);
        }
    });

    // ─── PHP 8.5 strict types enforcement on ALL source files ───────────

    test('all source files declare strict_types=1', function () {
        $dir = base_path('src');
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $violations = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }
            // Skip files that start with a shebang or are stubs
            $lines = explode("\n", $content);
            $found = false;
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === 'declare(strict_types=1);') {
                    $found = true;
                    break;
                }
                // Stop looking once we hit a namespace or class declaration
                if (str_starts_with($trimmed, 'namespace ') || str_starts_with($trimmed, '<?php')) {
                    continue;
                }
            }
            if (! $found) {
                $violations[] = $file->getPathname();
            }
        }

        expect($violations)->toBeEmpty();
    });

    // ─── Final class enforcement ─────────────────────────────────────────

    test('all concrete classes are final', function () {
        $concreteClasses = [
            EventManager::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            ConditionEngine::class,
            WildcardMatcher::class,
            ActionResolver::class,
            DomainEvent::class,
            WebhookAction::class,
            EventsServiceProvider::class,
            DispatchTriggerJob::class,
            EventManagerFacade::class,
        ];

        foreach ($concreteClasses as $class) {
            $ref = new ReflectionClass($class);
            expect($ref->isFinal())->toBeTrue("{$class} should be final");
        }
    });

    // ─── #[Override] on console command handle() methods ─────────────────

    test('all console commands have #[Override] on handle() method', function () {
        $commandNamespace = 'ZeroBoiler\\Events\\Console\\';
        $commands = [
            'EventsListCommand',
            'EventsFireCommand',
            'EventsRegisterCommand',
            'EventsEnableCommand',
            'EventsDisableCommand',
            'EventsRetryCommand',
            'EventsRedeliverCommand',
            'EventsLogCommand',
            'EventsSubscribeCommand',
            'EventsUnsubscribeCommand',
            'EventsSubscriptionsCommand',
        ];

        foreach ($commands as $command) {
            $class = $commandNamespace . $command;
            $ref = new ReflectionMethod($class, 'handle');
            $attrs = $ref->getAttributes(\Override::class);
            expect($attrs)->toHaveCount(1, "{$class}::handle() must have #[\\Override]");
        }
    });

    // ─── Return type declarations on all public methods ──────────────────

    test('EventManager all public methods have return types', function () {
        $ref = new ReflectionClass(EventManager::class);
        $violations = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getReturnType() === null) {
                $violations[] = $method->getName();
            }
        }

        expect($violations)->toBeEmpty();
    });

    test('TriggerBuilder all public methods have return types', function () {
        $ref = new ReflectionClass(TriggerBuilder::class);
        $violations = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getReturnType() === null) {
                $violations[] = $method->getName();
            }
        }

        expect($violations)->toBeEmpty();
    });

    test('SubscriptionBuilder all public methods have return types', function () {
        $ref = new ReflectionClass(SubscriptionBuilder::class);
        $violations = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getReturnType() === null) {
                $violations[] = $method->getName();
            }
        }

        expect($violations)->toBeEmpty();
    });

    test('ConditionEngine all public methods have return types', function () {
        $ref = new ReflectionClass(ConditionEngine::class);
        $violations = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getReturnType() === null) {
                $violations[] = $method->getName();
            }
        }

        expect($violations)->toBeEmpty();
    });

    test('DomainEvent all public methods have return types', function () {
        $ref = new ReflectionClass(DomainEvent::class);
        $violations = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getReturnType() === null) {
                $violations[] = $method->getName();
            }
        }

        expect($violations)->toBeEmpty();
    });

    // ─── Typed properties on models ─────────────────────────────────────

    test('Trigger model has typed $keyType and $incrementing', function () {
        $ref = new ReflectionClass(Trigger::class);
        expect($ref->getProperty('keyType')->getType()->getName())->toBe('string');
        expect($ref->getProperty('incrementing')->getType()->getName())->toBe('bool');
    });

    test('EventLog model has typed $keyType and $incrementing', function () {
        $ref = new ReflectionClass(EventLog::class);
        expect($ref->getProperty('keyType')->getType()->getName())->toBe('string');
        expect($ref->getProperty('incrementing')->getType()->getName())->toBe('bool');
    });

    test('Subscription model has typed $keyType and $incrementing', function () {
        $ref = new ReflectionClass(Subscription::class);
        expect($ref->getProperty('keyType')->getType()->getName())->toBe('string');
        expect($ref->getProperty('incrementing')->getType()->getName())->toBe('bool');
    });

    test('DispatchTriggerJob has typed public properties', function () {
        $ref = new ReflectionClass(DispatchTriggerJob::class);
        $props = ['backoff', 'queue', 'tries', 'connection'];
        foreach ($props as $prop) {
            $type = $ref->getProperty($prop)->getType();
            expect($type)->not->toBeNull("DispatchTriggerJob::\${$prop} must have a type");
        }
    });

    // ─── EventLog status constants consistency ──────────────────────────

    test('EventLog status constants are consistent', function () {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');

        expect(EventLog::$statuses)->toBeArray();
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
        expect(EventLog::$statuses)->toHaveCount(4);
    });

    // ─── DomainEvent readonly properties ──────────────────────────────────

    test('DomainEvent has readonly promoted and assigned properties', function () {
        $ref = new ReflectionClass(DomainEvent::class);

        $readonlyProps = ['eventType', 'payload', 'eventId', 'occurredAt'];
        foreach ($readonlyProps as $prop) {
            $rp = $ref->getProperty($prop);
            expect($rp->isReadOnly())->toBeTrue("DomainEvent::\${$prop} must be readonly");
        }
    });

    // ─── WildcardMatcher #[Pure] on static methods ───────────────────────

    test('WildcardMatcher static methods have #[Pure] attribute', function () {
        $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

        foreach ($methods as $method) {
            $ref = new ReflectionMethod(WildcardMatcher::class, $method);
            $attrs = $ref->getAttributes(\Pure::class);
            expect($attrs)->toHaveCount(1, "WildcardMatcher::{$method}() must have #[\\Pure]");
        }
    });

    // ─── Config completeness ────────────────────────────────────────────

    test('config/events.php has all required top-level keys', function () {
        $config = config('events');
        $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'wildcard_cache_ttl'];

        foreach ($requiredKeys as $key) {
            expect($config)->toHaveKey($key);
        }
    });

    test('config table_names has all required entries', function () {
        $tables = config('events.table_names');
        expect($tables)->toHaveKey('triggers');
        expect($tables)->toHaveKey('event_logs');
        expect($tables)->toHaveKey('subscriptions');
    });

    test('config retry section has tries and backoff', function () {
        $retry = config('events.retry');
        expect($retry)->toHaveKey('tries');
        expect($retry)->toHaveKey('backoff');
    });

    test('config subscriptions section has all required keys', function () {
        $subs = config('events.subscriptions');
        $requiredKeys = ['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm'];

        foreach ($requiredKeys as $key) {
            expect($subs)->toHaveKey($key);
        }
    });

    // ─── ServiceProvider binding lifecycle ──────────────────────────────

    test('EventsServiceProvider registers all required singletons', function () {
        $provider = new EventsServiceProvider(app());
        $provider->register();

        expect(app()->resolved(ConditionEngine::class))->toBeTrue();
        expect(app()->resolved(ConditionEngineContract::class))->toBeTrue();
        expect(app()->resolved(ActionResolver::class))->toBeTrue();
        expect(app()->resolved(EventManager::class))->toBeTrue();
    });

    test('TriggerBuilder and SubscriptionBuilder are transient (not singletons)', function () {
        // Reset the container for a clean test
        app()->forgetInstance(TriggerBuilder::class);
        app()->forgetInstance(SubscriptionBuilder::class);

        $a1 = app()->make(TriggerBuilder::class);
        $a2 = app()->make(TriggerBuilder::class);
        expect($a1)->not->toBe($a2);

        $b1 = app()->make(SubscriptionBuilder::class);
        $b2 = app()->make(SubscriptionBuilder::class);
        expect($b1)->not->toBe($b2);
    });

    // ─── Facade accessor ────────────────────────────────────────────────

    test('EventManager facade getFacadeAccessor has #[Override]', function () {
        $ref = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
        $attrs = $ref->getAttributes(\Override::class);
        expect($attrs)->toHaveCount(1);
    });

    test('EventManager facade resolves to EventManager class', function () {
        $accessor = (new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor'))
            ->invoke(null);
        expect($accessor)->toBe(\ZeroBoiler\Events\EventManager::class);
    });

    // ─── Version consistency ────────────────────────────────────────────

    test('composer.json version format is semver', function () {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        $version = $composer['version'] ?? '';
        expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
    });

    test('composer.json PHP version requires 8.5+', function () {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        expect($composer['require']['php'])->toBe('^8.5');
    });

    test('composer.json Laravel version requires 13.x', function () {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    });

    // ─── Contract binding ─────────────────────────────────────────────────

    test('ConditionEngineContract resolves to ConditionEngine', function () {
        $instance = app()->make(ConditionEngineContract::class);
        expect($instance)->toBeInstanceOf(ConditionEngine::class);
    });

    test('ConditionEngineContract singleton identity', function () {
        $a = app()->make(ConditionEngineContract::class);
        $b = app()->make(ConditionEngineContract::class);
        expect($a)->toBe($b);
    });

    test('Triggerable interface has handle method with correct signature', function () {
        $ref = new ReflectionMethod(Triggerable::class, 'handle');
        expect($ref->getReturnType()?->getName())->toBe('void');

        $params = $ref->getParameters();
        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('payload');
    });

    // ─── Model config-driven table names ───────────────────────────────────

    test('Trigger model reads table from config', function () {
        $trigger = new Trigger;
        $table = $trigger->getTable();
        expect($table)->toBe(config('events.table_names.triggers', 'triggers'));
    });

    test('EventLog model reads table from config', function () {
        $log = new EventLog;
        $table = $log->getTable();
        expect($table)->toBe(config('events.table_names.event_logs', 'event_logs'));
    });

    test('Subscription model reads table from config', function () {
        $sub = new Subscription;
        $table = $sub->getTable();
        expect($table)->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
    });
});
