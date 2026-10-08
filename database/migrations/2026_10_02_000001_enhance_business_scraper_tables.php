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
        Schema::table('scraper_searches', function (Blueprint $table) {
            $table->string('industry')->nullable()->after('keyword');
            $table->integer('lead_volume')->nullable()->after('max_results');
            $table->integer('websites_found')->default(0)->after('total_found');
            $table->integer('duplicates_removed')->default(0)->after('total_duplicates');
            $table->integer('no_email')->default(0)->after('total_emails_found');
            $table->json('options')->nullable()->after('error_message');
        });

        Schema::table('scraped_businesses', function (Blueprint $table) {
            $table->string('normalized_business_name')->nullable()->after('business_name')->index();
            $table->string('normalized_domain')->nullable()->after('website_domain')->index();
            $table->decimal('rating', 3, 2)->nullable()->after('google_maps_url');
            $table->integer('review_count')->nullable()->after('rating');
            $table->string('email_source', 100)->nullable()->after('email_normalized');
        });

        Schema::table('scraper_business_emails', function (Blueprint $table) {
            $table->string('source', 100)->nullable()->after('source_page');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scraper_business_emails', function (Blueprint $table) {
            $table->dropColumn(['source']);
        });

        Schema::table('scraped_businesses', function (Blueprint $table) {
            $table->dropColumn([
                'normalized_business_name',
                'normalized_domain',
                'rating',
                'review_count',
                'email_source',
            ]);
        });

        Schema::table('scraper_searches', function (Blueprint $table) {
            $table->dropColumn([
                'industry',
                'lead_volume',
                'websites_found',
                'duplicates_removed',
                'no_email',
                'options',
            ]);
        });
    }
};
