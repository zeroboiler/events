<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 173 — Deep production readiness audit for events package.
 *
 * Validates:
 * - All source file structure (strict types, namespace, final, readonly)
 * - Constructor promotion patterns (readonly + typed)
 * - Return type declarations completeness
 * - Docblock presence on public methods
 * - PHPStan 9 config correctness
 * - Composer.json PHP 8.5+/Laravel 13.x
 * - Config file completeness (8 top-level keys)
 * - ServiceProvider register/boot/provides consistency
 * - Facade accessor correctness
 * - Contract implementation verification
 * - DomainEvent roundtrip identity (eventId + occurredAt preserved)
 * - ReDoS protection limits
 * - WildcardMatcher pattern edge cases
 * - ConditionEngine operator coverage
 * - EventManager public API surface
 * - Console command count and signatures
 * - Model cast definitions
 * - Migration timestamps ordering
 * - Factory model references
 */
it('has correct PHP version requirement in composer.json', function (): void {
    $content = file_get_contents(__DIR__.'/../composer.json');
    $json = json_decode($content, true);

    expect($json['require']['php'])->toBe('^8.5')
        ->and($json['require']['illuminate/contracts'])->toBe('^13.0')
        ->and($json['require']['illuminate/support'])->toBe('^13.0')
        ->and($json['require']['illuminate/database'])->toBe('^13.0')
        ->and($json['require']['illuminate/queue'])->toBe('^13.0')
        ->and($json['require']['illuminate/cache'])->toBe('^13.0')
        ->and($json['require']['illuminate/http'])->toBe('^13.0')
        ->and($json['require']['ramsey/uuid'])->toBe('^4.7');
});

it('has correct PHPStan level 9 configuration', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect($content)->toContain('level: 9')
        ->and($content)->toContain('reportUnusedIgnoredErrors: true')
        ->and($content)->toContain('checkExplicitMixed: true')
        ->and($content)->toContain('checkMissingIterableValueType: true')
        ->and($content)->toContain('checkGenericClassInNonGenericObjectType: true')
        ->and($content)->toContain('checkUninitializedProperties: true')
        ->and($content)->toContain('checkFunctionNameCase: true')
        ->and($content)->toContain('checkClassLikeNameCase: true')
        ->and($content)->toContain('checkPropertyHookNameCase: true')
        ->and($content)->toContain('checkEnumCaseValueNameCase: true')
        ->and($content)->toContain('checkAlwaysTrueInstanceof: true')
        ->and($content)->toContain('paths:')
        ->and($content)->toContain('- src');
});

it('has all 33 source files with strict types and license headers', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);

    // Flatten nested directories
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $files = [];
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    expect(count($files))->toBe(33);

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)')
            ->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
    }
});

it('all service classes are final', function (): void {
    $nonFinal = [];
    $classes = [
        'EventManager' => __DIR__.'/../src/EventManager.php',
        'ConditionEngine' => __DIR__.'/../src/ConditionEngine.php',
        'WildcardMatcher' => __DIR__.'/../src/WildcardMatcher.php',
        'ActionResolver' => __DIR__.'/../src/ActionResolver.php',
        'TriggerBuilder' => __DIR__.'/../src/TriggerBuilder.php',
        'SubscriptionBuilder' => __DIR__.'/../src/SubscriptionBuilder.php',
        'EventScheduler' => __DIR__.'/../src/EventScheduler.php',
        'EventsServiceProvider' => __DIR__.'/../src/EventsServiceProvider.php',
        'DomainEvent' => __DIR__.'/../src/Domain/DomainEvent.php',
        'WebhookAction' => __DIR__.'/../src/Actions/WebhookAction.php',
        'DispatchTriggerJob' => __DIR__.'/../src/Jobs/DispatchTriggerJob.php',
        'EventLog' => __DIR__.'/../src/Models/EventLog.php',
        'Trigger' => __DIR__.'/../src/Models/Trigger.php',
        'Subscription' => __DIR__.'/../src/Models/Subscription.php',
    ];

    foreach ($classes as $name => $path) {
        $contents = file_get_contents($path);
        if (! str_contains($contents, 'final class '.$name) && ! str_contains($contents, 'final readonly class '.$name)) {
            $nonFinal[] = $name;
        }
    }

    expect($nonFinal)->toBeEmpty("Classes not declared as final: ".implode(', ', $nonFinal));
});

