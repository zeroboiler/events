<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\Subscription;

describe('Subscription::signPayload edge cases', function () {
    it('returns empty string when secret is null', function () {
        $sub = new Subscription(['secret' => null]);
        expect($sub->signPayload('{"test": true}'))->toBe('');
    });

    it('returns empty string when secret is empty string', function () {
        $sub = new Subscription(['secret' => '']);
        expect($sub->signPayload('{"test": true}'))->toBe('');
    });

    it('returns non-empty hex string when secret is set', function () {
        $sub = new Subscription(['secret' => 'whsec_test_secret_key_1234']);
        $signature = $sub->signPayload('{"event": "order.placed"}');
        expect($signature)->toBeString();
        expect($signature)->not->toBeEmpty();
        expect(strlen($signature))->toBe(64); // SHA-256 = 64 hex chars
    });

    it('produces consistent signatures for the same input', function () {
        $sub = new Subscription(['secret' => 'whsec_test_secret_key_1234']);
        $payload = '{"event": "order.placed", "data": {"id": 1}}';
        $sig1 = $sub->signPayload($payload);
        $sig2 = $sub->signPayload($payload);
        expect($sig1)->toBe($sig2);
    });

    it('produces different signatures for different payloads', function () {
        $sub = new Subscription(['secret' => 'whsec_test_secret_key_1234']);
        $sig1 = $sub->signPayload('{"event": "a"}');
        $sig2 = $sub->signPayload('{"event": "b"}');
        expect($sig1)->not->toBe($sig2);
    });

    it('produces different signatures for different secrets', function () {
        $sub1 = new Subscription(['secret' => 'whsec_secret_one']);
        $sub2 = new Subscription(['secret' => 'whsec_secret_two']);
        $payload = '{"event": "order.placed"}';
        expect($sub1->signPayload($payload))->not->toBe($sub2->signPayload($payload));
    });
});
