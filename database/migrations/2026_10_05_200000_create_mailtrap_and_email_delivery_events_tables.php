<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add Mailtrap & event fields to email_settings
        Schema::table('email_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('email_settings', 'mailtrap_api_token')) {
                $table->text('mailtrap_api_token')->nullable()->after('deepseek_api_key');
            }
            if (!Schema::hasColumn('email_settings', 'mailtrap_webhook_secret')) {
                $table->text('mailtrap_webhook_secret')->nullable()->after('mailtrap_api_token');
            }
            if (!Schema::hasColumn('email_settings', 'mailtrap_domain')) {
                $table->string('mailtrap_domain', 191)->nullable()->after('mailtrap_webhook_secret');
            }
            if (!Schema::hasColumn('email_settings', 'mailtrap_inbox_id')) {
                $table->string('mailtrap_inbox_id', 191)->nullable()->after('mailtrap_domain');
            }
        });

        // 2. Add delivery tracking fields to email_campaign_recipients
        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            if (!Schema::hasColumn('email_campaign_recipients', 'provider')) {
                $table->string('provider', 50)->nullable()->default('mailtrap')->after('status');
            }
            if (!Schema::hasColumn('email_campaign_recipients', 'deferred_at')) {
                $table->timestamp('deferred_at')->nullable()->after('delivered_at');
            }
            if (!Schema::hasColumn('email_campaign_recipients', 'bounced_at')) {
                $table->timestamp('bounced_at')->nullable()->after('deferred_at');
            }
            if (!Schema::hasColumn('email_campaign_recipients', 'complained_at')) {
                $table->timestamp('complained_at')->nullable()->after('bounced_at');
            }
            if (!Schema::hasColumn('email_campaign_recipients', 'unsubscribed_at')) {
                $table->timestamp('unsubscribed_at')->nullable()->after('complained_at');
            }
            if (!Schema::hasColumn('email_campaign_recipients', 'bounce_type')) {
                $table->string('bounce_type', 50)->nullable()->after('unsubscribed_at');
            }
            if (!Schema::hasColumn('email_campaign_recipients', 'bounce_reason')) {
                $table->text('bounce_reason')->nullable()->after('bounce_type');
            }
            if (!Schema::hasColumn('email_campaign_recipients', 'last_event_at')) {
                $table->timestamp('last_event_at')->nullable()->after('bounce_reason');
            }
        });

        // 3. Create dedicated email_delivery_events table for webhook audit & idempotency
        if (!Schema::hasTable('email_delivery_events')) {
            Schema::create('email_delivery_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('campaign_id')->nullable()->index();
                $table->unsignedBigInteger('recipient_id')->nullable()->index();
                $table->string('provider', 50)->default('mailtrap')->index();
                $table->string('provider_message_id', 191)->nullable()->index();
                $table->string('provider_event_id', 191)->nullable()->index();
                $table->string('event_type', 50)->index(); // delivery, bounce, soft_bounce, spam_complaint, unsubscribe, open, click, etc.
                $table->timestamp('event_timestamp')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->foreign('campaign_id')->references('id')->on('email_campaigns')->onDelete('cascade');
                $table->foreign('recipient_id')->references('id')->on('email_campaign_recipients')->onDelete('cascade');

                // Idempotency: prevent processing the exact same provider event twice
                $table->unique(['provider', 'provider_event_id'], 'uniq_delivery_event_provider_event');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_delivery_events');

        Schema::table('email_campaign_recipients', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'deferred_at',
                'bounced_at',
                'complained_at',
                'unsubscribed_at',
                'bounce_type',
                'bounce_reason',
                'last_event_at'
            ]);
        });

        Schema::table('email_settings', function (Blueprint $table) {
            $table->dropColumn([
                'mailtrap_api_token',
                'mailtrap_webhook_secret',
                'mailtrap_domain',
                'mailtrap_inbox_id'
            ]);
        });
    }
};
