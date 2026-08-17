<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

describe('EventManager Advanced CRUD', function (): void {
    it('returns null for non-existent trigger ID', function (): void {
        $manager = app(EventManager::class);

        $result = $manager->getTrigger('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeNull();
    });

    it('returns a trigger by ID after creation', function (): void {
        $manager = app(EventManager::class);

        $trigger = $manager->on('crud.test.get')
            ->name('Get Test Trigger')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        $result = $manager->getTrigger($trigger->id);

        expect($result)->not->toBeNull();
        expect($result->id)->toBe($trigger->id);
        expect($result->event)->toBe('crud.test.get');
        expect($result->name)->toBe('Get Test Trigger');
    });

    it('listTriggers returns empty collection when no triggers exist', function (): void {
        $manager = app(EventManager::class);

        // Use a unique event name unlikely to match anything
        $result = $manager->listTriggers('nonexistent.unique.event.12345');

        expect($result)->toBeEmpty();
    });

    it('listTriggers filters by event name (exact match)', function (): void {
        $manager = app(EventManager::class);

        $manager->on('crud.list.exact')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();
        $manager->on('crud.list.other')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();

        $result = $manager->listTriggers('crud.list.exact');

        expect($result)->toHaveCount(1);
        expect($result->first()->event)->toBe('crud.list.exact');
    });

    it('listTriggers filters by enabled status', function (): void {
        $manager = app(EventManager::class);

        $enabled = $manager->on('crud.enabled.test')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();
        $disabled = $manager->on('crud.disabled.test')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();
        $manager->disable($disabled->id);

        $enabledResults = $manager->listTriggers(null, enabled: true);
        $disabledResults = $manager->listTriggers(null, enabled: false);
        $allResults = $manager->listTriggers(null, enabled: null);

        expect($enabledResults->pluck('id')->contains($enabled->id))->toBeTrue();
        expect($enabledResults->pluck('id')->contains($disabled->id))->toBeFalse();
        expect($disabledResults->pluck('id')->contains($disabled->id))->toBeTrue();
        expect($allResults->pluck('id')->contains($enabled->id))->toBeTrue();
        expect($allResults->pluck('id')->contains($disabled->id))->toBeTrue();
    });

    it('listTriggers respects limit parameter', function (): void {
        $manager = app(EventManager::class);

        for ($i = 0; $i < 5; $i++) {
            $manager->on("crud.limit.test.{$i}")->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();
        }

        $result = $manager->listTriggers('crud.limit.test.*', limit: 2);

        expect($result)->toHaveCount(2);
    });

    it('deleteTrigger returns false for non-existent ID', function (): void {
        $manager = app(EventManager::class);

        $result = $manager->deleteTrigger('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    it('deleteTrigger removes trigger and invalidates cache', function (): void {
        $manager = app(EventManager::class);

        $trigger = $manager->on('crud.delete.test')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        // Verify it exists
        expect($manager->getTrigger($trigger->id))->not->toBeNull();

        // Delete it
        $result = $manager->deleteTrigger($trigger->id);
        expect($result)->toBeTrue();

        // Verify it no longer exists (soft-deleted, so find returns null by default in our test setup)
        $found = Trigger::withTrashed()->find($trigger->id);
        expect($found)->not->toBeNull();
        expect($found->deleted_at)->not->toBeNull();

        // getTrigger should return null (it uses find, not withTrashed)
        expect($manager->getTrigger($trigger->id))->toBeNull();
    });

    it('enable and disable return false for non-existent IDs', function (): void {
        $manager = app(EventManager::class);

        expect($manager->enable('00000000-0000-0000-0000-000000000000'))->toBeFalse();
        expect($manager->disable('00000000-0000-0000-0000-000000000000'))->toBeFalse();
    });

    it('invalidateTriggerCache clears the wildcard cache', function (): void {
        $manager = app(EventManager::class);

        // Prime the cache with a wildcard trigger
        $manager->on('cache.invalidate.*')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        // Fire an event that should cache wildcard triggers
        $manager->fire('cache.invalidate.test', []);

        // Verify cache has data
        $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
        expect(Cache::has($cacheKey))->toBeTrue();

        // Invalidate
        $manager->invalidateTriggerCache();

        expect(Cache::has($cacheKey))->toBeFalse();
    });

    it('setEnabled runtime toggle affects isDisabled', function (): void {
        $manager = app(EventManager::class);

        // Initially not disabled
        expect($manager->isDisabled())->toBeFalse();

        // Disable
        $manager->setEnabled(false);
        expect($manager->isDisabled())->toBeTrue();

        // Re-enable
        $manager->setEnabled(true);
        expect($manager->isDisabled())->toBeFalse();
    });

    it('fire with disabled system returns without dispatching', function (): void {
        $manager = app(EventManager::class);

        $manager->on('disabled.fire.test')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        $manager->setEnabled(false);
        $manager->fire('disabled.fire.test', ['key' => 'value']);

        // No event logs should have been created
        $logs = EventLog::where('event', 'disabled.fire.test')->get();
        expect($logs)->toBeEmpty();

        // Re-enable for subsequent tests
        $manager->setEnabled(true);
    });

    it('listTriggers with wildcard pattern uses LIKE query', function (): void {
        $manager = app(EventManager::class);

        $manager->on('crud.wildcard.a')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();
        $manager->on('crud.wildcard.b')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();
        $manager->on('other.prefix.x')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();

        $result = $manager->listTriggers('crud.wildcard.*');

        expect($result)->toHaveCount(2);
        foreach ($result as $t) {
            expect($t->event)->toMatch('/^crud\.wildcard\./');
        }
    });
});
