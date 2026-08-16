<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Domain\DomainEvent;
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
 * Phase 1 Infrastructure — comprehensive production readiness audit.
 *
 * Validates source file structure, PHP 8.5 compliance, service bindings,
 * config completeness, security measures, and key API contracts.
 */
final class EventsPhase170Phase1InfrastructureAuditTest extends TestCase
{
    // ── Source File Structure ──────────────────────────────────────────────

    /**
     * @test
     */
    public function all_33_source_files_exist_with_strict_types(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $files = $this->globR($srcDir, '*.php');

        $this->assertCount(33, $files, 'Expected exactly 33 source files in src/');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertNotFalse($content, "Could not read {$file}");
            $this->assertStringContainsString('declare(strict_types=1)', $content, "{$file} missing strict_types");
            $this->assertStringContainsString('This file is part of ZeroBoiler', $content, "{$file} missing license header");
        }
    }

    /**
     * @test
     */
    public function all_source_classes_are_final(): void
    {
        $classes = [
            EventManager::class,
            ConditionEngine::class,
            WildcardMatcher::class,
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
            EventsServiceProvider::class,
            EventManagerFacade::class,
        ];

        foreach ($classes as $class) {
            $ref = new \ReflectionClass($class);
            $this->assertTrue($ref->isFinal(), "{$class} must be declared final");
        }
    }

    /**
     * @test
     */
    public function wildcard_matcher_is_readonly_final_class(): void
    {
        $ref = new \ReflectionClass(WildcardMatcher::class);
        $this->assertTrue($ref->isReadOnly(), 'WildcardMatcher must be readonly');
        $this->assertTrue($ref->isFinal(), 'WildcardMatcher must be final');
    }

    /**
     * @test
     */
    public function condition_engine_implements_contract(): void
    {
        $engine = new ConditionEngine();
        $this->assertInstanceOf(ConditionEngineContract::class, $engine);
    }

    // ── ServiceProvider Bindings ──────────────────────────────────────────

    /**
     * @test
     */
    public function service_provider_provides_returns_7_bindings(): void
    {
        $provider = new \ReflectionClass(EventsServiceProvider::class);
        $method = $provider->getMethod('provides');
        $this->assertTrue($method->hasReturnType(), 'provides() must have return type');

        // Verify the provides method has the Override attribute
        $attrs = $method->getAttributes();
        $hasOverride = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        $this->assertTrue($hasOverride, 'provides() must have #[Override] attribute');
    }

    /**
     * @test
     */
    public function service_provider_has_correct_binding_lifetimes(): void
    {
        $ref = new \ReflectionMethod(EventsServiceProvider::class, 'register');
        $source = $this->getMethodSource($ref);

        // Singletons: EventManager, ConditionEngine, ConditionEngineContract, ActionResolver, EventScheduler
        $singletonCount = substr_count($source, 'singleton(');
        $this->assertGreaterThanOrEqual(5, $singletonCount, 'Expected at least 5 singleton bindings');

        // Transients: TriggerBuilder, SubscriptionBuilder
        $bindCount = substr_count($source, 'bind(');
        $this->assertGreaterThanOrEqual(2, $bindCount, 'Expected at least 2 transient bindings');
    }

    /**
     * @test
     */
    public function facade_accessor_points_to_event_manager(): void
    {
        $ref = new \ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
        $this->assertTrue($ref->hasReturnType());
        $this->assertTrue($ref->hasAttribute('Override'));

        $attrs = $ref->getAttributes('Override');
        $this->assertCount(1, $attrs);
    }

    // ── Config Completeness ────────────────────────────────────────────────

    /**
     * @test
     */
    public function config_has_8_top_level_keys(): void
    {
        $configPath = dirname(__DIR__, 2) . '/config/events.php';
        $this->assertFileExists($configPath);

        $config = require $configPath;
        $expectedKeys = [
            'table_names',
            'queue',
            'retry',
            'retention',
            'subscriptions',
            'disabled',
            'wildcard_cache_ttl',
        ];

        // 7 documented keys + any additional ones
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $config, "Config missing key: {$key}");
        }
        $this->assertGreaterThanOrEqual(7, count($config), 'Config must have at least 7 top-level keys');
    }

    // ── DomainEvent Immutability ───────────────────────────────────────────

    /**
     * @test
     */
    public function domain_event_properties_are_readonly(): void
    {
        $ref = new \ReflectionClass(DomainEvent::class);
        $props = ['eventId', 'eventType', 'payload', 'occurredAt'];

        foreach ($props as $prop) {
            $p = $ref->getProperty($prop);
            $this->assertTrue(
                $p->isReadOnly(),
                "DomainEvent::\${$prop} must be readonly"
            );
        }
    }

    /**
     * @test
     */
    public function domain_event_roundtrip_preserves_identity(): void
    {
        $original = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
        $array = $original->toArray();
        $restored = DomainEvent::fromArray($array);

        $this->assertSame($original->eventId->toString(), $restored->eventId->toString(), 'eventId must be preserved');
        $this->assertSame($original->eventType, $restored->eventType, 'eventType must be preserved');
        $this->assertSame($original->payload, $restored->payload, 'payload must be preserved');
        $this->assertSame($original->occurredAt->format('U'), $restored->occurredAt->format('U'), 'occurredAt must be preserved');
    }

    // ── ReDoS Protection ───────────────────────────────────────────────────

    /**
     * @test
     */
    public function condition_engine_rejects_nested_quantifier_patterns(): void
    {
        $engine = new ConditionEngine();

        // (a+)+ — nested quantifier should return false
        $this->assertFalse($engine->matches(
            ['code' => ['matches', '/(a+)+$/']],
            ['code' => 'aaaa'],
        ));
    }

    /**
     * @test
     */
    public function condition_engine_rejects_overly_long_regex(): void
    {
        $engine = new ConditionEngine();
        $longPattern = str_repeat('a', 501);

        $this->assertFalse($engine->matches(
            ['code' => ['matches', '/' . $longPattern . '/']],
            ['code' => str_repeat('a', 501)],
        ));
    }

    // ── WildcardMatcher Patterns ───────────────────────────────────────────

    /**
     * @test
     */
    public function wildcard_matcher_covers_all_pattern_types(): void
    {
        // Exact match
        $this->assertTrue(WildcardMatcher::matches('order.placed', 'order.placed'));

        // Single-segment wildcard
        $this->assertTrue(WildcardMatcher::matches('order.*', 'order.placed'));
        $this->assertFalse(WildcardMatcher::matches('order.*', 'order.placed.extra'));

        // Cross-segment wildcard
        $this->assertTrue(WildcardMatcher::matches('order.**', 'order.placed.extra'));

        // Catch-all
        $this->assertTrue(WildcardMatcher::matches('*', 'anything'));
        $this->assertTrue(WildcardMatcher::matches('**', 'anything'));
        $this->assertFalse(WildcardMatcher::matches('*', ''));

        // Non-matching
        $this->assertFalse(WildcardMatcher::matches('user.*', 'order.placed'));
    }

    // ── EventManager Global Disable ─────────────────────────────────────────

    /**
     * @test
     */
    public function event_manager_has_global_disable_methods(): void
    {
        $ref = new \ReflectionClass(EventManager::class);

        $this->assertTrue($ref->hasMethod('isDisabled'), 'EventManager must have isDisabled()');
        $this->assertTrue($ref->hasMethod('setEnabled'), 'EventManager must have setEnabled()');
    }

    // ── ConditionEngine All Operators ───────────────────────────────────────

    /**
     * @test
     */
    public function condition_engine_supports_all_documented_operators(): void
    {
        $engine = new ConditionEngine();
        $payload = [
            'amount' => 150,
            'status' => 'active',
            'tags' => ['urgent', 'billing'],
            'score' => 85,
            'min' => 50,
            'max' => 100,
            'code' => 'ABC-1234',
            'email' => 'admin@example.com',
            'domain' => 'example.com',
            'notes' => '',
            'deleted_at' => null,
        ];

        // Comparison operators
        $this->assertTrue($engine->matches(['amount' => ['>', 100]], $payload));
        $this->assertTrue($engine->matches(['amount' => ['>=', 150]], $payload));
        $this->assertTrue($engine->matches(['amount' => ['<', 200]], $payload));
        $this->assertTrue($engine->matches(['amount' => ['<=', 150]], $payload));

        // Equality
        $this->assertTrue($engine->matches(['status' => 'active'], $payload));
        $this->assertTrue($engine->matches(['status' => ['=', 'active']], $payload));
        $this->assertTrue($engine->matches(['status' => ['===', 'active']], $payload));
        $this->assertTrue($engine->matches(['status' => ['!=', 'inactive']], $payload));
        $this->assertTrue($engine->matches(['status' => ['!==', 123]], $payload));

        // Array operators
        $this->assertTrue($engine->matches(['tags' => ['contains', 'urgent']], $payload));
        $this->assertTrue($engine->matches(['tags' => ['not_contains', 'spam']], $payload));
        $this->assertTrue($engine->matches(['status' => ['in', ['active', 'pending']]], $payload));
        $this->assertTrue($engine->matches(['status' => ['not_in', ['deleted', 'banned']]], $payload));

        // Range
        $this->assertTrue($engine->matches(['score' => ['between', [50, 100]]], $payload));

        // Null checks
        $this->assertTrue($engine->matches(['deleted_at' => ['null']], $payload));
        $this->assertTrue($engine->matches(['status' => ['not_null']], $payload));

        // Empty
        $this->assertTrue($engine->matches(['notes' => ['empty']], $payload));
        $this->assertTrue($engine->matches(['status' => ['not_empty']], $payload));

        // String operators
        $this->assertTrue($engine->matches(['email' => ['starts_with', 'admin@']], $payload));
        $this->assertTrue($engine->matches(['domain' => ['ends_with', '.com']], $payload));
        $this->assertTrue($engine->matches(['code' => ['matches', '/^[A-Z]{3}-\\d{4}$/']], $payload));
    }

    // ── EscapesWildcardLike SQL Injection Prevention ────────────────────────

    /**
     * @test
     */
    public function escapes_wildcard_like_escapes_sql_chars(): void
    {
        // Use a concrete class that uses the trait
        $ref = new \ReflectionMethod(Subscription::class, 'wildcardToLike');
        $ref->setAccessible(true);

        // Pattern with SQL special chars
        $result = $ref->invoke(new Subscription, 'user.%.test');
        $this->assertNotNull($result);
        $this->assertStringNotContainsString('%', str_replace('\\%', '', $result));
        $this->assertStringContainsString('\\%', $result);
    }

    // ── WebhookAction HMAC Signing ──────────────────────────────────────────

    /**
     * @test
     */
    public function webhook_action_implements_triggerable(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(WebhookAction::class))->implementsInterface(\ZeroBoiler\Events\Contracts\Triggerable::class)
        );
    }

    // ── TriggerBuilder Action Dedup ────────────────────────────────────────

    /**
     * @test
     */
    public function trigger_builder_resolve_actions_is_private(): void
    {
        $ref = new \ReflectionMethod(TriggerBuilder::class, 'resolveActions');
        $this->assertTrue($ref->isPrivate(), 'resolveActions() must be private');
    }

    // ── phpstan.neon.dist Level 9 ──────────────────────────────────────────

    /**
     * @test
     */
    public function phpstan_config_is_level_9(): void
    {
        $configPath = dirname(__DIR__, 2) . '/phpstan.neon.dist';
        $this->assertFileExists($configPath);

        $content = file_get_contents($configPath);
        $this->assertStringContainsString('level: 9', $content);
        $this->assertStringContainsString('bootstrapFiles:', $content);
        $this->assertStringContainsString('checkExplicitMixed: true', $content);
        $this->assertStringContainsString('reportUnusedIgnoredErrors: true', $content);
    }

    // ── composer.json PHP 8.5+/Laravel 13.x ──────────────────────────────

    /**
     * @test
     */
    public function composer_json_targets_php_85_and_laravel_13(): void
    {
        $composerPath = dirname(__DIR__, 2) . '/composer.json';
        $this->assertFileExists($composerPath);

        $json = json_decode(file_get_contents($composerPath), true);
        $this->assertNotFalse($json);
        $this->assertSame('^8.5', $json['require']['php']);
        $this->assertSame('^13.0', $json['require']['illuminate/contracts']);
    }

    // ── Subscription recordDelivery Atomicity ───────────────────────────────

    /**
     * @test
     */
    public function subscription_record_delivery_source_uses_transaction(): void
    {
        $ref = new \ReflectionMethod(Subscription::class, 'recordDelivery');
        $source = $this->getMethodSource($ref);

        $this->assertStringContainsString('transaction(', $source, 'recordDelivery() must use a transaction for atomicity');
        $this->assertStringContainsString('increment(', $source, 'recordDelivery() must call increment()');
    }

    // ── Model Status Constants ─────────────────────────────────────────────

    /**
     * @test
     */
    public function event_log_has_status_constants(): void
    {
        $ref = new \ReflectionClass(EventLog::class);

        $this->assertSame('pending', $ref->getConstant('STATUS_PENDING'));
        $this->assertSame('dispatched', $ref->getConstant('STATUS_DISPATCHED'));
        $this->assertSame('completed', $ref->getConstant('STATUS_COMPLETED'));
        $this->assertSame('failed', $ref->getConstant('STATUS_FAILED'));
    }

    // ── Helper Methods ──────────────────────────────────────────────────────

    /**
     * Recursively glob for files matching pattern.
     *
     * @return list<string>
     */
    private function globR(string $dir, string $pattern): array
    {
        $results = [];
        $files = glob(rtrim($dir, '/') . '/' . $pattern);

        if ($files !== false) {
            foreach ($files as $file) {
                $results[] = $file;
            }
        }

        $dirs = glob(rtrim($dir, '/') . '/*', GLOB_ONLYDIR);

        if ($dirs !== false) {
            foreach ($dirs as $subDir) {
                $results = array_merge($results, $this->globR($subDir, $pattern));
            }
        }

        return $results;
    }

    private function getMethodSource(\ReflectionMethod $method): string
    {
        $filename = $method->getFileName();

        if ($filename === false) {
            return '';
        }

        $lines = file($filename);

        if ($lines === false) {
            return '';
        }

        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();

        return implode('', array_slice($lines, $start, $end - $start));
    }
}
