<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE-08 Prerequisite: Inventory Reservation Adoption/Retention Patch
 *
 * Enables Order-domain adoption of Checkout reservations without any
 * Order → Inventory persistence coupling.
 *
 * Adds to inventory_reservations:
 *  - expires_at: made nullable (null = indefinitely retained, i.e. adopted).
 *  - owner_type:      Inventory-owned opaque owner type (e.g. 'order', 'checkout').
 *  - owner_reference: Opaque owner reference (e.g. Order UUID). No FK, no coupling.
 *  - adopted_at:      Timestamp of adoption for auditing.
 *  - inv_res_cleanup_index: Composite index enabling fast skip of adopted rows in
 *                           the automatic expiration sweep.
 *
 * Schema authority:
 *  - Migration 000043 (accepted baseline) defines expires_at NOT NULL.
 *  - This migration performs the forward schema alteration on the existing database.
 *  - expires_at nullability on SQLite (test env) is handled via Blueprint->change().
 *
 * Rollback pre-condition:
 *  down() refuses to execute if any row has expires_at IS NULL (adopted reservation).
 *  Adopted reservations must be released or committed before rollback, or the DDL
 *  SET NOT NULL would fail at the DB level anyway, leaving the schema partially altered.
 *  Explicit pre-condition check provides a clear, early, safe failure message.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. ALTER expires_at to nullable.
        //    PostgreSQL: direct DDL (safe and efficient on existing database).
        //    SQLite (test env): Blueprint->change() via doctrine/dbal.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_reservations ALTER COLUMN expires_at DROP NOT NULL');
        } else {
            Schema::table('inventory_reservations', function (Blueprint $table): void {
                $table->timestamp('expires_at')->nullable()->change();
            });
        }

        // 2. Add adoption ownership and audit columns.
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->string('owner_type', 50)->nullable()->after('metadata');
            $table->string('owner_reference', 128)->nullable()->after('owner_type');
            $table->timestamp('adopted_at')->nullable()->after('owner_reference');

            // Composite index: allows ExpireReservationsCommand to efficiently skip
            // adopted rows (owner_type IS NOT NULL) without a sequential scan.
            $table->index(
                ['tenant_id', 'status', 'owner_type', 'expires_at'],
                'inv_res_cleanup_index'
            );
        });
    }

    public function down(): void
    {
        // Pre-condition: refuse rollback if any adopted rows exist.
        // Adopted rows have expires_at IS NULL. SET NOT NULL would fail at the DB
        // level for these rows, leaving the schema partially altered. Fail early.
        $adoptedCount = DB::table('inventory_reservations')
            ->whereNull('expires_at')
            ->count();

        if ($adoptedCount > 0) {
            throw new RuntimeException(
                "MIGRATION ROLLBACK REFUSED: {$adoptedCount} inventory_reservation row(s) have expires_at IS NULL ".
                '(adopted/indefinitely retained). Rolling back migration 000082 would fail at the database level '.
                'when attempting to SET NOT NULL. Release or commit all adopted reservations before rolling back.'
            );
        }

        // Safe to proceed — no adopted rows exist.
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropIndex('inv_res_cleanup_index');
            $table->dropColumn(['owner_type', 'owner_reference', 'adopted_at']);
        });

        // Revert expires_at to NOT NULL.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_reservations ALTER COLUMN expires_at SET NOT NULL');
        } else {
            Schema::table('inventory_reservations', function (Blueprint $table): void {
                $table->timestamp('expires_at')->nullable(false)->change();
            });
        }
    }
};
