<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedBigInteger('workflow_version_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->uuid('enrollment_key')->unique();
            $table->string('status', 32)->default('pending');
            $table->string('source', 64)->nullable();
            $table->string('source_event_id')->nullable();
            $table->text('stop_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('automation_workflows')->cascadeOnDelete();
            $table->foreign('workflow_version_id')->references('id')->on('automation_workflow_versions')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'created_at'], 'automation_enrollment_tenant_status_idx');
            $table->index(['tenant_id', 'contact_id', 'workflow_id'], 'automation_enrollment_contact_workflow_idx');
            $table->unique(['tenant_id', 'source_event_id', 'workflow_id'], 'automation_enrollment_event_unique');
        });

        Schema::table('automation_executions', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->unsignedBigInteger('enrollment_id')->nullable()->after('workflow_version_id');
            $table->text('completion_reason')->nullable()->after('last_error');
            $table->text('cancellation_reason')->nullable()->after('completion_reason');
            $table->timestamp('cancelled_at')->nullable()->after('finished_at');
            $table->foreign('enrollment_id')->references('id')->on('automation_enrollments')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'created_at'], 'automation_execution_tenant_status_idx');
            $table->index(['tenant_id', 'contact_id', 'created_at'], 'automation_execution_contact_idx');
        });

        DB::table('automation_executions')->whereNull('tenant_id')->update([
            'tenant_id' => DB::raw('(SELECT tenant_id FROM automation_workflows WHERE automation_workflows.id = automation_executions.workflow_id)'),
        ]);

        Schema::table('automation_logs', function (Blueprint $table) {
            $table->string('outcome', 32)->nullable()->after('status');
            $table->json('input_snapshot')->nullable()->after('message');
            $table->json('output_snapshot')->nullable()->after('input_snapshot');
            $table->text('skip_reason')->nullable()->after('errors');
            $table->timestamp('started_at')->nullable()->after('retry_count');
            $table->timestamp('finished_at')->nullable()->after('started_at');
            $table->index(['execution_id', 'node_id', 'created_at'], 'automation_log_execution_node_idx');
        });
    }

    public function down(): void
    {
        Schema::table('automation_logs', function (Blueprint $table) {
            $table->dropIndex('automation_log_execution_node_idx');
            $table->dropColumn(['outcome', 'input_snapshot', 'output_snapshot', 'skip_reason', 'started_at', 'finished_at']);
        });
        Schema::table('automation_executions', function (Blueprint $table) {
            $table->dropIndex('automation_execution_tenant_status_idx');
            $table->dropIndex('automation_execution_contact_idx');
            $table->dropForeign(['enrollment_id']);
            $table->dropColumn(['tenant_id', 'enrollment_id', 'completion_reason', 'cancellation_reason', 'cancelled_at']);
        });
        Schema::dropIfExists('automation_enrollments');
    }
};
