<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;

beforeEach(function (): void {
    // Reset config to defaults for each test
    config([
        'events.retry.tries' => 3,
        'events.retry.backoff' => '60,300,900',
        'events.queue.queue' => 'default',
        'events.queue.connection' => null,
        'events.subscriptions.timeout' => 30,
        'events.subscriptions.max_failures' => 10,
        'events.subscriptions.signature_algorithm' => 'sha256',
        'events.wildcard_cache_ttl' => 300,
    ]);
});

// ─── EventManager::fire() empty event validation ───────────────────────────

it('throws InvalidArgumentException when fire() is called with empty string', function (): void {
    $this->app->get(\ZeroBoiler\Events\EventManager::class)->fire('');
})->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

it('throws InvalidArgumentException when fire() is called with zero string', function (): void {
    $this->app->get(\ZeroBoiler\Events\EventManager::class)->fire('0');
})->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

it('fires normally with non-empty event name', function (): void {
    // Should not throw — no matching triggers means nothing happens
    $this->app->get(\ZeroBoiler\Events\EventManager::class)->fire('test.event', ['key' => 'value']);
    expect(true)->toBeTrue();
});

// ─── EventManager::fireModel() empty parameter validation ──────────────────

it('throws InvalidArgumentException when fireModel() is called with empty model class', function (): void {
    $model = new class {
        public function attributesToArray(): array
        {
            return ['id' => 1];
        }
    };

    $this->app->get(\ZeroBoiler\Events\EventManager::class)->fireModel('', 'created', $model);
})->throws(\InvalidArgumentException::class, 'Model class name cannot be empty');

it('throws InvalidArgumentException when fireModel() is called with empty action', function (): void {
    $model = new class {
        public function attributesToArray(): array
        {
            return ['id' => 1];
        }
    };

    $this->app->get(\ZeroBoiler\Events\EventManager::class)->fireModel('App\\Models\\Order', '', $model);
})->throws(\InvalidArgumentException::class, 'Model class name cannot be empty');

it('throws InvalidArgumentException when fireModel() is called with zero model class', function (): void {
    $model = new class {
        public function attributesToArray(): array
        {
            return ['id' => 1];
        }
    };

    $this->app->get(\ZeroBoiler\Events\EventManager::class)->fireModel('0', 'created', $model);
})->throws(\InvalidArgumentException::class, 'Model class name cannot be empty');

it('throws InvalidArgumentException when fireModel() is called with zero action', function (): void {
    $model = new class {
        public function attributesToArray(): array
        {
            return ['id' => 1];
        }
    };

    $this->app->get(\ZeroBoiler\Events\EventManager::class)->fireModel('App\\Models\\Order', '0', $model);
})->throws(\InvalidArgumentException::class, 'Model action cannot be empty');

it('fires model event normally with valid class and action', function (): void {
    $model = new class {
        public function attributesToArray(): array
        {
            return ['id' => 1, 'status' => 'active'];
        }
    };

    // Should not throw — no matching triggers
    $this->app->get(\ZeroBoiler\Events\EventManager::class)->fireModel('App\\Models\\Order', 'created', $model);
    expect(true)->toBeTrue();
});

// ─── DispatchTriggerJob array backoff config support ───────────────────────

it('accepts array backoff from config', function (): void {
    config(['events.retry.backoff' => [10, 30, 60]]);

    $job = new DispatchTriggerJob(
        (string) \Illuminate\Support\Str::uuid(),
        'test.event',
        ['key' => 'value'],
    );

    expect($job->backoff)->toBe([10, 30, 60]);
});

it('accepts string backoff from config', function (): void {
    config(['events.retry.backoff' => '10,30,60']);

    $job = new DispatchTriggerJob(
        (string) \Illuminate\Support\Str::uuid(),
        'test.event',
        ['key' => 'value'],
    );

    expect($job->backoff)->toBe([10, 30, 60]);
});

