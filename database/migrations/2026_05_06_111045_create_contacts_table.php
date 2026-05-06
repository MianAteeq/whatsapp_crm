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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();


            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();

            $table->string('company')->nullable();
            $table->string('job_title')->nullable();

            $table->text('address')->nullable();
            $table->date('birthday')->nullable();

            $table->string('website')->nullable();

            $table->text('notes')->nullable();

            $table->enum('status', ['active', 'inactive'])
                ->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
