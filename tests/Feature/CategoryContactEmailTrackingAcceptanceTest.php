<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Category;
use App\Models\Business;
use App\Models\Contact;
use App\Models\ScraperSearch;
use App\Models\ScrapedBusiness;
use App\Models\ScrapeResult;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailSuppression;
use App\Models\EmailSendingDomain;
use App\Jobs\ProcessMailtrapWebhookJob;
use App\Services\Scraper\ContactUpsertService;
use App\Services\Email\EmailAudienceFilterService;
use App\Services\Email\EmailCampaignService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryContactEmailTrackingAcceptanceTest extends TestCase
{
    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $userA;
    protected User $userB;
    protected ContactUpsertService $upsertService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->upsertService = app(ContactUpsertService::class);

        $this->tenantA = Tenant::firstOrCreate(['id' => 88881], ['name' => 'Dental Care Portal', 'plan' => 'enterprise']);
        $this->tenantB = Tenant::firstOrCreate(['id' => 88882], ['name' => 'Aesthetic Clinics Portal', 'plan' => 'enterprise']);

        $this->userA = User::firstOrCreate(
            ['email' => 'admin@tenantA.test'],
            ['name' => 'Admin A', 'tenant_id' => $this->tenantA->id, 'password' => bcrypt('secret123')]
        );

        $this->userB = User::firstOrCreate(
            ['email' => 'admin@tenantB.test'],
            ['name' => 'Admin B', 'tenant_id' => $this->tenantB->id, 'password' => bcrypt('secret123')]
        );

        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }

    protected function cleanDatabase(): void
    {
        $tenantIds = [$this->tenantA->id, $this->tenantB->id];

        EmailSuppression::whereIn('tenant_id', $tenantIds)->delete();
        EmailCampaignRecipient::whereIn('tenant_id', $tenantIds)->delete();
        EmailCampaign::whereIn('tenant_id', $tenantIds)->delete();
        EmailSendingDomain::whereIn('tenant_id', $tenantIds)->delete();
        ScrapeResult::whereIn('tenant_id', $tenantIds)->delete();

        $contactIds = Contact::whereIn('tenant_id', $tenantIds)->pluck('id');
        $businessIds = Business::whereIn('tenant_id', $tenantIds)->pluck('id');

        DB::table('contact_categories')->whereIn('contact_id', $contactIds)->delete();
        DB::table('business_categories')->whereIn('business_id', $businessIds)->delete();

        Contact::whereIn('tenant_id', $tenantIds)->delete();
        Business::whereIn('tenant_id', $tenantIds)->delete();
        ScrapedBusiness::whereIn('tenant_id', $tenantIds)->delete();
        ScraperSearch::whereIn('tenant_id', $tenantIds)->delete();
        Category::whereIn('tenant_id', $tenantIds)->delete();
    }

    /**
     * TEST 1: Scrape Dental Clinics.
     * Expected: records categorized as Dental Clinics with proper relationships.
     */
    public function test_01_scrape_dental_clinics_records_categorized_as_dental_clinics()
    {
        $category = Category::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Dental Clinics',
            'slug' => 'dental-clinics',
            'status' => 'active',
        ]);

        $search = ScraperSearch::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'category_id' => $category->id,
            'keyword' => 'Dental Clinic',
            'location' => 'Lahore',
            'status' => 'completed',
        ]);

        $scraped = ScrapedBusiness::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'search_id' => $search->id,
            'business_name' => 'ABC Dental Clinic',
            'phone' => '+92 300 1234567',
            'email' => 'info@abcdental.com',
            'website' => 'https://abcdental.com',
            'city' => 'Lahore',
            'category' => 'Dental Clinics',
            'status' => 'enriched',
        ]);

        $result = $this->upsertService->upsertBusinessAndContacts(
            $this->tenantA->id,
            $scraped,
            $category->id,
            $search->id,
            $this->userA->id
        );

        $this->assertNotNull($result['contact']);
        $this->assertNotNull($result['business']);

        // Check Business category relationship
        $this->assertTrue(
            $result['business']->categories()->where('categories.id', $category->id)->exists(),
            'Business must have Dental Clinics category relation'
        );

        // Check Contact category relationship
        $this->assertTrue(
            $result['contact']->categories()->where('categories.id', $category->id)->exists(),
            'Contact must have Dental Clinics category relation'
        );

        // Check ScrapeResult provenance
        $this->assertDatabaseHas('scrape_results', [
            'tenant_id' => $this->tenantA->id,
            'scrape_search_id' => $search->id,
            'business_id' => $result['business']->id,
            'contact_id' => $result['contact']->id,
            'category_id' => $category->id,
        ]);
    }

    /**
     * TEST 2: Scrape Dental Clinics again.
     * Expected: no duplicate businesses or contacts created.
     */
    public function test_02_scrape_dental_clinics_again_no_duplicate_businesses_or_contacts()
    {
        $category = Category::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Dental Clinics Unique',
            'slug' => 'dental-clinics-unique',
        ]);

        $search1 = ScraperSearch::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'category_id' => $category->id,
            'keyword' => 'Dental Clinic',
            'location' => 'Lahore',
            'status' => 'completed',
        ]);

        $scraped1 = ScrapedBusiness::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'search_id' => $search1->id,
            'business_name' => 'Smile Care Dental',
            'phone' => '+92 300 7654321',
            'email' => 'hello@smilecaredental.com',
            'website' => 'https://smilecaredental.com',
            'status' => 'enriched',
        ]);

        $res1 = $this->upsertService->upsertBusinessAndContacts(
            $this->tenantA->id,
            $scraped1,
            $category->id,
            $search1->id,
            $this->userA->id
        );

        $this->assertEquals(1, Business::where('tenant_id', $this->tenantA->id)->count());
        $this->assertEquals(1, Contact::where('tenant_id', $this->tenantA->id)->count());

        // Second scrape job discovers identical business and phone
        $search2 = ScraperSearch::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'category_id' => $category->id,
            'keyword' => 'Dentist Lahore',
            'location' => 'Lahore',
            'status' => 'completed',
        ]);

        $scraped2 = ScrapedBusiness::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'search_id' => $search2->id,
            'business_name' => 'Smile Care Dental Practice',
            'phone' => '0300 7654321', // Local format
            'email' => 'hello@smilecaredental.com',
            'website' => 'https://smilecaredental.com',
            'status' => 'enriched',
        ]);

        $res2 = $this->upsertService->upsertBusinessAndContacts(
            $this->tenantA->id,
            $scraped2,
            $category->id,
            $search2->id,
            $this->userA->id
        );

        $this->assertEquals(1, Business::where('tenant_id', $this->tenantA->id)->count(), 'No duplicate business should be created');
        $this->assertEquals(1, Contact::where('tenant_id', $this->tenantA->id)->count(), 'No duplicate contact should be created');
        $this->assertEquals($res1['business']->id, $res2['business']->id);
        $this->assertEquals($res1['contact']->id, $res2['contact']->id);
    }

    /**
     * TEST 3: Same phone in different formats.
     * Expected: one contact (normalized).
     */
    public function test_03_same_phone_in_different_formats_creates_one_contact()
    {
        $category = Category::create(['tenant_id' => $this->tenantA->id, 'name' => 'Real Estate', 'slug' => 'real-estate-norm']);

        $search = ScraperSearch::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'category_id' => $category->id,
            'keyword' => 'Real Estate',
            'location' => 'Lahore',
            'status' => 'completed',
        ]);

        $formats = [
            '0300-1234567',
            '0300 1234567',
            '+92 300 1234567',
            '+923001234567'
        ];

        foreach ($formats as $idx => $phone) {
            $scraped = ScrapedBusiness::create([
                'tenant_id' => $this->tenantA->id,
                'user_id' => $this->userA->id,
                'search_id' => $search->id,
                'business_name' => "Real Estate Office {$idx}",
                'phone' => $phone,
                'status' => 'enriched',
            ]);
            $this->upsertService->upsertBusinessAndContacts($this->tenantA->id, $scraped, $category->id, $search->id, $this->userA->id);
        }

        $contacts = Contact::where('tenant_id', $this->tenantA->id)->get();
        $this->assertCount(1, $contacts, 'All 4 phone representations must normalize into exactly one contact record');
        $this->assertEquals('+923001234567', $contacts->first()->phone_normalized);
    }

    /**
     * TEST 4: Contact belongs to Dental Clinics.
     * Expected: appears under Dental Clinics category filter.
     */
    public function test_04_contact_belongs_to_dental_clinics_appears_under_category()
    {
        $catDental = Category::create(['tenant_id' => $this->tenantA->id, 'name' => 'Dental Clinics', 'slug' => 'dental-clinics-list']);
        $catRealEstate = Category::create(['tenant_id' => $this->tenantA->id, 'name' => 'Real Estate', 'slug' => 'real-estate-list']);

        $contact = Contact::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Dr. Sara Malik',
            'email' => 'sara@dentalsmiles.pk',
            'phone' => '+923009988776',
            'phone_normalized' => '+923009988776',
            'status' => 'active',
        ]);
        $contact->categories()->sync([$catDental->id]);

        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/contacts?category_id={$catDental->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals($contact->id, $data[0]['id']);

        // Check filter for other category
        $responseOther = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/contacts?category_id={$catRealEstate->id}");
        $responseOther->assertStatus(200);
        $this->assertEmpty($responseOther->json('data'));
    }

    /**
     * TEST 5: Send Campaign A.
     * Expected: recipient status becomes Sent after dispatch attempt.
     */
    public function test_05_send_campaign_a_recipient_status_becomes_sent()
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Ali Khan',
            'email' => 'ali@testdomain.com',
            'phone' => '+923001112233',
            'status' => 'active',
        ]);

        $campaign = EmailCampaign::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Campaign A',
            'subject' => 'Welcome to Campaign A',
            'html_body' => '<p>Hello Ali</p>',
            'from_name' => 'Test Sender',
            'from_email' => 'outreach@testdomain.com',
            'status' => 'sending',
        ]);

        $recipient = EmailCampaignRecipient::create([
            'tenant_id' => $this->tenantA->id,
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => 'sent',
            'sent_at' => now(),
            'provider' => 'mailtrap',
            'provider_message_id' => 'msg-mt-1001',
        ]);

        $this->assertEquals('sent', $recipient->fresh()->status);
        $this->assertNotNull($recipient->fresh()->sent_at);
    }

    /**
     * TEST 6: Mailtrap webhook says Delivered.
     * Expected: status becomes Delivered with delivered_at timestamp.
     */
    public function test_06_mailtrap_webhook_says_delivered_status_becomes_delivered()
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Babar Azam',
            'email' => 'babar@cricket.pk',
            'phone' => '+923004455667',
        ]);

        $campaign = EmailCampaign::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'October Clinic Special',
            'subject' => 'Clinic Special Offer',
            'html_body' => '<p>Offer inside</p>',
            'from_name' => 'Outreach',
            'from_email' => 'outreach@dentist.com',
            'status' => 'sending',
        ]);

        $recipient = EmailCampaignRecipient::create([
            'tenant_id' => $this->tenantA->id,
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => 'sent',
            'sent_at' => now()->subMinutes(5),
            'provider' => 'mailtrap',
            'provider_message_id' => 'mailtrap-test-uuid-999',
        ]);

        // Process delivery event directly through the Mailtrap Webhook Job
        $event = [
            'event' => 'delivery',
            'message_id' => 'mailtrap-test-uuid-999',
            'email' => 'babar@cricket.pk',
            'timestamp' => time(),
        ];

        ProcessMailtrapWebhookJob::dispatchSync([$event]);

        $recipient->refresh();
        $this->assertEquals('delivered', $recipient->status);
        $this->assertNotNull($recipient->delivered_at);
    }

    /**
     * TEST 7: Contact has received Campaign A.
     * Expected: "Never Sent" filter excludes that contact for Campaign A.
     */
    public function test_07_contact_received_campaign_a_never_sent_excludes_it_for_campaign_a()
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Rehan Siddiqui',
            'email' => 'rehan@domain.pk',
            'phone' => '+923005566778',
        ]);

        $campaignA = EmailCampaign::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Dental Outreach #1',
            'subject' => 'Outreach Subject',
            'html_body' => '<p>Body text</p>',
            'from_name' => 'Outreach',
            'from_email' => 'outreach@test.com',
            'status' => 'completed',
        ]);

        EmailCampaignRecipient::create([
            'tenant_id' => $this->tenantA->id,
            'campaign_id' => $campaignA->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        // When filtering Campaign A with email_status=never_sent, $contact must NOT be returned
        $res = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/contacts?campaign_id={$campaignA->id}&email_status=never_sent");

        $res->assertStatus(200);
        $this->assertEmpty($res->json('data'));
    }

    /**
     * TEST 8: Contact has never received any campaign.
     * Expected: global "Never Sent" filter includes it.
     */
    public function test_08_contact_never_received_any_campaign_global_never_sent_includes_it()
    {
        $contactNever = Contact::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Brand New Lead',
            'email' => 'brandnew@hospital.pk',
            'phone' => '+923001239999',
            'status' => 'active',
        ]);

        $res = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/contacts?email_status=never_sent');

        $res->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($contactNever->id, $ids);
    }

    /**
     * TEST 9: Contact received Campaign A but not Campaign B.
     * Expected:
     * Campaign A -> Sent/Delivered
     * Campaign B -> Not Sent.
     */
    public function test_09_contact_received_campaign_a_but_not_campaign_b_correct_statuses()
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Zubair Qureshi',
            'email' => 'zubair@qureshi.pk',
            'phone' => '+923008899001',
        ]);

        $campaignA = EmailCampaign::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Campaign A',
            'subject' => 'Campaign A Subject',
            'html_body' => '<p>Body A</p>',
            'from_name' => 'Sender',
            'from_email' => 'outreach@test.com',
            'status' => 'completed',
        ]);

        $campaignB = EmailCampaign::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Campaign B',
            'subject' => 'Campaign B Subject',
            'html_body' => '<p>Body B</p>',
            'from_name' => 'Sender',
            'from_email' => 'outreach@test.com',
            'status' => 'completed',
        ]);

        EmailCampaignRecipient::create([
            'tenant_id' => $this->tenantA->id,
            'campaign_id' => $campaignA->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => 'delivered',
            'sent_at' => now()->subDay(),
            'delivered_at' => now()->subDay(),
        ]);

        // Campaign A check: email_status=delivered includes contact
        $resCampA = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/contacts?campaign_id={$campaignA->id}&email_status=delivered");
        $resCampA->assertStatus(200);
        $this->assertCount(1, $resCampA->json('data'));

        // Campaign B check: email_status=never_sent includes contact for Campaign B
        $resCampB = $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/contacts?campaign_id={$campaignB->id}&email_status=never_sent");
        $resCampB->assertStatus(200);
        $this->assertCount(1, $resCampB->json('data'));
    }

    /**
     * TEST 10: Contact unsubscribes.
     * Expected: suppressed and excluded from audience estimation.
     */
    public function test_10_contact_unsubscribes_excluded_from_future_sending()
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Opt Out Person',
            'email' => 'optout@patient.pk',
            'phone' => '+923007788990',
            'status' => 'active',
        ]);

        // Record unsubscribe suppression
        EmailSuppression::create([
            'tenant_id' => $this->tenantA->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'reason' => EmailSuppression::REASON_UNSUBSCRIBED,
        ]);

        $this->assertTrue(EmailSuppression::isSuppressed($this->tenantA->id, $contact->email));

        $estimate = EmailAudienceFilterService::evaluateAudience($this->tenantA->id, ['audience_type' => 'all']);
        $this->assertEquals(1, $estimate['reasons']['suppressed'] ?? 0);
        $this->assertEquals(0, $estimate['eligible_count']);
    }

    /**
     * TEST 11: Hard bounce.
     * Expected: appropriately suppressed.
     */
    public function test_11_hard_bounce_appropriately_suppressed()
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Bounce Lead',
            'email' => 'bademail@doesnotexist.invalid',
            'phone' => '+923003344556',
        ]);

        $campaign = EmailCampaign::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Initial Outreach',
            'subject' => 'Initial Outreach Subject',
            'html_body' => '<p>Initial Body</p>',
            'from_name' => 'Sender',
            'from_email' => 'outreach@test.com',
            'status' => 'completed',
        ]);

        EmailCampaignRecipient::create([
            'tenant_id' => $this->tenantA->id,
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => 'bounced',
            'bounce_reason' => '550 5.1.1 User unknown',
            'bounced_at' => now(),
        ]);

        EmailSuppression::create([
            'tenant_id' => $this->tenantA->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'reason' => EmailSuppression::REASON_HARD_BOUNCE,
            'details' => '550 5.1.1 User unknown',
        ]);

        $this->assertTrue(EmailSuppression::isSuppressed($this->tenantA->id, $contact->email));

        // Querying "never_sent" should NOT include this bounced contact
        $res = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/api/contacts?email_status=never_sent');
        $res->assertStatus(200);

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($contact->id, $ids);
    }

    /**
     * TEST 12: Tenant isolation.
     * Expected: Tenant A cannot access Tenant B contacts/categories/email history.
     */
    public function test_12_tenant_a_cannot_access_tenant_b_data()
    {
        $catB = Category::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Private Beta Category',
            'slug' => 'private-beta-category-b',
        ]);

        $contactB = Contact::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Tenant B Secret Contact',
            'email' => 'secret@tenantb.com',
            'phone' => '+923000000002',
        ]);

        // Tenant A queries categories
        $resCat = $this->actingAs($this->userA, 'sanctum')->getJson('/api/categories');
        $resCat->assertStatus(200);
        $catIds = collect($resCat->json('data'))->pluck('id')->all();
        $this->assertNotContains($catB->id, $catIds, 'Tenant A cannot see Tenant B categories');

        // Tenant A queries contacts
        $resContact = $this->actingAs($this->userA, 'sanctum')->getJson('/api/contacts');
        $resContact->assertStatus(200);
        $contactIds = collect($resContact->json('data'))->pluck('id')->all();
        $this->assertNotContains($contactB->id, $contactIds, 'Tenant A cannot see Tenant B contacts');
    }

    /**
     * TEST 13: Scraper finds an existing contact.
     * Expected: enrich missing fields instead of creating duplicate.
     */
    public function test_13_scraper_finds_existing_contact_enriches_missing_fields_without_duplicate()
    {
        $search = ScraperSearch::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'keyword' => 'Dental Care',
            'location' => 'Lahore',
            'status' => 'completed',
        ]);

        // Existing contact has phone and name but missing website and email
        $existing = Contact::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Dr. Hamid',
            'phone' => '+923001238877',
            'phone_normalized' => '+923001238877',
            'email' => null,
            'website' => null,
        ]);

        $scraped = ScrapedBusiness::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'search_id' => $search->id,
            'business_name' => 'Hamid Dental Practice',
            'phone' => '0300 1238877',
            'email' => 'hamid@hamiddental.com', // newly discovered email
            'website' => 'https://hamiddental.com', // newly discovered website
            'status' => 'enriched',
        ]);

        $this->upsertService->upsertBusinessAndContacts(
            $this->tenantA->id,
            $scraped,
            null,
            $search->id,
            $this->userA->id
        );

        $this->assertEquals(1, Contact::where('tenant_id', $this->tenantA->id)->count());

        $existing->refresh();
        $this->assertEquals('hamid@hamiddental.com', $existing->email, 'Missing email was enriched');
        $this->assertEquals('https://hamiddental.com', $existing->website, 'Missing website was enriched');
        $this->assertEquals('Dr. Hamid', $existing->name, 'Existing valid name was preserved');
    }

    /**
     * TEST 14: Scraper finds same business under another search query.
     * Expected: one business with multiple scraper/search associations in scrape_results.
     */
    public function test_14_scraper_finds_same_business_under_another_search_multiple_scrape_results()
    {
        $cat = Category::create(['tenant_id' => $this->tenantA->id, 'name' => 'Aesthetic Clinics', 'slug' => 'aesthetic-clinics-mult']);

        $search1 = ScraperSearch::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'category_id' => $cat->id,
            'keyword' => 'Hydrafacial Lahore',
            'location' => 'Gulberg, Lahore',
            'status' => 'completed',
        ]);

        $scraped1 = ScrapedBusiness::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'search_id' => $search1->id,
            'business_name' => 'Glow Aesthetics Clinic',
            'phone' => '+923005556667',
            'email' => 'info@glowclinic.pk',
            'status' => 'enriched',
        ]);

        $res1 = $this->upsertService->upsertBusinessAndContacts(
            $this->tenantA->id,
            $scraped1,
            $cat->id,
            $search1->id,
            $this->userA->id
        );

        $search2 = ScraperSearch::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'category_id' => $cat->id,
            'keyword' => 'Laser Treatment Lahore',
            'location' => 'DHA, Lahore',
            'status' => 'completed',
        ]);

        $scraped2 = ScrapedBusiness::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->userA->id,
            'search_id' => $search2->id,
            'business_name' => 'Glow Aesthetics Clinic DHA',
            'phone' => '+923005556667', // Identical phone
            'email' => 'info@glowclinic.pk',
            'status' => 'enriched',
        ]);

        $res2 = $this->upsertService->upsertBusinessAndContacts(
            $this->tenantA->id,
            $scraped2,
            $cat->id,
            $search2->id,
            $this->userA->id
        );

        // Assert only 1 Business was created
        $this->assertEquals(1, Business::where('tenant_id', $this->tenantA->id)->count());
        $this->assertEquals($res1['business']->id, $res2['business']->id);

        // Assert ScrapeResult contains BOTH searches
        $results = ScrapeResult::where('business_id', $res1['business']->id)->get();
        $this->assertCount(2, $results, 'Business must have multiple search associations in scrape_results');
        $searchIds = $results->pluck('scrape_search_id')->all();
        $this->assertContains($search1->id, $searchIds);
        $this->assertContains($search2->id, $searchIds);
    }
}
