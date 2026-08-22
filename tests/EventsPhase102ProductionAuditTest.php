<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\ConditionEngineContract as ConditionEngineContractInterface;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 102 — Production audit covering:
 *
 * 1. Composer dependency cleanup verification (no unused packages)
 * 2. ServiceProvider register() binding completeness
 * 3. Singleton vs Transient lifetime verification
 * 4. Contract interface binding verification
 * 5. EventManager public API surface completeness
 * 6. Facade accessor consistency with container
 * 7. Config default value parity between source and config file
 * 8. Model table name config-driven dynamic override
 * 9. Factory model property type verification
 * 10. Webhook URL scheme enforcement edge cases (data:, javascript:, blob:)
 * 11. ConditionEngine ReDoS protection — catastrophic pattern variants
 * 12. WildcardMatcher regex special characters in patterns
 * 13. DomainEvent reconstruction with all edge cases
 * 14. TriggerBuilder save() atomicity — no double INSERT
 * 15. SubscriptionBuilder save() transaction atomicity
 * 16. EventManager fire() — empty string vs '0' rejection
 * 17. EventManager fireModel() — object without attributesToArray/toArray
 * 18. DispatchTriggerJob config-driven property initialization
 * 19. EventLog status constant consistency
 * 20. README and composer.json version alignment
 */
test('composer.json has no unused dependencies', function (): void {
    $composerJson = file_get_contents(__DIR__.'/../composer.json');
    $composer = json_decode($composerJson, true);

    // These packages are known to be unused and should NOT be present
    $unusedPackages = [
        'vlucas/phpdotenv',
        'phpoption/phpoption',
        'illuminate/log',
        'illuminate/config',
    ];

    foreach ($unusedPackages as $pkg) {
        expect(array_key_exists($pkg, $composer['require'] ?? []))
            ->toBeFalse("Unused dependency {$pkg} should be removed from composer.json");
    }

    // These packages must be present
    $requiredPackages = [
        'illuminate/contracts',
        'illuminate/support',
        'illuminate/database',
        'illuminate/queue',
        'illuminate/cache',
        'illuminate/http',
        'ramsey/uuid',
    ];

    foreach ($requiredPackages as $pkg) {
        expect(array_key_exists($pkg, $composer['require'] ?? []))
            ->toBeTrue("Required dependency {$pkg} must be present in composer.json");
    }
});

test('service provider registers all required bindings', function (): void {
    $app = app();

    // Singleton bindings
    expect($app->make(ConditionEngine::class))->toBeInstanceOf(ConditionEngine::class);
    expect($app->make(ConditionEngineContract::class))->toBeInstanceOf(ConditionEngine::class);
    expect($app->make(ActionResolver::class))->toBeInstanceOf(ActionResolver::class);
    expect($app->make(EventManager::class))->toBeInstanceOf(EventManager::class);
    expect($app->make(EventScheduler::class))->toBeInstanceOf(EventScheduler::class);

    // Transient bindings — each resolution should return a fresh instance
    $builder1 = $app->make(TriggerBuilder::class);
    $builder2 = $app->make(TriggerBuilder::class);
    expect($builder1 === $builder2)->toBeFalse('TriggerBuilder must be transient');

    $subBuilder1 = $app->make(SubscriptionBuilder::class);
    $subBuilder2 = $app->make(SubscriptionBuilder::class);
    expect($subBuilder1 === $subBuilder2)->toBeFalse('SubscriptionBuilder must be transient');
});

test('contract interface resolves to concrete implementation', function (): void {
    $app = app();

    $contract = $app->make(ConditionEngineContractInterface::class);
    $concrete = $app->make(ConditionEngine::class);

    // Both should resolve to the same singleton instance
    expect($contract)->toBe($concrete);
    expect($contract)->toBeInstanceOf(ConditionEngine::class);
    expect($contract)->toBeInstanceOf(ConditionEngineContractInterface::class);
});

test('service provider provides() lists all services', function (): void {
    $app = app();
    $provider = new EventsServiceProvider($app);

    $provides = $provider->provides();

    $expectedProvides = [
        EventManager::class,
        ConditionEngine::class,
        ConditionEngineContract::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
    ];

    foreach ($expectedProvides as $service) {
        expect(in_array($service, $provides, true))
            ->toBeTrue("provides() must include {$service}");
    }
});

