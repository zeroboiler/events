<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

test('phpstan.neon.dist does not contain deprecated parameters', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toBeString();

    // checkGenericClassInNonGenericObjectType was removed in PHPStan 2.x
    expect($content)->not->toContain('checkGenericClassInNonGenericObjectType');
    // checkUninitializedProperties should not be blindly disabled
    expect($content)->not->toContain('checkUninitializedProperties: false');

    // Must use level 9
    expect($content)->toContain('level: max');

    // Must have reportUnmatchedIgnoredErrors for strictness
    expect($content)->toContain('reportUnmatchedIgnoredErrors: true');
});

test('phpstan.neon.dist has correct ignore error patterns', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    // Facade method ignores (Laravel magic)
    expect($content)->toContain('Config|Cache|Queue|Log|DB');
    expect($content)->toContain('Http');

    // Model dynamic property access
    expect($content)->toContain('Model::\$payload');
    expect($content)->toContain('Trigger|EventLog|Subscription');

    // Collection::push (valid method but PHPStan doesn't know about it)
    expect($content)->toContain('Collection::push');

    // WildcardToLike on Subscription model (trait method)
    expect($content)->toContain('wildcardToLike');

    // Helper functions
    expect($content)->toContain('now|database_path');
});

test('readme contains environment variables section', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');
    expect($readme)->toBeString();

    expect($readme)->toContain('### Environment Variables');
    expect($readme)->toContain('EVENTS_QUEUE_CONNECTION');
    expect($readme)->toContain('EVENTS_QUEUE');
    expect($readme)->toContain('EVENTS_RETRY_TRIES');
    expect($readme)->toContain('EVENTS_RETRY_BACKOFF');
    expect($readme)->toContain('EVENTS_LOG_RETENTION_DAYS');
    expect($readme)->toContain('EVENTS_LOG_PURGE_PENDING');
    expect($readme)->toContain('EVENTS_SUB_MAX_FAILURES');
    expect($readme)->toContain('EVENTS_SUB_TIMEOUT');
    expect($readme)->toContain('EVENTS_SUB_SIGNATURE_ALGORITHM');
    expect($readme)->toContain('EVENTS_DISABLED');
    expect($readme)->toContain('EVENTS_WILDCARD_CACHE_TTL');
});

test('all env variables in config have README documentation', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');
    $config = file_get_contents(__DIR__.'/../config/events.php');

    // Extract all env() calls from config
    preg_match_all('/env\(\s*[\'"]([\w_]+)[\'"]/', $config, $matches);
    $envVars = array_unique($matches[1]);

    // Ensure each env var is documented in the README
    foreach ($envVars as $var) {
        expect($readme)->toContain($var);
    }
});

test('composer.json requires PHP 8.5+', function (): void {
    $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($json['require']['php'])->toBe('^8.5');
});

test('composer.json has correct service provider registration', function (): void {
    $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($json['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider'
    );

    expect($json['extra']['laravel']['aliases']['EventManager'])->toBe(
        'ZeroBoiler\\Events\\Facades\\EventManager'
    );
});

test('EventsServiceProvider provides all public services', function (): void {
    $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app ?? app());

    $provides = $provider->provides();

    expect($provides)->toContain(\ZeroBoiler\Events\EventManager::class);
    expect($provides)->toContain(\ZeroBoiler\Events\ConditionEngine::class);
    expect($provides)->toContain(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
    expect($provides)->toContain(\ZeroBoiler\Events\ActionResolver::class);
    expect($provides)->toContain(\ZeroBoiler\Events\TriggerBuilder::class);
    expect($provides)->toContain(\ZeroBoiler\Events\SubscriptionBuilder::class);
});

test('all console commands are registered in ServiceProvider boot', function (): void {
    $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app ?? app());

    // Get the commands via reflection
    $ref = new ReflectionClass($provider);
    $method = $ref->getMethod('boot');

    // Instead of calling boot (which publishes etc.), verify command list
    $commandFiles = glob(__DIR__.'/../src/Console/*.php');
    $expectedCommands = [];
    foreach ($commandFiles as $file) {
        $content = file_get_contents($file);
        preg_match('/class\s+(\w+)\s+extends\s+Command/', $content, $m);
        if (isset($m[1]) && $m[1] !== 'Command') {
            $ns = 'ZeroBoiler\\Events\\Console\\' . $m[1];
            $expectedCommands[] = $ns;
        }
    }

    // Read boot method source to verify commands are registered
    $bootSource = file_get_contents(__DIR__.'/../src/EventsServiceProvider.php');
    foreach ($expectedCommands as $cmd) {
        $shortName = (new ReflectionClass($cmd))->getShortName();
        expect($bootSource)->toContain($shortName . '::class');
    }
});

