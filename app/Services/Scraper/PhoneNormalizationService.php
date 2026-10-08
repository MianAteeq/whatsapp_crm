<?php

declare(strict_types=1);

namespace App\Services\Scraper;

class PhoneNormalizationService
{
    /**
     * Map of common country/city keywords to calling codes
     */
    protected array $countryMap = [
        'pakistan' => '92',
        'lahore' => '92',
        'karachi' => '92',
        'islamabad' => '92',
        'rawalpindi' => '92',
        'faisalabad' => '92',
        'multan' => '92',
        'united kingdom' => '44',
        'uk' => '44',
        'great britain' => '44',
        'england' => '44',
        'london' => '44',
        'manchester' => '44',
        'birmingham' => '44',
        'leeds' => '44',
        'scotland' => '44',
        'wales' => '44',
        'united states' => '1',
        'usa' => '1',
        'us' => '1',
        'new york' => '1',
        'california' => '1',
        'texas' => '1',
        'florida' => '1',
        'canada' => '1',
        'toronto' => '1',
        'vancouver' => '1',
        'montreal' => '1',
        'united arab emirates' => '971',
        'uae' => '971',
        'dubai' => '971',
        'abu dhabi' => '971',
        'sharjah' => '971',
        'australia' => '61',
        'sydney' => '61',
        'melbourne' => '61',
        'brisbane' => '61',
        'saudi arabia' => '966',
        'ksa' => '966',
        'riyadh' => '966',
        'jeddah' => '966',
        'india' => '91',
        'delhi' => '91',
        'mumbai' => '91',
        'bangalore' => '91',
        'germany' => '49',
        'berlin' => '49',
        'munich' => '49',
        'france' => '33',
        'paris' => '33',
        'spain' => '34',
        'madrid' => '34',
        'italy' => '39',
        'rome' => '39',
        'qatar' => '974',
        'doha' => '974',
        'oman' => '968',
        'muscat' => '968',
        'kuwait' => '965',
        'bahrain' => '973',
        'malaysia' => '60',
        'kuala lumpur' => '60',
        'singapore' => '65',
    ];

    /**
     * Normalize a phone number to standard E.164 (+<country_code><number>)
     *
     * @param string|null $rawPhone
     * @param string|null $locationContext e.g. "Lahore, Pakistan", "London, UK"
     * @return string|null
     */
    public function normalize(?string $rawPhone, ?string $locationContext = null): ?string
    {
        return $this->normalizeE164($rawPhone, $locationContext);
    }

    /**
     * E.164 normalization implementation
     */
    public function normalizeE164(?string $rawPhone, ?string $locationContext = null): ?string
    {
        if ($rawPhone === null) {
            return null;
        }

        $trimmed = trim($rawPhone);
        if ($trimmed === '') {
            return null;
        }

        // Detect if explicit international prefix already present
        $hasPlus = str_starts_with($trimmed, '+');
        $has00 = str_starts_with($trimmed, '00');

        // Extract pure digits
        $digits = preg_replace('/[^0-9]/', '', $trimmed);
        if ($digits === '' || strlen($digits) < 7) {
            return null;
        }

        // Case 1: Started with '+' -> treat digits as international number
        if ($hasPlus) {
            return '+' . $digits;
        }

        // Case 2: Started with '00' -> international dial prefix
        if ($has00) {
            $without00 = substr($digits, 2);
            return '+' . $without00;
        }

        // Infer default country code from location context
        $defaultCountryCode = $this->detectCountryCode($locationContext);

        if ($defaultCountryCode) {
            // Check if already begins with the country code
            if (str_starts_with($digits, $defaultCountryCode) && strlen($digits) >= (strlen($defaultCountryCode) + 8)) {
                return '+' . $digits;
            }

            // Local trunk prefix handling (leading '0')
            if (str_starts_with($digits, '0')) {
                return '+' . $defaultCountryCode . substr($digits, 1);
            }

            // In Pakistan, mobile numbers without leading 0 (e.g., 3001234567, 10 digits)
            if ($defaultCountryCode === '92' && strlen($digits) === 10 && str_starts_with($digits, '3')) {
                return '+' . $defaultCountryCode . $digits;
            }

            // In US/Canada, standard 10-digit numbers
            if ($defaultCountryCode === '1' && strlen($digits) === 10) {
                return '+' . $defaultCountryCode . $digits;
            }

            // General fallback with country code
            return '+' . $defaultCountryCode . $digits;
        }

        // If no country context available:
        // If 11 digits starting with 0, or length >= 10, prefix +
        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            // Without country context, assume default +92 if starts with 03 (common in Pakistan)
            if (str_starts_with($digits, '03') && strlen($digits) === 11) {
                return '+92' . substr($digits, 1);
            }
            return '+' . substr($digits, 1);
        }

        return '+' . $digits;
    }

    /**
     * Detect country calling code from location string
     */
    public function detectCountryCode(?string $locationContext): ?string
    {
        if (empty($locationContext)) {
            return null;
        }

        $lower = strtolower($locationContext);

        foreach ($this->countryMap as $key => $code) {
            if (str_contains($lower, $key)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Check if two phone strings represent the identical phone number
     */
    public function areEquivalent(?string $phoneA, ?string $phoneB, ?string $locationContext = null): bool
    {
        if (empty($phoneA) || empty($phoneB)) {
            return false;
        }

        $normA = $this->normalize($phoneA, $locationContext);
        $normB = $this->normalize($phoneB, $locationContext);

        if ($normA && $normB) {
            return $normA === $normB;
        }

        // Fallback: compare raw stripped digits
        $digitsA = preg_replace('/[^0-9]/', '', $phoneA);
        $digitsB = preg_replace('/[^0-9]/', '', $phoneB);

        return !empty($digitsA) && $digitsA === $digitsB;
    }

    /**
     * Format an E.164 phone number nicely for UI display
     */
    public function formatDisplay(?string $e164): string
    {
        if (empty($e164)) {
            return '';
        }

        if (!str_starts_with($e164, '+')) {
            return $e164;
        }

        // Pakistan: +92 300 1234567
        if (str_starts_with($e164, '+92') && strlen($e164) === 13) {
            return '+92 ' . substr($e164, 3, 3) . ' ' . substr($e164, 6);
        }

        // UK: +44 20 7946 0999
        if (str_starts_with($e164, '+44') && strlen($e164) >= 12) {
            return '+44 ' . substr($e164, 3, 2) . ' ' . substr($e164, 5, 4) . ' ' . substr($e164, 9);
        }

        // US/Canada: +1 (555) 123-4567
        if (str_starts_with($e164, '+1') && strlen($e164) === 12) {
            return '+1 (' . substr($e164, 2, 3) . ') ' . substr($e164, 5, 3) . '-' . substr($e164, 8);
        }

        return $e164;
    }
}
