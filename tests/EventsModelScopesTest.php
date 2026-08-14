<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Str;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

// ─── Trigger Scopes ─────────────────────────────────────────────────────────

describe('Trigger::scopeEnabled', function (): void {
    it('returns only enabled triggers', function (): void {
        Trigger::factory()->enabled()->createMany(3);
        Trigger::factory()->disabled()->createMany(2);

        $result = Trigger::enabled()->get();

        expect($result)->toHaveCount(3);
        expect($result->every(fn (Trigger $t): bool => $t->enabled === true))->toBeTrue();
    });
});

describe('Trigger::scopeAsync', function (): void {
    it('returns only async triggers', function (): void {
        Trigger::factory()->async()->createMany(2);
        Trigger::factory()->sync()->createMany(3);

        $result = Trigger::async()->get();

        expect($result)->toHaveCount(2);
        expect($result->every(fn (Trigger $t): bool => $t->async === true))->toBeTrue();
    });
});

describe('Trigger::scopeOrderByPriority', function (): void {
    it('orders by priority descending', function (): void {
        Trigger::factory()->create(['priority' => 5, 'event' => 'a']);
        Trigger::factory()->create(['priority' => 10, 'event' => 'b']);
        Trigger::factory()->create(['priority' => 1, 'event' => 'c']);

        $result = Trigger::orderByPriority()->get();

        expect($result->pluck('priority')->toArray())->toBe([10, 5, 1]);
    });
});

// ─── EventLog Scopes ─────────────────────────────────────────────────────────

describe('EventLog::scopeWithStatus', function (): void {
    it('filters by exact status', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger->id)->completed()->createMany(3);
        EventLog::factory()->forTrigger($trigger->id)->failed()->createMany(2);

        $completed = EventLog::withStatus(EventLog::STATUS_COMPLETED)->get();
        $failed = EventLog::withStatus(EventLog::STATUS_FAILED)->get();

        expect($completed)->toHaveCount(3);
        expect($failed)->toHaveCount(2);
    });
});

describe('EventLog::scopeFailed', function (): void {
    it('filters failed logs', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger->id)->completed()->create();
        EventLog::factory()->forTrigger($trigger->id)->failed()->createMany(2);

        $result = EventLog::failed()->get();

        expect($result)->toHaveCount(2);
    });
});

describe('EventLog::scopePending', function (): void {
    it('filters pending logs', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger->id)->completed()->create();
        EventLog::factory()->forTrigger($trigger->id)->pending()->createMany(3);

        $result = EventLog::pending()->get();

        expect($result)->toHaveCount(3);
    });
});

describe('EventLog::scopeCompleted', function (): void {
    it('filters completed logs', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        EventLog::factory()->forTrigger($trigger->id)->failed()->create();
        EventLog::factory()->forTrigger($trigger->id)->completed()->createMany(4);

        $result = EventLog::completed()->get();

        expect($result)->toHaveCount(4);
    });
});

describe('EventLog::scopeStalePending', function (): void {
    it('filters pending logs older than threshold', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        $oldLog = EventLog::factory()->forTrigger($trigger->id)->pending()->create();
        $oldLog->created_at = now()->subHours(2);
        $oldLog->save();

        $recentLog = EventLog::factory()->forTrigger($trigger->id)->pending()->create();

        $stale = EventLog::stalePending(now()->subHour())->get();

        expect($stale)->toHaveCount(1);
        expect($stale->first()->id)->toBe($oldLog->id);
    });

    it('does not include non-pending logs', function (): void {
        $trigger = Trigger::factory()->enabled()->create();

        $oldCompleted = EventLog::factory()->forTrigger($trigger->id)->completed()->create();
        $oldCompleted->created_at = now()->subHours(5);
        $oldCompleted->save();

        $stale = EventLog::stalePending(now()->subHour())->get();

        expect($stale)->toHaveCount(0);
    });
});

// ─── Subscription Scopes ────────────────────────────────────────────────────

describe('Subscription::scopeActive', function (): void {
    it('returns only active subscriptions', function (): void {
        Subscription::factory()->active()->createMany(3);
        Subscription::factory()->inactive()->createMany(2);

        $result = Subscription::active()->get();

        expect($result)->toHaveCount(3);
        expect($result->every(fn (Subscription $s): bool => $s->active === true))->toBeTrue();
    });
});

describe('Subscription::scopeOrderByPriority', function (): void {
    it('orders by priority descending', function (): void {
        Subscription::factory()->create(['priority' => 5, 'event' => 'a']);
        Subscription::factory()->create(['priority' => 20, 'event' => 'b']);
        Subscription::factory()->create(['priority' => 10, 'event' => 'c']);

        $result = Subscription::orderByPriority()->get();

        expect($result->pluck('priority')->toArray())->toBe([20, 10, 5]);
    });
});

describe('Subscription::scopeExceededFailures', function (): void {
    it('returns subscriptions with failure_count >= max_failures config', function (): void {
        Subscription::factory()->withFailureCount(15)->create();
        Subscription::factory()->withFailureCount(5)->create();
        Subscription::factory()->withFailureCount(10)->create();

        $result = Subscription::exceededFailures()->get();

        expect($result)->toHaveCount(2);
    });
});

describe('Subscription::scopeForEvent', function (): void {
    it('filters by exact event name', function (): void {
        Subscription::factory()->forEvent('order.placed')->create();
        Subscription::factory()->forEvent('order.shipped')->create();

        $result = Subscription::forEvent('order.placed')->get();

        expect($result)->toHaveCount(1);
        expect($result->first()->event)->toBe('order.placed');
    });

    it('supports wildcard event filtering via LIKE', function (): void {
        Subscription::factory()->forEvent('order.placed')->create();
        Subscription::factory()->forEvent('order.shipped')->create();
        Subscription::factory()->forEvent('payment.received')->create();

        $result = Subscription::forEvent('order.*')->get();

        expect($result)->toHaveCount(2);
    });
});

