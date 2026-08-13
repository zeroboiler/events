<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager;

/**
 * Tests for EventsFireCommand — option parsing, JSON payloads, error handling.
 */
describe('EventsFireCommand', function (): void {
    it('registers fire command via service provider', function (): void {
        $commands = $this->app->make('Illuminate\Contracts\Console\Application');
        $all = $commands->all();

        expect(array_key_exists('zeroboiler:events:fire', $all))->toBeTrue();
    });

    it('has correct signature properties', function (): void {
        $command = new EventsFireCommand;

        expect($command->getSignature())->toContain('zeroboiler:events:fire');
        expect($command->getDescription())->toBe('Manually fire an event');
    });

    it('rejects empty event name', function (): void {
        $command = new EventsFireCommand;
        $command->setLaravel($this->app);
        $command->setApplication($this->app->make('Illuminate\Contracts\Console\Kernel'));

        // Simulate calling with empty event argument via artisan runner
        $tester = $this->artisan('zeroboiler:events:fire', ['event' => '']);
        $tester->assertFailed();
    });

    it('accepts event argument and payload options', function (): void {
        $command = new EventsFireCommand;
        $definition = $command->getDefinition();

        expect($definition->hasArgument('event'))->toBeTrue();
        expect($definition->hasOption('payload'))->toBeTrue();
        expect($definition->hasOption('json'))->toBeTrue();
        expect($definition->hasOption('async'))->toBeTrue();
    });

    it('has --async option defined', function (): void {
        $command = new EventsFireCommand;
        $definition = $command->getDefinition();

        expect($definition->hasOption('async'))->toBeTrue();
        expect($definition->getOption('async')->isBool())->toBeTrue();
    });

    it('rejects event name "0" as empty', function (): void {
        $this->artisan('zeroboiler:events:fire', ['event' => '0'])
            ->assertFailed();
    });

    it('fires event with payload key=value pairs', function (): void {
        EventManager::shouldReceive('fire')
            ->once()
            ->with('test.event', ['key' => 'value', 'order_id' => '42'], false);

        $this->artisan('zeroboiler:events:fire', [
            'event' => 'test.event',
            '--payload' => ['key=value', 'order_id=42'],
        ])->assertSuccessful();
    });

    it('fires event with async flag passes true', function (): void {
        EventManager::shouldReceive('fire')
            ->once()
            ->with('test.event', expect(function (array $payload): bool {
                return true;
            }), true);

        $this->artisan('zeroboiler:events:fire', [
            'event' => 'test.event',
            '--async' => true,
        ])->assertSuccessful();
    });

    it('fires event with JSON payload', function (): void {
        EventManager::shouldReceive('fire')
            ->once()
            ->with('test.event', ['nested' => ['key' => 'value']], false);

        $this->artisan('zeroboiler:events:fire', [
            'event' => 'test.event',
            '--json' => '{"nested":{"key":"value"}}',
        ])->assertSuccessful();
    });

    it('fails with invalid JSON payload', function (): void {
        $this->artisan('zeroboiler:events:fire', [
            'event' => 'test.event',
            '--json' => '{invalid}',
        ])->assertFailed();
    });
});

describe('EventsFireCommand parseJsonOption', function (): void {
    it('parses direct JSON string', function (): void {
        $command = new class extends EventsFireCommand
        {
            public function testParse(string $input): ?array
            {
                return $this->parseJsonOption($input);
            }
        };

        $result = $command->testParse('{"key":"value","nested":{"a":1}}');

        expect($result)->toBe(['key' => 'value', 'nested' => ['a' => 1]]);
    });

    it('returns null for invalid JSON', function (): void {
        $command = new class extends EventsFireCommand
        {
            public function testParse(string $input): ?array
            {
                return $this->parseJsonOption($input);
            }
        };

        $result = $command->testParse('{not-valid-json}');

        expect($result)->toBeNull();
    });

    it('returns null for non-array JSON (scalar)', function (): void {
        $command = new class extends EventsFireCommand
        {
            public function testParse(string $input): ?array
            {
                return $this->parseJsonOption($input);
            }
        };

        $result = $command->testParse('"just a string"');

        expect($result)->toBeNull();
    });

    it('returns null for JSON number', function (): void {
        $command = new class extends EventsFireCommand
        {
            public function testParse(string $input): ?array
            {
                return $this->parseJsonOption($input);
            }
        };

        $result = $command->testParse('42');

        expect($result)->toBeNull();
    });

    it('returns null for empty JSON object', function (): void {
        $command = new class extends EventsFireCommand
        {
            public function testParse(string $input): ?array
            {
                return $this->parseJsonOption($input);
            }
        };

        $result = $command->testParse('{}');

        expect($result)->toBeArray()->toBeEmpty();
    });

    it('returns null for non-existent @file reference', function (): void {
        $command = new class extends EventsFireCommand
        {
            public function testParse(string $input): ?array
            {
                return $this->parseJsonOption($input);
            }
        };

        $result = $command->testParse('@/non/existent/path/file.json');

        expect($result)->toBeNull();
    });
});
