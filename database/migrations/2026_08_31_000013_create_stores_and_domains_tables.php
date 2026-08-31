<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('slug', 100);
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->enum('customer_account_scope_override', ['tenant_default', 'store_isolated', 'tenant_wide'])->default('tenant_default');
            $table->jsonb('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('store_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('domain', 255)->unique();
            $table->enum('type', ['primary', 'custom', 'subdomain'])->default('subdomain');
            $table->boolean('is_verified')->default(true);
            $table->boolean('canonical')->default(false);
            $table->timestamps();

            $table->index(['store_id', 'is_verified']);
        });

        Schema::create('store_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 50)->default('staff');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'user_id']);
            $table->index(['store_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_users');
        Schema::dropIfExists('store_domains');
        Schema::dropIfExists('stores');
    }
};
