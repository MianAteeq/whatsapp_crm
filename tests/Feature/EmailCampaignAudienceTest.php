<?php

namespace Tests\Feature;

use App\Jobs\ProcessEmailCampaignJob;
use App\Models\Business;
use App\Models\Category;
use App\Models\Contact;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailSendingDomain;
use App\Models\EmailSuppression;
use App\Models\EmailTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Email\EmailAudienceFilterService;
use App\Services\Email\MailtrapDomainService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailCampaignAudienceTest extends TestCase
{
    private Tenant $tenant;
    private int $leadNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null, 'logging.default' => 'null']);
        app('db')->purge('sqlite');
        foreach ([
            '2026_04_04_174815_create_tenants_table.php',
            '2026_06_06_000000_create_users_table.php',
            '2026_06_22_182000_add_status_to_users_table.php',
            '2026_11_13_000000_create_roles_table.php',
            '2026_05_06_111045_create_contacts_table.php',
            '2026_10_02_000000_create_business_scraper_tables.php',
            '2026_10_03_140000_create_contact_upsert_and_enrichment_tables.php',
            '2026_10_06_140000_create_categories_businesses_and_scrape_results_tables.php',
            '2026_10_05_160000_create_email_campaign_tables.php',
            '2026_10_05_200000_create_mailtrap_and_email_delivery_events_tables.php',
            '2026_10_06_130000_create_email_sending_domains_and_audit_tables.php',
            '2026_11_14_000000_add_editor_data_to_email_templates.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->tenant = Tenant::create(['name' => 'Audience Workspace']);
        Sanctum::actingAs(User::create(['tenant_id' => $this->tenant->id, 'name' => 'Campaign Admin', 'email' => 'admin@example.test', 'password' => 'Testing123!', 'role' => 'admin', 'status' => 'active']));
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function lead(array $overrides = []): Contact
    {
        $number = ++$this->leadNumber;
        return Contact::withoutEvents(fn () => Contact::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => 'Lead '.$number,
            'first_name' => 'Lead', 'phone' => '+1555000'.$number, 'email' => "lead{$number}@example.test",
            'source' => 'Business Scraper', 'status' => 'active',
            'lead_quality_grade' => 'A', 'lead_quality_score' => 80,
            'website' => 'https://example.test',
        ], $overrides)));
    }

    private function campaign(array $overrides = []): EmailCampaign
    {
        return EmailCampaign::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => 'Campaign', 'subject' => 'Subject',
            'from_name' => 'Team', 'from_email' => 'sender@example.test', 'html_body' => '<p>Message</p>',
        ], $overrides));
    }

    public function test_all_scope_ignores_hidden_filters_and_stays_inside_workspace(): void
    {
        $this->lead();
        $this->lead(['source' => 'CRM', 'lead_quality_grade' => 'D', 'lead_quality_score' => 0]);
        $other = Tenant::create(['name' => 'Other']);
        $this->lead(['tenant_id' => $other->id]);
        $this->postJson('/api/email/campaigns/estimate-audience', [
            'audience_type' => 'all', 'source' => 'Not a matching source', 'location' => 'Nowhere',
            'email_history' => 'delivered', 'lead_quality_grades' => ['B'], 'min_lead_score' => 99,
        ])->assertOk()->assertJsonPath('data.eligible_count', 2)->assertJsonPath('data.total_evaluated', 2);
    }

    public function test_category_source_industry_business_city_grades_score_and_website_combine(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Dental Clinics', 'slug' => 'dental']);
        $business = Business::create(['tenant_id' => $this->tenant->id, 'business_name' => 'Example Business', 'city' => 'Lahore']);
        $match = $this->lead(['business_id' => $business->id]);
        $match->categories()->attach($category);
        foreach ([['lead_quality_grade' => 'C'], ['lead_quality_score' => 50], ['website' => '   '], ['source' => 'CRM'], ['business_id' => null]] as $overrides) {
            $lead = $this->lead(array_merge(['business_id' => $business->id], $overrides));
            $lead->categories()->attach($category);
        }
        $this->lead(['business_id' => $business->id]); // Not assigned to the selected category.
        $this->postJson('/api/email/campaigns/estimate-audience', [
            'audience_type' => 'filtered', 'category_id' => $category->id, 'source' => 'Business Scraper',
            'industry' => 'Dental', 'location' => 'Lahore', 'lead_quality_grades' => ['A', 'B'],
            'min_lead_score' => 70, 'website_available' => true,
        ])->assertOk()->assertJsonPath('data.eligible_count', 1)->assertJsonPath('data.sample_eligible.0.id', $match->id)
            ->assertJsonPath('data.sample_eligible.0.first_name', 'Lead');
    }

    public function test_manual_source_aliases_and_custom_sources_are_available_per_workspace(): void
    {
        $this->lead(['source' => 'CRM']);
        $this->lead(['source' => 'CRM Manual']);
        $this->lead(['source' => 'CSV Import']);
        $other = Tenant::create(['name' => 'Private Workspace']);
        $this->lead(['tenant_id' => $other->id, 'source' => 'Private Source']);
        Category::create(['tenant_id' => $other->id, 'name' => 'Private Category', 'slug' => 'private']);
        $this->postJson('/api/email/campaigns/estimate-audience', ['audience_type' => 'filtered', 'source' => 'CRM'])
            ->assertOk()->assertJsonPath('data.eligible_count', 2);
        $options = $this->getJson('/api/email/campaigns/audience-options')->assertOk();
        $this->assertContains('CSV Import', array_column($options->json('data.sources'), 'value'));
        $this->assertNotContains('Private Source', array_column($options->json('data.sources'), 'value'));
        $this->assertSame([], $options->json('data.categories'));
    }

    public function test_email_history_distinguishes_unsent_failures_delivery_and_bounces(): void
    {
        $campaign = $this->campaign();
        $never = $this->lead();
        $failed = $this->lead();
        $delivered = $this->lead();
        $bounced = $this->lead();
        foreach ([[$failed, 'failed'], [$delivered, 'delivered'], [$bounced, 'bounced']] as [$contact, $status]) {
            EmailCampaignRecipient::create(['tenant_id' => $this->tenant->id, 'campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'email' => $contact->email, 'status' => $status]);
        }
        foreach (['never_sent' => [$never->id, $failed->id], 'previously_sent' => [$delivered->id, $bounced->id], 'delivered' => [$delivered->id], 'bounced' => [$bounced->id]] as $history => $ids) {
            $result = $this->postJson('/api/email/campaigns/estimate-audience', ['audience_type' => 'filtered', 'email_history' => $history])->assertOk();
            $this->assertSame($ids, array_column($result->json('data.sample_eligible'), 'id'));
        }
    }

    public function test_invalid_and_foreign_filters_are_rejected_before_save_or_estimation(): void
    {
        $other = Tenant::create(['name' => 'Other']);
        $category = Category::create(['tenant_id' => $other->id, 'name' => 'Other', 'slug' => 'other']);
        $filters = ['audience_type' => 'filtered', 'category_id' => $category->id, 'lead_quality_grades' => ['Z'], 'min_lead_score' => 120, 'website_available' => 'yes'];
        $this->postJson('/api/email/campaigns/estimate-audience', $filters)->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'lead_quality_grades.0', 'min_lead_score', 'website_available']);
        $this->postJson('/api/email/campaigns', ['name' => 'Campaign', 'subject' => 'Subject', 'from_name' => 'Team', 'from_email' => 'sender@example.test', 'html_body' => '<p>Message</p>', 'audience_filter' => $filters])
            ->assertUnprocessable()->assertJsonValidationErrors('audience_filter.category_id');
    }

    public function test_empty_selected_audience_never_expands_to_every_contact(): void
    {
        $this->lead();
        $this->assertSame(0, EmailAudienceFilterService::buildBaseQuery($this->tenant->id, ['audience_type' => 'selected', 'contact_ids' => []])->count());
        $this->postJson('/api/email/campaigns/estimate-audience', ['audience_type' => 'selected', 'contact_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('contact_ids');
    }

    public function test_launch_uses_the_same_audience_as_preview_and_preserves_selected_template(): void
    {
        $match = $this->lead(['source' => 'CRM']);
        $this->lead(['source' => 'CRM', 'email' => $match->email]); // Duplicate recipient.
        $this->lead(['source' => 'CRM', 'email' => null]);
        $suppressed = $this->lead(['source' => 'CRM']);
        EmailSuppression::create(['tenant_id' => $this->tenant->id, 'email' => $suppressed->email, 'reason' => 'unsubscribed']);
        $this->lead(); // Different source.
        $domain = EmailSendingDomain::create(['tenant_id' => $this->tenant->id, 'domain' => 'example.test', 'status' => 'verified']);
        $this->mock(MailtrapDomainService::class, function ($mock) use ($domain) {
            $mock->shouldReceive('validateDomainForSending')->andReturn(['allowed' => true, 'domain' => $domain]);
        });
        $template = EmailTemplate::create(['tenant_id' => $this->tenant->id, 'name' => 'Saved Template', 'subject' => 'Saved subject', 'category' => 'General', 'html_body' => '<p>Saved template</p>']);
        $filters = ['audience_type' => 'filtered', 'source' => 'CRM'];
        $preview = $this->postJson('/api/email/campaigns/estimate-audience', $filters)->assertOk();
        $preview->assertJsonPath('data.eligible_count', 1)->assertJsonPath('data.excluded_count', 3);
        $created = $this->postJson('/api/email/campaigns', [
            'name' => 'Launch', 'subject' => $template->subject, 'from_name' => 'Team', 'from_email' => 'sender@example.test',
            'html_body' => '<p>Campaign-only content</p>', 'template_id' => $template->id, 'audience_filter' => $filters, 'launch_now' => true,
        ])->assertCreated()->assertJsonPath('data.template_id', $template->id)->assertJsonPath('data.eligible_count', 1);
        $campaignId = $created->json('data.id');
        $this->assertSame([$match->id], EmailCampaignRecipient::where('campaign_id', $campaignId)->pluck('contact_id')->all());
        $this->assertSame('<p>Saved template</p>', $template->fresh()->html_body);
        Queue::assertPushed(ProcessEmailCampaignJob::class);
    }

    public function test_saved_template_search_and_pagination_include_workspace_templates_beyond_first_page(): void
    {
        for ($i = 0; $i < 26; $i++) {
            EmailTemplate::create(['tenant_id' => $this->tenant->id, 'name' => 'Newsletter '.$i, 'subject' => 'News', 'category' => 'Newsletter', 'html_body' => '<p>Saved content</p>']);
        }
        $other = Tenant::create(['name' => 'Other']);
        EmailTemplate::create(['tenant_id' => $other->id, 'name' => 'Newsletter private', 'subject' => 'Private', 'category' => 'General', 'html_body' => '<p>Private content</p>']);
        $this->getJson('/api/email/templates?search=Newsletter&page=2')->assertOk()->assertJsonPath('meta.total', 26)->assertJsonCount(6, 'data');
        $this->getJson('/api/email/templates?search=Newsletter%2025')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Newsletter 25');
    }
}
