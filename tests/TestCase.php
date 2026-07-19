<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected static ?Container $app = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a fresh application for each test to ensure clean database state
        self::$app = $this->createApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clear facade resolved instances so they re-resolve from the next test's app
        Facade::clearResolvedInstances();

        // Clear Eloquent booted models so they re-boot with new connection resolvers
        // and re-register the UUID-generating creating callbacks.
        Model::clearBootedModels();

        // Clear resolved instances between tests
        if (self::$app instanceof Container) {
            foreach (self::$app->getBindings() as $key => $binding) {
                self::$app->forgetInstance($key);
            }
        }
    }
}