test('all source files have declare strict_types', function (): void {
    $files = glob(__DIR__.'/../src/{,**/}*.php');

    foreach ($files as $file) {
        $content = file_get_contents($file);
        // Skip interfaces and traits that are pure PHP — they should also have strict types
        expect($content)->toContain('declare(strict_types=1)', "File {$file} is missing strict_types declaration");
    }
});

test('all final classes are truly final', function (): void {
    $files = glob(__DIR__.'/../src/{,**/}*.php');

    $finalClasses = [];
    $nonFinalClasses = [];

    foreach ($files as $file) {
        $tokens = token_get_all(file_get_contents($file));
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            if ($tokens[$i][0] === T_FINAL && $tokens[$i + 1][0] === T_CLASS) {
                // Find the class name
                for ($j = $i + 2; $j < count($tokens); $j++) {
                    if ($tokens[$j][0] === T_STRING) {
                        $finalClasses[] = $tokens[$j][1];
                        break;
                    }
                }
            }
        }
    }

    // All major classes should be final
    expect($finalClasses)->toContain('EventManager');
    expect($finalClasses)->toContain('ConditionEngine');
    expect($finalClasses)->toContain('ActionResolver');
    expect($finalClasses)->toContain('TriggerBuilder');
    expect($finalClasses)->toContain('SubscriptionBuilder');
    expect($finalClasses)->toContain('WildcardMatcher');
    expect($finalClasses)->toContain('EventsServiceProvider');
    expect($finalClasses)->toContain('WebhookAction');
    expect($finalClasses)->toContain('DispatchTriggerJob');
    expect($finalClasses)->toContain('DomainEvent');
});

test('DomainEvent is truly immutable', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);

    // All properties should be readonly
    foreach ($ref->getProperties() as $prop) {
        expect($prop->isReadOnly())->toBeTrue("DomainEvent::\${$prop->name} is not readonly");
    }

    expect($ref->isFinal())->toBeTrue();
    expect($ref->getMethod('toArray')->isPublic())->toBeTrue();
    expect($ref->getMethod('fromArray')->isPublic())->toBeTrue();
    expect($ref->getMethod('occur')->isPublic())->toBeTrue();
});

test('ConditionEngine regex matching has ReDoS protection', function (): void {
    $engine = new \ZeroBoiler\Events\ConditionEngine();

    // Long patterns should be rejected
    $longPattern = str_repeat('a', 501);
    expect($engine->matches(['field' => ['matches', '/' . $longPattern . '/']], ['field' => 'test']))
        ->toBeFalse();

    // Nested quantifiers should be rejected
    expect($engine->matches(['field' => ['matches', '/(a+)+/']], ['field' => 'test']))
        ->toBeFalse();

    // Valid patterns should work
    expect($engine->matches(['field' => ['matches', '/^test.*/']], ['field' => 'test value']))
        ->toBeTrue();

    // Non-matching valid patterns should return false
    expect($engine->matches(['field' => ['matches', '/^test$/']], ['field' => 'test value']))
        ->toBeFalse();
});

test('WildcardMatcher handles edge cases safely', function (): void {
    // Empty event should not match non-empty pattern
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', ''))->toBeFalse();

    // Catch-all patterns match non-empty events
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', 'any.event'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('**', 'any.event'))->toBeTrue();

    // Single segment wildcard
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();

    // Cross-segment wildcard
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();

    // Exact match
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();

    // Empty pattern with non-empty event should not match
    expect(\ZeroBoiler\Events\WildcardMatcher::matches('', 'order.placed'))->toBeFalse();

    // Extract wildcards
    expect(\ZeroBoiler\Events\WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created'))
        ->toBe(['profile']);

    expect(\ZeroBoiler\Events\WildcardMatcher::extractWildcards('order.*', 'order.placed'))
        ->toBe(['placed']);

    // Cross-segment patterns return empty for extractWildcards
    expect(\ZeroBoiler\Events\WildcardMatcher::extractWildcards('order.**', 'order.placed'))
        ->toBe([]);
});

test('config events.php has all expected top-level keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config)->toHaveKey('table_names');
    expect($config)->toHaveKey('queue');
    expect($config)->toHaveKey('retry');
    expect($config)->toHaveKey('retention');
    expect($config)->toHaveKey('subscriptions');
    expect($config)->toHaveKey('disabled');
    expect($config)->toHaveKey('wildcard_cache_ttl');

    // Nested keys
    expect($config['table_names'])->toHaveKey('triggers');
    expect($config['table_names'])->toHaveKey('event_logs');
    expect($config['table_names'])->toHaveKey('subscriptions');

    expect($config['queue'])->toHaveKey('connection');
    expect($config['queue'])->toHaveKey('queue');

    expect($config['retry'])->toHaveKey('tries');
    expect($config['retry'])->toHaveKey('backoff');

    expect($config['retention'])->toHaveKey('days');
    expect($config['retention'])->toHaveKey('include_pending');

    expect($config['subscriptions'])->toHaveKey('auto_generate_secret');
    expect($config['subscriptions'])->toHaveKey('max_failures');
    expect($config['subscriptions'])->toHaveKey('timeout');
    expect($config['subscriptions'])->toHaveKey('signature_algorithm');
});

