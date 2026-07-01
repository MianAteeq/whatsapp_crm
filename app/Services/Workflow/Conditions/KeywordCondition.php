<?php

declare(strict_types=1);

namespace App\Services\Workflow\Conditions;

use App\Services\Workflow\WorkflowContext;

class KeywordCondition implements ConditionHandlerInterface
{
    public function evaluate(WorkflowContext $context, array $config): bool
{
    $message = $context->getMessage();

    if (!$message) {
        \Log::warning('[KeywordCondition] No message found');
        return false;
    }

    $text = strtolower(trim(
        data_get($message, 'body')
        ?? data_get($message, 'message')
        ?? data_get($message, 'content')
        ?? ''
    ));

    $operator = strtolower($config['operator'] ?? 'contains');

    $keywords = [];

    if (!empty($config['value'])) {
        $keywords = array_map('trim', explode(',', $config['value']));
    } elseif (!empty($config['keywords'])) {
        $keywords = is_array($config['keywords'])
            ? $config['keywords']
            : array_map('trim', explode(',', $config['keywords']));
    }

    \Log::info('[KeywordCondition] Evaluate', [
        'incoming' => $text,
        'operator' => $operator,
        'keywords' => $keywords,
        'config' => $config,
    ]);

    foreach ($keywords as $keyword) {

        $keyword = strtolower(trim($keyword));

        if ($operator === 'contains' && str_contains($text, $keyword)) {
            \Log::info('[KeywordCondition] MATCHED', [
                'keyword' => $keyword,
            ]);

            return true;
        }

        if ($operator === 'equals' && $text === $keyword) {
            \Log::info('[KeywordCondition] MATCHED', [
                'keyword' => $keyword,
            ]);

            return true;
        }

        if ($operator === 'starts_with' && str_starts_with($text, $keyword)) {
            \Log::info('[KeywordCondition] MATCHED', [
                'keyword' => $keyword,
            ]);

            return true;
        }
    }

    \Log::info('[KeywordCondition] NO MATCH');

    return false;
}
}
