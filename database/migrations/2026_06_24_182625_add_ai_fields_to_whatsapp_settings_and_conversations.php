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
            $table->string('openai_key')->nullable()->after('messaging_limit_tier');
            $table->text('company_prompt')->nullable()->after('openai_key');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('is_auto_reply_active')->default(true)->after('unread_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropColumn(['openai_key', 'company_prompt']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('is_auto_reply_active');
        });
    }
};
