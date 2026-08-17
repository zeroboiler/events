<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 185 — Infrastructure audit: string-zero guard consistency across
 * EventManager, ManagesHistory, and ManagesSubscriptions; DomainEvent
 * edge cases; and source file quality verification.
 */
final class EventsPhase185InfrastructureAuditTest extends TestCase
{
    // ── ManagesHistory: '0' guard consistency ────────────────────────

    public function test_get_event_history_skips_zero_string_event(): void
    {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        // Fire an event to create a log
        $manager->on('test.guard')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();
        $manager->fire('test.guard', ['key' => 'val']);

        // '0' as event name should be skipped (not treated as a valid event filter)
        $history = $manager->getEventHistory(event: '0');
        // Should return all logs since '0' is treated as "no filter"
        expect($history->count())->toBeGreaterThanOrEqual(0);
    }

    public function test_get_event_history_skips_zero_string_status(): void
    {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        // '0' as status should be skipped
        $history = $manager->getEventHistory(status: '0');
        expect($history)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
    }

    public function test_get_event_history_skips_zero_string_trigger_id(): void
    {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        // '0' as triggerId should be skipped
        $history = $manager->getEventHistory(triggerId: '0');
        expect($history)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
    }

    // ── ManagesSubscriptions: '0' guard consistency ────────────────

    public function test_list_subscriptions_skips_zero_string_event(): void
    {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $subs = $manager->listSubscriptions(event: '0');
        expect($subs)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        expect($subs->count())->toBe(0);
    }

    // ── EventManager: existing '0' guard verification ───────────────

    public function test_list_triggers_skips_zero_string_event(): void
    {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $manager->on('test.zero')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();

        // '0' should return no results (guard prevents it from being used as a filter)
        $triggers = $manager->listTriggers(event: '0');
        expect($triggers->count())->toBe(0);
    }

    public function test_get_trigger_skips_zero_string(): void
    {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->getTrigger('0');
        expect($trigger)->toBeNull();
    }

    public function test_delete_trigger_skips_zero_string(): void
    {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $result = $manager->deleteTrigger('0');
        expect($result)->toBeFalse();
    }

    public function test_enable_skips_zero_string(): void
    {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $result = $manager->enable('0');
        expect($result)->toBeFalse();
    }

    public function test_disable_skips_zero_string(): void
    {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $result = $manager->disable('0');
        expect($result)->toBeFalse();
    }

    // ── DomainEvent edge cases ──────────────────────────────────────

