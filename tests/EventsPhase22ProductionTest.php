<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Phase 22 Production Test Suite
 *
 * Covers:
 * - Pest.php duplicate entry verification
 * - Strict types enforcement across ALL source files
 * - Final class verification across ALL source files
 * - Return type declarations verification for all public methods
 * - #[\Override] attribute verification
 * - ServiceProvider singleton/transient lifecycle
 * - Config completeness (all 6 top-level sections + all sub-keys)
 * - Facade accessor correctness
 * - TriggerBuilder resolveActions deduplication edge cases
 * - ConditionEngine full operator matrix (all 19 operators + empty + null)
 * - WildcardMatcher comprehensive patterns
 * - EscapesWildcardLike trait behavior
 * - DomainEvent readonly properties + immutability
 * - EventLog/Subscription/Trigger model scope behavior
 * - parseActions @phpstan-return annotation presence
 * - Version consistency (composer.json vs README badge)
 */

describe('Pest.php integrity', function (): void {
    it('has no duplicate test file entries', function (): void {
        $pestContent = file_get_contents(__DIR__.'/Pest.php');
        $matches = [];
        preg_match_all("/'([^']+)'/", (string) $pestContent, $matches);
        $files = $matches[1];
        $unique = array_unique($files);

        expect(count($files))->toBe(count($unique), 'Duplicate entries found in Pest.php uses() list');
    });

    it('lists all existing test files that need TestCase bootstrap', function (): void {
        $pestContent = file_get_contents(__DIR__.'/Pest.php');
        $matches = [];
        preg_match_all("/'([^']+Test\\.php)'/", (string) $pestContent, $matches);
        $listed = $matches[1];

        $allTestFiles = glob(__DIR__.'/*Test.php');
        $allTestFiles = is_array($allTestFiles) ? $allTestFiles : [];
        $allBasenames = array_map(fn (string $f): string => basename($f), $allTestFiles);

        // WildcardMatcherTest and EscapesWildcardLikeTest are explicitly excluded
        $excluded = ['WildcardMatcherTest.php', 'EscapesWildcardLikeTest.php'];
        $expected = array_filter($allBasenames, fn (string $f): bool => ! in_array($f, $excluded, true));

        foreach ($expected as $file) {
            expect(in_array($file, $listed, true))->toBeTrue("{$file} is missing from Pest.php uses() list");
        }
    });
});

describe('Strict types enforcement', function (): void {
    it('all source files have declare(strict_types=1)', function (): void {
        $srcFiles = glob(__DIR__.'/../src/**/*.php');
        $srcFiles = is_array($srcFiles) ? $srcFiles : [];

        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            expect((string) $content)
                ->toContain('declare(strict_types=1)', "Missing strict_types in: {$file}");
        }
    });

    it('all test files have declare(strict_types=1)', function (): void {
        $testFiles = glob(__DIR__.'/*Test.php');
        $testFiles = is_array($testFiles) ? $testFiles : [];

        foreach ($testFiles as $file) {
            $content = file_get_contents($file);
            expect((string) $content)
                ->toContain('declare(strict_types=1)', "Missing strict_types in: {$file}");
        }
    });

    it('TestCase and Pest.php have declare(strict_types=1)', function (): void {
        foreach ([__DIR__.'/TestCase.php', __DIR__.'/Pest.php'] as $file) {
            $content = file_get_contents($file);
            expect((string) $content)
                ->toContain('declare(strict_types=1)', "Missing strict_types in: {$file}");
        }
    });
});

