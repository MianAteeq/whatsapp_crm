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
        // 1. Media Folders Table (supports nested folders per tenant)
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->string('color', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('media_folders')->onDelete('cascade');
            $table->index(['tenant_id', 'parent_id']);
        });

        // 2. Media Assets Table
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('media_folder_id')->nullable();
            $table->string('filename', 255);
            $table->string('original_name', 255);
            $table->string('disk', 50)->default('public');
            $table->text('file_path');
            $table->text('url');
            $table->string('mime_type', 100);
            $table->string('file_type', 30)->default('image'); // image, video, document, audio, other
            $table->unsignedBigInteger('file_size')->default(0); // in bytes
            $table->string('file_hash', 64)->nullable();        // SHA-256 hash for deduplication
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->float('duration')->nullable();               // seconds (for video/audio)
            $table->json('metadata')->nullable();                // EXIF, bitrate, encoding info
            $table->json('tags')->nullable();                    // string tags
            $table->unsignedInteger('access_count')->default(0);
            $table->dateTime('last_accessed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('media_folder_id')->references('id')->on('media_folders')->onDelete('set null');

            $table->index(['tenant_id', 'file_type']);
            $table->index(['tenant_id', 'media_folder_id']);
            $table->index(['tenant_id', 'file_hash']);
        });

        // 3. Media Asset References (safeguards against breaking published posts or campaigns)
        Schema::create('media_asset_references', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('media_asset_id');
            $table->string('reference_type', 50); // social_post, landing_page, campaign, template, message
            $table->string('reference_id', 100);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->onDelete('cascade');
            $table->index(['tenant_id', 'media_asset_id']);
            $table->index(['reference_type', 'reference_id']);
        });

        // 4. Marketing Saved Reports Configuration Table
        Schema::create('marketing_saved_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('report_type', 50); // overview, ads, social, leads, team
            $table->json('filters')->nullable();
            $table->json('metrics')->nullable();
            $table->json('schedule')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['tenant_id', 'report_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_saved_reports');
        Schema::dropIfExists('media_asset_references');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('media_folders');
    }
};

