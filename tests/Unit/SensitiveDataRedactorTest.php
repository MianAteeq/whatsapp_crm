<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Workflow\SensitiveDataRedactor;
use PHPUnit\Framework\TestCase;

class SensitiveDataRedactorTest extends TestCase
{
    public function test_it_redacts_nested_credentials_without_mutating_safe_values(): void
    {
        $result = SensitiveDataRedactor::redact([
            'email' => 'person@example.test',
            'authorization' => 'Bearer secret',
            'provider' => ['api_key' => 'abc123', 'region' => 'eu'],
            'metadata' => ['customer_token' => 'hidden', 'status' => 'sent'],
        ]);

        self::assertSame('person@example.test', $result['email']);
        self::assertSame('[REDACTED]', $result['authorization']);
        self::assertSame('[REDACTED]', $result['provider']['api_key']);
        self::assertSame('[REDACTED]', $result['metadata']['customer_token']);
        self::assertSame('sent', $result['metadata']['status']);
    }
}
