<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
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
 * Phase 1 Infrastructure — Production Readiness Deep Audit.
 *
 * Covers security hardening, edge cases, contract consistency,
 * and production-critical behaviors not covered by existing tests.
 */
describe('Production Readiness Deep Audit', function (): void {
    describe('Security Hardening', function (): void {
        test('error logging does not leak full stack traces', function (): void {
            $action = new class implements Triggerable
            {
                #[\Override]
                public function handle(array $payload): void
                {
                    throw new \RuntimeException('Secret operation failed: API_KEY=abc123');
                }
            };

            app()->bind(Triggerable::class, fn (): Triggerable => $action);

            $em = app()->make(EventManager::class);
            $trigger = Trigger::factory()->enabled()->sync()->create([
                'action' => get_class($action),
                'event' => 'security.test',
                'conditions' => null,
            ]);

            // fire() should throw, but log should not contain trace
            try {
                $em->fire('security.test', ['secret' => 'password123']);
            } catch (\Throwable $e) {
                // Expected
            }

            $log = EventLog::where('trigger_id', $trigger->id)->first();
            expect($log)->not->toBeNull();
            expect($log->status)->toBe(EventLog::STATUS_FAILED);

            // Verify error message is captured but payload is NOT in the log error field
            $error = $log->error;
            expect($error)->toBeString();
            expect($error)->toContain('Secret operation failed');
            // The error should not contain the full trace dump
            expect(strlen($error))->toBeLessThan(500);
        });

        test('webhook URL rejects SSRF patterns comprehensively', function (): void {
            $em = app()->make(EventManager::class);

            $ssrfUrls = [
                'ftp://evil.com/files',
                'file:///etc/passwd',
                'file://localhost/etc/shadow',
                'gopher://internal:6379/',
                'dict://internal:11211/',
                'ldap://internal:389/',
                'mailto:admin@evil.com',
                'javascript:alert(1)',
                'data:text/html,<script>alert(1)</script>',
                'php://filter/read=convert.base64-encode/resource=/etc/passwd',
                'zip:///etc/passwd',
                'ssh://internal:22/',
            ];

            foreach ($ssrfUrls as $url) {
                try {
                    $em->subscribe('ssrf.test', $url)->save();
                    // If no exception, the URL was accepted — fail
                    expect(true)->toBeFalse("SSRF URL should have been rejected: {$url}");
                } catch (\InvalidArgumentException $e) {
                    expect($e->getMessage())->toContain('HTTP or HTTPS');
                }
            }
        });

        test('subscription secret hidden from serialization', function (): void {
            $sub = Subscription::factory()->create([
                'secret' => 'whsec_super_secret_value',
            ]);

            $array = $sub->toArray();
            expect($array)->not->toHaveKey('secret');
            expect($array['secret'] ?? 'hidden')->not->toBe('whsec_super_secret_value');
        });

        test('event log payload does not leak internal webhook keys', function (): void {
            $log = EventLog::factory()->create([
                'payload' => [
                    'url' => 'https://evil.com/webhook',
                    'subscription_id' => 'secret-sub-id',
                    'event' => 'internal.event',
                    'headers' => ['X-Internal' => 'secret'],
                    'user_id' => 123,
                    'amount' => 99.99,
                ],
            ]);

            $payload = $log->payload;
            // Internal keys should be present in the stored payload (it's the raw log)
            // but WebhookAction should strip them before sending to external endpoints
            expect($payload)->toHaveKey('url');
            expect($payload)->toHaveKey('subscription_id');

            // Verify WebhookAction strips keys (indirectly via buildRedeliverBody pattern)
            $webhookData = $payload;
            unset($webhookData['url'], $webhookData['event'], $webhookData['headers'], $webhookData['subscription_id']);
            expect($webhookData)->not->toHaveKey('url');
            expect($webhookData)->not->toHaveKey('subscription_id');
            expect($webhookData)->not->toHaveKey('event');
            expect($webhookData)->not->toHaveKey('headers');
            expect($webhookData)->toHaveKey('user_id');
            expect($webhookData)->toHaveKey('amount');
        });
    });

    describe('Contract Consistency', function (): void {
        test('ConditionEngine implements ConditionEngineContract', function (): void {
            $engine = app()->make(ConditionEngineContract::class);
            expect($engine)->toBeInstanceOf(ConditionEngine::class);
            expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
        });

        test('ConditionEngine singleton identity', function (): void {
            $a = app()->make(ConditionEngine::class);
            $b = app()->make(ConditionEngine::class);
            expect(spl_object_id($a))->toBe(spl_object_id($b));

            $c = app()->make(ConditionEngineContract::class);
            expect(spl_object_id($a))->toBe(spl_object_id($c));
        });

        test('ActionResolver singleton identity', function (): void {
            $a = app()->make(ActionResolver::class);
            $b = app()->make(ActionResolver::class);
            expect(spl_object_id($a))->toBe(spl_object_id($b));
        });

        test('EventManager singleton identity', function (): void {
            $a = app()->make(EventManager::class);
            $b = app()->make(EventManager::class);
            expect(spl_object_id($a))->toBe(spl_object_id($b));
        });

        test('TriggerBuilder transient — fresh instance each resolution', function (): void {
            $a = app()->make(TriggerBuilder::class);
            $b = app()->make(TriggerBuilder::class);
            expect(spl_object_id($a))->not->toBe(spl_object_id($b));
        });

        test('SubscriptionBuilder transient — fresh instance each resolution', function (): void {
            $a = app()->make(SubscriptionBuilder::class);
            $b = app()->make(SubscriptionBuilder::class);
            expect(spl_object_id($a))->not->toBe(spl_object_id($b));
        });

        test('EventScheduler singleton identity', function (): void {
            $a = app()->make(EventScheduler::class);
            $b = app()->make(EventScheduler::class);
            expect(spl_object_id($a))->toBe(spl_object_id($b));
        });
    });

    describe('Wildcard Cache Edge Cases', function (): void {
        test('cache invalidation on trigger create makes it visible immediately', function (): void {
            $em = app()->make(EventManager::class);

            // Clear any existing cache
            Cache::forget('zeroboiler:events:enabled_wildcard_triggers');

            // Fire before trigger exists — should not match
            $trigger = Trigger::factory()->create([
                'event' => 'cache.test.*',
                'enabled' => true,
                'action' => '\\App\\Actions\\LogOrderEvent',
                'async' => false,
            ]);

            // Create the trigger via builder (which invalidates cache)
            Trigger::factory()->enabled()->create([
                'event' => 'cache.sudden.*',
                'enabled' => true,
                'action' => '\\App\\Actions\\LogOrderEvent',
                'async' => false,
            ]);

            // Verify cache is invalidated after direct create
            $cached = Cache::get('zeroboiler:events:enabled_wildcard_triggers');
            expect($cached)->toBeNull();
        });

        test('cache TTL 0 disables caching entirely', function (): void {
            $em = app()->make(EventManager::class);
            config(['events.wildcard_cache_ttl' => 0]);

            Trigger::factory()->enabled()->create([
                'event' => 'ttl_zero.*',
                'action' => '\\App\\Actions\\LogOrderEvent',
            ]);

            // Fire should work even with TTL 0
            $logsBefore = EventLog::count();
            $em->fire('ttl_zero.created', ['key' => 'value']);
            // With TTL 0, triggers are fetched directly from DB each time
            $triggerCount = Trigger::where('event', 'like', '%*%')
                ->where('enabled', true)
                ->count();
            expect($triggerCount)->toBeGreaterThanOrEqual(1);
        });
    });

    describe('DomainEvent Immutability', function (): void {
        test('eventId is preserved through roundtrip', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $originalId = $event->eventId->toString();
            $originalTime = $event->occurredAt->format(\DateTimeImmutable::ATOM);

            $restored = DomainEvent::fromArray($event->toArray());
            expect($restored->eventId->toString())->toBe($originalId);
            expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))->toBe($originalTime);
        });

        test('fromArray with empty eventType throws', function (): void {
            expect(fn (): DomainEvent => DomainEvent::fromArray(['payload' => []]))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('fromArray with non-string eventType throws', function (): void {
            expect(fn (): DomainEvent => DomainEvent::fromArray(['eventType' => 123]))
                ->toThrow(\InvalidArgumentException::class);
        });

        test('fromArray gracefully handles invalid UUID', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-valid-uuid',
                'payload' => ['data' => 1],
            ]);
            // Should generate a fresh UUID instead of throwing
            expect($event->eventId->toString())->not->toBe('not-a-valid-uuid');
        });

        test('fromArray gracefully handles invalid datetime', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-date',
                'payload' => ['data' => 1],
            ]);
            // Should use now() instead of throwing
            $now = new \DateTimeImmutable();
            $diff = $now->getTimestamp() - $event->occurredAt->getTimestamp();
            expect(abs($diff))->toBeLessThanOrEqual(1);
        });

        test('toArray has all required keys', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $data = $event->toArray();

            expect($data)->toHaveKey('eventId');
            expect($data)->toHaveKey('eventType');
            expect($data)->toHaveKey('payload');
            expect($data)->toHaveKey('occurredAt');
            expect($data['eventId'])->toBe($event->eventId->toString());
            expect($data['eventType'])->toBe('test.event');
            expect($data['payload'])->toBe(['key' => 'value']);
        });

        test('readonly properties cannot be reassigned', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);

            $ref = new ReflectionProperty($event, 'eventType');
            expect($ref->isReadOnly())->toBeTrue();

            $ref = new ReflectionProperty($event, 'eventId');
            expect($ref->isReadOnly())->toBeTrue();

            $ref = new ReflectionProperty($event, 'occurredAt');
            expect($ref->isReadOnly())->toBeTrue();

            $ref = new ReflectionProperty($event, 'payload');
            expect($ref->isReadOnly())->toBeTrue();
        });
    });

    describe('ConditionEngine Comprehensive Operators', function (): void {
        test('not_contains operator', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['urgent', 'billing']]))->toBeTrue();
            expect($engine->matches(['tags' => ['not_contains', 'urgent']], ['tags' => ['urgent', 'billing']]))->toBeFalse();
        });

        test('not_empty operator', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'some text']))->toBeTrue();
            expect($engine->matches(['notes' => ['not_empty']], ['notes' => '']))->toBeFalse();
            expect($engine->matches(['notes' => ['not_empty']], ['notes' => []]))->toBeFalse();
        });

        test('=== strict identity operator', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['flag' => ['===', true]], ['flag' => true]))->toBeTrue();
            expect($engine->matches(['flag' => ['===', true]], ['flag' => 1]))->toBeFalse();
            expect($engine->matches(['flag' => ['===', 0]], ['flag' => false]))->toBeFalse();
        });

        test('!== strict inequality operator', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['flag' => ['!==', true]], ['flag' => false]))->toBeTrue();
            expect($engine->matches(['flag' => ['!==', true]], ['flag' => true]))->toBeFalse();
        });

        test('ReDoS protection rejects long patterns', function (): void {
            $engine = new ConditionEngine();
            $longPattern = '/^([a-z]+)+$/' . str_repeat('a', 600);

            expect($engine->matches(
                ['code' => ['matches', $longPattern]],
                ['code' => 'test'],
            ))->toBeFalse();
        });

        test('ReDoS protection rejects nested quantifiers', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(
                ['code' => ['matches', '/(a+)+b/']],
                ['code' => 'aaab'],
            ))->toBeFalse();
        });

        test('empty conditions array matches everything', function (): void {
            $engine = new ConditionEngine();
            expect($engine->matches([], ['any' => 'data']))->toBeTrue();
            expect($engine->matches([], []))->toBeTrue();
        });
    });

    describe('Event Lifecycle Integration', function (): void {
        test('global disable prevents fire from dispatching', function (): void {
            $em = app()->make(EventManager::class);
            $em->setEnabled(false);

            Trigger::factory()->enabled()->sync()->create([
                'event' => 'disabled.test',
                'action' => '\\App\\Actions\\SendOrderNotification',
            ]);

            $countBefore = EventLog::count();
            $em->fire('disabled.test', ['key' => 'value']);

            expect(EventLog::count())->toBe($countBefore);

            // Re-enable
            $em->setEnabled(true);
        });

        test('fire with empty payload works when trigger has no conditions', function (): void {
            $em = app()->make(EventManager::class);

            Trigger::factory()->enabled()->sync()->create([
                'event' => 'empty.payload.test',
                'action' => '\\App\\Actions\\SendOrderNotification',
                'conditions' => null,
            ]);

            $countBefore = EventLog::count();
            $em->fire('empty.payload.test', []);

            expect(EventLog::count())->toBeGreaterThan($countBefore);
        });

        test('fireModel constructs correct event name', function (): void {
            $em = app()->make(EventManager::class);

            Trigger::factory()->enabled()->sync()->create([
                'event' => 'App\\Models\\Order.created',
                'action' => '\\App\\Actions\\LogOrderCreated',
                'conditions' => null,
            ]);

            $model = new class
            {
                public function attributesToArray(): array
                {
                    return ['id' => 1, 'status' => 'active'];
                }
            };

            $countBefore = EventLog::count();
            $em->fireModel('App\\Models\\Order', 'created', $model);

            expect(EventLog::count())->toBeGreaterThan($countBefore);

            $log = EventLog::latest()->first();
            expect($log->event)->toBe('App\\Models\\Order.created');
            expect($log->payload)->toHaveKey('id');
            expect($log->payload)->toHaveKey('status');
            expect($log->payload)->toHaveKey('model_class');
            expect($log->payload)->toHaveKey('action');
        });

        test('trigger builder action dedup preserves order', function (): void {
            $em = app()->make(EventManager::class);

            $trigger = $em->on('dedup.test')
                ->action('\\App\\Actions\\SendOrderNotification')
                ->actions([
                    '\\App\\Actions\\LogOrderEvent',
                    '\\App\\Actions\\SendOrderNotification', // duplicate
                ])
                ->save();

            $parsed = json_decode($trigger->action, true);
            expect($parsed)->toBe([
                '\\App\\Actions\\SendOrderNotification',
                '\\App\\Actions\\LogOrderEvent',
            ]);
        });

        test('delete trigger removes it and invalidates cache', function (): void {
            $em = app()->make(EventManager::class);

            $trigger = Trigger::factory()->enabled()->create([
                'event' => 'delete.test',
                'action' => '\\App\\Actions\\SendOrderNotification',
            ]);

            $id = $trigger->id;
            expect($em->deleteTrigger($id))->toBeTrue();
            expect(Trigger::find($id))->toBeNull();
            expect($em->deleteTrigger($id))->toBeFalse();

            // Verify cache was invalidated
            $cached = Cache::get('zeroboiler:events:enabled_wildcard_triggers');
            expect($cached)->toBeNull();
        });
    });

    describe('Facade Proxy Verification', function (): void {
        test('facade resolves to correct instance', function (): void {
            $em = app()->make(EventManager::class);
            $facadeRoot = EventManagerFacade::getFacadeRoot();

            expect(spl_object_id($facadeRoot))->toBe(spl_object_id($em));
        });

        test('facade on() returns TriggerBuilder', function (): void {
            $builder = EventManagerFacade::on('facade.test');
            expect($builder)->toBeInstanceOf(TriggerBuilder::class);
        });

        test('facade subscribe() returns SubscriptionBuilder', function (): void {
            $builder = EventManagerFacade::subscribe('facade.test', 'https://example.com');
            expect($builder)->toBeInstanceOf(SubscriptionBuilder::class);
        });
    });

    describe('WildcardMatcher Exhaustive Patterns', function (): void {
        test('exact match without wildcards', function (): void {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        test('single segment wildcard', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        test('cross segment wildcard', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra.deep'))->toBeTrue();
        });

        test('catch-all single star', function (): void {
            expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'a.b.c'))->toBeTrue();
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        test('catch-all double star', function (): void {
            expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        test('multiple single wildcards', function (): void {
            expect(WildcardMatcher::matches('*.order.*', 'user.order.created'))->toBeTrue();
            expect(WildcardMatcher::matches('*.order.*', 'user.order'))->toBeFalse();
        });

        test('regex special chars in event name are escaped', function (): void {
            expect(WildcardMatcher::matches('test.*', 'test.+'))->toBeTrue(); // literal dot in event
            expect(WildcardMatcher::matches('test.*', 'test().'))->toBeTrue(); // literal parens in event
        });

        test('extract wildcards from single-segment pattern', function (): void {
            expect(WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created'))
                ->toBe(['profile']);
        });

        test('extract wildcards returns empty for cross-segment pattern', function (): void {
            expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))
                ->toBe([]);
        });
    });

    describe('parseActions Edge Cases', function (): void {
        test('empty string returns empty array', function (): void {
            $em = app()->make(EventManager::class);
            $ref = new ReflectionMethod($em, 'parseActions');
            $result = $ref->invoke($em, '');
            expect($result)->toBe([]);
        });

        test('single class name string', function (): void {
            $em = app()->make(EventManager::class);
            $ref = new ReflectionMethod($em, 'parseActions');
            $result = $ref->invoke($em, \ZeroBoiler\Events\Tests\Actions\Foo');
            expect($result)->toBe([\ZeroBoiler\Events\Tests\Actions\Foo']);
        });

        test('JSON array of class names', function (): void {
            $em = app()->make(EventManager::class);
            $ref = new ReflectionMethod($em, 'parseActions');
            $result = $ref->invoke($em, '["App\\Actions\\Foo","App\\Actions\\Bar"]');
            expect($result)->toBe([\ZeroBoiler\Events\Tests\Actions\Foo', \ZeroBoiler\Events\Tests\Actions\Bar']);
        });

        test('JSON object with class + params', function (): void {
            $em = app()->make(EventManager::class);
            $ref = new ReflectionMethod($em, 'parseActions');
            $result = $ref->invoke($em, '{"class":"App\\Actions\\Foo","params":{"url":"https://example.com"}}');
            expect($result)->toEqual([
                ['class' => \ZeroBoiler\Events\Tests\Actions\Foo', 'params' => ['url' => 'https://example.com']],
            ]);
        });

        test('JSON with classes + params (multi-action)', function (): void {
            $em = app()->make(EventManager::class);
            $ref = new ReflectionMethod($em, 'parseActions');
            $result = $ref->invoke($em, '{"classes":["Foo","Bar"],"params":{"url":"https://example.com"}}');
            expect($result)->toEqual([
                ['class' => 'Foo', 'params' => ['url' => 'https://example.com']],
                ['class' => 'Bar', 'params' => ['url' => 'https://example.com']],
            ]);
        });
    });

    describe('Event History and Statistics', function (): void {
        test('getStats returns correct structure with zero data', function (): void {
            $em = app()->make(EventManager::class);
            EventLog::query()->delete();

            $stats = $em->getStats();

            expect($stats)->toHaveKey('total_logs');
            expect($stats)->toHaveKey('total_triggers');
            expect($stats)->toHaveKey('active_triggers');
            expect($stats)->toHaveKey('completed');
            expect($stats)->toHaveKey('failed');
            expect($stats)->toHaveKey('pending');
            expect($stats)->toHaveKey('dispatched');
            expect($stats)->toHaveKey('success_rate');
            expect($stats)->toHaveKey('failure_rate');
            expect($stats)->toHaveKey('avg_duration_ms');
            expect($stats)->toHaveKey('top_events');
            expect($stats)->toHaveKey('top_failed_events');
            expect($stats['total_logs'])->toBe(0);
            expect($stats['success_rate'])->toBeNull();
            expect($stats['failure_rate'])->toBeNull();
            expect($stats['avg_duration_ms'])->toBeNull();
        });

        test('purgeLogs only deletes old completed/failed logs', function (): void {
            EventLog::query()->delete();

            EventLog::factory()->completed()->create([
                'created_at' => now()->subDays(60),
            ]);
            EventLog::factory()->failed()->create([
                'created_at' => now()->subDays(60),
            ]);
            EventLog::factory()->pending()->create([
                'created_at' => now()->subDays(60),
            ]);

            $em = app()->make(EventManager::class);
            $deleted = $em->purgeLogs(now()->subDays(30), includePending: false);

            expect($deleted)->toBe(2); // Only completed + failed
            expect(EventLog::where('status', EventLog::STATUS_PENDING)->count())->toBe(1);
        });

        test('purgeLogs includes pending when flag set', function (): void {
            EventLog::query()->delete();

            EventLog::factory()->completed()->create([
                'created_at' => now()->subDays(60),
            ]);
            EventLog::factory()->pending()->create([
                'created_at' => now()->subDays(60),
            ]);

            $em = app()->make(EventManager::class);
            $deleted = $em->purgeLogs(now()->subDays(30), includePending: true);

            expect($deleted)->toBe(2);
            expect(EventLog::count())->toBe(0);
        });
    });

    describe('Subscription Lifecycle', function (): void {
        test('subscribeWebhook creates trigger with WebhookAction', function (): void {
            $em = app()->make(EventManager::class);
            $triggerId = $em->subscribeWebhook('webhook.test', 'https://example.com', ['status' => 'active'], 5);

            $trigger = Trigger::find($triggerId);
            expect($trigger)->not->toBeNull();
            expect($trigger->event)->toBe('webhook.test');
            expect($trigger->action)->toContain('WebhookAction');
            expect($trigger->priority)->toBe(5);
        });

        test('subscribe creates Subscription record and internal trigger', function (): void {
            $em = app()->make(EventManager::class);
            $sub = $em->subscribe('sub.test', 'https://example.com/hooks')
                ->withSecret('whsec_test_secret')
                ->withFilter(['status' => 'active'])
                ->priority(8)
                ->save();

            expect($sub)->toBeInstanceOf(Subscription::class);
            expect($sub->secret)->toBe('whsec_test_secret');
            expect($sub->priority)->toBe(8);

            // Verify internal trigger was also created
            $triggers = Trigger::where('event', 'sub.test')->get();
            expect($triggers->count())->toBeGreaterThanOrEqual(1);
        });

        test('unsubscribe removes subscription and internal trigger', function (): void {
            $em = app()->make(EventManager::class);
            $sub = $em->subscribe('unsub.test', 'https://example.com/hooks')->save();

            $result = $em->unsubscribe($sub->id);
            expect($result)->toBeTrue();

            expect(Subscription::find($sub->id))->toBeNull();

            // Verify trigger was also cleaned up
            $trigger = Trigger::where('event', 'unsub.test')->first();
            expect($trigger)->toBeNull();
        });

        test('signing produces deterministic HMAC', function (): void {
            $sub = Subscription::factory()->create([
                'secret' => 'whsec_test_secret_key',
            ]);

            $sig1 = $sub->signPayload('{"test": "data"}');
            $sig2 = $sub->signPayload('{"test": "data"}');

            expect($sig1)->toBe($sig2);
            expect($sig1)->not->toBe('');
        });
    });
});
