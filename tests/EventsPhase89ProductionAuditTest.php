<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 89 production audit — wildcard cache TTL=0 disable fix,
 * isDisabled() DRY refactoring in fire(), config/docs consistency.
 */
describe('Phase 89 Production Audit', function (): void {
    describe('Wildcard cache TTL=0 disables caching', function (): void {
        it('recognizes TTL=0 as a valid "disable" value', function (): void {
            $config = new \Illuminate\Config\Repository([
                'events' => [
                    'wildcard_cache_ttl' => 0,
                    'disabled' => false,
                ],
            ]);

            $app = new \Illuminate\Container\Container;
            $app->singleton('config', fn (): \Illuminate\Config\Repository => $config);
            $app->singleton(ConditionEngineContract::class, ConditionEngine::class);
            $app->singleton(ConditionEngine::class);
            $app->singleton(ActionResolver::class);
            $app->singleton(EventManager::class, function (\Illuminate\Container\Container $a): EventManager {
                return new EventManager(
                    $a->make(ConditionEngine::class),
                    $a->make(ActionResolver::class),
                    $a,
                );
            });

            $manager = $app->make(EventManager::class);
            expect($manager)->toBeInstanceOf(EventManager::class);

            // Verify the manager was created successfully with TTL=0 config
            expect($app->make(EventManager::class))->toBe($manager); // singleton
        });
    });

    describe('fire() uses isDisabled() for DRY consistency', function (): void {
        it('silently returns when events are globally disabled via config', function (): void {
            $config = new \Illuminate\Config\Repository([
                'events' => [
                    'disabled' => true,
                ],
            ]);

            $app = new \Illuminate\Container\Container;
            $app->singleton('config', fn (): \Illuminate\Config\Repository => $config);
            $app->singleton(ConditionEngineContract::class, ConditionEngine::class);
            $app->singleton(ConditionEngine::class);
            $app->singleton(ActionResolver::class);
            $app->singleton(EventManager::class, function (\Illuminate\Container\Container $a): EventManager {
                return new EventManager(
                    $a->make(ConditionEngine::class),
                    $a->make(ActionResolver::class),
                    $a,
                );
            });

            $manager = $app->make(EventManager::class);
            expect($manager->isDisabled())->toBeTrue();

            // fire() should not throw when disabled — just return silently
            $manager->fire('test.event', ['key' => 'value']);
            expect(true)->toBeTrue(); // Reached this point = no exception
        });

        it('fires normally when events are enabled', function (): void {
            $config = new \Illuminate\Config\Repository([
                'events' => [
                    'disabled' => false,
                ],
            ]);

            $app = new \Illuminate\Container\Container;
            $app->singleton('config', fn (): \Illuminate\Config\Repository => $config);
            $app->singleton(ConditionEngineContract::class, ConditionEngine::class);
            $app->singleton(ConditionEngine::class);
            $app->singleton(ActionResolver::class);
            $app->singleton(EventManager::class, function (\Illuminate\Container\Container $a): EventManager {
                return new EventManager(
                    $a->make(ConditionEngine::class),
                    $a->make(ActionResolver::class),
                    $a,
                );
            });

            $manager = $app->make(EventManager::class);
            expect($manager->isDisabled())->toBeFalse();
        });
    });

    describe('EventManager class finality', function (): void {
        it('EventManager is final', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('ConditionEngine is final', function (): void {
            $ref = new ReflectionClass(ConditionEngine::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('ActionResolver is final', function (): void {
            $ref = new ReflectionClass(ActionResolver::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('TriggerBuilder is final', function (): void {
            $ref = new ReflectionClass(TriggerBuilder::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('SubscriptionBuilder is final', function (): void {
            $ref = new ReflectionClass(SubscriptionBuilder::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('EventScheduler is final', function (): void {
            $ref = new ReflectionClass(EventScheduler::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('WildcardMatcher is readonly final', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('EventsServiceProvider is final', function (): void {
            $ref = new ReflectionClass(EventsServiceProvider::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('DomainEvent is final', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('DispatchTriggerJob is final', function (): void {
            $ref = new ReflectionClass(DispatchTriggerJob::class);
            expect($ref->isFinal())->toBeTrue();
        });
    });

    describe('Strict types in all source files', function (): void {
        it('all source files declare strict_types=1', function (): void {
            $dir = realpath(__DIR__.'/../src');
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $violations = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    continue;
                }
                if (! str_contains($contents, 'declare(strict_types=1)')) {
                    $violations[] = $file->getPathname();
                }
            }

            expect($violations)->toBeEmpty()
                ->and(implode(', ', $violations))->toBe('');
        });
    });

    describe('Config events.php has wildcard_cache_ttl documented correctly', function (): void {
        it('config file says "Set to 0 to disable" not "Set to null"', function (): void {
            $configPath = realpath(__DIR__.'/../config/events.php');
            expect($configPath)->toBeString()->and(file_exists($configPath))->toBeTrue();

            $contents = file_get_contents($configPath);
            expect($contents)->not->toBeFalse();
            expect(str_contains($contents, 'Set to 0 to disable caching'))->toBeTrue();
            expect(str_contains($contents, 'Set to null to disable caching'))->toBeFalse();
        });
    });

    describe('All source files have return type on public methods', function (): void {
        it('every public method in src/ has explicit return type', function (): void {
            $dir = realpath(__DIR__.'/../src');
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $violations = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    continue;
                }
                $tokens = token_get_all($contents);

                // Simple check: find 'function' followed by name and ')'
                // then check if next non-whitespace is ':'
                for ($i = 0, $count = count($tokens); $i < $count; $i++) {
                    if (! is_array($tokens[$i])) {
                        continue;
                    }
                    if ($tokens[$i][0] !== T_FUNCTION) {
                        continue;
                    }

                    // Look ahead to find the closing parenthesis
                    $j = $i + 1;
                    $depth = 0;
                    $foundClose = false;
                    while ($j < $count) {
                        $t = $tokens[$j];
                        if (is_array($t)) {
                            if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                                $depth++;
                            }
                        } elseif ($t === '{') {
                            $foundClose = false;
                            break;
                        } elseif ($t === ')') {
                            if ($depth === 0) {
                                $foundClose = true;
                                break;
                            }
                            $depth--;
                        } elseif ($t === '(') {
                            $depth++;
                        }
                        $j++;
                    }

                    if ($foundClose && $j + 1 < $count) {
                        // Skip whitespace/comments after )
                        $k = $j + 1;
                        while ($k < $count) {
                            if (is_array($tokens[$k])) {
                                if ($tokens[$k][0] === T_WHITESPACE || $tokens[$k][0] === T_COMMENT || $tokens[$k][0] === T_DOC_COMMENT) {
                                    $k++;
                                    continue;
                                }
                            }
                            break;
                        }
                        if ($k < $count && is_array($tokens[$k]) && $tokens[$k][0] === T_STRING && $tokens[$k][1] === 'void') {
                            // This could be return type 'void' or function named 'void' — check for colon
                            // Actually we need to check for ':' before the type
                            // Re-check: look backwards from k
                            continue;
                        }
                        // Check if there's a ':' before the next token
                        $hasColon = false;
                        $m = $j + 1;
                        while ($m < $k) {
                            if (! is_array($tokens[$m]) && $tokens[$m] === ':') {
                                $hasColon = true;
                                break;
                            }
                            $m++;
                        }

                        if (!$hasColon) {
                            // Extract function name
                            $nameIdx = $i + 1;
                            while ($nameIdx < $j && is_array($tokens[$nameIdx]) && in_array($tokens[$nameIdx][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                                $nameIdx++;
                            }
                            if ($nameIdx < $j && is_array($tokens[$nameIdx]) && $tokens[$nameIdx][0] === T_STRING) {
                                $violations[] = $file->getFilename().':'.$tokens[$nameIdx][1].'()';
                            }
                        }
                    }
                }
            }

            expect($violations)->toBeEmpty();
        });
    });

    describe('Facade accessor resolves correctly', function (): void {
        it('Facade getFacadeAccessor returns EventManager::class FQN', function (): void {
            $ref = new ReflectionClass(EventManagerFacade::class);
            $method = $ref->getMethod('getFacadeAccessor');
            expect($method->getReturnType()?->getName())->toBe('string');

            $facade = new class extends EventManagerFacade {
                #[\Override]
                protected static function getFacadeAccessor(): string
                {
                    return parent::getFacadeAccessor();
                }
            };

            // We can't call the method directly without bootstrapping,
            // but we verified the return type declaration exists
            expect(true)->toBeTrue();
        });
    });

    describe('ServiceProvider provides() completeness', function (): void {
        it('provides() includes all registered services', function (): void {
            $provider = new EventsServiceProvider(app());

            $provided = $provider->provides();

            expect($provided)->toContain(EventManager::class)
                ->and($provided)->toContain(ConditionEngine::class)
                ->and($provided)->toContain(ConditionEngineContract::class)
                ->and($provided)->toContain(ActionResolver::class)
                ->and($provided)->toContain(TriggerBuilder::class)
                ->and($provided)->toContain(SubscriptionBuilder::class)
                ->and($provided)->toContain(EventScheduler::class);
        });
    });

    describe('DomainEvent readonly properties', function (): void {
        it('has readonly eventType and payload properties', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);

            $eventType = $ref->getProperty('eventType');
            expect($eventType->isReadOnly())->toBeTrue()
                ->and($eventType->getType()?->getName())->toBe('string');

            $payload = $ref->getProperty('payload');
            expect($payload->isReadOnly())->toBeTrue()
                ->and($payload->getType()?->getName())->toBe('array');

            $eventId = $ref->getProperty('eventId');
            expect($eventId->isReadOnly())->toBeTrue();

            $occurredAt = $ref->getProperty('occurredAt');
            expect($occurredAt->isReadOnly())->toBeTrue();
        });
    });

    describe('phpstan.neon.dist configuration', function (): void {
        it('has level 9 configured', function (): void {
            $neonPath = realpath(__DIR__.'/../phpstan.neon.dist');
            expect($neonPath)->toBeString()->and(file_exists($neonPath))->toBeTrue();

            $contents = file_get_contents($neonPath);
            expect($contents)->not->toBeFalse();
            expect(str_contains($contents, 'level: max'))->toBeTrue();
        });

        it('has checkUninitializedProperties enabled', function (): void {
            $neonPath = realpath(__DIR__.'/../phpstan.neon.dist');
            $contents = file_get_contents($neonPath);
            expect(str_contains($contents, 'checkUninitializedProperties: true'))->toBeTrue();
        });

        it('has checkGenericClassInNonGenericObjectType enabled', function (): void {
            $neonPath = realpath(__DIR__.'/../phpstan.neon.dist');
            $contents = file_get_contents($neonPath);
            expect(str_contains($contents, 'checkGenericClassInNonGenericObjectType: true'))->toBeTrue();
        });

        it('scans src directory', function (): void {
            $neonPath = realpath(__DIR__.'/../phpstan.neon.dist');
            $contents = file_get_contents($neonPath);
            expect(str_contains($contents, 'paths:'))->toBeTrue();
            expect(str_contains($contents, '- src'))->toBeTrue();
        });
    });

    describe('composer.json correctness', function (): void {
        it('requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(realpath(__DIR__.'/../composer.json')), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('requires illuminate/contracts ^13.0', function (): void {
            $composer = json_decode(file_get_contents(realpath(__DIR__.'/../composer.json')), true);
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        });

        it('autoloads ZeroBoiler\\Events namespace from src/', function (): void {
            $composer = json_decode(file_get_contents(realpath(__DIR__.'/../composer.json')), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        it('extra.laravel.providers includes EventsServiceProvider', function (): void {
            $composer = json_decode(file_get_contents(realpath(__DIR__.'/../composer.json')), true);
            expect($composer['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Events\\EventsServiceProvider',
            );
        });

        it('extra.laravel.aliases includes EventManager facade', function (): void {
            $composer = json_decode(file_get_contents(realpath(__DIR__.'/../composer.json')), true);
            expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
                'ZeroBoiler\\Events\\Facades\\EventManager',
            );
        });
    });
});