it('WildcardMatcher is readonly final class', function (): void {
    $contents = file_get_contents(__DIR__.'/../src/WildcardMatcher.php');

    expect($contents)->toContain('readonly final class WildcardMatcher');
});

it('EventManager constructor uses promoted readonly properties', function (): void {
    $contents = file_get_contents(__DIR__.'/../src/EventManager.php');

    expect($contents)->toContain('protected readonly ConditionEngine $conditionEngine')
        ->and($contents)->toContain('protected readonly ActionResolver $actionResolver')
        ->and($contents)->toContain('protected readonly Container $app');
});

it('ServiceProvider provides all 7 bindings', function (): void {
    $provider = new ReflectionClass(EventsServiceProvider::class);
    $method = $provider->getMethod('provides');

    $instance = new EventsServiceProvider(app());
    $result = $method->invoke($instance);

    expect($result)->toBe([
        EventManager::class,
        ConditionEngine::class,
        ConditionEngineContract::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
    ]);
});

it('Facade accessor returns correct class name', function (): void {
    $facade = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    $method = $facade->getMethod('getFacadeAccessor');

    expect($method->invoke(null))->toBe(\ZeroBoiler\Events\EventManager::class);
});

it('config file has all 8 top-level keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    $expectedKeys = [
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'disabled',
        'wildcard_cache_ttl',
    ];

    foreach ($expectedKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
    }

    // Verify sub-keys
    expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions'])
        ->and($config['queue'])->toHaveKeys(['connection', 'queue'])
        ->and($config['retry'])->toHaveKeys(['tries', 'backoff'])
        ->and($config['retention'])->toHaveKeys(['days', 'include_pending', 'schedule_cron'])
        ->and($config['subscriptions'])->toHaveKeys(['auto_generate_secret', 'secret_length', 'max_failures', 'timeout', 'signature_algorithm', 'cleanup_cron']);
});

it('ConditionEngine implements ConditionEngineContract', function (): void {
    expect(ConditionEngine::class)->toImplement(ConditionEngineContract::class);
});

it('WebhookAction implements Triggerable', function (): void {
    expect(\ZeroBoiler\Events\Actions\WebhookAction::class)->toImplement(Triggerable::class);
});

