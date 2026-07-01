<?php

declare(strict_types=1);

namespace App\Services\Workflow\Triggers;

use InvalidArgumentException;

class TriggerRegistry
{
    protected array $handlers = [];

    /**
     * Register a new trigger handler type.
     */
    public function register(string $type, string $handlerClass): void
    {
        $this->handlers[$type] = $handlerClass;
    }

    /**
     * Resolve a trigger handler by type.
     */
    public function make(string $type): TriggerHandlerInterface
    {
        if (!isset($this->handlers[$type])) {
            throw new InvalidArgumentException("Workflow Trigger type [{$type}] is not registered.");
        }

        return app($this->handlers[$type]);
    }

    /**
     * Check if a trigger type is registered.
     */
    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * Get all registered trigger types.
     */
    public function all(): array
    {
        return $this->handlers;
    }
}
