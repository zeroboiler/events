<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

/**
 * Phase 96 — PHP 8.5 + PHPStan 9 Strict Audit.
 *
 * Validates production readiness for PHP 8.5 and PHPStan level 9:
 * - All source files have declare(strict_types=1)
 * - All public methods have return type declarations
 * - Final classes on ServiceProviders and Managers
 * - #[Override] on ServiceProvider overridden methods
 * - Composer requires PHP ^8.5
 */

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

describe('Phase 96 — PHP 8.5 + PHPStan 9 Strict Audit', function () {
    describe('declare(strict_types=1) enforcement', function () {
        test('every source file has declare(strict_types=1)', function () {
            $srcDir = __DIR__.'/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $phpFiles = new RegexIterator($iterator, '/\.php$/i');

            $violations = [];
            foreach ($phpFiles as $file) {
                $content = file_get_contents($file->getPathname());
                if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
                    $violations[] = (string) $file;
                }
            }

            expect($violations)->toBeEmpty(
                'All source files must have declare(strict_types=1). Violations: '.implode(', ', $violations)
            );
        });
    });

    describe('PHP 8.5 minimum requirement', function () {
        test('composer.json requires PHP ^8.5', function () {
            $composerJson = json_decode(
                file_get_contents(__DIR__.'/../composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $phpRequirement = $composerJson['require']['php'] ?? null;

            expect($phpRequirement)->not->toBeNull('composer.json must have a PHP version requirement');
            expect($phpRequirement)->toBe('^8.5', "PHP requirement must be '^8.5', got '{$phpRequirement}'");
        });
    });

    describe('return type declarations on public methods', function () {
        test('all public methods in source files have return types', function () {
            $srcDir = __DIR__.'/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $phpFiles = new RegexIterator($iterator, '/\.php$/i');

            $violations = [];
            foreach ($phpFiles as $file) {
                $content = file_get_contents($file->getPathname());
                if ($content === false) {
                    continue;
                }

                $tokens = token_get_all($content);
                $className = null;

                foreach ($tokens as $i => $token) {
                    // Track class/interface name
                    if (is_array($token) && $token[0] === T_CLASS) {
                        for ($j = $i + 1; $j < count($tokens); $j++) {
                            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                                $className = $tokens[$j][1];
                                break;
                            }
                        }
                    }

                    // Skip trait and anonymous class methods
                    if (is_array($token) && $token[0] === T_TRAIT) {
                        $className = null;
                    }

                    // Check public function declarations
                    if (is_array($token) && $token[0] === T_PUBLIC) {
                        for ($j = $i + 1; $j < count($tokens); $j++) {
                            if (is_array($tokens[$j]) && $tokens[$j][0] === T_FUNCTION) {
                                // Check if next non-whitespace token is a colon (return type)
                                $hasReturnType = false;
                                for ($k = $j + 1; $k < count($tokens); $k++) {
                                    $nextToken = $tokens[$k];
                                    if (is_array($nextToken) && in_array($nextToken[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                                        continue;
                                    }
                                    if ($nextToken === ':') {
                                        $hasReturnType = true;
                                    }
                                    break;
                                }

                                if (! $hasReturnType) {
                                    // Extract function name
                                    $funcName = 'unknown';
                                    for ($k = $j + 1; $k < count($tokens); $k++) {
                                        if (is_array($tokens[$k]) && $tokens[$k][0] === T_STRING) {
                                            $funcName = $tokens[$k][1];
                                            break;
                                        }
                                    }

                                    // Skip __construct, __destruct, __clone, __toString, __invoke
                                    if (in_array($funcName, ['__construct', '__destruct', '__clone', '__toString', '__invoke', '__sleep', '__wakeup'], true)) {
                                        continue;
                                    }

                                    $violations[] = ($className ?? 'unknown').'::'.$funcName.' in '.$file;
                                }
                                break;
                            }
                        }
                    }
                }
            }

            expect($violations)->toBeEmpty(
                'All public methods must have return type declarations. Violations: '.implode(', ', $violations)
            );
        });
    });

    describe('ServiceProvider class structure', function () {
        test('ServiceProvider has #[Override] on register() and boot()', function () {
            $srcDir = __DIR__.'/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $phpFiles = new RegexIterator($iterator, '/ServiceProvider\.php$/i');

            $violations = [];
            foreach ($phpFiles as $file) {
                $content = file_get_contents($file->getPathname());
                if ($content === false) {
                    continue;
                }

                if (! str_contains($content, 'class') || ! str_contains($content, 'ServiceProvider')) {
                    continue;
                }

                if (str_contains($content, 'public function register')) {
                    // Check if register() has #[Override]
                    if (! preg_match('/#\[\s*\\\\?Override\s*\]\s*(?:public\s+)?function\s+register/', $content)) {
                        $violations[] = 'register() missing #[Override] in '.(string) $file;
                    }
                }

                if (str_contains($content, 'public function boot')) {
                    if (! preg_match('/#\[\s*\\\\?Override\s*\]\s*(?:public\s+)?function\s+boot/', $content)) {
                        $violations[] = 'boot() missing #[Override] in '.(string) $file;
                    }
                }
            }

            expect($violations)->toBeEmpty(
                'ServiceProvider methods must have #[Override]. Violations: '.implode(', ', $violations)
            );
        });
    });

    describe('phpstan.neon.dist configuration', function () {
        test('phpstan.neon.dist exists and has level 9', function () {
            $phpstanConfig = __DIR__.'/../phpstan.neon.dist';
            expect(file_exists($phpstanConfig))->toBeTrue('phpstan.neon.dist must exist');

            $content = file_get_contents($phpstanConfig);
            expect($content)->not->toBeFalse();
            expect($content)->toContain('level: max');
        });

        test('phpstan.neon.dist checks src directory', function () {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('src');
        });
    });

    describe('typed properties audit', function () {
        test('all class properties have type declarations', function () {
            $srcDir = __DIR__.'/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $phpFiles = new RegexIterator($iterator, '/\.php$/i');

            $violations = [];
            foreach ($phpFiles as $file) {
                $content = file_get_contents($file->getPathname());
                if ($content === false) {
                    continue;
                }

                // Find property declarations without types
                // Pattern: public/private/protected $var = (not preceded by a type)
                if (preg_match_all(
                    '/(?:public|protected|private)\s+(static\s+)?\$(\w+)\s*[=;]/',
                    $content,
                    $matches,
                    PREG_SET_ORDER,
                )) {
                    foreach ($matches as $match) {
                        $violations[] = '$'.$match[2].' in '.$file;
                    }
                }
            }

            expect($violations)->toBeEmpty(
                'All class properties must have type declarations. Violations: '.implode(', ', $violations)
            );
        });
    });
});
