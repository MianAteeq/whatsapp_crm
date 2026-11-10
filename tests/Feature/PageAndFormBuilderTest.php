<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Contact;
use App\Models\BuilderPage;
use App\Models\BuilderForm;
use App\Models\BuilderSubmission;
use Illuminate\Foundation\Testing\WithFaker;

class PageAndFormBuilderTest extends TestCase
{
    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::first() ?? Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.crm.local',
        ]);

        $this->user = User::where('tenant_id', $this->tenant->id)->first() ?? User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Builder Test User',
            'email' => 'builder_test_' . rand(1000, 9999) . '@test.local',
            'password' => bcrypt('password'),
        ]);
    }

    /**
     * Test page creation and listing
     */
    public function test_can_create_and_list_landing_pages()
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/builder/pages', [
            'title' => 'Product Launch ' . rand(100, 999),
            'description' => 'A test landing page',
            'category' => 'saas',
        ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('success'));
        $pageId = $response->json('data.id');

        $listRes = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/builder/pages');
        $listRes->assertStatus(200);
        $this->assertNotEmpty($listRes->json('data'));
    }

    /**
     * Test form creation, publishing and public schema retrieval
     */
    public function test_can_create_and_publish_form()
    {
        $formRes = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/builder/forms', [
            'title' => 'Consulting Inquiry Form',
            'form_type' => 'lead_form',
            'status' => 'draft',
        ]);

        $formRes->assertStatus(201);
        $formId = $formRes->json('data.id');
        $slug = $formRes->json('data.slug');

        // Toggle Publish
        $pubRes = $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/builder/forms/{$formId}/publish");
        $pubRes->assertStatus(200);
        $this->assertEquals('published', $pubRes->json('data.status'));

        // Public Schema Fetch
        $publicRes = $this->getJson("/api/v1/public/builder/form/{$slug}");
        $publicRes->assertStatus(200);
        $this->assertEquals($slug, $publicRes->json('data.slug'));
    }

    /**
     * Test public form submission and CRM contact creation
     */
    public function test_public_form_submission_creates_crm_contact()
    {
        $testEmail = 'lead_' . rand(10000, 99999) . '@example.com';
        $testPhone = '+1555' . rand(1000000, 9999999);

        $form = BuilderForm::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Quick Contact Form',
            'slug' => 'test-quick-contact-' . rand(1000, 9999),
            'form_type' => 'lead_form',
            'status' => BuilderForm::STATUS_PUBLISHED,
            'crm_mapping' => [
                'field_mappings' => [
                    'full_name' => 'name',
                    'email' => 'email',
                    'phone' => 'phone',
                ],
                'auto_tags' => ['Automated-Test-Lead'],
                'duplicate_strategy' => 'update',
            ],
        ]);

        $submissionPayload = [
            'full_name' => 'Sarah Connor',
            'email' => $testEmail,
            'phone' => $testPhone,
            'company' => 'Cyberdyne Systems',
            'message' => 'Looking for enterprise CRM automation services.',
        ];

        $subRes = $this->postJson("/api/v1/public/builder/submit/{$form->slug}", $submissionPayload);
        $subRes->assertStatus(200);
        $this->assertTrue($subRes->json('success'));

        // Verify Contact was created in CRM contacts table
        $contact = Contact::where('tenant_id', $this->tenant->id)
            ->where('email', $testEmail)
            ->first();

        $this->assertNotNull($contact);
        $this->assertEquals('Sarah Connor', $contact->name);
        $this->assertEquals('Cyberdyne Systems', $contact->company);

        // Verify tag was attached
        $this->assertTrue($contact->tags()->where('name', 'Automated-Test-Lead')->exists());

        // Verify submission record was stored
        $this->assertDatabaseHas('builder_submissions', [
            'tenant_id' => $this->tenant->id,
            'form_id' => $form->id,
            'contact_id' => $contact->id,
        ]);
    }

    /**
     * Test honeypot spam protection
     */
    public function test_honeypot_discards_spam_submissions()
    {
        $form = BuilderForm::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'title' => 'Spam Test Form',
            'slug' => 'spam-test-' . rand(1000, 9999),
            'status' => BuilderForm::STATUS_PUBLISHED,
        ]);

        $spamPayload = [
            'name' => 'Spammer Bot',
            'email' => 'spam@bot.com',
            '_hp_name' => 'I am a bot filling the honeypot',
        ];

        $res = $this->postJson("/api/v1/public/builder/submit/{$form->slug}", $spamPayload);
        $res->assertStatus(200);

        // Verify NO contact was created for this bot
        $this->assertDatabaseMissing('contacts', [
            'email' => 'spam@bot.com',
        ]);
    }

    /**
     * Test dashboard metrics endpoint
     */
    public function test_dashboard_metrics()
    {
        $res = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/builder/dashboard/metrics');
        $res->assertStatus(200);
        $this->assertArrayHasKey('total_pages', $res->json('data'));
        $this->assertArrayHasKey('total_forms', $res->json('data'));
        $this->assertArrayHasKey('total_submissions', $res->json('data'));
        $this->assertArrayHasKey('leads_generated', $res->json('data'));
    }
}

