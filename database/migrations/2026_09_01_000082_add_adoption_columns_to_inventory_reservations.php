<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE-08 Prerequisite: Inventory Reservation Adoption/Retention Patch
 *
 * Adds Inventory-owned ownership and indefinite-retention columns to
 * inventory_reservations, enabling Order-adopted reservations to outlive the
 * original Checkout TTL without any Order -> Inventory persistence coupling.
 *
 * Critical: the existing accepted migration 000043 defines expires_at as NOT NULL.
 * The existing PostgreSQL database therefore has expires_at NOT NULL.
 * This migration performs the forward schema alteration on the EXISTING database.
 *
 * Semantics after this migration:
 *  - expires_at: nullable — null means indefinitely retained (adopted reservation).
 *  - owner_type:  Inventory-owned opaque owner type (no FK to Order).
 *  - owner_reference: Opaque owner reference (e.g. Order UUID, no FK).
 *  - adopted_at:  Timestamp of adoption for audit.
 *  - ExpireReservationsCommand skips rows where owner_type IS NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. ALTER expires_at to nullable on the existing database.
        //    We do NOT use Blueprint->change() because it requires doctrine/dbal
        //    and has different SQL generation depending on the driver version.
        //    We use a direct DDL statement that is correct and safe on PostgreSQL.
        //    On SQLite (test environment), Laravel's Schema::table()->nullable()->change()
        //    is applied conditionally.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_reservations ALTER COLUMN expires_at DROP NOT NULL');
        } else {
            // SQLite: use Blueprint change() for the test environment
            Schema::table('inventory_reservations', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable()->change();
            });
        }

        // 2. Add adoption ownership and audit columns
        Schema::table('inventory_reservations', function (Blueprint $table) {
            // Inventory-owned opaque owner type (e.g. 'checkout', 'order')
            $table->string('owner_type', 50)->nullable()->after('metadata');
            // Opaque owner reference (e.g. Order UUID — NOT a foreign key, no Order coupling)
            $table->string('owner_reference', 128)->nullable()->after('owner_type');
            // Timestamp when reservation was adopted, for auditing
            $table->timestamp('adopted_at')->nullable()->after('owner_reference');

            // Composite index for cleanup command: skips adopted reservations efficiently
            $table->index(
                ['tenant_id', 'status', 'owner_type', 'expires_at'],
                'inv_res_cleanup_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->dropIndex('inv_res_cleanup_index');
            $table->dropColumn(['owner_type', 'owner_reference', 'adopted_at']);
        });

        // Revert expires_at back to NOT NULL.
        // Note: any row that currently has expires_at = null (adopted reservation)
        // MUST have been released/committed before rollback, or the rollback will fail.
        // This is intentional: rolling back an active adoption patch is unsafe.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_reservations ALTER COLUMN expires_at SET NOT NULL');
        } else {
            Schema::table('inventory_reservations', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable(false)->change();
            });
        }
    }
};
