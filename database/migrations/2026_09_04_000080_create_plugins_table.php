<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugins', function (Blueprint $table): void {
            $table->id();
            $table->string('plugin_id', 150);
            $table->string('name');
            $table->string('version', 50);
            $table->string('status', 30)->default('discovered');
            $table->string('trust_level', 30)->default('unverified');
            $table->jsonb('manifest_snapshot');
            $table->jsonb('granted_permissions')->nullable();
            $table->timestamp('permissions_approved_at')->nullable();
            $table->unsignedInteger('consecutive_boot_failures')->default(0);
            $table->unsignedInteger('last_migration_batch')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique('plugin_id', 'uq_plugins_plugin_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugins');
    }
};
