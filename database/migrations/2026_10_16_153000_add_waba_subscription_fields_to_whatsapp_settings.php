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
            if (!Schema::hasColumn('whatsapp_settings', 'is_waba_subscribed')) {
                $table->boolean('is_waba_subscribed')->default(false)->after('is_registered');
            }
            if (!Schema::hasColumn('whatsapp_settings', 'waba_subscription_error')) {
                $table->text('waba_subscription_error')->nullable()->after('is_waba_subscribed');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('whatsapp_settings', 'is_waba_subscribed')) {
                $columnsToDrop[] = 'is_waba_subscribed';
            }
            if (Schema::hasColumn('whatsapp_settings', 'waba_subscription_error')) {
                $columnsToDrop[] = 'waba_subscription_error';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
