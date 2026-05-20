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
        Schema::create('campaign_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id');

            $table->foreignId('contact_id');

            $table->string('status')->default('pending');

            $table->string('message_id')->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->timestamp('delivered_at')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_contacts');
    }
};
