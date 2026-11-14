<?php

namespace App\Services;

use App\Models\Contact;
use App\Services\Scraper\PhoneNormalizationService;

class WhatsAppContactResolver
{
    public function __construct(private PhoneNormalizationService $phoneNormalizer)
    {
    }

    public function resolve(int $tenantId, string $whatsAppId, ?string $profileName = null): Contact
    {
        $whatsAppId = trim($whatsAppId);
        $normalized = $this->phoneNormalizer->normalizeE164($whatsAppId);
        $rawDigits = preg_replace('/[^0-9]/', '', $whatsAppId);

        $phoneVariants = array_values(array_unique(array_filter([
            $whatsAppId,
            $rawDigits,
            $normalized,
        ])));

        // Prefer the canonical record. Older webhook-created duplicates may still
        // have the raw WhatsApp ID in `phone` and no normalized value.
        $contact = $normalized
            ? Contact::where('tenant_id', $tenantId)
                ->where('phone_normalized', $normalized)
                ->first()
            : null;

        if (!$contact && $phoneVariants !== []) {
            $contact = Contact::where('tenant_id', $tenantId)
                ->whereIn('phone', $phoneVariants)
                ->first();
        }

        if ($contact) {
            if (!$contact->phone_normalized && $normalized) {
                $contact->update([
                    'phone_normalized' => $normalized,
                    'phone_original' => $contact->phone_original ?: $contact->phone,
                ]);
            }

            return $contact;
        }

        return Contact::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'phone_normalized' => $normalized,
            ],
            [
                'phone' => $normalized ?: $whatsAppId,
                'phone_original' => $whatsAppId,
                'name' => $profileName ?: $whatsAppId,
                'source' => 'WhatsApp',
            ]
        );
    }

    public function hasPlaceholderName(Contact $contact, string $whatsAppId): bool
    {
        $name = trim((string) $contact->name);
        $normalized = $this->phoneNormalizer->normalizeE164($whatsAppId);
        $nameDigits = preg_replace('/[^0-9]/', '', $name);
        $whatsAppDigits = preg_replace('/[^0-9]/', '', $whatsAppId);

        return $name === ''
            || $name === $contact->phone
            || ($normalized && $name === $normalized)
            || ($nameDigits !== '' && $nameDigits === $whatsAppDigits);
    }
}
