<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactImportTest extends TestCase
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
            '2026_06_24_154600_create_plans_table.php',
            '2026_06_30_000000_create_automation_tables.php',
            '2026_05_06_111045_create_contacts_table.php',
            '2026_05_06_111936_create_tags_table.php',
            '2026_05_06_112102_create_contact_tag_table.php',
            '2026_10_02_000000_create_business_scraper_tables.php',
            '2026_10_03_140000_create_contact_upsert_and_enrichment_tables.php',
            '2026_10_06_140000_create_categories_businesses_and_scrape_results_tables.php',
            '2026_10_05_160000_create_email_campaign_tables.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->tenant = Tenant::create(['name' => 'Import Workspace']);
        Sanctum::actingAs(User::create(['tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'Testing123!', 'role' => 'admin', 'status' => 'active']));
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_csv_upload_uses_mapped_columns_tags_category_and_campaign_source(): void
    {
        $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Imported Leads', 'slug' => 'imported']);
        $csv = "\xEF\xBB\xBFFull Name,Mobile,Email Address,Groups\n\"Ali, Ahmed\",+923001234567,ALI@example.test,\"VIP,Newsletter\"\nFatima,+923211234567,fatima@example.test,Newsletter\n";
        $this->post('/api/contacts/import', [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
            'mappings' => json_encode(['name' => 'Full Name', 'phone' => 'Mobile', 'email' => 'Email Address', 'tags' => 'Groups']),
            'category_id' => $category->id,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success_count', 2)->assertJsonPath('skipped_count', 0);
        $ali = Contact::where('email', 'ali@example.test')->firstOrFail();
        $this->assertSame('Ali, Ahmed', $ali->name);
        $this->assertSame('+923001234567', $ali->phone_normalized);
        $this->assertSame('CSV Import', $ali->source);
        $this->assertEqualsCanonicalizing(['VIP', 'Newsletter'], $ali->tags()->pluck('name')->all());
        $this->assertSame([$category->id], $ali->categories()->pluck('categories.id')->all());
        $this->postJson('/api/email/campaigns/estimate-audience', ['audience_type' => 'filtered', 'source' => 'CSV Import', 'category_id' => $category->id])
            ->assertOk()->assertJsonPath('data.eligible_count', 2);
    }

    public function test_import_skips_existing_and_in_file_normalized_duplicates_and_invalid_rows(): void
    {
        Contact::create(['tenant_id' => $this->tenant->id, 'name' => 'Existing contact', 'phone' => '+92 300 1234567', 'email' => 'existing@example.test']);
        $csv = "name,phone,email\nDuplicate,+923001234567,changed@example.test\nNew Lead,+923211234567,new@example.test\nRepeated,+92 321 1234567,repeat@example.test\nInvalid Email,+923331234567,not-an-email\nMissing Phone,,missing@example.test\n";
        $this->post('/api/contacts/import', ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('success_count', 1)->assertJsonPath('duplicate_count', 2)->assertJsonPath('invalid_count', 2);
        $this->assertSame(2, Contact::count());
        $this->assertSame('existing@example.test', Contact::where('name', 'Existing contact')->value('email'));
    }

    public function test_existing_phone_in_another_workspace_does_not_block_import(): void
    {
        $other = Tenant::create(['name' => 'Other Workspace']);
        Contact::create(['tenant_id' => $other->id, 'name' => 'Other contact', 'phone' => '+923001234567', 'phone_normalized' => '+923001234567']);
        $csv = "name,phone,email\nOwn contact,+923001234567,own@example.test\n";
        $this->post('/api/contacts/import', ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('success_count', 1);
        $this->assertSame(1, Contact::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_import_enforces_plan_limit_without_claiming_skipped_rows_were_imported(): void
    {
        DB::table('plans')->where('key', 'free')->update(['limits' => json_encode(['contacts' => '1'])]);
        $csv = "name,phone,email\nFirst,+923001234567,first@example.test\nSecond,+923211234567,second@example.test\n";
        $this->post('/api/contacts/import', ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('success_count', 1)->assertJsonPath('limit_skipped_count', 1);
        $this->assertSame(1, Contact::count());
    }

    public function test_invalid_mapping_file_and_foreign_category_return_validation_errors(): void
    {
        $this->post('/api/contacts/import', ['mappings' => '{broken'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('mappings');
        $this->post('/api/contacts/import', [], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $other = Tenant::create(['name' => 'Other']);
        $category = Category::create(['tenant_id' => $other->id, 'name' => 'Private', 'slug' => 'private']);
        $this->post('/api/contacts/import', ['file' => UploadedFile::fake()->createWithContent('contacts.csv', "name,phone\nLead,+923001234567\n"), 'category_id' => $category->id], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->assertSame(0, Contact::count());
    }
}
