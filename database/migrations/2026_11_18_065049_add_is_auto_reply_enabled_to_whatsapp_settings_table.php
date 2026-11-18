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
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_settings', 'is_auto_reply_enabled')) {
                $table->boolean('is_auto_reply_enabled')->default(true)->after('reply_language');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_settings', 'is_auto_reply_enabled')) {
                $table->dropColumn('is_auto_reply_enabled');
            }
        });
    }
};
