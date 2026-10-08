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
            $table->string('search_mode', 50)->default('standard')->after('lead_volume');
            $table->string('phase', 50)->default('discovery')->after('status');
            $table->integer('zones_total')->default(0)->after('phase');
            $table->integer('zones_completed')->default(0)->after('zones_total');
            $table->boolean('coverage_exhausted')->default(false)->after('zones_completed');
            $table->string('exhaustion_note')->nullable()->after('coverage_exhausted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scraper_searches', function (Blueprint $table) {
            $table->dropColumn([
                'search_mode',
                'phase',
                'zones_total',
                'zones_completed',
                'coverage_exhausted',
                'exhaustion_note',
            ]);
        });
    }
};
