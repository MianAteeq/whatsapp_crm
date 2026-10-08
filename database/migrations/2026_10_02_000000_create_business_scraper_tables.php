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
        // 1. Scraper Searches Table
        Schema::create('scraper_searches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('keyword');
            $table->string('location');
            $table->integer('radius')->nullable()->comment('Search radius in meters or km');
            $table->integer('max_results')->default(100);
            $table->string('status', 30)->default('queued')->index()->comment('queued, processing, completed, failed, cancelled');
            $table->integer('total_found')->default(0);
            $table->integer('total_processed')->default(0);
            $table->integer('total_emails_found')->default(0);
            $table->integer('total_duplicates')->default(0);
            $table->integer('total_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        // 2. Scraped Businesses Table
        Schema::create('scraped_businesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('search_id')->index();
            $table->string('google_place_id', 191)->nullable()->index();
            $table->string('business_name');
            $table->string('category')->nullable();
            $table->text('website')->nullable();
            $table->string('website_domain', 191)->nullable()->index();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->text('google_maps_url')->nullable();
            $table->string('email', 191)->nullable();
            $table->string('email_normalized', 191)->nullable()->index();
            $table->string('source', 100)->default('Google Maps / Business Scraper');
            $table->string('status', 30)->default('pending')->index()->comment('pending, email_found, no_website, no_email_found, failed, imported');
            $table->boolean('is_imported_to_crm')->default(false)->index();
            $table->unsignedBigInteger('crm_contact_id')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            // Foreign key to search
            $table->foreign('search_id')->references('id')->on('scraper_searches')->onDelete('cascade');

            // Prevent duplicate Google Place for the same tenant
            $table->unique(['tenant_id', 'google_place_id'], 'uniq_scraped_tenant_place');

            // Composite indexes for fast query and duplicate checks
            $table->index(['tenant_id', 'email_normalized'], 'idx_scraped_tenant_email');
            $table->index(['tenant_id', 'website_domain'], 'idx_scraped_tenant_domain');
        });

        // 3. Scraper Business Emails Table (Relational normalization for multiple emails)
        Schema::create('scraper_business_emails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scraped_business_id')->index();
            $table->string('email');
            $table->string('email_normalized', 191)->index();
            $table->boolean('is_primary')->default(false);
            $table->string('source_page', 255)->nullable();
            $table->timestamps();

            $table->foreign('scraped_business_id')->references('id')->on('scraped_businesses')->onDelete('cascade');
        });

        // 4. Scraper Jobs Table
        Schema::create('scraper_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('search_id')->index();
            $table->string('status', 30)->default('pending')->index()->comment('pending, running, completed, failed, cancelled');
            $table->integer('total')->default(0);
            $table->integer('processed')->default(0);
            $table->integer('successful')->default(0);
            $table->integer('failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('search_id')->references('id')->on('scraper_searches')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scraper_jobs');
        Schema::dropIfExists('scraper_business_emails');
        Schema::dropIfExists('scraped_businesses');
        Schema::dropIfExists('scraper_searches');
    }
};
