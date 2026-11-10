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
        // 1. Social Accounts (Facebook Pages & Instagram Professional Accounts)
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('platform', 20); // 'facebook' or 'instagram'
            $table->string('account_id', 100); // Meta Page ID or Instagram Business ID
            $table->string('name', 191);
            $table->string('username', 191)->nullable();
            $table->text('profile_picture_url')->nullable();
            $table->text('access_token'); // Encrypted access token
            $table->dateTime('token_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('category', 100)->nullable();
            $table->unsignedInteger('followers_count')->default(0);
            $table->dateTime('connected_at')->nullable();
            $table->json('metadata')->nullable(); // scopes, parent_page_id, ig_business_account_id
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'platform', 'is_active']);
            $table->unique(['tenant_id', 'platform', 'account_id']);
        });

        // 2. Social Posts
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->longText('content')->nullable(); // Caption / text
            $table->string('status', 30)->default('draft'); // draft, scheduled, publishing, published, failed
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->json('options')->nullable(); // first_comment, post_type, reel, etc.
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['tenant_id', 'status', 'scheduled_at']);
        });

        // 3. Social Post Media
        Schema::create('social_post_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('post_id');
            $table->string('media_type', 20); // 'image' or 'video'
            $table->text('url'); // Public URL accessible by Meta
            $table->text('file_path'); // Local storage disk path
            $table->string('filename', 255);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mime_type', 100);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('aspect_ratio', 20)->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('post_id')->references('id')->on('social_posts')->onDelete('cascade');
            $table->index(['post_id', 'order']);
        });

        // 4. Social Post Targets (Target accounts per post)
        Schema::create('social_post_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('social_account_id');
            $table->string('platform', 20); // 'facebook' or 'instagram'
            $table->string('status', 30)->default('pending'); // pending, publishing, published, failed
            $table->string('platform_post_id', 191)->nullable(); // Meta ID
            $table->text('platform_post_url')->nullable(); // Direct web link to post
            $table->dateTime('published_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->json('metrics')->nullable(); // cached likes, comments, impressions, reach
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('post_id')->references('id')->on('social_posts')->onDelete('cascade');
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->onDelete('cascade');
            $table->index(['tenant_id', 'status']);
            $table->index(['post_id', 'social_account_id']);
        });

        // 5. Social Comments (Unified Inbox)
        Schema::create('social_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('social_account_id');
            $table->unsignedBigInteger('social_post_target_id')->nullable();
            $table->string('platform', 20); // 'facebook' or 'instagram'
            $table->string('comment_id', 100); // Meta comment ID
            $table->string('post_id', 100); // Meta Post ID
            $table->string('parent_comment_id', 100)->nullable();
            $table->string('commenter_id', 100)->nullable();
            $table->string('commenter_name', 191)->nullable();
            $table->text('commenter_avatar_url')->nullable();
            $table->text('message');
            $table->dateTime('created_time')->nullable();
            $table->boolean('is_answered')->default(false);
            $table->boolean('is_handled')->default(false);
            $table->text('reply_message')->nullable();
            $table->dateTime('replied_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->onDelete('cascade');
            $table->foreign('social_post_target_id')->references('id')->on('social_post_targets')->onDelete('set null');
            $table->index(['tenant_id', 'social_account_id', 'is_handled']);
            $table->unique(['tenant_id', 'platform', 'comment_id']);
        });

        // 6. Social Analytics
        Schema::create('social_analytics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('social_account_id');
            $table->date('date');
            $table->string('platform', 20); // 'facebook' or 'instagram'
            $table->unsignedInteger('reach')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('engagement')->default(0);
            $table->unsignedInteger('likes')->default(0);
            $table->unsignedInteger('comments')->default(0);
            $table->unsignedInteger('shares')->default(0);
            $table->unsignedInteger('followers_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->onDelete('cascade');
            $table->unique(['tenant_id', 'social_account_id', 'date']);
        });

        // 7. Social Publish Logs
        Schema::create('social_publish_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('post_id')->nullable();
            $table->unsignedBigInteger('post_target_id')->nullable();
            $table->string('status', 30); // 'info', 'success', 'warning', 'error'
            $table->string('action', 50); // 'schedule', 'publish_attempt', 'published', 'failed', 'retry'
            $table->text('message');
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('post_id')->references('id')->on('social_posts')->onDelete('cascade');
            $table->foreign('post_target_id')->references('id')->on('social_post_targets')->onDelete('cascade');
            $table->index(['tenant_id', 'post_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_publish_logs');
        Schema::dropIfExists('social_analytics');
        Schema::dropIfExists('social_comments');
        Schema::dropIfExists('social_post_targets');
        Schema::dropIfExists('social_post_media');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_accounts');
    }
};