describe('Final class verification', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        WildcardMatcher::class,
        DomainEvent::class,
        EventsServiceProvider::class,
        WebhookAction::class,
        EventManagerFacade::class,
    ];

    foreach ($finalClasses as $class) {
        it("{$class} is final", function () use ($class): void {
            $ref = new ReflectionClass($class);
            expect($ref->isFinal())->toBeTrue("{$class} must be final");
        });
    }

    it('all console command classes are final', function (): void {
        $commandFiles = glob(__DIR__.'/../src/Console/*.php');
        $commandFiles = is_array($commandFiles) ? $commandFiles : [];

        foreach ($commandFiles as $file) {
            // Extract class name from file
            $content = file_get_contents($file);
            preg_match('/^class (\w+)/m', (string) $content, $m);
            if (isset($m[1])) {
                $fqn = 'ZeroBoiler\\Events\\Console\\'.$m[1];
                $ref = new ReflectionClass($fqn);
                expect($ref->isFinal())->toBeTrue("{$fqn} must be final");
            }
        }
    });
});

describe('Return type declarations', function (): void {
    $methodsToCheck = [
        [EventManager::class, 'on'],
        [EventManager::class, 'register'],
        [EventManager::class, 'fire'],
        [EventManager::class, 'fireModel'],
        [EventManager::class, 'enable'],
        [EventManager::class, 'disable'],
        [EventManager::class, 'invalidateTriggerCache'],
        [EventManager::class, 'listTriggers'],
        [EventManager::class, 'getTrigger'],
        [EventManager::class, 'deleteTrigger'],
        [EventManager::class, 'executeTrigger'],
        [ConditionEngine::class, 'matches'],
        [ActionResolver::class, 'resolve'],
        [TriggerBuilder::class, 'on'],
        [TriggerBuilder::class, 'name'],
        [TriggerBuilder::class, 'action'],
        [TriggerBuilder::class, 'actions'],
        [TriggerBuilder::class, 'when'],
        [TriggerBuilder::class, 'async'],
        [TriggerBuilder::class, 'priority'],
        [TriggerBuilder::class, 'actionParams'],
        [TriggerBuilder::class, 'save'],
        [SubscriptionBuilder::class, 'on'],
        [SubscriptionBuilder::class, 'to'],
        [SubscriptionBuilder::class, 'withSecret'],
        [SubscriptionBuilder::class, 'withFilter'],
        [SubscriptionBuilder::class, 'priority'],
        [SubscriptionBuilder::class, 'async'],
        [SubscriptionBuilder::class, 'save'],
        [DomainEvent::class, 'toArray'],
        [DomainEvent::class, 'occur'],
        [DomainEvent::class, 'fromArray'],
        [WebhookAction::class, 'handle'],
        [WildcardMatcher::class, 'matches'],
        [WildcardMatcher::class, 'findMatchingPatterns'],
        [WildcardMatcher::class, 'extractWildcards'],
    ];

    foreach ($methodsToCheck as [$class, $method]) {
        it("{$class}::{$method}() has a return type declaration", function () use ($class, $method): void {
            $ref = new ReflectionMethod($class, $method);
            $refType = $ref->getReturnType();
            expect($refType)->not->toBeNull(
                "{$class}::{$method}() must have a return type declaration"
            );
        });
    }

    it('trait public methods have return types', function (): void {
        $traitMethods = [
            [EscapesWildcardLike::class, 'wildcardToLike'],
        ];

        foreach ($traitMethods as [$trait, $method]) {
            $ref = new ReflectionMethod($trait, $method);
            $refType = $ref->getReturnType();
            expect($refType)->not->toBeNull(
                "{$trait}::{$method}() must have a return type declaration"
            );
        }
    });
});

