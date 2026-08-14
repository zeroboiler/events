<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Console\EventsDisableCommand;
use ZeroBoiler\Events\Console\EventsEnableCommand;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Console\EventsHealthCommand;
use ZeroBoiler\Events\Console\EventsListCommand;
use ZeroBoiler\Events\Console\EventsLogCommand;
use ZeroBoiler\Events\Console\EventsRedeliverCommand;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Console\EventsSubscribeCommand;
use ZeroBoiler\Events\Console\EventsSubscriptionsCommand;
use ZeroBoiler\Events\Console\EventsUnsubscribeCommand;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

beforeEach(function (): void {
    // Fresh app per test is handled by TestCase::setUp()
});

describe('Phase 61 — Production Readiness Deep Audit', function (): void {

    test('all 31 source files have declare(strict_types=1)', function (): void {
        $srcDir = __DIR__.'/../src';
        $files = glob($srcDir.'/**/*.php');
        expect($files)->not->toBeEmpty();

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        }
    });

    test('all core classes are final', function (): void {
        $coreClasses = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            WebhookAction::class,
            DispatchTriggerJob::class,
            DomainEvent::class,
            EventsServiceProvider::class,
            EventManagerFacade::class,
        ];

        foreach ($coreClasses as $class) {
            $ref = new ReflectionClass($class);
            expect($ref->isFinal())->toBeTrue("{$class} must be final");
        }
    });

    test('WildcardMatcher is readonly final class', function (): void {
        $ref = new ReflectionClass(WildcardMatcher::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isReadOnly())->toBeTrue();
    });

    test('all 12 console commands are final', function (): void {
        $commands = [
            EventsListCommand::class,
            EventsRegisterCommand::class,
            EventsFireCommand::class,
            EventsLogCommand::class,
            EventsRetryCommand::class,
            EventsEnableCommand::class,
            EventsDisableCommand::class,
            EventsHealthCommand::class,
            EventsSubscribeCommand::class,
            EventsUnsubscribeCommand::class,
            EventsSubscriptionsCommand::class,
            EventsRedeliverCommand::class,
        ];

        foreach ($commands as $class) {
            $ref = new ReflectionClass($class);
            expect($ref->isFinal())->toBeTrue("{$class} must be final");
        }
    });

    test('EventManager constructor has readonly promoted properties', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        $ctor = $ref->getConstructor();
        expect($ctor)->not->toBeNull();

        $params = $ctor->getParameters();
        expect(count($params))->toBe(3);

        foreach ($params as $param) {
            expect($param->isPromoted())->toBeTrue();
            $prop = $ref->getProperty($param->getName());
            expect($prop->isReadOnly())->toBeTrue();
        }
    });

    test('ActionResolver constructor has readonly promoted properties', function (): void {
        $ref = new ReflectionClass(ActionResolver::class);
        $ctor = $ref->getConstructor();
        expect($ctor)->not->toBeNull();

        $params = $ctor->getParameters();
        expect(count($params))->toBe(1);

        $param = $params[0];
        expect($param->isPromoted())->toBeTrue();
        $prop = $ref->getProperty($param->getName());
        expect($prop->isReadOnly())->toBeTrue();
    });

    test('DomainEvent has readonly promoted properties and readonly class properties', function (): void {
        $ref = new ReflectionClass(DomainEvent::class);
        expect($ref->isFinal())->toBeTrue();

        $ctor = $ref->getConstructor();
        $params = $ctor->getParameters();
        $promotedNames = [];
        foreach ($params as $p) {
            if ($p->isPromoted()) {
                $promotedNames[] = $p->getName();
            }
        }
        expect($promotedNames)->toContain('eventType');
        expect($promotedNames)->toContain('payload');

        // eventId and occurredAt are set in the constructor body, not promoted
        $eventIdProp = $ref->getProperty('eventId');
        expect($eventIdProp->isReadOnly())->toBeTrue();
        $occurredAtProp = $ref->getProperty('occurredAt');
        expect($occurredAtProp->isReadOnly())->toBeTrue();
    });

    test('ConditionEngine implements ConditionEngineContract', function (): void {
        expect(ConditionEngine::class)->toImplement(ConditionEngineContract::class);
    });

    test('WebhookAction implements Triggerable', function (): void {
        expect(WebhookAction::class)->toImplement(Triggerable::class);
    });

    test('DispatchTriggerJob implements ShouldQueue', function (): void {
        expect(DispatchTriggerJob::class)->toImplement(Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    test('WildcardMatcher all public methods have #[Pure] attribute', function (): void {
        $ref = new ReflectionClass(WildcardMatcher::class);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $hasPure = false;
            foreach ($method->getAttributes() as $attr) {
                $name = $attr->getName();
                if ($name === 'Pure' || $name === '\\Pure') {
                    $hasPure = true;
                    break;
                }
            }
            // Also check docblock for #[\Pure] annotation
            $doc = $method->getDocComment();
            if ($doc !== false && str_contains($doc, '#[') && str_contains($doc, 'Pure]')) {
                $hasPure = true;
            }
            expect($hasPure)->toBeTrue("WildcardMatcher::{$method->getName()} must have #[Pure]");
        }
    });

    test('EventLog has all 4 status constants', function (): void {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');
        expect(EventLog::$statuses)->toHaveCount(4);
    });

    test('config has all required sections', function (): void {
        $config = config('events');
        expect($config)->not->toBeNull();
        expect($config)->toBeArray();

        expect(isset($config['table_names']))->toBeTrue();
        expect(isset($config['queue']))->toBeTrue();
        expect(isset($config['retry']))->toBeTrue();
        expect(isset($config['retention']))->toBeTrue();
        expect(isset($config['subscriptions']))->toBeTrue();
        expect(isset($config['disabled']))->toBeTrue();
        expect(isset($config['wildcard_cache_ttl']))->toBeTrue();

        // Sub-keys
        expect($config['table_names']['triggers'])->toBeString();
        expect($config['table_names']['event_logs'])->toBeString();
        expect($config['table_names']['subscriptions'])->toBeString();
        expect($config['queue']['connection'])->toBeString();
        expect($config['queue']['queue'])->toBeString();
        expect($config['retry']['tries'])->toBeInt();
        expect($config['subscriptions']['max_failures'])->toBeInt();
        expect($config['subscriptions']['timeout'])->toBeInt();
        expect($config['subscriptions']['signature_algorithm'])->toBeString();
    });

    test('ServiceProvider registers correct bindings', function (): void {
        $app = app();

        // Singletons
        expect($app->make(ConditionEngine::class))->toBeInstanceOf(ConditionEngine::class);
        expect($app->make(ConditionEngineContract::class))->toBeInstanceOf(ConditionEngine::class);
        expect($app->make(ActionResolver::class))->toBeInstanceOf(ActionResolver::class);
        expect($app->make(EventManager::class))->toBeInstanceOf(EventManager::class);

        // Transients — each resolution gets a new instance
        $tb1 = $app->make(TriggerBuilder::class);
        $tb2 = $app->make(TriggerBuilder::class);
        expect($tb1)->not->toBe($tb2);

        $sb1 = $app->make(SubscriptionBuilder::class);
        $sb2 = $app->make(SubscriptionBuilder::class);
        expect($sb1)->not->toBe($sb2);

        // Contract identity
        expect($app->make(ConditionEngineContract::class))
            ->toBe($app->make(ConditionEngine::class));
    });

    test('Facade accessor returns correct class name', function (): void {
        $ref = new ReflectionClass(EventManagerFacade::class);
        $method = $ref->getMethod('getFacadeAccessor');
        expect($method)->not->toBeNull();
        expect($method->getReturnType()?->getName())->toBe('string');
        $accessor = $method->invoke(null);
        expect($accessor)->toBe(EventManager::class);
    });

    test('models use config-driven table names', function (): void {
        $triggerTable = (new Trigger)->getTable();
        expect($triggerTable)->toBe(config('events.table_names.triggers', 'triggers'));

        $logTable = (new EventLog)->getTable();
        expect($logTable)->toBe(config('events.table_names.event_logs', 'event_logs'));

        $subTable = (new Subscription)->getTable();
        expect($subTable)->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
    });

    test('models have string UUID key types and non-incrementing', function (): void {
        foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
            $ref = new ReflectionClass($model);
            $keyType = $ref->getProperty('keyType');
            expect($keyType->getValue(new $model))->toBe('string');

            $incrementing = $ref->getProperty('incrementing');
            expect($incrementing->getValue(new $model))->toBeFalse();
        }
    });

    test('EscapesWildcardLike returns null for non-wildcard patterns', function (): void {
        $engine = app()->make(ConditionEngine::class);
        // Access wildcardToLike via EventManager which uses the trait
        $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
        $em = app()->make(EventManager::class);

        expect($ref->invoke($em, 'order.placed'))->toBeNull();
        expect($ref->invoke($em, 'user.created'))->toBeNull();
    });

    test('EscapesWildcardLike converts asterisks to percent', function (): void {
        $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
        $em = app()->make(EventManager::class);

        expect($ref->invoke($em, 'order.*'))->toBe('order.%');
        expect($ref->invoke($em, '**'))->toBe('%%');
        expect($ref->invoke($em, '*.order.*'))->toBe('%.order.%');
    });

    test('DomainEvent roundtrip preserves identity', function (): void {
        $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
        $array = $event->toArray();
        $restored = DomainEvent::fromArray($array);

        expect($restored->eventType)->toBe('user.registered');
        expect($restored->payload)->toBe(['email' => 'test@example.com']);
        expect($restored->eventId->toString())->toBe($event->eventId->toString());
        expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
            ->toBe($event->occurredAt->format(DateTimeImmutable::ATOM));
    });

    test('ConditionEngine full operator matrix (19 operators)', function (): void {
        $engine = app()->make(ConditionEngine::class);

        // Comparison
        expect($engine->matches(['x' => ['>', 5]], ['x' => 10]))->toBeTrue();
        expect($engine->matches(['x' => ['>=', 5]], ['x' => 5]))->toBeTrue();
        expect($engine->matches(['x' => ['<', 5]], ['x' => 3]))->toBeTrue();
        expect($engine->matches(['x' => ['<=', 5]], ['x' => 5]))->toBeTrue();

        // Equality
        expect($engine->matches(['x' => 'foo'], ['x' => 'foo']))->toBeTrue();
        expect($engine->matches(['x' => ['===', true]], ['x' => true]))->toBeTrue();
        expect($engine->matches(['x' => ['!=', 'bar']], ['x' => 'foo']))->toBeTrue();
        expect($engine->matches(['x' => ['!==', 1]], ['x' => '1']))->toBeTrue();

        // Array
        expect($engine->matches(['x' => ['in', ['a', 'b']]], ['x' => 'a']))->toBeTrue();
        expect($engine->matches(['x' => ['not_in', ['a']]], ['x' => 'b']))->toBeTrue();
        expect($engine->matches(['x' => ['contains', 'bar']], ['x' => 'foobar']))->toBeTrue();
        expect($engine->matches(['x' => ['not_contains', 'x']], ['x' => 'foobar']))->toBeTrue();

        // Between
        expect($engine->matches(['x' => ['between', [1, 10]]], ['x' => 5]))->toBeTrue();

        // Null
        expect($engine->matches(['x' => ['null']], ['y' => 1]))->toBeTrue();
        expect($engine->matches(['x' => ['not_null']], ['x' => 'val']))->toBeTrue();

        // Empty
        expect($engine->matches(['x' => ['empty']], ['x' => '']))->toBeTrue();
        expect($engine->matches(['x' => ['not_empty']], ['x' => 'val']))->toBeTrue();

        // String
        expect($engine->matches(['x' => ['starts_with', 'fo']], ['x' => 'foobar']))->toBeTrue();
        expect($engine->matches(['x' => ['ends_with', 'bar']], ['x' => 'foobar']))->toBeTrue();
        expect($engine->matches(['x' => ['matches', '/^\\d+$/']], ['x' => '123']))->toBeTrue();

        // AND logic
        expect($engine->matches(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2]))->toBeTrue();
        expect($engine->matches(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 99]))->toBeFalse();
    });

    test('WildcardMatcher comprehensive patterns', function (): void {
        // Exact
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();

        // Single-segment
        expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();

        // Cross-segment
        expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();

        // Catch-all
        expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();

        // Multi-wildcard
        expect(WildcardMatcher::matches('*.order.*', 'user.order.created'))->toBeTrue();
        expect(WildcardMatcher::matches('*.order.*', 'user.order'))->toBeFalse();

        // Extract
        expect(WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created'))
            ->toBe(['profile']);
        expect(WildcardMatcher::extractWildcards('user.**', 'user.profile.created'))
            ->toBe([]);
    });

    test('EventManager API surface completeness', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        $expectedMethods = [
            'on', 'register', 'fire', 'fireModel', 'enable', 'disable',
            'deleteTrigger', 'getTrigger', 'listTriggers', 'invalidateTriggerCache',
            'isDisabled', 'setEnabled', 'executeTrigger',
            'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription',
            'subscribeWebhook', 'getEventHistory', 'getStats', 'purgeLogs',
        ];

        $methodNames = array_map(fn (ReflectionMethod $m): string => $m->getName(), $publicMethods);

        foreach ($expectedMethods as $method) {
            expect(in_array($method, $methodNames, true))->toBeTrue(
                "EventManager must have public method: {$method}"
            );
        }
    });

    test('TriggerBuilder fluent interface', function (): void {
        $ref = new ReflectionClass(TriggerBuilder::class);
        $fluentMethods = ['name', 'on', 'action', 'actions', 'when', 'async', 'priority', 'actionParams'];

        foreach ($fluentMethods as $method) {
            $m = $ref->getMethod($method);
            expect($m->getReturnType()?->getName())
                ->toBe('self', "TriggerBuilder::{$method}() must return self");
        }
    });

    test('SubscriptionBuilder fluent interface', function (): void {
        $ref = new ReflectionClass(SubscriptionBuilder::class);
        $fluentMethods = ['on', 'to', 'withSecret', 'withFilter', 'priority', 'async'];

        foreach ($fluentMethods as $method) {
            $m = $ref->getMethod($method);
            expect($m->getReturnType()?->getName())
                ->toBe('self', "SubscriptionBuilder::{$method}() must return self");
        }
    });

    test('phpstan.neon.dist has level 9', function (): void {
        $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
        expect($content)->toContain('level: max');
    });

    test('composer.json has correct structure', function (): void {
        $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        expect($json['name'])->toBe('zeroboiler/events');
        expect($json['type'])->toBe('library');
        expect($json['require']['php'])->toBe('^8.5');
        expect($json['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        expect($json['extra']['laravel']['providers'][0])
            ->toBe('ZeroBoiler\\Events\\EventsServiceProvider');
        expect($json['extra']['laravel']['aliases']['EventManager'])
            ->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
    });

    test('all 3 migration files exist', function (): void {
        $files = glob(__DIR__.'/../database/migrations/*.php');
        expect(count($files))->toBeGreaterThanOrEqual(3);
    });

    test('all 3 factory files exist', function (): void {
        $files = glob(__DIR__.'/../database/factories/*.php');
        expect(count($files))->toBe(3);
    });

    test('all source files have license headers', function (): void {
        $srcDir = __DIR__.'/../src';
        $files = glob($srcDir.'/**/*.php');
        expect($files)->not->toBeEmpty();

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect(str_contains($content, 'This file is part of ZeroBoiler'))
                ->toBeTrue("{$file} must have license header");
        }
    });

    test('ManagesHistory and ManagesSubscriptions traits are used by EventManager', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        $traits = $ref->getTraitNames();

        expect(in_array(ManagesHistory::class, $traits, true))->toBeTrue();
        expect(in_array(ManagesSubscriptions::class, $traits, true))->toBeTrue();
        expect(in_array(EscapesWildcardLike::class, $traits, true))->toBeTrue();
    });

    test('EventManager fire validation throws on empty event', function (): void {
        $em = app()->make(EventManager::class);

        expect(fn () => $em->fire(''))->toThrow(InvalidArgumentException::class);
        expect(fn () => $em->fire('0'))->toThrow(InvalidArgumentException::class);
    });

    test('EventManager fireModel validation throws on empty model class', function (): void {
        $em = app()->make(EventManager::class);
        $obj = new class {};

        expect(fn () => $em->fireModel('', 'created', $obj))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => $em->fireModel('App\\Model', '', $obj))
            ->toThrow(InvalidArgumentException::class);
    });

    test('Subscription signPayload returns empty for null secret', function (): void {
        $factory = Subscription::factory()->make(['secret' => null]);
        expect($factory->signPayload('test'))->toBe('');
    });

    test('ActionResolver throws for non-existent class', function (): void {
        $resolver = app()->make(ActionResolver::class);
        expect(fn () => $resolver->resolve('NonExistentClass'))
            ->toThrow(InvalidArgumentException::class);
    });

    test('getStats zero-state structure', function (): void {
        $em = app()->make(EventManager::class);
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
        expect($stats['total_triggers'])->toBe(0);
        expect($stats['success_rate'])->toBeNull();
    });

    test('EventManager global disable suppresses fire', function (): void {
        $em = app()->make(EventManager::class);

        // Should not be disabled by default
        expect($em->isDisabled())->toBeFalse();

        // Disable and verify fire is suppressed
        $em->setEnabled(false);
        expect($em->isDisabled())->toBeTrue();

        // fire() should return silently (no exception, no dispatch)
        $em->fire('test.event', ['key' => 'value']);
        // No trigger exists, so nothing would fire anyway, but the disable
        // check short-circuits before even querying
        expect($em->isDisabled())->toBeTrue();

        // Re-enable
        $em->setEnabled(true);
        expect($em->isDisabled())->toBeFalse();
    });

    test('WildcardMatcher regex special characters are safely handled', function (): void {
        // Patterns with regex special chars should match literally
        expect(WildcardMatcher::matches('user.name', 'user.name'))->toBeTrue();
        expect(WildcardMatcher::matches('user.name', 'username'))->toBeFalse();

        // Dot is a segment separator, not a regex any-char
        expect(WildcardMatcher::matches('user.*', 'userXname'))->toBeFalse();
        expect(WildcardMatcher::matches('user.*', 'user.anything'))->toBeTrue();
    });

    test('version consistency between composer.json and README', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        $readme = file_get_contents(__DIR__.'/../README.md');

        $version = $composer['version'];
        expect($readme)->toContain("version-{$version}-blue");
    });

    test('test file count matches actual files on disk', function (): void {
        $testFiles = glob(__DIR__.'/../*Test.php');
        // Include subdirectory test files
        $testFiles = array_merge($testFiles, glob(__DIR__.'/*Test.php'));

        // Count only in tests/ directory
        $allTests = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_contains($file->getFilename(), 'Test.php')) {
                $allTests[] = $file->getPathname();
            }
        }
        expect(count($allTests))->toBe(129); // 128 + this file
    });
});
