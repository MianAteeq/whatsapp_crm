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
        // 1. Social Conversations (Direct Messages & Comment Threads)
        Schema::create('social_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('social_account_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->string('platform', 20); // 'facebook' or 'instagram'
            $table->string('conversation_type', 30)->default('direct_message'); // 'direct_message' or 'comment'
            $table->string('platform_conversation_id', 191)->nullable(); // Meta thread ID / conversation ID
            $table->string('participant_id', 191); // Customer PSID / IGSID / Commenter ID
            $table->string('participant_name', 191);
            $table->string('participant_username', 191)->nullable();
            $table->text('participant_avatar_url')->nullable();
            $table->string('status', 20)->default('open'); // 'open', 'pending', 'resolved'
            $table->string('priority', 20)->default('normal'); // 'urgent', 'high', 'normal', 'low'
            $table->boolean('is_unread')->default(false);
            $table->unsignedInteger('unread_count')->default(0);
            $table->dateTime('last_message_at')->nullable();
            $table->text('last_message_preview')->nullable();
            $table->string('last_message_sender', 20)->nullable(); // 'customer', 'agent', 'system'
            $table->string('post_reference_id', 191)->nullable(); // For comments: platform post / media ID
            $table->text('post_reference_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('set null');
            $table->foreign('assigned_to_user_id')->references('id')->on('users')->onDelete('set null');

            $table->index(['tenant_id', 'status', 'last_message_at'], 'sc_tenant_status_msg_idx');
            $table->index(['tenant_id', 'platform', 'conversation_type'], 'sc_tenant_plat_type_idx');
            $table->index(['tenant_id', 'social_account_id'], 'sc_tenant_account_idx');
            $table->index(['tenant_id', 'assigned_to_user_id'], 'sc_tenant_assigned_idx');
            $table->index(['tenant_id', 'participant_id'], 'sc_tenant_part_idx');
            $table->index(['platform_conversation_id'], 'sc_plat_conv_idx');
        });

        // 2. Social Messages
        Schema::create('social_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('social_conversation_id');
            $table->unsignedBigInteger('social_account_id');
            $table->string('sender_type', 20)->default('customer'); // 'customer', 'agent', 'system'
            $table->unsignedBigInteger('sender_user_id')->nullable(); // CRM user if sent by agent
            $table->string('sender_id', 191); // PSID, IGSID, or Page ID
            $table->string('sender_name', 191)->nullable();
            $table->text('sender_avatar_url')->nullable();
            $table->longText('message_text')->nullable();
            $table->string('platform_message_id', 191)->nullable(); // Meta message mid.xxx or comment id
            $table->string('status', 20)->default('sent'); // 'pending', 'sent', 'delivered', 'read', 'failed'
            $table->dateTime('sent_at');
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('social_conversation_id')->references('id')->on('social_conversations')->onDelete('cascade');
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->onDelete('cascade');
            $table->foreign('sender_user_id')->references('id')->on('users')->onDelete('set null');

            $table->index(['social_conversation_id', 'sent_at'], 'sm_conv_sent_idx');
            $table->index(['tenant_id', 'platform_message_id'], 'sm_tenant_msg_idx');
            $table->index(['tenant_id', 'status'], 'sm_tenant_status_idx');
        });

        // 3. Social Message Attachments
        Schema::create('social_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('social_message_id');
            $table->string('attachment_type', 30); // 'image', 'video', 'audio', 'file', 'share', 'sticker'
            $table->text('url');
            $table->text('file_path')->nullable();
            $table->string('file_name', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('social_message_id')->references('id')->on('social_messages')->onDelete('cascade');
            $table->index(['social_message_id'], 'sma_msg_idx');
        });

        // 4. Social Conversation Assignments (Audit log of team assignment history)
        Schema::create('social_conversation_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('social_conversation_id');
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('social_conversation_id')->references('id')->on('social_conversations')->onDelete('cascade');
            $table->foreign('assigned_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('assigned_to_user_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['social_conversation_id', 'created_at'], 'sca_conv_created_idx');
        });

        // 5. Social Conversation Internal Notes
        Schema::create('social_conversation_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('social_conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->text('content');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('social_conversation_id')->references('id')->on('social_conversations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['social_conversation_id', 'created_at'], 'scn_conv_created_idx');
        });

        // 6. Social Conversation Tags
        Schema::create('social_conversation_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('social_conversation_id');
            $table->string('tag', 100);
            $table->string('color', 20)->default('#10B981');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('social_conversation_id')->references('id')->on('social_conversations')->onDelete('cascade');
            $table->index(['social_conversation_id', 'tag'], 'sct_conv_tag_idx');
        });

        // 7. Social Webhook Events (Idempotency and Audit)
        Schema::create('social_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('platform', 20); // 'facebook' or 'instagram'
            $table->string('event_id', 191)->unique(); // Meta message mid or delivery watermark or comment ID
            $table->string('event_type', 50); // 'messages', 'messaging_postbacks', 'message_deliveries', 'message_reads', 'feed'
            $table->string('sender_id', 191)->nullable();
            $table->string('recipient_id', 191)->nullable();
            $table->boolean('processed')->default(false);
            $table->longText('payload'); // Raw payload JSON
            $table->dateTime('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['platform', 'event_type', 'processed'], 'swe_plat_type_idx');
            $table->index(['tenant_id', 'created_at'], 'swe_tenant_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_webhook_events');
        Schema::dropIfExists('social_conversation_tags');
        Schema::dropIfExists('social_conversation_notes');
        Schema::dropIfExists('social_conversation_assignments');
        Schema::dropIfExists('social_message_attachments');
        Schema::dropIfExists('social_messages');
        Schema::dropIfExists('social_conversations');
    }
};