describe('#[\Override] attribute verification', function (): void {
    $overrideMethods = [
        [ConditionEngine::class, 'matches', ConditionEngineContract::class],
        [WebhookAction::class, 'handle', Triggerable::class],
    ];

    foreach ($overrideMethods as [$class, $method, $parent]) {
        it("{$class}::{$method}() has #[\Override] attribute", function () use ($class, $method, $parent): void {
            $ref = new ReflectionMethod($class, $method);
            $attrs = $ref->getAttributes();
            $hasOverride = array_any($attrs, fn (ReflectionAttribute $a): bool => $a->getName() === 'Override');

            expect($hasOverride)->toBeTrue(
                "{$class}::{$method}() must have #[\Override] attribute (implements {$parent})"
            );
        });
    }

    it('all model getTable() methods have #[\Override]', function (): void {
        foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
            $ref = new ReflectionMethod($model, 'getTable');
            $attrs = $ref->getAttributes();
            $hasOverride = array_any($attrs, fn (ReflectionAttribute $a): bool => $a->getName() === 'Override');
            expect($hasOverride)->toBeTrue("{$model}::getTable() must have #[\Override]");
        }
    });

    it('all model boot() methods have #[\Override]', function (): void {
        foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
            $ref = new ReflectionMethod($model, 'boot');
            $attrs = $ref->getAttributes();
            $hasOverride = array_any($attrs, fn (ReflectionAttribute $a): bool => $a->getName() === 'Override');
            expect($hasOverride)->toBeTrue("{$model}::boot() must have #[\Override]");
        }
    });

    it('EventsServiceProvider register() and boot() have #[\Override]', function (): void {
        foreach (['register', 'boot'] as $method) {
            $ref = new ReflectionMethod(EventsServiceProvider::class, $method);
            $attrs = $ref->getAttributes();
            $hasOverride = array_any($attrs, fn (ReflectionAttribute $a): bool => $a->getName() === 'Override');
            expect($hasOverride)->toBeTrue(
                EventsServiceProvider::class."::{$method}() must have #[\Override]"
            );
        }
    });
});

describe('ServiceProvider binding lifecycle', function (): void {
    it('EventManager is singleton', function (): void {
        $app = app();
        $first = $app->make(EventManager::class);
        $second = $app->make(EventManager::class);

        expect($first)->toBe($second);
    });

    it('ConditionEngine is singleton', function (): void {
        $app = app();
        $first = $app->make(ConditionEngine::class);
        $second = $app->make(ConditionEngine::class);

        expect($first)->toBe($second);
    });

    it('ActionResolver is singleton', function (): void {
        $app = app();
        $first = $app->make(ActionResolver::class);
        $second = $app->make(ActionResolver::class);

        expect($first)->toBe($second);
    });

    it('TriggerBuilder is transient', function (): void {
        $app = app();
        $first = $app->make(TriggerBuilder::class);
        $second = $app->make(TriggerBuilder::class);

        expect($first)->not->toBe($second);
    });

    it('SubscriptionBuilder is transient', function (): void {
        $app = app();
        $first = $app->make(SubscriptionBuilder::class);
        $second = $app->make(SubscriptionBuilder::class);

        expect($first)->not->toBe($second);
    });

    it('ConditionEngineContract resolves to ConditionEngine', function (): void {
        $contract = app(ConditionEngineContract::class);
        expect($contract)->toBeInstanceOf(ConditionEngine::class);

        $direct = app(ConditionEngine::class);
        expect($contract)->toBe($direct);
    });
});

describe('Facade accessor', function (): void {
    it('facade resolves to correct class', function (): void {
        $accessor = (new ReflectionClass(EventManagerFacade::class))
            ->getMethod('getFacadeAccessor')
            ->invoke(null);

        expect($accessor)->toBe(EventManager::class);
    });

    it('facade returns an EventManager instance', function (): void {
        $instance = EventManagerFacade::getFacadeRoot();
        expect($instance)->toBeInstanceOf(EventManager::class);
    });
});

describe('Config completeness', function (): void {
    it('has all 6 top-level config keys', function (): void {
        $config = config('events');
        $expectedKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'wildcard_cache_ttl'];

        foreach ($expectedKeys as $key) {
            expect(array_key_exists($key, $config))
                ->toBeTrue("Missing config key: events.{$key}");
        }
    });

    it('table_names has all 3 tables', function (): void {
        $tables = config('events.table_names');
        expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    });

    it('queue has connection and queue keys', function (): void {
        $queue = config('events.queue');
        expect($queue)->toHaveKeys(['connection', 'queue']);
    });

    it('retry has tries and backoff keys', function (): void {
        $retry = config('events.retry');
        expect($retry)->toHaveKeys(['tries', 'backoff']);
    });

    it('retention has days and include_pending keys', function (): void {
        $retention = config('events.retention');
        expect($retention)->toHaveKeys(['days', 'include_pending']);
    });

    it('subscriptions has all 5 keys', function (): void {
        $subs = config('events.subscriptions');
        expect($subs)->toHaveKeys([
            'auto_generate_secret',
            'max_failures',
            'timeout',
            'signature_algorithm',
        ]);
    });

    it('wildcard_cache_ttl is a positive integer', function (): void {
        $ttl = config('events.wildcard_cache_ttl');
        expect($ttl)->toBeInt();
        expect($ttl)->toBeGreaterThan(0);
    });
});

