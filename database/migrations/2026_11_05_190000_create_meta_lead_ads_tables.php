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
        // 1. Meta Lead Forms Table
        Schema::create('meta_lead_forms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('social_account_id');
            $table->string('form_id', 100);
            $table->string('page_id', 100);
            $table->string('ad_account_id', 100)->nullable();
            $table->string('name', 255);
            $table->string('status', 50)->default('ACTIVE'); // ACTIVE, ARCHIVED, PAUSED, DELETED
            $table->string('locale', 20)->nullable();
            $table->json('questions')->nullable();           // Discovered fields/questions structure
            $table->json('crm_mapping')->nullable();         // Form field key -> CRM contact column
            $table->json('rules')->nullable();               // pipeline, stage_id, assigned_user_id, auto_tags, duplicate_strategy
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_sync')->default(true);
            $table->unsignedInteger('submissions_count')->default(0);
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->onDelete('cascade');
            $table->unique(['tenant_id', 'form_id']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['page_id', 'form_id']);
        });

        // 2. Meta Lead Submissions Audit Log Table
        Schema::create('meta_lead_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('meta_lead_form_id')->nullable();
            $table->string('leadgen_id', 100);
            $table->string('form_id', 100);
            $table->string('page_id', 100);
            $table->string('ad_id', 100)->nullable();
            $table->string('ad_name', 255)->nullable();
            $table->string('adset_id', 100)->nullable();
            $table->string('adset_name', 255)->nullable();
            $table->string('campaign_id', 100)->nullable();
            $table->string('campaign_name', 255)->nullable();
            $table->string('platform', 30)->default('facebook'); // facebook or instagram
            $table->json('raw_payload');
            $table->json('mapped_data')->nullable();
            $table->unsignedBigInteger('crm_contact_id')->nullable();
            $table->string('sync_status', 30)->default('pending'); // synced, duplicate, failed, pending
            $table->text('error_message')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('meta_lead_form_id')->references('id')->on('meta_lead_forms')->onDelete('set null');
            $table->foreign('crm_contact_id')->references('id')->on('contacts')->onDelete('set null');
            $table->unique(['tenant_id', 'leadgen_id']);
            $table->index(['tenant_id', 'sync_status']);
            $table->index(['form_id', 'leadgen_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meta_lead_submissions');
        Schema::dropIfExists('meta_lead_forms');
    }
};

