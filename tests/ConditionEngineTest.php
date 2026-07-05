<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;

test('condition engine implements contract', function (): void {
    expect(app(ConditionEngine::class))
        ->toBeInstanceOf(ConditionEngineContract::class);
});

test('matches simple equality condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['status' => 'paid'], ['status' => 'paid']))
        ->toBeTrue()
        ->and($engine->matches(['status' => 'paid'], ['status' => 'pending']))
        ->toBeFalse();
});

test('matches greater than condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['amount' => ['>', 100]], ['amount' => 150]))
        ->toBeTrue()
        ->and($engine->matches(['amount' => ['>', 100]], ['amount' => 50]))
        ->toBeFalse();
});

test('matches less than condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['amount' => ['<', 100]], ['amount' => 50]))
        ->toBeTrue()
        ->and($engine->matches(['amount' => ['<', 100]], ['amount' => 150]))
        ->toBeFalse();
});

test('matches greater than or equal condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['amount' => ['>=', 100]], ['amount' => 100]))
        ->toBeTrue()
        ->and($engine->matches(['amount' => ['>=', 100]], ['amount' => 150]))
        ->toBeTrue()
        ->and($engine->matches(['amount' => ['>=', 100]], ['amount' => 50]))
        ->toBeFalse();
});

test('matches less than or equal condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['amount' => ['<=', 100]], ['amount' => 100]))
        ->toBeTrue()
        ->and($engine->matches(['amount' => ['<=', 100]], ['amount' => 50]))
        ->toBeTrue()
        ->and($engine->matches(['amount' => ['<=', 100]], ['amount' => 150]))
        ->toBeFalse();
});

test('matches in condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['status' => ['in', ['active', 'pending']]], ['status' => 'active']))
        ->toBeTrue()
        ->and($engine->matches(['status' => ['in', ['active', 'pending']]], ['status' => 'archived']))
        ->toBeFalse();
});

test('matches not_in condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['status' => ['not_in', ['archived', 'deleted']]], ['status' => 'active']))
        ->toBeTrue()
        ->and($engine->matches(['status' => ['not_in', ['archived', 'deleted']]], ['status' => 'archived']))
        ->toBeFalse();
});

test('matches contains condition for array', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['urgent', 'important']]))
        ->toBeTrue()
        ->and($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['important']]))
        ->toBeFalse();
});

test('matches contains condition for string', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['message' => ['contains', 'error']], ['message' => 'This is an error message']))
        ->toBeTrue()
        ->and($engine->matches(['message' => ['contains', 'error']], ['message' => 'This is a warning']))
        ->toBeFalse();
});

test('matches between condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['amount' => ['between', [100, 500]]], ['amount' => 250]))
        ->toBeTrue()
        ->and($engine->matches(['amount' => ['between', [100, 500]]], ['amount' => 100]))
        ->toBeTrue()
        ->and($engine->matches(['amount' => ['between', [100, 500]]], ['amount' => 500]))
        ->toBeTrue()
        ->and($engine->matches(['amount' => ['between', [100, 500]]], ['amount' => 50]))
        ->toBeFalse()
        // BUG-4 R35: Inverted ranges should be auto-normalized
        ->and($engine->matches(['amount' => ['between', [500, 100]]], ['amount' => 250]))
        ->toBeTrue()
        ->and($engine->matches(['amount' => ['between', [500, 100]]], ['amount' => 100]))
        ->toBeTrue()
        ->and($engine->matches(['amount' => ['between', [500, 100]]], ['amount' => 500]))
        ->toBeTrue()
        ->and($engine->matches(['amount' => ['between', [500, 100]]], ['amount' => 50]))
        ->toBeFalse();
});

test('matches null condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))
        ->toBeTrue()
        ->and($engine->matches(['deleted_at' => ['null']], ['deleted_at' => '2024-01-01']))
        ->toBeFalse();
});

test('matches not_null condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['deleted_at' => ['not_null']], ['deleted_at' => '2024-01-01']))
        ->toBeTrue()
        ->and($engine->matches(['deleted_at' => ['not_null']], ['deleted_at' => null]))
        ->toBeFalse();
});

test('matches empty condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['items' => ['empty']], ['items' => []]))
        ->toBeTrue()
        ->and($engine->matches(['items' => ['empty']], ['items' => ['item']]))
        ->toBeFalse();
});

test('matches not_empty condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['items' => ['not_empty']], ['items' => ['item']]))
        ->toBeTrue()
        ->and($engine->matches(['items' => ['not_empty']], ['items' => []]))
        ->toBeFalse();
});

test('matches starts_with condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['email' => ['starts_with', 'admin']], ['email' => 'admin@example.com']))
        ->toBeTrue()
        ->and($engine->matches(['email' => ['starts_with', 'admin']], ['email' => 'user@example.com']))
        ->toBeFalse();
});

test('matches ends_with condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['email' => ['ends_with', '.com']], ['email' => 'admin@example.com']))
        ->toBeTrue()
        ->and($engine->matches(['email' => ['ends_with', '.com']], ['email' => 'admin@example.org']))
        ->toBeFalse();
});

test('matches regex condition', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['email' => ['matches', '/^.+@.+\..+$/']], ['email' => 'admin@example.com']))
        ->toBeTrue()
        ->and($engine->matches(['email' => ['matches', '/^.+@.+\..+$/']], ['email' => 'not-an-email']))
        ->toBeFalse();
});

test('matches nested field with dot notation', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['user.email' => 'admin@example.com'], ['user' => ['email' => 'admin@example.com']]))
        ->toBeTrue()
        ->and($engine->matches(['order.total' => ['>', 100]], ['order' => ['total' => 150]]))
        ->toBeTrue();
});

test('matches multiple conditions', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches([
        'status' => 'paid',
        'amount' => ['>', 100],
        'user.role' => 'admin',
    ], [
        'status' => 'paid',
        'amount' => 150,
        'user' => ['role' => 'admin'],
    ]))
        ->toBeTrue()
        ->and($engine->matches([
            'status' => 'paid',
            'amount' => ['>', 100],
        ], [
            'status' => 'pending',
            'amount' => 150,
        ]))
        ->toBeFalse();
});

test('returns true when no conditions provided', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches([], ['anything' => 'here']))
        ->toBeTrue();
});

test('handles missing nested fields gracefully', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine->matches(['user.profile.name' => 'John'], ['user' => ['email' => 'john@example.com']]))
        ->toBeFalse();
});
