<?php

namespace App\Services\Builder;

use App\Models\BuilderForm;
use App\Models\BuilderSubmission;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exception;

class FormBuilderService
{
    /**
     * Get paginated forms for a tenant
     */
    public function getForms(int $tenantId, array $filters = [])
    {
        $query = BuilderForm::forTenant($tenantId)
            ->with(['user:id,name,email', 'page:id,title,slug'])
            ->withCount(['submissions']);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['form_type']) && $filters['form_type'] !== 'all') {
            $query->where('form_type', $filters['form_type']);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $sortField = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        return $query->orderBy($sortField, $sortOrder)->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new form or survey
     */
    public function createForm(int $tenantId, int $userId, array $data): BuilderForm
    {
        $slug = $this->generateUniqueSlug($data['slug'] ?? $data['title'] ?? 'form');
        $formType = $data['form_type'] ?? BuilderForm::TYPE_LEAD_FORM;

        $schema = $data['schema'] ?? ($formType === BuilderForm::TYPE_SURVEY 
            ? $this->getDefaultSurveySchema() 
            : $this->getDefaultLeadFormSchema());

        return BuilderForm::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'page_id' => $data['page_id'] ?? null,
            'title' => $data['title'],
            'slug' => $slug,
            'form_type' => $formType,
            'status' => $data['status'] ?? BuilderForm::STATUS_DRAFT,
            'schema' => $schema,
            'settings' => $data['settings'] ?? [
                'submit_button_text' => $formType === BuilderForm::TYPE_SURVEY ? 'Complete Survey' : 'Submit Information',
                'thank_you_message' => 'Thank you! Your submission has been received successfully.',
                'redirect_url' => '',
                'theme' => [
                    'primary_color' => '#166534',
                    'background_color' => '#FFFFFF',
                    'border_radius' => '10px',
                ],
            ],
            'crm_mapping' => $data['crm_mapping'] ?? [
                'field_mappings' => [
                    'full_name' => 'name',
                    'email' => 'email',
                    'phone' => 'phone',
                    'company' => 'company',
                    'message' => 'notes',
                ],
                'auto_tags' => [$formType === BuilderForm::TYPE_SURVEY ? 'Survey-Lead' : 'Web-Lead'],
                'duplicate_strategy' => 'update',
            ],
            'published_at' => ($data['status'] ?? '') === BuilderForm::STATUS_PUBLISHED ? now() : null,
        ]);
    }

    /**
     * Update an existing form
     */
    public function updateForm(int $tenantId, int $id, array $data): BuilderForm
    {
        $form = BuilderForm::forTenant($tenantId)->findOrFail($id);

        if (!empty($data['slug']) && $data['slug'] !== $form->slug) {
            $data['slug'] = $this->generateUniqueSlug($data['slug'], $form->id);
        }

        if (isset($data['status'])) {
            if ($data['status'] === BuilderForm::STATUS_PUBLISHED && $form->status !== BuilderForm::STATUS_PUBLISHED) {
                $data['published_at'] = now();
            } elseif ($data['status'] === BuilderForm::STATUS_DRAFT) {
                $data['published_at'] = null;
            }
        }

        $form->update(array_filter($data, fn($v) => !is_null($v)));

        return $form->fresh();
    }

    /**
     * Toggle publish status
     */
    public function togglePublish(int $tenantId, int $id): BuilderForm
    {
        $form = BuilderForm::forTenant($tenantId)->findOrFail($id);

        $newStatus = $form->status === BuilderForm::STATUS_PUBLISHED 
            ? BuilderForm::STATUS_DRAFT 
            : BuilderForm::STATUS_PUBLISHED;

        $form->update([
            'status' => $newStatus,
            'published_at' => $newStatus === BuilderForm::STATUS_PUBLISHED ? now() : null,
        ]);

        return $form;
    }

    /**
     * Clone a form
     */
    public function cloneForm(int $tenantId, int $id, int $userId): BuilderForm
    {
        $original = BuilderForm::forTenant($tenantId)->findOrFail($id);

        $cloned = $original->replicate();
        $cloned->user_id = $userId;
        $cloned->title = "{$original->title} (Copy)";
        $cloned->slug = $this->generateUniqueSlug("{$original->slug}-copy");
        $cloned->status = BuilderForm::STATUS_DRAFT;
        $cloned->published_at = null;
        $cloned->view_count = 0;
        $cloned->submission_count = 0;
        $cloned->save();

        return $cloned;
    }

    /**
     * Delete a form
     */
    public function deleteForm(int $tenantId, int $id): bool
    {
        $form = BuilderForm::forTenant($tenantId)->findOrFail($id);
        return (bool) $form->delete();
    }

    /**
     * Generate unique slug
     */
    public function generateUniqueSlug(string $text, ?int $ignoreId = null): string
    {
        $slug = Str::slug($text) ?: 'form-' . Str::random(6);
        $original = $slug;
        $count = 1;

        while (
            BuilderForm::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$original}-{$count}";
            $count++;
        }

        return $slug;
    }

    /**
     * Default schema for standard lead generation form
     */
    public function getDefaultLeadFormSchema(): array
    {
        return [
            'is_multi_step' => false,
            'steps' => [
                [
                    'id' => 'step_1',
                    'title' => 'Contact Details',
                    'description' => 'Please provide your contact information',
                    'fields' => [
                        [
                            'id' => 'field_name',
                            'name' => 'full_name',
                            'label' => 'Full Name',
                            'type' => 'text',
                            'placeholder' => 'John Doe',
                            'required' => true,
                            'width' => 'half',
                        ],
                        [
                            'id' => 'field_email',
                            'name' => 'email',
                            'label' => 'Email Address',
                            'type' => 'email',
                            'placeholder' => 'john@example.com',
                            'required' => true,
                            'width' => 'half',
                        ],
                        [
                            'id' => 'field_phone',
                            'name' => 'phone',
                            'label' => 'Phone Number',
                            'type' => 'phone',
                            'placeholder' => '+1 (555) 000-0000',
                            'required' => true,
                            'width' => 'half',
                        ],
                        [
                            'id' => 'field_company',
                            'name' => 'company',
                            'label' => 'Company Name',
                            'type' => 'text',
                            'placeholder' => 'Acme Inc.',
                            'required' => false,
                            'width' => 'half',
                        ],
                        [
                            'id' => 'field_service',
                            'name' => 'interested_service',
                            'label' => 'Service of Interest',
                            'type' => 'dropdown',
                            'options' => [
                                'CRM & Automation',
                                'Lead Generation & Ads',
                                'Social Media Planning',
                                'Custom Development',
                            ],
                            'required' => false,
                            'width' => 'full',
                        ],
                        [
                            'id' => 'field_message',
                            'name' => 'message',
                            'label' => 'How can we help you?',
                            'type' => 'textarea',
                            'placeholder' => 'Tell us about your project or inquiry...',
                            'required' => false,
                            'width' => 'full',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Default schema for interactive multi-step survey
     */
    public function getDefaultSurveySchema(): array
    {
        return [
            'is_multi_step' => true,
            'steps' => [
                [
                    'id' => 'step_1',
                    'title' => 'Satisfaction & Experience',
                    'description' => 'Tell us about your recent experience with our services',
                    'fields' => [
                        [
                            'id' => 'field_rating',
                            'name' => 'overall_satisfaction',
                            'label' => 'How satisfied are you with our service?',
                            'type' => 'rating',
                            'max_stars' => 5,
                            'required' => true,
                            'width' => 'full',
                        ],
                        [
                            'id' => 'field_recommend',
                            'name' => 'would_recommend',
                            'label' => 'Would you recommend us to a colleague or friend?',
                            'type' => 'yes_no',
                            'required' => true,
                            'width' => 'full',
                        ],
                    ],
                ],
                [
                    'id' => 'step_2',
                    'title' => 'Feature Preferences',
                    'description' => 'Help us prioritize what matters most to your team',
                    'fields' => [
                        [
                            'id' => 'field_favorite_features',
                            'name' => 'valuable_features',
                            'label' => 'Which capabilities do you find most valuable?',
                            'type' => 'checkbox',
                            'options' => [
                                'Automated Workflows & Triggers',
                                'Unified Multi-Channel Social Inbox',
                                'High-Converting Landing Pages',
                                'Campaign Delivery Analytics',
                            ],
                            'required' => false,
                            'width' => 'full',
                        ],
                        [
                            'id' => 'field_feedback',
                            'name' => 'additional_feedback',
                            'label' => 'What is one thing we could do better?',
                            'type' => 'textarea',
                            'placeholder' => 'Your feedback helps shape our product roadmap...',
                            'required' => false,
                            'width' => 'full',
                        ],
                    ],
                ],
                [
                    'id' => 'step_3',
                    'title' => 'Your Profile (Optional)',
                    'description' => 'Share your contact details if you would like follow-up updates',
                    'fields' => [
                        [
                            'id' => 'field_name',
                            'name' => 'full_name',
                            'label' => 'Your Name',
                            'type' => 'text',
                            'placeholder' => 'Jane Smith',
                            'required' => false,
                            'width' => 'half',
                        ],
                        [
                            'id' => 'field_email',
                            'name' => 'email',
                            'label' => 'Email Address',
                            'type' => 'email',
                            'placeholder' => 'jane@example.com',
                            'required' => false,
                            'width' => 'half',
                        ],
                    ],
                ],
            ],
        ];
    }
}

