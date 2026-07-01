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
        Schema::table('automation_executions', function (Blueprint $table) {
            $table->unsignedBigInteger('workflow_version_id')->nullable()->after('workflow_id');
            $table->text('last_error')->nullable()->after('current_node_id');
            $table->timestamp('started_at')->nullable()->after('retry_count');
            $table->timestamp('finished_at')->nullable()->after('started_at');
            $table->timestamp('resume_at')->nullable()->after('finished_at');
            $table->json('context_variables')->nullable()->after('resume_at');

            $table->foreign('workflow_version_id')->references('id')->on('automation_workflow_versions')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('automation_executions', function (Blueprint $table) {
            $table->dropForeign(['workflow_version_id']);
            $table->dropColumn([
                'workflow_version_id',
                'last_error',
                'started_at',
                'finished_at',
                'resume_at',
                'context_variables'
            ]);
        });
    }
};
