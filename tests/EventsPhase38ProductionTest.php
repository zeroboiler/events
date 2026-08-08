<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;

describe('Phase 38 — Production', function (): void {
    describe('README table format', function (): void {
        it('README Test Coverage table rows start with single pipe, not double pipe', function (): void {
            $readme = file_get_contents(__DIR__.'/../README.md');
            expect($readme)->not->toBeFalse();

            // Find table rows starting with || (malformed)
            // Phase rows have pattern: | Phase N production ...
            // Malformed rows have: || Phase N production ...
            $lines = explode("\n", $readme);
            $malformed = [];
            foreach ($lines as $i => $line) {
                if (preg_match('/^\|\| Phase \d+ production/', $line)) {
                    $malformed[] = $line + 1;
                }
            }

            expect($malformed)->toBeEmpty('README has malformed table rows at line(s): '.implode(', ', $malformed));
        });

        it('README version badge matches composer.json version', function (): void {
            $composerJson = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $readme = file_get_contents(__DIR__.'/../README.md');

            expect($composerJson)->toBeArray();
            expect($readme)->not->toBeFalse();

            $version = $composerJson['version'];
            expect($readme)->toContain("version-{$version}-blue");
        });
    });

    describe('TriggerBuilder save @throws docblock', function (): void {
        it('save() method has @throws InvalidArgumentException in docblock', function (): void {
            $reflection = new ReflectionMethod(TriggerBuilder::class, 'save');
            $doc = $reflection->getDocComment();

            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@throws');
            expect($doc)->toContain('InvalidArgumentException');
        });
    });

    describe('SubscriptionBuilder save @throws docblock', function (): void {
        it('save() method has @throws InvalidArgumentException in docblock', function (): void {
            $reflection = new ReflectionMethod(SubscriptionBuilder::class, 'save');
            $doc = $reflection->getDocComment();

            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@throws');
            expect($doc)->toContain('InvalidArgumentException');
        });
    });

    describe('DispatchTriggerJob backoff typed docblock', function (): void {
        it('$backoff property has list<int> typed docblock', function (): void {
            $reflection = new ReflectionProperty(DispatchTriggerJob::class, 'backoff');
            $doc = $reflection->getDocComment();

            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@var');
            expect($doc)->toContain('list<int>');
        });

        it('$backoff property is public array', function (): void {
            $reflection = new ReflectionProperty(DispatchTriggerJob::class, 'backoff');

            expect($reflection->isPublic())->toBeTrue();
            expect($reflection->getType()?->getName())->toBe('array');
        });

        it('$backoff default has 3 elements', function (): void {
            $reflection = new ReflectionProperty(DispatchTriggerJob::class, 'backoff');
            $reflection->setAccessible(true);

            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            $backoff = $reflection->getValue($job);

            expect($backoff)->toBeArray();
            expect(count($backoff))->toBe(3);
        });
    });

    describe('Version consistency', function (): void {
        it('composer.json version is valid semver format', function (): void {
            $composerJson = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composerJson)->toBeArray();
            expect($composerJson['version'])->toMatch('/^\d+\.\d+\.\d+$/');
        });

        it('composer.json version matches README badge', function (): void {
            $composerJson = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $readme = file_get_contents(__DIR__.'/../README.md');

            $version = $composerJson['version'];
            expect($readme)->toContain("version-{$version}-blue");
        });
    });
});
