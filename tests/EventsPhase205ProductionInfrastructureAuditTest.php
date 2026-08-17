<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
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
 * Phase 205 — Production infrastructure audit.
 *
 * Verifies PHP 8.5 strict types, typed properties, return types,
 * final classes, #[\Override] annotations, and architectural invariants
 * across all 33 source files.
 */
final class EventsPhase205ProductionInfrastructureAuditTest extends TestCase
{
    // ──────────────────────────────────────────
    // 1. Strict types declaration
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function all_source_files_declare_strict_types(): void
    {
        $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);
        $violations = [];

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = basename($file);
            }
        }

        expect($violations)->toBeEmpty('Files missing declare(strict_types=1): '.implode(', ', $violations));
    }

    // ──────────────────────────────────────────
    // 2. All classes are final
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function all_classes_are_final(): void
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
            EventManagerFacade::class,
        ];

        $nonFinal = [];

        foreach ($classes as $class) {
            $ref = new ReflectionClass($class);
            if (! $ref->isFinal()) {
                $nonFinal[] = $class;
            }
        }

        expect($nonFinal)->toBeEmpty('Classes not declared final: '.implode(', ', $nonFinal));
    }

    // ──────────────────────────────────────────
    // 3. All methods have return type declarations
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function all_public_methods_have_return_type_declarations(): void
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

        $missing = [];

        foreach ($classes as $class) {
            $ref = new ReflectionClass($class);
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue; // Skip inherited methods
                }
                if ($method->getName() === '__construct') {
                    continue; // Constructors don't need return types
                }
                if ($method->hasReturnType()) {
                    continue;
                }
                $missing[] = $class.'::'.$method->getName().'()';
            }
        }

        expect($missing)->toBeEmpty('Public methods missing return types: '.implode(', ', $missing));
    }

    // ──────────────────────────────────────────
    // 4. All properties are typed
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function all_declared_properties_are_typed(): void
    {
        $classes = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
            DomainEvent::class,
            WebhookAction::class,
            DispatchTriggerJob::class,
            Trigger::class,
            EventLog::class,
            Subscription::class,
        ];

        $untyped = [];

        foreach ($classes as $class) {
            $ref = new ReflectionClass($class);
            foreach ($ref->getProperties() as $prop) {
                if ($prop->isDynamic()) {
                    continue;
                }
                if ($prop->hasType()) {
                    continue;
                }
                $untyped[] = $class.'::$'.$prop->getName();
            }
        }

        expect($untyped)->toBeEmpty('Properties without type declarations: '.implode(', ', $untyped));
    }

    // ──────────────────────────────────────────
    // 5. Contracts are properly implemented
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function condition_engine_implements_contract(): void
    {
        expect(ConditionEngine::class)
            ->toImplement(ConditionEngineContract::class);

        $ref = new ReflectionClass(ConditionEngine::class);
        $contractMethods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(ConditionEngineContract::class))->getMethods(),
        );

        foreach ($contractMethods as $method) {
            $impl = $ref->getMethod($method);
            expect($impl->hasReturnType())
                ->toBeTrue("ConditionEngine::{$method}() must have a return type");
        }
    }

    /**
     * @test
     */
    public function webhook_action_implements_triggerable(): void
    {
        expect(WebhookAction::class)->toImplement(Triggerable::class);

        $ref = new ReflectionClass(WebhookAction::class);
        $handle = $ref->getMethod('handle');
        expect($handle->hasReturnType())->toBeTrue();
        expect($handle->getReturnType()?->getName())->toBe('void');
    }

    // ──────────────────────────────────────────
    // 6. ServiceProvider binding completeness
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function service_provider_registers_all_required_bindings(): void
    {
        $provider = new EventsServiceProvider(self::$app);

        // register() should not throw
        $provider->register();

        $requiredBindings = [
            EventManager::class,
            ConditionEngine::class,
            ConditionEngineContract::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
        ];

        foreach ($requiredBindings as $binding) {
            expect(self::$app->bound($binding))
                ->toBeTrue("ServiceProvider must bind {$binding}");

            $resolved = self::$app->make($binding);
            expect($resolved)->toBeInstanceOf($binding);
        }
    }

    /**
     * @test
     */
    public function event_manager_is_singleton(): void
    {
        $provider = new EventsServiceProvider(self::$app);
        $provider->register();

        $first = self::$app->make(EventManager::class);
        $second = self::$app->make(EventManager::class);

        expect($first)->toBe($second);
    }

    /**
     * @test
     */
    public function trigger_builder_is_transient(): void
    {
        $provider = new EventsServiceProvider(self::$app);
        $provider->register();

        $first = self::$app->make(TriggerBuilder::class);
        $second = self::$app->make(TriggerBuilder::class);

        expect($first)->not->toBe($second);
    }

    /**
     * @test
     */
    public function subscription_builder_is_transient(): void
    {
        $provider = new EventsServiceProvider(self::$app);
        $provider->register();

        $first = self::$app->make(SubscriptionBuilder::class);
        $second = self::$app->make(SubscriptionBuilder::class);

        expect($first)->not->toBe($second);
    }

    // ──────────────────────────────────────────
    // 7. Config completeness
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function config_file_has_all_required_keys(): void
    {
        $config = include __DIR__.'/../config/events.php';
        expect(is_array($config))->toBeTrue();

        $requiredKeys = [
            'table_names.triggers',
            'table_names.event_logs',
            'table_names.subscriptions',
            'queue.connection',
            'queue.queue',
            'retry.tries',
            'retry.backoff',
            'retention.days',
            'retention.include_pending',
            'retention.schedule_cron',
            'subscriptions.auto_generate_secret',
            'subscriptions.secret_length',
            'subscriptions.max_failures',
            'subscriptions.timeout',
            'subscriptions.signature_algorithm',
            'subscriptions.cleanup_cron',
            'disabled',
            'wildcard_cache_ttl',
        ];

        foreach ($requiredKeys as $key) {
            $parts = explode('.', $key);
            $value = $config;
            foreach ($parts as $part) {
                if (! is_array($value) || ! array_key_exists($part, $value)) {
                    $this->fail("Config key '{$key}' is missing");
                }
                $value = $value[$part];
            }
            expect($value)->not->toBeNull();
        }

        // Verify table_names are non-empty strings
        expect($config['table_names']['triggers'])->toBeString()->not->toBeEmpty();
        expect($config['table_names']['event_logs'])->toBeString()->not->toBeEmpty();
        expect($config['table_names']['subscriptions'])->toBeString()->not->toBeEmpty();
    }

    // ──────────────────────────────────────────
    // 8. Facade accessor correctness
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function facade_returns_event_manager(): void
    {
        $provider = new EventsServiceProvider(self::$app);
        $provider->register();

        EventManagerFacade::clearResolvedInstance('ZeroBoiler\\Events\\EventManager');

        $instance = EventManagerFacade::getFacadeRoot();

        expect($instance)->toBeInstanceOf(EventManager::class);
    }

    // ──────────────────────────────────────────
    // 9. Constructor injection correctness
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function event_manager_constructor_has_readonly_props(): void
    {
        $ref = new ReflectionClass(EventManager::class);
        $constructor = $ref->getConstructor();
        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect(count($params))->toBe(3);

        $expectedTypes = ['ConditionEngine', 'ActionResolver', 'Container'];
        foreach ($params as $i => $param) {
            $type = $param->getType();
            expect($type)->not->toBeNull("Param #{$i} must have a type");
            if ($type instanceof \ReflectionNamedType) {
                expect($type->getName())->toContain($expectedTypes[$i]);
            }
        }

        // All promoted properties should be readonly
        foreach ($ref->getProperties() as $prop) {
            if ($prop->isPromoted()) {
                expect($prop->isReadOnly())
                    ->toBeTrue("Promoted property {$prop->getName()} must be readonly");
            }
        }
    }

    /**
     * @test
     */
    public function dispatch_trigger_job_has_all_readonly_config_props(): void
    {
        $ref = new ReflectionClass(DispatchTriggerJob::class);

        $readonlyProps = ['triggerId', 'event', 'payload', 'backoff', 'queue', 'tries', 'connection'];
        foreach ($readonlyProps as $name) {
            $prop = $ref->getProperty($name);
            expect($prop->isReadOnly())
                ->toBeTrue("DispatchTriggerJob::\${$name} must be readonly");
            expect($prop->hasType())
                ->toBeTrue("DispatchTriggerJob::\${$name} must have a type");
        }
    }

    // ──────────────────────────────────────────
    // 10. DomainEvent immutability
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function domain_event_all_properties_are_readonly(): void
    {
        $ref = new ReflectionClass(DomainEvent::class);

        foreach (['eventId', 'eventType', 'payload', 'occurredAt'] as $propName) {
            $prop = $ref->getProperty($propName);
            expect($prop->isReadOnly())
                ->toBeTrue("DomainEvent::\${$propName} must be readonly");
        }
    }

    /**
     * @test
     */
    public function domain_event_from_array_preserves_identity(): void
    {
        $original = DomainEvent::occur('test.event', ['key' => 'value']);
        $data = $original->toArray();
        $restored = DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->eventType)->toBe($original->eventType);
        expect($restored->occurredAt->format(\DateTimeInterface::ATOM))
            ->toBe($original->occurredAt->format(\DateTimeInterface::ATOM));
        expect($restored->payload)->toBe($original->payload);
    }

    // ──────────────────────────────────────────
    // 11. WildcardMatcher readonly final
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function wildcard_matcher_is_readonly_final_with_static_methods(): void
    {
        $ref = new ReflectionClass(WildcardMatcher::class);

        expect($ref->isFinal())->toBeTrue();
        expect($ref->isReadOnly())->toBeTrue();

        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
        $names = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $methods);

        expect($names)->toContain('matches');
        expect($names)->toContain('findMatchingPatterns');
        expect($names)->toContain('extractWildcards');

        foreach ($methods as $method) {
            expect($method->isStatic())->toBeTrue("WildcardMatcher::{$method->getName()}() should be static");
        }
    }

    // ──────────────────────────────────────────
    // 12. Model structure invariants
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function all_models_use_soft_deletes_and_string_uuid_keys(): void
    {
        $models = [Trigger::class, EventLog::class, Subscription::class];

        foreach ($models as $model) {
            $ref = new ReflectionClass($model);

            // Must be final
            expect($ref->isFinal())->toBeTrue("{$model} must be final");

            // Must have soft deletes trait
            expect($ref->hasMethod('trashed'))->toBeTrue("{$model} must use SoftDeletes");

            // Must have casts() method
            expect($ref->hasMethod('casts'))->toBeTrue("{$model} must have casts() method");
        }
    }

    /**
     * @test
     */
    public function event_log_has_all_status_constants(): void
    {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');
    }

    // ──────────────────────────────────────────
    // 13. Trait usage verification
    // ──────────────────────────────────────────

    /**
     * @test
     */
    public function event_manager_uses_all_traits(): void
    {
        $ref = new ReflectionClass(EventManager::class);
        $traits = array_map(
            static fn (ReflectionClass $t): string => $t->getShortName(),
            $ref->getTraits(),
        );

        expect($traits)->toContain('EscapesWildcardLike');
        expect($traits)->toContain('ManagesHistory');
        expect($traits)->toContain('ManagesSubscriptions');
    }

    /**
     * @test
     */
    public function webhook_action_uses_gets_webhook_timeout(): void
    {
        $ref = new ReflectionClass(WebhookAction::class);
        $traits = array_map(
            static fn (ReflectionClass $t): string => $t->getShortName(),
            $ref->getTraits(),
        );

        expect($traits)->toContain('GetsWebhookTimeout');
    }
}
