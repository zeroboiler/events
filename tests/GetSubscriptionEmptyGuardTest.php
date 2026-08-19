<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Database\Factories\SubscriptionFactory;
use ZeroBoiler\Events\Facades\EventManager;

describe('getSubscription empty string guard', function () {
    it('returns null for empty string ID', function () {
        expect(EventManager::getSubscription(''))->toBeNull();
    });

    it('returns null for "0" string ID', function () {
        expect(EventManager::getSubscription('0'))->toBeNull();
    });

    it('returns null for non-existent UUID', function () {
        expect(EventManager::getSubscription('00000000-0000-0000-0000-000000000000'))->toBeNull();
    });

    it('returns the subscription when found', function () {
        $sub = Subscription::factory()->create();

        $result = EventManager::getSubscription($sub->id);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($sub->id);
    });
});
