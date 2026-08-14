<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;

/**
 * Phase 163 Production Audit — setAccessible removal verification,
 * source file completeness, and PHP 8.5 compliance.
 */
describe('Phase 163 — setAccessible removal and PHP 8.5 compliance', function (): void {
    test('no actual setAccessible(true) calls remain in source files', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            // Strip comments to avoid false positives on documentation
            $stripped = preg_replace('#//.*$#m', '', (string) $content);
            $stripped = preg_replace('#/\*.*?\*/#s', '', (string) $stripped);
            expect($stripped)
                ->not->toContain('->setAccessible(');
        }
    });

    test('no actual setAccessible(true) calls remain in test files', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $violations = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());

            // Strip multi-line and single-line comments
            $stripped = preg_replace('#//.*$#m', '', (string) $content);
            $stripped = preg_replace('#/\*.*?\*/#s', '', (string) $stripped);

            if ($stripped !== null && preg_match('/->setAccessible\s*\(/', $stripped)) {
                $violations[] = $file->getFilename();
            }
        }

        expect($violations)->toBeEmpty(
            'setAccessible() calls found in: '.implode(', ', $violations)
        );
    });

    test('EventManager::getConfig throws RuntimeException for non-ConfigRepository', function (): void {
        $container = new Illuminate\Container\Container;
        // Bind a non-ConfigRepository value for 'config'
        $container->instance('config', new stdClass);

        $em = new EventManager(
            new ConditionEngine,
            new ActionResolver($container),
            $container,
        );

        expect(fn (): bool => $em->isDisabled())
            ->throws(RuntimeException::class, 'Config repository not available');
    });

    test('EventManager constructor injection types are correct', function (): void {
        $container = app();
        $em = $container->make(EventManager::class);

        expect($em)->toBeInstanceOf(EventManager::class);
    });

    test('WildcardMatcher is readonly final class', function (): void {
        $reflection = new ReflectionClass(WildcardMatcher::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    });

    test('all service classes are final', function (): void {
        $classes = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
            EventsServiceProvider::class,
            EventManagerFacade::class,
        ];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            expect($reflection->isFinal())
                ->toBeTrue("{$class} must be final");
        }
    });

    test('all console commands are final', function (): void {
        $commands = [
            \ZeroBoiler\Events\Console\EventsListCommand::class,
            \ZeroBoiler\Events\Console\EventsFireCommand::class,
            \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
            \ZeroBoiler\Events\Console\EventsEnableCommand::class,
            \ZeroBoiler\Events\Console\EventsDisableCommand::class,
            \ZeroBoiler\Events\Console\EventsRetryCommand::class,
            \ZeroBoiler\Events\Console\EventsLogCommand::class,
            \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
            \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
            \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
            \ZeroBoiler\Events\Console\EventsHealthCommand::class,
            \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        ];

        foreach ($commands as $class) {
            $reflection = new ReflectionClass($class);
            expect($reflection->isFinal())
                ->toBeTrue("{$class} must be final");
        }
    });

    test('all models are final', function (): void {
        $models = [
            \ZeroBoiler\Events\Models\Trigger::class,
            \ZeroBoiler\Events\Models\EventLog::class,
            \ZeroBoiler\Events\Models\Subscription::class,
        ];

        foreach ($models as $class) {
            $reflection = new ReflectionClass($class);
            expect($reflection->isFinal())
                ->toBeTrue("{$class} must be final");
        }
    });

    test('WebhookAction is final and implements Triggerable', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->implementsInterface(Triggerable::class))->toBeTrue();
    });

    test('DispatchTriggerJob is final', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class);
        expect($reflection->isFinal())->toBeTrue();
    });
});
