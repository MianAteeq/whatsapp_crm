<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use InvalidArgumentException;

class ActionRegistry
{
    protected array $handlers = [];

    /**
     * Register a new action handler type.
     */
    public function register(string $type, string $handlerClass): void
    {
        $this->handlers[$type] = $handlerClass;
    }

    /**
     * Resolve an action handler by type.
     */
    public function make(string $type): ActionHandlerInterface
    {
        if (!isset($this->handlers[$type])) {
            throw new InvalidArgumentException("Workflow Action type [{$type}] is not registered.");
        }

        return app($this->handlers[$type]);
    }

    /**
     * Check if an action type is registered.
     */
    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }
}
