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
        if (Schema::hasTable('whatsapp_settings') && !Schema::hasColumn('whatsapp_settings', 'reply_language')) {
            Schema::table('whatsapp_settings', function (Blueprint $table) {
                // Arabic is the default for new auto-reply configurations
                $table->string('reply_language', 10)->default('ar-SA')->after('company_prompt');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('whatsapp_settings') && Schema::hasColumn('whatsapp_settings', 'reply_language')) {
            Schema::table('whatsapp_settings', function (Blueprint $table) {
                $table->dropColumn('reply_language');
            });
        }
    }
};
