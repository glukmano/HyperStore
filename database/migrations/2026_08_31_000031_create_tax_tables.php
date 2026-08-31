<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tax Classes
        Schema::create('tax_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // 2. Tax Zones
        Schema::create('tax_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('country_code', 2)->nullable()->index();
            $table->string('state_code', 50)->nullable()->index();
            $table->string('postal_code_pattern')->nullable();
            $table->integer('priority')->default(0)->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // 3. Tax Rates
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tax_class_id')->constrained('tax_classes')->cascadeOnDelete();
            $table->foreignId('tax_zone_id')->constrained('tax_zones')->cascadeOnDelete();

            $table->string('name');
            $table->decimal('rate_percentage', 8, 4); // e.g. 7.7000% or 19.0000%
            $table->boolean('is_compound')->default(false);
            $table->integer('priority')->default(0)->index();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'tax_class_id', 'tax_zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_zones');
        Schema::dropIfExists('tax_classes');
    }
};
