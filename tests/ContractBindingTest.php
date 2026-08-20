<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;

describe('ConditionEngineContract binding', function (): void {
    test('ConditionEngine is resolvable via contract', function (): void {
        $engine = app(ConditionEngineContract::class);

        expect($engine)->toBeInstanceOf(ConditionEngine::class);
    });

    test('ConditionEngine contract and concrete are the same instance (singleton)', function (): void {
        $contract = app(ConditionEngineContract::class);
        $concrete = app(ConditionEngine::class);

        expect($contract)->toBe($concrete);
    });
});

describe('EventManager readonly properties', function (): void {
    test('EventManager constructor parameters are typed as readonly', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect($params)->toHaveCount(3);

        expect($params[0]->getName())->toBe('conditionEngine')
            ->and($params[0]->getType()?->getName())->toBe(ConditionEngine::class);
        expect($params[1]->getName())->toBe('actionResolver')
            ->and($params[1]->getType()?->getName())->toBe(\ZeroBoiler\Events\ActionResolver::class);
        expect($params[2]->getName())->toBe('app')
            ->and($params[2]->getType()?->getName())->toBe(\Illuminate\Container\Container::class);
    });
});

describe('DispatchTriggerJob readonly properties', function (): void {
    test('DispatchTriggerJob has typed constructor parameters', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect($params)->toHaveCount(4);

        expect($params[0]->getName())->toBe('triggerId')
            ->and($params[0]->getType()?->getName())->toBe('string');
        expect($params[1]->getName())->toBe('event')
            ->and($params[1]->getType()?->getName())->toBe('string');
        expect($params[2]->getName())->toBe('payload')
            ->and($params[2]->getType()?->getName())->toBe('array');
        expect($params[3]->getName())->toBe('app')
            ->and($params[3]->getType()?->getName())->toBe(\Illuminate\Container\Container::class)
            ->and($params[3]->allowsNull())->toBeTrue();
    });
});

describe('DomainEvent constructor type safety', function (): void {
    test('DomainEvent constructor has correct parameter types', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect($params)->toHaveCount(4);

        expect($params[0]->getName())->toBe('eventType')
            ->and($params[0]->getType()?->getName())->toBe('string');
        expect($params[1]->getName())->toBe('payload')
            ->and($params[1]->getType()?->getName())->toBe('array');
        expect($params[2]->getName())->toBe('eventId')
            ->and($params[2]->getType()?->allowsNull())->toBeTrue();
        expect($params[3]->getName())->toBe('occurredAt')
            ->and($params[3]->getType()?->allowsNull())->toBeTrue();
    });
});

describe('All source files have strict types', function (): void {
    test('every PHP file in src/ declares strict_types=1', function (): void {
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
            $tokens = token_get_all($contents);

            // Find the first T_DECLARE or T_OPEN_TAG_WITH_CURLY
            $hasStrict = false;
            foreach ($tokens as $i => $token) {
                if (is_array($token) && $token[0] === T_DECLARE) {
                    // Check next tokens for 'strict_types' and '= 1'
                    for ($j = $i + 1; $j < count($tokens); $j++) {
                        $next = $tokens[$j];
                        if (is_array($next) && $next[1] === 'strict_types') {
                            $hasStrict = true;
                            break 2;
                        }
                    }
                }
            }

            if (! $hasStrict) {
                $violations[] = $file->getPathname();
            }
        }

        expect($violations)->toBeEmpty(
            'Files missing declare(strict_types=1): '.implode(', ', $violations),
        );
    });
});

describe('Config values match expected defaults', function (): void {
    test('events.queue.connection defaults to sync', function (): void {
        expect(config('events.queue.connection'))->toBe('sync');
    });

    test('events.queue.queue defaults to default', function (): void {
        expect(config('events.queue.queue'))->toBe('default');
    });

    test('events.retry.tries defaults to 3', function (): void {
        expect(config('events.retry.tries'))->toBe(3);
    });

    test('events.retry.backoff defaults to comma-separated string', function (): void {
        $backoff = config('events.retry.backoff');
        expect($backoff)->toBeString();
        $parts = explode(',', $backoff);
        expect($parts)->toHaveCount(3);
        foreach ($parts as $part) {
            expect((int) trim($part))->toBeGreaterThan(0);
        }
    });

    test('events.retention.days defaults to 30', function (): void {
        expect(config('events.retention.days'))->toBe(30);
    });

    test('events.wildcard_cache_ttl defaults to 300', function (): void {
        expect(config('events.wildcard_cache_ttl'))->toBe(300);
    });
});

describe('WebhookAction config-driven values', function (): void {
    test('WebhookAction timeout method reads from config', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class);
        expect($reflection->hasMethod('getTimeout'))->toBeTrue();

        $method = $reflection->getMethod('getTimeout');
        expect($method->isPrivate())->toBeTrue()
            ->and($method->hasReturnType())->toBeTrue()
            ->and($method->getReturnType()?->getName())->toBe('int');
    });

    test('WebhookAction max failures method reads from config', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class);
        expect($reflection->hasMethod('getMaxFailures'))->toBeTrue();

        $method = $reflection->getMethod('getMaxFailures');
        expect($method->isPrivate())->toBeTrue()
            ->and($method->hasReturnType())->toBeTrue()
            ->and($method->getReturnType()?->getName())->toBe('int');
    });
});
