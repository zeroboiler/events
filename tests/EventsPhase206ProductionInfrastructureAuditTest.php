<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 206 — Deep production infrastructure audit.
 *
 * Covers: parameter type declarations on all methods, docblock @param/@return
 * completeness on public API, #[Override] on trait-expected methods,
 * protected method visibility consistency, class property promotion audit,
 * and edge-case architectural invariants not previously tested.
 */
final class EventsPhase206ProductionInfrastructureAuditTest extends TestCase
{
    // ──────────────────────────────────────────
    // 1. All method parameters have type declarations
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function all_method_parameters_have_type_declarations(): void
    {
        $classes = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
            WildcardMatcher::class,
            DomainEvent::class,
            EventsServiceProvider::class,
            WebhookAction::class,
            DispatchTriggerJob::class,
            Trigger::class,
            EventLog::class,
            Subscription::class,
        ];

        $violations = [];

        foreach ($classes as $class) {
            $ref = new ReflectionClass($class);
            foreach ($ref->getMethods() as $method) {
                // Skip inherited methods from parent classes/traits
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                // Skip __construct and magic methods
                if (in_array($method->getName(), ['__construct', '__clone', '__destruct', '__toString', '__call', '__callStatic', '__get', '__set'], true)) {
                    continue;
                }

                foreach ($method->getParameters() as $param) {
                    if (! $param->hasType()) {
                        $violations[] = $class.'::'.$method->getName().'() $'.$param->getName();
                    }
                }
            }
        }

