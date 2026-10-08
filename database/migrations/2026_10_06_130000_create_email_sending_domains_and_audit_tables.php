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
        // 1. Email Sending Domains Table
        if (!Schema::hasTable('email_sending_domains')) {
            Schema::create('email_sending_domains', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('domain', 191);
                $table->enum('status', ['pending', 'verified', 'rejected', 'failed'])->default('pending');
                $table->string('mailtrap_domain_id')->nullable();
                $table->string('mailtrap_domain_name')->nullable();
                $table->string('spf_status', 50)->default('pending');
                $table->string('dkim_status', 50)->default('pending');
                $table->string('dmarc_status', 50)->default('pending');
                $table->json('dns_records')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->text('verification_error')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->unique(['tenant_id', 'domain'], 'uniq_tenant_sending_domain');
                $table->index(['tenant_id', 'status']);
                $table->index('domain');
            });
        }

        // 2. Email Domain Audit Logs Table
        if (!Schema::hasTable('email_domain_audit_logs')) {
            Schema::create('email_domain_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('domain_id')->nullable();
                $table->string('event', 100);
                $table->string('old_status', 50)->nullable();
                $table->string('new_status', 50)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'domain_id']);
                $table->index('event');
            });
        }

        // 3. Add sending_domain fields to email_campaigns
        if (Schema::hasTable('email_campaigns')) {
            Schema::table('email_campaigns', function (Blueprint $table) {
                if (!Schema::hasColumn('email_campaigns', 'sending_domain_id')) {
                    $table->unsignedBigInteger('sending_domain_id')->nullable()->after('reply_to');
                }
                if (!Schema::hasColumn('email_campaigns', 'sending_domain')) {
                    $table->string('sending_domain', 191)->nullable()->after('sending_domain_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('email_campaigns')) {
            Schema::table('email_campaigns', function (Blueprint $table) {
                if (Schema::hasColumn('email_campaigns', 'sending_domain')) {
                    $table->dropColumn('sending_domain');
                }
                if (Schema::hasColumn('email_campaigns', 'sending_domain_id')) {
                    $table->dropColumn('sending_domain_id');
                }
            });
        }

        Schema::dropIfExists('email_domain_audit_logs');
        Schema::dropIfExists('email_sending_domains');
    }
};
