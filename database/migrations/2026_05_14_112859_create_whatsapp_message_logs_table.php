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
        Schema::create('whatsapp_message_logs', function (Blueprint $table) {

            $table->id();



            // ======================================
            // TENANT
            // ======================================

            $table->unsignedBigInteger('tenant_id');



            // ======================================
            // RELATIONS
            // ======================================

            $table->unsignedBigInteger('campaign_id')->nullable();

            $table->unsignedBigInteger('contact_id')->nullable();



            // ======================================
            // MESSAGE IDS
            // ======================================

            $table->string('message_id')->nullable()->index();



            // ======================================
            // TEMPLATE
            // ======================================

            $table->string('template_name')->nullable();



            // ======================================
            // RECIPIENT
            // ======================================

            $table->string('recipient')->nullable();



            // ======================================
            // STATUS
            // ======================================

            $table->enum('status', [

                'queued',

                'sent',

                'delivered',

                'read',

                'failed',

                'replied'

            ])->default('queued');



            // ======================================
            // TRACKING TIMES
            // ======================================

            $table->timestamp('sent_at')->nullable();

            $table->timestamp('delivered_at')->nullable();

            $table->timestamp('read_at')->nullable();

            $table->timestamp('failed_at')->nullable();

            $table->timestamp('replied_at')->nullable();



            // ======================================
            // ERROR
            // ======================================

            $table->text('error_message')->nullable();



            // ======================================
            // RAW PAYLOAD
            // ======================================

            $table->json('payload')->nullable();



            $table->timestamps();



            // ======================================
            // INDEXES
            // ======================================

            $table->index('tenant_id');

            $table->index('campaign_id');

            $table->index('contact_id');

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_logs');
    }
};
