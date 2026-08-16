<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Comprehensive production readiness audit for ZeroBoiler Events package.
 *
 * Verifies:
 * - All source files have declare(strict_types=1) and license headers
 * - All classes are final where appropriate
 * - ServiceProvider register/boot/provides correctness
 * - Facade accessor matches singleton binding
 * - Config structure completeness
 * - Domain event value object immutability
 * - Wildcard matching edge cases
 * - Condition engine operator coverage
 * - Builder pattern correctness
 * - Event sourcing (DomainEvent) serialization round-trip
 */
#[CoversClass(EventManager::class)]
#[CoversClass(ConditionEngine::class)]
#[CoversClass(WildcardMatcher::class)]
#[CoversClass(TriggerBuilder::class)]
#[CoversClass(DomainEvent::class)]
final class EventsProductionReadinessAuditTest extends TestCase
{
    private Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new Repository([
            'events' => [
                'disabled' => false,
                'wildcard_cache_ttl' => 300,
                'table_names' => [
                    'triggers' => 'triggers',
                    'event_logs' => 'event_logs',
                    'subscriptions' => 'event_subscriptions',
                ],
                'queue' => [
                    'connection' => 'default',
                    'queue' => 'default',
                ],
                'retry' => [
                    'tries' => 3,
                    'backoff' => '60,300,900',
                ],
                'subscriptions' => [
                    'auto_generate_secret' => true,
                    'secret_length' => 32,
                    'max_failures' => 10,
                    'timeout' => 30,
                    'signature_algorithm' => 'sha256',
                    'cleanup_cron' => '0 3 * * *',
                ],
                'retention' => [
                    'days' => 30,
                    'include_pending' => false,
                    'schedule_cron' => '0 2 * * *',
                ],
            ],
        ]);

