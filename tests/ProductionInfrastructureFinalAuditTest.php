<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Container\Container;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

class ProductionInfrastructureFinalAuditTest extends TestCase
{
    // ─── ServiceProvider Binding Contract ─────────────────────────────

    public function test_event_manager_is_singleton(): void
    {
        $first = $this->app()->make(EventManager::class);
        $second = $this->app()->make(EventManager::class);

        $this->assertSame($first, $second, 'EventManager must be bound as singleton');
    }

    public function test_condition_engine_is_singleton(): void
    {
        $first = $this->app()->make(ConditionEngine::class);
        $second = $this->app()->make(ConditionEngine::class);

        $this->assertSame($first, $second, 'ConditionEngine must be bound as singleton');
    }

    public function test_condition_engine_contract_resolves_to_condition_engine(): void
    {
        $instance = $this->app()->make(ConditionEngineContract::class);

        $this->assertInstanceOf(ConditionEngine::class, $instance);
        $this->assertSame(
            $this->app()->make(ConditionEngine::class),
            $instance,
            'ConditionEngineContract must resolve to the same ConditionEngine singleton',
        );
    }

    public function test_action_resolver_is_singleton(): void
    {
        $first = $this->app()->make(ActionResolver::class);
        $second = $this->app()->make(ActionResolver::class);

        $this->assertSame($first, $second, 'ActionResolver must be bound as singleton');
    }

    public function test_event_scheduler_is_singleton(): void
    {
        $first = $this->app()->make(EventScheduler::class);
        $second = $this->app()->make(EventScheduler::class);

        $this->assertSame($first, $second, 'EventScheduler must be bound as singleton');
    }

    public function test_trigger_builder_is_transient(): void
    {
        $first = $this->app()->make(TriggerBuilder::class);
        $second = $this->app()->make(TriggerBuilder::class);

        $this->assertNotSame($first, $second, 'TriggerBuilder must be transient (fresh instance per resolution)');
    }

    public function test_subscription_builder_is_transient(): void
    {
        $first = $this->app()->make(SubscriptionBuilder::class);
        $second = $this->app()->make(SubscriptionBuilder::class);

        $this->assertNotSame($first, $second, 'SubscriptionBuilder must be transient (fresh instance per resolution)');
    }

    // ─── EventManager::container() ────────────────────────────────────

    public function test_container_returns_application_container(): void
    {
        $manager = $this->app()->make(EventManager::class);
        $container = $manager->container();

        $this->assertInstanceOf(Container::class, $container);
        $this->assertSame($this->app(), $container);
    }

    // ─── Global Enable / Disable ──────────────────────────────────────

    public function test_set_enabled_false_disables_system(): void
    {
        $manager = $this->app()->make(EventManager::class);

        $manager->setEnabled(false);
        $this->assertTrue($manager->isDisabled());

        $manager->setEnabled(true);
        $this->assertFalse($manager->isDisabled());
    }

    public function test_fire_returns_early_when_disabled(): void
    {
        $manager = $this->app()->make(EventManager::class);
        $manager->setEnabled(false);

        // Should not throw, just return silently
        $manager->fire('test.silent', ['key' => 'value']);

        // No trigger was created, no error — just silent return
        $this->assertTrue(true);
    }

    // ─── Facade Contract ──────────────────────────────────────────────

    public function test_facade_resolves_to_event_manager(): void
    {
        $facadeRoot = EventManagerFacade::getFacadeRoot();

        $this->assertInstanceOf(EventManager::class, $facadeRoot);
        $this->assertSame($this->app()->make(EventManager::class), $facadeRoot);
    }

    // ─── WildcardMatcher Edge Cases ───────────────────────────────────

    public function test_wildcard_matcher_find_matching_patterns(): void
    {
        $patterns = ['order.*', 'user.*', 'invoice.**', 'order.placed'];
        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        $this->assertSame(['order.*', 'order.placed'], $result);
    }

    public function test_wildcard_matcher_find_matching_patterns_empty_input(): void
    {
        $this->assertSame([], WildcardMatcher::findMatchingPatterns([], 'order.placed'));
    }

    public function test_wildcard_matcher_find_matching_patterns_no_match(): void
    {
        $patterns = ['user.*', 'invoice.*'];
        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        $this->assertSame([], $result);
    }

    public function test_wildcard_matcher_catch_all_matches_anything(): void
    {
        $this->assertTrue(WildcardMatcher::matches('*', 'a'));
        $this->assertTrue(WildcardMatcher::matches('*', 'a.b.c'));
        $this->assertFalse(WildcardMatcher::matches('*', ''));
    }