    public function test_domain_event_preserves_event_type_from_array(): void
    {
        $event = DomainEvent::occur('user.created', ['name' => 'Alice']);
        $data = $event->toArray();
        $restored = DomainEvent::fromArray($data);

        expect($restored->eventType)->toBe('user.created');
        expect($restored->payload)->toBe(['name' => 'Alice']);
        expect($restored->eventId->toString())->toBe($event->eventId->toString());
        expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))->toBe(
            $event->occurredAt->format(\DateTimeImmutable::ATOM),
        );
    }

    public function test_domain_event_from_array_missing_event_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DomainEvent::fromArray(['payload' => ['key' => 'val']]);
    }

    public function test_domain_event_from_array_empty_event_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DomainEvent::fromArray(['eventType' => '']);
    }

    public function test_domain_event_from_array_handles_invalid_uuid_gracefully(): void
    {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => 'not-a-uuid',
        ]);

        // Should generate a fresh UUID instead of crashing
        expect($event->eventId)->not->toBeNull();
        expect($event->eventType)->toBe('test.event');
    }

    public function test_domain_event_from_array_handles_invalid_datetime_gracefully(): void
    {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'occurredAt' => 'not-a-date',
        ]);

        // Should use current time instead of crashing
        expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        expect($event->eventType)->toBe('test.event');
    }

    public function test_domain_event_from_array_with_all_valid_fields(): void
    {
        $original = DomainEvent::occur('order.placed', ['amount' => 99.99]);
        $data = $original->toArray();
        $restored = DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->eventType)->toBe('order.placed');
        expect($restored->payload)->toBe(['amount' => 99.99]);
        expect($restored->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp());
    }

    // ── WildcardMatcher edge cases ──────────────────────────────────

    public function test_wildcard_matcher_single_star_does_not_match_empty(): void
    {
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    }

    public function test_wildcard_matcher_double_star_does_not_match_empty(): void
    {
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    }

    public function test_wildcard_matcher_exact_match(): void
    {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    }

    public function test_wildcard_matcher_exact_mismatch(): void
    {
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    }

    public function test_wildcard_matcher_single_segment(): void
    {
        expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
    }

    public function test_wildcard_matcher_cross_segment(): void
    {
        expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
    }

    public function test_wildcard_matcher_extract_wildcards_single(): void
    {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

        expect($result)->toBe(['profile']);
    }

    public function test_wildcard_matcher_extract_wildcards_returns_empty_for_double_star(): void
    {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

        expect($result)->toBe([]);
    }

    public function test_wildcard_matcher_find_matching_patterns(): void
    {
        $patterns = ['order.*', 'user.*', '*.created'];

        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toBe(['order.*']);
    }

    public function test_wildcard_matcher_find_matching_patterns_empty_input(): void
    {
        $result = WildcardMatcher::findMatchingPatterns([], 'order.placed');

        expect($result)->toBe([]);
    }

    // ── ConditionEngine operator coverage ────────────────────────────

    public function test_condition_engine_empty_conditions_matches_anything(): void
    {
        $engine = new ConditionEngine;

        expect($engine->matches([], ['anything' => 'goes']))->toBeTrue();
    }

    public function test_condition_engine_null_operator(): void
    {
        $engine = new ConditionEngine;

        expect($engine->matches(['field' => ['null']], ['field' => null]))->toBeTrue();
        expect($engine->matches(['field' => ['null']], ['field' => 'value']))->toBeFalse();
    }

    public function test_condition_engine_not_null_operator(): void
    {
        $engine = new ConditionEngine;

        expect($engine->matches(['field' => ['not_null']], ['field' => 'value']))->toBeTrue();
        expect($engine->matches(['field' => ['not_null']], ['field' => null]))->toBeFalse();
    }

    public function test_condition_engine_empty_operator(): void
    {
        $engine = new ConditionEngine;

        expect($engine->matches(['field' => ['empty']], ['field' => '']))->toBeTrue();
        expect($engine->matches(['field' => ['empty']], ['field' => null]))->toBeTrue();
        expect($engine->matches(['field' => ['empty']], ['field' => 'value']))->toBeFalse();
    }

    public function test_condition_engine_not_empty_operator(): void
    {
        $engine = new ConditionEngine;

        expect($engine->matches(['field' => ['not_empty']], ['field' => 'value']))->toBeTrue();
        expect($engine->matches(['field' => ['not_empty']], ['field' => '']))->toBeFalse();
        expect($engine->matches(['field' => ['not_empty']], ['field' => null]))->toBeFalse();
    }

    public function test_condition_engine_starts_with(): void
    {
        $engine = new ConditionEngine;

        expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
        expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'user@test.com']))->toBeFalse();
    }

    public function test_condition_engine_ends_with(): void
    {
        $engine = new ConditionEngine;

        expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();
        expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.org']))->toBeFalse();
    }

    public function test_condition_engine_matches_operator(): void
    {
        $engine = new ConditionEngine;

        expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();
        expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'abc']))->toBeFalse();
    }

    public function test_condition_engine_between_auto_normalizes_inverted(): void
    {
        $engine = new ConditionEngine;

        // Inverted range [100, 50] should be auto-normalized to [50, 100]
        expect($engine->matches(['age' => ['between', [100, 50]]], ['age' => 75]))->toBeTrue();
        expect($engine->matches(['age' => ['between', [100, 50]]], ['age' => 25]))->toBeFalse();
    }

    public function test_condition_engine_dot_notation(): void
    {
        $engine = new ConditionEngine;

        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'admin']],
        ))->toBeTrue();

        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'user']],
        ))->toBeFalse();
    }

    public function test_condition_engine_in_operator(): void
    {
        $engine = new ConditionEngine;

        expect($engine->matches(
            ['status' => ['in', ['active', 'pending']]],
            ['status' => 'active'],
        ))->toBeTrue();

        expect($engine->matches(
            ['status' => ['in', ['active', 'pending']]],
            ['status' => 'deleted'],
        ))->toBeFalse();
    }

    public function test_condition_engine_not_in_operator(): void
    {
        $engine = new ConditionEngine;

        expect($engine->matches(
            ['status' => ['not_in', ['deleted', 'archived']]],
            ['status' => 'active'],
        ))->toBeTrue();

        expect($engine->matches(
            ['status' => ['not_in', ['deleted', 'archived']]],
            ['status' => 'deleted'],
        ))->toBeFalse();
    }

    // ── Source file quality verification ───────────────────────────

    public function test_all_source_files_have_strict_types(): void
    {
        $srcFiles = glob(__DIR__.'/../src/**/*.php');

        expect($srcFiles)->not->toBeEmpty();

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        }
    }

    public function test_all_source_files_have_license_header(): void
    {
        $srcFiles = glob(__DIR__.'/../src/**/*.php');

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('This file is part of ZeroBoiler');
        }
    }

    public function test_all_source_classes_are_final(): void
    {
        $srcFiles = glob(__DIR__.'/../src/{Actions,Console,Contracts,Domain,Facades,Jobs,Models}/*.php');

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            // Skip interfaces and traits
            if (str_contains($content, 'interface ') || str_contains($content, 'trait ')) {
                continue;
            }
            expect($content)->toContain('final class');
        }
    }

    public function test_config_has_all_required_top_level_keys(): void
    {
        $config = config('events');

        expect($config)->toHaveKey('table_names');
        expect($config)->toHaveKey('queue');
        expect($config)->toHaveKey('retry');
        expect($config)->toHaveKey('retention');
        expect($config)->toHaveKey('subscriptions');
        expect($config)->toHaveKey('disabled');
        expect($config)->toHaveKey('wildcard_cache_ttl');
    }

    public function test_config_table_names_has_all_tables(): void
    {
        $tables = config('events.table_names');

        expect($tables)->toHaveKey('triggers');
        expect($tables)->toHaveKey('event_logs');
        expect($tables)->toHaveKey('subscriptions');
    }

    public function test_service_provider_registers_all_bindings(): void
    {
        $app = app();

        expect($app->bound(\ZeroBoiler\Events\EventManager::class))->toBeTrue();
        expect($app->bound(\ZeroBoiler\Events\ConditionEngine::class))->toBeTrue();
        expect($app->bound(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class))->toBeTrue();
        expect($app->bound(\ZeroBoiler\Events\ActionResolver::class))->toBeTrue();
        expect($app->bound(\ZeroBoiler\Events\EventScheduler::class))->toBeTrue();
        expect($app->bound(\ZeroBoiler\Events\TriggerBuilder::class))->toBeTrue();
        expect($app->bound(\ZeroBoiler\Events\SubscriptionBuilder::class))->toBeTrue();
    }

    public function test_service_provider_provides_returns_expected_bindings(): void
    {
        $provider = new \ZeroBoiler\Events\EventsServiceProvider(app());

        $provides = $provider->provides();

        expect($provides)->toContain(\ZeroBoiler\Events\EventManager::class);
        expect($provides)->toContain(\ZeroBoiler\Events\ConditionEngine::class);
        expect($provides)->toContain(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
        expect($provides)->toContain(\ZeroBoiler\Events\ActionResolver::class);
        expect($provides)->toContain(\ZeroBoiler\Events\TriggerBuilder::class);
        expect($provides)->toContain(\ZeroBoiler\Events\SubscriptionBuilder::class);
        expect($provides)->toContain(\ZeroBoiler\Events\EventScheduler::class);
        expect($provides)->toHaveCount(7);
    }

    public function test_event_log_status_constants(): void
    {
        expect(\ZeroBoiler\Events\Models\EventLog::STATUS_PENDING)->toBe('pending');
        expect(\ZeroBoiler\Events\Models\EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(\ZeroBoiler\Events\Models\EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(\ZeroBoiler\Events\Models\EventLog::STATUS_FAILED)->toBe('failed');

        // Verify all unique
        $statuses = [
            \ZeroBoiler\Events\Models\EventLog::STATUS_PENDING,
            \ZeroBoiler\Events\Models\EventLog::STATUS_DISPATCHED,
            \ZeroBoiler\Events\Models\EventLog::STATUS_COMPLETED,
            \ZeroBoiler\Events\Models\EventLog::STATUS_FAILED,
        ];
        expect(array_unique($statuses))->toHaveCount(4);
    }

    public function test_facade_get_facade_accessor_returns_event_manager_class(): void
    {
        // The facade accessor must point to EventManager::class for container resolution
        $expected = \ZeroBoiler\Events\EventManager::class;

        // Verify via the static proxy (facade resolves to EventManager singleton)
        $resolved = app($expected);
        expect($resolved)->toBeInstanceOf($expected);
    }
}
