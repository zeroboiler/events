<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

/**
 * Validates that all PHP source files in the package have balanced
 * parentheses, brackets, and braces.
 *
 * This test catches syntax errors (missing parentheses, brackets, etc.)
 * that would prevent the code from loading at all.
 */
test('all src/ PHP files have balanced delimiters', function (): void {
    $srcDir = __DIR__ . '/../src';
    $errors = checkPhpDelimiters($srcDir);

    expect($errors)->toBeEmpty('Syntax issues found: ' . implode('; ', $errors));
});

test('all migration PHP files have balanced delimiters', function (): void {
    $migrationDir = __DIR__ . '/../database/migrations';
    $errors = checkPhpDelimiters($migrationDir);

    expect($errors)->toBeEmpty('Migration syntax issues: ' . implode('; ', $errors));
});

test('all factory PHP files have balanced delimiters', function (): void {
    $factoryDir = __DIR__ . '/../database/factories';
    $errors = checkPhpDelimiters($factoryDir);

    expect($errors)->toBeEmpty('Factory syntax issues: ' . implode('; ', $errors));
});

/**
 * Check all .php files in a directory for balanced delimiters.
 *
 * @return list<string> Error messages (empty if no issues)
 */
function checkPhpDelimiters(string $directory): array
{
    $errors = [];
    $openers = ['(' => ')', '[' => ']', '{' => '}'];
    $closers = [')' => '(', ']' => '[', '}' => '{'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $content = (string) file_get_contents($path);
        $tokens = token_get_all($content);

        $stack = [];
        $line = 1;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                // Track line numbers from token content
                $line += substr_count((string) $token[1], "\n");
                continue;
            }

            // $token is a single-character string delimiter
            if (isset($openers[$token])) {
                $stack[] = ['char' => $token, 'expected' => $openers[$token], 'line' => $line];
            } elseif (isset($closers[$token])) {
                $expectedOpener = $closers[$token];

                if (empty($stack)) {
                    $errors[] = "Unexpected '{$token}' at {$path}:{$line}";
                } elseif ($stack[array_key_last($stack)]['char'] !== $expectedOpener) {
                    $last = $stack[array_key_last($stack)];
                    $errors[] = "Mismatched '{$token}' at {$path}:{$line} (opened '{$last['char']}' at line {$last['line']})";
                } else {
                    array_pop($stack);
                }
            }
        }

        foreach ($stack as $unclosed) {
            $errors[] = "Unclosed '{$unclosed['char']}' at {$path}:{$unclosed['line']}";
        }
    }

    return $errors;
}
