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

test('EventException base class extends RuntimeException', function (): void {
    $e = new EventException('test message');

    expect($e)->toBeInstanceOf(\RuntimeException::class)
        ->and($e->getMessage())->toBe('test message')
        ->and($e->getCode())->toBe(0)
        ->and($e->getPrevious())->toBeNull();
});

test('EventException accepts custom code and previous exception', function (): void {
    $previous = new \LogicException('inner');
    $e = new EventException('outer', 42, $previous);

    expect($e->getMessage())->toBe('outer')
        ->and($e->getCode())->toBe(42)
        ->and($e->getPrevious())->toBe($previous);
});

test('ActionResolutionException formats message with class and reason', function (): void {
    $e = new ActionResolutionException('App\\Actions\\Foo', 'Class must implement Triggerable');

    expect($e)->toBeInstanceOf(EventException::class)
        ->and($e->getMessage())->toBe("Failed to resolve action 'App\\Actions\\Foo': Class must implement Triggerable");
});

test('ActionResolutionException formats message with only class', function (): void {
    $e = new ActionResolutionException('MissingClass');

    expect($e->getMessage())->toBe("Failed to resolve action 'MissingClass'");
});

test('ConditionEvaluationException formats message with field and reason', function (): void {
    $e = new ConditionEvaluationException('amount', 'value is not numeric');

    expect($e)->toBeInstanceOf(EventException::class)
        ->and($e->getMessage())->toBe("Condition evaluation failed for field 'amount': value is not numeric");
});

test('SubscriptionException accepts message and previous', function (): void {
    $previous = new \RuntimeException('connection refused');
    $e = new SubscriptionException('Webhook delivery failed', $previous);

    expect($e)->toBeInstanceOf(EventException::class)
        ->and($e->getMessage())->toBe('Webhook delivery failed')
        ->and($e->getPrevious())->toBe($previous);
});

test('SubscriptionException with null previous', function (): void {
    $e = new SubscriptionException('No subscription found');

    expect($e->getPrevious())->toBeNull();
});

test('TriggerNotFoundException formats message with trigger ID', function (): void {
    $e = new TriggerNotFoundException('abc-123-def');

    expect($e)->toBeInstanceOf(EventException::class)
        ->and($e->getMessage())->toBe('Trigger not found: abc-123-def');
});

test('TriggerNotFoundException with empty ID', function (): void {
    $e = new TriggerNotFoundException('');

    expect($e->getMessage())->toBe('Trigger not found: ');
});