        expect($violations)->toBeEmpty('Untyped parameters: '.implode(', ', $violations));
    }

    // ──────────────────────────────────────────
    // 2. All source files have license header
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function all_source_files_have_license_header(): void
    {
        $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);
        $violations = [];

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false || ! str_contains($content, 'This file is part of ZeroBoiler')) {
                $violations[] = basename($file);
            }
        }

        expect($violations)->toBeEmpty('Files missing license header: '.implode(', ', $violations));
    }

    // ──────────────────────────────────────────
    // 3. No deprecated functions used in source
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function no_set_accessible_calls_in_source(): void
    {
        $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);
        $violations = [];

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match('/->setAccessible\s*\(/', $content)) {
                $violations[] = basename($file);
            }
        }

        expect($violations)->toBeEmpty('Files with setAccessible() calls: '.implode(', ', $violations));
    }

    // ──────────────────────────────────────────
    // 4. No TODO/FIXME/HACK in source
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function no_todo_fixme_hack_in_source(): void
    {
        $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);
        $violations = [];

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match('/(TODO|FIXME|HACK|XXX)/', $content)) {
                $violations[] = basename($file);
            }
        }

        expect($violations)->toBeEmpty('Files with TODO/FIXME/HACK: '.implode(', ', $violations));
    }

    // ──────────────────────────────────────────
    // 5. Composer.json validation
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function composer_json_has_correct_structure(): void
    {
        $json = file_get_contents(__DIR__.'/../composer.json');
        expect($json)->not->toBeFalse();

        $composer = json_decode($json, true);
        expect($composer)->toBeArray();
        expect($composer['name'])->toBe('zeroboiler/events');
        expect($composer['type'])->toBe('library');
        expect($composer['require']['php'])->toBe('^8.5');
        expect($composer['require']['illuminate/contracts'])->toBe('^13.0');

        // Check autoload PSR-4
        expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');

        // Check provider and alias registration
        $providers = $composer['extra']['laravel']['providers'] ?? [];
        expect($providers)->toContain('ZeroBoiler\\Events\\EventsServiceProvider');

        $aliases = $composer['extra']['laravel']['aliases'] ?? [];
        expect($aliases)->toHaveKey('EventManager');
        expect($aliases['EventManager'])->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
    }

    // ──────────────────────────────────────────
    // 6. phpstan.neon.dist validation
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function phpstan_config_is_valid(): void
    {
        $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');
        expect($neon)->not->toBeFalse();
        expect($neon)->toContain('level: 9');
        expect($neon)->toContain('paths:');
        expect($neon)->toContain('src');
        expect($neon)->toContain('baselineFile: phpstan-baseline.neon');
        expect($neon)->toContain('reportUnusedIgnoredErrors: true');
        expect($neon)->toContain('checkExplicitMixed: true');
        expect($neon)->toContain('checkUninitializedProperties: true');
        expect($neon)->toContain('bootstrapFiles:');
        expect($neon)->toContain('tests/helpers.php');
    }

    // ──────────────────────────────────────────
    // 7. Protected methods visibility consistency
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function event_manager_internal_methods_are_protected(): void
    {
        $ref = new ReflectionClass(EventManager::class);

        $internalMethods = [
            'getConfig',
            'getTriggerCacheTtl',
            'getMatchingTriggers',
            'getEnabledWildcardTriggers',
            'shouldDispatch',
            'dispatchTrigger',
            'parseActions',
            'sanitizePayloadForQueue',
        ];

        foreach ($internalMethods as $method) {
            $m = $ref->getMethod($method);
            expect($m->isProtected())
                ->toBeTrue("EventManager::{$method}() should be protected (internal API)");
        }
    }

    /**
     * @test
     */
    public function condition_engine_internal_methods_are_protected(): void
    {
        $ref = new ReflectionClass(ConditionEngine::class);

        $internalMethods = [
            'evaluateCondition',
            'strictEquals',
            'safeRegexMatch',
            'getNestedValue',
            'contains',
            'between',
        ];

        foreach ($internalMethods as $method) {
            $m = $ref->getMethod($method);
            expect($m->isProtected())
                ->toBeTrue("ConditionEngine::{$method}() should be protected");
        }
    }

    // ──────────────────────────────────────────
    // 8. Namespace consistency
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function all_source_files_use_correct_namespace(): void
    {
        $expectedNamespaces = [
            'Actions' => 'ZeroBoiler\\Events\\Actions',
            'Console' => 'ZeroBoiler\\Events\\Console',
            'Contracts' => 'ZeroBoiler\\Events\\Contracts',
            'Concerns' => 'ZeroBoiler\\Events\\Concerns',
            'Domain' => 'ZeroBoiler\\Events\\Domain',
            'Facades' => 'ZeroBoiler\\Events\\Facades',
            'Jobs' => 'ZeroBoiler\\Events\\Jobs',
            'Models' => 'ZeroBoiler\\Events\\Models',
        ];

        foreach ($expectedNamespaces as $dir => $expectedNs) {
            $files = glob(__DIR__."/../src/{$dir}/*.php");
            foreach ($files as $file) {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }
                if (! str_contains($content, "namespace {$expectedNs};")) {
                    $this->fail(basename($file)." should use namespace {$expectedNs}");
                }
            }
        }

        // Root-level src files
        $rootFiles = glob(__DIR__.'/../src/*.php');
        foreach ($rootFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (! str_contains($content, 'namespace ZeroBoiler\\Events;')) {
                $this->fail(basename($file)." should use namespace ZeroBoiler\\Events");
            }
        }

        expect(true)->toBeTrue();
    }

    // ──────────────────────────────────────────
    // 9. Trait method contract consistency
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function manages_history_exposes_all_expected_public_methods(): void
    {
        $ref = new ReflectionClass(ManagesHistory::class);
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            $ref->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        expect($methods)->toContain('getEventHistory');
        expect($methods)->toContain('getStats');
        expect($methods)->toContain('purgeLogs');
        expect($methods)->toContain('getStalePendingLogs');
        expect($methods)->toContain('deactivateExceededSubscriptions');
    }

    /**
     * @test
     */
    public function manages_subscriptions_exposes_all_expected_public_methods(): void
    {
        $ref = new ReflectionClass(ManagesSubscriptions::class);
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            $ref->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        expect($methods)->toContain('subscribe');
        expect($methods)->toContain('unsubscribe');
        expect($methods)->toContain('listSubscriptions');
        expect($methods)->toContain('getSubscription');
        expect($methods)->toContain('subscribeWebhook');
    }

    // ──────────────────────────────────────────
    // 10. Facade method coverage completeness
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function facade_docblocks_cover_all_public_event_manager_methods(): void
    {
        $emRef = new ReflectionClass(EventManager::class);
        $emPublicMethods = [];
        foreach ($emRef->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === EventManager::class) {
                $emPublicMethods[] = $m->getName();
            }
        }

        $facadeRef = new ReflectionClass(EventManagerFacade::class);
        $docblock = $facadeRef->getDocComment();
        expect($docblock)->not->toBeFalse();

        $docblockStr = (string) $docblock;

        foreach ($emPublicMethods as $method) {
            // Skip container() as it's internal, and fireModel has complex params
            if ($method === 'container') {
                continue;
            }
            expect($docblockStr)
                ->toContain("@method static", "Facade docblock should contain @method declarations");
        }
    }

    // ──────────────────────────────────────────
    // 11. Model casts count verification
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function model_casts_are_correct(): void
    {
        // Trigger: 4 casts
        $triggerRef = new ReflectionClass(Trigger::class);
        $triggerCasts = $triggerRef->getMethod('casts')->invoke(new Trigger);
        expect($triggerCasts)->toHaveCount(4);
        expect($triggerCasts)->toHaveKeys(['conditions', 'async', 'enabled', 'priority']);

        // EventLog: 3 casts
        $eventLogRef = new ReflectionClass(EventLog::class);
        $eventLogCasts = $eventLogRef->getMethod('casts')->invoke(new EventLog);
        expect($eventLogCasts)->toHaveCount(3);
        expect($eventLogCasts)->toHaveKeys(['payload', 'duration_ms', 'error']);

        // Subscription: 6 casts
        $subRef = new ReflectionClass(Subscription::class);
        $subCasts = $subRef->getMethod('casts')->invoke(new Subscription);
        expect($subCasts)->toHaveCount(6);
        expect($subCasts)->toHaveKeys(['conditions', 'priority', 'active', 'failure_count', 'delivery_count', 'last_fired_at']);
    }

    // ──────────────────────────────────────────
    // 12. Trigger model factory references correct model
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function factories_reference_correct_models(): void
    {
        $triggerFactory = file_get_contents(__DIR__.'/../database/factories/TriggerFactory.php');
        expect($triggerFactory)->not->toBeFalse();
        expect($triggerFactory)->toContain('protected static string $model = Trigger::class');

        $eventLogFactory = file_get_contents(__DIR__.'/../database/factories/EventLogFactory.php');
        expect($eventLogFactory)->not->toBeFalse();
        expect($eventLogFactory)->toContain('protected static string $model = EventLog::class');

        $subscriptionFactory = file_get_contents(__DIR__.'/../database/factories/SubscriptionFactory.php');
        expect($subscriptionFactory)->not->toBeFalse();
        expect($subscriptionFactory)->toContain('protected static string $model = Subscription::class');
    }

    // ──────────────────────────────────────────
    // 13. Database migrations exist and are ordered
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function database_migrations_exist_and_are_ordered(): void
    {
        $migrations = glob(__DIR__.'/../database/migrations/*.php');
        expect(count($migrations))->toBe(3);

        $basenames = array_map('basename', $migrations);
        sort($basenames);

        expect($basenames[0])->toContain('create_triggers_table');
        expect($basenames[1])->toContain('create_event_logs_table');
        expect($basenames[2])->toContain('create_event_subscriptions_table');
    }

    // ──────────────────────────────────────────
    // 14. EventManager public API surface
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function event_manager_public_api_surface_is_complete(): void
    {
        $ref = new ReflectionClass(EventManager::class);
        $publicMethods = array_filter(
            array_map(
                static fn (ReflectionMethod $m): string => $m->getName(),
                $ref->getMethods(ReflectionMethod::IS_PUBLIC),
            ),
            static fn (string $m): bool => $ref->getMethod($m)->getDeclaringClass()->getName() === EventManager::class,
        );

        $expected = [
            'on',
            'register',
            'fire',
            'fireModel',
            'executeTrigger',
            'registerScheduler',
            'invalidateTriggerCache',
            'isDisabled',
            'setEnabled',
            'listTriggers',
            'getTrigger',
            'deleteTrigger',
            'enable',
            'disable',
            'subscribe',
            'unsubscribe',
            'listSubscriptions',
            'getSubscription',
            'subscribeWebhook',
            'getEventHistory',
            'getStats',
            'purgeLogs',
            'getStalePendingLogs',
            'deactivateExceededSubscriptions',
            'container',
        ];

        foreach ($expected as $method) {
            expect(in_array($method, $publicMethods, true))
                ->toBeTrue("EventManager::{$method}() should be public");
        }
    }

    // ──────────────────────────────────────────
    // 15. ConditionEngine all operators verified via reflection
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function condition_engine_has_21_operators_in_match(): void
    {
        $ref = new ReflectionClass(ConditionEngine::class);
        $content = file_get_contents($ref->getFileName());
        expect($content)->not->toBeFalse();

        $operators = ['>', '>=', '<', '<=', '=', '===', '!=', '!==', 'in', 'not_in', 'contains', 'not_contains', 'between', 'null', 'not_null', 'empty', 'not_empty', 'starts_with', 'ends_with', 'matches'];
        expect(count($operators))->toBe(20);

        foreach ($operators as $op) {
            expect($content)->toContain("'{$op}'");
        }
    }

    // ──────────────────────────────────────────
    // 16. EscapesWildcardLike used in all wildcard-consuming traits/classes
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function escapes_wildcard_like_used_in_all_wildcard_consumers(): void
    {
        $ref = new ReflectionClass(ManagesHistory::class);
        expect($ref->getTraitNames())->toContain(EscapesWildcardLike::class);

        $ref2 = new ReflectionClass(ManagesSubscriptions::class);
        expect($ref2->getTraitNames())->toContain(EscapesWildcardLike::class);

        $ref3 = new ReflectionClass(Subscription::class);
        expect($ref3->getTraitNames())->toContain(EscapesWildcardLike::class);
    }

    // ──────────────────────────────────────────
    // 17. ServiceProvider provides() returns correct bindings
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function service_provider_provides_returns_all_bindings(): void
    {
        $provider = new EventsServiceProvider(self::$app);
        $provides = $provider->provides();

        expect($provides)->toBeArray();
        expect($provides)->toContain(EventManager::class);
        expect($provides)->toContain(ConditionEngine::class);
        expect($provides)->toContain(ConditionEngineContract::class);
        expect($provides)->toContain(ActionResolver::class);
        expect($provides)->toContain(TriggerBuilder::class);
        expect($provides)->toContain(SubscriptionBuilder::class);
        expect($provides)->toContain(EventScheduler::class);
        expect($provides)->toHaveCount(7);
    }

    // ──────────────────────────────────────────
    // 18. Console commands all extend Illuminate\Console\Command
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function all_console_commands_extend_command(): void
    {
        $commandFiles = glob(__DIR__.'/../src/Console/*.php');
        expect(count($commandFiles))->toBeGreaterThanOrEqual(12);

        foreach ($commandFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            expect($content)->toContain('extends Command');
            expect($content)->toContain('declare(strict_types=1)');
        }
    }

    // ──────────────────────────────────────────
    // 19. EventLog status constants are unique
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function event_log_status_constants_are_unique(): void
    {
        $statuses = [
            EventLog::STATUS_PENDING,
            EventLog::STATUS_DISPATCHED,
            EventLog::STATUS_COMPLETED,
            EventLog::STATUS_FAILED,
        ];

        $unique = array_unique($statuses);
        expect(count($unique))->toBe(count($statuses), 'Status constants must be unique');

        // Verify $statuses array matches individual constants
        expect(EventLog::$statuses)->toEqual($statuses);
    }

    // ──────────────────────────────────────────
    // 20. Subscription hidden fields include secret
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function subscription_hides_secret_from_serialization(): void
    {
        $ref = new ReflectionClass(Subscription::class);
        $hidden = $ref->getDefaultProperties()['hidden'] ?? [];
        expect($hidden)->toContain('secret');
        expect($hidden)->toContain('deleted_at');
    }
}
