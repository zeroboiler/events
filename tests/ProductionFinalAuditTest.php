<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use ZeroBoiler\Events\Models\EventLog;

test('EventLog casts method returns expected keys', function (): void {
    $casts = (new EventLog)->casts();

    expect($casts)->toHaveKey('payload')
        ->and($casts['payload'])->toBe('array')
        ->and($casts)->toHaveKey('duration_ms')
        ->and($casts['duration_ms'])->toBe('int')
        ->and($casts)->toHaveKey('error')
        ->and($casts['error'])->toBe('string');
});

test('EventLog error cast is string type for PHPStan 9 compatibility', function (): void {
    $model = new EventLog;

    $casts = $model->casts();

    // The 'error' field must have an explicit string cast for PHPStan 9
    // so that markAsFailed(string $error) assignments are type-safe
    expect($casts)->toHaveKey('error');
    expect($casts['error'])->toBe('string');
});

test('rector.php exists and uses LaravelSetList', function (): void {
    $path = __DIR__.'/../rector.php';

    expect(File::exists($path))->toBeTrue('rector.php must exist');

    $content = File::get($path);

    expect($content)->toContain('RectorConfig', 'rector.php must use RectorConfig')
        ->and($content)->toContain('src', 'rector.php must scan src/ directory')
        ->and($content)->toContain('LaravelSetList', 'rector.php must use LaravelSetList');
});

test('all source files have declare(strict_types=1)', function (): void {
    $srcPath = __DIR__.'/../src';
    $files = File::allFiles($srcPath);

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = File::get($file->getPathname());
        $hasStrict = str_contains($contents, 'declare(strict_types=1)');

        expect($hasStrict)->toBeTrue("{$file->getRelativePathname()} must have declare(strict_types=1)");
    }
});

test('all console commands are final classes', function (): void {
    $commandPath = __DIR__.'/../src/Console';
    $files = glob($commandPath.'/*Command.php');

    expect($files)->not->toBeEmpty('Console commands directory must exist');

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        $className = basename($file, '.php');
        $namespace = 'ZeroBoiler\\Events\\Console\\'.$className;

        $reflected = new ReflectionClass($namespace);

        expect($reflected->isFinal())->toBeTrue("{$className} must be a final class");
    }
});

test('all core classes are final', function (): void {
    $classes = [
        \ZeroBoiler\Events\EventManager::class,
        \ZeroBoiler\Events\ActionResolver::class,
        \ZeroBoiler\Events\ConditionEngine::class,
        \ZeroBoiler\Events\TriggerBuilder::class,
        \ZeroBoiler\Events\SubscriptionBuilder::class,
        \ZeroBoiler\Events\Domain\DomainEvent::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
        \ZeroBoiler\Events\EventsServiceProvider::class,
    ];

    foreach ($classes as $class) {
        $reflected = new ReflectionClass($class);

        expect($reflected->isFinal())->toBeTrue("{$class} must be a final class");
    }
});

test('WildcardMatcher is readonly final class', function (): void {
    $reflected = new ReflectionClass(\ZeroBoiler\Events\WildcardMatcher::class);

    expect($reflected->isFinal())->toBeTrue('WildcardMatcher must be final')
        ->and($reflected->isReadOnly())->toBeTrue('WildcardMatcher must be readonly');
});
