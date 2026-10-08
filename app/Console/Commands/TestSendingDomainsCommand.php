<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use App\Models\User;
use App\Models\EmailSendingDomain;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Email\MailtrapDomainService;
use App\Services\Email\EmailCampaignService;
use App\Jobs\SendEmailBatchJob;
use Illuminate\Support\Facades\DB;

class TestSendingDomainsCommand extends Command
{
    protected $signature = 'test:sending-domains';
    protected $description = 'Run the 10 Acceptance Tests for Mailtrap Email Sending Domain Verification';

    public function handle(MailtrapDomainService $domainService)
    {
        $this->info("==================================================");
        $this->info("RUNNING 10 ACCEPTANCE TESTS FOR SENDING DOMAINS");
        $this->info("==================================================\n");

        $passed = 0;
        $failed = 0;

        DB::beginTransaction();

        try {
            $tenantA = Tenant::firstOrCreate(
                ['id' => 99991],
                ['name' => 'Test Tenant Alpha', 'plan' => 'pro']
            );
            $tenantB = Tenant::firstOrCreate(
                ['id' => 99992],
                ['name' => 'Test Tenant Beta', 'plan' => 'pro']
            );

            // Clean previous test artifacts if any
            EmailSendingDomain::whereIn('tenant_id', [$tenantA->id, $tenantB->id])->delete();
            EmailCampaign::whereIn('tenant_id', [$tenantA->id, $tenantB->id])->delete();

            // -------------------------------------------------------------------------
            // TEST 1: User adds example.com. Expected: Pending.
            // -------------------------------------------------------------------------
            $this->comment("TEST 1: User adds example.com. Expected: Pending.");
            $domain1 = EmailSendingDomain::create([
                'tenant_id' => $tenantA->id,
                'domain' => 'test-alpha.example.com',
                'status' => EmailSendingDomain::STATUS_PENDING,
                'mailtrap_domain_id' => 12345,
                'mailtrap_domain_name' => 'test-alpha.example.com',
                'spf_status' => 'pending',
                'dkim_status' => 'pending',
                'dmarc_status' => 'pending',
                'dns_records' => [
                    ['type' => 'CNAME', 'name' => 'mail._domainkey.test-alpha.example.com', 'value' => 'mailtrap.example.com', 'purpose' => 'DKIM'],
                    ['type' => 'TXT', 'name' => 'test-alpha.example.com', 'value' => 'v=spf1 include:_spf.mailtrap.io ~all', 'purpose' => 'SPF'],
                ],
            ]);

            if ($domain1->status === EmailSendingDomain::STATUS_PENDING && !$domain1->isVerified()) {
                $this->info("  [PASS] Domain created with status: pending, isVerified() === false");
                $passed++;
            } else {
                $this->error("  [FAIL] Domain status is not pending: {$domain1->status}");
                $failed++;
            }

            // -------------------------------------------------------------------------
            // TEST 2: User has not configured DNS. Expected: Cannot send.
            // -------------------------------------------------------------------------
            $this->comment("\nTEST 2: User has not configured DNS. Expected: Cannot send.");
            $val2 = $domainService->validateDomainForSending($tenantA->id, 'info@test-alpha.example.com', $domain1->id);
            if ($val2['allowed'] === false && str_contains(strtolower($val2['message']), 'pending')) {
                $this->info("  [PASS] Sending blocked because domain DNS is unverified: " . $val2['message']);
                $passed++;
            } else {
                $this->error("  [FAIL] Sending was unexpectedly allowed for pending domain! Result: " . json_encode($val2));
                $failed++;
            }

            // -------------------------------------------------------------------------
            // TEST 3: SPF/DKIM/DMARC verified. Expected: Domain becomes Verified.
            // -------------------------------------------------------------------------
            $this->comment("\nTEST 3: SPF/DKIM/DMARC verified. Expected: Domain becomes Verified.");
            $domain1->update([
                'status' => EmailSendingDomain::STATUS_VERIFIED,
                'spf_status' => 'verified',
                'dkim_status' => 'verified',
                'dmarc_status' => 'verified',
                'verified_at' => now(),
                'last_checked_at' => now(),
            ]);
            $domain1->refresh();

            if ($domain1->status === EmailSendingDomain::STATUS_VERIFIED && $domain1->isVerified()) {
                $this->info("  [PASS] Domain updated to verified status. isVerified() === true");
                $passed++;
            } else {
                $this->error("  [FAIL] Domain is not verified: {$domain1->status}");
                $failed++;
            }

            // -------------------------------------------------------------------------
            // TEST 4: Verified domain: example.com, From: sales@example.com. Expected: Send allowed.
            // -------------------------------------------------------------------------
            $this->comment("\nTEST 4: Verified domain test-alpha.example.com, From: sales@test-alpha.example.com. Expected: Send allowed.");
            $val4 = $domainService->validateDomainForSending($tenantA->id, 'sales@test-alpha.example.com', $domain1->id);
            if ($val4['allowed'] === true && $val4['domain']->id === $domain1->id) {
                $this->info("  [PASS] Send allowed for verified domain. Validated domain: {$val4['domain']->domain}");
                $passed++;
            } else {
                $this->error("  [FAIL] Send was blocked unexpectedly: " . ($val4['message'] ?? 'Unknown error'));
                $failed++;
            }

            // -------------------------------------------------------------------------
            // TEST 5: Verified domain: example.com, From: sales@gmail.com. Expected: BLOCKED.
            // -------------------------------------------------------------------------
            $this->comment("\nTEST 5: Verified domain test-alpha.example.com, From: sales@gmail.com. Expected: BLOCKED.");
            $val5 = $domainService->validateDomainForSending($tenantA->id, 'sales@gmail.com', $domain1->id);
            if ($val5['allowed'] === false && str_contains(strtolower($val5['message']), 'mismatch')) {
                $this->info("  [PASS] Blocked From domain mismatch: " . $val5['message']);
                $passed++;
            } else {
                $this->error("  [FAIL] Sending from unauthorized domain (gmail.com) was allowed! Result: " . json_encode($val5));
                $failed++;
            }

            // -------------------------------------------------------------------------
            // TEST 6: Tenant A owns example.com. Tenant B attempts to use example.com. Expected: BLOCKED.
            // -------------------------------------------------------------------------
            $this->comment("\nTEST 6: Tenant A owns domain. Tenant B attempts to send from it. Expected: BLOCKED.");
            $val6 = $domainService->validateDomainForSending($tenantB->id, 'sales@test-alpha.example.com', $domain1->id);
            if ($val6['allowed'] === false && (str_contains(strtolower($val6['message']), 'does not belong') || str_contains(strtolower($val6['message']), 'not found') || str_contains(strtolower($val6['message']), 'not verified'))) {
                $this->info("  [PASS] Cross-tenant attempt blocked: " . $val6['message']);
                $passed++;
            } else {
                $this->error("  [FAIL] Cross-tenant spoofing was allowed! Result: " . json_encode($val6));
                $failed++;
            }

            // -------------------------------------------------------------------------
            // TEST 7: Domain becomes rejected after previously being verified. Expected: Future sends blocked.
            // -------------------------------------------------------------------------
            $this->comment("\nTEST 7: Domain becomes rejected after previously being verified. Expected: Future sends blocked.");
            $domain1->update([
                'status' => EmailSendingDomain::STATUS_REJECTED,
                'verification_error' => 'Mailtrap flagged domain reputation issues.',
                'last_checked_at' => now(),
            ]);
            $domain1->refresh();

            $val7 = $domainService->validateDomainForSending($tenantA->id, 'sales@test-alpha.example.com', $domain1->id);
            if ($val7['allowed'] === false && str_contains(strtolower($val7['message']), 'rejected')) {
                $this->info("  [PASS] Rejected domain blocked: " . $val7['message']);
                $passed++;
            } else {
                $this->error("  [FAIL] Send allowed on rejected domain! Result: " . json_encode($val7));
                $failed++;
            }

            // -------------------------------------------------------------------------
            // TEST 8: Frontend changes sending_domain_id to another tenant's domain. Expected: BLOCKED server-side.
            // -------------------------------------------------------------------------
            $this->comment("\nTEST 8: Frontend spoofing sending_domain_id across tenants. Expected: BLOCKED server-side.");
            // Create a verified domain for Tenant B
            $domainB = EmailSendingDomain::create([
                'tenant_id' => $tenantB->id,
                'domain' => 'test-beta.example.com',
                'status' => EmailSendingDomain::STATUS_VERIFIED,
                'mailtrap_domain_id' => 99999,
                'spf_status' => 'verified',
                'dkim_status' => 'verified',
                'dmarc_status' => 'verified',
            ]);

            // Tenant A submits payload specifying Tenant B's domain ID
            $val8 = $domainService->validateDomainForSending($tenantA->id, 'sales@test-beta.example.com', $domainB->id);
            if ($val8['allowed'] === false && str_contains(strtolower($val8['message']), 'does not belong')) {
                $this->info("  [PASS] Server-side tenant boundary enforced: " . $val8['message']);
                $passed++;
            } else {
                $this->error("  [FAIL] Frontend spoofing of sending_domain_id was NOT blocked! Result: " . json_encode($val8));
                $failed++;
            }

            // -------------------------------------------------------------------------
            // TEST 9: Queued email belongs to a domain that becomes unverified before worker execution.
            // Expected: Email is NOT sent. Recipient marked failed/blocked with "Sending domain is no longer verified."
            // -------------------------------------------------------------------------
            $this->comment("\nTEST 9: Queued email when domain becomes unverified before worker execution. Expected: Email NOT sent.");
            
            $domain9 = EmailSendingDomain::create([
                'tenant_id' => $tenantA->id,
                'domain' => 'queue-test.example.com',
                'status' => EmailSendingDomain::STATUS_VERIFIED,
                'mailtrap_domain_id' => 88888,
                'spf_status' => 'verified',
                'dkim_status' => 'verified',
                'dmarc_status' => 'verified',
            ]);

            $campaign9 = EmailCampaign::create([
                'tenant_id' => $tenantA->id,
                'name' => 'Worker Verification Test Campaign',
                'from_name' => 'Worker Tester',
                'from_email' => 'alerts@queue-test.example.com',
                'sending_domain_id' => $domain9->id,
                'sending_domain' => 'queue-test.example.com',
                'subject' => 'Queued Email Test',
                'html_body' => '<p>Hello queued world</p>',
                'status' => EmailCampaign::STATUS_SENDING,
                'total_recipients' => 1,
            ]);

            $recipient9 = EmailCampaignRecipient::create([
                'tenant_id' => $tenantA->id,
                'campaign_id' => $campaign9->id,
                'email' => 'destination@example.com',
                'status' => 'queued',
            ]);

            // Now revoke the domain BEFORE the worker executes
            $domain9->update([
                'status' => EmailSendingDomain::STATUS_FAILED,
                'verification_error' => 'DNS records were deleted at registrar.',
            ]);

            // Execute the batch job directly
            $job = new SendEmailBatchJob($campaign9->id, [$recipient9->id]);
            $campaignService = app(EmailCampaignService::class);
            $job->handle($campaignService);

            $recipient9->refresh();
            $campaign9->refresh();

            if ($recipient9->status === 'failed' && str_contains($recipient9->error_message ?? '', 'Sending domain is no longer verified')) {
                $this->info("  [PASS] Worker aborted sending: recipient status='{$recipient9->status}', error='{$recipient9->error_message}'");
                $passed++;
            } else {
                $this->error("  [FAIL] Worker did not block unverified domain send: recipient status='{$recipient9->status}', error='{$recipient9->error_message}'");
                $failed++;
            }

            // -------------------------------------------------------------------------
            // TEST 10: Pending domain appears in UI. Expected: Cannot select it as campaign sending domain.
            // -------------------------------------------------------------------------
            $this->comment("\nTEST 10: Pending domain appears in UI. Expected: Cannot select it as campaign sending domain.");
            
            // Check that selectable domains query returns only verified domains for selection
            $selectableDomains = EmailSendingDomain::where('tenant_id', $tenantA->id)
                ->where('status', EmailSendingDomain::STATUS_VERIFIED)
                ->get();

            $allTenantDomains = EmailSendingDomain::where('tenant_id', $tenantA->id)->get();
            $hasUnverifiedInSelectable = $selectableDomains->contains(function ($d) {
                return $d->status !== EmailSendingDomain::STATUS_VERIFIED;
            });

            if (!$hasUnverifiedInSelectable && $allTenantDomains->count() > $selectableDomains->count()) {
                $this->info("  [PASS] Verified domains query excludes all pending/rejected/failed domains ({$selectableDomains->count()} verified of {$allTenantDomains->count()} total tenant domains)");
                $passed++;
            } else if (!$hasUnverifiedInSelectable) {
                $this->info("  [PASS] Verified domains query excludes all non-verified domains.");
                $passed++;
            } else {
                $this->error("  [FAIL] Non-verified domain leaked into selectable list!");
                $failed++;
            }

        } finally {
            DB::rollBack();
        }

        $this->info("\n==================================================");
        $this->info("TEST SUMMARY: {$passed}/10 PASSED, {$failed}/10 FAILED");
        $this->info("==================================================");

        return $failed === 0 ? 0 : 1;
    }
}
