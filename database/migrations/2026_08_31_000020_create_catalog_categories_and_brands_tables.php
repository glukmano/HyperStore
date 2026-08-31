<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Brands ─────────────────────────────────────────────────────────────
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 100);
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('brand_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('slug', 255);
            $table->string('website', 500)->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'locale']);
            $table->index(['locale', 'slug']);
        });

        // ── Categories ─────────────────────────────────────────────────────────
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->string('code', 100);
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $table->integer('sort_order')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'parent_id', 'sort_order']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('category_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('slug', 255);
            $table->timestamps();

            $table->unique(['category_id', 'locale']);
            $table->index(['locale', 'slug']);
        });

        Schema::create('category_stores', function (Blueprint $table): void {
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->boolean('is_visible')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->primary(['category_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_stores');
        Schema::dropIfExists('category_translations');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brand_translations');
        Schema::dropIfExists('brands');
    }
};
