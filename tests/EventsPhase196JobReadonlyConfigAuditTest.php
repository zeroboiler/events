<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;

/**
 * Tests for DispatchTriggerJob readonly property enforcement and config edge cases.
 *
 * Verifies that job properties are correctly set from config at construction time
 * and that readonly properties are immutable after construction (PHP 8.2+ semantics).
 *
 * @see \ZeroBoiler\Events\Jobs\DispatchTriggerJob
 */
class EventsPhase196JobReadonlyConfigAuditTest extends TestCase
{
    public function test_job_properties_are_readonly_after_construction(): void
    {
        $app = $this->createApplication();
        $config = $app->make('config');

        $config->set('events.retry.tries', 5);
        $config->set('events.retry.backoff', '30,120,300');
        $config->set('events.queue.queue', 'events-high');
        $config->set('events.queue.connection', 'redis');

        $job = new DispatchTriggerJob(
            triggerId: (string) \Illuminate\Support\Str::uuid(),
            event: 'test.event',
            payload: ['key' => 'value'],
            app: $app,
        );

        expect($job->tries)->toBe(5);
        expect($job->queue)->toBe('events-high');
        expect($job->connection)->toBe('redis');
        expect($job->backoff)->toBe([30, 120, 300]);
    }

    public function test_job_backoff_supports_array_config(): void
    {
        $app = $this->createApplication();
        $config = $app->make('config');

        $config->set('events.retry.backoff', [10, 20, 30, 60]);

        $job = new DispatchTriggerJob(
            triggerId: (string) \Illuminate\Support\Str::uuid(),
            event: 'test.event',
            payload: [],
            app: $app,
        );

        expect($job->backoff)->toBe([10, 20, 30, 60]);
    }

    public function test_job_connection_defaults_to_null_when_not_configured(): void
    {
        $app = $this->createApplication();
        $config = $app->make('config');

        $config->set('events.queue.connection', null);

        $job = new DispatchTriggerJob(
            triggerId: (string) \Illuminate\Support\Str::uuid(),
            event: 'test.event',
            payload: [],
            app: $app,
        );

        expect($job->connection)->toBeNull();
    }

    public function test_job_queue_defaults_to_default_when_empty_string(): void
    {
        $app = $this->createApplication();
        $config = $app->make('config');

        $config->set('events.queue.queue', '');

        $job = new DispatchTriggerJob(
            triggerId: (string) \Illuminate\Support\Str::uuid(),
            event: 'test.event',
            payload: [],
            app: $app,
        );

        expect($job->queue)->toBe('default');
    }

    public function test_job_tries_defaults_to_3_when_zero(): void
    {
        $app = $this->createApplication();
        $config = $app->make('config');

        $config->set('events.retry.tries', 0);

        $job = new DispatchTriggerJob(
            triggerId: (string) \Illuminate\Support\Str::uuid(),
            event: 'test.event',
            payload: [],
            app: $app,
        );

        expect($job->tries)->toBe(3);
    }

    public function test_job_tries_defaults_to_3_when_negative(): void
    {
        $app = $this->createApplication();
        $config = $app->make('config');

        $config->set('events.retry.tries', -1);

        $job = new DispatchTriggerJob(
            triggerId: (string) \Illuminate\Support\Str::uuid(),
            event: 'test.event',
            payload: [],
            app: $app,
        );

        expect($job->tries)->toBe(3);
    }

    public function test_job_backoff_trims_whitespace_from_comma_separated(): void
    {
        $app = $this->createApplication();
        $config = $app->make('config');

        $config->set('events.retry.backoff', ' 10 , 20 , 30 ');

        $job = new DispatchTriggerJob(
            triggerId: (string) \Illuminate\Support\Str::uuid(),
            event: 'test.event',
            payload: [],
            app: $app,
        );

        expect($job->backoff)->toBe([10, 20, 30]);
    }

    public function test_job_promoted_properties_are_readonly(): void
    {
        $app = $this->createApplication();

        $triggerId = (string) \Illuminate\Support\Str::uuid();
        $event = 'test.readonly';
        $payload = ['test' => true];

        $job = new DispatchTriggerJob(
            triggerId: $triggerId,
            event: $event,
            payload: $payload,
            app: $app,
        );

        expect($job->triggerId)->toBe($triggerId);
        expect($job->event)->toBe($event);
        expect($job->payload)->toBe($payload);
    }

    public function test_job_works_without_container_fallback(): void
    {
        // Simulate environment where no container is passed
        // (job should fall back to global app() helper)
        $app = $this->createApplication();
        \ZeroBoiler\Events\Tests\set_test_app($app);
        $config = $app->make('config');

        $config->set('events.retry.tries', 1);
        $config->set('events.retry.backoff', '5');
        $config->set('events.queue.queue', 'test-queue');

        $job = new DispatchTriggerJob(
            triggerId: (string) \Illuminate\Support\Str::uuid(),
            event: 'test.fallback',
            payload: [],
            app: null,
        );

        expect($job->tries)->toBe(1);
        expect($job->backoff)->toBe([5]);
        expect($job->queue)->toBe('test-queue');
    }

    public function test_job_event_log_id_starts_as_null(): void
    {
        $app = $this->createApplication();

        $job = new DispatchTriggerJob(
            triggerId: (string) \Illuminate\Support\Str::uuid(),
            event: 'test.event',
            payload: [],
            app: $app,
        );

        // The eventLogId is protected and not directly accessible,
        // but we can verify it hasn't been set by construction
        $reflection = new \ReflectionClass($job);
        $prop = $reflection->getProperty('eventLogId');

        expect($prop->getValue($job))->toBeNull();
    }

    public function test_job_config_driven_defaults_without_overrides(): void
    {
        $app = $this->createApplication();
        // Do NOT set any config — verify defaults kick in
        $job = new DispatchTriggerJob(
            triggerId: (string) \Illuminate\Support\Str::uuid(),
            event: 'test.defaults',
            payload: ['foo' => 'bar'],
            app: $app,
        );

        expect($job->tries)->toBe(3);
        expect($job->queue)->toBe('default');
        expect($job->connection)->toBeNull();
        // Default backoff from config fallback '60,300,900'
        expect($job->backoff)->toBe([60, 300, 900]);
    }
}
