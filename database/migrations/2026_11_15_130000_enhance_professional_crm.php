<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $t) { $t->json('rules')->nullable(); $t->json('required_fields')->nullable(); $t->json('allowed_role_slugs')->nullable(); });
        Schema::table('opportunities', function (Blueprint $t) { $t->text('description')->nullable(); $t->timestamp('next_activity_at')->nullable()->index(); $t->timestamp('last_activity_at')->nullable(); });
        Schema::table('businesses', function (Blueprint $t) { $t->string('industry')->nullable()->index(); $t->string('linkedin_url')->nullable(); $t->unsignedInteger('employee_count')->nullable(); });
        Schema::table('appointments', function (Blueprint $t) { $t->string('meeting_url')->nullable(); $t->text('outcome')->nullable(); });
        Schema::create('sales_activities', function (Blueprint $t) { $t->id(); $t->foreignId('tenant_id')->index(); $t->foreignId('user_id')->nullable()->index(); $t->string('activity_type',40)->index(); $t->string('subject_type',40)->index(); $t->unsignedBigInteger('subject_id')->index(); $t->foreignId('contact_id')->nullable()->index(); $t->foreignId('business_id')->nullable()->index(); $t->foreignId('opportunity_id')->nullable()->index(); $t->string('title'); $t->text('description')->nullable(); $t->json('metadata')->nullable(); $t->timestamp('occurred_at')->index(); $t->timestamps(); $t->index(['tenant_id','subject_type','subject_id']); });
    }
    public function down(): void
    {
        Schema::dropIfExists('sales_activities');
        Schema::table('appointments', fn(Blueprint $t) => $t->dropColumn(['meeting_url','outcome']));
        Schema::table('businesses', fn(Blueprint $t) => $t->dropColumn(['industry','linkedin_url','employee_count']));
        Schema::table('opportunities', fn(Blueprint $t) => $t->dropColumn(['description','next_activity_at','last_activity_at']));
        Schema::table('pipeline_stages', fn(Blueprint $t) => $t->dropColumn(['rules','required_fields','allowed_role_slugs']));
    }
};
