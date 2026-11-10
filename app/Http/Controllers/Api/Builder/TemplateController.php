<?php

namespace App\Http\Controllers\Api\Builder;

use App\Http\Controllers\Controller;
use App\Models\BuilderTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TemplateController extends Controller
{
    /**
     * Get pre-built templates by category and type
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $category = $request->query('category');
        $type = $request->query('type'); // page, form, survey

        $templates = BuilderTemplate::availableForTenant($tenantId)
            ->when($category && $category !== 'all', fn($q) => $q->where('category', $category))
            ->when($type && $type !== 'all', fn($q) => $q->where('template_type', $type))
            ->get();

        // If no templates exist in DB yet, provide built-in system templates
        if ($templates->isEmpty()) {
            $templates = $this->getSystemDefaultTemplates($type, $category);
        }

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    /**
     * Built-in template presets with rich turnkey sections and design themes
     */
    protected function getSystemDefaultTemplates(?string $type, ?string $category): array
    {
        $all = [
            [
                'id' => 1,
                'name' => 'SaaS Modern Growth Template',
                'category' => 'saas',
                'template_type' => 'page',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=600&auto=format&fit=crop&q=80',
                'description' => 'High-conversion split hero, logo cloud, feature grid, KPI stats, and tiered pricing.',
                'content' => [
                    'theme' => [
                        'primary_color' => '#166534',
                        'secondary_color' => '#12263A',
                        'background_color' => '#FFFFFF',
                        'text_color' => '#0F172A',
                        'font_family' => 'Inter, sans-serif',
                        'container_width' => '1200px',
                        'border_radius' => '12px',
                        'palette_id' => 'emerald',
                    ],
                    'sections' => [
                        [
                            'id' => 'sec-saas-1',
                            'type' => 'hero',
                            'name' => 'Hero Header (Split)',
                            'visible' => true,
                            'content' => [
                                'layout' => 'split',
                                'badge' => '⚡ NEXT-GEN AUTOMATION PLATFORM',
                                'title' => 'Scale Your Client Pipeline With Autonomous Intelligence',
                                'subtitle' => 'Eliminate manual data entry, streamline multi-channel messaging on WhatsApp and Meta, and convert visitors into high-value sales opportunities.',
                                'cta_text' => 'Start Free 14-Day Trial',
                                'cta_url' => '#pricing',
                                'secondary_cta_text' => 'View Live Demo',
                                'secondary_cta_url' => '#video',
                                'image_url' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=1000&auto=format&fit=crop&q=80',
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#F8FAFC',
                                'padding_top' => 'py-20',
                                'padding_bottom' => 'py-20',
                                'text_align' => 'left',
                            ],
                        ],
                        [
                            'id' => 'sec-saas-2',
                            'type' => 'logo_cloud',
                            'name' => 'Logo Cloud / Social Proof',
                            'visible' => true,
                            'content' => [
                                'title' => 'TRUSTED BY REVENUE TEAMS AT INNOVATIVE COMPANIES',
                                'logos' => [
                                    ['name' => 'Acme Corp', 'icon' => 'Building2'],
                                    ['name' => 'Starlight Media', 'icon' => 'Sparkles'],
                                    ['name' => 'Global Logistics', 'icon' => 'Globe'],
                                    ['name' => 'Apex Analytics', 'icon' => 'Activity'],
                                    ['name' => 'Nova Systems', 'icon' => 'Cpu'],
                                ],
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#FFFFFF',
                                'padding_top' => 'py-10',
                                'padding_bottom' => 'py-10',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-saas-3',
                            'type' => 'stats',
                            'name' => 'KPI & Stats Counters',
                            'visible' => true,
                            'content' => [
                                'title' => 'Proven Enterprise Performance',
                                'subtitle' => 'Real metrics achieved by active teams utilizing our automation engine.',
                                'items' => [
                                    ['value' => '99.98%', 'label' => 'System Uptime SLA'],
                                    ['value' => '4.8x', 'label' => 'Faster Lead Response'],
                                    ['value' => '1.2M+', 'label' => 'Messages Delivered'],
                                    ['value' => '42%', 'label' => 'Average Conversion Lift'],
                                ],
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#0F172A',
                                'padding_top' => 'py-16',
                                'padding_bottom' => 'py-16',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-saas-4',
                            'type' => 'features',
                            'name' => 'Features Grid',
                            'visible' => true,
                            'content' => [
                                'title' => 'Engineered For High-Converting Funnels',
                                'subtitle' => 'Everything your marketing and sales operations need in one cohesive ecosystem.',
                                'items' => [
                                    ['title' => 'Unified Social Inbox', 'desc' => 'Manage WhatsApp Cloud, Instagram Direct, and Facebook Messenger from a synchronized queue.'],
                                    ['title' => 'No-Code Page Builder', 'desc' => 'Deploy lightning-fast landing pages with drag-and-drop sections and instant CRM lead sync.'],
                                    ['title' => 'Triggered Automations', 'desc' => 'Automatically route leads, trigger drip email sequences, and notify sales reps instantly.'],
                                    ['title' => 'Multi-Tenant Isolation', 'desc' => 'Enterprise security boundaries ensuring customer records and tokens remain strictly partitioned.'],
                                    ['title' => 'Meta Pixel & GTM Ready', 'desc' => 'Inject tracking scripts seamlessly with zero custom code deployment required.'],
                                    ['title' => 'Automated Contact Mapping', 'desc' => 'Every form submission normalizes names, phones, and emails with smart deduplication.'],
                                ],
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#FFFFFF',
                                'padding_top' => 'py-16',
                                'padding_bottom' => 'py-16',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-saas-5',
                            'type' => 'pricing',
                            'name' => 'Tiered Pricing Plans',
                            'visible' => true,
                            'content' => [
                                'title' => 'Simple, Transparent Investment',
                                'subtitle' => 'Choose the plan engineered to match your growth phase.',
                                'billing_period' => 'monthly',
                                'items' => [
                                    [
                                        'name' => 'Starter',
                                        'price' => '$49',
                                        'period' => '/mo',
                                        'desc' => 'Ideal for solopreneurs & small agencies getting started.',
                                        'features' => ['Up to 5 Landing Pages', '2,500 CRM Contacts', 'Meta Social Inbox (1 Page)', 'Standard Email Support'],
                                        'cta_text' => 'Get Started Starter',
                                    ],
                                    [
                                        'name' => 'Growth',
                                        'price' => '$99',
                                        'period' => '/mo',
                                        'desc' => 'Our most popular tier for fast-scaling growth operations.',
                                        'popular' => true,
                                        'features' => ['Unlimited Landing Pages & Forms', '25,000 Contacts', 'Full WhatsApp & Meta Integration', 'Automated CRM Lead Routing', 'Custom Head/Body Tracking Scripts', 'Priority SLA Support'],
                                        'cta_text' => 'Start 14-Day Free Trial',
                                    ],
                                    [
                                        'name' => 'Enterprise',
                                        'price' => '$249',
                                        'period' => '/mo',
                                        'desc' => 'Dedicated infrastructure for high-volume revenue organizations.',
                                        'features' => ['Unlimited Everything', 'Dedicated Database Isolation', 'Custom Webhook Integrations', 'Custom Domain SSL', 'Dedicated Account Strategist'],
                                        'cta_text' => 'Contact Enterprise Sales',
                                    ],
                                ],
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#F8FAFC',
                                'padding_top' => 'py-16',
                                'padding_bottom' => 'py-16',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-saas-6',
                            'type' => 'faq',
                            'name' => 'Interactive FAQ Accordion',
                            'visible' => true,
                            'content' => [
                                'title' => 'Frequently Asked Questions',
                                'subtitle' => 'Have questions? We are here to help.',
                                'items' => [
                                    ['q' => 'How does direct CRM lead sync work?', 'a' => 'When a visitor fills out any embedded form or standalone survey, their submission is instantly parsed, validated, and saved into your central CRM contacts database with your chosen duplicate strategy (update or create) and auto-tags applied.'],
                                    ['q' => 'Can I connect custom domains or slugs?', 'a' => 'Yes. Every page can have a unique branded slug (/p/your-campaign) or be served via custom domain CNAME records.'],
                                    ['q' => 'Are pages mobile responsive?', 'a' => 'Every single section block is designed with responsive flexbox and CSS grid, rendering pixel-perfect layouts on smartphones, tablets, and wide monitors.'],
                                    ['q' => 'Can I add Meta Pixel and Google Tag Manager?', 'a' => 'Yes. The SEO & Tracking drawer allows direct injection of custom <head> and <body> scripts that load automatically on your live page.'],
                                ],
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#FFFFFF',
                                'padding_top' => 'py-14',
                                'padding_bottom' => 'py-14',
                                'text_align' => 'left',
                            ],
                        ],
                        [
                            'id' => 'sec-saas-7',
                            'type' => 'cta_banner',
                            'name' => 'High-Impact Call To Action',
                            'visible' => true,
                            'content' => [
                                'title' => 'Ready To Supercharge Your Lead Generation?',
                                'subtitle' => 'Join thousands of businesses streamlining customer acquisition with our unified platform.',
                                'cta_text' => 'Claim Your Free Account Now',
                                'cta_url' => '#contact',
                                'secondary_cta_text' => 'Talk to an Expert',
                                'secondary_cta_url' => '#contact',
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#166534',
                                'padding_top' => 'py-16',
                                'padding_bottom' => 'py-16',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-saas-8',
                            'type' => 'footer',
                            'name' => 'Footer',
                            'visible' => true,
                            'content' => [
                                'copyright' => '© 2026 CRM Automation Platform. All rights reserved.',
                                'links' => ['Privacy Policy', 'Terms of Service', 'Security', 'Support Center'],
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#0F172A',
                                'padding_top' => 'py-10',
                                'padding_bottom' => 'py-10',
                                'text_align' => 'center',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 2,
                'name' => 'Agency & Consulting Landing Page',
                'category' => 'agency',
                'template_type' => 'page',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1551836022-d5d88e9218df?w=600&auto=format&fit=crop&q=80',
                'description' => 'Showcase case studies, client testimonials, and book strategy consultations.',
                'content' => [
                    'theme' => [
                        'primary_color' => '#0F172A',
                        'secondary_color' => '#166534',
                        'background_color' => '#FFFFFF',
                        'text_color' => '#1E293B',
                        'font_family' => 'Plus Jakarta Sans, sans-serif',
                        'container_width' => '1140px',
                        'border_radius' => '16px',
                        'palette_id' => 'slate',
                    ],
                    'sections' => [
                        [
                            'id' => 'sec-agency-1',
                            'type' => 'hero',
                            'name' => 'Hero Header',
                            'visible' => true,
                            'content' => [
                                'badge' => 'PREMIER DIGITAL GROWTH AGENCY',
                                'title' => 'We Engineer Predictable Customer Acquisition Systems',
                                'subtitle' => 'We partner with enterprise brands and ambitious startups to accelerate revenue, deploy high-converting funnels, and optimize conversion rates.',
                                'cta_text' => 'Book Free Strategy Session',
                                'cta_url' => '#contact',
                                'secondary_cta_text' => 'Explore Client Results',
                                'secondary_cta_url' => '#case-studies',
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#F8FAFC',
                                'padding_top' => 'py-20',
                                'padding_bottom' => 'py-20',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-agency-2',
                            'type' => 'stats',
                            'name' => 'Impact Statistics',
                            'visible' => true,
                            'content' => [
                                'title' => 'Delivering Measurable Commercial Impact',
                                'items' => [
                                    ['value' => '$45M+', 'label' => 'Client Revenue Generated'],
                                    ['value' => '320+', 'label' => 'Campaigns Deployed'],
                                    ['value' => '94%', 'label' => 'Client Retention Rate'],
                                ],
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#FFFFFF',
                                'padding_top' => 'py-12',
                                'padding_bottom' => 'py-12',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-agency-3',
                            'type' => 'services',
                            'name' => 'Our Core Services',
                            'visible' => true,
                            'content' => [
                                'title' => 'Full-Funnel Growth Solutions',
                                'subtitle' => 'Targeted strategies customized to your commercial goals.',
                                'items' => [
                                    ['title' => 'Performance Marketing', 'desc' => 'Targeted Meta and Google campaigns optimized for return on ad spend and qualified inquiries.'],
                                    ['title' => 'Landing Page Optimization', 'desc' => 'High-velocity split testing and conversion-focused design that maximizes your lead capture rates.'],
                                    ['title' => 'CRM Automation & Nurturing', 'desc' => 'Architecting seamless follow-up sequences across WhatsApp and Email so no lead slips through the cracks.'],
                                ],
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#F8FAFC',
                                'padding_top' => 'py-16',
                                'padding_bottom' => 'py-16',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-agency-4',
                            'type' => 'testimonials',
                            'name' => 'Client Testimonials',
                            'visible' => true,
                            'content' => [
                                'title' => 'Endorsed by Fast-Growing Companies',
                                'items' => [
                                    ['quote' => 'They doubled our monthly inbound qualified leads in under 60 days. The CRM integration saved our SDRs over 20 hours a week.', 'author' => 'Alexander Hayes', 'role' => 'Chief Marketing Officer, BlueWave Tech'],
                                    ['quote' => 'The highest converting landing pages we have ever deployed. Truly world-class strategy and execution.', 'author' => 'Elena Rostova', 'role' => 'Managing Director, Horizon Ventures'],
                                ],
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#FFFFFF',
                                'padding_top' => 'py-16',
                                'padding_bottom' => 'py-16',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-agency-5',
                            'type' => 'contact_form',
                            'name' => 'Consultation Request Form',
                            'visible' => true,
                            'content' => [
                                'title' => 'Ready to Multiply Your Growth?',
                                'subtitle' => 'Schedule a discovery call with our partners. We will audit your current pipeline and map your growth plan.',
                                'submit_button_text' => 'Request Strategy Session',
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#F8FAFC',
                                'padding_top' => 'py-16',
                                'padding_bottom' => 'py-16',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-agency-6',
                            'type' => 'footer',
                            'name' => 'Footer',
                            'visible' => true,
                            'content' => [
                                'copyright' => '© 2026 Growth Agency Partners LLC. All rights reserved.',
                                'links' => ['Privacy Policy', 'Terms of Engagement', 'Contact Us'],
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#0F172A',
                                'padding_top' => 'py-8',
                                'padding_bottom' => 'py-8',
                                'text_align' => 'center',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 3,
                'name' => 'Webinar & Event Registration Funnel',
                'category' => 'general',
                'template_type' => 'page',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=600&auto=format&fit=crop&q=80',
                'description' => 'Urgency countdown timer, keynote speaker bios, and 1-click attendee registration.',
                'content' => [
                    'theme' => [
                        'primary_color' => '#EA580C',
                        'secondary_color' => '#1E293B',
                        'background_color' => '#FFFFFF',
                        'text_color' => '#0F172A',
                        'font_family' => 'Outfit, sans-serif',
                        'container_width' => '1100px',
                        'border_radius' => '14px',
                        'palette_id' => 'warm',
                    ],
                    'sections' => [
                        [
                            'id' => 'sec-webinar-1',
                            'type' => 'hero',
                            'name' => 'Event Header',
                            'visible' => true,
                            'content' => [
                                'badge' => '🔴 LIVE MASTERCLASS • LIMITED TO 500 SEATS',
                                'title' => 'Mastering AI-Powered B2B Customer Acquisition in 2026',
                                'subtitle' => 'Learn the step-by-step framework top operators use to build 7-figure customer funnels, automate outreach, and close deals effortlessly.',
                                'cta_text' => 'Reserve My Free Seat',
                                'cta_url' => '#register',
                                'secondary_cta_text' => 'View Session Agenda',
                                'secondary_cta_url' => '#agenda',
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#FFFBEB',
                                'padding_top' => 'py-20',
                                'padding_bottom' => 'py-16',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-webinar-2',
                            'type' => 'countdown',
                            'name' => 'Urgency Countdown Timer',
                            'visible' => true,
                            'content' => [
                                'target_date' => date('Y-m-d H:i:s', strtotime('+7 days 18:00:00')),
                                'label' => 'LIVE BROADCAST COMMENCES IN:',
                                'cta_text' => 'Claim Your Spot Before Registration Closes',
                                'cta_url' => '#register',
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#0F172A',
                                'padding_top' => 'py-12',
                                'padding_bottom' => 'py-12',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-webinar-3',
                            'type' => 'features',
                            'name' => 'What You Will Learn',
                            'visible' => true,
                            'content' => [
                                'title' => 'Actionable Insights You Will Walk Away With',
                                'subtitle' => 'No fluff or generic advice — just field-tested blueprints.',
                                'items' => [
                                    ['title' => 'The Automated DM Pipeline', 'desc' => 'How to turn social media conversations on Meta and WhatsApp into booked calendar meetings automatically.'],
                                    ['title' => 'High-Conversion Landing Page Blueprint', 'desc' => 'The 5 psychological triggers that turn passive website visitors into eager leads.'],
                                    ['title' => 'Zero-Maintenance Lead Routing', 'desc' => 'How to sync form submissions directly to your CRM with automatic segmentation and immediate SMS/Email alerts.'],
                                ],
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#FFFFFF',
                                'padding_top' => 'py-16',
                                'padding_bottom' => 'py-16',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-webinar-4',
                            'type' => 'contact_form',
                            'name' => 'Registration Form',
                            'visible' => true,
                            'content' => [
                                'title' => 'Register For The Free Masterclass',
                                'subtitle' => 'Instant access link and workbook will be sent to your email immediately.',
                                'submit_button_text' => 'Confirm My Seat Now',
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#F8FAFC',
                                'padding_top' => 'py-16',
                                'padding_bottom' => 'py-16',
                                'text_align' => 'center',
                            ],
                        ],
                        [
                            'id' => 'sec-webinar-5',
                            'type' => 'footer',
                            'name' => 'Footer',
                            'visible' => true,
                            'content' => [
                                'copyright' => '© 2026 Masterclass Series. All rights reserved.',
                                'links' => ['Privacy Policy', 'Terms of Service', 'Support'],
                            ],
                            'settings' => [
                                'bg_type' => 'solid',
                                'bg_color' => '#0F172A',
                                'padding_top' => 'py-8',
                                'padding_bottom' => 'py-8',
                                'text_align' => 'center',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 4,
                'name' => 'Lead Gen Contact Form',
                'category' => 'general',
                'template_type' => 'form',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1557804506-669a67965ba0?w=600&auto=format&fit=crop&q=80',
                'description' => 'Optimized for high completions with name, phone, email, and service dropdown.',
            ],
            [
                'id' => 5,
                'name' => 'Client Satisfaction NPS Survey',
                'category' => 'general',
                'template_type' => 'survey',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1434030216411-0b793f4b4173?w=600&auto=format&fit=crop&q=80',
                'description' => '3-step interactive customer feedback survey with 5-star ratings and NPS.',
            ],
        ];

        return array_values(array_filter($all, function ($t) use ($type, $category) {
            if ($type && $type !== 'all' && $t['template_type'] !== $type) return false;
            if ($category && $category !== 'all' && $t['category'] !== $category) return false;
            return true;
        }));
    }
}

