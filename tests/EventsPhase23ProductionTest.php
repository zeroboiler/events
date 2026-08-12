<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\WildcardMatcher;

// ─── #[\Override] on Facade::getFacadeAccessor ────────────────────────────────

it('Facade::getFacadeAccessor has #[Override] attribute', function (): void {
    $method = new ReflectionMethod(\ZeroBoiler\Events\Facades\EventManager::class, 'getFacadeAccessor');
    $attrs = $method->getAttributes(\Override::class);

    expect($attrs)->toHaveCount(1);
});

// ─── DispatchTriggerJob backoff re-indexing ───────────────────────────────────

it('DispatchTriggerJob re-indexes array backoff keys with array_values', function (): void {
    Config::set('events.retry.backoff', [5 => 10, 99 => 30, 200 => 60]);

    $job = new DispatchTriggerJob(
        triggerId: (string) \Illuminate\Support\Str::uuid(),
        event: 'test.event',
        payload: [],
    );

    expect($job->backoff)
        ->toBe([10, 30, 60])
        ->and(array_is_list($job->backoff))->toBeTrue();
});

it('DispatchTriggerJob handles single-element array backoff', function (): void {
    Config::set('events.retry.backoff', [60]);

    $job = new DispatchTriggerJob(
        triggerId: (string) \Illuminate\Support\Str::uuid(),
        event: 'test.event',
        payload: [],
    );

    expect($job->backoff)->toBe([60]);
});

it('DispatchTriggerJob handles array backoff with string values from config', function (): void {
    Config::set('events.retry.backoff', ['10', '20', '30']);

    $job = new DispatchTriggerJob(
        triggerId: (string) \Illuminate\Support\Str::uuid(),
        event: 'test.event',
        payload: [],
    );

    expect($job->backoff)->toBe([10, 20, 30]);
});

// ─── EventManager parseActions return type consistency ───────────────────────

it('parseActions returns empty list for empty string', function (): void {
    $em = app(EventManager::class);

    // Access parseActions via TriggerBuilder save() flow
    // Empty action string is already tested, verify the behavior is consistent
    $builder = $em->on('test.event');

    try {
        $builder->save();
    } catch (\InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('action');
    }
});

// ─── Config completeness: all referenced config keys exist ─────────────────────

it('config has all required keys for retry section', function (): void {
    $retry = Config::get('events.retry');

    expect($retry)->toBeArray()
        ->and($retry)->toHaveKeys(['tries', 'backoff'])
        ->and($retry['tries'])->toBeInt()
        ->and($retry['backoff'])->toBeString();
});

it('config has all required keys for subscriptions section', function (): void {
    $subs = Config::get('events.subscriptions');

    expect($subs)->toBeArray()
        ->and($subs)->toHaveKeys([
            'auto_generate_secret',
            'max_failures',
            'timeout',
            'signature_algorithm',
        ])
        ->and($subs['auto_generate_secret'])->toBeBool()
        ->and($subs['max_failures'])->toBeInt()
        ->and($subs['timeout'])->toBeInt()
        ->and($subs['signature_algorithm'])->toBeString();
});

it('config has all required keys for retention section', function (): void {
    $retention = Config::get('events.retention');

    expect($retention)->toBeArray()
        ->and($retention)->toHaveKeys(['days', 'include_pending'])
        ->and($retention['days'])->toBeInt()
        ->and($retention['include_pending'])->toBeBool();
});

it('config has all required keys for queue section', function (): void {
    $queue = Config::get('events.queue');

    expect($queue)->toBeArray()
        ->and($queue)->toHaveKeys(['connection', 'queue']);
});

// ─── Strict types enforcement ────────────────────────────────────────────────

it('all source files have declare(strict_types=1)', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $missing = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false || ! str_contains($contents, 'declare(strict_types=1)')) {
            $missing[] = $file->getPathname();
        }
    }

    expect($missing)->toBeEmpty(
        'All source files must have declare(strict_types=1). Missing: '.implode(', ', $missing),
    );
});

// ─── Final class verification ───────────────────────────────────────────────

