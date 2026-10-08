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
        Schema::table('scraped_businesses', function (Blueprint $table) {
            // Drop tenant-wide place index so new searches are not starved of previously seen places
            $table->dropUnique('uniq_scraped_tenant_place');

            // Enforce strict uniqueness per search
            $table->unique(['search_id', 'google_place_id'], 'uniq_scraped_search_place');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scraped_businesses', function (Blueprint $table) {
            $table->dropUnique('uniq_scraped_search_place');
            $table->unique(['tenant_id', 'google_place_id'], 'uniq_scraped_tenant_place');
        });
    }
};
