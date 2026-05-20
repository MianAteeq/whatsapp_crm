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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id');

            $table->string('name');

            $table->string('type')->default('broadcast');

            $table->foreignId('template_id')->nullable();

            $table->string('status')->default('draft');

            $table->integer('total_contacts')->default(0);

            $table->integer('sent_count')->default(0);

            $table->integer('delivered_count')->default(0);

            $table->integer('read_count')->default(0);

            $table->integer('failed_count')->default(0);

            $table->timestamp('scheduled_at')->nullable();

            $table->timestamp('started_at')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
