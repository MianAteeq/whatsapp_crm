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
        Schema::create('whatsapp_billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('whatsapp_account_id')
                ->nullable()
                ->constrained('whatsapp_settings')
                ->nullOnDelete();

            $table->string('waba_id')->index();
            $table->string('business_id')->nullable()->index();
            $table->string('billing_status', 50)->default('Not Configured')->index();
            $table->string('currency', 10)->nullable();
            $table->string('country', 10)->nullable();
            $table->string('payment_method_status', 50)->nullable();
            $table->string('last_error_code', 50)->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('last_error_details')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_successful_message_at')->nullable();
            $table->timestamps();

            // Unique and performance indexes
            $table->unique(['tenant_id', 'waba_id'], 'tenant_waba_unique');
            $table->index(['tenant_id', 'billing_status'], 'tenant_billing_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_billing_profiles');
    }
};

