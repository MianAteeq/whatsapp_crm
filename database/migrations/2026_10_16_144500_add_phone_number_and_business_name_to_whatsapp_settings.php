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
            if (!Schema::hasColumn('whatsapp_settings', 'phone_number')) {
                $table->string('phone_number')->nullable()->after('business_account_id');
            }
            if (!Schema::hasColumn('whatsapp_settings', 'business_name')) {
                $table->string('business_name')->nullable()->after('phone_number');
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
            if (Schema::hasColumn('whatsapp_settings', 'phone_number')) {
                $columnsToDrop[] = 'phone_number';
            }
            if (Schema::hasColumn('whatsapp_settings', 'business_name')) {
                $columnsToDrop[] = 'business_name';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
