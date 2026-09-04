<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        // ── Pages + Page Builder ───────────────────────────────────────────────
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->string('template', 100)->default('default');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE pages ADD CONSTRAINT chk_pages_status CHECK (status IN ('draft', 'published', 'archived'))");
        }

        Schema::create('page_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title', 255);
            $table->string('slug', 255);
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->foreignId('og_image_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->timestamps();

            $table->unique(['page_id', 'locale']);
            $table->index(['locale', 'slug']);
        });

        Schema::create('page_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('block_type', 100);
            $table->unsignedInteger('position')->default(0);
            $table->jsonb('config')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index(['page_id', 'position']);
        });

        // ── Blog ───────────────────────────────────────────────────────────────
        Schema::create('blog_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->string('category', 100)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'published_at']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE blog_posts ADD CONSTRAINT chk_blog_posts_status CHECK (status IN ('draft', 'published', 'archived'))");
        }

        Schema::create('blog_post_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title', 255);
            $table->string('slug', 255);
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->timestamps();

            $table->unique(['blog_post_id', 'locale']);
            $table->index(['locale', 'slug']);
        });

        // ── FAQ ────────────────────────────────────────────────────────────────
        Schema::create('faqs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('category', 100)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_published', 'sort_order']);
        });

        Schema::create('faq_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('faq_id')->constrained('faqs')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('question', 500);
            $table->text('answer');
            $table->timestamps();

            $table->unique(['faq_id', 'locale']);
        });

        // ── Menus ──────────────────────────────────────────────────────────────
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 100);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->string('route_type', 20);
            $table->string('route_target', 500);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['menu_id', 'parent_id', 'sort_order']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE menu_items ADD CONSTRAINT chk_menu_items_route_type CHECK (route_type IN ('page', 'category', 'product', 'external', 'vendor'))");
        }

        Schema::create('menu_item_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('label', 150);
            $table->timestamps();

            $table->unique(['menu_item_id', 'locale']);
        });

        // ── Banners ────────────────────────────────────────────────────────────
        Schema::create('banners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('placement', 100)->default('home');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'placement', 'is_active']);
        });

        Schema::create('banner_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('banner_id')->constrained('banners')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('headline', 255)->nullable();
            $table->string('cta_text', 100)->nullable();
            $table->string('link_url', 500)->nullable();
            $table->timestamps();

            $table->unique(['banner_id', 'locale']);
        });

        // ── Redirects ──────────────────────────────────────────────────────────
        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('from_path', 500);
            $table->string('to_path', 500);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->string('locale', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_external')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'from_path', 'locale']);
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE redirects ADD CONSTRAINT chk_redirects_status_code CHECK (status_code IN (301, 302, 307, 308))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('banner_translations');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('menu_item_translations');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('faq_translations');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('blog_post_translations');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('page_blocks');
        Schema::dropIfExists('page_translations');
        Schema::dropIfExists('pages');
    }
};