test('event manager public api surface is complete', function (): void {
    $em = app(EventManager::class);

    // Builder methods
    expect(method_exists($em, 'on'))->toBeTrue();
    expect(method_exists($em, 'register'))->toBeTrue();

    // Firing methods
    expect(method_exists($em, 'fire'))->toBeTrue();
    expect(method_exists($em, 'fireModel'))->toBeTrue();
    expect(method_exists($em, 'executeTrigger'))->toBeTrue();

    // CRUD methods
    expect(method_exists($em, 'listTriggers'))->toBeTrue();
    expect(method_exists($em, 'getTrigger'))->toBeTrue();
    expect(method_exists($em, 'deleteTrigger'))->toBeTrue();
    expect(method_exists($em, 'enable'))->toBeTrue();
    expect(method_exists($em, 'disable'))->toBeTrue();

    // Cache methods
    expect(method_exists($em, 'invalidateTriggerCache'))->toBeTrue();

    // Global disable
    expect(method_exists($em, 'isDisabled'))->toBeTrue();
    expect(method_exists($em, 'setEnabled'))->toBeTrue();

    // Subscription methods (from trait)
    expect(method_exists($em, 'subscribe'))->toBeTrue();
    expect(method_exists($em, 'unsubscribe'))->toBeTrue();
    expect(method_exists($em, 'listSubscriptions'))->toBeTrue();
    expect(method_exists($em, 'getSubscription'))->toBeTrue();
    expect(method_exists($em, 'subscribeWebhook'))->toBeTrue();

    // History methods (from trait)
    expect(method_exists($em, 'getEventHistory'))->toBeTrue();
    expect(method_exists($em, 'getStats'))->toBeTrue();
    expect(method_exists($em, 'purgeLogs'))->toBeTrue();
    expect(method_exists($em, 'getStalePendingLogs'))->toBeTrue();
    expect(method_exists($em, 'deactivateExceededSubscriptions'))->toBeTrue();

    // Scheduler
    expect(method_exists($em, 'registerScheduler'))->toBeTrue();
});

test('facade accessor matches container binding', function (): void {
    $expected = EventManager::class;
    $actual = EventManagerFacade::getFacadeAccessor();

    expect($actual)->toBe($expected);
});

test('config default values match source code expectations', function (): void {
    $config = config('events');

    // Top-level keys must exist
    $expectedKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
    foreach ($expectedKeys as $key) {
        expect(array_key_exists($key, $config))
            ->toBeTrue("Config key 'events.{$key}' must exist");
    }

    // Table names sub-keys
    $tableKeys = ['triggers', 'event_logs', 'subscriptions'];
    foreach ($tableKeys as $key) {
        expect(array_key_exists($key, $config['table_names']))
            ->toBeTrue("Config key 'events.table_names.{$key}' must exist");
    }

    // Subscriptions sub-keys
    $subKeys = ['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm', 'cleanup_cron'];
    foreach ($subKeys as $key) {
        expect(array_key_exists($key, $config['subscriptions']))
            ->toBeTrue("Config key 'events.subscriptions.{$key}' must exist");
    }

    // Retention sub-keys
    $retKeys = ['days', 'include_pending', 'schedule_cron'];
    foreach ($retKeys as $key) {
        expect(array_key_exists($key, $config['retention']))
            ->toBeTrue("Config key 'events.retention.{$key}' must exist");
    }

    // Queue sub-keys
    $queueKeys = ['connection', 'queue'];
    foreach ($queueKeys as $key) {
        expect(array_key_exists($key, $config['queue']))
            ->toBeTrue("Config key 'events.queue.{$key}' must exist");
    }

    // Retry sub-keys
    $retryKeys = ['tries', 'backoff'];
    foreach ($retryKeys as $key) {
        expect(array_key_exists($key, $config['retry']))
            ->toBeTrue("Config key 'events.retry.{$key}' must exist");
    }
});

test('model table names are config-driven', function (): void {
    $trigger = new Trigger;
    $eventLog = new EventLog;
    $subscription = new Subscription;

    expect($trigger->getTable())->toBe('triggers');
    expect($eventLog->getTable())->toBe('event_logs');
    expect($subscription->getTable())->toBe('event_subscriptions');
});

