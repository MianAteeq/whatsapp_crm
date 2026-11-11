<?php

namespace Tests\Feature;

use App\Jobs\ProcessSocialWebhookJob;
use App\Models\SocialAccount;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\SocialWebhookEvent;
use App\Models\Tenant;
use App\Services\Social\FacebookPageSubscriptionService;
use App\Services\Social\MetaGraphApiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FacebookWebhookIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.facebook.app_secret' => 'test_app_secret_12345']);
        config(['services.facebook.webhook_verify_token' => 'talkinexx-verify-token']);
    }

    /**
     * 1. Meta Webhook GET verification succeeds with valid token
     */
    public function test_webhook_verification_handshake_succeeds_with_valid_token(): void
    {
        $response = $this->get('/api/webhooks/facebook?hub.mode=subscribe&hub.verify_token=talkinexx-verify-token&hub.challenge=test_challenge_999');

        $response->assertStatus(200);
        $this->assertEquals('test_challenge_999', $response->getContent());
    }

    /**
     * 2. Meta Webhook GET verification fails with invalid token
     */
    public function test_webhook_verification_handshake_fails_with_invalid_token(): void
    {
        $response = $this->get('/api/webhooks/facebook?hub.mode=subscribe&hub.verify_token=wrong_token&hub.challenge=test_challenge_999');

        $response->assertStatus(403);
    }

    /**
     * 3. Webhook POST accepts valid HMAC-SHA256 signature
     */
    public function test_webhook_signature_validation_accepts_valid_hmac(): void
    {
        Queue::fake();

        $payload = json_encode([
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'PAGE_12345',
                    'time' => time(),
                    'messaging' => [
                        [
                            'sender' => ['id' => 'USER_789'],
                            'recipient' => ['id' => 'PAGE_12345'],
                            'timestamp' => time() * 1000,
                            'message' => [
                                'mid' => 'm_test_msg_001',
                                'text' => 'Hello Talkinexx!',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'test_app_secret_12345');

        $response = $this->call(
            'POST',
            '/api/webhooks/facebook',
            [],
            [],
            [],
            [
                'HTTP_X-Hub-Signature-256' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        );

        $response->assertStatus(200);
        $response->assertJson(['status' => 'EVENT_RECEIVED']);
        Queue::assertPushed(ProcessSocialWebhookJob::class);
    }

    /**
     * 4. Webhook POST rejects invalid HMAC-SHA256 signature
     */
    public function test_webhook_signature_validation_rejects_invalid_hmac(): void
    {
        $payload = json_encode(['object' => 'page', 'entry' => []]);

        $response = $this->call(
            'POST',
            '/api/webhooks/facebook',
            [],
            [],
            [],
            [
                'HTTP_X-Hub-Signature-256' => 'sha256=invalid_tampered_signature_123',
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        );

        $response->assertStatus(403);
    }

    /**
     * 5. Multi-Tenant Page Resolution & Strict Tenant Isolation (3 Tenants)
     */
    public function test_multi_tenant_page_resolution_and_strict_tenant_isolation(): void
    {
        // Create 3 simulated tenants
        $tenantA = Tenant::create(['name' => 'Tenant Alpha']);
        $tenantB = Tenant::create(['name' => 'Tenant Beta']);
        $tenantC = Tenant::create(['name' => 'Tenant Gamma']);

        // Create Facebook Pages for each tenant
        $pageA = SocialAccount::create([
            'tenant_id' => $tenantA->id,
            'platform' => 'facebook',
            'account_id' => 'PAGE_ALPHA_101',
            'name' => 'Alpha Page',
            'access_token' => 'token_alpha_101',
            'is_active' => true,
            'webhook_status' => SocialAccount::STATUS_WEBHOOK_ACTIVE,
            'followers_count' => 100,
        ]);

        $pageB = SocialAccount::create([
            'tenant_id' => $tenantB->id,
            'platform' => 'facebook',
            'account_id' => 'PAGE_BETA_202',
            'name' => 'Beta Page',
            'access_token' => 'token_beta_202',
            'is_active' => true,
            'webhook_status' => SocialAccount::STATUS_WEBHOOK_ACTIVE,
            'followers_count' => 200,
        ]);

        $pageC = SocialAccount::create([
            'tenant_id' => $tenantC->id,
            'platform' => 'facebook',
            'account_id' => 'PAGE_GAMMA_303',
            'name' => 'Gamma Page',
            'access_token' => 'token_gamma_303',
            'is_active' => true,
            'webhook_status' => SocialAccount::STATUS_WEBHOOK_ACTIVE,
            'followers_count' => 300,
        ]);

        // Incoming webhook payload targeting Tenant B's page
        $uniqueMid = 'mid_beta_' . uniqid();
        $payload = [
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'PAGE_BETA_202',
                    'time' => time(),
                    'messaging' => [
                        [
                            'sender' => ['id' => 'CUSTOMER_BETA_555'],
                            'recipient' => ['id' => 'PAGE_BETA_202'],
                            'timestamp' => time() * 1000,
                            'message' => [
                                'mid' => $uniqueMid,
                                'text' => 'Direct message for Tenant Beta',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Execute job directly
        $job = new ProcessSocialWebhookJob($payload);
        $job->handle(app(MetaGraphApiService::class));

        // 1. Verify message and conversation exist strictly under Tenant B
        $this->assertDatabaseHas('social_conversations', [
            'tenant_id' => $tenantB->id,
            'social_account_id' => $pageB->id,
            'participant_id' => 'CUSTOMER_BETA_555',
        ]);

        $this->assertDatabaseHas('social_messages', [
            'tenant_id' => $tenantB->id,
            'platform_message_id' => $uniqueMid,
            'message_text' => 'Direct message for Tenant Beta',
        ]);

        // 2. Strict tenant isolation: Tenant A and Tenant C must NOT have received any messages
        $this->assertEquals(0, SocialConversation::where('tenant_id', $tenantA->id)->count());
        $this->assertEquals(0, SocialMessage::where('tenant_id', $tenantA->id)->count());

        $this->assertEquals(0, SocialConversation::where('tenant_id', $tenantC->id)->count());
        $this->assertEquals(0, SocialMessage::where('tenant_id', $tenantC->id)->count());

        // 3. Verify Tenant B's page last_webhook_received_at was updated
        $pageB->refresh();
        $this->assertNotNull($pageB->last_webhook_received_at);
    }

    /**
     * 6. Duplicate Webhook Events Deduplication
     */
    public function test_duplicate_webhook_events_are_deduplicated(): void
    {
        $tenant = Tenant::create(['name' => 'Deduplication Tenant']);
        $page = SocialAccount::create([
            'tenant_id' => $tenant->id,
            'platform' => 'facebook',
            'account_id' => 'PAGE_DEDUP_111',
            'name' => 'Dedup Page',
            'access_token' => 'token_dedup',
            'is_active' => true,
            'followers_count' => 50,
        ]);

        $duplicateMid = 'mid_dedup_' . uniqid();
        $payload = [
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'PAGE_DEDUP_111',
                    'time' => time(),
                    'messaging' => [
                        [
                            'sender' => ['id' => 'CUSTOMER_111'],
                            'recipient' => ['id' => 'PAGE_DEDUP_111'],
                            'timestamp' => time() * 1000,
                            'message' => [
                                'mid' => $duplicateMid,
                                'text' => 'Deduplication test message',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Process first time
        $job1 = new ProcessSocialWebhookJob($payload);
        $job1->handle(app(MetaGraphApiService::class));

        // Process second time (redelivery simulation)
        $job2 = new ProcessSocialWebhookJob($payload);
        $job2->handle(app(MetaGraphApiService::class));

        // Exactly 1 message should exist in database
        $count = SocialMessage::where('tenant_id', $tenant->id)
            ->where('platform_message_id', $duplicateMid)
            ->count();

        $this->assertEquals(1, $count);
    }

    /**
     * 7. Unmapped Facebook Page ID does not assign to arbitrary tenant
     */
    public function test_unmapped_page_id_is_safely_ignored(): void
    {
        $initialMessagesCount = SocialMessage::count();

        $payload = [
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'PAGE_UNREGISTERED_99999',
                    'time' => time(),
                    'messaging' => [
                        [
                            'sender' => ['id' => 'STRANGER_999'],
                            'recipient' => ['id' => 'PAGE_UNREGISTERED_99999'],
                            'timestamp' => time() * 1000,
                            'message' => [
                                'mid' => 'mid_orphan_' . uniqid(),
                                'text' => 'Orphan message',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $job = new ProcessSocialWebhookJob($payload);
        $job->handle(app(MetaGraphApiService::class));

        // No new messages should be created
        $this->assertEquals($initialMessagesCount, SocialMessage::count());
    }

    /**
     * 8. Delivery and Read Receipts update message status
     */
    public function test_delivery_and_read_receipts_update_message_status(): void
    {
        $tenant = Tenant::create(['name' => 'Receipts Tenant']);
        $page = SocialAccount::create([
            'tenant_id' => $tenant->id,
            'platform' => 'facebook',
            'account_id' => 'PAGE_RECEIPTS_777',
            'name' => 'Receipts Page',
            'access_token' => 'token_receipts',
            'is_active' => true,
            'followers_count' => 10,
        ]);

        $conversation = SocialConversation::create([
            'tenant_id' => $tenant->id,
            'social_account_id' => $page->id,
            'participant_id' => 'CUSTOMER_777',
            'participant_name' => 'Customer',
            'platform' => 'facebook',
            'status' => 'open',
        ]);

        $mid = 'mid_outbound_' . uniqid();
        $message = SocialMessage::create([
            'tenant_id' => $tenant->id,
            'social_conversation_id' => $conversation->id,
            'social_account_id' => $page->id,
            'platform_message_id' => $mid,
            'sender_type' => SocialMessage::SENDER_AGENT,
            'sender_id' => 'PAGE_RECEIPTS_777',
            'message_text' => 'Agent outbound test message',
            'status' => SocialMessage::STATUS_SENT,
            'sent_at' => now()->subMinute(),
        ]);

        // 1. Send Delivery receipt event
        $deliveryPayload = [
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'PAGE_RECEIPTS_777',
                    'time' => time(),
                    'messaging' => [
                        [
                            'sender' => ['id' => 'CUSTOMER_777'],
                            'recipient' => ['id' => 'PAGE_RECEIPTS_777'],
                            'delivery' => [
                                'mids' => [$mid],
                                'watermark' => time() * 1000,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $job = new ProcessSocialWebhookJob($deliveryPayload);
        $job->handle(app(MetaGraphApiService::class));

        $message->refresh();
        $this->assertEquals(SocialMessage::STATUS_DELIVERED, $message->status);

        // 2. Send Read receipt event
        $readPayload = [
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'PAGE_RECEIPTS_777',
                    'time' => time(),
                    'messaging' => [
                        [
                            'sender' => ['id' => 'CUSTOMER_777'],
                            'recipient' => ['id' => 'PAGE_RECEIPTS_777'],
                            'read' => [
                                'watermark' => (time() + 10) * 1000,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $job2 = new ProcessSocialWebhookJob($readPayload);
        $job2->handle(app(MetaGraphApiService::class));

        $message->refresh();
        $this->assertEquals(SocialMessage::STATUS_READ, $message->status);
    }

    /**
     * 9. Subscription Service: Subscribe Page Success
     */
    public function test_page_subscription_service_subscribes_and_updates_status(): void
    {
        $tenant = Tenant::create(['name' => 'Sub Test Tenant']);
        $account = SocialAccount::create([
            'tenant_id' => $tenant->id,
            'platform' => 'facebook',
            'account_id' => 'PAGE_SUB_123',
            'name' => 'Subscription Test Page',
            'access_token' => 'valid_page_token_123',
            'is_active' => true,
            'webhook_status' => SocialAccount::STATUS_WEBHOOK_PENDING,
            'followers_count' => 10,
        ]);

        // Mock Meta Graph API response
        Http::fake([
            'https://graph.facebook.com/*/PAGE_SUB_123/subscribed_apps*' => Http::response(['success' => true], 200),
        ]);

        $service = app(FacebookPageSubscriptionService::class);
        $result = $service->subscribePage($account);

        $this->assertTrue($result['success']);
        $this->assertEquals(SocialAccount::STATUS_WEBHOOK_ACTIVE, $result['status']);

        $account->refresh();
        $this->assertEquals(SocialAccount::STATUS_WEBHOOK_ACTIVE, $account->webhook_status);
        $this->assertNull($account->webhook_subscription_error);
        $this->assertNotNull($account->last_synced_at);
    }

    /**
     * 10. Subscription Service: Handles Meta Error and stores error
     */
    public function test_page_subscription_service_handles_failure_gracefully(): void
    {
        $tenant = Tenant::create(['name' => 'Sub Fail Tenant']);
        $account = SocialAccount::create([
            'tenant_id' => $tenant->id,
            'platform' => 'facebook',
            'account_id' => 'PAGE_FAIL_999',
            'name' => 'Failing Page',
            'access_token' => 'expired_token',
            'is_active' => true,
            'webhook_status' => SocialAccount::STATUS_WEBHOOK_PENDING,
            'followers_count' => 10,
        ]);

        Http::fake([
            'https://graph.facebook.com/*/PAGE_FAIL_999/subscribed_apps*' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired.',
                    'code' => 190,
                ],
            ], 400),
        ]);

        $service = app(FacebookPageSubscriptionService::class);
        $result = $service->subscribePage($account);

        $this->assertFalse($result['success']);
        $this->assertEquals(SocialAccount::STATUS_WEBHOOK_FAILED, $result['status']);

        $account->refresh();
        $this->assertEquals(SocialAccount::STATUS_WEBHOOK_FAILED, $account->webhook_status);
        $this->assertStringContainsString('Session has expired', $account->webhook_subscription_error);
    }
}