it('falls back to default backoff when config is non-string non-array', function (): void {
    config(['events.retry.backoff' => 12345]);

    $job = new DispatchTriggerJob(
        (string) \Illuminate\Support\Str::uuid(),
        'test.event',
        ['key' => 'value'],
    );

    expect($job->backoff)->toBe([60, 300, 900]);
});

it('converts float values in array backoff to int', function (): void {
    config(['events.retry.backoff' => [1.5, 2.7, 3.9]]);

    $job = new DispatchTriggerJob(
        (string) \Illuminate\Support\Str::uuid(),
        'test.event',
        ['key' => 'value'],
    );

    expect($job->backoff)->toBe([1, 2, 3]);
});

it('handles empty array backoff', function (): void {
    config(['events.retry.backoff' => []]);

    $job = new DispatchTriggerJob(
        (string) \Illuminate\Support\Str::uuid(),
        'test.event',
        ['key' => 'value'],
    );

    expect($job->backoff)->toBe([]);
});

// ─── DispatchTriggerJob config-driven tries validation ────────────────────

it('uses default tries when config is zero', function (): void {
    config(['events.retry.tries' => 0]);

    $job = new DispatchTriggerJob(
        (string) \Illuminate\Support\Str::uuid(),
        'test.event',
        [],
    );

    expect($job->tries)->toBe(3);
});

it('uses default tries when config is negative', function (): void {
    config(['events.retry.tries' => -5]);

    $job = new DispatchTriggerJob(
        (string) \Illuminate\Support\Str::uuid(),
        'test.event',
        [],
    );

    expect($job->tries)->toBe(3);
});

it('uses default tries when config is non-integer', function (): void {
    config(['events.retry.tries' => 'abc']);

    $job = new DispatchTriggerJob(
        (string) \Illuminate\Support\Str::uuid(),
        'test.event',
        [],
    );

    expect($job->tries)->toBe(3);
});

it('accepts custom integer tries from config', function (): void {
    config(['events.retry.tries' => 5]);

    $job = new DispatchTriggerJob(
        (string) \Illuminate\Support\Str::uuid(),
        'test.event',
        [],
    );

    expect($job->tries)->toBe(5);
});

// ─── ConditionEngine comprehensive operator coverage ───────────────────────

it('evaluates strict inequality (!==) correctly', function (): void {
    $engine = $this->app->get(\ZeroBoiler\Events\ConditionEngine::class);

    // Same type, same value → false
    expect($engine->matches(['x' => ['!==', 42]], ['x' => 42]))->toBeFalse();
    // Same type, different value → true
    expect($engine->matches(['x' => ['!==', 42]], ['x' => 43]))->toBeTrue();
    // Different types → true
    expect($engine->matches(['x' => ['!==', 42]], ['x' => '42']))->toBeTrue();
});

it('evaluates strict equality (===) correctly', function (): void {
    $engine = $this->app->get(\ZeroBoiler\Events\ConditionEngine::class);

    // Same type, same value → true
    expect($engine->matches(['x' => ['===', 42]], ['x' => 42]))->toBeTrue();
    // Different types → false
    expect($engine->matches(['x' => ['===', 42]], ['x' => '42']))->toBeFalse();
    // Null check → true
    expect($engine->matches(['x' => ['===', null]], ['x' => null]))->toBeTrue();
    // Non-null → false
    expect($engine->matches(['x' => ['===', null]], ['x' => 0]))->toBeFalse();
});

it('evaluates empty operator correctly', function (): void {
    $engine = $this->app->get(\ZeroBoiler\Events\ConditionEngine::class);

    // Empty string → true
    expect($engine->matches(['x' => ['empty']], ['x' => '']))->toBeTrue();
    // Empty array → true
    expect($engine->matches(['x' => ['empty']], ['x' => []]))->toBeTrue();
    // Zero → true (empty() considers 0 as empty)
    expect($engine->matches(['x' => ['empty']], ['x' => 0]))->toBeTrue();
    // Non-empty → false
    expect($engine->matches(['x' => ['empty']], ['x' => 'hello']))->toBeFalse();
    // Null → true
    expect($engine->matches(['x' => ['empty']], []))->toBeTrue();
});

