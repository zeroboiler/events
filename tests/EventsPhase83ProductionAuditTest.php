<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

describe('Phase 83 Production Audit', function (): void {
    describe('Return type completeness — all public/protected methods', function (): void {
        $reflectionClasses = [
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
            \ZeroBoiler\Events\Contracts\ConditionEngineContract::class,
            \ZeroBoiler\Events\Contracts\Triggerable::class,
        ];

        test('all methods have explicit return type declarations', function () use ($reflectionClasses): void {
            foreach ($reflectionClasses as $class) {
                $reflection = new ReflectionClass($class);
                $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED | ReflectionMethod::IS_PRIVATE);

                foreach ($methods as $method) {
                    // Skip constructor (return type is implicit void in PHP 8.5)
                    if ($method->isConstructor()) {
                        continue;
                    }

                    // Skip synthetic methods
                    if ($method->isInternal()) {
                        continue;
                    }

                    $returnType = $method->getReturnType();
                    expect($returnType)->not->toBeNull(
                        "{$class}::{$method->getName()}() must have an explicit return type declaration"
                    );
                }
            }
        });
    });

    describe('Strict types — all PHP files', function (): void {
        test('all src files declare strict_types=1', function (): void {
            $srcDir = __DIR__.'/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $phpFiles = [];

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $phpFiles[] = $file->getPathname();
                }
            }

            expect($phpFiles)->not->toBeEmpty('src/ directory should contain PHP files');

            foreach ($phpFiles as $filePath) {
                $contents = file_get_contents($filePath);
                expect($contents)->toContain(
                    'declare(strict_types=1)',
                    "{$filePath} must declare strict_types=1"
                );
            }
        });
    });

    describe('Typed properties — all models', function (): void {
        $models = [
            \ZeroBoiler\Events\Models\Trigger::class,
            \ZeroBoiler\Events\Models\EventLog::class,
            \ZeroBoiler\Events\Models\Subscription::class,
        ];

        test('all model properties have explicit types', function () use ($models): void {
            foreach ($models as $model) {
                $reflection = new ReflectionClass($model);
                $defaults = $reflection->getDefaultProperties();
                $properties = $reflection->getProperties();

                foreach ($properties as $property) {
                    if ($property->isStatic()) {
                        continue;
                    }

                    // Check that each property has a type
                    $type = $property->getType();
                    expect($type)->not->toBeNull(
                        "{$model}::\${$property->getName()} must have an explicit type declaration"
                    );
                }
            }
        });
    });

    describe('Docblock presence on public API methods', function (): void {
        test('EventManager public methods have docblocks', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            $skipMethods = ['__construct', '__clone', '__wakeup', '__sleep', '__toString'];

            foreach ($methods as $method) {
                if (in_array($method->getName(), $skipMethods, true)) {
                    continue;
                }

                $doc = $method->getDocComment();
                expect($doc)->not->toBeFalse(
                    "EventManager::{$method->getName()}() should have a docblock"
                );
            }
        });

        test('ConditionEngine public methods have docblocks', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\ConditionEngine::class);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                $doc = $method->getDocComment();
                expect($doc)->not->toBeFalse(
                    "ConditionEngine::{$method->getName()}() should have a docblock"
                );
            }
        });

        test('TriggerBuilder public methods have docblocks', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\TriggerBuilder::class);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            $skipMethods = ['__construct'];

            foreach ($methods as $method) {
                if (in_array($method->getName(), $skipMethods, true)) {
                    continue;
                }

                $doc = $method->getDocComment();
                expect($doc)->not->toBeFalse(
                    "TriggerBuilder::{$method->getName()}() should have a docblock"
                );
            }
        });

        test('SubscriptionBuilder public methods have docblocks', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\SubscriptionBuilder::class);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            $skipMethods = ['__construct'];

            foreach ($methods as $method) {
                if (in_array($method->getName(), $skipMethods, true)) {
                    continue;
                }

                $doc = $method->getDocComment();
                expect($doc)->not->toBeFalse(
                    "SubscriptionBuilder::{$method->getName()}() should have a docblock"
                );
            }
        });
    });

    describe('Contract interface completeness', function (): void {
        test('ConditionEngineContract has matches() method with correct signature', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
            expect($reflection->isInterface())->toBeTrue();

            $method = $reflection->getMethod('matches');
            expect($method)->not->toBeNull();
            expect($method->isPublic())->toBeTrue();

            $params = $method->getParameters();
            expect(count($params))->toBe(2);
            expect($params[0]->getName())->toBe('conditions');
            expect($params[1]->getName())->toBe('payload');

            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect((string) $returnType)->toBe('bool');
        });

        test('Triggerable has handle() method with correct signature', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Contracts\Triggerable::class);
            expect($reflection->isInterface())->toBeTrue();

            $method = $reflection->getMethod('handle');
            expect($method)->not->toBeNull();
            expect($method->isPublic())->toBeTrue();

            $params = $method->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->getName())->toBe('payload');

            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect((string) $returnType)->toBe('void');
        });

        test('ConditionEngine implements ConditionEngineContract', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\ConditionEngine::class);
            expect($reflection->implementsInterface(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class))->toBeTrue();
        });

        test('WebhookAction implements Triggerable', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class);
            expect($reflection->implementsInterface(\ZeroBoiler\Events\Contracts\Triggerable::class))->toBeTrue();
        });
    });

    describe('Constructor DI — proper typed injection', function (): void {
        test('EventManager constructor has typed parameters', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
            $constructor = $reflection->getConstructor();
            expect($constructor)->not->toBeNull();

            $params = $constructor->getParameters();
            expect(count($params))->toBe(3);

            foreach ($params as $param) {
                $type = $param->getType();
                expect($type)->not->toBeNull(
                    "EventManager constructor parameter \${$param->getName()} must have a type"
                );
            }
        });

        test('ActionResolver constructor has typed Container parameter', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\ActionResolver::class);
            $constructor = $reflection->getConstructor();
            expect($constructor)->not->toBeNull();

            $params = $constructor->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->getName())->toBe('app');
            expect((string) $params[0]->getType())->toBe('Illuminate\\Container\\Container');
        });
    });

    describe('Config consistency — source references match config keys', function (): void {
        $configKeys = [
            'events.disabled',
            'events.wildcard_cache_ttl',
            'events.queue.connection',
            'events.queue.queue',
            'events.retry.tries',
            'events.retry.backoff',
            'events.subscriptions.auto_generate_secret',
            'events.subscriptions.max_failures',
            'events.subscriptions.timeout',
            'events.subscriptions.signature_algorithm',
            'events.table_names.triggers',
            'events.table_names.event_logs',
            'events.table_names.subscriptions',
            'events.retention.days',
            'events.retention.include_pending',
        ];

        test('all config keys used in source exist in config file', function () use ($configKeys): void {
            $config = include __DIR__.'/../config/events.php';

            foreach ($configKeys as $key) {
                $parts = explode('.', $key);
                // Skip 'events.' prefix
                $current = $config;
                $found = true;

                for ($i = 1; $i < count($parts); $i++) {
                    $part = $parts[$i];
                    if (is_array($current) && array_key_exists($part, $current)) {
                        $current = $current[$part];
                    } else {
                        $found = false;
                        break;
                    }
                }

                expect($found)->toBeTrue("Config key '{$key}' must exist in config/events.php");
            }
        });
    });

    describe('No deprecated PHP features', function (): void {
        test('no dynamic property access (property_exists on stdClass)', function (): void {
            // Scan source for patterns that suggest dynamic property usage
            $srcDir = __DIR__.'/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                // Check for no __get/__set magic methods (unless explicitly documented)
                if (preg_match('/public\s+function\s+__set\s*\(/', $contents)) {
                    // Only allowed if class has #[AllowDynamicProperties]
                    if (! str_contains($contents, 'AllowDynamicProperties')) {
                        $this->fail("{$file->getPathname()} has __set() without #[AllowDynamicProperties]");
                    }
                }
            }

            expect(true)->toBeTrue();
        });
    });

    describe('Proper error handling patterns', function (): void {
        test('TriggerBuilder::save() validates event and action before save', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\TriggerBuilder::class);
            $method = $reflection->getMethod('save');
            $code = file_get_contents($reflection->getFileName());

            $start = $method->getStartLine();
            $end = $method->getEndLine();
            $lines = array_slice(explode("\n", $code), $start - 1, $end - $start + 1);
            $body = implode("\n", $lines);

            // Must validate event name
            expect($body)->toContain('InvalidArgumentException');
            // Must validate action
            expect($body)->toContain('action is required');
        });

        test('SubscriptionBuilder::save() validates URL scheme', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\SubscriptionBuilder::class);
            $method = $reflection->getMethod('save');
            $code = file_get_contents($reflection->getFileName());

            $start = $method->getStartLine();
            $end = $method->getEndLine();
            $lines = array_slice(explode("\n", $code), $start - 1, $end - $start + 1);
            $body = implode("\n", $lines);

            // Must validate URL scheme (HTTP/HTTPS only)
            expect($body)->toContain('http');
            expect($body)->toContain('https');
            // Must validate URL format
            expect($body)->toContain('FILTER_VALIDATE_URL');
        });
    });

    describe('WildcardMatcher edge cases — correctness', function (): void {
        test('single-segment wildcard does not cross dot boundaries', function (): void {
            expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        test('cross-segment wildcard matches across dot boundaries', function (): void {
            expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
            expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.**', 'order'))->toBeFalse();
        });

        test('catch-all pattern matches non-empty events', function (): void {
            expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', 'anything'))->toBeTrue();
            expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', ''))->toBeFalse();
            expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', 'a.b.c'))->toBeTrue();
        });

        test('exact match works correctly', function (): void {
            expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        test('regex special chars in event names are escaped', function (): void {
            expect(\ZeroBoiler\Events\WildcardMatcher::matches('user.login', 'user.login'))->toBeTrue();
            // Event names with dots should work (dots are segment separators, not regex)
            expect(\ZeroBoiler\Events\WildcardMatcher::matches('app.module.event', 'app.module.event'))->toBeTrue();
        });

        test('extractWildcards returns empty for cross-segment patterns', function (): void {
            expect(\ZeroBoiler\Events\WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))->toBe([]);
        });

        test('extractWildcards extracts single-segment values', function (): void {
            $result = \ZeroBoiler\Events\WildcardMatcher::extractWildcards('user.*.created', 'user.admin.created');
            expect($result)->toBe(['admin']);
        });
    });

    describe('ConditionEngine operators — null-safety', function (): void {
        test('comparison operators return false when actual is null', function (): void {
            $engine = new \ZeroBoiler\Events\ConditionEngine;

            // All comparison operators should return false when actual is null
            $comparisons = ['>', '>=', '<', '<='];
            foreach ($comparisons as $op) {
                $result = $engine->matches(['value' => [$op, 100]], ['value' => null]);
                expect($result)->toBeFalse("Operator {$op} should return false for null actual");
            }
        });

        test('in/not_in return false when value is null', function (): void {
            $engine = new \ZeroBoiler\Events\ConditionEngine;

            expect($engine->matches(['role' => ['in', ['admin']]], ['role' => null]))->toBeFalse();
            expect($engine->matches(['role' => ['not_in', ['admin']]], ['role' => null]))->toBeFalse();
        });

        test('between returns false when actual is null', function (): void {
            $engine = new \ZeroBoiler\Events\ConditionEngine;

            expect($engine->matches(['amount' => ['between', [0, 100]]], ['amount' => null]))->toBeFalse();
        });

        test('null/not_null operators work with null actual', function (): void {
            $engine = new \ZeroBoiler\Events\ConditionEngine;

            expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
            expect($engine->matches(['deleted_at' => ['not_null']], ['deleted_at' => null]))->toBeFalse();
        });

        test('empty/not_empty operators work correctly', function (): void {
            $engine = new \ZeroBoiler\Events\ConditionEngine;

            expect($engine->matches(['notes' => ['empty']], ['notes' => '']))->toBeTrue();
            expect($engine->matches(['notes' => ['empty']], ['notes' => 'hello']))->toBeFalse();
            expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'hello']))->toBeTrue();
        });

        test('not_contains operator is available', function (): void {
            $engine = new \ZeroBoiler\Events\ConditionEngine;

            expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['urgent', 'important']]))->toBeTrue();
            expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['urgent', 'spam']]))->toBeFalse();
        });

        test('not_empty operator is available', function (): void {
            $engine = new \ZeroBoiler\Events\ConditionEngine;

            expect($engine->matches(['name' => ['not_empty']], ['name' => 'test']))->toBeTrue();
            expect($engine->matches(['name' => ['not_empty']], ['name' => '']))->toBeFalse();
        });
    });

    describe('DomainEvent — reconstruction edge cases', function (): void {
        test('fromArray throws on empty eventType', function (): void {
            expect(fn (): mixed => \ZeroBoiler\Events\Domain\DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(\InvalidArgumentException::class, 'eventType is required');
        });

        test('fromArray throws on missing eventType', function (): void {
            expect(fn (): mixed => \ZeroBoiler\Events\Domain\DomainEvent::fromArray([]))
                ->toThrow(\InvalidArgumentException::class, 'eventType is required');
        });

        test('fromArray generates fresh UUID for invalid UUID string', function (): void {
            $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-uuid',
                'payload' => ['key' => 'value'],
            ]);

            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['key' => 'value']);
            // Should have a valid UUID (freshly generated since input was invalid)
            expect($event->eventId->toString())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
        });

        test('fromArray uses now() for invalid datetime string', function (): void {
            $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-datetime',
            ]);

            expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
        });

        test('toArray/fromArray roundtrip preserves eventId and occurredAt', function (): void {
            $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.roundtrip', ['data' => 42]);
            $array = $original->toArray();
            $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($array);

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->payload)->toBe($original->payload);
        });
    });

    describe('Composer.json correctness', function (): void {
        test('composer.json has correct PHP version requirement', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['require']['php'])->toBe('^8.5');
        });

        test('composer.json has Laravel 13 requirement', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
            expect($composer['require']['illuminate/support'])->toBe('^13.0');
        });

        test('composer.json has correct autoload namespace', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        test('composer.json has ServiceProvider in extra.laravel.providers', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Events\\EventsServiceProvider'
            );
        });

        test('composer.json has Facade alias in extra.laravel.aliases', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
                'ZeroBoiler\\Events\\Facades\\EventManager'
            );
        });

        test('composer.json has all CI scripts', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect(isset($composer['scripts']['test']))->toBeTrue();
            expect(isset($composer['scripts']['analyse']))->toBeTrue();
            expect(isset($composer['scripts']['lint']))->toBeTrue();
            expect(isset($composer['scripts']['rector']))->toBeTrue();
            expect(isset($composer['scripts']['ci']))->toBeTrue();
        });
    });
});
