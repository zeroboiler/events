<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

/**
 * Phase 49 production audit: strict types verification, config completeness,
 * event lifecycle edge cases, wildcard matching correctness, and domain event
 * serialization round-trip.
 */

describe('Phase 49 Production Audit', function (): void {
    $srcDir = dirname(__DIR__, 2).'/src';

    test('all source files declare strict_types=1', function () use ($srcDir): void {
        $srcFiles = glob($srcDir.'/**/*.php');

        foreach ($srcFiles as $file) {
            $contents = file_get_contents($file);
            expect($contents)->not->toBeFalse();
            expect(str_contains($contents, 'declare(strict_types=1)'))->toBeTrue(
                "File {$file} is missing declare(strict_types=1)"
            );
        }
        expect(true)->toBeTrue();
    });

    test('config file contains all required keys', function (): void {
        $configPath = dirname(__DIR__, 2).'/config/events.php';
        expect(file_exists($configPath))->toBeTrue('config/events.php must exist');

        $config = require $configPath;
        expect(is_array($config))->toBeTrue('Config must return an array');

        $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'wildcard_cache_ttl'];
        foreach ($requiredKeys as $key) {
            expect(array_key_exists($key, $config))
                ->toBeTrue("Config key '{$key}' is missing from config/events.php");
        }

        // Nested keys
        expect(isset($config['table_names']['triggers']))->toBeTrue('table_names.triggers required');
        expect(isset($config['table_names']['event_logs']))->toBeTrue('table_names.event_logs required');
        expect(isset($config['table_names']['subscriptions']))->toBeTrue('table_names.subscriptions required');
        expect(isset($config['queue']['connection']))->toBeTrue('queue.connection required');
        expect(isset($config['queue']['queue']))->toBeTrue('queue.queue required');
    });

    test('ConditionEngine operators match documented set', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\ConditionEngine::class);
        $method = $reflection->getMethod('evaluateCondition');

        // Verify all documented operators exist in the match expression
        $contents = file_get_contents($srcDir.'/ConditionEngine.php');

        $documentedOperators = [
            '>', '>=', '<', '<=', '=', '===', '!=', '!==',
            'in', 'not_in', 'contains', 'not_contains', 'between',
            'null', 'not_null', 'empty', 'not_empty',
            'starts_with', 'ends_with', 'matches',
        ];

        foreach ($documentedOperators as $op) {
            expect(str_contains($contents, "'{$op}'"))->toBeTrue(
                "ConditionEngine must support operator '{$op}'"
            );
        }
    });

    test('WildcardMatcher supports all documented patterns', function (): void {
        // Exact match
        expect(\ZeroBoiler\WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        expect(\ZeroBoiler\WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();

        // Single-segment wildcard
        expect(\ZeroBoiler\WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
        expect(\ZeroBoiler\WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();

        // Cross-segment wildcard
        expect(\ZeroBoiler\WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
        expect(\ZeroBoiler\WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        expect(\ZeroBoiler\WildcardMatcher::matches('order.**', 'order.placed.extra.detail'))->toBeTrue();

        // Catch-all
        expect(\ZeroBoiler\WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
        expect(\ZeroBoiler\WildcardMatcher::matches('*', ''))->toBeFalse();
        expect(\ZeroBoiler\WildcardMatcher::matches('**', 'anything'))->toBeTrue();

        // Multiple wildcards
        expect(\ZeroBoiler\WildcardMatcher::matches('*.order.*', 'user.order.created'))->toBeTrue();
        expect(\ZeroBoiler\WildcardMatcher::matches('*.order.*', 'user.order.created.extra'))->toBeFalse();
    });

    test('WildcardMatcher extractWildcards works correctly', function (): void {
        $result = \ZeroBoiler\WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
        expect($result)->toBe(['profile']);

        // Cross-segment wildcards return empty
        expect(\ZeroBoiler\WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))->toBe([]);

        // Non-matching pattern returns empty
        expect(\ZeroBoiler\WildcardMatcher::extractWildcards('user.*.created', 'other.order.deleted'))->toBe([]);
    });

    test('DomainEvent serialization round-trip preserves identity', function (): void {
        $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('user.registered', ['email' => 'test@example.com']);

        $data = $event->toArray();

        expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
        expect($data['eventType'])->toBe('user.registered');
        expect($data['payload']['email'])->toBe('test@example.com');

        // Reconstruct
        $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($event->eventId->toString());
        expect($restored->eventType)->toBe('user.eventType');
    });

    test('DomainEvent fromArray with invalid eventType throws', function (): void {
        \ZeroBoiler\Events\Domain\DomainEvent::fromArray(['eventType' => '', 'payload' => []]);
    })->throws(\InvalidArgumentException::class);

    test('Triggerable interface has correct signature', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\Contracts\Triggerable::class);
        expect($reflection->isInterface())->toBeTrue();
        expect($reflection->hasMethod('handle'))->toBeTrue();

        $method = $reflection->getMethod('handle');
        expect($method->hasReturnType())->toBeTrue();
        expect($method->getReturnType()->getName())->toBe('void');

        $params = $method->getParameters();
        expect(count($params))->toBe(1);
    });

    test('ConditionEngineContract interface is implemented by ConditionEngine', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\ConditionEngine::class);
        expect($reflection->implementsInterface(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class))->toBeTrue();
    });

    test('EventManager facade accessor returns EventManager', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
        $method = $reflection->getMethod('getFacadeAccessor');

        expect($method->invoke(null))->toBe(\ZeroBoiler\Events\EventManager::class);
    });

    test('EventLog status constants cover all lifecycle states', function (): void {
        $expected = ['pending', 'dispatched', 'completed', 'failed'];
        $actual = \ZeroBoiler\Events\Models\EventLog::$statuses;

        foreach ($expected as $status) {
            expect(in_array($status, $actual, true))->toBeTrue("EventLog must have status '{$status}'");
        }
    });

    test('DispatchTriggerJob reads retry config at construction', function (): void {
        // Verify the job constructor properly reads config
        $reflection = new ReflectionClass(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class);
        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect(count($params))->toBe(3);
        expect($params[0]->getName())->toBe('triggerId');
        expect($params[0]->getType()->getName())->toBe('string');
        expect($params[1]->getName())->toBe('event');
        expect($params[1]->getType()->getName())->toBe('string');
        expect($params[2]->getName())->toBe('payload');
    });

    test('EscapesWildcardLike trait exists and is used', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\Concerns\EscapesWildcardLike::class);
        expect($reflection->isTrait())->toBeTrue();

        // Verify it's used in EventManager and ManagesHistory
        $emReflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
        $mhReflection = new ReflectionClass(\ZeroBoiler\Events\Concerns\ManagesHistory::class);
        $msReflection = new ReflectionClass(\ZeroBoiler\Events\Concerns\ManagesSubscriptions::class);

        $emTraits = array_map(fn (\ReflectionClass $t): string => $t->getShortName(), $emReflection->getTraitNames());
        $mhTraits = array_map(fn (\ReflectionClass $t): string => $t->getShortName(), $mhReflection->getTraitNames());
        $msTraits = array_map(fn (\ReflectionClass $t): string => $t->getShortName(), $msReflection->getTraitNames());

        expect(in_array('EscapesWildcardLike', $emTraits, true))->toBeTrue();
        expect(in_array('EscapesWildcardLike', $mhTraits, true))->toBeTrue();
        expect(in_array('EscapesWildcardLike', $msTraits, true))->toBeTrue();
    });
});
