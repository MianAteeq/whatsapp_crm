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
        // 1. Categories Table
        if (!Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('name', 191);
                $table->string('slug', 191);
                $table->text('description')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->unique(['tenant_id', 'slug'], 'uniq_cat_tenant_slug');
                $table->index(['tenant_id', 'name'], 'idx_cat_tenant_name');
            });
        }

        // 2. Businesses Table (Keep businesses separate from contacts)
        if (!Schema::hasTable('businesses')) {
            Schema::create('businesses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('business_name', 255);
                $table->string('normalized_business_name', 255)->nullable()->index();
                $table->text('website')->nullable();
                $table->string('website_domain', 191)->nullable()->index();
                $table->string('phone', 50)->nullable();
                $table->string('normalized_phone', 50)->nullable()->index();
                $table->string('phone_original', 50)->nullable();
                $table->text('address')->nullable();
                $table->string('city', 100)->nullable()->index();
                $table->string('state', 100)->nullable();
                $table->string('country', 100)->nullable();
                $table->string('google_place_id', 191)->nullable()->index();
                $table->text('google_maps_url')->nullable();
                $table->decimal('rating', 3, 2)->nullable();
                $table->integer('review_count')->nullable();
                $table->string('source', 100)->default('Business Scraper');
                $table->json('source_details')->nullable();
                $table->timestamp('first_discovered_at')->nullable();
                $table->timestamp('last_discovered_at')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->unique(['tenant_id', 'google_place_id'], 'uniq_biz_tenant_place');
                $table->index(['tenant_id', 'normalized_phone'], 'idx_biz_tenant_phone');
                $table->index(['tenant_id', 'website_domain'], 'idx_biz_tenant_domain');
            });
        }

        // 3. Business Categories Pivot Table (Many-to-Many)
        if (!Schema::hasTable('business_categories')) {
            Schema::create('business_categories', function (Blueprint $table) {
                $table->unsignedBigInteger('business_id');
                $table->unsignedBigInteger('category_id');

                $table->foreign('business_id')->references('id')->on('businesses')->onDelete('cascade');
                $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
                $table->primary(['business_id', 'category_id'], 'pk_biz_category');
                $table->index('category_id', 'idx_biz_cat_category');
            });
        }

        // 4. Contact Categories Pivot Table (Many-to-Many)
        if (!Schema::hasTable('contact_categories')) {
            Schema::create('contact_categories', function (Blueprint $table) {
                $table->unsignedBigInteger('contact_id');
                $table->unsignedBigInteger('category_id');

                $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
                $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
                $table->primary(['contact_id', 'category_id'], 'pk_contact_category');
                $table->index('category_id', 'idx_contact_cat_category');
            });
        }

        // 5. Update Contacts table: add business_id, contact_type, first_name, last_name, email_normalized
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'business_id')) {
                $table->unsignedBigInteger('business_id')->nullable()->after('tenant_id')->index();
                $table->foreign('business_id')->references('id')->on('businesses')->onDelete('set null');
            }
            if (!Schema::hasColumn('contacts', 'first_name')) {
                $table->string('first_name', 100)->nullable()->after('name');
            }
            if (!Schema::hasColumn('contacts', 'last_name')) {
                $table->string('last_name', 100)->nullable()->after('first_name');
            }
            if (!Schema::hasColumn('contacts', 'email_normalized')) {
                $table->string('email_normalized', 191)->nullable()->after('email')->index();
            }
            if (!Schema::hasColumn('contacts', 'contact_type')) {
                $table->string('contact_type', 50)->default('General')->after('job_title')->index();
            }
        });

        // 6. Update scraper_searches table: add category_id
        Schema::table('scraper_searches', function (Blueprint $table) {
            if (!Schema::hasColumn('scraper_searches', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable()->after('tenant_id')->index();
                $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
            }
        });

        // 7. Scrape Results Table (Tracks discovery origin for each business/contact)
        if (!Schema::hasTable('scrape_results')) {
            Schema::create('scrape_results', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('scrape_search_id')->index();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->unsignedBigInteger('contact_id')->nullable()->index();
                $table->unsignedBigInteger('category_id')->nullable()->index();
                $table->string('discovery_source', 100)->default('Google Maps');
                $table->timestamp('discovered_at')->nullable();
                $table->string('enrichment_status', 50)->default('NEW');
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                $table->foreign('scrape_search_id')->references('id')->on('scraper_searches')->onDelete('cascade');
                $table->foreign('business_id')->references('id')->on('businesses')->onDelete('cascade');
                $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
                $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');

                $table->index(['tenant_id', 'scrape_search_id', 'business_id'], 'idx_scrape_tenant_search_biz');
                $table->index(['tenant_id', 'category_id'], 'idx_scrape_tenant_cat');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrape_results');

        Schema::table('scraper_searches', function (Blueprint $table) {
            if (Schema::hasColumn('scraper_searches', 'category_id')) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            }
        });

        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'business_id')) {
                $table->dropForeign(['business_id']);
                $table->dropColumn('business_id');
            }
            $table->dropColumn([
                'first_name',
                'last_name',
                'email_normalized',
                'contact_type',
            ]);
        });

        Schema::dropIfExists('contact_categories');
        Schema::dropIfExists('business_categories');
        Schema::dropIfExists('businesses');
        Schema::dropIfExists('categories');
    }
};
