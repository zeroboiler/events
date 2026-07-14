<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use RuntimeException;

/**
 * Thrown when one or more triggers fail during {@see EventManager::fire()}.
 *
 * Individual trigger failures are collected so the caller has full
 * visibility, while the dispatch loop continues for remaining triggers.
 */
class TriggerDispatchException extends RuntimeException
{
    /**
     * @param  string  $message  Summary message
     * @param  array<int, string>  $errors  Per-trigger error descriptions
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
