<?php

namespace Tests\Unit;

use App\Models\WhatsappBillingProfile;
use App\Services\WhatsAppBillingService;
use PHPUnit\Framework\TestCase;

class WhatsAppBillingServiceTest extends TestCase
{
    public function test_successful_messaging_is_active_payment_evidence(): void
    {
        $profile = new WhatsappBillingProfile();
        $profile->setRawAttributes([
            'last_successful_message_at' => '2026-11-14 12:00:00',
        ]);

        $status = (new WhatsAppBillingService())->determinePaymentMethodStatus(
            $profile,
            'SAR',
            null,
            WhatsappBillingProfile::STATUS_OPERATIONAL
        );

        $this->assertSame('active', $status);
    }

    public function test_currency_without_message_history_is_managed_in_meta(): void
    {
        $status = (new WhatsAppBillingService())->determinePaymentMethodStatus(
            new WhatsappBillingProfile(),
            'SAR',
            null,
            WhatsappBillingProfile::STATUS_PENDING_VERIFICATION
        );

        $this->assertSame('managed_in_meta', $status);
    }

    public function test_billing_error_requires_attention(): void
    {
        $profile = new WhatsappBillingProfile();
        $profile->last_error_code = '131042';

        $status = (new WhatsAppBillingService())->determinePaymentMethodStatus(
            $profile,
            'SAR',
            null,
            WhatsappBillingProfile::STATUS_PAYMENT_ATTENTION_REQUIRED
        );

        $this->assertSame('attention_required', $status);
    }
}
