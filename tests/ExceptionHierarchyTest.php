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

describe('Exception hierarchy', function (): void {
    test('EventException extends RuntimeException', function (): void {
        $e = new EventException('test');
        expect($e)->toBeInstanceOf(\RuntimeException::class);
    });

    test('EventException accepts code and previous', function (): void {
        $prev = new \RuntimeException('inner');
        $e = new EventException('outer', 42, $prev);
        expect($e->getMessage())->toBe('outer');
        expect($e->getCode())->toBe(42);
        expect($e->getPrevious())->toBe($prev);
    });

    test('ActionResolutionException formats message with class and reason', function (): void {
        $e = new ActionResolutionException('App\\Actions\\Foo', 'not found');
        expect($e->getMessage())->toBe("Failed to resolve action 'App\\Actions\\Foo': not found");
        expect($e)->toBeInstanceOf(EventException::class);
    });

    test('ActionResolutionException formats message without reason', function (): void {
        $e = new ActionResolutionException('App\\Actions\\Foo');
        expect($e->getMessage())->toBe("Failed to resolve action 'App\\Actions\\Foo'");
    });

    test('ConditionEvaluationException formats message with field and reason', function (): void {
        $e = new ConditionEvaluationException('amount', 'invalid operator');
        expect($e->getMessage())->toBe("Condition evaluation failed for field 'amount': invalid operator");
        expect($e)->toBeInstanceOf(EventException::class);
    });

    test('SubscriptionException accepts previous exception', function (): void {
        $prev = new \RuntimeException('network error');
        $e = new SubscriptionException('webhook failed', $prev);
        expect($e->getMessage())->toBe('webhook failed');
        expect($e->getCode())->toBe(0);
        expect($e->getPrevious())->toBe($prev);
        expect($e)->toBeInstanceOf(EventException::class);
    });

    test('TriggerNotFoundException formats message with trigger ID', function (): void {
        $e = new TriggerNotFoundException('abc-123');
        expect($e->getMessage())->toBe('Trigger not found: abc-123');
        expect($e)->toBeInstanceOf(EventException::class);
    });

    test('all exceptions are final', function (): void {
        expect((new \ReflectionClass(EventException::class))->isFinal())->toBeFalse();
        expect((new \ReflectionClass(ActionResolutionException::class))->isFinal())->toBeTrue();
        expect((new \ReflectionClass(ConditionEvaluationException::class))->isFinal())->toBeTrue();
        expect((new \ReflectionClass(SubscriptionException::class))->isFinal())->toBeTrue();
        expect((new \ReflectionClass(TriggerNotFoundException::class))->isFinal())->toBeTrue();
    });
});
