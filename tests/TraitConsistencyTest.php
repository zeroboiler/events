<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use ZeroBoiler\Events\Console\EventsListCommand;
use ZeroBoiler\Events\Console\EventsSubscriptionsCommand;

describe('Console commands use EscapesWildcardLike trait', function (): void {
    test('EventsListCommand uses EscapesWildcardLike trait', function (): void {
        $uses = class_uses(EventsListCommand::class);

        expect($uses)
            ->toBeArray()
            ->toHaveKey(\ZeroBoiler\Events\Concerns\EscapesWildcardLike::class);
    });

    test('EventsSubscriptionsCommand uses EscapesWildcardLike trait', function (): void {
        $uses = class_uses(EventsSubscriptionsCommand::class);

        expect($uses)
            ->toBeArray()
            ->toHaveKey(\ZeroBoiler\Events\Concerns\EscapesWildcardLike::class);
    });

    test('EventManager uses EscapesWildcardLike via ManagesHistory trait', function (): void {
        $uses = class_uses_recursive(\ZeroBoiler\Events\EventManager::class);

        expect($uses)
            ->toBeArray()
            ->toHaveKey(\ZeroBoiler\Events\Concerns\EscapesWildcardLike::class);
    });

    test('Subscription model uses EscapesWildcardLike trait', function (): void {
        $uses = class_uses(\ZeroBoiler\Events\Models\Subscription::class);

        expect($uses)
            ->toBeArray()
            ->toHaveKey(\ZeroBoiler\Events\Concerns\EscapesWildcardLike::class);
    });
});
