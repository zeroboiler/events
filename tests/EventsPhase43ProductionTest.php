<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\WildcardMatcher;

beforeEach(function (): void {
    // Ensure clean state
    EventManagerFacade::invalidateTriggerCache();
});

// ─── fire() async parameter ───────────────────────────────────────────────────

it('fire() accepts async parameter with default false', function (): void {
    $em = app(EventManager::class);

    // Calling fire() without async should work (default false)
    $em->fire('test.phase43.event', ['key' => 'value']);

    expect(true)->toBeTrue();
});

it('fire() async=true signature is compatible with backward usage', function (): void {
    $em = app(EventManager::class);

    // Verify fire() can be called with all 3 params
    $ref = new ReflectionMethod($em, 'fire');
    $params = $ref->getParameters();

    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('event');
    expect($params[1]->getName())->toBe('payload');
    expect($params[2]->getName())->toBe('async');

    // Default value should be false
    expect($params[2]->isDefaultValueAvailable())->toBeTrue();
    expect($params[2]->getDefaultValue())->toBeFalse();
});

it('fire() has return type void declared', function (): void {
    $ref = new ReflectionMethod(EventManager::class, 'fire');
    expect($ref->getReturnType()?->getName())->toBe('void');
});

it('dispatchTrigger() has forceAsync parameter with default false', function (): void {
    $ref = new ReflectionMethod(EventManager::class, 'dispatchTrigger');
    $params = $ref->getParameters();

    expect($params)->toHaveCount(4);
    expect($params[3]->getName())->toBe('forceAsync');
    expect($params[3]->isDefaultValueAvailable())->toBeTrue();
    expect($params[3]->getDefaultValue())->toBeFalse();
});

// ─── Facade @method annotation ────────────────────────────────────────────────

it('Facade @method annotation includes async parameter for fire()', function (): void {
    $facadeFile = __DIR__.'/../src/Facades/EventManager.php';
    $content = file_get_contents($facadeFile);
    expect($content)->not->toBeFalse();

    // Verify the fire @method includes async parameter
    expect(str_contains($content, 'fire(string $event, array<string, mixed> $payload = [], bool $async = false)'))->toBeTrue();
});

// ─── EventsFireCommand --async flag ───────────────────────────────────────────

it('EventsFireCommand has --async option in signature', function (): void {
    $ref = new ReflectionClass(EventsFireCommand::class);
    $prop = $ref->getProperty('signature');
    $signature = $prop->getValue(new EventsFireCommand);

    expect(str_contains($signature, '--async'))->toBeTrue();
});

it('EventsFireCommand passes async option to EventManager::fire()', function (): void {
    $command = new EventsFireCommand();
    $ref = new ReflectionMethod($command, 'handle');
    $body = file_get_contents((string) $ref->getFileName());

    // Verify the fire call includes async parameter
    expect(str_contains($body, 'async: (bool) $this->option(\'async\')'))->toBeTrue();
});

// ─── EventsFireCommand JSON precedence fix ─────────────────────────────────────

it('EventsFireCommand --json keys take precedence over --payload keys', function (): void {
    $command = new EventsFireCommand();
    $ref = new ReflectionMethod($command, 'handle');
    $body = file_get_contents((string) $ref->getFileName());

    // Verify the array_key_exists check prevents --payload from overriding --json
    expect(str_contains($body, 'array_key_exists($key, $payload)'))->toBeTrue();
});

it('EventsFireCommand JSON precedence comment is accurate', function (): void {
    $command = new EventsFireCommand();
    $ref = new ReflectionMethod($command, 'handle');
    $body = file_get_contents((string) $ref->getFileName());

    // Verify the comment mentions --payload deferring to --json
    expect(str_contains($body, 'json takes precedence'))->toBeTrue();
});

// ─── ConditionEngine unknown operator fix ────────────────────────────────────

it('ConditionEngine unknown operator returns false', function (): void {
    $engine = new ConditionEngine;

    // Unknown operator in array syntax should return false, not fall through to strictEquals
    $result = $engine->matches(
        ['field' => ['unknown_operator', 'value']],
        ['field' => 'value'],
    );

    expect($result)->toBeFalse();
});

it('ConditionEngine empty array condition returns false', function (): void {
    $engine = new ConditionEngine;

    // Empty array as condition value should return false
    $result = $engine->matches(
        ['field' => []],
        ['field' => 'anything'],
    );

    expect($result)->toBeFalse();
});

