<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Products ───────────────────────────────────────────────────────────
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('attribute_set_id')->nullable()->constrained('attribute_sets')->nullOnDelete();
            $table->string('product_type', 100);
            $table->string('sku', 150);
            $table->string('barcode', 100)->nullable();
            $table->string('mpn', 100)->nullable();
            $table->enum('status', ['draft', 'active', 'inactive', 'archived'])->default('draft');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'product_type', 'status']);
            $table->index(['tenant_id', 'brand_id']);
        });

        Schema::create('product_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 255);
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'locale']);
        });

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->primary(['product_id', 'category_id']);
        });

        // ── Typed Attribute Values ─────────────────────────────────────────────
        Schema::create('product_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->text('text_value')->nullable();
            $table->bigInteger('int_value')->nullable();
            $table->decimal('decimal_value', 18, 4)->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->date('date_value')->nullable();
            $table->dateTime('datetime_value')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->jsonb('json_value')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'attribute_id']);
            $table->index(['attribute_id', 'int_value']);
            $table->index(['attribute_id', 'decimal_value']);
            $table->index(['attribute_id', 'boolean_value']);
        });

        // Relational Select / Multiselect Option Values
        Schema::create('product_attribute_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->foreignId('attribute_option_id')->constrained('attribute_options')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'attribute_id', 'attribute_option_id']);
            $table->index(['attribute_id', 'attribute_option_id']);
        });

        // ── Variants ───────────────────────────────────────────────────────────
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku', 150);
            $table->string('barcode', 100)->nullable();
            $table->string('combination_hash', 64);
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $table->integer('sort_order')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'combination_hash']);
            $table->index(['product_id', 'sku']);
            $table->index(['product_id', 'status']);
        });

        Schema::create('product_variant_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->foreignId('attribute_option_id')->constrained('attribute_options')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['variant_id', 'attribute_id']);
            $table->index(['attribute_id', 'attribute_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_options');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_attribute_options');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('product_translations');
        Schema::dropIfExists('products');
    }
};
