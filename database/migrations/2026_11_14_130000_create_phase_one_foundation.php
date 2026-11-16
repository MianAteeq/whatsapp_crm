<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_roles', function (Blueprint $table) {
            $table->id(); $table->foreignId('tenant_id')->index();
            $table->string('name', 80); $table->string('slug', 80); $table->text('description')->nullable();
            $table->json('permissions'); $table->boolean('is_system')->default(false); $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->foreignId('tenant_role_id')->nullable()->index()->after('tenant_id'));
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id(); $table->foreignId('tenant_id')->index(); $table->foreignId('user_id')->nullable()->index();
            $table->string('actor_type', 20)->default('user'); $table->string('actor_label')->nullable();
            $table->string('module', 60)->index(); $table->string('action', 80)->index();
            $table->string('auditable_type')->nullable(); $table->unsignedBigInteger('auditable_id')->nullable();
            $table->text('description')->nullable(); $table->json('old_values')->nullable(); $table->json('new_values')->nullable();
            $table->json('metadata')->nullable(); $table->string('ip_address', 45)->nullable(); $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::create('contact_communication_preferences', function (Blueprint $table) {
            $table->id(); $table->foreignId('tenant_id')->index(); $table->foreignId('contact_id')->unique();
            $table->boolean('email_allowed')->default(true); $table->boolean('whatsapp_allowed')->default(true);
            $table->boolean('sms_allowed')->default(true); $table->boolean('phone_allowed')->default(true);
            $table->string('source', 40)->default('default'); $table->text('reason')->nullable();
            $table->timestamp('email_unsubscribed_at')->nullable(); $table->timestamp('whatsapp_opted_out_at')->nullable(); $table->timestamps();
        });
        Schema::create('tenant_contact_policies', function (Blueprint $table) {
            $table->id(); $table->foreignId('tenant_id')->unique();
            $table->boolean('allow_duplicates')->default(false); $table->boolean('match_email')->default(true); $table->boolean('match_phone')->default(true);
            $table->string('ambiguous_action', 30)->default('review');
            $table->string('email_verification_policy', 30)->default('first_send');
            $table->boolean('unsubscribe_footer_enabled')->default(true); $table->string('unsubscribe_label')->default('Unsubscribe from marketing emails');
            $table->timestamps();
        });
        Schema::create('duplicate_review_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('tenant_id')->index(); $table->string('source', 40)->default('manual');
            $table->json('incoming_data'); $table->json('candidate_contact_ids'); $table->json('reasons');
            $table->string('status', 20)->default('pending')->index(); $table->foreignId('resolved_by')->nullable();
            $table->foreignId('resolved_contact_id')->nullable(); $table->timestamps();
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('email_verification_status', 20)->default('unchecked')->index();
            $table->timestamp('email_verification_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn(['email_verification_status','email_verification_checked_at']));
        Schema::dropIfExists('duplicate_review_items'); Schema::dropIfExists('tenant_contact_policies');
        Schema::dropIfExists('contact_communication_preferences'); Schema::dropIfExists('audit_logs');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('tenant_role_id')); Schema::dropIfExists('tenant_roles');
    }
};
