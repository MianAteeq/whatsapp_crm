<?php

declare(strict_types=1);

namespace App\Services\Workflow\Triggers;

use App\Services\Workflow\WorkflowContext;

class KeywordTrigger implements TriggerHandlerInterface
{
    public function matches(WorkflowContext $context, array $config): bool
    {
        $message = $context->getMessage();
        if (!$message) {
            return false;
        }

        $text = strtolower(trim($message->message ?? ''));
        $keywords = $config['keywords'] ?? ($config['keyword'] ?? []);
        if (is_string($keywords)) {
            $keywords = array_map('trim', explode(',', $keywords));
        }

        $operator = $config['operator'] ?? 'contains'; // contains, equals, starts_with

        foreach ($keywords as $kw) {
            $kwNormalized = strtolower(trim($kw));
            if (empty($kwNormalized)) {
                continue;
            }

            if ($operator === 'equals' && $text === $kwNormalized) {
                return true;
            }
            if ($operator === 'starts_with' && str_starts_with($text, $kwNormalized)) {
                return true;
            }
            if ($operator === 'contains' && str_contains($text, $kwNormalized)) {
                return true;
            }
        }

        return false;
    }
}