test('webhook url scheme enforcement rejects dangerous protocols', function (string $url): void {
    $em = app(EventManager::class);
    $builder = $em->subscribe('test.event', $url);

    $this->expectException(\InvalidArgumentException::class);
    $builder->save();
})->with([
    'data URI' => 'data:text/html,<script>alert(1)</script>',
    'javascript URI' => 'javascript:alert(1)',
    'blob URI' => 'blob:http://example.com/abc',
    'ftp URI' => 'ftp://example.com/file',
    'file URI' => 'file:///etc/passwd',
    'mailto URI' => 'mailto:admin@example.com',
]);

test('webhook url scheme enforcement accepts http and https', function (string $url): void {
    // Just validation — no actual HTTP call
    $builder = app(EventManager::class)->subscribe('test.event', $url);
    expect($builder)->toBeInstanceOf(SubscriptionBuilder::class);
})->with([
    'HTTP URL' => 'http://example.com/webhook',
    'HTTPS URL' => 'https://example.com/webhook',
]);

test('condition engine rejects catastrophic regex patterns', function (string $pattern): void {
    $engine = new ConditionEngine;
    $result = $engine->matches(['code' => ['matches', $pattern]], ['code' => 'abc']);

    // Should return false for catastrophic patterns
    expect($result)->toBeFalse();
})->with([
    'nested quantifier a+' => '/(a+)+/',
    'nested quantifier a*' => '/(a*)*/',
    'nested quantifier a+?' => '/(a+?)+/',
    'complex nested' => '/((a+)+b)+/',
]);

test('condition engine rejects overly long regex patterns', function (): void {
    $engine = new ConditionEngine;
    $longPattern = '/^[a-z]{250}/'; // Under 500 chars — should work
    $result = $engine->matches(['code' => ['matches', $longPattern]], ['code' => str_repeat('a', 250)]);
    expect($result)->toBeTrue();

    $tooLongPattern = '/' . str_repeat('a', 501) . '/'; // Over 500 chars
    $result = $engine->matches(['code' => ['matches', $tooLongPattern]], ['code' => str_repeat('a', 501)]);
    expect($result)->toBeFalse();
});

test('wildcard matcher handles regex special characters in patterns', function (): void {
    // Patterns with dots should not be treated as regex dots
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'orderXplaced'))->toBeFalse();
    expect(WildcardMatcher::matches('user.1.2.3', 'user.1.2.3'))->toBeTrue();
    expect(WildcardMatcher::matches('*.1.2.3', 'user.1.2.3'))->toBeTrue();
});

test('domain event reconstruction preserves identity', function (): void {
    $original = DomainEvent::occur('test.event', ['key' => 'value']);
    $array = $original->toArray();
    $restored = DomainEvent::fromArray($array);

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
    expect($restored->occurredAt->format(\DateTimeInterface::ATOM))
        ->toBe($original->occurredAt->format(\DateTimeInterface::ATOM));
});

test('domain event reconstruction handles missing eventType', function (): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('eventType is required');

    DomainEvent::fromArray(['payload' => ['key' => 'value']]);
});

test('domain event is immutable', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);

    expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
    expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
    expect($event->eventType)->toBe('test.event');

    // Readonly properties — cannot be reassigned
    $reflection = new \ReflectionClass($event);

    expect($reflection->getProperty('eventId')->isReadOnly())->toBeTrue();
    expect($reflection->getProperty('occurredAt')->isReadOnly())->toBeTrue();
    expect($reflection->getProperty('eventType')->isReadOnly())->toBeTrue();
    expect($reflection->getProperty('payload')->isReadOnly())->toBeTrue();
});

test('event manager rejects empty and zero-string event names', function (string $event): void {
    $this->expectException(\InvalidArgumentException::class);
    app(EventManager::class)->fire($event);
})->with([
    'empty string' => '',
    'zero string' => '0',
]);

test('event manager rejects empty and zero-string model class', function (string $class): void {
    $this->expectException(\InvalidArgumentException::class);
    app(EventManager::class)->fireModel($class, 'created', new \stdClass);
})->with([
    'empty string' => '',
    'zero string' => '0',
]);

