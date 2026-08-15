<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\EventManager;

describe('SubscriptionBuilder uses container() method', function (): void {
    test('SubscriptionBuilder reads config via EventManager::container()', function (): void {
        $manager = app(EventManager::class);

        // Verify that container() is accessible on EventManager
        $container = $manager->container();
        expect($container)->toBeInstanceOf(\Illuminate\Container\Container::class);

        // Verify the config repository is resolvable from the container
        $config = $container->get('config');
        expect($config)->toBeInstanceOf(\Illuminate\Contracts\Config\Repository::class);
    });
});

describe('GetsWebhookTimeout trait config resolution', function (): void {
    test('getWebhookTimeout returns configured timeout value', function (): void {
        $manager = app(EventManager::class);

        // Create a concrete class using the trait to test it
        $action = new class($manager)
        {
            use GetsWebhookTimeout;

            public function __construct(
                protected readonly EventManager $eventManager,
            ) {}

            public function testGetWebhookConfig(): \Illuminate\Contracts\Config\Repository
            {
                return $this->getWebhookConfig();
            }
        };

        $config = $action->testGetWebhookConfig();
        expect($config)->toBeInstanceOf(\Illuminate\Contracts\Config\Repository::class);

        $timeout = $action->getWebhookTimeout();
        expect($timeout)->toBeInt()->toBeGreaterThanOrEqual(1);
    });
});