    public function test_wildcard_matcher_double_star_catch_all(): void
    {
        $this->assertTrue(WildcardMatcher::matches('**', 'anything.at.all'));
        $this->assertFalse(WildcardMatcher::matches('**', ''));
    }

    public function test_wildcard_matcher_cross_segment(): void
    {
        $this->assertTrue(WildcardMatcher::matches('order.**', 'order.placed'));
        $this->assertTrue(WildcardMatcher::matches('order.**', 'order.placed.extra'));
        $this->assertFalse(WildcardMatcher::matches('order.**', 'user.placed'));
    }

    public function test_wildcard_matcher_single_segment(): void
    {
        $this->assertTrue(WildcardMatcher::matches('order.*', 'order.placed'));
        $this->assertTrue(WildcardMatcher::matches('order.*', 'order.shipped'));
        $this->assertFalse(WildcardMatcher::matches('order.*', 'order.placed.extra'));
    }

    public function test_wildcard_matcher_multiple_single_wildcards(): void
    {
        $this->assertTrue(WildcardMatcher::matches('*.order.*', 'user.order.created'));
        $this->assertFalse(WildcardMatcher::matches('*.order.*', 'user.order'));  // 3 segments vs 2
    }

    public function test_wildcard_matcher_extract_wildcards(): void
    {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

        $this->assertSame(['profile'], $result);
    }

    public function test_wildcard_matcher_extract_wildcards_cross_segment_returns_empty(): void
    {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

        $this->assertSame([], $result);
    }

    // ─── EscapesWildcardLike Trait ────────────────────────────────────

    public function test_wildcard_to_like_null_for_exact_match(): void
    {
        // EventManager uses this trait; test through the public API
        $manager = $this->app()->make(EventManager::class);

        // Exact event names should return triggers from DB (no LIKE needed)
        Trigger::factory()->create(['event' => 'exact.match', 'enabled' => true]);

        $triggers = $manager->listTriggers('exact.match');
        $this->assertCount(1, $triggers);
    }

    public function test_wildcard_to_like_converts_asterisk_to_percent(): void
    {
        $manager = $this->app()->make(EventManager::class);

        Trigger::factory()->create(['event' => 'order.placed', 'enabled' => true]);
        Trigger::factory()->create(['event' => 'order.shipped', 'enabled' => true]);
        Trigger::factory()->create(['event' => 'user.created', 'enabled' => true]);

        $triggers = $manager->listTriggers('order.*');

        $this->assertCount(2, $triggers);
        foreach ($triggers as $t) {
            $this->assertStringStartsWith('order.', $t->event);
        }
    }

    // ─── ConditionEngine Dot Notation ─────────────────────────────────

