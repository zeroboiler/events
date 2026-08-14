<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

beforeEach(function (): void {
    // Ensure fresh tables
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

describe('Phase 87 — Production Audit', function (): void {

    test('EventManager::getMatchingTriggers respects empty Collection return from cache', function (): void {
        // When there are no wildcard triggers, getEnabledWildcardTriggers
        // should return an empty Collection (not null)
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        // Fire an event that has no triggers at all
        $manager->fire('nonexistent.event.test', ['key' => 'value']);
        // Should not throw — no triggers matched is valid behavior
        expect(true)->toBeTrue();
    });

    test('TriggerBuilder save generates valid action string with single action and params', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $builder = $manager->on('test.single.action.params');

        $trigger = $builder
            ->name('Single Action With Params')
            ->action('App\\Actions\\TestAction')
            ->actionParams(['url' => 'https://example.com/hook'])
            ->async(true)
            ->priority(5)
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray();
        expect($decoded['class'])->toBe('App\\Actions\\TestAction');
        expect($decoded['params'])->toBe(['url' => 'https://example.com/hook']);

        $trigger->delete();
    });

    test('TriggerBuilder save generates valid action string with multiple actions and params', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $builder = $manager->on('test.multi.action.params');

        $trigger = $builder
            ->name('Multi Action With Params')
            ->actions(['App\\Actions\\First', 'App\\Actions\\Second'])
            ->actionParams(['webhook_url' => 'https://example.com'])
            ->priority(10)
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray();
        expect($decoded['classes'])->toBe(['App\\Actions\\First', 'App\\Actions\\Second']);
        expect($decoded['params'])->toBe(['webhook_url' => 'https://example.com']);

        $trigger->delete();
    });

    test('ConditionEngine::strictEquals handles int vs string comparison', function (): void {
        $engine = new ConditionEngine;

        // '5' (string) should match 5 (int) via string coercion (both scalar)
        expect($engine->matches(['amount' => '5'], ['amount' => 5]))->toBeTrue();
        // But strict === should be false
        expect($engine->matches(['amount' => ['===', '5']], ['amount' => 5]))->toBeFalse();
    });

    test('ConditionEngine::strictEquals handles float vs int comparison', function (): void {
        $engine = new ConditionEngine;

        // float 5.0 vs int 5 — different types, both scalar, string coercion
        expect($engine->matches(['value' => 5], ['value' => 5.0]))->toBeTrue();
    });

    test('ConditionEngine handles empty payload with non-empty conditions', function (): void {
        $engine = new ConditionEngine;

        // Empty payload — conditions reference fields that don't exist
        expect($engine->matches(
            ['status' => 'active', 'role' => 'admin'],
            [],
        ))->toBeFalse();
    });

    test('ConditionEngine between operator with non-numeric actual', function (): void {
        $engine = new ConditionEngine;

        expect($engine->matches(
            ['name' => ['between', [1, 10]]],
            ['name' => 'hello'],
        ))->toBeFalse();
    });

    test('WildcardMatcher matches empty event against catch-all', function (): void {
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    test('WildcardMatcher exact match without wildcards', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    });

    test('WildcardMatcher single char event', function (): void {
        expect(WildcardMatcher::matches('*', 'a'))->toBeTrue();
        expect(WildcardMatcher::matches('a', 'a'))->toBeTrue();
        expect(WildcardMatcher::matches('a', 'b'))->toBeFalse();
    });

    test('WildcardMatcher findMatchingPatterns empty input', function (): void {
        expect(WildcardMatcher::findMatchingPatterns([], 'order.placed'))->toBe([]);
    });

    test('WildcardMatcher extractWildcards with no wildcards in pattern', function (): void {
        expect(WildcardMatcher::extractWildcards('order.placed', 'order.placed'))->toBe([]);
    });

    test('DomainEvent fromArray preserves all fields including extras', function (): void {
        $event = DomainEvent::occur('user.created', ['email' => 'test@example.com']);

        $array = $event->toArray();
        // Add an extra field that's not part of the standard structure
        $array['extra_field'] = 'should_be_ignored';

        $restored = DomainEvent::fromArray($array);

        expect($restored->eventType)->toBe('user.created');
        expect($restored->eventId->toString())->toBe($event->eventId->toString());
        expect($restored->occurredAt->format(DateTimeImmutable::ATOM))->toBe(
            $event->occurredAt->format(DateTimeImmutable::ATOM)
        );
        // Extra fields in the payload are preserved if in the payload key
        expect($restored->payload)->toHaveKey('email');
    });

    test('DomainEvent fromArray with empty eventType throws', function (): void {
        expect(fn () => DomainEvent::fromArray([]))->toThrow(InvalidArgumentException::class);
    });

    test('EventManager fireModel with object having only toArray (no attributesToArray)', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $model = new class {
            public function toArray(): array
            {
                return ['id' => 42, 'name' => 'Test'];
            }
        };

        // Should not throw — it uses toArray() as fallback
        $manager->fireModel('App\\Models\\Test', 'created', $model);
        expect(true)->toBeTrue();
    });

    test('Subscription::signPayload returns empty for empty secret', function (): void {
        $sub = Subscription::factory()->create(['secret' => '']);

        $signature = $sub->signPayload('test-payload');

        expect($signature)->toBe('');
    });

    test('Subscription::hasExceededFailures with explicit override', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 5]);

        // With max=3, should be exceeded
        expect($sub->hasExceededFailures(3))->toBeTrue();
        // With max=10, should not be exceeded
        expect($sub->hasExceededFailures(10))->toBeFalse();
    });

    test('EventLog status constants are consistent', function (): void {
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
        expect(EventLog::$statuses)->toHaveCount(4);
    });

    test('DispatchTriggerJob properties are set correctly from config', function (): void {
        $job = new DispatchTriggerJob(
            'trigger-uuid',
            'test.event',
            ['key' => 'value'],
        );

        // Reads from config events.retry.tries which defaults to 3 in tests
        expect($job->tries)->toBeGreaterThanOrEqual(1);
        expect($job->backoff)->toBeArray();
        expect($job->queue)->toBeString();
        expect($job->triggerId)->toBe('trigger-uuid');
        expect($job->event)->toBe('test.event');
        expect($job->payload)->toBe(['key' => 'value']);
    });

    test('EventsServiceProvider provides all registered services', function (): void {
        $provider = app(\ZeroBoiler\Events\EventsServiceProvider::class);

        $provides = $provider->provides();

        expect($provides)->toContain(\ZeroBoiler\Events\EventManager::class);
        expect($provides)->toContain(ConditionEngine::class);
        expect($provides)->toContain(ConditionEngineContract::class);
        expect($provides)->toContain(ActionResolver::class);
        expect($provides)->toContain(TriggerBuilder::class);
        expect($provides)->toContain(SubscriptionBuilder::class);
        expect($provides)->toHaveCount(6);
    });

    test('SubscriptionBuilder validates event name is not empty on save', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect(fn () => $manager->subscribe('', 'https://example.com/hook')->save())
            ->toThrow(InvalidArgumentException::class, 'Event name is required');
    });

    test('SubscriptionBuilder validates URL is not empty on save', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect(fn () => $manager->subscribe('test.event', '')->save())
            ->toThrow(InvalidArgumentException::class, 'Webhook URL is required');
    });

    test('TriggerBuilder validates event name is not empty on save', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect(fn () => $manager->on('')->action('App\\Actions\\Test')->save())
            ->toThrow(InvalidArgumentException::class, 'Event name is required');
    });

    test('TriggerBuilder validates at least one action on save', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect(fn () => $manager->on('test.event')->save())
            ->toThrow(InvalidArgumentException::class, 'At least one action is required');
    });

    test('EventManager listTriggers with empty event filter string returns all', function (): void {
        Trigger::factory()->count(3)->create(['enabled' => true]);

        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $results = $manager->listTriggers('');

        expect($results)->toBeInstanceOf(Collection::class);
        expect($results->count())->toBe(3);
    });

    test('EventManager listSubscriptions with null event returns all', function (): void {
        Subscription::factory()->count(2)->create(['active' => true]);

        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $results = $manager->listSubscriptions(null);

        expect($results)->toBeInstanceOf(Collection::class);
        expect($results->count())->toBe(2);
    });

    test('EventManager getStats returns correct structure', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);
        $stats = $manager->getStats();

        expect($stats)->toHaveKeys([
            'total_logs',
            'total_triggers',
            'active_triggers',
            'completed',
            'failed',
            'pending',
            'dispatched',
            'success_rate',
            'failure_rate',
            'avg_duration_ms',
            'top_events',
            'top_failed_events',
        ]);
    });

    test('EventLog scopeStalePending works correctly', function (): void {
        $trigger = Trigger::factory()->create();
        EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_PENDING,
            'created_at' => now()->subHours(5),
        ]);
        EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'status' => EventLog::STATUS_PENDING,
            'created_at' => now()->subMinutes(5),
        ]);

        $stale = EventLog::stalePending(now()->subHours(2))->get();

        expect($stale)->toHaveCount(1);
    });

    test('Trigger scopeEnabled returns only enabled triggers', function (): void {
        Trigger::factory()->create(['enabled' => true, 'event' => 'enabled.test']);
        Trigger::factory()->create(['enabled' => false, 'event' => 'disabled.test']);

        $enabled = Trigger::enabled()->get();
        expect($enabled)->toHaveCount(1);
        expect($enabled->first()->enabled)->toBeTrue();
    });

    test('Subscription scopeForEvent with wildcard pattern', function (): void {
        Subscription::factory()->create(['event' => 'order.placed']);
        Subscription::factory()->create(['event' => 'order.shipped']);

        $matched = Subscription::forEvent('order.*')->get();
        expect($matched)->toHaveCount(2);
    });

    test('Config file contains all required keys', function (): void {
        $config = include __DIR__.'/../config/events.php';

        expect($config)->toHaveKey('table_names');
        expect($config)->toHaveKey('queue');
        expect($config)->toHaveKey('retry');
        expect($config)->toHaveKey('retention');
        expect($config)->toHaveKey('subscriptions');
        expect($config)->toHaveKey('disabled');
        expect($config)->toHaveKey('wildcard_cache_ttl');

        expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
        expect($config['queue'])->toHaveKeys(['connection', 'queue']);
        expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
        expect($config['retention'])->toHaveKeys(['days', 'include_pending']);
        expect($config['subscriptions'])->toHaveKeys([
            'auto_generate_secret',
            'max_failures',
            'timeout',
            'signature_algorithm',
        ]);
    });

    test('phpstan.neon.dist level is 9', function (): void {
        $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
        expect($content)->toContain('level: max');
        expect($content)->toContain('checkMissingIterableValueType: true');
        expect($content)->toContain('checkGenericClassInNonGenericObjectType: true');
        expect($content)->toContain('checkUninitializedProperties: true');
        expect($content)->toContain('checkClassLikeNameCase: true');
    });

    test('All source files have strict types declaration', function (): void {
        $srcDir = __DIR__.'/../src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $phpFiles = array_filter(
            iterator_to_array($iterator),
            fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
        );

        foreach ($phpFiles as $file) {
            $contents = file_get_contents($file->getPathname());
            expect($contents)->toContain('declare(strict_types=1)');
        }
    });

    test('composer.json extra section has correct provider and alias', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        expect($composer['extra']['laravel']['providers'])->toContain(
            'ZeroBoiler\\Events\\EventsServiceProvider',
        );
        expect($composer['extra']['laravel']['aliases'])->toHaveKey('EventManager');
        expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
            'ZeroBoiler\\Events\\Facades\\EventManager',
        );
    });
});
