<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Contracts\Triggerable;

beforeEach(function (): void {
    App::clearResolvedInstances();
});

test('action resolver resolves triggerable class', function (): void {
    $resolver = app(ActionResolver::class);

    $handler = $resolver->resolve(TestAction::class);

    expect($handler)->toBeInstanceOf(TestAction::class)
        ->and($handler)->toBeInstanceOf(Triggerable::class);
});

test('action resolver uses container to instantiate', function (): void {
    $resolver = app(ActionResolver::class);

    $handler1 = $resolver->resolve(TestActionWithDependency::class);
    $handler2 = $resolver->resolve(TestActionWithDependency::class);

    // Laravel container creates new instances unless singleton
    expect($handler1)->toBeInstanceOf(TestActionWithDependency::class)
        ->and($handler2)->toBeInstanceOf(TestActionWithDependency::class);
});

test('resolved handler can be called', function (): void {
    $resolver = app(ActionResolver::class);

    $handler = $resolver->resolve(TestAction::class);

    $called = false;
    $handler->handle(['test' => 'data']);

    expect($handler->wasCalled)->toBeTrue();
});

test('action resolver throws exception for nonexistent class', function (): void {
    $resolver = app(ActionResolver::class);

    expect(fn () => $resolver->resolve('App\\Nonexistent\\FakeAction'))
        ->toThrow(InvalidArgumentException::class, 'does not exist');
});

test('action resolver throws exception for class not implementing triggerable', function (): void {
    $resolver = app(ActionResolver::class);

    expect(fn () => $resolver->resolve(stdClass::class))
        ->toThrow(InvalidArgumentException::class, 'must implement');
});

test('action resolver error message includes class name', function (): void {
    $resolver = app(ActionResolver::class);

    expect(fn () => $resolver->resolve(stdClass::class))
        ->toThrow(InvalidArgumentException::class, 'stdClass');
});

// Test helpers

class TestAction implements Triggerable
{
    public bool $wasCalled = false;

    public function handle(array $payload): void
    {
        $this->wasCalled = true;
    }
}

class TestActionWithDependency implements Triggerable
{
    public function __construct(
        public string $dependency = 'test'
    ) {}

    public function handle(array $payload): void
    {
        // Handle the payload
    }
}
