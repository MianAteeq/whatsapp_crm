<?php

namespace App\Services\Builder;

use App\Models\BuilderPage;
use App\Models\BuilderForm;
use App\Models\BuilderSubmission;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exception;

class PageBuilderService
{
    /**
     * Get paginated pages for a tenant
     */
    public function getPages(int $tenantId, array $filters = [])
    {
        $query = BuilderPage::forTenant($tenantId)
            ->with(['user:id,name,email'])
            ->withCount(['submissions']);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }

        $sortField = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        return $query->orderBy($sortField, $sortOrder)->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new landing page
     */
    public function createPage(int $tenantId, int $userId, array $data): BuilderPage
    {
        $slug = $this->generateUniqueSlug($data['slug'] ?? $data['title'] ?? 'landing-page');

        $sections = $data['sections'] ?? $this->getDefaultSections($data['title'] ?? 'New Page');

        return BuilderPage::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'title' => $data['title'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? 'landing_page',
            'status' => $data['status'] ?? BuilderPage::STATUS_DRAFT,
            'theme' => $data['theme'] ?? $this->getDefaultTheme(),
            'sections' => $sections,
            'seo_settings' => $data['seo_settings'] ?? [
                'meta_title' => $data['title'],
                'meta_description' => $data['description'] ?? '',
                'keywords' => '',
                'og_image' => '',
            ],
            'published_at' => ($data['status'] ?? '') === BuilderPage::STATUS_PUBLISHED ? now() : null,
        ]);
    }

    /**
     * Update an existing landing page
     */
    public function updatePage(int $tenantId, int $id, array $data): BuilderPage
    {
        $page = BuilderPage::forTenant($tenantId)->findOrFail($id);

        if (!empty($data['slug']) && $data['slug'] !== $page->slug) {
            $data['slug'] = $this->generateUniqueSlug($data['slug'], $page->id);
        }

        if (isset($data['status'])) {
            if ($data['status'] === BuilderPage::STATUS_PUBLISHED && $page->status !== BuilderPage::STATUS_PUBLISHED) {
                $data['published_at'] = now();
            } elseif ($data['status'] === BuilderPage::STATUS_DRAFT) {
                $data['published_at'] = null;
            }
        }

        $page->update(array_filter($data, fn($v) => !is_null($v)));

        return $page->fresh();
    }

    /**
     * Publish or unpublish a page
     */
    public function togglePublish(int $tenantId, int $id): BuilderPage
    {
        $page = BuilderPage::forTenant($tenantId)->findOrFail($id);

        $newStatus = $page->status === BuilderPage::STATUS_PUBLISHED 
            ? BuilderPage::STATUS_DRAFT 
            : BuilderPage::STATUS_PUBLISHED;

        $page->update([
            'status' => $newStatus,
            'published_at' => $newStatus === BuilderPage::STATUS_PUBLISHED ? now() : null,
        ]);

        return $page;
    }

    /**
     * Clone an existing page
     */
    public function clonePage(int $tenantId, int $id, int $userId): BuilderPage
    {
        $original = BuilderPage::forTenant($tenantId)->findOrFail($id);

        $cloned = $original->replicate();
        $cloned->user_id = $userId;
        $cloned->title = "{$original->title} (Copy)";
        $cloned->slug = $this->generateUniqueSlug("{$original->slug}-copy");
        $cloned->status = BuilderPage::STATUS_DRAFT;
        $cloned->published_at = null;
        $cloned->view_count = 0;
        $cloned->submission_count = 0;
        $cloned->save();

        return $cloned;
    }

    /**
     * Delete a page
     */
    public function deletePage(int $tenantId, int $id): bool
    {
        $page = BuilderPage::forTenant($tenantId)->findOrFail($id);
        return (bool) $page->delete();
    }

    /**
     * Increment page views
     */
    public function recordPageView(string $slug): ?BuilderPage
    {
        $page = BuilderPage::where('slug', $slug)
            ->where('status', BuilderPage::STATUS_PUBLISHED)
            ->first();

        if ($page) {
            $page->increment('view_count');
        }

        return $page;
    }

    /**
     * Generate unique slug
     */
    public function generateUniqueSlug(string $text, ?int $ignoreId = null): string
    {
        $slug = Str::slug($text) ?: 'page-' . Str::random(6);
        $original = $slug;
        $count = 1;

        while (
            BuilderPage::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$original}-{$count}";
            $count++;
        }

        return $slug;
    }

    /**
     * Default design theme configuration
     */
    public function getDefaultTheme(): array
    {
        return [
            'primary_color' => '#166534',
            'secondary_color' => '#12263A',
            'background_color' => '#FFFFFF',
            'text_color' => '#1E293B',
            'font_family' => 'Inter, sans-serif',
            'container_width' => '1200px',
            'border_radius' => '12px',
        ];
    }

    /**
     * Default initial starter sections
     */
    public function getDefaultSections(string $title): array
    {
        return [
            [
                'id' => 'section_hero_' . Str::random(8),
                'type' => 'hero',
                'name' => 'Hero Banner',
                'visible' => true,
                'content' => [
                    'badge' => 'High Converting Landing Page',
                    'title' => 'Transform Your Business with Our Proven Solution',
                    'subtitle' => 'Capture more leads, drive qualified conversations, and scale your conversions with our all-in-one platform.',
                    'cta_text' => 'Get Started Today',
                    'cta_link' => '#contact',
                    'secondary_cta_text' => 'Learn More',
                    'secondary_cta_link' => '#features',
                    'image_url' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=1200&auto=format&fit=crop&q=80',
                ],
                'settings' => [
                    'bg_type' => 'gradient',
                    'bg_color' => '#F8FAFC',
                    'text_align' => 'center',
                    'padding_top' => '80px',
                    'padding_bottom' => '80px',
                ],
            ],
            [
                'id' => 'section_features_' . Str::random(8),
                'type' => 'features',
                'name' => 'Key Features',
                'visible' => true,
                'content' => [
                    'title' => 'Why Industry Leaders Choose Us',
                    'subtitle' => 'Built for modern growth teams who demand speed, reliability, and precision.',
                    'items' => [
                        [
                            'icon' => 'Zap',
                            'title' => 'Lightning Fast Setup',
                            'description' => 'Launch stunning responsive pages and lead funnels in minutes without touching a single line of code.',
                        ],
                        [
                            'icon' => 'ShieldCheck',
                            'title' => 'Direct CRM Synchronization',
                            'description' => 'Every submission is instantly validated, deduplicated, and mapped to your CRM contact database.',
                        ],
                        [
                            'icon' => 'BarChart3',
                            'title' => 'Real-Time Conversion Analytics',
                            'description' => 'Track visitor views, submission velocity, and conversion ratios live with interactive metric dashboards.',
                        ],
                    ],
                ],
                'settings' => [
                    'bg_type' => 'solid',
                    'bg_color' => '#FFFFFF',
                    'columns' => 3,
                    'padding_top' => '64px',
                    'padding_bottom' => '64px',
                ],
            ],
            [
                'id' => 'section_contact_' . Str::random(8),
                'type' => 'contact_form',
                'name' => 'Lead Capture Form',
                'visible' => true,
                'content' => [
                    'title' => 'Ready to Get Started?',
                    'subtitle' => 'Leave your contact information below and our team will get back to you within 24 hours.',
                    'form_slug' => '', // can be linked to a builder_form
                    'submit_text' => 'Submit Inquiry',
                ],
                'settings' => [
                    'bg_type' => 'solid',
                    'bg_color' => '#F1F5F9',
                    'padding_top' => '64px',
                    'padding_bottom' => '64px',
                ],
            ],
            [
                'id' => 'section_footer_' . Str::random(8),
                'type' => 'footer',
                'name' => 'Page Footer',
                'visible' => true,
                'content' => [
                    'copyright' => '© ' . date('Y') . ' All rights reserved.',
                    'links' => [
                        ['label' => 'Privacy Policy', 'url' => '#'],
                        ['label' => 'Terms of Service', 'url' => '#'],
                        ['label' => 'Contact Us', 'url' => '#contact'],
                    ],
                ],
                'settings' => [
                    'bg_type' => 'solid',
                    'bg_color' => '#0F172A',
                    'padding_top' => '32px',
                    'padding_bottom' => '32px',
                ],
            ],
        ];
    }
}

