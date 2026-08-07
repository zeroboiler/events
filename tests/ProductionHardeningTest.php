<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\DispatchTriggerJob;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;

beforeEach(function (): void {
    $this->app = $this->createApplication();
    $this->provider = new EventsServiceProvider($this->app);
});

describe('Production Hardening v1.36.0', function (): void {
    describe('readonly keyword verification', function (): void {
        test('EventManager constructor properties use readonly keyword not #[Readonly] attribute', function (): void {
            $reflection = new ReflectionClass(EventManager::class);
            $constructor = $reflection->getMethod('__construct');
            $parameters = $constructor->getParameters();

            expect($parameters)->toHaveCount(3);

            foreach ($parameters as $param) {
                // In PHP 8.5, readonly promoted properties have the readonly flag
                // NOT the #[\Readonly] attribute (which was removed)
                $attributes = $param->getAttributes();
                $hasReadonlyAttr = array_filter(
                    $attributes,
                    fn (ReflectionAttribute $a): bool => $a->getName() === 'Readonly'
                        || str_contains($a->getName(), 'Readonly'),
                );

                expect($hasReadonlyAttr)->toBeEmpty(
                    "Parameter \${$param->getName()} should NOT have #[Readonly] attribute"
                );

                // Verify the property IS readonly
                $prop = $reflection->getProperty($param->getName());
                expect($prop->isReadOnly())->toBeTrue(
                    "Property \${$param->getName()} must be readonly"
                );
            }
        });

        test('ActionResolver constructor property uses readonly keyword', function (): void {
            $reflection = new ReflectionClass(ActionResolver::class);
            $prop = $reflection->getProperty('app');
            expect($prop->isReadOnly())->toBeTrue();
        });

        test('TriggerBuilder constructor property uses readonly keyword', function (): void {
            $reflection = new ReflectionClass(TriggerBuilder::class);
            $prop = $reflection->getProperty('eventManager');
            expect($prop->isReadOnly())->toBeTrue();
        });

        test('SubscriptionBuilder constructor property uses readonly keyword', function (): void {
            $reflection = new ReflectionClass(SubscriptionBuilder::class);
            $prop = $reflection->getProperty('eventManager');
            expect($prop->isReadOnly())->toBeTrue();
        });

        test('DispatchTriggerJob promoted properties use readonly keyword', function (): void {
            $reflection = new ReflectionClass(DispatchTriggerJob::class);
            $constructor = $reflection->getMethod('__construct');
            $parameters = $constructor->getParameters();

            expect($parameters)->toHaveCount(3);

            foreach ($parameters as $param) {
                $prop = $reflection->getProperty($param->getName());
                expect($prop->isReadOnly())->toBeTrue(
                    "Property \${$param->getName()} must be readonly"
                );
            }
        });
    });

    describe('no #[Readonly] attribute in any source file', function (): void {
        test('no source file contains #[Readonly] attribute', function (): void {
            $srcDir = realpath(__DIR__.'/../src');
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
                if (str_contains($contents, '#[\\Readonly]')) {
                    $violations[] = $file->getPathname();
                }
            }

            expect($violations)->toBeEmpty(
                'No source files should contain #[\\Readonly] attribute. Found in: '.implode(', ', $violations)
            );
        });
    });

    describe('ServiceProvider binding correctness', function (): void {
        test('TriggerBuilder is registered as transient (not shared)', function (): void {
            $this->provider->register();

            // Transient bindings should create new instances each time
            $a = $this->app->make(TriggerBuilder::class);
            $b = $this->app->make(TriggerBuilder::class);

            expect($a)->toBeInstanceOf(TriggerBuilder::class);
            expect($b)->toBeInstanceOf(TriggerBuilder::class);
            // For transient bindings, instances should be different objects
            expect($a)->not->toBe($b);
        });

        test('SubscriptionBuilder is registered as transient (not shared)', function (): void {
            $this->provider->register();

            $a = $this->app->make(SubscriptionBuilder::class);
            $b = $this->app->make(SubscriptionBuilder::class);

            expect($a)->toBeInstanceOf(SubscriptionBuilder::class);
            expect($a)->not->toBe($b);
        });

        test('ConditionEngine is registered as singleton (shared)', function (): void {
            $this->provider->register();

            $a = $this->app->make(ConditionEngine::class);
            $b = $this->app->make(ConditionEngine::class);

            expect($a)->toBe($b);
        });

        test('ConditionEngineContract resolves to ConditionEngine singleton', function (): void {
            $this->provider->register();

            $contract = $this->app->make(ConditionEngineContract::class);
            $concrete = $this->app->make(ConditionEngine::class);

            expect($contract)->toBeInstanceOf(ConditionEngine::class);
            expect($contract)->toBe($concrete);
        });

        test('EventManager is registered as singleton (shared)', function (): void {
            $this->provider->register();

            $a = $this->app->make(EventManager::class);
            $b = $this->app->make(EventManager::class);

            expect($a)->toBe($b);
        });
    });

    describe('Config merge completeness', function (): void {
        test('config is merged with all expected top-level keys', function (): void {
            $this->provider->register();

            $config = $this->app->get('config');
            assert($config instanceof \Illuminate\Contracts\Config\Repository);

            // Verify all top-level keys are present
            expect($config->get('events.table_names'))->not->toBeNull();
            expect($config->get('events.queue'))->not->toBeNull();
            expect($config->get('events.retry'))->not->toBeNull();
            expect($config->get('events.retention'))->not->toBeNull();
            expect($config->get('events.subscriptions'))->not->toBeNull();
            expect($config->get('events.wildcard_cache_ttl'))->not->toBeNull();
        });

        test('config table_names has all three table entries', function (): void {
            $this->provider->register();
            $config = $this->app->get('config');
            assert($config instanceof \Illuminate\Contracts\Config\Repository);

            $tables = $config->get('events.table_names');
            expect($tables)->toBeArray();
            expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
        });

        test('config subscriptions has all expected keys', function (): void {
            $this->provider->register();
            $config = $this->app->get('config');
            assert($config instanceof \Illuminate\Contracts\Config\Repository);

            $subs = $config->get('events.subscriptions');
            expect($subs)->toBeArray();
            expect($subs)->toHaveKeys([
                'auto_generate_secret',
                'max_failures',
                'timeout',
                'signature_algorithm',
            ]);
        });

        test('config retry has tries and backoff', function (): void {
            $this->provider->register();
            $config = $this->app->get('config');
            assert($config instanceof \Illuminate\Contracts\Config\Repository);

            $retry = $config->get('events.retry');
            expect($retry)->toBeArray();
            expect($retry)->toHaveKey('tries');
            expect($retry)->toHaveKey('backoff');
        });
    });

    describe('Pest.php test inclusion completeness', function (): void {
        test('all test files in tests/ directory that need TestCase are listed in Pest.php uses()', function (): void {
            $testsDir = realpath(__DIR__);
            $pestContent = file_get_contents($testsDir.'/Pest.php');
            assert($pestContent !== false);

            $testFiles = glob($testsDir.'/*Test.php');
            // Files that don't need TestCase (plain PHP)
            $excludeFiles = ['WildcardMatcherTest.php', 'EscapesWildcardLikeTest.php', 'CreatesApplication.php'];

            $missing = [];
            foreach ($testFiles as $file) {
                $basename = basename($file);
                if (in_array($basename, $excludeFiles, true)) {
                    continue;
                }
                if (! str_contains($pestContent, $basename)) {
                    $missing[] = $basename;
                }
            }

            expect($missing)->toBeEmpty(
                'Pest.php uses() is missing these test files: '.implode(', ', $missing)
            );
        });
    });
});