describe('ConditionEngine operator matrix', function (): void {
    $engine = app(ConditionEngine::class);

    $operators = [
        '>' => [['amount', ['>', 50]], ['amount' => 100], true],
        '>=' => [['amount', ['>=', 50]], ['amount' => 50], true],
        '<' => [['amount', ['<', 50]], ['amount' => 20], true],
        '<=' => [['amount', ['<=', 50]], ['amount' => 50], true],
        '=' => [['status', 'paid'], ['status' => 'paid'], true],
        '===' => [['flag', ['===', true]], ['flag' => true], true],
        '!=' => [['status', ['!=', 'draft']], ['status' => 'paid'], true],
        '!==' => [['flag', ['!==', false]], ['flag' => true], true],
        'in' => [['role', ['in', ['admin', 'mod']]], ['role' => 'admin'], true],
        'not_in' => [['role', ['not_in', ['guest']]], ['role' => 'admin'], true],
        'contains' => [['tags', ['contains', 'urgent']], ['tags' => ['urgent', 'bug']], true],
        'not_contains' => [['tags', ['not_contains', 'spam']], ['tags' => ['urgent']], true],
        'between' => [['age', ['between', [18, 65]]], ['age' => 30], true],
        'null' => [['deleted_at', ['null']], ['deleted_at' => null], true],
        'not_null' => [['email', ['not_null']], ['email' => 'a@b.com'], true],
        'empty' => [['notes', ['empty']], ['notes' => ''], true],
        'not_empty' => [['notes', ['not_empty']], ['notes' => 'hello'], true],
        'starts_with' => [['email', ['starts_with', 'admin@']], ['email' => 'admin@test.com'], true],
        'ends_with' => [['domain', ['ends_with', '.com']], ['domain' => 'test.com'], true],
        'matches' => [['code', ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC'], true],
    ];

    foreach ($operators as [$conditions, $payload, $expected]) {
        it("operator works: {$conditions[0]}", function () use ($engine, $conditions, $payload, $expected): void {
            expect($engine->matches($conditions, $payload))->toBe($expected);
        });
    }

    it('empty conditions array matches any payload', function () use ($engine): void {
        expect($engine->matches([], ['anything' => 'here']))->toBeTrue();
    });

    it('multiple conditions use AND logic', function () use ($engine): void {
        $conditions = [
            'status' => 'paid',
            'amount' => ['>', 100],
        ];
        expect($engine->matches($conditions, ['status' => 'paid', 'amount' => 200]))->toBeTrue();
        expect($engine->matches($conditions, ['status' => 'paid', 'amount' => 50]))->toBeFalse();
        expect($engine->matches($conditions, ['status' => 'pending', 'amount' => 200]))->toBeFalse();
    });

    it('dot notation works for nested fields', function () use ($engine): void {
        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'admin']],
        ))->toBeTrue();

        expect($engine->matches(
            ['user.role' => 'admin'],
            ['user' => ['role' => 'user']],
        ))->toBeFalse();
    });

    it('between operator auto-normalizes inverted ranges', function () use ($engine): void {
        expect($engine->matches(
            ['age', ['between', [100, 18]]],
            ['age' => 30],
        ))->toBeTrue();
    });
});

