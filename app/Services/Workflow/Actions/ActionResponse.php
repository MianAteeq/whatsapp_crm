<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use Carbon\Carbon;

class ActionResponse
{
    public string $status; // success, wait, failed
    public string $message;
    public ?Carbon $resumeTime;
    public array $variables;
    public ?string $error;

    public function __construct(
        string $status,
        string $message = '',
        ?Carbon $resumeTime = null,
        array $variables = [],
        ?string $error = null
    ) {
        $this->status = $status;
        $this->message = $message;
        $this->resumeTime = $resumeTime;
        $this->variables = $variables;
        $this->error = $error;
    }

    public static function success(string $message = '', array $variables = []): self
    {
        return new self('success', $message, null, $variables);
    }

    public static function wait(Carbon $resumeTime, string $message = ''): self
    {
        return new self('wait', $message, $resumeTime);
    }

    public static function failed(string $error, string $message = ''): self
    {
        return new self('failed', $message, null, [], $error);
    }
}
