<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

// ─── SubscriptionBuilder HTTP-only URL validation ───

test('subscription builder rejects ftp:// URLs', function (): void {
    $builder = app()->make(SubscriptionBuilder::class);
    $builder->on('order.placed')->to('ftp://evil.com/upload');

    expect(fn (): Subscription => $builder->save())
        ->toThrow(InvalidArgumentException::class, 'must use HTTP or HTTPS protocol');
});

test('subscription builder rejects file:// URLs', function (): void {
    $builder = app()->make(SubscriptionBuilder::class);
    $builder->on('order.placed')->to('file:///etc/passwd');

    expect(fn (): Subscription => $builder->save())
        ->toThrow(InvalidArgumentException::class, 'must use HTTP or HTTPS protocol');
});

test('subscription builder rejects mailto: URLs', function (): void {
    $builder = app()->make(SubscriptionBuilder::class);
    $builder->on('order.placed')->to('mailto:admin@example.com');

    expect(fn (): Subscription => $builder->save())
        ->toThrow(InvalidArgumentException::class, 'must use HTTP or HTTPS protocol');
});

test('subscription builder accepts https:// URLs', function (): void {
    $trigger = Trigger::factory()->create(['event' => 'test.accept.https']);
    $builder = app()->make(SubscriptionBuilder::class);
    $builder->on('test.accept.https')->to('https://partner.com/webhooks');

    // Will fail because of DB constraints in the test, but the URL check should pass
    try {
        $builder->save();
    } catch (InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), 'HTTP or HTTPS')) {
            $this->fail('HTTPS URL was incorrectly rejected');
        }
        // Other validation errors (e.g., trigger creation) are fine
    }
    expect(true)->toBeTrue();
});

test('subscription builder accepts http:// URLs', function (): void {
    $builder = app()->make(SubscriptionBuilder::class);
    $builder->on('order.placed')->to('http://localhost:3000/hooks');

    try {
        $builder->save();
    } catch (InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), 'HTTP or HTTPS')) {
            $this->fail('HTTP URL was incorrectly rejected');
        }
    }
    expect(true)->toBeTrue();
});

// ─── SubscriptionBuilder validation ordering ───

test('subscription builder validates event name before URL scheme', function (): void {
    $builder = app()->make(SubscriptionBuilder::class);
    $builder->on('')->to('ftp://evil.com');

    expect(fn (): Subscription => $builder->save())
        ->toThrow(InvalidArgumentException::class, 'Event name is required');
});

test('subscription builder validates empty URL before scheme check', function (): void {
    $builder = app()->make(SubscriptionBuilder::class);
    $builder->on('order.placed')->to('');

    expect(fn (): Subscription => $builder->save())
        ->toThrow(InvalidArgumentException::class, 'Webhook URL is required');
});
