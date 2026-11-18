<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_workflows', function (Blueprint $table) {
            $table->unsignedBigInteger('lock_version')->default(0)->after('version');
            $table->timestamp('draft_saved_at')->nullable()->after('lock_version');
            $table->unsignedBigInteger('published_version_id')->nullable()->after('draft_saved_at');
            $table->foreign('published_version_id')->references('id')->on('automation_workflow_versions')->nullOnDelete();
        });
        Schema::table('automation_workflow_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('status');
            $table->text('change_summary')->nullable()->after('created_by');
            $table->timestamp('published_at')->nullable()->after('change_summary');
            $table->index(['workflow_id', 'status', 'created_at'], 'automation_version_workflow_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('automation_workflows', function (Blueprint $table) {
            $table->dropForeign(['published_version_id']);
            $table->dropColumn(['lock_version', 'draft_saved_at', 'published_version_id']);
        });
        Schema::table('automation_workflow_versions', function (Blueprint $table) {
            $table->dropIndex('automation_version_workflow_status_idx');
            $table->dropColumn(['created_by', 'change_summary', 'published_at']);
        });
    }
};