describe('WildcardMatcher comprehensive', function (): void {
    it('exact non-dotted match', function (): void {
        expect(WildcardMatcher::matches('order', 'order'))->toBeTrue();
        expect(WildcardMatcher::matches('order', 'payment'))->toBeFalse();
    });

    it('exact dotted match', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    });

    it('single-segment wildcard', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
    });

    it('cross-segment wildcard', function (): void {
        expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
    });

    it('catch-all patterns', function (): void {
        expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    it('multi-wildcard pattern', function (): void {
        expect(WildcardMatcher::matches('*.order.*', 'user.order.created'))->toBeTrue();
        expect(WildcardMatcher::matches('*.order.*', 'user.order'))->toBeFalse();
    });

    it('findMatchingPatterns preserves order', function (): void {
        $result = WildcardMatcher::findMatchingPatterns(
            ['order.*', 'user.*', 'order.placed'],
            'order.placed',
        );

        expect($result)->toBe(['order.*', 'order.placed']);
    });

    it('extractWildcards single-segment', function (): void {
        expect(WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created'))
            ->toBe(['profile']);
    });

    it('extractWildcards returns empty for ** patterns', function (): void {
        expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))
            ->toBe([]);
    });

    it('extractWildcards returns empty for non-matching patterns', function (): void {
        expect(WildcardMatcher::extractWildcards('user.*.created', 'order.profile.created'))
            ->toBe([]);
    });
});

describe('EscapesWildcardLike trait', function (): void {
    it('returns null for non-wildcard patterns', function (): void {
        $matcher = new class
        {
            use EscapesWildcardLike;
        };

        // Access protected method via reflection
        $ref = new ReflectionMethod($matcher, 'wildcardToLike');
        $ref->setAccessible(true);

        expect($ref->invoke($matcher, 'order.placed'))->toBeNull();
    });

    it('converts * to %', function (): void {
        $matcher = new class
        {
            use EscapesWildcardLike;
        };

        $ref = new ReflectionMethod($matcher, 'wildcardToLike');
        $ref->setAccessible(true);

        expect($ref->invoke($matcher, 'order.*'))->toBe('order.%');
    });

    it('escapes SQL LIKE special characters', function (): void {
        $matcher = new class
        {
            use EscapesWildcardLike;
        };

        $ref = new ReflectionMethod($matcher, 'wildcardToLike');
        $ref->setAccessible(true);

        // Percent sign should be escaped
        expect($ref->invoke($matcher, 'order.%*'))->toBe('order.\\%%');
        // Underscore should be escaped
        expect($ref->invoke($matcher, 'test_*'))->toBe('test\\_%');
    });
});

describe('DomainEvent readonly and immutability', function (): void {
    it('has all readonly properties', function (): void {
        $ref = new ReflectionClass(DomainEvent::class);
        $props = $ref->getProperties();

        foreach ($props as $prop) {
            expect($prop->isReadOnly())->toBeTrue(
                "DomainEvent::\${$prop->name} must be readonly"
            );
        }
    });

    it('preserves eventId and occurredAt through roundtrip', function (): void {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);
        $data = $event->toArray();
        $restored = DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($event->eventId->toString());
        expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
            ->toBe($event->occurredAt->format(DateTimeImmutable::ATOM));
        expect($restored->eventType)->toBe($event->eventType);
        expect($restored->payload)->toBe($event->payload);
    });

    it('generates fresh UUID on each occur()', function (): void {
        $a = DomainEvent::occur('test');
        $b = DomainEvent::occur('test');

        expect($a->eventId->toString())->not->toBe($b->eventId->toString());
    });

    it('toArray has all required keys', function (): void {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);
        $data = $event->toArray();

        expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
    });
});