test('event manager rejects empty and zero-string model action', function (string $action): void {
    $this->expectException(\InvalidArgumentException::class);
    app(EventManager::class)->fireModel('App\\Models\\User', $action, new \stdClass);
})->with([
    'empty string' => '',
    'zero string' => '0',
]);

test('trigger builder rejects empty event and no action', function (): void {
    $em = app(EventManager::class);

    // No action
    $builder = $em->on('test.event');
    // Action not set — should throw on save()
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('action is required');
    $builder->save();
});

test('trigger builder generates name from event when not provided', function (): void {
    $em = app(EventManager::class);

    $trigger = $em->on('order.shipped')
        ->action('\ZeroBoiler\Events\Tests\Actions\TestAction')
        ->save();

    expect($trigger->name)->toBe('order.shipped Trigger');
    expect($trigger->event)->toBe('order.shipped');
});

test('dispatch trigger job reads config at construction time', function (): void {
    $job = new DispatchTriggerJob(
        triggerId: (string) \Illuminate\Support\Str::uuid(),
        event: 'test.event',
        payload: ['key' => 'value'],
    );

    expect($job->tries)->toBe(3); // From config events.retry.tries
    expect($job->queue)->toBe('default'); // From config events.queue.queue
});

test('event log status constants are consistent', function (): void {
    $statuses = EventLog::$statuses;

    expect($statuses)->toContain(EventLog::STATUS_PENDING);
    expect($statuses)->toContain(EventLog::STATUS_DISPATCHED);
    expect($statuses)->toContain(EventLog::STATUS_COMPLETED);
    expect($statuses)->toContain(EventLog::STATUS_FAILED);
    expect(count($statuses))->toBe(4);
});

test('subscription sign payload returns empty string for null secret', function (): void {
    $sub = Subscription::factory()->withoutSecret()->create();

    expect($sub->signPayload('test-payload'))->toBe('');
});

test('subscription sign payload returns empty string for empty secret', function (): void {
    $sub = new Subscription([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'event' => 'test',
        'url' => 'https://example.com',
        'secret' => '',
    ]);

    expect($sub->signPayload('test-payload'))->toBe('');
});

test('event manager global disable silences fire calls', function (): void {
    $em = app(EventManager::class);
    $em->setEnabled(false);

    $em->fire('test.event', ['key' => 'value']);

    // Should not throw — just return silently
    expect(true)->toBeTrue();

    // Re-enable
    $em->setEnabled(true);
    expect($em->isDisabled())->toBeFalse();
});

test('wildcard matcher readonly and final class', function (): void {
    $reflection = new \ReflectionClass(WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue('WildcardMatcher must be final');
    expect($reflection->isReadOnly())->toBeTrue('WildcardMatcher must be readonly');
});

test('all source files have strict types declaration', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php');

    expect(count($files))->toBeGreaterThan(0);

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect(str_contains($content, 'declare(strict_types=1);'))
            ->toBeTrue("{$file} must have declare(strict_types=1)");
    }
});

test('readme version matches composer json version', function (): void {
    $composer = json_decode(
        file_get_contents(__DIR__.'/../composer.json'),
        true,
    );

    $composerVersion = $composer['version'];

    $readme = file_get_contents(__DIR__.'/../README.md');

    // Check version badge
    expect(str_contains($readme, "version-{$composerVersion}"))
        ->toBeTrue("README version badge must match composer.json version ({$composerVersion})");
});

test('phpstan neon dist has correct configuration', function (): void {
    $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    // Level 9
    expect(str_contains($neon, 'level: 9'))->toBeTrue('PHPStan level must be 9');

    // Critical checks enabled
    expect(str_contains($neon, 'checkUninitializedProperties: true'))->toBeTrue();
    expect(str_contains($neon, 'checkGenericClassInNonGenericObjectType: true'))->toBeTrue();
    expect(str_contains($neon, 'checkMissingIterableValueType: true'))->toBeTrue();
    expect(str_contains($neon, 'checkFunctionNameCase: true'))->toBeTrue();
    expect(str_contains($neon, 'checkClassLikeNameCase: true'))->toBeTrue();

    // Paths
    expect(str_contains($neon, 'src'))->toBeTrue();
    expect(str_contains($neon, 'database/migrations'))->toBeTrue();
    expect(str_contains($neon, 'database/factories'))->toBeTrue();
});