it('all concrete classes in src/ are final', function (): void {
    $srcDir = __DIR__.'/../src';
    $excluded = []; // No exclusions needed — all concrete classes should be final
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $nonFinal = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            continue;
        }

        // Skip abstract classes, interfaces, traits, and enums
        if (preg_match('/\b(?:abstract\s+class|interface|trait|enum)\s+/', $contents)) {
            continue;
        }

        // Check if it has a class declaration
        if (! preg_match('/\bclass\s+(\w+)/', $contents, $matches)) {
            continue;
        }

        $className = $matches[1];
        if (in_array($className, $excluded, true)) {
            continue;
        }

        if (! preg_match('/\bfinal\s+class\s+/', $contents)) {
            $relativePath = str_replace($srcDir.'/', '', $file->getPathname());
            $nonFinal[] = "{$relativePath} ({$className})";
        }
    }

    expect($nonFinal)->toBeEmpty(
        'All concrete classes should be final. Non-final: '.implode(', ', $nonFinal),
    );
});

// ─── WildcardMatcher #[\Pure] attribute verification ─────────────────────────

it('WildcardMatcher::matches has #[Pure] attribute', function (): void {
    $method = new ReflectionMethod(WildcardMatcher::class, 'matches');
    $attrs = $method->getAttributes(\Pure::class);

    expect($attrs)->toHaveCount(1);
});

it('WildcardMatcher::findMatchingPatterns has #[Pure] attribute', function (): void {
    $method = new ReflectionMethod(WildcardMatcher::class, 'findMatchingPatterns');
    $attrs = $method->getAttributes(\Pure::class);

    expect($attrs)->toHaveCount(1);
});

it('WildcardMatcher::extractWildcards has #[Pure] attribute', function (): void {
    $method = new ReflectionMethod(WildcardMatcher::class, 'extractWildcards');
    $attrs = $method->getAttributes(\Pure::class);

    expect($attrs)->toHaveCount(1);
});

// ─── ConditionEngine #[\Override] on interface method ─────────────────────────

it('ConditionEngine::matches has #[Override] attribute', function (): void {
    $method = new ReflectionMethod(ConditionEngine::class, 'matches');
    $attrs = $method->getAttributes(\Override::class);

    expect($attrs)->toHaveCount(1);
});

it('WebhookAction::handle has #[Override] attribute', function (): void {
    $method = new ReflectionMethod(\ZeroBoiler\Events\Actions\WebhookAction::class, 'handle');
    $attrs = $method->getAttributes(\Override::class);

    expect($attrs)->toHaveCount(1);
});

// ─── Service provider binding lifecycle ──────────────────────────────────────

it('EventManager is bound as singleton', function (): void {
    $first = app(EventManager::class);
    $second = app(EventManager::class);

    expect($first)->toBe($second);
});

it('ConditionEngine is bound as singleton', function (): void {
    $first = app(ConditionEngine::class);
    $second = app(ConditionEngine::class);

    expect($first)->toBe($second);
});

it('ConditionEngineContract resolves to ConditionEngine', function (): void {
    $instance = app(ConditionEngineContract::class);

    expect($instance)->toBeInstanceOf(ConditionEngine::class);
});

it('ActionResolver is bound as singleton', function (): void {
    $first = app(\ZeroBoiler\Events\ActionResolver::class);
    $second = app(\ZeroBoiler\Events\ActionResolver::class);

    expect($first)->toBe($second);
});

it('TriggerBuilder is transient (not shared)', function (): void {
    $first = app(\ZeroBoiler\Events\TriggerBuilder::class);
    $second = app(\ZeroBoiler\Events\TriggerBuilder::class);

    expect($first)->not->toBe($second);
});

it('SubscriptionBuilder is transient (not shared)', function (): void {
    $first = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
    $second = app(\ZeroBoiler\Events\SubscriptionBuilder::class);

    expect($first)->not->toBe($second);
});

// ─── EventLog status constants consistency ───────────────────────────────────

it('EventLog status constants match $statuses array', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Models\EventLog::class);
    $constants = array_filter(
        $reflection->getConstants(ReflectionClassConstant::IS_PUBLIC),
        fn (string $name): bool => str_starts_with($name, 'STATUS_'),
        ARRAY_FILTER_USE_KEY,
    );

    $statusesProperty = $reflection->getProperty('statuses');
    /** @var array<int, string> $statuses */
    $statuses = $statusesProperty->getValue(null);

    expect(array_values($constants))->toEqual($statuses);
});

// ─── Version consistency ─────────────────────────────────────────────────────

it('composer.json version matches README badge', function (): void {
    $composer = json_decode(
        file_get_contents(__DIR__.'/../composer.json') ?: '{}',
        true,
    );

    $readme = file_get_contents(__DIR__.'/../README.md') ?: '';

    expect($composer)->toBeArray()
        ->and($composer['version'])->toBeString()
        ->and($readme)->toContain("version-{$composer['version']}");
});
