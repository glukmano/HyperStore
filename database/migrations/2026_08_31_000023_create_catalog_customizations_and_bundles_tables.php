<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Customizable Customer Input Fields ─────────────────────────────────
        Schema::create('product_custom_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->enum('type', ['text', 'textarea', 'select', 'checkbox', 'date', 'file', 'number']);
            $table->string('code', 100);
            $table->boolean('is_required')->default(false);
            $table->jsonb('validation_rules')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'code']);
            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('product_custom_field_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_custom_field_id')->constrained('product_custom_fields', 'id', 'pcf_trans_pcf_id_fk')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('label', 255);
            $table->string('help_text', 500)->nullable();
            $table->string('placeholder', 255)->nullable();
            $table->timestamps();

            $table->unique(['product_custom_field_id', 'locale'], 'pcf_trans_id_locale_unique');
        });

        Schema::create('product_custom_field_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_custom_field_id')->constrained('product_custom_fields', 'id', 'pcf_opt_pcf_id_fk')->cascadeOnDelete();
            $table->string('code', 100);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_custom_field_id', 'code'], 'pcf_opt_pcf_id_code_unique');
        });

        Schema::create('product_custom_field_option_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_custom_field_option_id')->constrained('product_custom_field_options', 'id', 'pcf_opt_trans_opt_id_fk')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('label', 255);
            $table->timestamps();

            $table->unique(['product_custom_field_option_id', 'locale'], 'pcf_opt_trans_opt_id_loc_unique');
        });

        // ── Bundle / Composite Items ───────────────────────────────────────────
        Schema::create('product_bundle_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('item_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('item_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->integer('quantity')->default(1);
            $table->boolean('is_optional')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['parent_product_id', 'sort_order']);
        });

        // ── Extensible Product Relationships ───────────────────────────────────
        Schema::create('product_relationships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('type', 100); // e.g. related, alternative, accessory, cross_sell, up_sell
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'related_product_id', 'type'], 'prod_rel_prod_related_type_unique');
            $table->index(['product_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_relationships');
        Schema::dropIfExists('product_bundle_items');
        Schema::dropIfExists('product_custom_field_option_translations');
        Schema::dropIfExists('product_custom_field_options');
        Schema::dropIfExists('product_custom_field_translations');
        Schema::dropIfExists('product_custom_fields');
    }
};