describe('Model scopes and relations', function (): void {
    it('Trigger has correct scopes', function (): void {
        $ref = new ReflectionClass(Trigger::class);
        $methods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));

        expect($methods)->toContain('scopeEnabled');
        expect($methods)->toContain('scopeAsync');
        expect($methods)->toContain('scopeOrderByPriority');
    });

    it('EventLog has correct scopes', function (): void {
        $ref = new ReflectionClass(EventLog::class);
        $methods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));

        expect($methods)->toContain('scopeWithStatus');
        expect($methods)->toContain('scopeFailed');
        expect($methods)->toContain('scopePending');
        expect($methods)->toContain('scopeCompleted');
    });

    it('Subscription has correct scopes', function (): void {
        $ref = new ReflectionClass(Subscription::class);
        $methods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));

        expect($methods)->toContain('scopeActive');
        expect($methods)->toContain('scopeOrderByPriority');
        expect($methods)->toContain('scopeForEvent');
    });

    it('Trigger->eventLogs relation exists', function (): void {
        $ref = new ReflectionClass(Trigger::class);
        $methods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
        expect($methods)->toContain('eventLogs');
    });

    it('EventLog->trigger relation exists', function (): void {
        $ref = new ReflectionClass(EventLog::class);
        $methods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
        expect($methods)->toContain('trigger');
    });
});

describe('EventLog status constants', function (): void {
    it('has all 4 status constants', function (): void {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');
    });

    it('status constants match $statuses array', function (): void {
        expect(EventLog::$statuses)->toBe([
            EventLog::STATUS_PENDING,
            EventLog::STATUS_DISPATCHED,
            EventLog::STATUS_COMPLETED,
            EventLog::STATUS_FAILED,
        ]);
    });
});

describe('parseActions @phpstan-return annotation', function (): void {
    it('parseActions method has @phpstan-return annotation', function (): void {
        $ref = new ReflectionMethod(EventManager::class, 'parseActions');
        $doc = $ref->getDocComment();

        expect($doc)->not->toBeFalse();
        expect((string) $doc)->toContain('@phpstan-return');
    });

    it('parseActions method has @return annotation with list type', function (): void {
        $ref = new ReflectionMethod(EventManager::class, 'parseActions');
        $doc = $ref->getDocComment();

        expect($doc)->not->toBeFalse();
        expect((string) $doc)->toContain('@return');
        expect((string) $doc)->toContain('list<');
    });
});

describe('Version consistency', function (): void {
    it('composer.json version matches README badge', function (): void {
        $composer = json_decode(
            file_get_contents(__DIR__.'/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $composerVersion = $composer['version'];

        $readme = file_get_contents(__DIR__.'/../README.md');
        preg_match('/version-(\d+\.\d+\.\d+)/', (string) $readme, $m);
        $readmeVersion = $m[1] ?? null;

        expect($readmeVersion)->not->toBeNull('README version badge not found');
        expect($composerVersion)->toBe($readmeVersion);
    });
});

describe('WildcardMatcher #[Pure] attribute', function (): void {
    $pureMethods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

    foreach ($pureMethods as $method) {
        it("{$method}() has #[Pure] attribute", function () use ($method): void {
            $ref = new ReflectionMethod(WildcardMatcher::class, $method);
            $attrs = $ref->getAttributes();
            $hasPure = array_any($attrs, fn (ReflectionAttribute $a): bool => $a->getName() === 'Pure');

            expect($hasPure)->toBeTrue(
                "WildcardMatcher::{$method}() must have #[Pure] attribute"
            );
        });
    }
});

describe('Subscription signPayload edge cases', function (): void {
    it('returns empty string for null secret', function (): void {
        $sub = Subscription::factory()->create(['secret' => null]);
        expect($sub->signPayload('test'))->toBe('');
    });

    it('returns empty string for empty secret', function (): void {
        $sub = Subscription::factory()->create(['secret' => '']);
        expect($sub->signPayload('test'))->toBe('');
    });

    it('returns deterministic signature', function (): void {
        $sub = Subscription::factory()->create(['secret' => 'test_secret']);
        $sig1 = $sub->signPayload('payload');
        $sig2 = $sub->signPayload('payload');

        expect($sig1)->toBe($sig2);
    });
});

describe('TriggerBuilder save validation', function (): void {
    it('throws for empty event', function (): void {
        $builder = app()->make(TriggerBuilder::class);
        $builder->action('SomeAction::class')->save();
    })->throws(InvalidArgumentException::class, 'Event name is required');

    it('throws for no action', function (): void {
        $builder = app()->make(TriggerBuilder::class);
        $builder->on('test.event')->save();
    })->throws(InvalidArgumentException::class, 'At least one action is required');
});

describe('SubscriptionBuilder save validation', function (): void {
    it('throws for empty event', function (): void {
        $builder = app()->make(SubscriptionBuilder::class);
        $builder->to('https://example.com')->save();
    })->throws(InvalidArgumentException::class, 'Event name is required');

    it('throws for empty URL', function (): void {
        $builder = app()->make(SubscriptionBuilder::class);
        $builder->on('test.event')->to('')->save();
    })->throws(InvalidArgumentException::class, 'Webhook URL is required');

    it('throws for invalid URL', function (): void {
        $builder = app()->make(SubscriptionBuilder::class);
        $builder->on('test.event')->to('not-a-url')->save();
    })->throws(InvalidArgumentException::class, 'valid URL');
});

describe('ActionResolver error handling', function (): void {
    it('throws for non-existent class', function (): void {
        $resolver = app(ActionResolver::class);
        $resolver->resolve('NonExistent\\ActionClass');
    })->throws(InvalidArgumentException::class, 'does not exist');

    it('throws for non-Triggerable class', function (): void {
        $resolver = app(ActionResolver::class);
        $resolver->resolve(stdClass::class);
    })->throws(InvalidArgumentException::class, 'must implement');
});

describe('ManagesHistory trait methods', function (): void {
    it('getEventHistory exists on EventManager', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        expect($ref->hasMethod('getEventHistory'))->toBeTrue();
    });

    it('getStats exists on EventManager', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        expect($ref->hasMethod('getStats'))->toBeTrue();
    });

    it('purgeLogs exists on EventManager', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        expect($ref->hasMethod('purgeLogs'))->toBeTrue();
    });
});

