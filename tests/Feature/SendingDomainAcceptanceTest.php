<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\EmailSendingDomain;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Email\MailtrapDomainService;
use App\Services\Email\EmailCampaignService;
use App\Jobs\SendEmailBatchJob;
use Illuminate\Support\Facades\DB;

class SendingDomainAcceptanceTest extends TestCase
{
    protected MailtrapDomainService $domainService;
    protected Tenant $tenantA;
    protected Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domainService = app(MailtrapDomainService::class);
        $this->tenantA = Tenant::firstOrCreate(['id' => 99991], ['name' => 'Tenant Alpha', 'plan' => 'pro']);
        $this->tenantB = Tenant::firstOrCreate(['id' => 99992], ['name' => 'Tenant Beta', 'plan' => 'pro']);
    }

    protected function tearDown(): void
    {
        EmailSendingDomain::whereIn('tenant_id', [$this->tenantA->id, $this->tenantB->id])->delete();
        EmailCampaign::whereIn('tenant_id', [$this->tenantA->id, $this->tenantB->id])->delete();
        parent::tearDown();
    }

    /** TEST 1: User adds example.com. Expected: Pending. */
    public function test_01_user_adds_domain_status_is_pending()
    {
        $domain = EmailSendingDomain::create([
            'tenant_id' => $this->tenantA->id,
            'domain' => 'alpha.com',
            'status' => EmailSendingDomain::STATUS_PENDING,
            'mailtrap_domain_id' => 101,
            'spf_status' => 'pending',
            'dkim_status' => 'pending',
            'dmarc_status' => 'pending',
        ]);

        $this->assertEquals(EmailSendingDomain::STATUS_PENDING, $domain->status);
        $this->assertFalse($domain->isVerified());
    }

    /** TEST 2: User has not configured DNS. Expected: Cannot send. */
    public function test_02_unconfigured_dns_cannot_send()
    {
        $domain = EmailSendingDomain::create([
            'tenant_id' => $this->tenantA->id,
            'domain' => 'alpha.com',
            'status' => EmailSendingDomain::STATUS_PENDING,
            'mailtrap_domain_id' => 102,
        ]);

        $result = $this->domainService->validateDomainForSending($this->tenantA->id, 'info@alpha.com', $domain->id);
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('pending', strtolower($result['message']));
    }

    /** TEST 3: SPF/DKIM/DMARC verified. Expected: Domain becomes Verified. */
    public function test_03_dns_verified_updates_status()
    {
        $domain = EmailSendingDomain::create([
            'tenant_id' => $this->tenantA->id,
            'domain' => 'alpha.com',
            'status' => EmailSendingDomain::STATUS_PENDING,
            'mailtrap_domain_id' => 103,
        ]);

        $domain->update([
            'status' => EmailSendingDomain::STATUS_VERIFIED,
            'spf_status' => 'verified',
            'dkim_status' => 'verified',
            'dmarc_status' => 'verified',
            'verified_at' => now(),
        ]);

        $this->assertTrue($domain->fresh()->isVerified());
    }

    /** TEST 4: Verified domain: example.com, From: sales@example.com. Expected: Send allowed. */
    public function test_04_verified_domain_matching_from_email_allowed()
    {
        $domain = EmailSendingDomain::create([
            'tenant_id' => $this->tenantA->id,
            'domain' => 'alpha.com',
            'status' => EmailSendingDomain::STATUS_VERIFIED,
            'mailtrap_domain_id' => 104,
        ]);

        $result = $this->domainService->validateDomainForSending($this->tenantA->id, 'sales@alpha.com', $domain->id);
        $this->assertTrue($result['allowed']);
        $this->assertEquals($domain->id, $result['domain']->id);
    }

    /** TEST 5: Verified domain: example.com, From: sales@gmail.com. Expected: BLOCKED. */
    public function test_05_unverified_from_domain_blocked()
    {
        $domain = EmailSendingDomain::create([
            'tenant_id' => $this->tenantA->id,
            'domain' => 'alpha.com',
            'status' => EmailSendingDomain::STATUS_VERIFIED,
            'mailtrap_domain_id' => 105,
        ]);

        $result = $this->domainService->validateDomainForSending($this->tenantA->id, 'sales@gmail.com', $domain->id);
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('mismatch', strtolower($result['message']));
    }

    /** TEST 6: Tenant A owns example.com. Tenant B attempts to use example.com. Expected: BLOCKED. */
    public function test_06_cross_tenant_sending_blocked()
    {
        $domain = EmailSendingDomain::create([
            'tenant_id' => $this->tenantA->id,
            'domain' => 'alpha.com',
            'status' => EmailSendingDomain::STATUS_VERIFIED,
            'mailtrap_domain_id' => 106,
        ]);

        $result = $this->domainService->validateDomainForSending($this->tenantB->id, 'sales@alpha.com', $domain->id);
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('unauthorized', strtolower($result['message']));
    }

    /** TEST 7: Domain becomes rejected after previously being verified. Expected: Future sends blocked. */
    public function test_07_rejected_domain_future_sends_blocked()
    {
        $domain = EmailSendingDomain::create([
            'tenant_id' => $this->tenantA->id,
            'domain' => 'alpha.com',
            'status' => EmailSendingDomain::STATUS_REJECTED,
            'mailtrap_domain_id' => 107,
        ]);

        $result = $this->domainService->validateDomainForSending($this->tenantA->id, 'sales@alpha.com', $domain->id);
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('rejected', strtolower($result['message']));
    }

    /** TEST 8: Frontend changes sending_domain_id to another tenant's domain. Expected: BLOCKED server-side. */
    public function test_08_spoofed_sending_domain_id_blocked_server_side()
    {
        $domainB = EmailSendingDomain::create([
            'tenant_id' => $this->tenantB->id,
            'domain' => 'beta.com',
            'status' => EmailSendingDomain::STATUS_VERIFIED,
            'mailtrap_domain_id' => 108,
        ]);

        $result = $this->domainService->validateDomainForSending($this->tenantA->id, 'sales@beta.com', $domainB->id);
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('unauthorized', strtolower($result['message']));
    }

    /** TEST 9: Queued email domain becomes unverified before worker execution. Expected: Email NOT sent. */
    public function test_09_worker_revalidates_and_aborts_if_domain_unverified()
    {
        $domain = EmailSendingDomain::create([
            'tenant_id' => $this->tenantA->id,
            'domain' => 'queue.com',
            'status' => EmailSendingDomain::STATUS_VERIFIED,
            'mailtrap_domain_id' => 109,
        ]);

        $campaign = EmailCampaign::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Worker Test',
            'from_name' => 'Tester',
            'from_email' => 'alerts@queue.com',
            'sending_domain_id' => $domain->id,
            'sending_domain' => 'queue.com',
            'subject' => 'Subject',
            'html_body' => '<p>Test</p>',
            'status' => EmailCampaign::STATUS_SENDING,
            'total_recipients' => 1,
        ]);

        $recipient = EmailCampaignRecipient::create([
            'tenant_id' => $this->tenantA->id,
            'campaign_id' => $campaign->id,
            'email' => 'target@example.com',
            'status' => 'queued',
        ]);

        // Revoke domain
        $domain->update(['status' => EmailSendingDomain::STATUS_FAILED]);

        $job = new SendEmailBatchJob($campaign->id, [$recipient->id]);
        $job->handle(app(EmailCampaignService::class));

        $recipient->refresh();
        $this->assertEquals('failed', $recipient->status);
        $this->assertStringContainsString('Sending domain is no longer verified', $recipient->error_message);
    }

    /** TEST 10: Pending domain appears in UI. Expected: Cannot select it as campaign sending domain. */
    public function test_10_selectable_domains_only_returns_verified()
    {
        EmailSendingDomain::create([
            'tenant_id' => $this->tenantA->id,
            'domain' => 'pending.com',
            'status' => EmailSendingDomain::STATUS_PENDING,
            'mailtrap_domain_id' => 110,
        ]);

        $selectable = EmailSendingDomain::where('tenant_id', $this->tenantA->id)
            ->where('status', EmailSendingDomain::STATUS_VERIFIED)
            ->pluck('domain')
            ->toArray();

        $this->assertNotContains('pending.com', $selectable);
    }
}