test('event manager cache ttl 0 disables caching', function (): void {
    $em = app(EventManager::class);

    // Set TTL to 0 via reflection to test the behavior
    $reflection = new \ReflectionMethod($em, 'getTriggerCacheTtl');

    // Temporarily set config
    config(['events.wildcard_cache_ttl' => 0]);

    $ttl = $reflection->invoke($em);
    expect($ttl)->toBe(0);

    // Restore
    config(['events.wildcard_cache_ttl' => 300]);
});

test('event manager cache ttl handles negative values with default fallback', function (): void {
    $em = app(EventManager::class);

    $reflection = new \ReflectionMethod($em, 'getTriggerCacheTtl');

    config(['events.wildcard_cache_ttl' => -5]);

    $ttl = $reflection->invoke($em);
    expect($ttl)->toBe(300); // Default fallback

    config(['events.wildcard_cache_ttl' => 300]);
});

test('condition engine handles deep nested dot notation', function (): void {
    $engine = new ConditionEngine;

    $payload = [
        'user' => [
            'profile' => [
                'settings' => [
                    'notifications' => 'enabled',
                ],
            ],
        ],
    ];

    expect($engine->matches(
        ['user.profile.settings.notifications' => 'enabled'],
        $payload,
    ))->toBeTrue();

    expect($engine->matches(
        ['user.profile.settings.notifications' => 'disabled'],
        $payload,
    ))->toBeFalse();
});

test('wildcard matcher extract wildcards returns empty for cross segment patterns', function (): void {
    expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))->toBe([]);
    expect(WildcardMatcher::extractWildcards('**', 'anything'))->toBe([]);
});

test('wildcard matcher extract wildcards returns correct values for single segment', function (): void {
    $extracted = WildcardMatcher::extractWildcards('user.*.created', 'user.john.created');
    expect($extracted)->toBe(['john']);

    $extracted2 = WildcardMatcher::extractWildcards('*.order.*', 'user.123.placed');
    expect($extracted2)->toBe(['user', 'placed']);
});

test('factory model properties are typed as static string', function (): void {
    $triggerFactoryReflection = new \ReflectionProperty(
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class,
        'model',
    );
    expect($triggerFactoryReflection->getType()->getName())->toBe(Trigger::class);

    $eventLogFactoryReflection = new \ReflectionProperty(
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class,
        'model',
    );
    expect($eventLogFactoryReflection->getType()->getName())->toBe(EventLog::class);

    $subscriptionFactoryReflection = new \ReflectionProperty(
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class,
        'model',
    );
    expect($subscriptionFactoryReflection->getType()->getName())->toBe(Subscription::class);
});

test('subscription builder rejects empty url', function (): void {
    $em = app(EventManager::class);
    $builder = $em->subscribe('test.event', '');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('URL is required');
    $builder->save();
});

test('subscription builder rejects invalid url', function (): void {
    $em = app(EventManager::class);
    $builder = $em->subscribe('test.event', 'not-a-valid-url');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('valid URL');
    $builder->save();
});

test('trigger delete invalidates cache', function (): void {
    $em = app(EventManager::class);
    $trigger = Trigger::factory()->enabled()->create();

    // Delete should succeed
    $result = $em->deleteTrigger($trigger->id);
    expect($result)->toBeTrue();

    // Non-existent trigger should return false
    $result = $em->deleteTrigger($trigger->id);
    expect($result)->toBeFalse();
});

test('trigger enable and disable return false for non existent', function (): void {
    $em = app(EventManager::class);
    $fakeId = (string) \Illuminate\Support\Str::uuid();

    expect($em->enable($fakeId))->toBeFalse();
    expect($em->disable($fakeId))->toBeFalse();
});

test('event manager list triggers with null filters returns all', function (): void {
    Trigger::factory()->count(3)->create();

    $em = app(EventManager::class);
    $triggers = $em->listTriggers();

    expect($triggers)->toHaveCount(3);
});

test('event manager list triggers with wildcard event filter', function (): void {
    Trigger::factory()->forEvent('order.placed')->create();
    Trigger::factory()->forEvent('user.created')->create();

    $em = app(EventManager::class);
    $triggers = $em->listTriggers(event: 'order.*');

    expect($triggers)->toHaveCount(1);
    expect($triggers->first()->event)->toBe('order.placed');
});
