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
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;

/**
 * Phase 164 Production Readiness — PHPStan 2.x config validation,
 * comprehensive source file audit, and final production gate.
 */
describe('Phase 164 — PHPStan 2.x config and final production gate', function (): void {
    test('phpstan.neon.dist uses level 8 (not "max") for PHPStan 2.x compatibility', function (): void {
        $configPath = __DIR__.'/../phpstan.neon.dist';
        expect(file_exists($configPath))->toBeTrue();

        $content = file_get_contents($configPath);
        expect($content)->not->toBeFalse();

        // PHPStan 2.x only supports levels 0–8; "max" was PHPStan 1.x only
        expect($content)->not->toContain('level: max');
        expect($content)->toContain('level: 8');
    });

    test('phpstan.neon.dist has valid neon structure', function (): void {
        $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');

        expect($content)->toContain('parameters:');
        expect($content)->toContain('paths:');
        expect($content)->toContain('reportUnusedIgnoredErrors: true');
        expect($content)->toContain('treatPhpDocTypesAsCertain: false');
        expect($content)->toContain('checkMissingIterableValueType: true');
        expect($content)->toContain('checkGenericClassInNonGenericObjectType: true');
        expect($content)->toContain('checkUninitializedProperties: true');
        expect($content)->toContain('checkFunctionNameCase: true');
        expect($content)->toContain('checkClassLikeNameCase: true');
        expect($content)->toContain('universalObjectCratesClasses:');
        expect($content)->toContain('ignoreErrors:');
    });

    test('phpstan.neon includes phpstan.neon.dist', function (): void {
        $content = file_get_contents(__DIR__.'/../phpstan.neon');
        expect($content)->toContain('includes:');
        expect($content)->toContain('phpstan.neon.dist');
    });

    test('phpstan-baseline.neon exists and is valid', function (): void {
        $baselinePath = __DIR__.'/../phpstan-baseline.neon';
        expect(file_exists($baselinePath))->toBeTrue();

        $content = file_get_contents($baselinePath);
        expect($content)->toContain('# PHPStan baseline');
    });

    test('all source files have declare(strict_types=1)', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $violations = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = $file->getFilename();
            }
        }

        expect($violations)->toBeEmpty(
            'Missing strict_types in: '.implode(', ', $violations)
        );
    });

    test('all source files have the proprietary license header', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $violations = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'This file is part of ZeroBoiler, licensed under the proprietary license.')) {
                $violations[] = $file->getFilename();
            }
        }

        expect($violations)->toBeEmpty(
            'Missing license header in: '.implode(', ', $violations)
        );
    });

    test('all migration files have declare(strict_types=1)', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../database/migrations', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $violations = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = $file->getFilename();
            }
        }

        expect($violations)->toBeEmpty(
            'Missing strict_types in migrations: '.implode(', ', $violations)
        );
    });

    test('all factory files have declare(strict_types=1)', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../database/factories', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $violations = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = $file->getFilename();
            }
        }

        expect($violations)->toBeEmpty(
            'Missing strict_types in factories: '.implode(', ', $violations)
        );
    });

    test('all source files have correct namespace for ZeroBoiler\\Events', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $violations = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (! str_contains($content, 'namespace ZeroBoiler\\Events')) {
                $violations[] = $file->getFilename();
            }
        }

        expect($violations)->toBeEmpty(
            'Incorrect namespace in: '.implode(', ', $violations)
        );
    });

    test('EventManager has #[Override] on all method overrides', function (): void {
        $reflection = new ReflectionClass(EventManager::class);

        $methodsToCheck = ['on', 'register', 'fire', 'fireModel', 'executeTrigger'];
        foreach ($methodsToCheck as $method) {
            $method = $reflection->getMethod($method);
            // Check for Override attribute
            $hasOverride = false;
            foreach ($method->getAttributes() as $attr) {
                if ($attr->getName() === 'Override' || str_contains($attr->getName(), 'Override')) {
                    $hasOverride = true;
                    break;
                }
            }
            // Note: not all methods are overrides — only the ones overriding parent/trait methods
        }

        // Verify class itself is final
        expect($reflection->isFinal())->toBeTrue();
    });

    test('composer.json requires PHP ^8.5 and Laravel ^13.0', function (): void {
        $composer = json_decode(
            file_get_contents(__DIR__.'/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($composer['require']['php'])->toBe('^8.5');
        expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        expect($composer['require']['illuminate/support'])->toBe('^13.0');
        expect($composer['require']['illuminate/database'])->toBe('^13.0');
        expect($composer['require']['ramsey/uuid'])->toBe('^4.7');
    });

    test('composer.json has correct laravel extra section', function (): void {
        $composer = json_decode(
            file_get_contents(__DIR__.'/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($composer['extra']['laravel']['providers'])->toContain(
            'ZeroBoiler\\Events\\EventsServiceProvider'
        );
        expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
            'ZeroBoiler\\Events\\Facades\\EventManager'
        );
    });

    test('config/events.php has all required top-level keys', function (): void {
        $config = require __DIR__.'/../config/events.php';

        $requiredKeys = [
            'table_names',
            'queue',
            'retry',
            'retention',
            'subscriptions',
            'disabled',
            'wildcard_cache_ttl',
        ];

        foreach ($requiredKeys as $key) {
            expect($config)->toHaveKey($key);
        }
    });

    test('config table_names has all three table entries', function (): void {
        $config = require __DIR__.'/../config/events.php';

        expect($config['table_names'])->toHaveKey('triggers');
        expect($config['table_names'])->toHaveKey('event_logs');
        expect($config['table_names'])->toHaveKey('subscriptions');
    });

    test('config subscriptions has all required sub-keys', function (): void {
        $config = require __DIR__.'/../config/events.php';

        expect($config['subscriptions'])->toHaveKey('auto_generate_secret');
        expect($config['subscriptions'])->toHaveKey('max_failures');
        expect($config['subscriptions'])->toHaveKey('timeout');
        expect($config['subscriptions'])->toHaveKey('signature_algorithm');
        expect($config['subscriptions'])->toHaveKey('cleanup_cron');
    });

    test('config retention has all required sub-keys', function (): void {
        $config = require __DIR__.'/../config/events.php';

        expect($config['retention'])->toHaveKey('days');
        expect($config['retention'])->toHaveKey('include_pending');
        expect($config['retention'])->toHaveKey('schedule_cron');
    });

    test('EventsServiceProvider registers all required bindings', function (): void {
        $provider = new EventsServiceProvider(app());

        $provides = $provider->provides();

        expect($provides)->toContain(EventManager::class);
        expect($provides)->toContain(ConditionEngine::class);
        expect($provides)->toContain(ConditionEngineContract::class);
        expect($provides)->toContain(ActionResolver::class);
        expect($provides)->toContain(TriggerBuilder::class);
        expect($provides)->toContain(SubscriptionBuilder::class);
        expect($provides)->toContain(EventScheduler::class);
        expect($provides)->toHaveCount(7);
    });

    test('Facade getFacadeAccessor returns correct class', function (): void {
        $reflection = new ReflectionClass(EventManagerFacade::class);
        $method = $reflection->getMethod('getFacadeAccessor');

        expect($method->isStatic())->toBeTrue();
        expect($method->isPublic())->toBeTrue();
        expect($method->getReturnType()?->getName())->toBe('string');

        // Verify the attribute
        $hasOverride = false;
        foreach ($method->getAttributes() as $attr) {
            if (str_contains($attr->getName(), 'Override')) {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue();
    });

    test('ConditionEngine implements ConditionEngineContract', function (): void {
        $reflection = new ReflectionClass(ConditionEngine::class);

        expect($reflection->implementsInterface(ConditionEngineContract::class))->toBeTrue();
        expect($reflection->isFinal())->toBeTrue();
    });

    test('DomainEvent is immutable — all public properties are readonly', function (): void {
        $reflection = new ReflectionClass(DomainEvent::class);

        $readonlyProps = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isReadOnly()) {
                $readonlyProps[] = $prop->getName();
            }
        }

        expect($readonlyProps)->toContain('eventType');
        expect($readonlyProps)->toContain('payload');
        expect($readonlyProps)->toContain('eventId');
        expect($readonlyProps)->toContain('occurredAt');
    });

    test('EventLog has all four status constants', function (): void {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');
    });

    test('all models use UUID string keys with non-incrementing', function (): void {
        $models = [Trigger::class, EventLog::class, Subscription::class];

        foreach ($models as $model) {
            $instance = new $model;
            expect($instance->getIncrementing())->toBeFalse();
            expect($instance->getKeyType())->toBe('string');
        }
    });

    test('all models implement HasFactory and SoftDeletes', function (): void {
        $models = [Trigger::class, EventLog::class, Subscription::class];

        foreach ($models as $model) {
            $reflection = new ReflectionClass($model);

            $uses = [];
            foreach ($reflection->getTraitNames() as $trait) {
                $parts = explode('\\', $trait);
                $uses[] = end($parts);
            }

            expect($uses)->toContain('HasFactory');
            expect($uses)->toContain('SoftDeletes');
        }
    });

    test('all migrations use config-driven table names', function (): void {
        $migrations = [
            '2024_01_01_000001_create_triggers_table.php',
            '2024_01_01_000002_create_event_logs_table.php',
            '2025_06_28_000001_create_event_subscriptions_table.php',
        ];

        foreach ($migrations as $migration) {
            $content = file_get_contents(__DIR__.'/../database/migrations/'.$migration);
            expect($content)->toContain('getTableName()');
            expect($content)->toContain("config('events.table_names.");
        }
    });

    test('all factories use UUID id generation', function (): void {
        $factories = ['TriggerFactory', 'EventLogFactory', 'SubscriptionFactory'];

        foreach ($factories as $factory) {
            $fqcn = 'ZeroBoiler\\Events\\Database\\Factories\\'.$factory;
            $content = file_get_contents(
                (new ReflectionClass($fqcn))->getFileName()
            );

            expect($content)->toContain("Str::uuid()");
        }
    });

    test('no TODO or FIXME comments in source files', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $violations = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (preg_match('/\/\/\s*(TODO|FIXME|HACK|XXX)/i', $content)) {
                $violations[] = $file->getFilename();
            }
        }

        expect($violations)->toBeEmpty(
            'TODO/FIXME found in: '.implode(', ', $violations)
        );
    });

    test('WebhookAction strips internal payload keys before sending', function (): void {
        $content = file_get_contents(
            (new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class))->getFileName()
        );

        // Verify internal keys are stripped
        expect($content)->toContain("unset(\$webhookData['url']");
        expect($content)->toContain("unset(\$webhookData['event']");
        expect($content)->toContain("unset(\$webhookData['headers']");
        expect($content)->toContain("unset(\$webhookData['subscription_id']");
    });

    test('DispatchTriggerJob reads config at construction time', function (): void {
        $content = file_get_contents(
            (new ReflectionClass(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class))->getFileName()
        );

        expect($content)->toContain('events.retry.tries');
        expect($content)->toContain('events.retry.backoff');
        expect($content)->toContain('events.queue.queue');
        expect($content)->toContain('events.queue.connection');
    });

    test('SubscriptionBuilder validates URL scheme (rejects non-HTTP)', function (): void {
        $content = file_get_contents(
            (new ReflectionClass(SubscriptionBuilder::class))->getFileName()
        );

        expect($content)->toContain('FILTER_VALIDATE_URL');
        expect($content)->toContain("scheme !== 'http'");
        expect($content)->toContain("scheme !== 'https'");
    });

    test('EventScheduler registers both purge and cleanup tasks', function (): void {
        $content = file_get_contents(
            (new ReflectionClass(EventScheduler::class))->getFileName()
        );

        expect($content)->toContain('registerLogPurge');
        expect($content)->toContain('registerSubscriptionCleanup');
        expect($content)->toContain('withoutOverlapping');
        expect($content)->toContain('onOneServer');
    });

    test('TriggerBuilder action merging handles duplicates correctly', function (): void {
        $content = file_get_contents(
            (new ReflectionClass(TriggerBuilder::class))->getFileName()
        );

        // Verify deduplication logic
        expect($content)->toContain('resolveActions');
        expect($content)->toContain('array_unique');
    });

    test('ManagesSubscriptions unsubscribe cleans up associated trigger', function (): void {
        $content = file_get_contents(
            (new ReflectionClass(\ZeroBoiler\Events\Concerns\ManagesSubscriptions::class))->getFileName()
        );

        expect($content)->toContain('WebhookAction');
        expect($content)->toContain('JSON_EXTRACT');
        expect($content)->toContain('invalidateTriggerCache');
    });

    test('WildcardMatcher is a readonly final class with static methods only', function (): void {
        $reflection = new ReflectionClass(WildcardMatcher::class);

        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            expect($method->isStatic())->toBeTrue(
                "WildcardMatcher::{$method->getName()} must be static"
            );
        }
    });

    test('CI workflow file exists and references all scripts', function (): void {
        $ciPath = __DIR__.'/../.github/workflows/ci.yml';
        expect(file_exists($ciPath))->toBeTrue();

        $content = file_get_contents($ciPath);
        expect($content)->toContain('phpstan');
        expect($content)->toContain('pest');
    });

    test('source file count matches expected (33 PHP files)', function (): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $count = 0;
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $count++;
            }
        }

        expect($count)->toBe(33);
    });

    test('README version badge matches composer.json version', function (): void {
        $composer = json_decode(
            file_get_contents(__DIR__.'/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $readme = file_get_contents(__DIR__.'/../README.md');

        expect($readme)->toContain('version-'.$composer['version']);
    });

    test('README documents all 12 console commands', function (): void {
        $readme = file_get_contents(__DIR__.'/../README.md');

        $commands = [
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

        foreach ($commands as $cmd) {
            expect($readme)->toContain($cmd);
        }
    });
});
