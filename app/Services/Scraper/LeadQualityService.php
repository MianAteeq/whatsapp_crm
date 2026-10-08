<?php

namespace App\Services\Scraper;

class LeadQualityService
{
    /**
     * Default scoring weights (configurable)
     */
    protected array $weights = [
        'phone' => 25,
        'email' => 25,
        'website' => 15,
        'name' => 10,
        'address' => 10,
        'category' => 5,
        'google_maps' => 5,
        'additional' => 5,
    ];

    /**
     * Grade thresholds
     */
    protected array $gradeThresholds = [
        'A' => 80,
        'B' => 60,
        'C' => 40,
        'D' => 0,
    ];

    public function __construct(array $customWeights = [])
    {
        if (!empty($customWeights)) {
            $this->weights = array_merge($this->weights, $customWeights);
        }
    }

    /**
     * Calculate quality score, grade, missing fields, and enrichment status
     * Accepts Contact or ScrapedBusiness model or array
     *
     * @param mixed $record
     * @return array
     */
    public function calculate($record): array
    {
        $data = is_array($record) ? $record : (is_object($record) ? $record->toArray() : []);

        $phone = !empty($data['phone_normalized']) ? $data['phone_normalized'] : (!empty($data['phone']) ? $data['phone'] : null);
        $email = !empty($data['email_normalized']) ? $data['email_normalized'] : (!empty($data['email']) ? $data['email'] : null);
        $website = !empty($data['website']) ? $data['website'] : null;
        $name = !empty($data['business_name']) ? $data['business_name'] : (!empty($data['name']) ? $data['name'] : null);
        $address = !empty($data['address']) ? $data['address'] : null;
        $category = !empty($data['category']) ? $data['category'] : null;
        $googleMaps = !empty($data['google_place_id']) || !empty($data['google_maps_url']);
        $additional = (!empty($data['rating']) && $data['rating'] > 0)
            || (!empty($data['review_count']) && $data['review_count'] > 0)
            || !empty($data['secondary_phone'])
            || !empty($data['secondary_email'])
            || !empty($data['social_profiles']);

        $score = 0;
        $missingFields = [];

        // 1. Phone (+25)
        if (!empty($phone)) {
            $score += $this->weights['phone'];
        } else {
            $missingFields[] = 'phone';
        }

        // 2. Email (+25)
        if (!empty($email)) {
            $score += $this->weights['email'];
        } else {
            $missingFields[] = 'email';
        }

        // 3. Website (+15)
        if (!empty($website)) {
            $score += $this->weights['website'];
        } else {
            $missingFields[] = 'website';
        }

        // 4. Business Name (+10)
        if (!empty($name) && strtolower(trim($name)) !== 'unknown business') {
            $score += $this->weights['name'];
        } else {
            $missingFields[] = 'name';
        }

        // 5. Address (+10)
        if (!empty($address)) {
            $score += $this->weights['address'];
        } else {
            $missingFields[] = 'address';
        }

        // 6. Category (+5)
        if (!empty($category)) {
            $score += $this->weights['category'];
        } else {
            $missingFields[] = 'category';
        }

        // 7. Google Maps / Place (+5)
        if ($googleMaps) {
            $score += $this->weights['google_maps'];
        } else {
            $missingFields[] = 'google_maps';
        }

        // 8. Additional Info (+5)
        if ($additional) {
            $score += $this->weights['additional'];
        }

        // Cap at 100
        $score = min(100, max(0, $score));

        // Determine Grade
        $grade = 'D';
        foreach ($this->gradeThresholds as $g => $minScore) {
            if ($score >= $minScore) {
                $grade = $g;
                break;
            }
        }

        // Determine Enrichment Status
        $status = $this->determineStatus($phone, $email, $website, $name, $address);

        return [
            'score' => $score,
            'grade' => $grade,
            'missing_fields' => $missingFields,
            'enrichment_status' => $status,
        ];
    }

    /**
     * Determine enrichment status based on field presence
     */
    protected function determineStatus(?string $phone, ?string $email, ?string $website, ?string $name, ?string $address): string
    {
        if (empty($phone)) {
            return 'NO_PHONE';
        }

        $hasCore = !empty($name) && !empty($phone);
        $hasEmail = !empty($email);
        $hasWebsite = !empty($website);
        $hasAddress = !empty($address);

        if ($hasCore && $hasEmail && $hasWebsite && $hasAddress) {
            return 'ENRICHED';
        }

        if ($hasCore && $hasEmail && $hasWebsite) {
            return 'ENRICHED';
        }

        if ($hasCore && !$hasEmail && !$hasWebsite) {
            return 'NO_EMAIL';
        }

        if ($hasCore && !$hasEmail) {
            return 'NO_EMAIL';
        }

        if ($hasCore && !$hasWebsite) {
            return 'NO_WEBSITE';
        }

        return 'PARTIAL';
    }
}
