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
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_message_logs', 'campaign_id')) {
                $table->unsignedBigInteger('campaign_id')->nullable()->after('tenant_id');
                $table->index('campaign_id');
            }
            if (!Schema::hasColumn('whatsapp_message_logs', 'contact_id')) {
                $table->unsignedBigInteger('contact_id')->nullable()->after('campaign_id');
                $table->index('contact_id');
            }
            if (!Schema::hasColumn('whatsapp_message_logs', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('whatsapp_message_logs', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('sent_at');
            }
            if (!Schema::hasColumn('whatsapp_message_logs', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('delivered_at');
            }
            if (!Schema::hasColumn('whatsapp_message_logs', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('read_at');
            }
            if (!Schema::hasColumn('whatsapp_message_logs', 'replied_at')) {
                $table->timestamp('replied_at')->nullable()->after('failed_at');
            }
            if (!Schema::hasColumn('whatsapp_message_logs', 'error_message')) {
                $table->text('error_message')->nullable()->after('replied_at');
            }
            if (!Schema::hasColumn('whatsapp_message_logs', 'payload')) {
                $table->json('payload')->nullable()->after('error_message');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            $table->dropColumn([
                'campaign_id',
                'contact_id',
                'sent_at',
                'delivered_at',
                'read_at',
                'failed_at',
                'replied_at',
                'error_message',
                'payload',
            ]);
        });
    }
};
