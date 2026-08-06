<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Concerns\EscapesWildcardLike;

test('wildcardToLike returns null when no wildcard present', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('order.placed'))
        ->toBeNull()
        ->and($trait->wildcardToLike('user.created'))
        ->toBeNull();
});

test('wildcardToLike converts single asterisk to percent', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('order.*'))
        ->toBe('order.%');
});

test('wildcardToLike converts multiple asterisks to percents', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('*.order.*'))
        ->toBe('%.order.%');
});

test('wildcardToLike converts cross-segment double asterisk', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    // Both * and ** are converted to % — the SQL LIKE % already handles
    // cross-segment matching naturally.
    expect($trait->wildcardToLike('order.**'))
        ->toBe('order.%%');
});

test('wildcardToLike escapes percent signs literally', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('order.%'))
        ->toBe('order.\\%');
});

test('wildcardToLike escapes underscores literally', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('order._'))
        ->toBe('order.\\_');
});

test('wildcardToLike escapes backslashes', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('order.\\placed'))
        ->toBe('order.\\\\placed');
});

test('wildcardToLike handles mixed special characters and wildcards', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('*.test_%'))
        ->toBe('%.test\\_\\%');
});

test('wildcardToLike handles catch-all pattern', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    expect($trait->wildcardToLike('*'))
        ->toBe('%');
});

test('wildcardToLike returns null for empty string', function (): void {
    $trait = new class
    {
        use EscapesWildcardLike;
    };

    expect($trait->wildcardToLike(''))
        ->toBeNull();
});
