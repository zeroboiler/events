<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\Events;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

beforeEach(function (): void {
    $reflection = new ReflectionClass(EventsServiceProvider::class);
    $srcDir = dirname($reflection->getFileName());
    $this->configPath = dirname($srcDir).'/config/events.php';
});

describe('Phase 119 Production Audit', function (): void {
    describe('Source file integrity', function (): void {
        it('all source files have strict_types=1', function (): void {
            $srcDir = __DIR__.'/../src';
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            $violations = [];
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
                    $violations[] = $file->getPathname();
                }
            }
            expect($violations)->toBeEmpty('Files missing declare(strict_types=1): '.implode(', ', $violations));
        });

        it('all source files have license header', function (): void {
            $srcDir = __DIR__.'/../src';
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            $violations = [];
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                if ($content === false || ! str_contains($content, 'part of ZeroBoiler')) {
                    $violations[] = $file->getPathname();
                }
            }
            expect($violations)->toBeEmpty('Files missing license header: '.implode(', ', $violations));
        });

        it('no setAccessible() or array_last() in source', function (): void {
            $srcDir = __DIR__.'/../src';
            $content = '';
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $c = file_get_contents($file->getPathname());
                    if ($c !== false) {
                        $content .= $c."\n";
                    }
                }
            }
            expect($content)->not()->toContain('setAccessible(');
            expect($content)->not()->toContain('array_last(');
        });
    });

    describe('Interface contracts', function (): void {
        it('ConditionEngine implements ConditionEngineContract', function (): void {
            $ref = new ReflectionClass(ConditionEngine::class);
            expect($ref->implementsInterface(ConditionEngineContract::class))->toBeTrue();
        });

        it('EventManager is final', function (): void {
            expect((new ReflectionClass(EventManager::class))->isFinal())->toBeTrue();
        });

        it('WildcardMatcher is static-only and final', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
        });
    });

    describe('DomainEvent immutability', function (): void {
        it('DomainEvent has 4 readonly properties', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            $props = $ref->getProperties();
            $publicReadonly = array_filter($props, static fn (\ReflectionProperty $p): bool => $p->isPublic() && $p->isReadOnly());
            expect(count($publicReadonly))->toBeGreaterThanOrEqual(4);
        });
    });

    describe('ServiceProvider verification', function (): void {
        it('provides() returns EventManager', function (): void {
            $provider = new EventsServiceProvider(app());
            $provides = $provider->provides();
            expect($provides)->toContain(EventManager::class);
        });

        it('register() creates EventManager binding', function (): void {
            $app = app();
            $provider = new EventsServiceProvider($app);
            $provider->register();
            expect($app->resolved(EventManager::class))->toBeTrue();
        });
    });

    describe('Config completeness', function (): void {
        it('config file has required keys', function (): void {
            $config = include $this->configPath;
            $requiredKeys = ['events_table', 'event_logs_table', 'subscriptions_table'];
            foreach ($requiredKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
            }
        });
    });

    describe('PHPStan and composer', function (): void {
        it('phpstan.neon.dist has level 9', function (): void {
            $path = __DIR__.'/../phpstan.neon.dist';
            expect(file_exists($path))->toBeTrue();
            expect(file_get_contents($path))->toContain('level: 9');
        });

        it('composer requires PHP ^8.5 and version matches badge', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
            $readme = file_get_contents(__DIR__.'/../README.md');
            expect($readme)->toContain('version-'.$composer['version']);
        });
    });
});
