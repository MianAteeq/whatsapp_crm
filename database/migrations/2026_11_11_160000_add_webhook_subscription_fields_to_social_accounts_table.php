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
        Schema::table('social_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('social_accounts', 'webhook_status')) {
                $table->string('webhook_status', 50)->default('webhook_pending')->after('is_active')->index();
            }
            if (!Schema::hasColumn('social_accounts', 'webhook_subscription_error')) {
                $table->text('webhook_subscription_error')->nullable()->after('webhook_status');
            }
            if (!Schema::hasColumn('social_accounts', 'last_webhook_received_at')) {
                $table->timestamp('last_webhook_received_at')->nullable()->after('webhook_subscription_error');
            }
            if (!Schema::hasColumn('social_accounts', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('last_webhook_received_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('social_accounts', 'webhook_status')) {
                $columnsToDrop[] = 'webhook_status';
            }
            if (Schema::hasColumn('social_accounts', 'webhook_subscription_error')) {
                $columnsToDrop[] = 'webhook_subscription_error';
            }
            if (Schema::hasColumn('social_accounts', 'last_webhook_received_at')) {
                $columnsToDrop[] = 'last_webhook_received_at';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

