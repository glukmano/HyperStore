<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->after('is_super_admin');
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('default_locale', 10)->default('en')->after('status');

            $table->index('is_super_admin');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_super_admin']);
            $table->dropIndex(['status']);
            $table->dropColumn(['is_super_admin', 'status', 'phone', 'default_locale']);
        });
    }
};
