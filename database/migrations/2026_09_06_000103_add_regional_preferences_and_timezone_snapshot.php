<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-18 §16/§17 (guest/user preferences) + Owner Delta §10 (historical
 * Order timezone must be frozen, not re-resolved from a mutable Market).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->string('preferred_locale', 35)->nullable()->after('notification_preferences');
            $table->string('preferred_currency', 3)->nullable()->after('preferred_locale');
            $table->string('preferred_timezone', 50)->nullable()->after('preferred_currency');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('timezone_snapshot', 50)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('timezone_snapshot');
        });

        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->dropColumn(['preferred_locale', 'preferred_currency', 'preferred_timezone']);
        });
    }
};
