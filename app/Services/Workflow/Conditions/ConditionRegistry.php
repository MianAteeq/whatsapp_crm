<?php

declare(strict_types=1);

namespace App\Services\Workflow\Conditions;

use InvalidArgumentException;

class ConditionRegistry
{
    protected array $handlers = [];

    /**
     * Register a new condition handler type.
     */
    public function register(string $type, string $handlerClass): void
    {
        $this->handlers[$type] = $handlerClass;
    }

    /**
     * Resolve a condition handler by type.
     */
    public function make(string $type): ConditionHandlerInterface
    {
        if (!isset($this->handlers[$type])) {
            throw new InvalidArgumentException("Workflow Condition type [{$type}] is not registered.");
        }

        return app($this->handlers[$type]);
    }

    /**
     * Check if a condition type is registered.
     */
    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }
}