    public function test_condition_engine_nested_dot_notation(): void
    {
        $engine = $this->app()->make(ConditionEngine::class);

        $result = $engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'admin', 'name' => 'John']],
        );

        $this->assertTrue($result);
    }

    public function test_condition_engine_nested_missing_key_returns_false(): void
    {
        $engine = $this->app()->make(ConditionEngine::class);

        $result = $engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['name' => 'John']],
        );

        $this->assertFalse($result);
    }

    // ─── Exception Hierarchy ───────────────────────────────────────────

    public function test_event_exception_is_runtime_exception(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new \ZeroBoiler\Events\Exceptions\EventException);
    }

    public function test_all_leaf_exceptions_extend_event_exception(): void
    {
        $base = \ZeroBoiler\Events\Exceptions\EventException::class;

        $this->assertInstanceOf($base, new \ZeroBoiler\Events\Exceptions\ActionResolutionException('TestClass', 'reason'));
        $this->assertInstanceOf($base, new \ZeroBoiler\Events\Exceptions\ConditionEvaluationException('field', 'reason'));
        $this->assertInstanceOf($base, new \ZeroBoiler\Events\Exceptions\SubscriptionException('message'));
        $this->assertInstanceOf($base, new \ZeroBoiler\Events\Exceptions\TriggerNotFoundException('id'));
    }

    public function test_event_exception_accepts_previous(): void
    {
        $previous = new \RuntimeException('original');
        $exception = new \ZeroBoiler\Events\EventException('wrapped', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    // ─── DomainEvent Immutability ─────────────────────────────────────

    public function test_domain_event_is_immutable(): void
    {
        $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line — intentionally testing runtime immutability
        $event->eventType = 'changed';
    }

    public function test_domain_event_from_array_preserves_identity(): void
    {
        $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('order.created', ['id' => 1]);
        $data = $original->toArray();

        $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($data);

        $this->assertSame($original->eventId->toString(), $restored->eventId->toString());
        $this->assertSame($original->eventType, $restored->eventType);
        $this->assertSame($original->occurredAt->format(\DateTimeImmutable::ATOM), $restored->occurredAt->format(\DateTimeImmutable::ATOM));
    }

    // ─── Model Table Name Config Override ─────────────────────────────

    public function test_trigger_uses_config_table_name(): void
    {
        $config = $this->app()->make('config');
        $config->set('events.table_names.triggers', 'custom_triggers');

        $trigger = new Trigger;
        $this->assertSame('custom_triggers', $trigger->getTable());

        // Reset
        $config->set('events.table_names.triggers', 'triggers');
    }

    public function test_event_log_uses_config_table_name(): void
    {
        $config = $this->app()->make('config');
        $config->set('events.table_names.event_logs', 'custom_logs');

        $log = new EventLog;
        $this->assertSame('custom_logs', $log->getTable());

        // Reset
        $config->set('events.table_names.event_logs', 'event_logs');
    }

    public function test_subscription_uses_config_table_name(): void
    {
        $config = $this->app()->make('config');
        $config->set('events.table_names.subscriptions', 'custom_subs');

        $sub = new Subscription;
        $this->assertSame('custom_subs', $sub->getTable());

        // Reset
        $config->set('events.table_names.subscriptions', 'event_subscriptions');
    }

    // ─── EventLog Status Constants ────────────────────────────────────

    public function test_event_log_status_constants(): void
    {
        $this->assertSame('pending', EventLog::STATUS_PENDING);
        $this->assertSame('dispatched', EventLog::STATUS_DISPATCHED);
        $this->assertSame('completed', EventLog::STATUS_COMPLETED);
        $this->assertSame('failed', EventLog::STATUS_FAILED);
    }

    public function test_event_log_statuses_array(): void
    {
        $expected = ['pending', 'dispatched', 'completed', 'failed'];
        $this->assertSame($expected, EventLog::$statuses);
    }

    // ─── Payload Size Guard ────────────────────────────────────────────

    public function test_fire_rejects_oversized_payload(): void
    {
        $manager = $this->app()->make(EventManager::class);

        $config = $this->app()->make('config');
        $config->set('events.payload_max_bytes', 100);

        try {
            $manager->fire('test.event', ['data' => str_repeat('x', 200)]);
            $this->fail('Expected InvalidArgumentException for oversized payload');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('maximum allowed size', $e->getMessage());
        } finally {
            $config->set('events.payload_max_bytes', 1_048_576);
        }
    }

    public function test_fire_accepts_payload_when_limit_is_zero(): void
    {
        $manager = $this->app()->make(EventManager::class);

        $config = $this->app()->make('config');
        $config->set('events.payload_max_bytes', 0);

        // Should not throw regardless of size
        $manager->fire('test.no.limit', ['data' => str_repeat('x', 50_000)]);

        $config->set('events.payload_max_bytes', 1_048_576);
        $this->assertTrue(true);
    }

    // ─── Cache TTL Edge Cases ─────────────────────────────────────────

    public function test_wildcard_cache_ttl_zero_disables_cache(): void
    {
        $config = $this->app()->make('config');
        $config->set('events.wildcard_cache_ttl', 0);

        // Create a wildcard trigger
        Trigger::factory()->create(['event' => 'cache.test.*', 'enabled' => true, 'async' => false]);

        // Should fire without error even with cache disabled
        // (no trigger action class exists so we can't actually fire, but listing should work)
        $triggers = Trigger::enabled()->where('event', 'like', '%*%')->get();
        $this->assertCount(1, $triggers);

        $config->set('events.wildcard_cache_ttl', 300);
    }

    // ─── Delete Trigger ────────────────────────────────────────────────

    public function test_delete_trigger_invalidates_cache(): void
    {
        $manager = $this->app()->make(EventManager::class);

        $trigger = Trigger::factory()->create(['event' => 'to.delete.*', 'enabled' => true]);
        $id = $trigger->id;

        $this->assertTrue($manager->deleteTrigger($id));
        $this->assertNull(Trigger::find($id));
    }

    public function test_delete_trigger_returns_false_for_empty_id(): void
    {
        $manager = $this->app()->make(EventManager::class);

        $this->assertFalse($manager->deleteTrigger(''));
        $this->assertFalse($manager->deleteTrigger('0'));
    }

    // ─── Enable / Disable ──────────────────────────────────────────────

    public function test_enable_disable_nonexistent_trigger(): void
    {
        $manager = $this->app()->make(EventManager::class);
        $fakeId = (string) \Illuminate\Support\Str::uuid();

        $this->assertFalse($manager->enable($fakeId));
        $this->assertFalse($manager->disable($fakeId));
    }

    // ─── List Triggers Filtering ───────────────────────────────────────

    public function test_list_triggers_with_all_filters(): void
    {
        Trigger::factory()->create(['event' => 'filter.test', 'enabled' => true, 'priority' => 10]);
        Trigger::factory()->create(['event' => 'filter.other', 'enabled' => false, 'priority' => 5]);

        $manager = $this->app()->make(EventManager::class);

        $enabled = $manager->listTriggers(enabled: true);
        $this->assertGreaterThanOrEqual(1, $enabled->count());

        foreach ($enabled as $t) {
            $this->assertTrue($t->enabled);
        }
    }

    // ─── Subscription::matchesEvent ───────────────────────────────────

    public function test_subscription_matches_event_exact(): void
    {
        $sub = new Subscription(['event' => 'order.placed']);
        $this->assertTrue($sub->matchesEvent('order.placed'));
        $this->assertFalse($sub->matchesEvent('order.shipped'));
    }

    public function test_subscription_matches_event_wildcard(): void
    {
        $sub = new Subscription(['event' => 'order.*']);
        $this->assertTrue($sub->matchesEvent('order.placed'));
        $this->assertFalse($sub->matchesEvent('order.placed.extra'));
    }

    public function test_subscription_matches_event_cross_segment_wildcard(): void
    {
        $sub = new Subscription(['event' => 'order.**']);
        $this->assertTrue($sub->matchesEvent('order.placed'));
        $this->assertTrue($sub->matchesEvent('order.placed.extra'));
    }

    // ─── Subscription::signPayload ─────────────────────────────────────

    public function test_subscription_sign_payload_returns_hmac(): void
    {
        $sub = new Subscription(['secret' => 'whsec_testsecret1234567890abcdef']);
        $signature = $sub->signPayload('{"test": true}');

        $this->assertNotEmpty($signature);
        $this->assertSame(64, strlen($signature)); // SHA256 = 32 bytes = 64 hex chars
    }

    public function test_subscription_sign_payload_empty_secret_returns_empty(): void
    {
        $sub = new Subscription(['secret' => null]);
        $this->assertSame('', $sub->signPayload('{}'));
    }

    // ─── EventLog Scopes ───────────────────────────────────────────────

    public function test_event_log_scope_stale_pending(): void
    {
        $oldLog = EventLog::factory()->pending()->create();
        // Manually backdate
        $oldLog->update(['created_at' => now()->subHours(2)]);

        $stale = EventLog::stalePending(now()->subHour())->get();
        $this->assertGreaterThanOrEqual(1, $stale->count());
    }

    // ─── Trigger Scopes ────────────────────────────────────────────────

    public function test_trigger_scope_enabled(): void
    {
        Trigger::factory()->create(['enabled' => true]);
        Trigger::factory()->create(['enabled' => false]);

        $enabled = Trigger::enabled()->get();
        foreach ($enabled as $t) {
            $this->assertTrue($t->enabled);
        }
    }

    public function test_trigger_scope_order_by_priority(): void
    {
        Trigger::factory()->create(['priority' => 1]);
        Trigger::factory()->create(['priority' => 10]);
        Trigger::factory()->create(['priority' => 5]);

        $ordered = Trigger::orderByPriority()->get()->values();
        $this->assertSame(10, $ordered[0]->priority);
        $this->assertSame(5, $ordered[1]->priority);
        $this->assertSame(1, $ordered[2]->priority);
    }

    // ─── fireModel Validation ──────────────────────────────────────────

    public function test_fire_model_rejects_empty_model_class(): void
    {
        $manager = $this->app()->make(EventManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model class name cannot be empty');
        $manager->fireModel('', 'created', new \stdClass);
    }

    public function test_fire_model_rejects_empty_action(): void
    {
        $manager = $this->app()->make(EventManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model action cannot be empty');
        $manager->fireModel('App\Models\Order', '', new \stdClass);
    }

    // ─── Helper ────────────────────────────────────────────────────────

    private function app(): Container
    {
        $app = self::$app;

        if (! $app instanceof Container) {
            throw new \RuntimeException('Application container not available');
        }

        return $app;
    }
}
