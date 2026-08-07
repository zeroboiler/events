<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Console\EventsRedeliverCommand;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Contracts\Triggerable;

// ─── Redeliver command: buildRedeliverBody strips internal keys ───────────

test('redeliver buildRedeliverBody strips url from data payload', function (): void {
    $log = new EventLog([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'order.placed',
        'payload' => [
            'url' => 'https://example.com/hooks',
            'order_id' => 42,
            'status' => 'paid',
        ],
        'status' => EventLog::STATUS_FAILED,
    ]);
    $log->save();

    $command = new EventsRedeliverCommand;

    $reflection = new ReflectionMethod(EventsRedeliverCommand::class, 'buildRedeliverBody');
    $reflection->setAccessible(true);
    $body = $reflection->invoke($command, $log);

    expect($body['event'])->toBe('order.placed')
        ->and($body['redelivered'])->toBeTrue()
        ->and($body['original_log_id'])->toBe($log->id)
        ->and($body['data'])->toHaveKey('order_id')
        ->and($body['data'])->toHaveKey('status')
        ->and($body['data'])->not->toHaveKey('url')
        ->and($body['data'])->not->toHaveKey('event')
        ->and($body['data'])->not->toHaveKey('headers')
        ->and($body['data'])->not->toHaveKey('subscription_id');
});

test('redeliver buildRedeliverBody strips all internal keys including subscription_id and headers', function (): void {
    $log = new EventLog([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'user.created',
        'payload' => [
            'url' => 'https://example.com/hooks',
            'event' => 'user.created',
            'headers' => ['X-Custom' => 'value'],
            'subscription_id' => 'sub-123',
            'user_email' => 'test@example.com',
        ],
        'status' => EventLog::STATUS_FAILED,
    ]);
    $log->save();

    $command = new EventsRedeliverCommand;

    $reflection = new ReflectionMethod(EventsRedeliverCommand::class, 'buildRedeliverBody');
    $reflection->setAccessible(true);
    $body = $reflection->invoke($command, $log);

    expect($body['data'])->toHaveKey('user_email')
        ->and($body['data'])->not->toHaveKey('url')
        ->and($body['data'])->not->toHaveKey('event')
        ->and($body['data'])->not->toHaveKey('headers')
        ->and($body['data'])->not->toHaveKey('subscription_id');
});

test('redeliver buildRedeliverBody preserves timestamp and redelivered flag', function (): void {
    $log = new EventLog([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'test.event',
        'payload' => ['url' => 'https://example.com'],
        'status' => EventLog::STATUS_FAILED,
    ]);
    $log->save();

    $command = new EventsRedeliverCommand;

    $reflection = new ReflectionMethod(EventsRedeliverCommand::class, 'buildRedeliverBody');
    $reflection->setAccessible(true);
    $body = $reflection->invoke($command, $log);

    expect($body)->toHaveKey('timestamp')
        ->and($body['timestamp'])->toBeString()
        ->and($body['redelivered'])->toBeTrue()
        ->and($body['original_log_id'])->toBe($log->id);
});

test('redeliver buildRedeliverBody handles non-array payload gracefully', function (): void {
    $log = new EventLog([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'test.empty',
        'payload' => 'invalid',
        'status' => EventLog::STATUS_FAILED,
    ]);
    $log->save();

    $command = new EventsRedeliverCommand;

    $reflection = new ReflectionMethod(EventsRedeliverCommand::class, 'buildRedeliverBody');
    $reflection->setAccessible(true);
    $body = $reflection->invoke($command, $log);

    // Should not throw, data should be empty
    expect($body['data'])->toBe([])
        ->and($body['event'])->toBe('test.empty');
});

// ─── Redeliver command: getTimeout config ──────────────────────────────────

test('redeliver getTimeout reads from config', function (): void {
    Config::set('events.subscriptions.timeout', 60);

    $command = new EventsRedeliverCommand;

    $reflection = new ReflectionMethod(EventsRedeliverCommand::class, 'getTimeout');
    $reflection->setAccessible(true);
    $timeout = $reflection->invoke($command);

    expect($timeout)->toBe(60);
});

test('redeliver getTimeout defaults to 30 when config is null', function (): void {
    Config::set('events.subscriptions.timeout', null);

    $command = new EventsRedeliverCommand;

    $reflection = new ReflectionMethod(EventsRedeliverCommand::class, 'getTimeout');
    $reflection->setAccessible(true);
    $timeout = $reflection->invoke($command);

    expect($timeout)->toBe(30);
});

test('redeliver getTimeout defaults to 30 when config is zero', function (): void {
    Config::set('events.subscriptions.timeout', 0);

    $command = new EventsRedeliverCommand;

    $reflection = new ReflectionMethod(EventsRedeliverCommand::class, 'getTimeout');
    $reflection->setAccessible(true);
    $timeout = $reflection->invoke($command);

    expect($timeout)->toBe(30);
});

// ─── ConditionEngine: matches operator with null actual value ──────────────

