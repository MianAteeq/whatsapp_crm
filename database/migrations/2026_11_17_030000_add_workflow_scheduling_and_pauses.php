<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('timezone')->nullable()->after('birthday');
        });
        Schema::table('automation_workflows', function (Blueprint $table) {
            $table->string('timezone_mode', 32)->default('tenant')->after('max_active_enrollments_per_contact');
            $table->json('execution_days')->nullable()->after('timezone_mode');
            $table->time('execution_start_time')->nullable()->after('execution_days');
            $table->time('execution_end_time')->nullable()->after('execution_start_time');
            $table->string('sender_type', 32)->nullable()->after('execution_end_time');
            $table->unsignedBigInteger('sender_id')->nullable()->after('sender_type');
            $table->string('read_state_behavior', 32)->default('preserve')->after('sender_id');
            $table->string('missed_execution_behavior', 32)->default('resume')->after('read_state_behavior');
        });
        Schema::create('automation_pause_windows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('workflow_id');
            $table->string('name');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('recurrence', 20)->default('none');
            $table->unsignedSmallInteger('recurrence_limit')->nullable();
            $table->boolean('pause_enrollment')->default(true);
            $table->boolean('pause_execution')->default(true);
            $table->timestamps();
            $table->foreign('workflow_id')->references('id')->on('automation_workflows')->cascadeOnDelete();
            $table->index(['tenant_id', 'workflow_id', 'starts_at', 'ends_at'], 'automation_pause_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_pause_windows');
        Schema::table('automation_workflows', function (Blueprint $table) {
            $table->dropColumn(['timezone_mode', 'execution_days', 'execution_start_time', 'execution_end_time', 'sender_type', 'sender_id', 'read_state_behavior', 'missed_execution_behavior']);
        });
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn('timezone'));
    }
};
