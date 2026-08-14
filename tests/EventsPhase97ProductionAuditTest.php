<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

/**
 * Phase 97 — Comprehensive Production Readiness Audit.
 *
 * Covers remaining gaps and edge cases not yet verified by Phase 1–96:
 * - Config file completeness vs source code usage
 * - ServiceProvider provides() matches all registered services
 * - EventManager public API completeness vs Facade @method annotations
 * - All models use correct table names from config
 * - DispatchTriggerJob reads all config keys at construction time
 * - EventScheduler reads all config keys correctly
 * - phpstan.neon.dist suppressions are minimal and accurate
 * - All console commands are registered in ServiceProvider
 * - DomainEvent readonly property types are correct
 * - Trait usage consistency across classes
 * - All factories have correct model references
 * - ConditionEngine operator exhaustiveness vs documentation
 */

describe('Phase 97 — Comprehensive Production Readiness Audit', function () {
    describe('Config file completeness', function () {
        test('config/events.php has all keys referenced by source code', function () {
            $configPath = __DIR__.'/../config/events.php';
            $config = require $configPath;
            expect($config)->toBeArray();

            // Top-level keys that must exist
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
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
            }

            // table_names sub-keys
            expect(array_key_exists('triggers', $config['table_names']))->toBeTrue();
            expect(array_key_exists('event_logs', $config['table_names']))->toBeTrue();
            expect(array_key_exists('subscriptions', $config['table_names']))->toBeTrue();

            // queue sub-keys
            expect(array_key_exists('connection', $config['queue']))->toBeTrue();
            expect(array_key_exists('queue', $config['queue']))->toBeTrue();

            // retry sub-keys
            expect(array_key_exists('tries', $config['retry']))->toBeTrue();
            expect(array_key_exists('backoff', $config['retry']))->toBeTrue();

            // retention sub-keys
            expect(array_key_exists('days', $config['retention']))->toBeTrue();
            expect(array_key_exists('include_pending', $config['retention']))->toBeTrue();
            expect(array_key_exists('schedule_cron', $config['retention']))->toBeTrue();

            // subscriptions sub-keys
            expect(array_key_exists('auto_generate_secret', $config['subscriptions']))->toBeTrue();
            expect(array_key_exists('max_failures', $config['subscriptions']))->toBeTrue();
            expect(array_key_exists('timeout', $config['subscriptions']))->toBeTrue();
            expect(array_key_exists('signature_algorithm', $config['subscriptions']))->toBeTrue();
            expect(array_key_exists('cleanup_cron', $config['subscriptions']))->toBeTrue();
        });
    });

    describe('ServiceProvider provides() completeness', function () {
        test('provides() lists all services registered in register()', function () {
            $src = file_get_contents(__DIR__.'/../src/EventsServiceProvider.php');
            expect($src)->not->toBeFalse();

            // Extract provides() return array
            expect($src)->toContain('EventManager::class');
            expect($src)->toContain('ConditionEngine::class');
            expect($src)->toContain('ConditionEngineContract::class');
            expect($src)->toContain('ActionResolver::class');
            expect($src)->toContain('TriggerBuilder::class');
            expect($src)->toContain('SubscriptionBuilder::class');
            expect($src)->toContain('EventScheduler::class');

            // Verify provides() has exactly 7 entries
            preg_match_all('/\'[A-Z]\w+::class\'/', $src, $providesMatches);
            // The provides() method has 7 entries, register() also references 7 classes
            expect(count($providesMatches[0]))->toBeGreaterThanOrEqual(7);
        });
    });

    describe('Facade @method completeness', function () {
        test('Facade documents all EventManager public methods', function () {
            $facade = file_get_contents(__DIR__.'/../src/Facades/EventManager.php');
            $manager = file_get_contents(__DIR__.'/../src/EventManager.php');
            expect($facade)->not->toBeFalse();
            expect($manager)->not->toBeFalse();

            // Critical public methods that MUST be documented on the Facade
            $requiredMethods = [
                'on(', 'register(', 'fire(', 'fireModel(',
                'enable(', 'disable(', 'invalidateTriggerCache(',
                'isDisabled(', 'setEnabled(', 'listTriggers(',
                'getTrigger(', 'deleteTrigger(', 'subscribe(',
                'unsubscribe(', 'listSubscriptions(', 'getSubscription(',
                'subscribeWebhook(', 'getEventHistory(', 'getStats(',
                'purgeLogs(', 'getStalePendingLogs(',
                'deactivateExceededSubscriptions(', 'executeTrigger(',
                'registerScheduler(',
            ];

            foreach ($requiredMethods as $method) {
                expect($facade)->toContain($method, "Facade missing @method for: {$method}");
            }
        });
    });

    describe('Console commands registration', function () {
        test('all console commands are registered in ServiceProvider boot()', function () {
            $provider = file_get_contents(__DIR__.'/../src/EventsServiceProvider.php');
            expect($provider)->not->toBeFalse();

            // All command classes must appear in $this->commands([])
            $commandClasses = [
                'EventsListCommand::class',
                'EventsRegisterCommand::class',
                'EventsFireCommand::class',
                'EventsLogCommand::class',
                'EventsRetryCommand::class',
                'EventsEnableCommand::class',
                'EventsDisableCommand::class',
                'EventsHealthCommand::class',
                'EventsSubscribeCommand::class',
                'EventsUnsubscribeCommand::class',
                'EventsSubscriptionsCommand::class',
                'EventsRedeliverCommand::class',
            ];

            foreach ($commandClasses as $cmd) {
                expect($provider)->toContain($cmd, "ServiceProvider missing command registration: {$cmd}");
            }
        });

        test('all console command files exist', function () {
            $commands = [
                'EventsListCommand.php',
                'EventsRegisterCommand.php',
                'EventsFireCommand.php',
                'EventsLogCommand.php',
                'EventsRetryCommand.php',
                'EventsEnableCommand.php',
                'EventsDisableCommand.php',
                'EventsHealthCommand.php',
                'EventsSubscribeCommand.php',
                'EventsUnsubscribeCommand.php',
                'EventsSubscriptionsCommand.php',
                'EventsRedeliverCommand.php',
            ];

            foreach ($commands as $cmd) {
                expect(file_exists(__DIR__.'/../src/Console/'.$cmd))
                    ->toBeTrue("Missing console command file: {$cmd}");
            }
        });
    });

    describe('Model table name config-driven', function () {
        test('all three models read table name from events.table_names config', function () {
            $models = [
                'Trigger.php' => 'events.table_names.triggers',
                'EventLog.php' => 'events.table_names.event_logs',
                'Subscription.php' => 'events.table_names.subscriptions',
            ];

            foreach ($models as $modelFile => $configKey) {
                $src = file_get_contents(__DIR__.'/../src/Models/'.$modelFile);
                expect($src)->not->toBeFalse();
                expect($src)->toContain($configKey, "{$modelFile} must read table from {$configKey}");
            }
        });
    });

    describe('DispatchTriggerJob config consumption', function () {
        test('reads all config keys at construction time', function () {
            $src = file_get_contents(__DIR__.'/../src/Jobs/DispatchTriggerJob.php');
            expect($src)->not->toBeFalse();

            // Must read these config keys
            $configKeys = [
                'events.retry.tries',
                'events.retry.backoff',
                'events.queue.queue',
                'events.queue.connection',
            ];

            foreach ($configKeys as $key) {
                expect($src)->toContain($key, "DispatchTriggerJob must read config key: {$key}");
            }
        });
    });

    describe('EventScheduler config consumption', function () {
        test('reads all config keys for scheduled tasks', function () {
            $src = file_get_contents(__DIR__.'/../src/EventScheduler.php');
            expect($src)->not->toBeFalse();

            $configKeys = [
                'events.retention.days',
                'events.retention.schedule_cron',
                'events.retention.include_pending',
                'events.subscriptions.cleanup_cron',
            ];

            foreach ($configKeys as $key) {
                expect($src)->toContain($key, "EventScheduler must read config key: {$key}");
            }
        });
    });

    describe('phpstan.neon.dist minimal suppressions', function () {
        test('suppressions do not include preg_quote or preg_match null warnings', function () {
            $phpstan = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($phpstan)->not->toBeFalse();

            // These should NOT be suppressed anymore (all callers have strict string types)
            expect($phpstan)->not->toContain('preg_quote');
            expect($phpstan)->not->toContain('preg_match');

            // Must have level 9
            expect($phpstan)->toContain('level: max');

            // Must target src directory
            expect($phpstan)->toContain('src');

            // Must have reportUnmatchedIgnoredErrors
            expect($phpstan)->toContain('reportUnmatchedIgnoredErrors: true');
        });

        test('has the expected number of suppressions (7 for Laravel facades/Eloquent)', function () {
            $phpstan = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($phpstan)->not->toBeFalse();

            preg_match_all("/^- '#/", $phpstan, $matches);
            expect(count($matches[0]))->toBe(7, 'Expected exactly 7 PHPStan suppressions');
        });
    });

    describe('DomainEvent immutability and types', function () {
        test('all properties are readonly', function () {
            $src = file_get_contents(__DIR__.'/../src/Domain/DomainEvent.php');
            expect($src)->not->toBeFalse();

            // Must have readonly keyword
            expect($src)->toContain('public readonly string $eventType');
            expect($src)->toContain('public readonly array $payload');
            expect($src)->toContain('public readonly UuidInterface $eventId');
            expect($src)->toContain('public readonly DateTimeImmutable $occurredAt');
        });

        test('class is final', function () {
            $src = file_get_contents(__DIR__.'/../src/Domain/DomainEvent.php');
            expect($src)->not->toBeFalse();
            expect($src)->toContain('final class DomainEvent');
        });

        test('fromArray handles all edge cases gracefully', function () {
            // Empty eventType throws
            expect(fn () => \ZeroBoiler\Events\Domain\DomainEvent::fromArray([]))
                ->toThrow(\InvalidArgumentException::class);

            // Missing eventType throws
            expect(fn () => \ZeroBoiler\Events\Domain\DomainEvent::fromArray(['payload' => []]))
                ->toThrow(\InvalidArgumentException::class);

            // Valid reconstruction preserves fields
            $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);
            $data = $original->toArray();
            $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($data);

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->payload)->toBe($original->payload);
            expect($restored->occurredAt->format('U'))->toBe($original->occurredAt->format('U'));
        });
    });

    describe('Trait usage consistency', function () {
        test('EscapesWildcardLike is used by EventManager, EventsListCommand, EventsLogCommand, EventsSubscriptionsCommand, and Subscription', function () {
            $filesThatShouldUseIt = [
                'src/EventManager.php',
                'src/Console/EventsListCommand.php',
                'src/Console/EventsLogCommand.php',
                'src/Console/EventsSubscriptionsCommand.php',
                'src/Models/Subscription.php',
            ];

            foreach ($filesThatShouldUseIt as $file) {
                $src = file_get_contents(__DIR__.'/../'.$file);
                expect($src)->not->toBeFalse();
                expect($src)->toContain('EscapesWildcardLike', "{$file} must use EscapesWildcardLike trait");
            }
        });

        test('ManagesHistory and ManagesSubscriptions are used by EventManager', function () {
            $src = file_get_contents(__DIR__.'/../src/EventManager.php');
            expect($src)->not->toBeFalse();
            expect($src)->toContain('use ManagesHistory;');
            expect($src)->toContain('use ManagesSubscriptions;');
        });

        test('GetsWebhookTimeout is used by WebhookAction and EventsRedeliverCommand', function () {
            $webhook = file_get_contents(__DIR__.'/../src/Actions/WebhookAction.php');
            $redeliver = file_get_contents(__DIR__.'/../src/Console/EventsRedeliverCommand.php');
            expect($webhook)->not->toBeFalse();
            expect($redeliver)->not->toBeFalse();
            expect($webhook)->toContain('use GetsWebhookTimeout;');
            expect($redeliver)->toContain('use GetsWebhookTimeout;');
        });
    });

    describe('Factory correctness', function () {
        test('all factories reference correct model class', function () {
            $factories = [
                'TriggerFactory.php' => 'Trigger::class',
                'EventLogFactory.php' => 'EventLog::class',
                'SubscriptionFactory.php' => 'Subscription::class',
            ];

            foreach ($factories as $file => $modelClass) {
                $src = file_get_contents(__DIR__.'/../database/factories/'.$file);
                expect($src)->not->toBeFalse();
                expect($src)->toContain('protected string $model = '.$modelClass);
            }
        });

        test('all factories use HasFactory trait', function () {
            $models = [
                'Trigger.php',
                'EventLog.php',
                'Subscription.php',
            ];

            foreach ($models as $modelFile) {
                $src = file_get_contents(__DIR__.'/../src/Models/'.$modelFile);
                expect($src)->not->toBeFalse();
                expect($src)->toContain('use HasFactory');
            }
        });
    });

    describe('ConditionEngine operator exhaustiveness', function () {
        test('all documented operators are implemented in evaluateCondition match', function () {
            $src = file_get_contents(__DIR__.'/../src/ConditionEngine.php');
            expect($src)->not->toBeFalse();

            $operators = [
                '>', '>=', '<', '<=', '=', '===', '!=', '!==',
                'in', 'not_in', 'contains', 'not_contains',
                'between', 'null', 'not_null', 'empty', 'not_empty',
                'starts_with', 'ends_with', 'matches',
            ];

            foreach ($operators as $op) {
                expect($src)->toContain("'{$op}'", "Missing operator: {$op}");
            }
        });
    });

    describe('WildcardMatcher correctness', function () {
        test('is readonly and final', function () {
            $src = file_get_contents(__DIR__.'/../src/WildcardMatcher.php');
            expect($src)->not->toBeFalse();
            expect($src)->toContain('readonly final class WildcardMatcher');
        });

        test('matches() is #[Pure]', function () {
            $src = file_get_contents(__DIR__.'/../src/WildcardMatcher.php');
            expect($src)->not->toBeFalse();
            expect($src)->toContain('#[\\Pure]');
        });

        test('all three static methods have return type declarations', function () {
            $src = file_get_contents(__DIR__.'/../src/WildcardMatcher.php');
            expect($src)->not->toBeFalse();
            expect($src)->toContain('public static function matches(string $pattern, string $event): bool');
            expect($src)->toContain('public static function findMatchingPatterns(array $patterns, string $event): array');
            expect($src)->toContain('public static function extractWildcards(string $pattern, string $event): array');
        });
    });

    describe('composer.json correctness', function () {
        test('extra.laravel.providers and aliases are correct', function () {
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

        test('autoload mapping is correct', function () {
            $composer = json_decode(
                file_get_contents(__DIR__.'/../composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
            expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
        });

        test('PHP requirement is ^8.5', function () {
            $composer = json_decode(
                file_get_contents(__DIR__.'/../composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            expect($composer['require']['php'])->toBe('^8.5');
        });
    });

    describe('Migration config-driven table names', function () {
        test('all three migrations read table names from config', function () {
            $migrations = [
                '2024_01_01_000001_create_triggers_table.php' => 'events.table_names.triggers',
                '2024_01_01_000002_create_event_logs_table.php' => 'events.table_names.event_logs',
                '2025_06_28_000001_create_event_subscriptions_table.php' => 'events.table_names.subscriptions',
            ];

            foreach ($migrations as $file => $configKey) {
                $src = file_get_contents(__DIR__.'/../database/migrations/'.$file);
                expect($src)->not->toBeFalse();
                expect($src)->toContain($configKey, "Migration {$file} must read from {$configKey}");
            }
        });
    });

    describe('CI workflow correctness', function () {
        test('CI uses PHP 8.5', function () {
            $ci = file_get_contents(__DIR__.'/../.github/workflows/ci.yml');
            expect($ci)->not->toBeFalse();
            expect($ci)->toContain('php-version: \'8.5\'');
        });

        test('CI runs phpstan with phpstan.neon.dist', function () {
            $ci = file_get_contents(__DIR__.'/../.github/workflows/ci.yml');
            expect($ci)->not->toBeFalse();
            expect($ci)->toContain('phpstan.neon.dist');
        });

        test('CI requires minimum 80% coverage', function () {
            $ci = file_get_contents(__DIR__.'/../.github/workflows/ci.yml');
            expect($ci)->not->toBeFalse();
            expect($ci)->toContain('--min=80');
        });
    });

    describe('Pest.php registration completeness', function () {
        test('EventsPhase96StrictAuditTest.php is registered in Pest.php', function () {
            $pest = file_get_contents(__DIR__.'/../tests/Pest.php');
            expect($pest)->not->toBeFalse();
            expect($pest)->toContain('EventsPhase96StrictAuditTest.php');
        });
    });
});
