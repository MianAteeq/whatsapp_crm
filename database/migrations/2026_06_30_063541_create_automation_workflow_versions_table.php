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
        Schema::create('automation_workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->string('version_number');
            $table->string('status')->default('active'); // active, deprecated
            $table->json('nodes_data');
            $table->json('connections_data');
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('automation_workflows')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_workflow_versions');
    }
};
