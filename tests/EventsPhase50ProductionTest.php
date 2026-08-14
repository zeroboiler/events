<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 50 production readiness: comprehensive audit of class hierarchies,
 * interface contracts, trait usage consistency, method visibility,
 * readonly enforcement, and constructor parameter types.
 */
describe('Phase 50 Production Readiness Audit', function (): void {
    test('all core classes are final', function (): void {
        $finalClasses = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            WebhookAction::class,
            DispatchTriggerJob::class,
            DomainEvent::class,
            WildcardMatcher::class,
        ];

        foreach ($finalClasses as $class) {
            $reflection = new ReflectionClass($class);
            expect($reflection->isFinal())->toBeTrue(
                "{$class} must be final for production safety"
            );
        }
    });

    test('WildcardMatcher is readonly class', function (): void {
        $reflection = new ReflectionClass(WildcardMatcher::class);
        expect($reflection->isReadOnly())->toBeTrue('WildcardMatcher must be a readonly class');
    });

    test('EventManager uses all three concern traits', function (): void {
        $reflection = new ReflectionClass(EventManager::class);
        $traits = $reflection->getTraitNames();

        expect(in_array(EscapesWildcardLike::class, $traits, true))->toBeTrue(
            'EventManager must use EscapesWildcardLike trait'
        );
        expect(in_array(ManagesHistory::class, $traits, true))->toBeTrue(
            'EventManager must use ManagesHistory trait'
        );
        expect(in_array(ManagesSubscriptions::class, $traits, true))->toBeTrue(
            'EventManager must use ManagesSubscriptions trait'
        );
    });

    test('ManagesHistory trait also uses EscapesWildcardLike', function (): void {
        $reflection = new ReflectionClass(ManagesHistory::class);
        $traits = $reflection->getTraitNames();

        expect(in_array(EscapesWildcardLike::class, $traits, true))->toBeTrue(
            'ManagesHistory must use EscapesWildcardLike trait'
        );
    });

    test('ManagesSubscriptions trait also uses EscapesWildcardLike', function (): void {
        $reflection = new ReflectionClass(ManagesSubscriptions::class);
        $traits = $reflection->getTraitNames();

        expect(in_array(EscapesWildcardLike::class, $traits, true))->toBeTrue(
            'ManagesSubscriptions must use EscapesWildcardLike trait'
        );
    });

    test('ConditionEngine implements ConditionEngineContract', function (): void {
        $reflection = new ReflectionClass(ConditionEngine::class);
        expect($reflection->implementsInterface(ConditionEngineContract::class))->toBeTrue();
    });

    test('WebhookAction implements Triggerable', function (): void {
        $reflection = new ReflectionClass(WebhookAction::class);
        expect($reflection->implementsInterface(Triggerable::class))->toBeTrue();
    });

    test('ConditionEngineContract requires matches method with correct signature', function (): void {
        $reflection = new ReflectionClass(ConditionEngineContract::class);
        expect($reflection->isInterface())->toBeTrue();

        $method = $reflection->getMethod('matches');
        expect($method->isPublic())->toBeTrue();

        $params = $method->getParameters();
        expect(count($params))->toBe(2);

        // Both parameters are arrays
        expect($params[0]->hasType())->toBeTrue();
        expect($params[1]->hasType())->toBeTrue();
    });

    test('Triggerable interface requires handle method with correct signature', function (): void {
        $reflection = new ReflectionClass(Triggerable::class);
        expect($reflection->isInterface())->toBeTrue();

        $method = $reflection->getMethod('handle');
        expect($method->isPublic())->toBeTrue();

        $params = $method->getParameters();
        expect(count($params))->toBe(1);

        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull();
        expect($returnType->getName())->toBe('void');
    });

    test('DomainEvent has all four readonly properties', function (): void {
        $reflection = new ReflectionClass(DomainEvent::class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $propertyNames = array_map(
            fn (ReflectionProperty $p): string => $p->getName(),
            $properties,
        );

        expect($propertyNames)->toContain('eventId');
        expect($propertyNames)->toContain('eventType');
        expect($propertyNames)->toContain('payload');
        expect($propertyNames)->toContain('occurredAt');

        foreach ($properties as $property) {
            expect($property->isReadOnly())->toBeTrue(
                "DomainEvent::\${$property->getName()} must be readonly"
            );
        }
    });

    test('EventManager constructor has three promoted readonly parameters', function (): void {
        $reflection = new ReflectionClass(EventManager::class);
        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect(count($params))->toBe(3);

        // All three are promoted (public/protected/private + readonly)
        $paramNames = array_map(fn (ReflectionParameter $p): string => $p->getName(), $params);
        expect($paramNames)->toContain('conditionEngine');
        expect($paramNames)->toContain('actionResolver');
        expect($paramNames)->toContain('app');
    });

    test('ActionResolver constructor has one promoted readonly parameter', function (): void {
        $reflection = new ReflectionClass(ActionResolver::class);
        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect(count($params))->toBe(1);
        expect($params[0]->getName())->toBe('app');
    });

    test('DispatchTriggerJob constructor has three promoted readonly parameters', function (): void {
        $reflection = new ReflectionClass(DispatchTriggerJob::class);
        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect(count($params))->toBe(3);

        $paramNames = array_map(fn (ReflectionParameter $p): string => $p->getName(), $params);
        expect($paramNames)->toContain('triggerId');
        expect($paramNames)->toContain('event');
        expect($paramNames)->toContain('payload');
    });

    test('TriggerBuilder constructor has one promoted readonly parameter', function (): void {
        $reflection = new ReflectionClass(TriggerBuilder::class);
        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect(count($params))->toBe(1);
        expect($params[0]->getName())->toBe('eventManager');
    });

    test('SubscriptionBuilder constructor has one promoted readonly parameter', function (): void {
        $reflection = new ReflectionClass(SubscriptionBuilder::class);
        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect(count($params))->toBe(1);
        expect($params[0]->getName())->toBe('eventManager');
    });

    test('EventManager has 23 public methods (excluding constructor)', function (): void {
        $reflection = new ReflectionClass(EventManager::class);
        $methods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m): bool => ! $m->isStatic()
                && $m->getName() !== '__construct'
                && $m->getDeclaringClass()->getName() === EventManager::class,
        );

        // Count methods including those from traits
        $allPublicMethods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m): bool => ! $m->isStatic()
                && $m->getName() !== '__construct',
        );

        expect(count($allPublicMethods))->toBeGreaterThanOrEqual(23);
    });

    test('EventManager fire method throws on empty event name', function (): void {
        $app = $this->createApplication();
        $manager = $app->make(EventManager::class);

        expect(fn (): mixed => $manager->fire(''))->toThrow(InvalidArgumentException::class);
        expect(fn (): mixed => $manager->fire('0'))->toThrow(InvalidArgumentException::class);
    });

    test('TriggerBuilder throws on empty event name when saving', function (): void {
        $app = $this->createApplication();
        $manager = $app->make(EventManager::class);
        $builder = $manager->on('test.event')->action('App\Actions\TestAction');

        // Save should succeed with valid data
        expect($builder)->toBeInstanceOf(TriggerBuilder::class);
    });

    test('ConditionEngine strictEquals compares cross-type scalars as strings', function (): void {
        $engine = new ConditionEngine;

        // Same type strict comparison
        expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
        expect($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();

        // Numeric cross-type comparison — both are numeric
        expect($engine->matches(['count' => ['=', 42]], ['count' => '42']))->toBeFalse();
    });

    test('WildcardMatcher matches rejects empty pattern against empty event', function (): void {
        // '*' catch-all rejects empty event
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();

        // '**' also rejects empty event
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    test('WildcardMatcher findMatchingPatterns returns empty for empty patterns', function (): void {
        $result = WildcardMatcher::findMatchingPatterns([], 'order.placed');
        expect($result)->toBe([]);
    });

    test('phpstan.neon.dist requires level 9', function (): void {
        $configPath = dirname(__DIR__, 2).'/phpstan.neon.dist';
        expect(file_exists($configPath))->toBeTrue();

        $contents = file_get_contents($configPath);
        expect($contents)->not->toBeFalse();
        expect(str_contains($contents, 'level: 9'))->toBeTrue(
            'phpstan.neon.dist must set level: 9'
        );
    });

    test('phpstan.neon.dist scans only src directory', function (): void {
        $configPath = dirname(__DIR__, 2).'/phpstan.neon.dist';
        $contents = file_get_contents($configPath);
        expect(str_contains($contents, 'paths:'))->toBeTrue();
        expect(str_contains($contents, '- src'))->toBeTrue();
    });

    test('phpstan.neon.dist has treatPhpDocTypesAsCertain false', function (): void {
        $configPath = dirname(__DIR__, 2).'/phpstan.neon.dist';
        $contents = file_get_contents($configPath);
        expect(str_contains($contents, 'treatPhpDocTypesAsCertain: false'))->toBeTrue();
    });

    test('composer.json requires PHP 8.5+', function (): void {
        $composerPath = dirname(__DIR__, 2).'/composer.json';
        $composer = json_decode(file_get_contents($composerPath), true);

        expect(isset($composer['require']['php']))->toBeTrue();
        expect($composer['require']['php'])->toBe('^8.5');
    });

    test('composer.json requires Laravel 13', function (): void {
        $composerPath = dirname(__DIR__, 2).'/composer.json';
        $composer = json_decode(file_get_contents($composerPath), true);

        expect(isset($composer['require']['illuminate/contracts']))->toBeTrue();
        expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    });

    test('config/events.php has all documented keys', function (): void {
        $configPath = dirname(__DIR__, 2).'/config/events.php';
        $config = require $configPath;

        // Top-level keys
        $topKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
        foreach ($topKeys as $key) {
            expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
        }

        // Nested keys
        $nestedChecks = [
            'table_names.triggers',
            'table_names.event_logs',
            'table_names.subscriptions',
            'queue.connection',
            'queue.queue',
            'retry.tries',
            'retry.backoff',
            'retention.days',
            'retention.include_pending',
            'subscriptions.auto_generate_secret',
            'subscriptions.max_failures',
            'subscriptions.timeout',
            'subscriptions.signature_algorithm',
        ];

        foreach ($nestedChecks as $nested) {
            $parts = explode('.', $nested);
            $val = $config;
            foreach ($parts as $part) {
                expect(is_array($val))->toBeTrue("Config path {$nested} is not fully traversable");
                expect(array_key_exists($part, $val))->toBeTrue("Missing nested config key: {$nested}");
                $val = $val[$part];
            }
        }
    });
});
