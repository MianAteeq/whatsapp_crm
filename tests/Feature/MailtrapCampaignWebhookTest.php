<?php

namespace Tests\Feature;

use App\Jobs\ProcessMailtrapWebhookJob;
use App\Jobs\SendEmailBatchJob;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailDeliveryEvent;
use App\Models\EmailSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Email\EmailCampaignService;
use App\Services\Email\EmailProviderFactory;
use App\Services\Email\MailtrapDomainService;
use App\Services\Email\Providers\MailtrapEmailProvider;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailtrapCampaignWebhookTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'queue.default' => 'sync',
            'logging.default' => 'null',
            'services.mailtrap.api_token' => 'global-test-token',
            'services.mailtrap.webhook_secret' => 'test-signing-secret',
        ]);
        app('db')->purge('sqlite');
        foreach ([
            '2026_04_04_174815_create_tenants_table.php',
            '2026_06_06_000000_create_users_table.php',
            '2026_06_22_182000_add_status_to_users_table.php',
            '2026_11_13_000000_create_roles_table.php',
            '2026_05_06_111045_create_contacts_table.php',
            '2026_10_05_160000_create_email_campaign_tables.php',
            '2026_10_05_170000_add_deepseek_api_key_to_email_settings.php',
            '2026_10_05_200000_create_mailtrap_and_email_delivery_events_tables.php',
            '2026_10_06_130000_create_email_sending_domains_and_audit_tables.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->tenant = Tenant::create(['name' => 'Campaign Workspace']);
        Http::preventStrayRequests();
        $this->mock(MailtrapDomainService::class, function ($mock) {
            $mock->shouldReceive('validateDomainForSending')->andReturn(['allowed' => true]);
        });
    }

    private function setting(): EmailSetting
    {
        return EmailSetting::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'mailtrap', 'is_active' => true,
            'from_email' => 'sender@example.test', 'from_name' => 'Campaign Sender',
            'mailtrap_api_token' => 'workspace-test-token',
        ]);
    }

    private function recipient(?string $messageId = 'provider-message-123'): EmailCampaignRecipient
    {
        $campaign = EmailCampaign::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'November Campaign', 'subject' => 'Campaign subject',
            'from_email' => 'sender@example.test', 'from_name' => 'Campaign Sender',
            'html_body' => '<p>Campaign content</p>', 'status' => 'sending',
        ]);

        return EmailCampaignRecipient::create([
            'tenant_id' => $this->tenant->id, 'campaign_id' => $campaign->id,
            'email' => 'recipient@example.test', 'provider' => 'mailtrap',
            'status' => $messageId ? 'sent' : 'queued',
            'provider_message_id' => $messageId,
        ]);
    }

    private function event(array $overrides = []): array
    {
        return array_merge([
            'event' => 'delivery', 'message_id' => 'provider-message-123',
            'event_id' => 'event-123', 'email' => 'recipient@example.test',
            'sending_stream' => 'bulk', 'sending_domain_name' => 'example.test',
            'timestamp' => time(),
        ], $overrides);
    }

    private function postWebhook(array $events, string $path = '/api/webhooks/mailtrap')
    {
        $body = json_encode(['events' => $events]);

        return $this->call('POST', $path, server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_MAILTRAP_SIGNATURE' => hash_hmac('sha256', $body, 'test-signing-secret'),
        ], content: $body);
    }

    public function test_campaign_send_uses_bulk_platform_token_and_tracks_signed_delivery(): void
    {
        $this->setting();
        $recipient = $this->recipient(null);
        Http::fake(['https://bulk.api.mailtrap.io/api/send' => Http::response(['success' => true, 'message_ids' => ['provider-message-123']])]);

        (new SendEmailBatchJob($recipient->campaign_id, [$recipient->id]))->handle();

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://bulk.api.mailtrap.io/api/send'
            && $request->hasHeader('Authorization', 'Bearer global-test-token')
            && $request['custom_variables']['tenant_id'] === (string) $this->tenant->id
            && $request['custom_variables']['campaign_id'] === (string) $recipient->campaign_id
            && $request['custom_variables']['recipient_id'] === (string) $recipient->id
            && $request['category'] === 'November Campaign');
        $this->assertSame('sent', $recipient->fresh()->status);
        $this->assertSame('provider-message-123', $recipient->fresh()->provider_message_id);

        $this->postWebhook([$this->event()])->assertOk()->assertJsonPath('events_received', 1);
        $this->assertSame('delivered', $recipient->fresh()->status);
        $this->assertNotNull($recipient->fresh()->delivered_at);
        $this->assertSame(1, $recipient->campaign->fresh()->delivered_count);
    }

    public function test_campaign_preview_uses_the_same_bulk_stream_and_token(): void
    {
        $this->setting();
        Http::fake(['https://bulk.api.mailtrap.io/api/send' => Http::response(['success' => true, 'message_ids' => ['preview-message']])]);
        $result = EmailCampaignService::sendTestEmail($this->tenant->id, ['test_email' => 'preview@example.test', 'subject' => 'Preview']);
        $this->assertTrue($result['success']);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://bulk.api.mailtrap.io/api/send'
            && $request->hasHeader('Authorization', 'Bearer global-test-token')
            && $request['category'] === 'Campaign Test');
    }

    public function test_connection_test_ignores_customer_tokens_and_uses_platform_bulk_stream(): void
    {
        $this->setting();
        $user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Administrator', 'email' => 'admin@example.test', 'password' => 'TestingPassword123!', 'role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($user);
        Http::fake(['https://bulk.api.mailtrap.io/api/send' => Http::response(['success' => true, 'message_ids' => ['connection-message']])]);
        $this->postJson('/api/email/settings/test', ['provider' => 'mailtrap', 'from_email' => 'sender@example.test', 'test_email' => 'preview@example.test', 'mailtrap_api_token' => 'request-override-token'])->assertOk()->assertJsonPath('message_id', 'connection-message');
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://bulk.api.mailtrap.io/api/send'
            && $request->hasHeader('Authorization', 'Bearer global-test-token'));
    }

    public function test_factory_uses_platform_credentials_despite_legacy_customer_credentials(): void
    {
        $setting = $this->setting();
        $setting->mailtrap_webhook_secret = 'legacy-customer-secret';
        $provider = EmailProviderFactory::make($setting);
        $this->assertInstanceOf(MailtrapEmailProvider::class, $provider);
        $this->assertSame('global-test-token', $provider->getApiToken());
        $this->assertSame('test-signing-secret', $provider->getWebhookSecret());
    }

    public function test_missing_platform_token_does_not_fall_back_to_customer_token_or_simulated_delivery(): void
    {
        $setting = $this->setting();
        config(['services.mailtrap.api_token' => null]);
        $provider = EmailProviderFactory::make($setting);
        $this->assertInstanceOf(MailtrapEmailProvider::class, $provider);
        $this->assertNull($provider->getApiToken());
        $result = $provider->send(['from_email' => 'sender@example.test', 'to_email' => 'recipient@example.test', 'text_body' => 'Test']);
        $this->assertFalse($result['success']);
        $this->assertFalse($setting->toSafeArray()['has_mailtrap_api_token']);
        Http::assertNothingSent();
    }

    public function test_legacy_smtp_and_log_provider_settings_cannot_bypass_platform_delivery(): void
    {
        $setting = $this->setting();
        foreach (['smtp', 'sendgrid', 'log'] as $legacyProvider) {
            $setting->provider = $legacyProvider;
            $provider = EmailProviderFactory::make($setting);
            $this->assertInstanceOf(MailtrapEmailProvider::class, $provider);
            $this->assertSame('global-test-token', $provider->getApiToken());
        }
    }

    public function test_domain_verification_uses_only_platform_token(): void
    {
        $this->setting();
        $service = new MailtrapDomainService();
        $this->assertSame('global-test-token', $service->resolveApiToken($this->tenant->id));
        config(['services.mailtrap.api_token' => null]);
        $this->expectException(\RuntimeException::class);
        $service->resolveApiToken($this->tenant->id);
    }

    public function test_customer_settings_cannot_store_mailtrap_credentials(): void
    {
        $user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Administrator', 'email' => 'admin@example.test', 'password' => 'TestingPassword123!', 'role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($user);
        $this->postJson('/api/email/settings', [
            'from_name' => 'Customer', 'from_email' => 'sender@example.test',
            'mailtrap_api_token' => 'customer-token', 'mailtrap_webhook_secret' => 'customer-secret',
        ])->assertUnprocessable()->assertJsonValidationErrors(['mailtrap_api_token', 'mailtrap_webhook_secret']);
        $this->postJson('/api/email/settings', [
            'from_name' => 'Customer', 'from_email' => 'sender@example.test',
        ])->assertOk()->assertJsonPath('data.provider', 'mailtrap');
    }

    public function test_mailtrap_message_ids_are_not_invented_when_provider_omits_them(): void
    {
        Http::fake(['https://bulk.api.mailtrap.io/api/send' => Http::response(['success' => true])]);
        $result = (new MailtrapEmailProvider(apiToken: 'test-token'))->send(['from_email' => 'sender@example.test', 'to_email' => 'recipient@example.test', 'text_body' => 'Test']);
        $this->assertTrue($result['success']);
        $this->assertNull($result['message_id']);
    }

    public function test_all_existing_mailtrap_webhook_urls_process_event_batches(): void
    {
        Queue::fake();
        foreach (['/api/webhooks/mailtrap', '/api/webhook/mailtrap', '/api/webhook/email/mailtrap'] as $path) {
            $this->postWebhook([$this->event()], $path)->assertOk()->assertJsonPath('events_received', 1);
        }
        Queue::assertPushed(ProcessMailtrapWebhookJob::class, 3);
    }

    public function test_webhook_signature_is_required_and_checked_before_dispatch(): void
    {
        Queue::fake();
        $this->postJson('/api/webhooks/mailtrap', ['events' => [$this->event()]])->assertUnauthorized();
        $this->postJson('/api/webhooks/mailtrap', ['events' => [$this->event()]], ['Mailtrap-Signature' => str_repeat('0', 64)])->assertUnauthorized();
        $this->postJson('/api/webhook/email/mailtrap', ['events' => [$this->event()]], ['Mailtrap-Signature' => str_repeat('0', 64)])->assertUnauthorized();
        Queue::assertNothingPushed();
    }

    public function test_signed_json_lines_are_supported(): void
    {
        Queue::fake();
        $body = json_encode($this->event())."\n".json_encode($this->event(['event_id' => 'event-456']))."\n";
        $this->call('POST', '/api/webhooks/mailtrap', server: [
            'CONTENT_TYPE' => 'application/x-ndjson', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_MAILTRAP_SIGNATURE' => hash_hmac('sha256', $body, 'test-signing-secret'),
        ], content: $body)->assertOk()->assertJsonPath('events_received', 2);
        Queue::assertPushed(ProcessMailtrapWebhookJob::class, fn ($job) => count($job->events) === 2);
    }

    public function test_malformed_signed_payload_is_rejected_instead_of_silently_acknowledged(): void
    {
        Queue::fake();
        $body = '{invalid-json';
        $this->call('POST', '/api/webhooks/mailtrap', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_MAILTRAP_SIGNATURE' => hash_hmac('sha256', $body, 'test-signing-secret'),
        ], content: $body)->assertStatus(400);
        $this->postWebhook(['invalid event'])->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_standalone_curl_email_cannot_update_an_unrelated_campaign(): void
    {
        $first = $this->recipient('campaign-message-a');
        $second = $this->recipient('campaign-message-b');
        $this->postWebhook([$this->event(['message_id' => 'standalone-curl-message'])])->assertOk();
        $this->assertSame('sent', $first->fresh()->status);
        $this->assertSame('sent', $second->fresh()->status);
        $this->assertSame(0, EmailDeliveryEvent::count());
        $this->postWebhook([$this->event(['message_id' => 'message-a'])])->assertOk();
        $this->assertSame('sent', $first->fresh()->status);
    }

    public function test_metadata_correlates_early_events_and_cannot_override_another_send(): void
    {
        $recipient = $this->recipient(null);
        $metadata = ['recipient_id' => (string) $recipient->id, 'campaign_id' => (string) $recipient->campaign_id, 'tenant_id' => (string) $this->tenant->id];
        $this->postWebhook([$this->event(['custom_variables' => $metadata])])->assertOk();
        $this->assertSame('delivered', $recipient->fresh()->status);
        $other = $this->recipient('different-send');
        $metadata['recipient_id'] = (string) $other->id;
        $metadata['campaign_id'] = (string) $other->campaign_id;
        $this->postWebhook([$this->event(['event_id' => 'unrelated-event', 'custom_variables' => $metadata])])->assertOk();
        $this->assertSame('sent', $other->fresh()->status);
    }

    public function test_duplicate_delivery_event_is_idempotent(): void
    {
        $recipient = $this->recipient();
        $this->postWebhook([$this->event()])->assertOk();
        $this->postWebhook([$this->event()])->assertOk();
        $this->assertSame(1, EmailDeliveryEvent::count());
        $this->assertSame(1, $recipient->campaign->fresh()->delivered_count);
    }

    public function test_mailtrap_soft_bounce_event_name_with_space_is_processed(): void
    {
        $recipient = $this->recipient();
        $this->postWebhook([$this->event(['event' => 'soft bounce', 'response' => 'Try again later'])])->assertOk();
        $this->assertSame('bounced', $recipient->fresh()->status);
        $this->assertSame('soft', $recipient->fresh()->bounce_type);
        $this->assertSame('soft_bounce', EmailDeliveryEvent::firstOrFail()->event_type);
    }

    public function test_fast_delivery_webhook_is_not_overwritten_when_send_returns(): void
    {
        $this->setting();
        $recipient = $this->recipient(null);
        Http::fake(['https://bulk.api.mailtrap.io/api/send' => function (ClientRequest $request) {
            (new ProcessMailtrapWebhookJob([$this->event(['custom_variables' => $request['custom_variables']])]))->handle();

            return Http::response(['success' => true, 'message_ids' => ['provider-message-123']]);
        }]);
        (new SendEmailBatchJob($recipient->campaign_id, [$recipient->id]))->handle();
        $this->assertSame('delivered', $recipient->fresh()->status);
        $this->assertSame(1, $recipient->campaign->fresh()->delivered_count);
    }
}