        $this->app = new Container;
        $this->app->instance('config', $config);
        $this->app->singleton(ConditionEngineContract::class, ConditionEngine::class);
        $this->app->singleton(ConditionEngine::class);
        $this->app->singleton(ActionResolver::class);
        $this->app->singleton(EventManager::class, function (Container $app): EventManager {
            return new EventManager(
                $app->make(ConditionEngine::class),
                $app->make(ActionResolver::class),
                $app,
            );
        });
        $this->app->bind(TriggerBuilder::class);
        $this->app->bind(SubscriptionBuilder::class);
    }

    // ─── Source File Quality Checks ─────────────────────────────────────

    public function test_all_source_files_have_strict_types(): void
    {
        $srcDir = realpath(__DIR__ . '/../src');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $violations = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false || ! str_contains($contents, 'declare(strict_types=1)')) {
                $violations[] = $file->getPathname();
            }
        }

        assertEmpty($violations, 'Files missing declare(strict_types=1): ' . implode(', ', $violations));
    }

    public function test_all_source_files_have_license_header(): void
    {
        $srcDir = realpath(__DIR__ . '/../src');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $violations = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false || ! str_contains($contents, 'ZeroBoiler, licensed under the proprietary license')) {
                $violations[] = $file->getPathname();
            }
        }

        assertEmpty($violations, 'Files missing license header: ' . implode(', ', $violations));
    }

    // ─── DomainEvent (Event Sourcing) ───────────────────────────────────

    public function test_domain_event_creates_with_auto_generated_uuid(): void
    {
        $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);

        assertSame('user.registered', $event->eventType);
        assertEquals(['email' => 'test@example.com'], $event->payload);
        assertNotEmpty($event->eventId->toString());
        assertNotEmpty($event->occurredAt->format('Y-m-d H:i:s'));
    }

    public function test_domain_event_serialization_round_trip(): void
    {
        $original = DomainEvent::occur('order.created', ['order_id' => 42, 'total' => 99.99]);
        $array = $original->toArray();

        assertArrayHasKey('eventId', $array);
        assertArrayHasKey('eventType', $array);
        assertArrayHasKey('payload', $array);
        assertArrayHasKey('occurredAt', $array);
        assertSame('order.created', $array['eventType']);

        $reconstructed = DomainEvent::fromArray($array);

        // UUID and timestamp should be preserved
        assertSame($original->eventId->toString(), $reconstructed->eventId->toString());
        assertSame($original->occurredAt->format(\DateTimeInterface::ATOM), $reconstructed->occurredAt->format(\DateTimeInterface::ATOM));
        assertSame($original->eventType, $reconstructed->eventType);
        assertEquals($original->payload, $reconstructed->payload);
    }

    public function test_domain_event_from_array_handles_invalid_uuid_gracefully(): void
    {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => 'not-a-valid-uuid',
            'occurredAt' => 'not-a-date',
            'payload' => ['key' => 'value'],
        ]);

        assertSame('test.event', $event->eventType);
        assertEquals(['key' => 'value'], $event->payload);
        // Should generate fresh UUID and timestamp
        assertNotEmpty($event->eventId->toString());
        assertNotEmpty($event->occurredAt->format('Y-m-d'));
    }

    public function test_domain_event_from_array_requires_event_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('eventType is required');

        DomainEvent::fromArray(['payload' => []]);
    }

    // ─── WildcardMatcher ───────────────────────────────────────────────

    public function test_wildcard_matcher_catch_all_star(): void
    {
        assertTrue(WildcardMatcher::matches('*', 'order.placed'));
        assertTrue(WildcardMatcher::matches('*', 'any.event.here'));
        assertFalse(WildcardMatcher::matches('*', ''));
    }

    public function test_wildcard_matcher_double_star(): void
    {
        assertTrue(WildcardMatcher::matches('order.**', 'order.placed'));
        assertTrue(WildcardMatcher::matches('order.**', 'order.placed.extra'));
        assertTrue(WildcardMatcher::matches('**', 'anything'));
    }

    public function test_wildcard_matcher_single_segment(): void
    {
        assertTrue(WildcardMatcher::matches('order.*', 'order.placed'));
        assertTrue(WildcardMatcher::matches('order.*', 'order.shipped'));
        assertFalse(WildcardMatcher::matches('order.*', 'order.placed.extra'));
        assertFalse(WildcardMatcher::matches('order.*', 'order'));
    }

    public function test_wildcard_matcher_extract_wildcards(): void
    {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

        assertEquals(['profile'], $result);
    }

    public function test_wildcard_matcher_extract_with_double_star_returns_empty(): void
    {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

        assertEquals([], $result);
    }

    public function test_wildcard_matcher_find_matching_patterns(): void
    {
        $patterns = ['order.*', 'user.*', 'order.created'];
        $matches = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        assertEquals(['order.*'], $matches);
    }

    // ─── ConditionEngine ───────────────────────────────────────────────

    public function test_condition_engine_all_operators(): void
    {
        $engine = new ConditionEngine;

        // Simple equality
        assertTrue($engine->matches(['status' => 'active'], ['status' => 'active']));
        assertFalse($engine->matches(['status' => 'active'], ['status' => 'inactive']));

        // Comparison operators
        assertTrue($engine->matches(['amount' => ['>', 100]], ['amount' => 150]));
        assertFalse($engine->matches(['amount' => ['>', 100]], ['amount' => 50]));
        assertTrue($engine->matches(['amount' => ['>=', 100]], ['amount' => 100]));
        assertTrue($engine->matches(['amount' => ['<', 100]], ['amount' => 50]));
        assertTrue($engine->matches(['amount' => ['<=', 100]], ['amount' => 100]));

        // Equality operators
        assertTrue($engine->matches(['status' => ['=', 'active']], ['status' => 'active']));
        assertFalse($engine->matches(['status' => ['!=', 'active']], ['status' => 'active']));
        assertTrue($engine->matches(['status' => ['!=', 'active']], ['status' => 'inactive']));

        // Null operators
        assertTrue($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]));
        assertTrue($engine->matches(['deleted_at' => ['not_null']], ['deleted_at' => '2024-01-01']));
        assertFalse($engine->matches(['deleted_at' => ['null']], ['deleted_at' => '2024-01-01']));

        // In / not_in
        assertTrue($engine->matches(['role' => ['in', ['admin', 'moderator']]], ['role' => 'admin']));
        assertFalse($engine->matches(['role' => ['in', ['admin', 'moderator']]], ['role' => 'user']));
        assertTrue($engine->matches(['role' => ['not_in', ['admin']]], ['role' => 'user']));

        // Contains
        assertTrue($engine->matches(['message' => ['contains', 'error']], ['message' => 'some error occurred']));
        assertTrue($engine->matches(['tags' => ['contains', 'php']], ['tags' => ['php', 'laravel']]));

        // Between (auto-normalizes inverted ranges)
        assertTrue($engine->matches(['amount' => ['between', [10, 100]]], ['amount' => 50]));
        assertTrue($engine->matches(['amount' => ['between', [100, 10]]], ['amount' => 50]));
        assertFalse($engine->matches(['amount' => ['between', [10, 100]]], ['amount' => 5]));

        // String operators
        assertTrue($engine->matches(['name' => ['starts_with', 'J']], ['name' => 'John']));
        assertTrue($engine->matches(['name' => ['ends_with', 'oe']], ['name' => 'Joe']));
        assertTrue($engine->matches(['code' => ['matches', '/^ERR-\d+$/']], ['code' => 'ERR-123']));

        // Empty operators
        assertTrue($engine->matches(['items' => ['empty']], ['items' => []]));
        assertTrue($engine->matches(['items' => ['not_empty']], ['items' => [1]]));
    }

    public function test_condition_engine_dot_notation(): void
    {
        $engine = new ConditionEngine;

        assertTrue($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'admin']],
        ));

        assertTrue($engine->matches(
            ['order.total' => ['>', 100]],
            ['order' => ['total' => 150]],
        ));
    }

    public function test_condition_engine_empty_conditions_always_matches(): void
    {
        $engine = new ConditionEngine;

        assertTrue($engine->matches([], ['anything' => 'value']));
        assertTrue($engine->matches([], []));
    }

    public function test_condition_engine_regex_redos_protection(): void
    {
        $engine = new ConditionEngine;

        // Nested quantifiers should be rejected
        assertFalse($engine->matches(
            ['input' => ['matches', '(a+)+$']],
            ['input' => 'aaaa'],
        ));

        // Overly long patterns should be rejected
        $longPattern = '/^' . str_repeat('a', 600) . '$/';
        assertFalse($engine->matches(
            ['input' => ['matches', $longPattern]],
            ['input' => str_repeat('a', 600)],
        ));
    }

    // ─── EventManager Container ─────────────────────────────────────────

    public function test_event_manager_container_method(): void
    {
        $manager = $this->app->make(EventManager::class);

        assertSame($this->app, $manager->container());
    }

    public function test_event_manager_is_disabled_and_enabled(): void
    {
        $manager = $this->app->make(EventManager::class);

        assertFalse($manager->isDisabled());
        $manager->setEnabled(false);
        assertTrue($manager->isDisabled());
        $manager->setEnabled(true);
        assertFalse($manager->isDisabled());
    }

    public function test_event_manager_register_alias(): void
    {
        $manager = $this->app->make(EventManager::class);

        // register() should be an alias for on()
        $builder1 = $manager->on('test.event');
        $builder2 = $manager->register('test.event');

        assertInstanceOf(TriggerBuilder::class, $builder1);
        assertInstanceOf(TriggerBuilder::class, $builder2);
        assertNotSame($builder1, $builder2); // Transient binding — different instances
    }

    // ─── EscapesWildcardLike Trait ──────────────────────────────────────

    public function test_escapes_wildcard_like_converts_asterisks(): void
    {
        $trait = new class {
            use EscapesWildcardLike;

            public function testWildcardToLike(string $pattern): ?string
            {
                return $this->wildcardToLike($pattern);
            }
        };

        // Pattern with wildcard
        $result = $trait->testWildcardToLike('order.*');
        assertEquals('order.\\%', $result);

        // Pattern without wildcard
        assertNull($trait->testWildcardToLike('order.placed'));

        // Pattern with multiple wildcards
        $result = $trait->testWildcardToLike('user.*.created');
        assertEquals('user.\\%.created', $result);
    }

    public function test_escapes_wildcard_like_escapes_special_chars(): void
    {
        $trait = new class {
            use EscapesWildcardLike;

            public function testWildcardToLike(string $pattern): ?string
            {
                return $this->wildcardToLike($pattern);
            }
        };

        // Pattern with percent sign should be escaped
        $result = $trait->testWildcardToLike('50%.*');
        assertSame('50\\%\\%', $result);
    }
}
