<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inbox_conversation_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('channel', 24);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('assigned_to_user_id')->nullable()->index();
            $table->string('status', 20)->default('open')->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'channel', 'source_id'], 'inbox_state_source_unique');
        });

        Schema::create('inbox_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('user_id')->index();
            $table->string('channel', 24);
            $table->unsignedBigInteger('source_id');
            $table->boolean('is_starred')->default(false)->index();
            $table->boolean('is_following')->default(false)->index();
            $table->boolean('unread_override')->nullable();
            $table->timestamp('last_viewed_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'channel', 'source_id'], 'inbox_user_state_unique');
        });

        Schema::create('inbox_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('user_id')->index();
            $table->string('channel', 24);
            $table->unsignedBigInteger('source_id');
            $table->text('content');
            $table->timestamps();
            $table->index(['tenant_id', 'channel', 'source_id'], 'inbox_notes_source_index');
        });

        Schema::create('reply_snippets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('created_by_user_id')->nullable()->index();
            $table->string('title', 120);
            $table->string('category', 80)->nullable()->index();
            $table->text('body');
            $table->string('visibility', 20)->default('shared')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'title'], 'reply_snippets_tenant_title_unique');
        });

        Schema::create('inbox_sla_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->string('channel', 24);
            $table->unsignedInteger('response_minutes');
            $table->unsignedInteger('warning_minutes')->default(15);
            $table->boolean('continuous_clock')->default(true);
            $table->boolean('human_reply_stops_clock')->default(true);
            $table->boolean('ai_reply_stops_clock')->default(false);
            $table->boolean('workflow_reply_stops_clock')->default(false);
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'channel'], 'inbox_sla_policy_channel_unique');
        });

        Schema::create('inbox_sla_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('policy_id')->nullable()->index();
            $table->string('channel', 24);
            $table->unsignedBigInteger('source_id');
            $table->string('last_inbound_message_key')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('responded_at')->nullable();
            $table->string('status', 20)->default('inactive')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'channel', 'source_id'], 'inbox_sla_state_source_unique');
        });

        Schema::create('inbox_sla_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('channel', 24);
            $table->unsignedBigInteger('source_id');
            $table->string('event_type', 40);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'channel', 'source_id'], 'inbox_sla_events_source_index');
        });

        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->string('conversation_channel', 24)->nullable()->after('opportunity_id');
            $table->unsignedBigInteger('conversation_source_id')->nullable()->after('conversation_channel');
            $table->index(['tenant_id', 'conversation_channel', 'conversation_source_id'], 'crm_tasks_conversation_index');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('sender_user_id')->nullable()->after('conversation_id')->index();
            $table->string('origin_type', 20)->nullable()->after('direction')->index();
            $table->index(['tenant_id', 'message_id'], 'messages_tenant_provider_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_tenant_provider_index');
            $table->dropColumn(['sender_user_id', 'origin_type']);
        });
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->dropIndex('crm_tasks_conversation_index');
            $table->dropColumn(['conversation_channel', 'conversation_source_id']);
        });
        Schema::dropIfExists('inbox_sla_events');
        Schema::dropIfExists('inbox_sla_states');
        Schema::dropIfExists('inbox_sla_policies');
        Schema::dropIfExists('reply_snippets');
        Schema::dropIfExists('inbox_notes');
        Schema::dropIfExists('inbox_user_states');
        Schema::dropIfExists('inbox_conversation_states');
    }
};

