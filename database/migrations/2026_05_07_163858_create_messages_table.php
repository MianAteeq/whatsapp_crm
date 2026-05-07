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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');

            $table->foreignId('conversation_id');

            $table->string('message_id')->nullable();

            $table->enum('direction', [

                'incoming',

                'outgoing'

            ]);

            $table->text('message')->nullable();

            $table->string('type')->default('text');

            $table->string('status')->nullable();

            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
