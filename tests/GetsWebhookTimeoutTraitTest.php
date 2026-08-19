<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;

/**
 * Stand-in class that uses the GetsWebhookTimeout trait.
 *
 * Without its own getConfig() method — exercises the fallback path
 * through the global app() helper.
 */
final class GetsWebhookTimeoutStub
{
    use GetsWebhookTimeout;
}

/**
 * Stand-in class that uses GetsWebhookTimeout and provides getConfig().
 *
 * Exercises the primary config resolution path.
 */
final class GetsWebhookTimeoutWithConfigStub
{
    use GetsWebhookTimeout;

    private ConfigRepository $configRepository;

    public function __construct(ConfigRepository $configRepository)
    {
        $this->configRepository = $configRepository;
    }

    /**
     * @since 1.0.0
     */
    protected function getConfig(): ConfigRepository
    {
        return $this->configRepository;
    }
}

/**
 * Tests for the GetsWebhookTimeout trait.
 *
 * @see \ZeroBoiler\Events\Concerns\GetsWebhookTimeout
 */
class GetsWebhookTimeoutTraitTest extends TestCase
{
    public function test_get_webhook_timeout_returns_int_from_valid_config(): void
    {
        $this->app['config']->set('events.subscriptions.timeout', 45);

        $stub = new GetsWebhookTimeoutStub();

        $result = $stub->getWebhookTimeout();

        expect($result)->toBe(45);
    }

    public function test_get_webhook_timeout_returns_int_from_numeric_string(): void
    {
        $this->app['config']->set('events.subscriptions.timeout', '60');

        $stub = new GetsWebhookTimeoutStub();

        $result = $stub->getWebhookTimeout();

        expect($result)->toBe(60);
    }

    public function test_get_webhook_timeout_returns_default_when_missing(): void
    {
        $this->app['config']->set('events.subscriptions.timeout', null);

        $stub = new GetsWebhookTimeoutStub();

        $result = $stub->getWebhookTimeout();

        expect($result)->toBe(30);
    }

    public function test_get_webhook_timeout_returns_default_when_zero(): void
    {
        $this->app['config']->set('events.subscriptions.timeout', 0);

        $stub = new GetsWebhookTimeoutStub();

        $result = $stub->getWebhookTimeout();

        expect($result)->toBe(30);
    }

    public function test_get_webhook_timeout_returns_default_when_negative(): void
    {
        $this->app['config']->set('events.subscriptions.timeout', -5);

        $stub = new GetsWebhookTimeoutStub();

        $result = $stub->getWebhookTimeout();

        expect($result)->toBe(30);
    }

    public function test_get_webhook_timeout_returns_default_when_non_numeric(): void
    {
        $this->app['config']->set('events.subscriptions.timeout', 'abc');

        $stub = new GetsWebhookTimeoutStub();

        $result = $stub->getWebhookTimeout();

        expect($result)->toBe(30);
    }

    public function test_get_webhook_timeout_returns_default_when_bool(): void
    {
        $this->app['config']->set('events.subscriptions.timeout', true);

        $stub = new GetsWebhookTimeoutStub();

        $result = $stub->getWebhookTimeout();

        expect($result)->toBe(30);
    }

    public function test_get_webhook_timeout_with_config_stub_returns_int(): void
    {
        $config = $this->app->make(ConfigRepository::class);
        $config->set('events.subscriptions.timeout', 120);

        $stub = new GetsWebhookTimeoutWithConfigStub($config);

        $result = $stub->getWebhookTimeout();

        expect($result)->toBe(120);
    }

    public function test_get_webhook_timeout_with_config_stub_falls_back_to_default(): void
    {
        $config = $this->app->make(ConfigRepository::class);
        $config->set('events.subscriptions.timeout', null);

        $stub = new GetsWebhookTimeoutWithConfigStub($config);

        $result = $stub->getWebhookTimeout();

        expect($result)->toBe(30);
    }

    public function test_get_webhook_config_returns_config_repository(): void
    {
        $stub = new GetsWebhookTimeoutStub();

        $result = $stub->getWebhookConfig();

        expect($result)->toBeInstanceOf(ConfigRepository::class);
    }

    public function test_get_webhook_timeout_handles_float_string(): void
    {
        $this->app['config']->set('events.subscriptions.timeout', '15.5');

        $stub = new GetsWebhookTimeoutStub();

        $result = $stub->getWebhookTimeout();

        expect($result)->toBe(15);
    }

    public function test_get_webhook_timeout_clamps_large_values(): void
    {
        $this->app['config']->set('events.subscriptions.timeout', 9999);

        $stub = new GetsWebhookTimeoutStub();

        $result = $stub->getWebhookTimeout();

        expect($result)->toBe(9999);
    }
}
