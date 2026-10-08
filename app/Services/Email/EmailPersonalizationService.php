<?php

namespace App\Services\Email;

use App\Models\Contact;

class EmailPersonalizationService
{
    /**
     * Map contact attributes to a structured dictionary
     */
    public static function buildContactVariables(?Contact $contact, ?string $unsubscribeUrl = null): array
    {
        if (!$contact) {
            return [
                'first_name' => '',
                'last_name' => '',
                'name' => '',
                'business_name' => '',
                'company_name' => '',
                'company' => '',
                'email' => '',
                'phone' => '',
                'website' => '',
                'city' => '',
                'industry' => '',
                'unsubscribe_url' => $unsubscribeUrl ?? '#',
            ];
        }

        $rawName = trim($contact->name ?? '');
        
        // Strip common honorific prefixes (Dr., Mr., Mrs., Ms., Prof.) to get natural first name
        $cleanedName = preg_replace('/^(dr\.|dr|mr\.|mr|mrs\.|mrs|ms\.|ms|prof\.|engr\.)\s+/i', '', $rawName);
        $nameParts = preg_split('/\s+/', $cleanedName);
        $firstName = $nameParts[0] ?? '';
        $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

        $company = trim($contact->company ?? '');
        $businessName = !empty($company) ? $company : $rawName;

        // Try to extract city: Priority 1 is source_details['city']
        $city = '';
        if (!empty($contact->source_details['city'])) {
            $city = trim((string)$contact->source_details['city']);
        } elseif (!empty($contact->address)) {
            $addressParts = array_map('trim', explode(',', $contact->address));
            $last = end($addressParts);
            $commonCountries = ['pakistan', 'usa', 'united states', 'uk', 'uae', 'canada', 'australia', 'india'];
            if (in_array(strtolower($last), $commonCountries) && count($addressParts) > 1) {
                $city = $addressParts[count($addressParts) - 2];
            } else {
                $city = $last;
            }
        }

        // Industry
        $industry = $contact->job_title ?? '';
        if (empty($industry) && !empty($contact->source_details['category'])) {
            $industry = (string)$contact->source_details['category'];
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $rawName,
            'business_name' => $businessName,
            'company_name' => $company ?: $businessName,
            'company' => $company,
            'email' => trim($contact->email ?? ''),
            'phone' => trim($contact->phone ?? ''),
            'website' => trim($contact->website ?? ''),
            'city' => $city,
            'industry' => $industry,
            'unsubscribe_url' => $unsubscribeUrl ?? '#',
        ];
    }

    /**
     * Render template string replacing {{variable}} and {{variable | default: "fallback"}}
     */
    public static function render(?string $template, array $variables): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        // Pattern handles:
        // {{field}}
        // {{field | default: "fallback"}}
        // {{field | default: 'fallback'}}
        // {{field | "fallback"}}
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\-\.]+)(?:\s*\|\s*(?:default:\s*)?["\']([^"\']*)["\'])?\s*\}\}/i', function ($matches) use ($variables) {
            $key = strtolower(trim($matches[1]));
            $fallback = $matches[2] ?? null;

            if (isset($variables[$key]) && trim((string)$variables[$key]) !== '') {
                return (string)$variables[$key];
            }

            if ($fallback !== null) {
                return $fallback;
            }

            // Sensible default fallbacks if neither variable nor custom default is provided
            return match ($key) {
                'first_name', 'name' => 'there',
                'business_name', 'company_name', 'company' => 'your business',
                default => '',
            };
        }, $template);
    }
}
