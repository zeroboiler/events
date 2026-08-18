<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Exceptions\ActionResolutionException;
use ZeroBoiler\Events\Exceptions\ConditionEvaluationException;
use ZeroBoiler\Events\Exceptions\EventException;
use ZeroBoiler\Events\Exceptions\SubscriptionException;
use ZeroBoiler\Events\Exceptions\TriggerNotFoundException;

describe('Exception Hierarchy', function (): void {
    describe('EventException', function (): void {
        test('extends RuntimeException', function (): void {
            $e = new EventException('test message');
            expect($e)->toBeInstanceOf(\RuntimeException::class);
        });

        test('stores message and code', function (): void {
            $e = new EventException('custom error', 42);
            expect($e->getMessage())->toBe('custom error');
            expect($e->getCode())->toBe(42);
        });

        test('supports previous exception chaining', function (): void {
            $previous = new \RuntimeException('root cause');
            $e = new EventException('wrapper', 0, $previous);
            expect($e->getPrevious())->toBe($previous);
        });

        test('is catchable as Throwable', function (): void {
            $e = new EventException('test');
            $caught = false;
            try {
                throw $e;
            } catch (\Throwable $t) {
                $caught = $t instanceof EventException;
            }
            expect($caught)->toBeTrue();
        });
    });

    describe('TriggerNotFoundException', function (): void {
        test('extends EventException', function (): void {
            $e = new TriggerNotFoundException('abc-123');
            expect($e)->toBeInstanceOf(EventException::class);
        });

        test('includes trigger ID in message', function (): void {
            $e = new TriggerNotFoundException('550e8400-e29b-41d4-a716-446655440000');
            expect($e->getMessage())->toContain('550e8400-e29b-41d4-a716-446655440000');
        });

        test('has zero code by default', function (): void {
            $e = new TriggerNotFoundException('id');
            expect($e->getCode())->toBe(0);
        });
    });

    describe('ConditionEvaluationException', function (): void {
        test('extends EventException', function (): void {
            $e = new ConditionEvaluationException('amount', 'non-numeric value');
            expect($e)->toBeInstanceOf(EventException::class);
        });

        test('includes field and reason in message', function (): void {
            $e = new ConditionEvaluationException('status', 'unsupported operator');
            expect($e->getMessage())->toContain('status');
            expect($e->getMessage())->toContain('unsupported operator');
        });
    });

    describe('ActionResolutionException', function (): void {
        test('extends EventException', function (): void {
            $e = new ActionResolutionException('App\\Actions\\Foo');
            expect($e)->toBeInstanceOf(EventException::class);
        });

        test('includes class name in message', function (): void {
            $e = new ActionResolutionException('App\\Actions\\Foo');
            expect($e->getMessage())->toContain('App\\Actions\\Foo');
        });

        test('includes reason when provided', function (): void {
            $e = new ActionResolutionException('App\\Actions\\Foo', 'Class does not exist');
            expect($e->getMessage())->toContain('Class does not exist');
        });
    });

    describe('SubscriptionException', function (): void {
        test('extends EventException', function (): void {
            $e = new SubscriptionException('Invalid webhook URL');
            expect($e)->toBeInstanceOf(EventException::class);
        });

        test('supports previous exception', function (): void {
            $previous = new \RuntimeException('connection refused');
            $e = new SubscriptionException('Webhook delivery failed', $previous);
            expect($e->getPrevious())->toBe($previous);
        });
    });
});
