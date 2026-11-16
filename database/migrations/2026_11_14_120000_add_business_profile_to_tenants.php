<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('logo_path')->nullable()->after('legal_name');
            $table->string('address_line_1')->nullable()->after('logo_path');
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('city')->nullable()->after('address_line_2');
            $table->string('region')->nullable()->after('city');
            $table->string('postal_code', 30)->nullable()->after('region');
            $table->string('country_code', 2)->nullable()->after('postal_code');
            $table->string('currency_code', 3)->default('USD')->after('country_code');
            $table->string('timezone')->default('UTC')->after('currency_code');
            $table->string('customer_timezone_policy', 40)->default('customer_when_available')->after('timezone');
        });

        Schema::create('business_profile_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->json('changes');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profile_audit_logs');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name', 'logo_path', 'address_line_1', 'address_line_2',
                'city', 'region', 'postal_code', 'country_code', 'currency_code',
                'timezone', 'customer_timezone_policy',
            ]);
        });
    }
};
