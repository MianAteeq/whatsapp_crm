<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Page and Form Builder Module.
     */
    public function up(): void
    {
        // 1. Landing Pages and Micro-Websites
        Schema::create('builder_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->string('category', 60)->default('landing_page'); // landing_page, microsite, portfolio, etc.
            $table->string('status', 30)->default('draft'); // draft, published, archived
            $table->json('theme')->nullable(); // colors, font_family, container_width, custom_styles
            $table->json('sections')->nullable(); // Array of customizable section blocks
            $table->json('seo_settings')->nullable(); // meta_title, meta_description, keywords, og_image, favicon
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('submission_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'created_at'], 'idx_bp_tenant_status_created');
            $table->index(['slug'], 'idx_bp_slug');
        });

        // 2. Forms and Surveys
        Schema::create('builder_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('builder_pages')->nullOnDelete();
            $table->string('title', 255);
            $table->string('slug', 120)->unique();
            $table->string('form_type', 40)->default('lead_form'); // lead_form, survey, contact_form, registration
            $table->string('status', 30)->default('draft'); // draft, published, archived
            $table->json('schema')->nullable(); // steps, fields, validation, conditional rules
            $table->json('settings')->nullable(); // submit_text, redirect_url, thank_you_message, theme
            $table->json('crm_mapping')->nullable(); // field_mappings, auto_tags, assigned_user_id, duplicate_strategy
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('submission_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'form_type', 'status'], 'idx_bf_tenant_type_status');
            $table->index(['slug'], 'idx_bf_slug');
        });

        // 3. Submissions and Survey Responses
        Schema::create('builder_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('form_id')->constrained('builder_forms')->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('builder_pages')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->json('data'); // submitted key-value fields
            $table->json('survey_results')->nullable(); // question scores, NPS, completion times
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('referer')->nullable();
            $table->json('utm_params')->nullable(); // utm_source, utm_medium, utm_campaign
            $table->string('status', 30)->default('new'); // new, contact_created, contact_updated, spam
            $table->timestamps();

            $table->index(['tenant_id', 'form_id', 'created_at'], 'idx_bs_tenant_form_created');
            $table->index(['tenant_id', 'contact_id'], 'idx_bs_tenant_contact');
        });

        // 4. Pre-built Templates Library
        Schema::create('builder_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('category', 60)->default('saas'); // saas, agency, medical, real_estate, consulting, fitness
            $table->string('template_type', 30)->default('page'); // page, form, survey
            $table->string('thumbnail_url', 500)->nullable();
            $table->text('description')->nullable();
            $table->json('content'); // Default sections or schema
            $table->boolean('is_system')->default(true);
            $table->timestamps();

            $table->index(['category', 'template_type'], 'idx_bt_cat_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('builder_templates');
        Schema::dropIfExists('builder_submissions');
        Schema::dropIfExists('builder_forms');
        Schema::dropIfExists('builder_pages');
    }
};

