<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add enrichment & phone uniqueness columns to contacts
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('phone_normalized', 50)->nullable()->after('phone')->index();
            $table->string('phone_original', 50)->nullable()->after('phone_normalized');
            $table->string('google_place_id', 191)->nullable()->after('phone_original')->index();
            $table->string('website_domain', 191)->nullable()->after('website')->index();
            $table->string('secondary_phone', 50)->nullable()->after('phone_original');
            $table->string('secondary_phone_normalized', 50)->nullable()->after('secondary_phone');
            $table->string('secondary_email', 191)->nullable()->after('email');
            $table->unsignedTinyInteger('lead_quality_score')->default(0)->after('status');
            $table->string('lead_quality_grade', 5)->default('D')->after('lead_quality_score');
            $table->string('enrichment_status', 30)->default('NEW')->after('lead_quality_grade')->index();
            $table->json('missing_fields')->nullable()->after('enrichment_status');
            $table->string('source', 100)->default('CRM')->after('missing_fields');
            $table->json('source_details')->nullable()->after('source');
            $table->timestamp('last_scraped_at')->nullable()->after('source_details');
            $table->timestamp('last_enriched_at')->nullable()->after('last_scraped_at');
            $table->unsignedSmallInteger('enrichment_attempts')->default(0)->after('last_enriched_at');
        });

        // 2. Add normalization and quality fields to scraped_businesses
        Schema::table('scraped_businesses', function (Blueprint $table) {
            $table->string('phone_original', 50)->nullable()->after('phone');
            $table->string('phone_normalized', 50)->nullable()->after('phone_original')->index();
            $table->unsignedTinyInteger('lead_quality_score')->default(0)->after('status');
            $table->string('lead_quality_grade', 5)->default('D')->after('lead_quality_score');
            $table->string('enrichment_status', 30)->default('NEW')->after('lead_quality_grade')->index();
            $table->json('missing_fields')->nullable()->after('enrichment_status');
            $table->json('source_details')->nullable()->after('missing_fields');
            $table->timestamp('last_enriched_at')->nullable()->after('source_details');
            $table->unsignedSmallInteger('enrichment_attempts')->default(0)->after('last_enriched_at');
        });

        // 3. Create contact_enrichment_logs table for comprehensive audit history
        Schema::create('contact_enrichment_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('contact_id')->index();
            $table->unsignedBigInteger('scraped_business_id')->nullable()->index();
            $table->unsignedBigInteger('search_id')->nullable()->index();
            $table->string('field_name', 50)->index();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('source', 100)->default('Scraper Enrichment');
            $table->timestamps();

            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->foreign('scraped_business_id')->references('id')->on('scraped_businesses')->onDelete('set null');
            $table->index(['tenant_id', 'contact_id', 'created_at'], 'idx_enrich_tenant_contact_date');
        });

        // 4. Backfill phone_normalized for existing contacts and enforce uniqueness index
        $contacts = DB::table('contacts')->select('id', 'tenant_id', 'phone')->get();
        $seen = [];
        foreach ($contacts as $c) {
            $raw = trim((string) $c->phone);
            if (empty($raw)) continue;

            $digits = preg_replace('/[^0-9]/', '', $raw);
            $normalized = $raw;
            if (str_starts_with($raw, '+')) {
                $normalized = '+' . $digits;
            } elseif (str_starts_with($raw, '00')) {
                $normalized = '+' . substr($digits, 2);
            } elseif (str_starts_with($raw, '03') && strlen($digits) === 11) {
                // Pakistan local mobile format
                $normalized = '+92' . substr($digits, 1);
            } elseif (str_starts_with($raw, '0') && strlen($digits) >= 10) {
                $normalized = '+' . substr($digits, 1);
            } elseif (strlen($digits) >= 10) {
                $normalized = '+' . $digits;
            }

            // Deduplicate if already seen for this tenant to avoid unique constraint failure
            $key = "{$c->tenant_id}_{$normalized}";
            if (isset($seen[$key])) {
                // Keep phone_original but make normalized null for old duplicate artifact
                DB::table('contacts')->where('id', $c->id)->update([
                    'phone_original' => $raw,
                    'phone_normalized' => null,
                ]);
            } else {
                $seen[$key] = true;
                DB::table('contacts')->where('id', $c->id)->update([
                    'phone_original' => $raw,
                    'phone_normalized' => $normalized,
                ]);
            }
        }

        // Add database-level unique index on [tenant_id, phone_normalized]
        Schema::table('contacts', function (Blueprint $table) {
            $table->unique(['tenant_id', 'phone_normalized'], 'uniq_contact_tenant_phone_normalized');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_enrichment_logs');

        Schema::table('scraped_businesses', function (Blueprint $table) {
            $table->dropColumn([
                'phone_original',
                'phone_normalized',
                'lead_quality_score',
                'lead_quality_grade',
                'enrichment_status',
                'missing_fields',
                'source_details',
                'last_enriched_at',
                'enrichment_attempts',
            ]);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('uniq_contact_tenant_phone_normalized');
            $table->dropColumn([
                'phone_normalized',
                'phone_original',
                'google_place_id',
                'website_domain',
                'secondary_phone',
                'secondary_phone_normalized',
                'secondary_email',
                'lead_quality_score',
                'lead_quality_grade',
                'enrichment_status',
                'missing_fields',
                'source',
                'source_details',
                'last_scraped_at',
                'last_enriched_at',
                'enrichment_attempts',
            ]);
        });
    }
};