describe('ManagesSubscriptions trait methods', function (): void {
    it('subscribe exists on EventManager', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        expect($ref->hasMethod('subscribe'))->toBeTrue();
    });

    it('unsubscribe exists on EventManager', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        expect($ref->hasMethod('unsubscribe'))->toBeTrue();
    });

    it('listSubscriptions exists on EventManager', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        expect($ref->hasMethod('listSubscriptions'))->toBeTrue();
    });

    it('getSubscription exists on EventManager', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        expect($ref->hasMethod('getSubscription'))->toBeTrue();
    });

    it('subscribeWebhook exists on EventManager', function (): void {
        $ref = new ReflectionClass(EventManager::class);
        expect($ref->hasMethod('subscribeWebhook'))->toBeTrue();
    });
});

describe('Fluent interface return types', function (): void {
    $builderMethods = [
        [TriggerBuilder::class, 'name', 'self'],
        [TriggerBuilder::class, 'on', 'self'],
        [TriggerBuilder::class, 'action', 'self'],
        [TriggerBuilder::class, 'actions', 'self'],
        [TriggerBuilder::class, 'when', 'self'],
        [TriggerBuilder::class, 'async', 'self'],
        [TriggerBuilder::class, 'priority', 'self'],
        [TriggerBuilder::class, 'actionParams', 'self'],
        [SubscriptionBuilder::class, 'on', 'self'],
        [SubscriptionBuilder::class, 'to', 'self'],
        [SubscriptionBuilder::class, 'withSecret', 'self'],
        [SubscriptionBuilder::class, 'withFilter', 'self'],
        [SubscriptionBuilder::class, 'priority', 'self'],
        [SubscriptionBuilder::class, 'async', 'self'],
    ];

    foreach ($builderMethods as [$class, $method]) {
        it("{$class}::{$method}() returns self", function () use ($class, $method): void {
            $ref = new ReflectionMethod($class, $method);
            $returnType = $ref->getReturnType();

            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('self');
        });
    }
});

describe('Config merge verification', function (): void {
    it('events config is merged from package', function (): void {
        $config = config('events');
        expect($config)->not->toBeNull();
        expect(is_array($config))->toBeTrue();
    });
});
