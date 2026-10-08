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
        // 1. Email Provider & Sender Settings
        Schema::create('email_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('provider', 50)->default('smtp'); // smtp, sendgrid, ses, mailgun, log
            $table->string('from_name', 191);
            $table->string('from_email', 191);
            $table->string('reply_to', 191)->nullable();
            
            // SMTP settings
            $table->string('smtp_host', 191)->nullable();
            $table->unsignedInteger('smtp_port')->nullable()->default(587);
            $table->string('smtp_encryption', 20)->nullable()->default('tls'); // tls, ssl, none
            $table->string('smtp_username', 191)->nullable();
            $table->text('smtp_password')->nullable(); // encrypted
            
            // API-based provider settings
            $table->text('api_key')->nullable(); // encrypted
            $table->string('api_domain', 191)->nullable(); // Mailgun domain, SES region, etc.
            
            // Sending limits
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->unsignedInteger('daily_limit')->default(5000);
            $table->boolean('is_active')->default(true);
            $table->json('extra_config')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        // 2. Email Templates
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 191);
            $table->string('subject', 255);
            $table->string('category', 50)->default('General'); // Sales, Introduction, Follow-up, Appointment, Newsletter, General
            $table->longText('html_body');
            $table->longText('text_body')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'category']);
        });

        // 3. Email Campaigns
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('name', 255);
            $table->string('subject', 255);
            $table->string('from_name', 191);
            $table->string('from_email', 191);
            $table->string('reply_to', 191)->nullable();
            $table->unsignedBigInteger('template_id')->nullable()->index();
            $table->longText('html_body');
            $table->longText('text_body')->nullable();
            $table->json('audience_filter')->nullable();
            
            // Statuses: draft, scheduled, sending, completed, paused, cancelled, failed
            $table->string('status', 30)->default('draft')->index();
            
            // Metric counts
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);
            
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('template_id')->references('id')->on('email_templates')->onDelete('set null');
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        // 4. Email Campaign Recipients (with idempotency & delivery tracking)
        Schema::create('email_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('email', 191)->index();
            
            // Statuses: pending, queued, sending, sent, delivered, bounced, failed, skipped, unsubscribed
            $table->string('status', 30)->default('pending')->index();
            $table->string('provider_message_id', 191)->nullable()->index();
            $table->string('personalized_subject', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('campaign_id')->references('id')->on('email_campaigns')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('set null');
            
            // Unique constraints for idempotency (deduplicate contacts & email within the campaign)
            $table->unique(['campaign_id', 'contact_id'], 'uniq_camp_recipient_contact');
            $table->unique(['campaign_id', 'email'], 'uniq_camp_recipient_email');
            $table->index(['campaign_id', 'status']);
        });

        // 5. Global Suppressions / Unsubscribes
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('email', 191)->index();
            $table->string('reason', 50)->default('unsubscribed'); // unsubscribed, hard_bounce, spam_complaint, manually_suppressed
            $table->text('details')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('set null');
            $table->unique(['tenant_id', 'email'], 'uniq_suppression_tenant_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('email_campaign_recipients');
        Schema::dropIfExists('email_campaigns');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('email_settings');
    }
};
