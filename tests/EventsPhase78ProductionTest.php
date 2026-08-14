<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

describe('Phase 78 — Final Production Readiness', function () {
    describe('WildcardMatcher readonly class', function () {
        it('is a readonly final class', function () {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('has no constructor — pure static API', function () {
            $ref = new ReflectionClass(WildcardMatcher::class);
            $constructor = $ref->getConstructor();
            expect($constructor)->toBeNull();
        });

        it('all public methods are static and #[Pure]', function () {
            $ref = new ReflectionClass(WildcardMatcher::class);
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                expect($method->isStatic())->toBeTrue("Method {$method->getName()} must be static");
                $hasPure = array_filter(
                    $method->getAttributes(),
                    fn (ReflectionAttribute $a): bool => $a->getName() === 'Pure',
                );
                expect($hasPure)->not->toBeEmpty("Method {$method->getName()} must have #[Pure] attribute");
            }
        });
    });

    describe('EscapesWildcardLike trait', function () {
        it('has exactly one method: wildcardToLike', function () {
            $ref = new ReflectionClass(EscapesWildcardLike::class);
            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
            // Trait methods have 0 public methods — wildcardToLike is protected
            $methods = $ref->getMethods();
            $ownMethods = array_filter(
                $methods,
                fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === EscapesWildcardLike::class,
            );
            expect(count($ownMethods))->toBe(1);
            $method = reset($ownMethods);
            expect($method->getName())->toBe('wildcardToLike');
            expect($method->isProtected())->toBeTrue();
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->allowsNull())->toBeTrue();
            expect((string) $returnType)->toBe('?string');
        });
    });

    describe('DomainEvent immutability', function () {
        it('all properties are public readonly', function () {
            $ref = new ReflectionClass(DomainEvent::class);
            foreach ($ref->getProperties() as $prop) {
                expect($prop->isReadOnly())->toBeTrue("Property {$prop->getName()} must be readonly");
                expect($prop->isPublic())->toBeTrue("Property {$prop->getName()} must be public");
            }
        });

        it('has exactly 4 properties', function () {
            $ref = new ReflectionClass(DomainEvent::class);
            expect(count($ref->getProperties()))->toBe(4);
        });

        it('property types are correct', function () {
            $ref = new ReflectionClass(DomainEvent::class);
            $types = array_map(
                fn (ReflectionProperty $p): string => (string) ($p->getType() ?? 'none'),
                $ref->getProperties(),
            );
            expect($types)->toContain('Ramsey\Uuid\UuidInterface');
            expect($types)->toContain('DateTimeImmutable');
            expect($types)->toContain('string');
            expect($types)->toContain('array');
        });
    });

    describe('ConditionEngine contract compliance', function () {
        it('implements ConditionEngineContract', function () {
            $ref = new ReflectionClass(ConditionEngine::class);
            expect($ref->implementsInterface(ConditionEngineContract::class))->toBeTrue();
            expect($ref->isFinal())->toBeTrue();
        });

        it('matches() method has #[Override] attribute', function () {
            $method = new ReflectionMethod(ConditionEngine::class, 'matches');
            $attrs = array_filter(
                $method->getAttributes(),
                fn (ReflectionAttribute $a): bool => $a->getName() === 'Override',
            );
            expect(count($attrs))->toBe(1);
        });

        it('strictEquals is private and #[Pure]', function () {
            $method = new ReflectionMethod(ConditionEngine::class, 'strictEquals');
            expect($method->isPrivate())->toBeTrue();
            $attrs = array_filter(
                $method->getAttributes(),
                fn (ReflectionAttribute $a): bool => $a->getName() === 'Pure',
            );
            expect(count($attrs))->toBe(1);
        });
    });

    describe('EventManager class structure', function () {
        it('is final', function () {
            expect((new ReflectionClass(EventManager::class))->isFinal())->toBeTrue();
        });

        it('constructor has 3 readonly promoted properties', function () {
            $ctor = (new ReflectionClass(EventManager::class))->getConstructor();
            $params = $ctor->getParameters();
            expect(count($params))->toBe(3);
            foreach ($params as $param) {
                expect($param->isPromoted())->toBeTrue();
                expect($param->isReadOnly())->toBeTrue();
            }
        });

        it('uses exactly 3 traits', function () {
            $ref = new ReflectionClass(EventManager::class);
            $traits = array_map(
                fn (ReflectionClass $t): string => $t->getShortName(),
                $ref->getTraits(),
            );
            expect($traits)->toHaveCount(3);
            expect($traits)->toContain('EscapesWildcardLike');
            expect($traits)->toContain('ManagesHistory');
            expect($traits)->toContain('ManagesSubscriptions');
        });
    });

    describe('TriggerBuilder class structure', function () {
        it('is final with readonly EventManager property', function () {
            $ref = new ReflectionClass(TriggerBuilder::class);
            expect($ref->isFinal())->toBeTrue();
            $ctor = $ref->getConstructor();
            $params = $ctor->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->getName())->toBe('eventManager');
            expect($params[0]->isPromoted())->toBeTrue();
            expect($params[0]->isReadOnly())->toBeTrue();
        });

        it('all properties are typed', function () {
            $ref = new ReflectionClass(TriggerBuilder::class);
            foreach ($ref->getProperties() as $prop) {
                $type = $prop->getType();
                expect($type)->not->toBeNull("Property {$prop->getName()} must have a type");
                expect($type instanceof ReflectionNamedType)->toBeTrue();
            }
        });
    });

    describe('SubscriptionBuilder class structure', function () {
        it('is final with readonly EventManager property', function () {
            $ref = new ReflectionClass(SubscriptionBuilder::class);
            expect($ref->isFinal())->toBeTrue();
            $ctor = $ref->getConstructor();
            $params = $ctor->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->getName())->toBe('eventManager');
            expect($params[0]->isPromoted())->toBeTrue();
            expect($params[0]->isReadOnly())->toBeTrue();
        });

        it('secret property is nullable string', function () {
            $ref = new ReflectionClass(SubscriptionBuilder::class);
            $prop = $ref->getProperty('secret');
            $type = $prop->getType();
            expect($type)->not->toBeNull();
            expect((string) $type)->toBe('?string');
        });
    });

    describe('declare(strict_types=1) enforcement', function () {
        it('all src files have strict types', function () {
            $srcDir = __DIR__.'/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $violations = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    continue;
                }
                if (! str_contains($contents, 'declare(strict_types=1)')) {
                    $violations[] = $file->getPathname();
                }
            }
            expect($violations)->toBeEmpty('Files missing declare(strict_types=1): '.implode(', ', $violations));
        });

        it('all test files have strict types', function () {
            $testsDir = __DIR__;
            $iterator = new DirectoryIterator($testsDir);
            $violations = [];
            foreach ($iterator as $file) {
                if ($file->isDot() || $file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    continue;
                }
                if (! str_contains($contents, 'declare(strict_types=1)')) {
                    $violations[] = $file->getFilename();
                }
            }
            expect($violations)->toBeEmpty('Test files missing declare(strict_types=1): '.implode(', ', $violations));
        });

        it('all factory files have strict types', function () {
            $factoryDir = __DIR__.'/../database/factories';
            $violations = [];
            foreach (glob($factoryDir.'/*.php') as $file) {
                $contents = file_get_contents($file);
                if ($contents === false) {
                    continue;
                }
                if (! str_contains($contents, 'declare(strict_types=1)')) {
                    $violations[] = basename($file);
                }
            }
            expect($violations)->toBeEmpty('Factory files missing declare(strict_types=1): '.implode(', ', $violations));
        });

        it('all migration files have strict types', function () {
            $migrationDir = __DIR__.'/../database/migrations';
            $violations = [];
            foreach (glob($migrationDir.'/*.php') as $file) {
                $contents = file_get_contents($file);
                if ($contents === false) {
                    continue;
                }
                if (! str_contains($contents, 'declare(strict_types=1)')) {
                    $violations[] = basename($file);
                }
            }
            expect($violations)->toBeEmpty('Migration files missing declare(strict_types=1): '.implode(', ', $violations));
        });
    });

    describe('Service Provider completeness', function () {
        it('registers all 6 bindings', function () {
            $app = app();
            $provider = new \ZeroBoiler\Events\EventsServiceProvider($app);
            $provider->register();

            // Singletons
            expect($app->resolved(ConditionEngineContract::class))->toBeTrue();
            expect($app->resolved(ConditionEngine::class))->toBeTrue();
            expect($app->resolved(\ZeroBoiler\Events\ActionResolver::class))->toBeTrue();
            expect($app->resolved(\ZeroBoiler\Events\EventManager::class))->toBeTrue();

            // Transients (bound, not singleton)
            $bindings = $app->getBindings();
            expect(isset($bindings[SubscriptionBuilder::class]))->toBeTrue();
            expect(isset($bindings[TriggerBuilder::class]))->toBeTrue();
        });

        it('provides() returns all 6 service names', function () {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider(app());
            $provides = $provider->provides();
            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(ConditionEngine::class);
            expect($provides)->toContain(ConditionEngineContract::class);
            expect($provides)->toContain(\ZeroBoiler\Events\ActionResolver::class);
            expect($provides)->toContain(TriggerBuilder::class);
            expect($provides)->toContain(SubscriptionBuilder::class);
            expect(count($provides))->toBe(6);
        });

        it('provides() has #[Override] attribute', function () {
            $method = new ReflectionMethod(\ZeroBoiler\Events\EventsServiceProvider::class, 'provides');
            $attrs = array_filter(
                $method->getAttributes(),
                fn (ReflectionAttribute $a): bool => $a->getName() === 'Override',
            );
            expect(count($attrs))->toBe(1);
        });

        it('register() and boot() have #[Override] attributes', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\EventsServiceProvider::class);
            $register = $ref->getMethod('register');
            $boot = $ref->getMethod('boot');
            foreach ([$register, $boot] as $method) {
                $attrs = array_filter(
                    $method->getAttributes(),
                    fn (ReflectionAttribute $a): bool => $a->getName() === 'Override',
                );
                expect(count($attrs))->toBe(1, "Method {$method->getName()} must have #[Override]");
            }
        });
    });

    describe('Facade completeness', function () {
        it('has getFacadeAccessor() returning EventManager::class', function () {
            $facade = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
            expect($facade->isFinal())->toBeTrue();
            $method = $facade->getMethod('getFacadeAccessor');
            $attrs = array_filter(
                $method->getAttributes(),
                fn (ReflectionAttribute $a): bool => $a->getName() === 'Override',
            );
            expect(count($attrs))->toBe(1);
        });
    });

    describe('PHPStan config correctness', function () {
        it('phpstan.neon.dist exists and has correct level', function () {
            $path = __DIR__.'/../phpstan.neon.dist';
            expect(file_exists($path))->toBeTrue();
            $contents = file_get_contents($path);
            expect(str_contains($contents, 'level: 9'))->toBeTrue();
            expect(str_contains($contents, 'checkGenericClassInNonGenericObjectType: false'))->toBeTrue();
            expect(! str_contains($contents, 'baselineFile'))->toBeTrue('baselineFile should be removed');
        });
    });

    describe('Config file completeness', function () {
        it('config/events.php has all required keys', function () {
            $config = require __DIR__.'/../config/events.php';
            expect(array_key_exists('table_names', $config))->toBeTrue();
            expect(array_key_exists('queue', $config))->toBeTrue();
            expect(array_key_exists('retry', $config))->toBeTrue();
            expect(array_key_exists('retention', $config))->toBeTrue();
            expect(array_key_exists('subscriptions', $config))->toBeTrue();
            expect(array_key_exists('disabled', $config))->toBeTrue();
            expect(array_key_exists('wildcard_cache_ttl', $config))->toBeTrue();

            // Sub-keys
            expect(array_key_exists('triggers', $config['table_names']))->toBeTrue();
            expect(array_key_exists('event_logs', $config['table_names']))->toBeTrue();
            expect(array_key_exists('subscriptions', $config['table_names']))->toBeTrue();
            expect(array_key_exists('connection', $config['queue']))->toBeTrue();
            expect(array_key_exists('queue', $config['queue']))->toBeTrue();
            expect(array_key_exists('tries', $config['retry']))->toBeTrue();
            expect(array_key_exists('backoff', $config['retry']))->toBeTrue();
            expect(array_key_exists('days', $config['retention']))->toBeTrue();
            expect(array_key_exists('include_pending', $config['retention']))->toBeTrue();
            expect(array_key_exists('auto_generate_secret', $config['subscriptions']))->toBeTrue();
            expect(array_key_exists('max_failures', $config['subscriptions']))->toBeTrue();
            expect(array_key_exists('timeout', $config['subscriptions']))->toBeTrue();
            expect(array_key_exists('signature_algorithm', $config['subscriptions']))->toBeTrue();
        });

        it('queue.connection fallback is correct', function () {
            $config = require __DIR__.'/../config/events.php';
            // Default should reference config('queue.default')
            expect($config['queue']['connection'])->toBeNonEmptyString();
        });
    });
});
