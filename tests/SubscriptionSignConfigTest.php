<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\Models\Subscription;

beforeEach(function (): void {
    Subscription::query()->delete();
});

describe('Subscription signPayload config-driven algorithm', function (): void {
    test('signPayload uses sha256 by default', function (): void {
        Config::set('events.subscriptions.signature_algorithm', 'sha256');

        $subscription = Subscription::factory()
            ->withSecret('whsec_test_key')
            ->create();

        $payload = '{"test":"data"}';
        $expected = hash_hmac('sha256', $payload, 'whsec_test_key');

        expect($subscription->signPayload($payload))->toBe($expected);
    });

    test('signPayload uses sha384 when configured', function (): void {
        Config::set('events.subscriptions.signature_algorithm', 'sha384');

        $subscription = Subscription::factory()
            ->withSecret('whsec_test_key')
            ->create();

        $payload = '{"test":"data"}';
        $expected = hash_hmac('sha384', $payload, 'whsec_test_key');

        expect($subscription->signPayload($payload))->toBe($expected);
    });

    test('signPayload uses sha512 when configured', function (): void {
        Config::set('events.subscriptions.signature_algorithm', 'sha512');

        $subscription = Subscription::factory()
            ->withSecret('whsec_test_key')
            ->create();

        $payload = '{"test":"data"}';
        $expected = hash_hmac('sha512', $payload, 'whsec_test_key');

        expect($subscription->signPayload($payload))->toBe($expected);
    });

    test('signPayload falls back to sha256 for invalid algorithm', function (): void {
        Config::set('events.subscriptions.signature_algorithm', 12345);

        $subscription = Subscription::factory()
            ->withSecret('whsec_test_key')
            ->create();

        $payload = '{"test":"data"}';
        $expected = hash_hmac('sha256', $payload, 'whsec_test_key');

        expect($subscription->signPayload($payload))->toBe($expected);
    });

    test('signPayload falls back to sha256 when config is null', function (): void {
        Config::set('events.subscriptions.signature_algorithm', null);

        $subscription = Subscription::factory()
            ->withSecret('whsec_test_key')
            ->create();

        $payload = '{"test":"data"}';
        $expected = hash_hmac('sha256', $payload, 'whsec_test_key');

        expect($subscription->signPayload($payload))->toBe($expected);
    });

    test('signPayload returns empty string when secret is null', function (): void {
        Config::set('events.subscriptions.signature_algorithm', 'sha256');

        $subscription = Subscription::factory()->withoutSecret()->create();

        expect($subscription->signPayload('test'))->toBe('');
    });

    test('signPayload returns empty string when secret is empty', function (): void {
        Config::set('events.subscriptions.signature_algorithm', 'sha256');

        $subscription = Subscription::factory()->create(['secret' => '']);

        expect($subscription->signPayload('test'))->toBe('');
    });
});
