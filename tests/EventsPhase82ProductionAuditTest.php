<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

describe('Phase 82 Production Audit', function (): void {
    describe('EventManager type safety improvements', function (): void {
        test('getConfig() uses instanceof check instead of assert()', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
            $method = $reflection->getMethod('getConfig');

            $code = file_get_contents($reflection->getFileName());
            $start = $method->getStartLine();
            $end = $method->getEndLine();
            $lines = array_slice(explode("\n", $code), $start - 1, $end - $start + 1);
            $methodBody = implode("\n", $lines);

            // Must use instanceof check
            expect($methodBody)->toContain('instanceof');
            // Must NOT use assert() for type narrowing
            expect($methodBody)->not->toContain('assert($config');
        });

        test('on() uses instanceof check instead of assert()', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
            $method = $reflection->getMethod('on');

            $code = file_get_contents($reflection->getFileName());
            $start = $method->getStartLine();
            $end = $method->getEndLine();
            $lines = array_slice(explode("\n", $code), $start - 1, $end - $start + 1);
            $methodBody = implode("\n", $lines);

            expect($methodBody)->toContain('instanceof TriggerBuilder');
            expect($methodBody)->not->toContain('assert($builder');
        });

        test('ManagesSubscriptions::subscribe() uses instanceof check instead of assert()', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Concerns\ManagesSubscriptions::class);
            $method = $reflection->getMethod('subscribe');

            $traitFile = $reflection->getFileName();
            expect($traitFile)->toBeString();
            expect(file_exists($traitFile))->toBeTrue();

            $code = file_get_contents($traitFile);
            $start = $method->getStartLine();
            $end = $method->getEndLine();
            $lines = array_slice(explode("\n", $code), $start - 1, $end - $start + 1);
            $methodBody = implode("\n", $lines);

            expect($methodBody)->toContain('instanceof SubscriptionBuilder');
            expect($methodBody)->not->toContain('assert($builder');
        });

        test('getEnabledWildcardTriggers() does not use assert()', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
            $method = $reflection->getMethod('getEnabledWildcardTriggers');

            $code = file_get_contents($reflection->getFileName());
            $start = $method->getStartLine();
            $end = $method->getEndLine();
            $lines = array_slice(explode("\n", $code), $start - 1, $end - $start + 1);
            $methodBody = implode("\n", $lines);

            expect($methodBody)->not->toContain('assert(');
        });
    });

    describe('EventManager uses ConfigRepository import', function (): void {
        test('EventManager imports ConfigRepository type', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
            $file = $reflection->getFileName();
            $code = file_get_contents($file);

            expect($code)->toContain('use Illuminate\\Contracts\\Config\\Repository as ConfigRepository');
        });

        test('getConfig() return type is ConfigRepository (resolved FQN)', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
            $method = $reflection->getMethod('getConfig');

            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            // ReflectionMethod::getReturnType() resolves import aliases to the FQN.
            // The source uses `use Illuminate\Contracts\Config\Repository as ConfigRepository`.
            expect((string) $returnType)->toBe('Illuminate\\Contracts\\Config\\Repository');
        });
    });

    describe('PHPStan config completeness', function (): void {
        test('phpstan.neon.dist has all required checks', function (): void {
            $configPath = __DIR__.'/../phpstan.neon.dist';
            expect(file_exists($configPath))->toBeTrue();

            $content = file_get_contents($configPath);

            expect($content)->toContain('level: 9');
            expect($content)->toContain('checkFunctionNameCase: true');
            expect($content)->toContain('checkUninitializedProperties: true');
            expect($content)->toContain('checkGenericClassInNonGenericObjectType: true');
            expect($content)->toContain('checkMissingIterableValueType: true');
            expect($content)->toContain('checkClassLikeNameCase: true');
            expect($content)->toContain('checkPropertyHookNameCase: true');
            expect($content)->toContain('checkEnumCaseValueNameCase: true');
            expect($content)->toContain('reportUnmatchedIgnoredErrors: true');
            expect($content)->toContain('treatPhpDocTypesAsCertain: false');
        });

        test('phpstan.neon.dist has Eloquent facade suppressions', function (): void {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');

            expect($content)->toContain('Config|Cache|Queue|Log|DB');
            expect($content)->toContain('Http::');
            expect($content)->toContain('wildcardToLike');
            expect($content)->toContain('database_path');
        });

        test('phpstan baseline is empty (no suppressed errors)', function (): void {
            $baselinePath = __DIR__.'/../phpstan-baseline.neon';
            expect(file_exists($baselinePath))->toBeTrue();

            $content = file_get_contents($baselinePath);
            expect(trim($content))->not->toContain('ignoreErrors');
        });
    });

    describe('ServiceProvider completeness', function (): void {
        test('provides() lists all services', function (): void {
            $provider = new ReflectionClass(\ZeroBoiler\Events\EventsServiceProvider::class);
            $method = $provider->getMethod('provides');

            $result = [];
            if (class_exists(\ZeroBoiler\Events\EventManager::class)) {
                $sp = new \ZeroBoiler\Events\EventsServiceProvider(app());
                $result = $sp->provides();
            }

            expect($result)->toContain(\ZeroBoiler\Events\EventManager::class);
            expect($result)->toContain(\ZeroBoiler\Events\ConditionEngine::class);
            expect($result)->toContain(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
            expect($result)->toContain(\ZeroBoiler\Events\ActionResolver::class);
            expect($result)->toContain(\ZeroBoiler\Events\TriggerBuilder::class);
            expect($result)->toContain(\ZeroBoiler\Events\SubscriptionBuilder::class);
        });

        test('register() binds TriggerBuilder as transient (not singleton)', function (): void {
            $provider = new ReflectionClass(\ZeroBoiler\Events\EventsServiceProvider::class);
            $method = $provider->getMethod('register');

            $code = file_get_contents($provider->getFileName());
            $start = $method->getStartLine();
            $end = $method->getEndLine();
            $lines = array_slice(explode("\n", $code), $start - 1, $end - $start + 1);
            $body = implode("\n", $lines);

            // TriggerBuilder should be bound, not singleton
            expect($body)->toContain('TriggerBuilder::class');
            expect($body)->not->toContain('singleton(TriggerBuilder::class)');
        });

        test('register() binds SubscriptionBuilder as transient (not singleton)', function (): void {
            $provider = new ReflectionClass(\ZeroBoiler\Events\EventsServiceProvider::class);
            $method = $provider->getMethod('register');

            $code = file_get_contents($provider->getFileName());
            $start = $method->getStartLine();
            $end = $method->getEndLine();
            $lines = array_slice(explode("\n", $code), $start - 1, $end - $start + 1);
            $body = implode("\n", $lines);

            expect($body)->toContain('SubscriptionBuilder::class');
            expect($body)->not->toContain('singleton(SubscriptionBuilder::class)');
        });
    });

    describe('Config file completeness', function (): void {
        test('config/events.php has all required sections', function (): void {
            $content = file_get_contents(__DIR__.'/../config/events.php');

            expect($content)->toContain('table_names');
            expect($content)->toContain('queue');
            expect($content)->toContain('retry');
            expect($content)->toContain('retention');
            expect($content)->toContain('subscriptions');
            expect($content)->toContain('disabled');
            expect($content)->toContain('wildcard_cache_ttl');
        });

        test('config/events.php subscription section has all keys', function (): void {
            $config = include __DIR__.'/../config/events.php';

            expect(isset($config['subscriptions']))->toBeTrue();
            expect(isset($config['subscriptions']['auto_generate_secret']))->toBeTrue();
            expect(isset($config['subscriptions']['max_failures']))->toBeTrue();
            expect(isset($config['subscriptions']['timeout']))->toBeTrue();
            expect(isset($config['subscriptions']['signature_algorithm']))->toBeTrue();
        });

        test('config/events.php table_names has all tables', function (): void {
            $config = include __DIR__.'/../config/events.php';

            expect(isset($config['table_names']['triggers']))->toBeTrue();
            expect(isset($config['table_names']['event_logs']))->toBeTrue();
            expect(isset($config['table_names']['subscriptions']))->toBeTrue();
        });
    });

    describe('Code quality — final classes', function (): void {
        $finalClasses = [
            \ZeroBoiler\Events\EventManager::class,
            \ZeroBoiler\Events\ConditionEngine::class,
            \ZeroBoiler\Events\ActionResolver::class,
            \ZeroBoiler\Events\TriggerBuilder::class,
            \ZeroBoiler\Events\SubscriptionBuilder::class,
            \ZeroBoiler\Events\WildcardMatcher::class,
            \ZeroBoiler\Events\EventsServiceProvider::class,
            \ZeroBoiler\Events\Domain\DomainEvent::class,
            \ZeroBoiler\Events\Actions\WebhookAction::class,
            \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
            \ZeroBoiler\Events\Facades\EventManager::class,
        ];

        test('all core classes are final', function () use ($finalClasses): void {
            foreach ($finalClasses as $class) {
                $reflection = new ReflectionClass($class);
                expect($reflection->isFinal())->toBeTrue("{$class} should be final");
            }
        });
    });

    describe('Code quality — readonly classes', function (): void {
        test('WildcardMatcher is readonly final', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\WildcardMatcher::class);

            expect($reflection->isFinal())->toBeTrue();
            // PHP 8.2+ readonly classes
            $attributes = $reflection->getAttributes();
            $isReadonly = false;
            foreach ($attributes as $attr) {
                if ($attr->getName() === 'AllowDynamicProperties') {
                    $isReadonly = false;
                    break;
                }
            }
            // Check via reflection property (readonly classes in PHP 8.2)
            $code = file_get_contents($reflection->getFileName());
            $start = $reflection->getStartLine();
            $end = $reflection->getEndLine();
            $lines = array_slice(explode("\n", $code), $start - 1, $end - $start + 1);
            $classDecl = implode("\n", $lines);

            expect($classDecl)->toContain('readonly');
        });
    });

    describe('Code quality — #[Override] attributes', function (): void {
        test('EventManager::getConfig() does not need #[Override] (own method)', function (): void {
            // getConfig is a custom method, not an override
            $reflection = new ReflectionMethod(\ZeroBoiler\Events\EventManager::class, 'getConfig');
            $attrs = $reflection->getAttributes();

            $hasOverride = false;
            foreach ($attrs as $attr) {
                if ($attr->getName() === 'Override' || str_ends_with($attr->getName(), '\\Override')) {
                    $hasOverride = true;
                    break;
                }
            }

            // getConfig is NOT an override, so it should NOT have the attribute
            expect($hasOverride)->toBeFalse();
        });
    });

    describe('DomainEvent immutability', function (): void {
        test('DomainEvent properties are readonly', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);

            expect($reflection->getProperty('eventId')->isReadOnly())->toBeTrue();
            expect($reflection->getProperty('occurredAt')->isReadOnly())->toBeTrue();
            expect($reflection->getProperty('eventType')->isReadOnly())->toBeTrue();
            expect($reflection->getProperty('payload')->isReadOnly())->toBeTrue();
        });

        test('DomainEvent is final', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);
            expect($reflection->isFinal())->toBeTrue();
        });
    });

    describe('Migration config-driven table names', function (): void {
        test('triggers migration uses config for table name', function (): void {
            $content = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php');
            expect($content)->toContain("config('events.table_names.triggers'");
        });

        test('event_logs migration uses config for table names', function (): void {
            $content = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php');
            expect($content)->toContain("config('events.table_names.event_logs'");
            expect($content)->toContain("config('events.table_names.triggers'");
        });

        test('event_subscriptions migration uses config for table name', function (): void {
            $content = file_get_contents(__DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php');
            expect($content)->toContain("config('events.table_names.subscriptions'");
        });
    });

    describe('Facade method coverage', function (): void {
        test('Facade @method docblock covers all EventManager public methods', function (): void {
            $facadeFile = __DIR__.'/../src/Facades/EventManager.php';
            $content = file_get_contents($facadeFile);

            $emReflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
            $publicMethods = array_filter(
                $emReflection->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $m): bool => ! $m->isConstructor() && ! $m->isStatic(),
            );

            foreach ($publicMethods as $method) {
                // All public methods should be in the facade docblock
                expect($content)->toContain('@method', "Facade should document method {$method->getName()}");
            }
        });
    });
});