it('evaluates not_empty operator correctly', function (): void {
    $engine = $this->app->get(\ZeroBoiler\Events\ConditionEngine::class);

    // Non-empty string → true
    expect($engine->matches(['x' => ['not_empty']], ['x' => 'hello']))->toBeTrue();
    // Non-empty array → true
    expect($engine->matches(['x' => ['not_empty']], ['x' => ['a']]))->toBeTrue();
    // Empty string → false
    expect($engine->matches(['x' => ['not_empty']], ['x' => '']))->toBeFalse();
    // Missing field → false (null is empty)
    expect($engine->matches(['x' => ['not_empty']], []))->toBeFalse();
});

// ─── WildcardMatcher comprehensive edge cases ──────────────────────────────

it('matches exact event names', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
});

it('matches single-segment wildcard', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', 'order.shipped'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
});

it('matches cross-segment wildcard', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.**', 'order.placed.extra.deep'))->toBeTrue();
});

it('matches catch-all wildcard', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', 'anything'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', 'a.b.c.d'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', ''))->toBeFalse();
});

it('matches double-star catch-all', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('**', 'anything'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('**', 'a.b.c.d'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('**', ''))->toBeFalse();
});

it('matches multiple wildcards', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*.order.*', 'user.order.created'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*.order.*', 'user.order'))->toBeFalse();
});

it('rejects empty event with non-wildcard pattern', function (): void {
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.placed', ''))->toBeFalse();
});

// ─── DomainEvent edge cases ────────────────────────────────────────────────

it('creates event with occur() factory', function (): void {
    $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('user.created', ['id' => 1]);

    expect($event->eventType)->toBe('user.created')
        ->and($event->payload)->toBe(['id' => 1])
        ->and($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class)
        ->and($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
});

it('round-trips through toArray and fromArray', function (): void {
    $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);
    $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString())
        ->and($restored->eventType)->toBe($original->eventType)
        ->and($restored->payload)->toBe($original->payload);
});

it('handles fromArray with missing fields gracefully', function (): void {
    $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([]);

    expect($event->eventType)->toBe('')
        ->and($event->payload)->toBe([]);
});

it('generates fresh UUID for invalid eventId in fromArray', function (): void {
    $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
        'eventType' => 'test',
        'eventId' => 'not-a-uuid',
    ]);

    // Should not throw — generates a fresh UUID
    expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
});

// ─── Config completeness verification ───────────────────────────────────────

it('has all required config keys with correct types', function (): void {
    $config = config('events');

    // Top-level keys
    expect($config)->toHaveKey('table_names')
        ->and($config)->toHaveKey('queue')
        ->and($config)->toHaveKey('retry')
        ->and($config)->toHaveKey('retention')
        ->and($config)->toHaveKey('subscriptions')
        ->and($config)->toHaveKey('wildcard_cache_ttl');

    // table_names sub-keys
    expect($config['table_names'])->toHaveKey('triggers')
        ->and($config['table_names'])->toHaveKey('event_logs')
        ->and($config['table_names'])->toHaveKey('subscriptions');

    // queue sub-keys
    expect($config['queue'])->toHaveKey('connection')
        ->and($config['queue'])->toHaveKey('queue');

    // retry sub-keys
    expect($config['retry'])->toHaveKey('tries')
        ->and($config['retry'])->toHaveKey('backoff');

    // retention sub-keys
    expect($config['retention'])->toHaveKey('days')
        ->and($config['retention'])->toHaveKey('include_pending');

    // subscriptions sub-keys
    expect($config['subscriptions'])->toHaveKey('auto_generate_secret')
        ->and($config['subscriptions'])->toHaveKey('max_failures')
        ->and($config['subscriptions'])->toHaveKey('timeout')
        ->and($config['subscriptions'])->toHaveKey('signature_algorithm');
});