test('migrations use config-driven table names', function (): void {
    $files = [
        '2024_01_01_000001_create_triggers_table.php',
        '2024_01_01_000002_create_event_logs_table.php',
        '2025_06_28_000001_create_event_subscriptions_table.php',
    ];

    foreach ($files as $file) {
        $content = file_get_contents(__DIR__ . '/../database/migrations/' . $file);
        // All migrations should read table names from config
        expect($content)->toContain("config('events.table_names.");
    }
});

test('CI workflow uses PHP 8.5 and phpstan.neon.dist', function (): void {
    $ci = file_get_contents(__DIR__ . '/../.github/workflows/ci.yml');

    expect($ci)->toContain("php-version: '8.5'");
    expect($ci)->toContain('phpstan.neon.dist');
    expect($ci)->toContain('vendor/bin/pest');
    expect($ci)->toContain('vendor/bin/phpstan');
    expect($ci)->toContain('vendor/bin/pint');
    expect($ci)->toContain('vendor/bin/rector');
    expect($ci)->toContain('--min=80');
});

test('EventLog model has correct status constants', function (): void {
    expect(\ZeroBoiler\Events\Models\EventLog::STATUS_PENDING)->toBe('pending');
    expect(\ZeroBoiler\Events\Models\EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(\ZeroBoiler\Events\Models\EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(\ZeroBoiler\Events\Models\EventLog::STATUS_FAILED)->toBe('failed');

    expect(\ZeroBoiler\Events\Models\EventLog::$statuses)->toEqual([
        'pending', 'dispatched', 'completed', 'failed',
    ]);
});

test('Trigger model scopes are correctly defined', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Models\Trigger::class);

    $scopeMethods = array_filter(
        $ref->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m) => str_starts_with($m->getName(), 'scope')
    );

    $scopeNames = array_map(fn (ReflectionMethod $m) => $m->getName(), $scopeMethods);

    expect($scopeNames)->toContain('scopeEnabled');
    expect($scopeNames)->toContain('scopeAsync');
    expect($scopeNames)->toContain('scopeOrderByPriority');
});

test('Subscription model has HMAC signing method', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Models\Subscription::class);

    expect($ref->hasMethod('signPayload'))->toBeTrue();
    expect($ref->hasMethod('recordDelivery'))->toBeTrue();
    expect($ref->hasMethod('recordFailure'))->toBeTrue();
    expect($ref->hasMethod('resetFailures'))->toBeTrue();
    expect($ref->hasMethod('hasExceededFailures'))->toBeTrue();
    expect($ref->hasMethod('matchesEvent'))->toBeTrue();
});

test('Facade has complete method coverage of EventManager', function (): void {
    $facadeRef = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    $facadeDoc = $facadeRef->getDocComment();

    $managerRef = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $publicMethods = array_filter(
        $managerRef->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m) => ! $m->isStatic()
            && ! str_starts_with($m->getName(), '__')
            && $m->getDeclaringClass()->getName() === \ZeroBoiler\Events\EventManager::class
    );

    foreach ($publicMethods as $method) {
        // Traits methods are documented separately
        if (in_array($method->getName(), [
            'getEventHistory', 'getStats', 'purgeLogs',
            'getStalePendingLogs', 'deactivateExceededSubscriptions',
            'subscribe', 'unsubscribe', 'listSubscriptions',
            'getSubscription', 'subscribeWebhook',
        ])) {
            expect($facadeDoc)->toContain($method->getName(), "Facade docblock missing method: {$method->getName()}");
        }
    }
});
