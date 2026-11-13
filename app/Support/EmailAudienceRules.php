<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class EmailAudienceRules
{
    public static function forTenant(int $tenantId, string $prefix = ''): array
    {
        $rules = [
            'audience_type' => ['nullable', Rule::in(['all', 'selected', 'filtered'])],
            'contact_ids' => ['required_if:'.$prefix.'audience_type,selected', 'array', 'max:10000'],
            'contact_ids.*' => ['integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)],
            'category_ids' => ['nullable', 'array', 'max:100'],
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)],
            'tag_ids' => ['nullable', 'array', 'max:100'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')],
            'email_history' => ['nullable', Rule::in(['all', 'never_sent', 'previously_sent', 'sent', 'delivered', 'bounced'])],
            'lead_quality_grades' => ['nullable', 'array', 'max:4'],
            'lead_quality_grades.*' => [Rule::in(['A', 'B', 'C', 'D']), 'distinct'],
            'min_lead_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'source' => 'nullable|string|max:191',
            'industry' => 'nullable|string|max:191',
            'location' => 'nullable|string|max:191',
            'job_title' => 'nullable|string|max:191',
            'status' => 'nullable|string|max:50',
            'website_available' => 'nullable|boolean',
            'has_email' => 'nullable|boolean',
            'has_phone' => 'nullable|boolean',
            'lead_quality' => 'nullable', // Existing API alias, normalized by the service.
        ];
        $prefixed = [];
        foreach ($rules as $key => $rule) {
            $prefixed[$prefix.$key] = $rule;
        }

        return $prefixed;
    }
}
