<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
    Cache::clear();
});

// ─── Phase 76: ServiceProvider provides() #[Override] ─────────────────

test('EventsServiceProvider::provides() has Override attribute', function (): void {
    $method = new ReflectionMethod(EventsServiceProvider::class, 'provides');
    $attrs = $method->getAttributes(\Override::class);

    expect($attrs)->toHaveCount(1, 'EventsServiceProvider::provides() must have #[Override]');
});

test('EventsServiceProvider::register() has Override attribute', function (): void {
    $method = new ReflectionMethod(EventsServiceProvider::class, 'register');
    $attrs = $method->getAttributes(\Override::class);

    expect($attrs)->toHaveCount(1, 'EventsServiceProvider::register() must have #[Override]');
});

test('EventsServiceProvider::boot() has Override attribute', function (): void {
    $method = new ReflectionMethod(EventsServiceProvider::class, 'boot');
    $attrs = $method->getAttributes(\Override::class);

    expect($attrs)->toHaveCount(1, 'EventsServiceProvider::boot() must have #[Override]');
});

// ─── Phase 76: WebhookAction implements Triggerable ────────────────────

test('WebhookAction implements Triggerable', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class);

    expect($ref->isFinal())->toBeTrue('WebhookAction must be final')
        ->and($ref->implementsInterface(\ZeroBoiler\Events\Contracts\Triggerable::class))->toBeTrue();
});

test('WebhookAction::handle has Override attribute', function (): void {
    $method = new ReflectionMethod(\ZeroBoiler\Events\Actions\WebhookAction::class, 'handle');
    $attrs = $method->getAttributes(\Override::class);

    expect($attrs)->toHaveCount(1);
});

// ─── Phase 76: DispatchTriggerJob implements ShouldQueue ──────────────

test('DispatchTriggerJob implements ShouldQueue', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class);

    expect($ref->isFinal())->toBeTrue()
        ->and($ref->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class))->toBeTrue();
});

// ─── Phase 76: ConditionEngine is readonly final ──────────────────────

test('ConditionEngine is readonly final class', function (): void {
    $ref = new ReflectionClass(ConditionEngine::class);

    expect($ref->isFinal())->toBeTrue()
        ->and($ref->isReadOnly())->toBeTrue();
});

// ─── Phase 76: WildcardMatcher is readonly final ──────────────────────

test('WildcardMatcher is readonly final class', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);

    expect($ref->isFinal())->toBeTrue()
        ->and($ref->isReadOnly())->toBeTrue();
});

// ─── Phase 76: DomainEvent is final ──────────────────────────────────

test('DomainEvent is final with readonly properties', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);

    expect($ref->isFinal())->toBeTrue();

    $props = $ref->getProperties();
    foreach ($props as $prop) {
        expect($prop->isReadOnly())->toBeTrue("DomainEvent::\${$prop->name} must be readonly");
    }
});

// ─── Phase 76: Trigger model table name fallback ──────────────────────

test('Trigger model falls back to triggers when config is not string', function (): void {
    config(['events.table_names.triggers' => 12345]);

    $trigger = new Trigger;
    expect($trigger->getTable())->toBe('triggers');
});

test('EventLog model falls back to event_logs when config is not string', function (): void {
    config(['events.table_names.event_logs' => null]);

    $log = new EventLog;
    expect($log->getTable())->toBe('event_logs');
});

test('Subscription model falls back to event_subscriptions when config is not string', function (): void {
    config(['events.table_names.subscriptions' => true]);

    $sub = new Subscription;
    expect($sub->getTable())->toBe('event_subscriptions');
});

// ─── Phase 76: phpstan.neon.dist has baselineFile ────────────────────

test('phpstan.neon.dist includes baselineFile', function (): void {
    $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect($contents)->toContain('baselineFile: phpstan-baseline.neon');
});

test('phpstan.neon.dist includes checkGenericClassInNonGenericObjectType', function (): void {
    $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect($contents)->toContain('checkGenericClassInNonGenericObjectType: true');
});

// ─── Phase 76: Config file completeness ────────────────────────────────

test('config events.disabled is boolean default', function (): void {
    $disabled = config('events.disabled');

    expect($disabled)->toBeFalse();
});

test('config events.wildcard_cache_ttl is positive int default', function (): void {
    $ttl = config('events.wildcard_cache_ttl');

    expect($ttl)->toBeInt()->toBeGreaterThan(0);
});

test('config events.queue has connection and queue keys', function (): void {
    $queue = config('events.queue');

    expect($queue)->toBeArray()
        ->and($queue)->toHaveKeys(['connection', 'queue']);
});

test('config events.subscriptions.auto_generate_secret is boolean', function (): void {
    $auto = config('events.subscriptions.auto_generate_secret');

    expect($auto)->toBeBool();
});

// ─── Phase 76: Trigger model fillable and hidden ─────────────────────

test('Trigger model has correct fillable attributes', function (): void {
    $fillable = (new Trigger)->getFillable();

    expect($fillable)->toContain('id', 'name', 'event', 'action', 'conditions', 'async', 'priority', 'enabled');
});

test('Trigger model hides deleted_at', function (): void {
    $hidden = (new Trigger)->getHidden();

    expect($hidden)->toContain('deleted_at');
});

// ─── Phase 76: EventLog model status scope ──────────────────────────

test('EventLog scopeWithStatus filters correctly', function (): void {
    EventLog::factory()->create(['status' => 'completed']);
    EventLog::factory()->create(['status' => 'failed']);

    $completed = EventLog::withStatus('completed')->get();
    expect($completed)->toHaveCount(1);
    expect($completed->first()->status)->toBe('completed');
});

test('EventLog scopeStalePending returns logs before threshold', function (): void {
    $stale = EventLog::factory()->create([
        'status' => 'pending',
        'created_at' => now()->subDays(7),
    ]);
    EventLog::factory()->create([
        'status' => 'pending',
        'created_at' => now()->subMinutes(5),
    ]);

    $results = EventLog::stalePending(now()->subHours(1))->get();
    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($stale->id);
});

// ─── Phase 76: Subscription matchesEvent ──────────────────────────────

test('Subscription matchesEvent with wildcard pattern', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.*']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.shipped'))->toBeTrue();
    expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
});

test('Subscription matchesEvent with cross-segment wildcard', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.**']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.placed.extra'))->toBeTrue();
});

test('Subscription matchesEvent with exact pattern', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.placed']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.shipped'))->toBeFalse();
});

// ─── Phase 76: Test file count accuracy ──────────────────────────────

test('test file count is accurate (Phase 76)', function (): void {
    $pestFiles = glob(__DIR__.'/*.php');
    $supportFiles = ['Pest.php', 'TestCase.php', 'helpers.php', 'TestActions.php', 'CreatesApplication.php'];
    $testFiles = array_values(array_filter($pestFiles, function (string $f) use ($supportFiles): bool {
        return ! in_array(basename($f), $supportFiles, true);
    }));

    expect($testFiles)->toHaveCount(151);
});