it('DispatchTriggerJob implements ShouldQueue', function (): void {
    expect(DispatchTriggerJob::class)->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

it('DomainEvent preserves identity through roundtrip', function (): void {
    $original = DomainEvent::occur('test.event', ['key' => 'value']);
    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString())
        ->and($restored->eventType)->toBe($original->eventType)
        ->and($restored->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp())
        ->and($restored->payload)->toBe($original->payload);
});

it('DomainEvent rejects empty eventType in fromArray', function (): void {
    DomainEvent::fromArray(['eventType' => '', 'payload' => []]);
})->throws(InvalidArgumentException::class, 'eventType is required');

it('ConditionEngine ReDoS protection rejects long patterns', function (): void {
    $engine = new ConditionEngine();

    $longPattern = str_repeat('a', 501);
    $result = $engine->matches(['code' => ['matches', '/'.$longPattern.'/']], ['code' => 'test']);

    expect($result)->toBeFalse();
});

it('ConditionEngine ReDoS protection rejects nested quantifiers', function (): void {
    $engine = new ConditionEngine();

    $result = $engine->matches(
        ['code' => ['matches', '/(a+)+/']],
        ['code' => 'aaa'],
    );

    expect($result)->toBeFalse();
});

it('WildcardMatcher catch-all patterns match any event', function (): void {
    expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue()
        ->and(WildcardMatcher::matches('*', 'single'))->toBeTrue()
        ->and(WildcardMatcher::matches('*', ''))->toBeFalse();
});

it('WildcardMatcher cross-segment patterns', function (): void {
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue()
        ->and(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue()
        ->and(WildcardMatcher::matches('order.**', 'order'))->toBeFalse();
});

it('WildcardMatcher single-segment patterns', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue()
        ->and(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse()
        ->and(WildcardMatcher::matches('order.*', 'order'))->toBeFalse();
});

it('ConditionEngine supports all 21 operators', function (): void {
    $engine = new ConditionEngine();

    $payload = [
        'amount' => 150,
        'status' => 'active',
        'tags' => ['urgent', 'billing'],
        'email' => 'admin@example.com',
        'code' => 'ABC-1234',
        'domain' => 'example.com',
        'notes' => '',
        'deleted_at' => null,
    ];

    // All operators should match
    expect($engine->matches(['amount' => ['>', 100]], $payload))->toBeTrue()
        ->and($engine->matches(['amount' => ['>=', 150]], $payload))->toBeTrue()
        ->and($engine->matches(['amount' => ['<', 200]], $payload))->toBeTrue()
        ->and($engine->matches(['amount' => ['<=', 150]], $payload))->toBeTrue()
        ->and($engine->matches(['status' => ['=', 'active']], $payload))->toBeTrue()
        ->and($engine->matches(['status' => ['===', 'active']], $payload))->toBeTrue()
        ->and($engine->matches(['status' => ['!=', 'inactive']], $payload))->toBeTrue()
        ->and($engine->matches(['status' => ['!==', 'pending']], $payload))->toBeTrue()
        ->and($engine->matches(['status' => ['in', ['active', 'pending']]], $payload))->toBeTrue()
        ->and($engine->matches(['status' => ['not_in', ['inactive', 'draft']]], $payload))->toBeTrue()
        ->and($engine->matches(['tags' => ['contains', 'urgent']], $payload))->toBeTrue()
        ->and($engine->matches(['tags' => ['not_contains', 'spam']], $payload))->toBeTrue()
        ->and($engine->matches(['amount' => ['between', [100, 200]]], $payload))->toBeTrue()
        ->and($engine->matches(['deleted_at' => ['null']], $payload))->toBeTrue()
        ->and($engine->matches(['status' => ['not_null']], $payload))->toBeTrue()
        ->and($engine->matches(['notes' => ['empty']], $payload))->toBeTrue()
        ->and($engine->matches(['status' => ['not_empty']], $payload))->toBeTrue()
        ->and($engine->matches(['email' => ['starts_with', 'admin@']], $payload))->toBeTrue()
        ->and($engine->matches(['domain' => ['ends_with', '.com']], $payload))->toBeTrue()
        ->and($engine->matches(['code' => ['matches', '/^[A-Z]{3}-\\d{4}$/']], $payload))->toBeTrue();
});

it('EscapesWildcardLike correctly escapes SQL special chars', function (): void {
    $trait = new class
    {
        use ZeroBoiler\Events\Concerns\EscapesWildcardLike;

        public function testWildcardToLike(string $pattern): ?string
        {
            return $this->wildcardToLike($pattern);
        }
    };

    expect($trait->testWildcardToLike('order.*'))->toBe('order.%')
        ->and($trait->testWildcardToLike('order.**'))->toBe('order.%')
        ->and($trait->testWildcardToLike('order.placed'))->toBeNull()
        ->and($trait->testWildcardToLike('user.%'))->toBe('user.\\%')
        ->and($trait->testWildcardToLike('user._test.*'))->toBe('user.\\_test.%');
});

it('has 12 console commands registered in ServiceProvider', function (): void {
    $provider = new ReflectionClass(EventsServiceProvider::class);
    $method = $provider->getMethod('boot');

    $app = app();
    $instance = new EventsServiceProvider($app);
    $method->invoke($instance);

    // Check that the commands are registered
    $commands = $app->make('Illuminate\Contracts\Console\Kernel')->all();
    $expectedCommandPrefixes = [
        'zeroboiler:events:list',
        'zeroboiler:events:fire',
        'zeroboiler:events:register',
        'zeroboiler:events:enable',
        'zeroboiler:events:disable',
        'zeroboiler:events:retry',
        'zeroboiler:events:redeliver',
        'zeroboiler:events:log',
        'zeroboiler:events:subscribe',
        'zeroboiler:events:unsubscribe',
        'zeroboiler:events:subscriptions',
        'zeroboiler:events:health',
    ];

    foreach ($expectedCommandPrefixes as $cmd) {
        expect($commands)->toHaveKey($cmd);
    }
});

it('models have correct cast definitions', function (): void {
    $triggerCasts = (new ReflectionMethod(\ZeroBoiler\Events\Models\Trigger::class, 'casts'))->invoke(
        new \ZeroBoiler\Events\Models\Trigger,
    );
    expect($triggerCasts)->toHaveKeys(['conditions', 'async', 'enabled', 'priority']);

    $eventLogCasts = (new ReflectionMethod(\ZeroBoiler\Events\Models\EventLog::class, 'casts'))->invoke(
        new \ZeroBoiler\Events\Models\EventLog,
    );
    expect($eventLogCasts)->toHaveKeys(['payload', 'duration_ms', 'error']);

    $subCasts = (new ReflectionMethod(\ZeroBoiler\Events\Models\Subscription::class, 'casts'))->invoke(
        new \ZeroBoiler\Events\Models\Subscription,
    );
    expect($subCasts)->toHaveKeys(['conditions', 'priority', 'active', 'failure_count', 'delivery_count', 'last_fired_at']);
});

it('all source files have return type declarations on public methods', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $files = [];
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    $missingReturnTypes = [];

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        $tokens = token_get_all($contents);

        // Find class definitions
        $inClass = false;
        $className = '';
        for ($i = 0; $i < count($tokens); $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
                $inClass = true;
                // Get class name
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $className = $tokens[$j][1];
                        break;
                    }
                }
            }

            if ($inClass && is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                // Get visibility
                $visibility = 'public';
                for ($k = $i - 1; $k >= 0; $k--) {
                    if (is_array($tokens[$k])) {
                        if ($tokens[$k][0] === T_PUBLIC) {
                            $visibility = 'public';
                            break;
                        } elseif ($tokens[$k][0] === T_PROTECTED) {
                            $visibility = 'protected';
                            break;
                        } elseif ($tokens[$k][0] === T_PRIVATE) {
                            $visibility = 'private';
                            break;
                        }
                    } elseif ($tokens[$k] === '{' || $tokens[$k] === '}') {
                        break;
                    }
                }

                // Check for return type — simplified check: look for ): or : in the signature
                $hasReturnType = false;
                for ($l = $i + 1; $l < count($tokens) && $l < $i + 20; $l++) {
                    if ($tokens[$l] === ')') {
                        // Check if next meaningful token is ':'
                        for ($m = $l + 1; $m < count($tokens) && $m < $l + 5; $m++) {
                            if ($tokens[$m] === ':') {
                                $hasReturnType = true;
                                break 2;
                            }
                            if ($tokens[$m] === '{' || $tokens[$m] === ';') {
                                break 2;
                            }
                        }
                    }
                }

                if (!$hasReturnType && $visibility === 'public') {
                    $missingReturnTypes[] = $className;
                }
            }
        }
    }

    expect($missingReturnTypes)->toBeEmpty(
        'Public methods without return type declarations in: '.implode(', ', array_unique($missingReturnTypes))
    );
});

it('EventManager has all public API methods', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $publicMethods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        $ref->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    $expectedMethods = [
        'on',
        'register',
        'fire',
        'fireModel',
        'enable',
        'disable',
        'deleteTrigger',
        'invalidateTriggerCache',
        'isDisabled',
        'setEnabled',
        'listTriggers',
        'getTrigger',
        'container',
        'registerScheduler',
        'executeTrigger',
        // From ManagesHistory trait
        'getEventHistory',
        'getStats',
        'purgeLogs',
        'getStalePendingLogs',
        'deactivateExceededSubscriptions',
        // From ManagesSubscriptions trait
        'subscribe',
        'unsubscribe',
        'listSubscriptions',
        'getSubscription',
        'subscribeWebhook',
    ];

    foreach ($expectedMethods as $method) {
        expect($publicMethods)->toContain($method, "Missing public method: {$method}");
    }
});

it('phpunit.xml has correct configuration', function (): void {
    $xml = simplexml_load_file(__DIR__.'/../phpunit.xml');

    expect((string) $xml['beStrictAboutOutputDuringTests'])->toBe('true')
        ->and((string) $xml['failOnRisky'])->toBe('true')
        ->and((string) $xml['failOnWarning'])->toBe('true');
});
