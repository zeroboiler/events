<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngine as ConditionEngineImpl;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

describe('Phase 65 — Final production readiness audit', function (): void {
    test('EventManager fire() with empty event name throws InvalidArgumentException', function (): void {
        $manager = app(EventManager::class);

        expect(fn (): mixed => $manager->fire(''))
            ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty');
    });

    test('EventManager fire() with zero-string event name throws InvalidArgumentException', function (): void {
        $manager = app(EventManager::class);

        expect(fn (): mixed => $manager->fire('0'))
            ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty');
    });

    test('EventManager fireModel() with empty model class throws InvalidArgumentException', function (): void {
        $manager = app(EventManager::class);

        expect(fn (): mixed => $manager->fireModel('', 'created', new \stdClass))
            ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty');
    });

    test('EventManager fireModel() with empty action throws InvalidArgumentException', function (): void {
        $manager = app(EventManager::class);

        expect(fn (): mixed => $manager->fireModel('App\\Models\\Order', '', new \stdClass))
            ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty');
    });

    test('TriggerBuilder save() with empty event throws InvalidArgumentException', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->on('');

        expect(fn (): mixed => $builder->action('App\\Actions\\Foo')->save())
            ->toThrow(\InvalidArgumentException::class, 'Event name is required');
    });

    test('TriggerBuilder save() with zero-string event throws InvalidArgumentException', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->on('0');

        expect(fn (): mixed => $builder->action('App\\Actions\\Foo')->save())
            ->toThrow(\InvalidArgumentException::class, 'Event name is required');
    });

    test('TriggerBuilder save() without action throws InvalidArgumentException', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->on('test.event');

        expect(fn (): mixed => $builder->save())
            ->toThrow(\InvalidArgumentException::class, 'At least one action is required');
    });

    test('TriggerBuilder actions() validates all class names are non-empty strings', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->on('test.event');

        expect(fn (): mixed => $builder->actions(['App\\Actions\\Foo', '']))
            ->toThrow(\InvalidArgumentException::class, 'Each action class must be a non-empty string');
    });

    test('TriggerBuilder actions() validates zero-string class names', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->on('test.event');

        expect(fn (): mixed => $builder->actions(['App\\Actions\\Foo', '0']))
            ->toThrow(\InvalidArgumentException::class, 'Each action class must be a non-empty string');
    });

    test('TriggerBuilder actions() validates non-string class names', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->on('test.event');

        expect(fn (): mixed => $builder->actions([123]))
            ->toThrow(\InvalidArgumentException::class, 'Each action class must be a non-empty string');
    });

    test('SubscriptionBuilder save() with empty event throws InvalidArgumentException', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->subscribe('', 'https://example.com/hooks');

        expect(fn (): mixed => $builder->save())
            ->toThrow(\InvalidArgumentException::class, 'Event name is required for subscription');
    });

    test('SubscriptionBuilder save() with empty URL throws InvalidArgumentException', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->subscribe('test.event', '');

        expect(fn (): mixed => $builder->save())
            ->toThrow(\InvalidArgumentException::class, 'Webhook URL is required for subscription');
    });

    test('SubscriptionBuilder save() rejects FTP URLs', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->subscribe('test.event', 'ftp://example.com/hooks');

        expect(fn (): mixed => $builder->save())
            ->toThrow(\InvalidArgumentException::class, 'Webhook URL must use HTTP or HTTPS protocol');
    });

    test('SubscriptionBuilder save() rejects file:// URLs', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->subscribe('test.event', 'file:///tmp/hooks');

        expect(fn (): mixed => $builder->save())
            ->toThrow(\InvalidArgumentException::class, 'Webhook URL must use HTTP or HTTPS protocol');
    });

    test('ActionResolver throws on non-existent class', function (): void {
        $resolver = app(ActionResolver::class);

        expect(fn (): mixed => $resolver->resolve('NonExistent\\Action\\Class'))
            ->toThrow(\InvalidArgumentException::class, 'does not exist');
    });

    test('ActionResolver throws on class that does not implement Triggerable', function (): void {
        $resolver = app(ActionResolver::class);

        expect(fn (): mixed => $resolver->resolve(\stdClass::class))
            ->toThrow(\InvalidArgumentException::class, 'must implement');
    });

    test('DomainEvent fromArray throws on missing eventType', function (): void {
        expect(fn (): mixed => DomainEvent::fromArray([]))
            ->toThrow(\InvalidArgumentException::class, 'eventType is required');
    });

    test('DomainEvent fromArray handles invalid UUID gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'payload' => ['key' => 'value'],
            'eventId' => 'not-a-uuid',
            'occurredAt' => 'not-a-date',
        ]);

        expect($event->eventType)->toBe('test.event');
        expect($event->payload)->toBe(['key' => 'value']);
        // Invalid UUID → fresh UUID generated, invalid date → now
        expect($event->eventId)->not->toBeNull();
        expect($event->occurredAt)->not->toBeNull();
    });

    test('WildcardMatcher does not match empty event with non-empty pattern', function (): void {
        expect(WildcardMatcher::matches('order.placed', ''))->toBeFalse();
        expect(WildcardMatcher::matches('order.*', ''))->toBeFalse();
    });

    test('WildcardMatcher handles backslash in event names', function (): void {
        expect(WildcardMatcher::matches('order\\.placed', 'order.placed'))->toBeFalse();
    });

    test('ConditionEngine matches() returns true for empty conditions', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches([], ['any' => 'data']))->toBeTrue();
    });

    test('ConditionEngine handles empty operator array', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['field' => []], ['field' => 'value']))->toBeFalse();
    });

    test('ConditionEngine not_empty operator', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['field' => ['not_empty']], ['field' => 'value']))->toBeTrue();
        expect($engine->matches(['field' => ['not_empty']], ['field' => '']))->toBeFalse();
        expect($engine->matches(['field' => ['not_empty']], ['field' => 0]))->toBeTrue(); // 0 is not empty in PHP
    });

    test('ConditionEngine not_contains operator', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['field' => ['not_contains', 'bar']], ['field' => 'foobar']))->toBeFalse();
        expect($engine->matches(['field' => ['not_contains', 'bar']], ['field' => 'fooqux']))->toBeTrue();
    });

    test('ConditionEngine strict identity operators (=== and !==)', function (): void {
        $engine = app(ConditionEngine::class);

        // === strict type match
        expect($engine->matches(['field' => ['===', '0']], ['field' => 0]))->toBeFalse();
        expect($engine->matches(['field' => ['===', 0]], ['field' => 0]))->toBeTrue();

        // !== strict type mismatch
        expect($engine->matches(['field' => ['!==', '0']], ['field' => 0]))->toBeTrue();
        expect($engine->matches(['field' => ['!==', 0]], ['field' => 0]))->toBeFalse();
    });

    test('ConditionEngine between() rejects non-array value', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['field' => ['between', 'not-an-array']], ['field' => 50]))->toBeFalse();
    });

    test('ConditionEngine between() with wrong-length array', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['field' => ['between', [50]]], ['field' => 75]))->toBeFalse();
        expect($engine->matches(['field' => ['between', [1, 2, 3]]], ['field' => 2]))->toBeFalse();
    });

    test('ConditionEngine between() normalizes inverted range', function (): void {
        $engine = app(ConditionEngine::class);

        // [100, 50] should be auto-normalized to [50, 100]
        expect($engine->matches(['field' => ['between', [100, 50]]], ['field' => 75]))->toBeTrue();
        expect($engine->matches(['field' => ['between', [100, 50]]], ['field' => 25]))->toBeFalse();
    });

    test('ConditionEngine safeRegexMatch rejects patterns over max length', function (): void {
        $engine = app(ConditionEngine::class);
        $longPattern = '/a' . str_repeat('a', 500) . '/';

        expect($engine->matches(['field' => ['matches', $longPattern]], ['field' => 'test']))->toBeFalse();
    });

    test('ConditionEngine safeRegexMatch rejects nested quantifiers (ReDoS)', function (): void {
        $engine = app(ConditionEngine::class);

        // (a+)+ pattern triggers ReDoS protection
        expect($engine->matches(['field' => ['matches', '/(a+)+/']], ['field' => 'aaa']))->toBeFalse();
    });

    test('ConditionEngine getNestedValue handles non-array current', function (): void {
        $engine = app(ConditionEngine::class);

        // When traversing dot notation and hitting a non-array value
        expect($engine->matches(['user.name' => 'John'], ['user' => 'not-array']))->toBeFalse();
    });

    test('ConditionEngine contains() with array actual', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['normal', 'urgent']]))->toBeTrue();
        expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['normal']]))->toBeFalse();
    });

    test('ConditionEngine contains() with non-string non-array actual', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['field' => ['contains', 'bar']], ['field' => 123]))->toBeFalse();
    });

    test('EventLog status constants are consistent', function (): void {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');

        expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
    });

    test('Trigger model uses UUID string key', function (): void {
        $trigger = Trigger::factory()->create();
        $found = Trigger::find($trigger->id);

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($trigger->id);
        expect($found->getKeyType())->toBe('string');
        expect($found->incrementing)->toBeFalse();
    });

    test('Subscription model uses UUID string key', function (): void {
        $sub = Subscription::factory()->create();
        $found = Subscription::find($sub->id);

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($sub->id);
        expect($found->getKeyType())->toBe('string');
        expect($found->incrementing)->toBeFalse();
    });

    test('EventLog model uses UUID string key', function (): void {
        $trigger = Trigger::factory()->create();
        $log = EventLog::factory()->forTrigger($trigger->id)->create();
        $found = EventLog::find($log->id);

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($log->id);
        expect($found->getKeyType())->toBe('string');
        expect($found->incrementing)->toBeFalse();
    });

    test('Trigger scopeEnabled returns only enabled triggers', function (): void {
        Trigger::factory()->enabled()->create(['event' => 'test.enabled']);
        Trigger::factory()->disabled()->create(['event' => 'test.disabled']);

        $enabled = Trigger::enabled()->get();
        expect($enabled)->toHaveCount(1);
        expect($enabled->first()->event)->toBe('test.enabled');
    });

    test('Subscription scopeActive returns only active subscriptions', function (): void {
        Subscription::factory()->active()->create(['event' => 'test.active']);
        Subscription::factory()->inactive()->create(['event' => 'test.inactive']);

        $active = Subscription::active()->get();
        expect($active)->toHaveCount(1);
        expect($active->first()->event)->toBe('test.active');
    });

    test('Subscription matchesEvent exact match', function (): void {
        $sub = Subscription::factory()->create(['event' => 'order.placed']);

        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.shipped'))->toBeFalse();
    });

    test('Subscription matchesEvent wildcard match', function (): void {
        $sub = Subscription::factory()->create(['event' => 'order.*']);

        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
    });

    test('Subscription matchesEvent cross-segment wildcard', function (): void {
        $sub = Subscription::factory()->create(['event' => 'order.**']);

        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.placed.extra'))->toBeTrue();
    });

    test('Subscription signPayload returns empty for null secret', function (): void {
        $sub = Subscription::factory()->withoutSecret()->create();

        expect($sub->signPayload('test payload'))->toBe('');
    });

    test('Subscription signPayload returns empty for empty secret', function (): void {
        $sub = Subscription::factory()->create(['secret' => '']);

        expect($sub->signPayload('test payload'))->toBe('');
    });

    test('Subscription signPayload returns HMAC for valid secret', function (): void {
        $sub = Subscription::factory()->create(['secret' => 'whsec_test123']);

        $signature = $sub->signPayload('test payload');

        expect($signature)->not->toBeEmpty();
        expect(strlen($signature))->toBe(64); // SHA-256 = 64 hex chars
    });

    test('Subscription hasExceededFailures uses config default', function (): void {
        $sub = Subscription::factory()->withFailureCount(9)->create();

        expect($sub->hasExceededFailures())->toBeFalse(); // Default threshold is 10
    });

    test('Subscription hasExceededFailures with custom max', function (): void {
        $sub = Subscription::factory()->withFailureCount(5)->create();

        expect($sub->hasExceededFailures(5))->toBeTrue();
        expect($sub->hasExceededFailures(6))->toBeFalse();
    });

    test('EventManager enable/disable non-existent trigger returns false', function (): void {
        $manager = app(EventManager::class);

        expect($manager->enable('non-existent-uuid'))->toBeFalse();
        expect($manager->disable('non-existent-uuid'))->toBeFalse();
    });

    test('EventManager deleteTrigger non-existent returns false', function (): void {
        $manager = app(EventManager::class);

        expect($manager->deleteTrigger('non-existent-uuid'))->toBeFalse();
    });

    test('EventManager getTrigger non-existent returns null', function (): void {
        $manager = app(EventManager::class);

        expect($manager->getTrigger('non-existent-uuid'))->toBeNull();
    });

    test('EventManager unsubscribe non-existent returns false', function (): void {
        $manager = app(EventManager::class);

        expect($manager->unsubscribe('non-existent-uuid'))->toBeFalse();
    });

    test('EventManager getSubscription non-existent returns null', function (): void {
        $manager = app(EventManager::class);

        expect($manager->getSubscription('non-existent-uuid'))->toBeNull();
    });

    test('EventManager listTriggers with event filter', function (): void {
        Trigger::factory()->create(['event' => 'order.placed']);
        Trigger::factory()->create(['event' => 'user.created']);

        $manager = app(EventManager::class);
        $triggers = $manager->listTriggers('order.placed');

        expect($triggers)->toHaveCount(1);
        expect($triggers->first()->event)->toBe('order.placed');
    });

    test('EventManager listTriggers with wildcard filter', function (): void {
        Trigger::factory()->create(['event' => 'order.placed']);
        Trigger::factory()->create(['event' => 'order.shipped']);
        Trigger::factory()->create(['event' => 'user.created']);

        $manager = app(EventManager::class);
        $triggers = $manager->listTriggers('order.*');

        expect($triggers)->toHaveCount(2);
    });

    test('EventManager listTriggers with enabled filter', function (): void {
        Trigger::factory()->enabled()->create(['event' => 'a']);
        Trigger::factory()->disabled()->create(['event' => 'b']);

        $manager = app(EventManager::class);
        $triggers = $manager->listTriggers(null, true);

        expect($triggers)->toHaveCount(1);
    });

    test('EventManager isDisabled/setEnabled runtime toggle', function (): void {
        $manager = app(EventManager::class);

        expect($manager->isDisabled())->toBeFalse();

        $manager->setEnabled(false);
        expect($manager->isDisabled())->toBeTrue();

        $manager->setEnabled(true);
        expect($manager->isDisabled())->toBeFalse();
    });

    test('EventManager fire returns silently when disabled', function (): void {
        $manager = app(EventManager::class);
        $manager->setEnabled(false);

        // Should not throw — silently returns
        $manager->fire('test.event', ['key' => 'value']);

        // No triggers should have been created
        expect(EventLog::count())->toBe(0);

        $manager->setEnabled(true);
    });

    test('WildcardMatcher findMatchingPatterns preserves order', function (): void {
        $patterns = ['user.*.created', 'order.*', '*.deleted', '**'];
        $matching = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($matching)->toBe(['order.*', '**']);
    });

    test('WildcardMatcher extractWildcards returns correct values', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

        expect($result)->toBe(['profile']);
    });

    test('WildcardMatcher extractWildcards returns empty for non-matching events', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'order.placed');

        expect($result)->toBe([]);
    });

    test('WildcardMatcher extractWildcards returns empty for cross-segment patterns', function (): void {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

        expect($result)->toBe([]);
    });

    test('DomainEvent occur factory method creates fresh UUID', function (): void {
        $e1 = DomainEvent::occur('test.event');
        $e2 = DomainEvent::occur('test.event');

        expect($e1->eventId->toString())->not->toBe($e2->eventId->toString());
    });

    test('DomainEvent toArray/fromArray roundtrip preserves all fields', function (): void {
        $original = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
        $restored = DomainEvent::fromArray($original->toArray());

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->eventType)->toBe($original->eventType);
        expect($restored->payload)->toBe($original->payload);
        expect($restored->occurredAt->format(\DateTimeInterface::ATOM))
            ->toBe($original->occurredAt->format(\DateTimeInterface::ATOM));
    });

    test('EscapesWildcardLike converts asterisks to percent signs', function (): void {
        $manager = app(EventManager::class);

        // We use reflection to test the protected method
        $ref = new ReflectionMethod($manager, 'wildcardToLike');
        $ref->setAccessible(true);

        expect($ref->invoke($manager, 'order.*'))->toBe('order.%');
        expect($ref->invoke($manager, 'order.**'))->toBe('order.%%');
        expect($ref->invoke($manager, 'order.placed'))->toBeNull();
    });

    test('EscapesWildcardLike escapes SQL special chars', function (): void {
        $manager = app(EventManager::class);

        $ref = new ReflectionMethod($manager, 'wildcardToLike');
        $ref->setAccessible(true);

        expect($ref->invoke($manager, 'test.%'))->toBe('test.\\%');
        expect($ref->invoke($manager, 'test._*'))->toBe('test.\\_%');
    });

    test('ServiceProvider registers all bindings correctly', function (): void {
        $app = app();

        // Singletons
        $ce1 = $app->make(ConditionEngine::class);
        $ce2 = $app->make(ConditionEngine::class);
        expect($ce1)->toBe($ce2); // Same instance (singleton)

        $ar1 = $app->make(ActionResolver::class);
        $ar2 = $app->make(ActionResolver::class);
        expect($ar1)->toBe($ar2); // Same instance (singleton)

        // Contract binding
        $contract = $app->make(ConditionEngineContract::class);
        expect($contract)->toBeInstanceOf(ConditionEngine::class);

        // Transient bindings
        $tb1 = $app->make(TriggerBuilder::class);
        $tb2 = $app->make(TriggerBuilder::class);
        expect($tb1)->not->toBe($tb2); // Different instances (transient)

        $sb1 = $app->make(SubscriptionBuilder::class);
        $sb2 = $app->make(SubscriptionBuilder::class);
        expect($sb1)->not->toBe($sb2); // Different instances (transient)
    });

    test('Config contains all required keys', function (): void {
        $config = config('events');

        expect($config)->toBeArray();
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

    test('All source files have declare(strict_types=1)', function (): void {
        $srcDir = __DIR__.'/../src';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $violations = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $tokens = token_get_all($contents);
            $foundStrict = false;

            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_DECLARE) {
                    $foundStrict = true;
                    break;
                }
            }

            if (! $foundStrict) {
                $violations[] = $file->getPathname();
            }
        }

        expect($violations)->toBeEmpty(
            'Files missing declare(strict_types=1): '.implode(', ', $violations),
        );
    });

    test('All final classes are actually declared final', function (): void {
        $classes = [
            EventManager::class,
            ConditionEngine::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            WildcardMatcher::class,
            ActionResolver::class,
            EventsServiceProvider::class,
            DomainEvent::class,
        ];

        foreach ($classes as $class) {
            $ref = new ReflectionClass($class);
            expect($ref->isFinal())->toBeTrue("{$class} should be declared final");
        }
    });

    test('All console commands extend Illuminate\\Console\\Command and are final', function (): void {
        $commandDir = __DIR__.'/../src/Console';
        $files = glob($commandDir.'/*.php');

        foreach ($files as $file) {
            $ref = new ReflectionClass(require $file);

            expect($ref->isFinal())->toBeTrue(basename($file).' should be final');
            expect($ref->isSubclassOf(\Illuminate\Console\Command::class))->toBeTrue(
                basename($file).' should extend Illuminate\\Console\\Command',
            );
        }
    });

    test('Composer.json autoload PSR-4 matches actual directory structure', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        $autoload = $composer['autoload']['psr-4']['ZeroBoiler\\Events\\'] ?? '';
        expect($autoload)->toBe('src/');

        $devAutoload = $composer['autoload-dev']['psr-4'] ?? [];
        expect($devAutoload)->toHaveKey('ZeroBoiler\\Events\\Tests\\');
        expect($devAutoload)->toHaveKey('ZeroBoiler\\Events\\Database\\Factories\\');
    });

    test('getStats returns correct structure with zero data', function (): void {
        $manager = app(EventManager::class);
        $stats = $manager->getStats();

        expect($stats)->toBeArray();
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

        expect($stats['total_logs'])->toBe(0);
        expect($stats['success_rate'])->toBeNull();
        expect($stats['failure_rate'])->toBeNull();
        expect($stats['avg_duration_ms'])->toBeNull();
        expect($stats['top_events'])->toBeArray();
        expect($stats['top_failed_events'])->toBeArray();
    });

    test('purgeLogs deletes completed logs older than threshold', function (): void {
        $trigger = Trigger::factory()->create();
        $log = EventLog::factory()->completed()->forTrigger($trigger->id)->create();

        // Move the log back in time
        $log->update(['created_at' => now()->subDays(60)]);

        $manager = app(EventManager::class);
        $deleted = $manager->purgeLogs(now()->subDays(30), includePending: false);

        expect($deleted)->toBe(1);
        expect(EventLog::find($log->id))->toBeNull();
    });

    test('purgeLogs skips pending logs when includePending is false', function (): void {
        $trigger = Trigger::factory()->create();
        $log = EventLog::factory()->pending()->forTrigger($trigger->id)->create();
        $log->update(['created_at' => now()->subDays(60)]);

        $manager = app(EventManager::class);
        $deleted = $manager->purgeLogs(now()->subDays(30), includePending: false);

        expect($deleted)->toBe(0);
        expect(EventLog::find($log->id))->not->toBeNull();
    });

    test('purgeLogs deletes pending logs when includePending is true', function (): void {
        $trigger = Trigger::factory()->create();
        $log = EventLog::factory()->pending()->forTrigger($trigger->id)->create();
        $log->update(['created_at' => now()->subDays(60)]);

        $manager = app(EventManager::class);
        $deleted = $manager->purgeLogs(now()->subDays(30), includePending: true);

        expect($deleted)->toBe(1);
        expect(EventLog::find($log->id))->toBeNull();
    });

    test('deactivateExceededSubscriptions deactivates exceeded subscriptions', function (): void {
        // Create a subscription with failure count = max_failures (10)
        $sub = Subscription::factory()->active()->withFailureCount(10)->create();

        $manager = app(EventManager::class);
        $count = $manager->deactivateExceededSubscriptions();

        expect($count)->toBe(1);
        $sub->refresh();
        expect($sub->active)->toBeFalse();
    });

    test('getStalePendingLogs returns only old pending logs', function (): void {
        $trigger = Trigger::factory()->create();

        // Fresh pending log
        $fresh = EventLog::factory()->pending()->forTrigger($trigger->id)->create();
        // Old pending log
        $stale = EventLog::factory()->pending()->forTrigger($trigger->id)->create();
        $stale->update(['created_at' => now()->subHours(25)]);

        $manager = app(EventManager::class);
        $staleLogs = $manager->getStalePendingLogs(now()->subHours(24));

        expect($staleLogs)->toHaveCount(1);
        expect($staleLogs->first()->id)->toBe($stale->id);
    });
});
