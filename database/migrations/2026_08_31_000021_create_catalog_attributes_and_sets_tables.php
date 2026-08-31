<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Attribute Sets ─────────────────────────────────────────────────────
        Schema::create('attribute_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('code', 100);
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('attribute_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_set_id')->constrained('attribute_sets')->cascadeOnDelete();
            $table->string('name', 255);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['attribute_set_id', 'sort_order']);
        });

        // ── Attributes ─────────────────────────────────────────────────────────
        Schema::create('attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 100);
            $table->enum('type', [
                'text', 'textarea', 'integer', 'decimal', 'boolean',
                'date', 'datetime', 'select', 'multiselect', 'color',
                'measurement', 'url', 'file',
            ]);
            $table->jsonb('validation_rules')->nullable();
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_comparable')->default(false);
            $table->boolean('is_variant_driving')->default(false);
            $table->boolean('is_visible_on_front')->default(true);
            $table->integer('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'type', 'status']);
            $table->index(['tenant_id', 'is_filterable']);
        });

        Schema::create('attribute_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('unit_label', 50)->nullable();
            $table->timestamps();

            $table->unique(['attribute_id', 'locale']);
        });

        Schema::create('attribute_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('color_code', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['attribute_id', 'code']);
            $table->index(['attribute_id', 'sort_order']);
        });

        Schema::create('attribute_option_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_option_id')->constrained('attribute_options')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('label', 255);
            $table->timestamps();

            $table->unique(['attribute_option_id', 'locale']);
        });

        Schema::create('attribute_set_attributes', function (Blueprint $table): void {
            $table->foreignId('attribute_set_id')->constrained('attribute_sets')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->foreignId('attribute_group_id')->nullable()->constrained('attribute_groups')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->primary(['attribute_set_id', 'attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_set_attributes');
        Schema::dropIfExists('attribute_option_translations');
        Schema::dropIfExists('attribute_options');
        Schema::dropIfExists('attribute_translations');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('attribute_groups');
        Schema::dropIfExists('attribute_sets');
    }
};