// ─── Subscription Instance Methods ──────────────────────────────────────────

describe('Subscription::recordDelivery', function (): void {
    it('increments delivery_count and sets last_fired_at', function (): void {
        $sub = Subscription::factory()->create([
            'delivery_count' => 3,
            'last_fired_at' => null,
        ]);

        $sub->recordDelivery();
        $sub->refresh();

        expect($sub->delivery_count)->toBe(4);
        expect($sub->last_fired_at)->not->toBeNull();
    });
});

describe('Subscription::recordFailure', function (): void {
    it('increments failure_count', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 2]);

        $sub->recordFailure();
        $sub->refresh();

        expect($sub->failure_count)->toBe(3);
    });
});

describe('Subscription::resetFailures', function (): void {
    it('resets failure_count to zero', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 7]);

        $sub->resetFailures();
        $sub->refresh();

        expect($sub->failure_count)->toBe(0);
    });
});

describe('Subscription::hasExceededFailures', function (): void {
    it('returns true when failure_count exceeds threshold', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 15]);

        expect($sub->hasExceededFailures(10))->toBeTrue();
    });

    it('returns false when failure_count is below threshold', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 5]);

        expect($sub->hasExceededFailures(10))->toBeFalse();
    });

    it('uses config when max is null', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 12]);

        expect($sub->hasExceededFailures(null))->toBeTrue();
    });

    it('returns true when exactly at threshold', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 10]);

        expect($sub->hasExceededFailures(10))->toBeTrue();
    });
});

describe('Subscription::matchesEvent', function (): void {
    it('matches exact event name', function (): void {
        $sub = Subscription::factory()->forEvent('order.placed')->create();

        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.shipped'))->toBeFalse();
    });

    it('matches single-segment wildcard', function (): void {
        $sub = Subscription::factory()->forEvent('order.*')->create();

        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.shipped'))->toBeTrue();
        expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
    });

    it('matches cross-segment wildcard', function (): void {
        $sub = Subscription::factory()->forEvent('order.**')->create();

        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.placed.extra'))->toBeTrue();
    });
});

describe('Subscription::signPayload', function (): void {
    it('returns HMAC-SHA256 signature', function (): void {
        $sub = Subscription::factory()->withSecret('whsec_testsecret123')->create();

        $signature = $sub->signPayload('{"test": "data"}');

        expect($signature)->not->toBeEmpty();
        expect(strlen($signature))->toBe(64); // SHA256 hex output length
    });

    it('returns empty string when secret is null', function (): void {
        $sub = Subscription::factory()->withoutSecret()->create();

        $signature = $sub->signPayload('{"test": "data"}');

        expect($signature)->toBe('');
    });

    it('returns empty string when secret is empty', function (): void {
        $sub = Subscription::factory()->create(['secret' => '']);

        $signature = $sub->signPayload('{"test": "data"}');

        expect($signature)->toBe('');
    });

    it('produces consistent signature for same input', function (): void {
        $sub = Subscription::factory()->withSecret('whsec_consistent')->create();

        $sig1 = $sub->signPayload('hello');
        $sig2 = $sub->signPayload('hello');

        expect($sig1)->toBe($sig2);
    });
});

// ─── EventLog Instance Methods ──────────────────────────────────────────────

describe('EventLog::markAsCompleted', function (): void {
    it('sets status to completed and duration', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        $log = EventLog::factory()->forTrigger($trigger->id)->pending()->create();

        $log->markAsCompleted(150);
        $log->refresh();

        expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
        expect($log->duration_ms)->toBe(150);
    });
});

describe('EventLog::markAsFailed', function (): void {
    it('sets status to failed and error message', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        $log = EventLog::factory()->forTrigger($trigger->id)->pending()->create();

        $log->markAsFailed('Connection timeout');
        $log->refresh();

        expect($log->status)->toBe(EventLog::STATUS_FAILED);
        expect($log->error)->toBe('Connection timeout');
    });
});

// ─── Trigger Relationships ─────────────────────────────────────────────────

describe('Trigger::eventLogs relationship', function (): void {
    it('returns related event logs', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        EventLog::factory()->forTrigger($trigger->id)->completed()->createMany(3);

        $trigger->refresh();
        $logs = $trigger->eventLogs;

        expect($logs)->toHaveCount(3);
    });
});

describe('EventLog::trigger relationship', function (): void {
    it('returns the parent trigger', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        $log = EventLog::factory()->forTrigger($trigger->id)->completed()->create();

        expect($log->trigger->id)->toBe($trigger->id);
    });
});

// ─── Trigger Model ─────────────────────────────────────────────────────────

describe('Trigger model config-driven table', function (): void {
    it('uses config table name', function (): void {
        $trigger = new Trigger;

        expect($trigger->getTable())->toBe('triggers');
    });

    it('uses string key type', function (): void {
        $trigger = new Trigger;

        expect($trigger->getKeyType())->toBe('string');
        expect($trigger->incrementing)->toBeFalse();
    });
});

describe('EventLog model config-driven table', function (): void {
    it('uses config table name', function (): void {
        $log = new EventLog;

        expect($log->getTable())->toBe('event_logs');
    });
});

describe('Subscription model config-driven table', function (): void {
    it('uses config table name', function (): void {
        $sub = new Subscription;

        expect($sub->getTable())->toBe('event_subscriptions');
    });
});
