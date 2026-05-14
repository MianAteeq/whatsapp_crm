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

            $table->unsignedBigInteger('tenant_id');

            $table->string('message_id')->nullable();

            $table->string('template_name')->nullable();

            $table->string('recipient')->nullable();

            $table->enum('status', [
                'sent',
                'delivered',
                'read',
                'failed',
                'replied'
            ])->default('sent');

            $table->timestamps();
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
