<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Contracts\Triggerable;

test('ActionResolver rejects non-existent class', function (): void {
    $resolver = new ActionResolver($this->app);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('does not exist');

    $resolver->resolve('NonExistent\ActionClass');
});

test('ActionResolver rejects class that does not implement Triggerable', function (): void {
    $resolver = new ActionResolver($this->app);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('must implement');

    $resolver->resolve(\stdClass::class);
});

test('ActionResolver resolves valid Triggerable implementation', function (): void {
    $resolver = new ActionResolver($this->app);

    $instance = $resolver->resolve(TestTriggerableAction::class);

    expect($instance)->toBeInstanceOf(Triggerable::class)
        ->and($instance)->toBeInstanceOf(TestTriggerableAction::class);
});

test('ActionResolver resolves same class consistently', function (): void {
    $resolver = new ActionResolver($this->app);

    $a = $resolver->resolve(TestTriggerableAction::class);
    $b = $resolver->resolve(TestTriggerableAction::class);

    // Same class, may be different instances (singleton resolver, but transient bindings)
    expect($a::class)->toBe($b::class);
});

test('ActionResolver rejects empty class name', function (): void {
    $resolver = new ActionResolver($this->app);

    $this->expectException(\InvalidArgumentException::class);

    $resolver->resolve('');
});

final class TestTriggerableAction implements Triggerable
{
    public function handle(array $payload): void
    {
        // no-op test action
    }
}
