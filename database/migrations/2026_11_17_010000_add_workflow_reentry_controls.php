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
            $table->string('reentry_policy', 32)->default('after_completion')->after('status');
            $table->unsignedInteger('reentry_cooldown_minutes')->default(0)->after('reentry_policy');
            $table->boolean('stop_on_response')->default(false)->after('reentry_cooldown_minutes');
            $table->unsignedSmallInteger('max_active_enrollments_per_contact')->default(1)->after('stop_on_response');
        });
    }

    public function down(): void
    {
        Schema::table('automation_workflows', function (Blueprint $table) {
            $table->dropColumn(['reentry_policy', 'reentry_cooldown_minutes', 'stop_on_response', 'max_active_enrollments_per_contact']);
        });
    }
};