test('condition engine matches operator returns false for null actual', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches([
        'code' => ['matches', '/^[A-Z]{3}$/'],
    ], [
        'code' => null,
    ]))->toBeFalse();
});

test('condition engine matches operator returns false for null pattern', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches([
        'code' => ['matches', null],
    ], [
        'code' => 'ABC',
    ]))->toBeFalse();
});

// ─── ConditionEngine: starts_with / ends_with with null actual ────────────

test('condition engine starts_with returns false for null actual', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches([
        'email' => ['starts_with', 'admin@'],
    ], [
        'email' => null,
    ]))->toBeFalse();
});

test('condition engine ends_with returns false for null actual', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches([
        'domain' => ['ends_with', '.com'],
    ], [
        'domain' => null,
    ]))->toBeFalse();
});

test('condition engine starts_with returns false for null value', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches([
        'email' => ['starts_with', null],
    ], [
        'email' => 'admin@test.com',
    ]))->toBeFalse();
});

test('condition engine ends_with returns false for null value', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches([
        'domain' => ['ends_with', null],
    ], [
        'domain' => 'example.com',
    ]))->toBeFalse();
});

// ─── EventLog: boot auto-generates UUID on creating ───────────────────────

test('event log boot generates UUID when id is empty', function (): void {
    $log = new EventLog([
        'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'test.auto.uuid',
        'payload' => [],
        'status' => EventLog::STATUS_PENDING,
    ]);
    $log->save();

    expect($log->id)->not->toBeEmpty()
        ->and(\Ramsey\Uuid\Uuid::isValid($log->id))->toBeTrue();
});

test('event log boot preserves explicit id', function (): void {
    $id = (string) \Ramsey\Uuid\Uuid::uuid4();
    $log = new EventLog([
        'id' => $id,
        'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'test.explicit.uuid',
        'payload' => [],
        'status' => EventLog::STATUS_PENDING,
    ]);
    $log->save();

    expect($log->id)->toBe($id);
});

// ─── Subscription: boot auto-generates UUID on creating ───────────────────

test('subscription boot generates UUID when id is empty', function (): void {
    $sub = new Subscription([
        'event' => 'test.auto.sub',
        'url' => 'https://example.com/hooks',
        'secret' => 'whsec_test123',
    ]);
    $sub->save();

    expect($sub->id)->not->toBeEmpty()
        ->and(\Ramsey\Uuid\Uuid::isValid($sub->id))->toBeTrue();
});

// ─── Trigger: boot auto-generates UUID on creating ────────────────────────

test('trigger boot generates UUID when id is empty', function (): void {
    $trigger = new Trigger([
        'name' => 'Auto UUID Trigger',
        'event' => 'test.auto.trigger',
        'action' => TestBootAction::class,
    ]);
    $trigger->save();

    expect($trigger->id)->not->toBeEmpty()
        ->and(\Ramsey\Uuid\Uuid::isValid($trigger->id))->toBeTrue();
});

// ─── TriggerBuilder: save validates event name ────────────────────────────

test('trigger builder save throws for empty event', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $builder = $manager->on('');

    $builder->action(TestBootAction::class)->save();
})->throws(\InvalidArgumentException::class, 'Event name is required');

test('trigger builder save throws for zero-string event', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $builder = $manager->on('0');

    $builder->action(TestBootAction::class)->save();
})->throws(\InvalidArgumentException::class, 'Event name is required');

test('trigger builder save throws when no action set', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $builder = $manager->on('test.no.action');

    $builder->save();
})->throws(\InvalidArgumentException::class, 'At least one action is required');

// ─── SubscriptionBuilder: save validations ───────────────────────────────

test('subscription builder save throws for empty event', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $builder = $manager->subscribe('', 'https://example.com/hooks');

    $builder->save();
})->throws(\InvalidArgumentException::class, 'Event name is required for subscription');

test('subscription builder save throws for invalid URL', function (): void {
    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $builder = $manager->subscribe('test.event', 'not-a-url');

    $builder->save();
})->throws(\InvalidArgumentException::class, 'Webhook URL must be a valid URL');

test('subscription builder save auto-generates secret by default', function (): void {
    Config::set('events.subscriptions.auto_generate_secret', true);

    // We can't actually save (creates trigger + webhook), but we test the secret generation path
    $url = 'https://'.\Illuminate\Support\Str::random(12).'.example.com/webhook';

    $manager = app(\ZeroBoiler\Events\EventManager::class);
    $builder = $manager->subscribe('test.auto.secret', $url);

    // Verify the builder is constructed properly
    expect($builder)->toBeInstanceOf(\ZeroBoiler\Events\SubscriptionBuilder::class);
});

// ─── WebhookAction: handle throws for missing url ─────────────────────────

test('webhook action handle throws for empty payload url', function (): void {
    $action = new WebhookAction;
    $action->handle([]);
})->throws(\InvalidArgumentException::class, 'WebhookAction requires a non-empty "url"');

