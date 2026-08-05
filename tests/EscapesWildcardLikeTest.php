<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Concerns\EscapesWildcardLike;

it('returns null for patterns without wildcard', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    $method = new ReflectionMethod($trait, 'wildcardToLike');
    $method->setAccessible(true);

    expect($method->invoke($trait, 'order.placed'))->toBeNull();
});

it('converts single wildcard to SQL LIKE percent', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    $method = new ReflectionMethod($trait, 'wildcardToLike');
    $method->setAccessible(true);

    expect($method->invoke($trait, 'order.*'))->toBe('order.%');
});

it('converts leading wildcard', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    $method = new ReflectionMethod($trait, 'wildcardToLike');
    $method->setAccessible(true);

    expect($method->invoke($trait, '*.placed'))->toBe('%.placed');
});

it('converts catch-all wildcard', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    $method = new ReflectionMethod($trait, 'wildcardToLike');
    $method->setAccessible(true);

    expect($method->invoke($trait, '*'))->toBe('%');
});

it('escapes SQL LIKE special characters', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    $method = new ReflectionMethod($trait, 'wildcardToLike');
    $method->setAccessible(true);

    // Percent and underscore in the pattern should be escaped
    expect($method->invoke($trait, 'order_%'))
        ->toBe('order\_%');
});

it('escapes backslashes in pattern', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    $method = new ReflectionMethod($trait, 'wildcardToLike');
    $method->setAccessible(true);

    expect($method->invoke($trait, 'order\\*.placed'))
        ->toBe('order\\\\%.placed');
});

it('handles multiple wildcards', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    $method = new ReflectionMethod($trait, 'wildcardToLike');
    $method->setAccessible(true);

    expect($method->invoke($trait, '*.order.*'))->toBe('%.order.%');
});

it('converts double wildcard', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    $method = new ReflectionMethod($trait, 'wildcardToLike');
    $method->setAccessible(true);

    expect($method->invoke($trait, 'order.**'))->toBe('order.%%');
});

it('preserves non-special characters', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    $method = new ReflectionMethod($trait, 'wildcardToLike');
    $method->setAccessible(true);

    expect($method->invoke($trait, 'api.v1.users.created.*'))
        ->toBe('api.v1.users.created.%');
});

it('returns null for empty string pattern', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    $method = new ReflectionMethod($trait, 'wildcardToLike');
    $method->setAccessible(true);

    expect($method->invoke($trait, ''))->toBeNull();
});

it('escapes mixed special characters with wildcards', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    $method = new ReflectionMethod($trait, 'wildcardToLike');
    $method->setAccessible(true);

    // Pattern: user_%.* (underscore, percent, and wildcard)
    expect($method->invoke($trait, 'user_%.*'))->toBe('user\_%%.%');
});
