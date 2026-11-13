<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailTemplateBrandingTest extends TestCase
{
    private Tenant $tenant;

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
            '2026_10_05_160000_create_email_campaign_tables.php',
            '2026_11_14_000000_add_editor_data_to_email_templates.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->tenant = Tenant::create(['name' => 'Template Workspace']);
        Sanctum::actingAs(User::create(['tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'Testing123!', 'role' => 'admin', 'status' => 'active']));
    }

    private function payload(): array
    {
        return [
            'name' => 'Corporate Letter', 'subject' => 'Website updates', 'category' => 'General',
            'html_body' => '<table><tr><td>Company header</td></tr><tr><td>Message body</td></tr></table>',
            'editor_data' => [
                'frame_id' => 'corporate-letter',
                'body_html' => '<p>Message body</p><table><tr><td>Nested content</td></tr></table>',
                'brand' => [
                    'company_name' => 'Example Team', 'company_full_name' => 'Example Ltd',
                    'logo_url' => 'https://example.test/logo.png', 'website_url' => 'https://example.test',
                    'email' => 'hello@example.test', 'phone' => '+1 555 0100',
                    'department' => 'Operations', 'contact_label' => 'Talk to us',
                    'address' => "42 Example Street\nExample City",
                    'navigation' => [['label' => 'Company', 'url' => 'https://example.test/company']],
                    'socials' => ['linkedin' => 'https://linkedin.com/company/example', 'facebook' => '', 'instagram' => '', 'x' => ''],
                ],
            ],
        ];
    }

    public function test_template_brand_and_body_survive_save_reopen_and_duplicate(): void
    {
        $created = $this->postJson('/api/email/templates', $this->payload())->assertCreated();
        $id = $created->json('data.id');
        $this->getJson('/api/email/templates/'.$id)->assertOk()
            ->assertJsonPath('data.editor_data.brand.company_name', 'Example Team')
            ->assertJsonPath('data.editor_data.brand.address', "42 Example Street\nExample City")
            ->assertJsonPath('data.editor_data.body_html', $this->payload()['editor_data']['body_html']);
        $copy = $this->postJson('/api/email/templates/'.$id.'/duplicate')->assertCreated();
        $this->assertNotSame($id, $copy->json('data.id'));
        $this->assertSame($created->json('data.editor_data'), $copy->json('data.editor_data'));
    }

    public function test_content_and_brand_can_be_updated_without_losing_structured_fields(): void
    {
        $id = $this->postJson('/api/email/templates', $this->payload())->json('data.id');
        $payload = $this->payload();
        $payload['editor_data']['body_html'] = '<p>Updated message</p>';
        $payload['editor_data']['brand']['address'] = 'New office address';
        $payload['editor_data']['brand']['socials']['linkedin'] = 'https://linkedin.com/company/new';
        $payload['html_body'] = '<p>Updated rendered email</p>';
        $this->putJson('/api/email/templates/'.$id, $payload)->assertOk()
            ->assertJsonPath('data.editor_data.brand.address', 'New office address')
            ->assertJsonPath('data.editor_data.brand.socials.linkedin', 'https://linkedin.com/company/new')
            ->assertJsonPath('data.editor_data.body_html', '<p>Updated message</p>');
    }

    public function test_legacy_html_only_templates_remain_supported(): void
    {
        $payload = $this->payload();
        unset($payload['editor_data']);
        $this->postJson('/api/email/templates', $payload)->assertCreated()->assertJsonPath('data.editor_data', null);
    }

    public function test_unsafe_brand_urls_are_rejected(): void
    {
        $payload = $this->payload();
        $payload['editor_data']['brand']['logo_url'] = 'javascript:alert(1)';
        $payload['editor_data']['brand']['socials']['linkedin'] = 'data:text/html,invalid';
        $this->postJson('/api/email/templates', $payload)->assertUnprocessable()->assertJsonValidationErrors(['editor_data.brand.logo_url', 'editor_data.brand.socials.linkedin']);
    }

    public function test_template_access_is_scoped_to_its_workspace(): void
    {
        $other = Tenant::create(['name' => 'Other Workspace']);
        $payload = $this->payload();
        $foreign = EmailTemplate::create([...$payload, 'tenant_id' => $other->id]);
        $this->getJson('/api/email/templates/'.$foreign->id)->assertNotFound();
        $this->putJson('/api/email/templates/'.$foreign->id, $payload)->assertNotFound();
        $this->postJson('/api/email/templates/'.$foreign->id.'/duplicate')->assertNotFound();
        $this->getJson('/api/email/templates')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_campaign_cannot_reference_another_workspaces_template(): void
    {
        $other = Tenant::create(['name' => 'Other Workspace']);
        $foreign = EmailTemplate::create([...$this->payload(), 'tenant_id' => $other->id]);
        $this->postJson('/api/email/campaigns', [
            'name' => 'Campaign', 'subject' => 'Subject', 'from_name' => 'Team',
            'from_email' => 'sender@example.test', 'html_body' => '<p>Message</p>', 'template_id' => $foreign->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('template_id');
    }
}