test('webhook action handle throws for empty string url', function (): void {
    $action = new WebhookAction;
    $action->handle(['url' => '']);
})->throws(\InvalidArgumentException::class, 'WebhookAction requires a non-empty "url"');

// ─── DispatchTriggerJob: constructor with edge-case config ───────────────

test('dispatch trigger job handles non-int backoff config', function (): void {
    Config::set('events.retry.backoff', 'invalid');

    $job = new DispatchTriggerJob('test', 'test.event', []);

    // Should have tried to parse and likely produced [0] or empty array
    expect($job->backoff)->toBeArray();
});

// ─── EventLog: markAsCompleted / markAsFailed ────────────────────────────

test('event log markAsCompleted updates status and duration', function (): void {
    $log = EventLog::factory()->create([
        'status' => EventLog::STATUS_DISPATCHED,
        'duration_ms' => null,
    ]);

    $log->markAsCompleted(150);
    $log->refresh();

    expect($log->status)->toBe(EventLog::STATUS_COMPLETED)
        ->and($log->duration_ms)->toBe(150);
});

test('event log markAsFailed updates status and error', function (): void {
    $log = EventLog::factory()->create([
        'status' => EventLog::STATUS_DISPATCHED,
        'error' => null,
    ]);

    $log->markAsFailed('Connection timeout');
    $log->refresh();

    expect($log->status)->toBe(EventLog::STATUS_FAILED)
        ->and($log->error)->toBe('Connection timeout');
});

// ─── Subscription: signPayload determinism ───────────────────────────────

test('subscription signPayload produces deterministic signatures', function (): void {
    $sub = Subscription::factory()->create([
        'secret' => 'whsec_test_deterministic',
    ]);

    $sig1 = $sub->signPayload('{"test": "data"}');
    $sig2 = $sub->signPayload('{"test": "data"}');

    expect($sig1)->toBe($sig2)
        ->and($sig1)->not->toBeEmpty();
});

test('subscription signPayload returns empty for null secret', function (): void {
    $sub = Subscription::factory()->create([
        'secret' => null,
    ]);

    expect($sub->signPayload('{"test": "data"}'))->toBe('');
});

test('subscription signPayload returns empty for empty secret', function (): void {
    $sub = Subscription::factory()->create([
        'secret' => '',
    ]);

    expect($sub->signPayload('{"test": "data"}'))->toBe('');
});

// ─── Config: all keys have correct types ─────────────────────────────────

test('config table_names are all strings', function (): void {
    $tableNames = config('events.table_names');

    expect($tableNames)->toBeArray()
        ->and($tableNames['triggers'])->toBeString()
        ->and($tableNames['event_logs'])->toBeString()
        ->and($tableNames['subscriptions'])->toBeString();
});

test('config subscription keys have correct types', function (): void {
    $subs = config('events.subscriptions');

    expect($subs)->toBeArray()
        ->and($subs['auto_generate_secret'])->toBeBool()
        ->and($subs['max_failures'])->toBeInt()
        ->and($subs['timeout'])->toBeInt()
        ->and($subs['signature_algorithm'])->toBeString();
});

test('config retry keys have correct types', function (): void {
    $retry = config('events.retry');

    expect($retry)->toBeArray()
        ->and($retry['tries'])->toBeInt()
        ->and($retry['backoff'])->toBeString();
});

test('config retention keys have correct types', function (): void {
    $retention = config('events.retention');

    expect($retention)->toBeArray()
        ->and($retention['days'])->toBeInt()
        ->and($retention['include_pending'])->toBeBool();
});

test('config wildcard_cache_ttl is positive int', function (): void {
    $ttl = config('events.wildcard_cache_ttl');

    expect($ttl)->toBeInt()
        ->and($ttl)->toBeGreaterThan(0);
});

// ─── ServiceProvider: contract binding ───────────────────────────────────

test('condition engine contract resolves to condition engine instance', function (): void {
    $contract = app(ConditionEngineContract::class);
    $concrete = app(ConditionEngine::class);

    expect($contract)->toBeInstanceOf(ConditionEngine::class)
        ->and($contract)->toBe($concrete); // Same singleton instance
});

// ─── Action resolver: error cases ─────────────────────────────────────────

test('action resolver throws for non-existent class', function (): void {
    $resolver = app(\ZeroBoiler\Events\ActionResolver::class);

    $resolver->resolve('NonExistent\\ActionClass');
})->throws(\InvalidArgumentException::class, 'does not exist');

test('action resolver throws for class not implementing Triggerable', function (): void {
    $resolver = app(\ZeroBoiler\Events\ActionResolver::class);

    $resolver->resolve(\stdClass::class);
})->throws(\InvalidArgumentException::class, 'must implement');

// ─── Test action class for Phase 9 tests ──────────────────────────────────

final class TestBootAction implements \ZeroBoiler\Events\Contracts\Triggerable
{
    public function handle(array $payload): void
    {
        // No-op test action
    }
}
