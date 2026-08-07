<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

// ─── EventLog scope methods ────────────────────────────────────────────────

test('EventLog::scopeWithStatus filters by exact status', function (): void {
    $completed = EventLog::factory()->completed()->create();
    $failed = EventLog::factory()->failed()->create();

    $results = EventLog::withStatus(EventLog::STATUS_COMPLETED)->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($completed->id);
});

test('EventLog::scopeFailed returns only failed logs', function (): void {
    $failed = EventLog::factory()->failed()->create();
    EventLog::factory()->completed()->create();
    EventLog::factory()->pending()->create();

    $results = EventLog::failed()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($failed->id);
});

test('EventLog::scopePending returns only pending logs', function (): void {
    $pending = EventLog::factory()->pending()->create();
    EventLog::factory()->completed()->create();
    EventLog::factory()->failed()->create();

    $results = EventLog::pending()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($pending->id);
});

test('EventLog::scopeCompleted returns only completed logs', function (): void {
    $completed = EventLog::factory()->completed()->create();
    EventLog::factory()->failed()->create();
    EventLog::factory()->pending()->create();

    $results = EventLog::completed()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($completed->id);
});

test('EventLog::scopeWithStatus returns empty for non-existent status', function (): void {
    EventLog::factory()->completed()->create();

    $results = EventLog::withStatus('non_existent_status')->get();

    expect($results)->toHaveCount(0);
});

// ─── EventLog markAsCompleted / markAsFailed ────────────────────────────────

test('EventLog::markAsCompleted updates status and duration', function (): void {
    $log = EventLog::factory()->pending()->create();

    $log->markAsCompleted(250);

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    expect($log->duration_ms)->toBe(250);
});

test('EventLog::markAsFailed updates status and error message', function (): void {
    $log = EventLog::factory()->pending()->create();

    $log->markAsFailed('Connection timeout');

    $log->refresh();
    expect($log->status)->toBe(EventLog::STATUS_FAILED);
    expect($log->error)->toBe('Connection timeout');
});

// ─── Trigger scopes ─────────────────────────────────────────────────────────

test('Trigger::scopeEnabled returns only enabled triggers', function (): void {
    $enabled = Trigger::factory()->enabled()->create();
    Trigger::factory()->disabled()->create();

    $results = Trigger::enabled()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($enabled->id);
});

test('Trigger::scopeAsync returns only async triggers', function (): void {
    $async = Trigger::factory()->async()->create();
    Trigger::factory()->sync()->create();

    $results = Trigger::async()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($async->id);
});

test('Trigger::scopeOrderByPriority orders by priority descending', function (): void {
    $low = Trigger::factory()->enabled()->create(['priority' => 1]);
    $high = Trigger::factory()->enabled()->create(['priority' => 100]);

    $results = Trigger::enabled()->orderByPriority()->get();

    expect($results->first()->id)->toBe($high->id);
    expect($results->last()->id)->toBe($low->id);
});

// ─── Trigger relation: eventLogs ─────────────────────────────────────────────

test('Trigger::eventLogs returns related event logs', function (): void {
    $trigger = Trigger::factory()->create();
    $log1 = EventLog::factory()->create(['trigger_id' => $trigger->id]);
    $log2 = EventLog::factory()->create(['trigger_id' => $trigger->id]);

    $logs = $trigger->eventLogs;

    expect($logs)->toHaveCount(2);
    expect($logs->pluck('id')->toArray())->toContain($log1->id);
    expect($logs->pluck('id')->toArray())->toContain($log2->id);
});

test('Trigger::eventLogs returns empty for trigger with no logs', function (): void {
    $trigger = Trigger::factory()->create();

    expect($trigger->eventLogs)->toHaveCount(0);
});

// ─── EventLog relation: trigger ────────────────────────────────────────────

test('EventLog::trigger returns owning trigger', function (): void {
    $trigger = Trigger::factory()->create();
    $log = EventLog::factory()->create(['trigger_id' => $trigger->id]);

    expect($log->trigger->id)->toBe($trigger->id);
});

// ─── Subscription::scopeActive ──────────────────────────────────────────────

test('Subscription::scopeActive returns only active subscriptions', function (): void {
    $active = Subscription::factory()->active()->create();
    Subscription::factory()->inactive()->create();

    $results = Subscription::active()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($active->id);
});

test('Subscription::scopeOrderByPriority orders by priority descending', function (): void {
    $low = Subscription::factory()->create(['priority' => 1]);
    $high = Subscription::factory()->create(['priority' => 50]);

    $results = Subscription::orderByPriority()->get();

    expect($results->first()->id)->toBe($high->id);
    expect($results->last()->id)->toBe($low->id);
});

// ─── Subscription::matchesEvent ────────────────────────────────────────────

test('Subscription::matchesEvent exact match', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.placed']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.shipped'))->toBeFalse();
});

test('Subscription::matchesEvent single wildcard', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.*']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.shipped'))->toBeTrue();
    expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
});

test('Subscription::matchesEvent cross-segment wildcard', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.**']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.placed.extra'))->toBeTrue();
    expect($sub->matchesEvent('payment.received'))->toBeFalse();
});

// ─── #[\Override] attribute verification ────────────────────────────────────

test('ConditionEngine::matches has #[Override] attribute', function (): void {
    $method = new ReflectionMethod(\ZeroBoiler\Events\ConditionEngine::class, 'matches');

    $hasOverride = array_any(
        $method->getAttributes(),
        fn (ReflectionAttribute $attr): bool => $attr->getName() === 'Override',
    );

    expect($hasOverride)->toBeTrue('ConditionEngine::matches must have #[Override] attribute');
});

test('WebhookAction::handle has #[Override] attribute', function (): void {
    $method = new ReflectionMethod(\ZeroBoiler\Events\Actions\WebhookAction::class, 'handle');

    $hasOverride = array_any(
        $method->getAttributes(),
        fn (ReflectionAttribute $attr): bool => $attr->getName() === 'Override',
    );

    expect($hasOverride)->toBeTrue('WebhookAction::handle must have #[Override] attribute');
});

// ─── DomainEvent readonly uses isReadOnly() not attribute ──────────────────

test('DomainEvent properties use readonly keyword modifier (PHP 8.5)', function (): void {
    $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.verify', []);
    $reflection = new ReflectionClass($event);

    $readonlyProps = ['eventType', 'payload', 'eventId', 'occurredAt'];
    foreach ($readonlyProps as $prop) {
        $property = $reflection->getProperty($prop);
        // PHP 8.5: readonly keyword sets isReadOnly() flag, NOT a #[\Readonly] attribute
        expect($property->isReadOnly())->toBeTrue("Property \${$prop} must have readonly modifier");

        $hasReadonlyAttr = array_any(
            $property->getAttributes(),
            fn (ReflectionAttribute $attr): bool => $attr->getName() === 'Readonly',
        );
        expect($hasReadonlyAttr)->toBeFalse("Property \${$prop} must NOT have #[\\Readonly] attribute");
    }
});
