<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Faker\Generator;
use Faker\Provider\DateTime;
use Faker\Provider\en_US\Address;
use Faker\Provider\en_US\Company;
use Faker\Provider\en_US\Person;
use Faker\Provider\Internet;
use Faker\Provider\Lorem;
use Faker\Provider\Miscellaneous;
use Faker\Provider\PhoneNumber;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;

// Load Composer autoloader
require_once __DIR__.'/../vendor/autoload.php';

// Global app instance for tests
global $__testAppInstance;
$__testAppInstance = null;

// Define helper functions for tests before anything else loads

if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $default;
    }
}

if (! function_exists('set_test_app')) {
    function set_test_app(Container $app): void
    {
        global $__testAppInstance;
        $__testAppInstance = $app;
    }
}

if (! function_exists('app')) {
    /**
     * @param  array<string, mixed>  $parameters
     */
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        global $__testAppInstance;

        if ($abstract === null) {
            return $__testAppInstance;
        }

        return $__testAppInstance?->make($abstract, $parameters) ?? null;
    }
}

if (! function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        static $config = null;

        if ($config === null && function_exists('app')) {
            $app = app();
            if ($app && isset($app['config'])) {
                $config = $app['config'];
            }
        }

        if ($key === null) {
            return $config;
        }

        if ($config instanceof Repository) {
            return $config->get($key, $default);
        }

        return $default;
    }
}

if (! function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return '/config/'.$path;
    }
}

if (! function_exists('now')) {
    function now($tz = null): Carbon
    {
        return Carbon::now($tz);
    }
}

if (! function_exists('fake')) {
    function fake(?string $locale = null): mixed
    {
        static $faker = null;

        if ($faker === null) {
            $faker = new Generator;
            // Add concrete providers manually, excluding the abstract Text provider
            $faker->addProvider(new Address($faker));
            $faker->addProvider(new Company($faker));
            $faker->addProvider(new Person($faker));
            $faker->addProvider(new Lorem($faker));
            $faker->addProvider(new Internet($faker));
            $faker->addProvider(new PhoneNumber($faker));
            $faker->addProvider(new DateTime($faker));
            $faker->addProvider(new Miscellaneous($faker));
        }

        return $faker;
    }
}
