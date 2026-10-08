<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingKnowledge extends Model
{
    protected $table = 'marketing_knowledge';

    const MODE_MANUAL = 'manual';
    const MODE_APPROVAL = 'approval';
    const MODE_AUTONOMOUS = 'autonomous';

    protected $fillable = [
        'tenant_id',
        'company_name',
        'company_description',
        'brand_voice',
        'website',
        'demo_link',
        'booking_link',
        'contact_email',
        'contact_phone',
        'pricing_overview',
        'case_studies',
        'target_industries',
        'approval_mode',
        'max_emails_per_campaign',
        'max_emails_per_day',
        'min_hours_between_campaigns',
        'cooldown_days_per_contact',
        'require_approval_above_recipients',
        'allowed_categories',
        'allowed_services',
        'allowed_sending_domain_ids',
    ];

    protected $casts = [
        'case_studies' => 'array',
        'target_industries' => 'array',
        'allowed_categories' => 'array',
        'allowed_services' => 'array',
        'allowed_sending_domain_ids' => 'array',
        'max_emails_per_campaign' => 'integer',
        'max_emails_per_day' => 'integer',
        'min_hours_between_campaigns' => 'integer',
        'cooldown_days_per_contact' => 'integer',
        'require_approval_above_recipients' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get or create knowledge settings for a tenant with sensible defaults
     */
    public static function getOrCreateForTenant(int $tenantId): self
    {
        $existing = self::where('tenant_id', $tenantId)->first();
        if ($existing) {
            return $existing;
        }

        $tenant = Tenant::find($tenantId);
        $companyName = $tenant?->company_name ?: $tenant?->name ?: 'Our Company';

        return self::create([
            'tenant_id' => $tenantId,
            'company_name' => $companyName,
            'company_description' => 'A leading provider of cutting-edge business automation, AI, and CRM solutions.',
            'brand_voice' => 'Professional, authoritative, consultative, and outcome-oriented',
            'website' => 'https://example.com',
            'demo_link' => 'https://calendly.com/demo',
            'booking_link' => 'https://calendly.com/consultation',
            'contact_email' => 'contact@' . ($tenant?->domain ?? 'example.com'),
            'contact_phone' => '+1 (555) 019-2834',
            'pricing_overview' => 'Tailored enterprise and growth plans starting with flexible monthly tiers.',
            'case_studies' => [
                'Helped a leading regional agency increase qualified lead capture by 340% within 30 days.',
                'Automated 80%+ of incoming inquiries for customer service teams without increasing headcount.'
            ],
            'target_industries' => [
                'Real Estate',
                'Dental Clinics',
                'Automotive',
                'Software & Technology',
                'Legal & Professional Services',
                'Healthcare & Clinics'
            ],
            'approval_mode' => self::MODE_APPROVAL,
            'max_emails_per_campaign' => 500,
            'max_emails_per_day' => 1000,
            'min_hours_between_campaigns' => 24,
            'cooldown_days_per_contact' => 7,
            'require_approval_above_recipients' => 250,
            'allowed_categories' => [],
            'allowed_services' => [],
            'allowed_sending_domain_ids' => [],
        ]);
    }
}
