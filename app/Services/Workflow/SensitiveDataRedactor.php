<?php

declare(strict_types=1);

namespace App\Services\Workflow;

final class SensitiveDataRedactor
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'api_key',
        'apikey', 'secret', 'authorization', 'cookie', 'credit_card', 'card_number', 'cvv', 'cvc',
    ];

    public static function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower(str_replace(['-', ' '], '_', (string) $key));
            $redacted[$key] = self::isSensitive($normalized) ? '[REDACTED]' : self::redact($item);
        }

        return $redacted;
    }

    private static function isSensitive(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($key === $sensitive || str_ends_with($key, '_'.$sensitive)) {
                return true;
            }
        }
        return false;
    }
}
