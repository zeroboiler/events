<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Domain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when one or more event listeners fail during dispatch.
 *
 * Wraps the original exceptions so callers can inspect individual
 * failures while still being notified that something went wrong.
 */
final class ListenerException extends RuntimeException
{
    /** @var array<int, array{listener: callable, throwable: Throwable}> */
    private array $failures = [];

    /**
     * @param  array<int, array{listener: callable, throwable: Throwable}>  $failures
     */
    public static function withFailures(array $failures): self
    {
        $count = count($failures);
        $first = $failures[0]['throwable'] ?? null;

        $instance = new self(
            sprintf('%d listener(s) failed during event dispatch.', $count),
            0,
            $first,
        );

        $instance->failures = $failures;

        return $instance;
    }

    /**
     * @return array<int, array{listener: callable, throwable: Throwable}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @return list<Throwable>
     */
    public function throwables(): array
    {
        return array_map(fn (array $f): Throwable => $f['throwable'], $this->failures);
    }
}
