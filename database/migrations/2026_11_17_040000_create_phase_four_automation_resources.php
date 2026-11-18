<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_folders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('name');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'parent_id', 'name']);
        });
        Schema::table('automation_workflows', function (Blueprint $t) {
            $t->unsignedBigInteger('folder_id')->nullable()->after('tenant_id');
            $t->text('internal_notes')->nullable()->after('description');
            $t->foreign('folder_id')->references('id')->on('automation_folders')->nullOnDelete();
        });
        Schema::create('automation_comments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('workflow_id');
            $t->unsignedBigInteger('user_id');
            $t->string('node_id')->nullable();
            $t->text('body');
            $t->timestamps();
            $t->foreign('workflow_id')->references('id')->on('automation_workflows')->cascadeOnDelete();
            $t->index(['tenant_id', 'workflow_id']);
        });
        Schema::create('automation_templates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('key');
            $t->string('name');
            $t->string('category');
            $t->text('description')->nullable();
            $t->json('definition');
            $t->json('required_mappings')->nullable();
            $t->boolean('is_system')->default(false);
            $t->timestamps();
            $t->unique(['tenant_id', 'key']);
        });
        Schema::create('automation_event_waits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('execution_id');
            $t->string('node_id');
            $t->string('event_type');
            $t->json('match_config')->nullable();
            $t->timestamp('timeout_at')->nullable();
            $t->timestamp('matched_at')->nullable();
            $t->string('status', 20)->default('waiting');
            $t->timestamps();
            $t->foreign('execution_id')->references('id')->on('automation_executions')->cascadeOnDelete();
            $t->index(['tenant_id', 'event_type', 'status']);
        });
        Schema::create('email_sequences', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('folder_id')->nullable();
            $t->string('name');
            $t->string('status', 20)->default('draft');
            $t->boolean('stop_on_response')->default(true);
            $t->unsignedBigInteger('lock_version')->default(0);
            $t->timestamp('draft_saved_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });
        Schema::create('email_sequence_steps', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sequence_id');
            $t->unsignedSmallInteger('position');
            $t->unsignedInteger('delay_minutes')->default(0);
            $t->unsignedBigInteger('email_template_id')->nullable();
            $t->string('subject');
            $t->longText('body')->nullable();
            $t->timestamps();
            $t->foreign('sequence_id')->references('id')->on('email_sequences')->cascadeOnDelete();
            $t->unique(['sequence_id', 'position']);
        });
        Schema::create('email_sequence_enrollments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('sequence_id');
            $t->unsignedBigInteger('contact_id');
            $t->string('status', 20)->default('active');
            $t->unsignedSmallInteger('current_step')->default(0);
            $t->timestamp('next_send_at')->nullable();
            $t->text('stop_reason')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'sequence_id', 'status'], 'ese_tenant_seq_status_idx');
        });
        Schema::create('email_sequence_logs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tenant_id'); $t->unsignedBigInteger('enrollment_id'); $t->unsignedBigInteger('step_id')->nullable();
            $t->string('status',20); $t->string('provider')->nullable(); $t->string('provider_message_id')->nullable(); $t->text('error')->nullable(); $t->timestamp('sent_at')->nullable(); $t->timestamps();
            $t->index(['tenant_id', 'enrollment_id', 'created_at'], 'esl_tenant_enroll_idx');
        });
        Schema::create('tracked_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->uuid('public_token')->unique();
            $t->string('name');
            $t->text('destination_url');
            $t->string('repeat_click_policy', 20)->default('every');
            $t->boolean('trigger_workflows')->default(true);
            $t->timestamps();
            $t->index(['tenant_id', 'created_at']);
        });
        Schema::create('tracked_link_visits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('tracked_link_id');
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->string('visitor_token')->nullable();
            $t->text('user_agent')->nullable();
            $t->string('ip_hash', 64)->nullable();
            $t->string('classification', 16)->default('unknown');
            $t->string('classification_reason')->nullable();
            $t->boolean('manual_override')->default(false);
            $t->json('raw_metadata')->nullable();
            $t->timestamp('visited_at');
            $t->timestamps();
            $t->foreign('tracked_link_id')->references('id')->on('tracked_links')->cascadeOnDelete();
            $t->index(['tenant_id', 'tracked_link_id', 'classification'], 'tlv_tenant_link_class_idx');
        });
        Schema::create('automation_bot_rules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('name');
            $t->string('signal_type');
            $t->string('pattern');
            $t->string('classification', 16)->default('bot');
            $t->boolean('enabled')->default(true);
            $t->timestamps();
            $t->index(['tenant_id', 'enabled']);
        });
        Schema::create('automation_alert_settings', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tenant_id')->unique(); $t->boolean('enabled')->default(false);
            $t->json('recipient_user_ids')->nullable(); $t->unsignedInteger('repeat_suppression_minutes')->default(60);
            $t->unsignedSmallInteger('hourly_limit')->default(20); $t->timestamps();
        });
        Schema::create('automation_failure_alerts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('tenant_id'); $t->unsignedBigInteger('execution_id'); $t->string('fingerprint', 64);
            $t->string('status', 20)->default('pending'); $t->json('recipient_user_ids')->nullable(); $t->text('error_summary')->nullable();
            $t->timestamp('sent_at')->nullable(); $t->timestamps(); $t->index(['tenant_id', 'fingerprint', 'created_at'], 'afa_tenant_fingerprint_idx');
        });
    }

    public function down(): void
    {
        foreach (['automation_failure_alerts', 'automation_alert_settings', 'automation_bot_rules', 'tracked_link_visits', 'tracked_links', 'email_sequence_logs', 'email_sequence_enrollments', 'email_sequence_steps', 'email_sequences', 'automation_event_waits', 'automation_templates', 'automation_comments'] as $table) Schema::dropIfExists($table);
        Schema::table('automation_workflows', function (Blueprint $t) {
            $t->dropForeign(['folder_id']);
            $t->dropColumn(['folder_id', 'internal_notes']);
        });
        Schema::dropIfExists('automation_folders');
    }
};
