<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketingService extends Model
{
    protected $table = 'marketing_services';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'target_industries',
        'features',
        'benefits',
        'pricing',
        'cta_text',
        'cta_url',
        'is_active',
    ];

    protected $casts = [
        'target_industries' => 'array',
        'features' => 'array',
        'benefits' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (MarketingService $service) {
            if (empty($service->slug)) {
                $service->slug = Str::slug($service->name);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Seed or provide standard initial services for tenant if none exist
     */
    public static function seedDefaultServicesForTenant(int $tenantId): void
    {
        if (self::where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $defaults = [
            [
                'name' => 'AI Chatbot',
                'description' => 'Autonomous 24/7 conversational AI assistant designed to engage website visitors, answer common questions, and capture qualified leads instantly.',
                'target_industries' => ['Real Estate', 'Dental', 'Automotive', 'E-commerce', 'Legal', 'Healthcare'],
                'features' => [
                    '24/7 instant response across web and mobile',
                    'Intelligent lead qualification & question branching',
                    'Direct CRM & appointment calendar sync',
                    'Multi-language conversational support',
                    'Seamless handover to human team when requested'
                ],
                'benefits' => [
                    'Eliminate response delays and capture after-hours leads',
                    'Cut front-desk inquiry response time by up to 90%',
                    'Qualify prospects automatically before your team calls them',
                    'Boost website visitor conversion rate by 3x'
                ],
                'pricing' => 'Growth plan starting from $199/month with unlimited chats',
                'cta_text' => 'Book a Demo',
                'cta_url' => 'https://example.com/demo',
                'is_active' => true,
            ],
            [
                'name' => 'AI Receptionist',
                'description' => 'Smart automated reception system handling incoming calls, booking patient/client appointments, and managing front-office communication.',
                'target_industries' => ['Dental Clinics', 'Medical Practices', 'Automotive Dealerships', 'Law Firms', 'Spas & Wellness'],
                'features' => [
                    'Automated appointment scheduling and calendar management',
                    'FAQ and business hours answering',
                    'Automated SMS/Email confirmation and reminders',
                    'Emergency inquiry escalation'
                ],
                'benefits' => [
                    'Never miss a phone call or booking inquiry',
                    'Reduce front desk workload so staff focus on in-person clients',
                    'Decrease appointment no-show rates by 45%'
                ],
                'pricing' => 'Starting from $249/month',
                'cta_text' => 'Schedule a Consultation',
                'cta_url' => 'https://example.com/consultation',
                'is_active' => true,
            ],
            [
                'name' => 'WhatsApp Automation',
                'description' => 'Complete WhatsApp Business marketing, drip campaigns, catalog integration, and automated customer follow-ups.',
                'target_industries' => ['Real Estate', 'Retail & E-commerce', 'Dealerships', 'Travel Agencies', 'Clinics'],
                'features' => [
                    'Broadcast promotional campaigns with verified templates',
                    'Automated two-way conversational flows',
                    'Interactive quick-reply buttons and catalog links',
                    'Real-time delivery, read, and reply analytics'
                ],
                'benefits' => [
                    'Reach customers where they have 98% open rates',
                    'Drive instant replies compared to traditional SMS or cold calls',
                    'Automate repetitive lead re-engagement'
                ],
                'pricing' => 'Starting from $149/month + message units',
                'cta_text' => 'See Live Interactive Demo',
                'cta_url' => 'https://example.com/whatsapp-demo',
                'is_active' => true,
            ],
            [
                'name' => 'CRM & Outbound Growth Suite',
                'description' => 'Unified multi-channel CRM, automated email marketing, lead scraper, and customer pipeline management.',
                'target_industries' => ['Software & SaaS', 'B2B Services', 'Agencies', 'Brokers', 'Contractors'],
                'features' => [
                    'Automated email warming & verified domain delivery',
                    'Lead enrichment and quality grading (A, B, C)',
                    'Integrated pipeline tracking and deal stages',
                    'Dynamic merge tag personalization'
                ],
                'benefits' => [
                    'Consolidate leads, emails, and follow-ups in one system',
                    'Ensure 99%+ deliverability through verified Mailtrap pipelines',
                    'Accelerate sales velocity with automated reminders'
                ],
                'pricing' => 'Custom growth tier starting from $299/month',
                'cta_text' => 'Claim Free 14-Day Trial',
                'cta_url' => 'https://example.com/trial',
                'is_active' => true,
            ]
        ];

        foreach ($defaults as $svc) {
            self::create(array_merge($svc, [
                'tenant_id' => $tenantId,
                'slug' => Str::slug($svc['name']),
            ]));
        }
    }
}