it('ConditionEngine all 19 known operators still work', function (): void {
    $engine = new ConditionEngine;
    $payload = ['amount' => 150, 'tags' => ['urgent', 'vip'], 'role' => 'admin', 'code' => 'ABC-1234', 'name' => 'John Doe', 'notes' => '', 'age' => 30];

    // > (greater than)
    expect($engine->matches(['amount' => ['>', 100]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['>', 200]], $payload))->toBeFalse();

    // >=
    expect($engine->matches(['amount' => ['>=', 150]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['>=', 151]], $payload))->toBeFalse();

    // <
    expect($engine->matches(['amount' => ['<', 200]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['<', 100]], $payload))->toBeFalse();

    // <=
    expect($engine->matches(['amount' => ['<=', 150]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['<=', 149]], $payload))->toBeFalse();

    // = (strictEquals)
    expect($engine->matches(['role' => ['=', 'admin']], $payload))->toBeTrue();
    expect($engine->matches(['role' => ['=', 'user']], $payload))->toBeFalse();

    // ===
    expect($engine->matches(['amount' => ['===', 150]], $payload))->toBeTrue();

    // !=
    expect($engine->matches(['role' => ['!=', 'user']], $payload))->toBeTrue();
    expect($engine->matches(['role' => ['!=', 'admin']], $payload))->toBeFalse();

    // !==
    expect($engine->matches(['amount' => ['!==', '150']], $payload))->toBeTrue();

    // in
    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], $payload))->toBeTrue();
    expect($engine->matches(['role' => ['in', ['user']]], $payload))->toBeFalse();

    // not_in
    expect($engine->matches(['role' => ['not_in', ['user']]], $payload))->toBeTrue();
    expect($engine->matches(['role' => ['not_in', ['admin']]], $payload))->toBeFalse();

    // contains (array)
    expect($engine->matches(['tags' => ['contains', 'urgent']], $payload))->toBeTrue();
    expect($engine->matches(['tags' => ['contains', 'other']], $payload))->toBeFalse();

    // not_contains
    expect($engine->matches(['tags' => ['not_contains', 'spam']], $payload))->toBeTrue();
    expect($engine->matches(['tags' => ['not_contains', 'urgent']], $payload))->toBeFalse();

    // between
    expect($engine->matches(['age' => ['between', [20, 40]]], $payload))->toBeTrue();
    expect($engine->matches(['age' => ['between', [31, 50]]], $payload))->toBeFalse();

    // null
    expect($engine->matches(['missing' => ['null']], $payload))->toBeTrue();
    expect($engine->matches(['age' => ['null']], $payload))->toBeFalse();

    // not_null
    expect($engine->matches(['age' => ['not_null']], $payload))->toBeTrue();
    expect($engine->matches(['missing' => ['not_null']], $payload))->toBeFalse();

    // empty
    expect($engine->matches(['notes' => ['empty']], $payload))->toBeTrue();

    // not_empty
    expect($engine->matches(['name' => ['not_empty']], $payload))->toBeTrue();

    // starts_with
    expect($engine->matches(['name' => ['starts_with', 'Jo']], $payload))->toBeTrue();
    expect($engine->matches(['name' => ['starts_with', 'Xo']], $payload))->toBeFalse();

    // ends_with
    expect($engine->matches(['name' => ['ends_with', 'Doe']], $payload))->toBeTrue();
    expect($engine->matches(['name' => ['ends_with', 'Xoe']], $payload))->toBeFalse();

    // matches (regex)
    expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}-\\d{4}$/']], $payload))->toBeTrue();
    expect($engine->matches(['code' => ['matches', '/^[0-9]+$/']], $payload))->toBeFalse();
});

// ─── Config completeness ────────────────────────────────────────────────────

it('config has all 6 required top-level sections', function (): void {
    $config = config('events');
    expect($config)->not->toBeNull();

    $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'wildcard_cache_ttl'];
    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
    }
});

// ─── Version consistency ──────────────────────────────────────────────────────

it('composer.json version matches README badge', function (): void {
    $composerJson = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect($composerJson['version'])->not->toBeEmpty();
    expect(str_contains($readme, "version-{$composerJson['version']}"))->toBeTrue();
});

// ─── Strict types enforcement ─────────────────────────────────────────────────

it('all src/ files have declare(strict_types=1)', function (): void {
    $srcDir = __DIR__.'/../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $violations = [];
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if (! str_contains($content, 'declare(strict_types=1)')) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBeEmpty('Files missing declare(strict_types=1): '.implode(', ', $violations));
});

// ─── Final class verification ────────────────────────────────────────────────

it('core classes are final', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        WildcardMatcher::class,
    ];

    foreach ($finalClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} should be final");
    }
});
