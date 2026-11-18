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
            $table->text('access_token')->nullable()->change();
            $table->string('phone_number_id')->nullable()->change();
            $table->string('business_account_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->text('access_token')->nullable(false)->change();
            $table->string('phone_number_id')->nullable(false)->change();
            $table->string('business_account_id')->nullable(false)->change();
        });
    }
};

