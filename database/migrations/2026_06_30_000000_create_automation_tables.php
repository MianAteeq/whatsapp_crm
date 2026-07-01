<?php

declare(strict_types=1);

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
        // 1. automation_workflows
        Schema::create('automation_workflows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('inactive'); // active, inactive
            $table->string('version')->default('1.0.0');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });

        // 2. automation_nodes
        Schema::create('automation_nodes', function (Blueprint $table) {
            $table->string('id')->primary(); // uuid or custom react-flow node ID
            $table->unsignedBigInteger('workflow_id');
            $table->string('type'); // trigger, action, delay, condition, ai
            $table->string('label');
            $table->json('config')->nullable();
            $table->double('position_x')->default(0);
            $table->double('position_y')->default(0);
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('automation_workflows')->onDelete('cascade');
        });

        // 3. automation_connections
        Schema::create('automation_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->string('source_node_id');
            $table->string('target_node_id');
            $table->string('source_handle')->nullable();
            $table->string('target_handle')->nullable();
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('automation_workflows')->onDelete('cascade');
            $table->foreign('source_node_id')->references('id')->on('automation_nodes')->onDelete('cascade');
            $table->foreign('target_node_id')->references('id')->on('automation_nodes')->onDelete('cascade');
        });

        // 4. automation_executions
        Schema::create('automation_executions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('status')->default('running'); // running, completed, failed, retrying
            $table->string('current_node_id')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('automation_workflows')->onDelete('cascade');
        });

        // 5. automation_logs
        Schema::create('automation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('execution_id');
            $table->string('node_id')->nullable();
            $table->string('step_name');
            $table->string('status'); // success, failed
            $table->text('message')->nullable();
            $table->json('api_response')->nullable();
            $table->integer('execution_time_ms')->default(0);
            $table->text('errors')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamps();

            $table->foreign('execution_id')->references('id')->on('automation_executions')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_logs');
        Schema::dropIfExists('automation_executions');
        Schema::dropIfExists('automation_connections');
        Schema::dropIfExists('automation_nodes');
        Schema::dropIfExists('automation_workflows');
    }
};