// ─── ServiceProvider bindings verification ─────────────────────────────────

it('binds EventManager as singleton', function (): void {
    $first = $this->app->get(\ZeroBoiler\Events\EventManager::class);
    $second = $this->app->get(\ZeroBoiler\Events\EventManager::class);

    expect($first)->toBe($second);
});

it('binds ConditionEngine as singleton', function (): void {
    $first = $this->app->get(\ZeroBoiler\Events\ConditionEngine::class);
    $second = $this->app->get(\ZeroBoiler\Events\ConditionEngine::class);

    expect($first)->toBe($second);
});

it('binds ConditionEngineContract to ConditionEngine', function (): void {
    $contract = $this->app->get(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
    $concrete = $this->app->get(\ZeroBoiler\Events\ConditionEngine::class);

    expect($contract)->toBe($concrete);
});

it('binds TriggerBuilder as transient', function (): void {
    $first = $this->app->make(\ZeroBoiler\Events\TriggerBuilder::class);
    $second = $this->app->make(\ZeroBoiler\Events\TriggerBuilder::class);

    expect($first)->not->toBe($second);
});

it('binds SubscriptionBuilder as transient', function (): void {
    $first = $this->app->make(\ZeroBoiler\Events\SubscriptionBuilder::class);
    $second = $this->app->make(\ZeroBoiler\Events\SubscriptionBuilder::class);

    expect($first)->not->toBe($second);
});

// ─── EventLog status constants consistency ────────────────────────────────

it('has consistent status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending')
        ->and(EventLog::STATUS_DISPATCHED)->toBe('dispatched')
        ->and(EventLog::STATUS_COMPLETED)->toBe('completed')
        ->and(EventLog::STATUS_FAILED)->toBe('failed');

    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING)
        ->and(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED)
        ->and(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED)
        ->and(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
});

// ─── Final class verification ──────────────────────────────────────────────

it('marks core classes as final', function (): void {
    $finalClasses = [
        \ZeroBoiler\Events\EventManager::class,
        \ZeroBoiler\Events\ConditionEngine::class,
        \ZeroBoiler\Events\ActionResolver::class,
        \ZeroBoiler\Events\WildcardMatcher::class,
        \ZeroBoiler\Events\TriggerBuilder::class,
        \ZeroBoiler\Events\SubscriptionBuilder::class,
        \ZeroBoiler\Events\Domain\DomainEvent::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
        \ZeroBoiler\Events\EventsServiceProvider::class,
    ];

    foreach ($finalClasses as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue("{$class} must be final");
    }
});

// ─── readonly keyword verification ──────────────────────────────────────────

it('uses readonly keyword on EventManager constructor properties', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    foreach ($params as $param) {
        if ($param->getName() === 'conditionEngine' || $param->getName() === 'actionResolver' || $param->getName() === 'app') {
            expect($param->isReadOnly())->toBeTrue("{$param->getName()} must be readonly in EventManager");
        }
    }
});

it('uses readonly keyword on DomainEvent promoted properties', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);

    expect($reflection->getProperty('eventType')->isReadOnly())->toBeTrue()
        ->and($reflection->getProperty('payload')->isReadOnly())->toBeTrue()
        ->and($reflection->getProperty('eventId')->isReadOnly())->toBeTrue()
        ->and($reflection->getProperty('occurredAt')->isReadOnly())->toBeTrue();
});

// ─── Strict types enforcement ──────────────────────────────────────────────

it('enforces declare(strict_types=1) in all source files', function (): void {
    $srcDir = __DIR__.'/../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = $file->getContents();
        expect($contents)
            ->toContain('declare(strict_types=1)', "{$file->getFilename()} must declare strict types");
    }
});

// ─── Facade accessor verification ───────────────────────────────────────────

it('facade resolves to correct service', function (): void {
    $accessor = (new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class))
        ->getMethod('getFacadeAccessor')
        ->invoke(null);

    expect($accessor)->toBe(\ZeroBoiler\Events\EventManager::class);
});
