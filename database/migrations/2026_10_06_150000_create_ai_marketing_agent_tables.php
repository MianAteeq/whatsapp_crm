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
        // 1. Marketing Knowledge & Autonomous Safety Limits
        if (!Schema::hasTable('marketing_knowledge')) {
            Schema::create('marketing_knowledge', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->string('company_name', 191)->nullable();
                $table->text('company_description')->nullable();
                $table->string('brand_voice', 100)->default('Professional, consultative, and outcome-focused');
                $table->string('website', 255)->nullable();
                $table->string('demo_link', 255)->nullable();
                $table->string('booking_link', 255)->nullable();
                $table->string('contact_email', 191)->nullable();
                $table->string('contact_phone', 50)->nullable();
                $table->text('pricing_overview')->nullable();
                $table->json('case_studies')->nullable();
                $table->json('target_industries')->nullable();
                
                // Autonomous Safety Limits (Requirement 14 & 15)
                $table->enum('approval_mode', ['manual', 'approval', 'autonomous'])->default('approval');
                $table->unsignedInteger('max_emails_per_campaign')->default(500);
                $table->unsignedInteger('max_emails_per_day')->default(1000);
                $table->unsignedInteger('min_hours_between_campaigns')->default(24);
                $table->unsignedInteger('cooldown_days_per_contact')->default(7);
                $table->unsignedInteger('require_approval_above_recipients')->default(250);
                $table->json('allowed_categories')->nullable();
                $table->json('allowed_services')->nullable();
                $table->json('allowed_sending_domain_ids')->nullable();

                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }

        // 2. Marketing Services Catalog (Requirement 5)
        if (!Schema::hasTable('marketing_services')) {
            Schema::create('marketing_services', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('name', 191);
                $table->string('slug', 191);
                $table->text('description')->nullable();
                $table->json('target_industries')->nullable(); // e.g. ["Real Estate", "Dental", "Automotive"]
                $table->json('features')->nullable(); // e.g. ["24/7 lead capture", "CRM sync"]
                $table->json('benefits')->nullable(); // e.g. ["Reduced response time", "Higher conversion"]
                $table->string('pricing', 255)->nullable();
                $table->string('cta_text', 100)->default('Book a Demo');
                $table->string('cta_url', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->unique(['tenant_id', 'slug'], 'uniq_tenant_service_slug');
            });
        }

        // 3. AI Campaign Agent Runs (Requirement 30)
        if (!Schema::hasTable('ai_campaign_agent_runs')) {
            Schema::create('ai_campaign_agent_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('campaign_id')->nullable()->index();
                $table->text('user_request');
                $table->json('interpreted_intent')->nullable(); // category, location, service, objective, channel, action
                $table->string('approval_mode', 30)->default('approval'); // manual, approval, autonomous
                $table->string('status', 30)->default('planning')->index(); // planning, awaiting_approval, approved, running, completed, failed, cancelled
                $table->json('actions_taken')->nullable(); // chronological step log
                $table->unsignedInteger('recipients_selected')->default(0);
                $table->unsignedInteger('recipients_excluded')->default(0);
                $table->json('audience_summary')->nullable(); // breakdown of reasons
                $table->json('campaign_plan')->nullable(); // subject, preheader, from, cta, html_preview
                $table->json('performance_analysis')->nullable(); // post-campaign analysis
                $table->text('errors')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
                $table->foreign('campaign_id')->references('id')->on('email_campaigns')->onDelete('set null');
            });
        }

        // 4. AI Campaign Agent Actions (Detailed tool invocations & activity audit)
        if (!Schema::hasTable('ai_campaign_agent_actions')) {
            Schema::create('ai_campaign_agent_actions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('run_id')->index();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('action_type', 50)->index(); // tool_call, validation, approval, queue, analysis
                $table->string('tool_name', 100)->nullable();
                $table->json('input_payload')->nullable();
                $table->json('output_payload')->nullable();
                $table->string('status', 30)->default('success'); // success, failed, skipped
                $table->text('message')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('run_id')->references('id')->on('ai_campaign_agent_runs')->onDelete('cascade');
                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_campaign_agent_actions');
        Schema::dropIfExists('ai_campaign_agent_runs');
        Schema::dropIfExists('marketing_services');
        Schema::dropIfExists('marketing_knowledge');
    }
};
