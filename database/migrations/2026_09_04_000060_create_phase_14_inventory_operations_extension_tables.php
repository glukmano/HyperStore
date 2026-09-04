<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Warehouse taxonomy split: ownership_type + vendor_id (ADR-0122, ADR-0123)
        // `type` (facility function) becomes nullable: per ADR-0122, an unresolved/unknown
        // facility classification is left explicitly NULL rather than fabricated.
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->string('type', 50)->nullable()->default(null)->change();
            $table->string('ownership_type', 32)->nullable()->after('type');
            $table->unsignedBigInteger('vendor_id')->nullable()->after('ownership_type');
        });

        // Source-provable legacy backfill: schema default 'owned' maps to ownership, not facility.
        // 'type' (facility) is left untouched for those rows — no fabricated facility value.
        DB::table('warehouses')->where('type', 'owned')->update(['ownership_type' => 'platform']);

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'uq_warehouses_tenant_id');
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_warehouses_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->restrictOnDelete();
        });

        // 2. inventory_sources: composite tenant-pair unique, prerequisite for composite FKs (ADR-0126)
        Schema::table('inventory_sources', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'uq_inventory_sources_tenant_id');
        });

        // 3. inventory_transfers: composite tenant-pair unique (prerequisite for
        // inventory_transfer_items' composite FK below) + composite FKs to inventory_sources (ADR-0126)
        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'uq_inventory_transfers_tenant_id');
        });

        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'source_inventory_source_id'], 'fk_it_tenant_source')
                ->references(['tenant_id', 'id'])->on('inventory_sources')->restrictOnDelete();
            $table->foreign(['tenant_id', 'destination_inventory_source_id'], 'fk_it_tenant_destination')
                ->references(['tenant_id', 'id'])->on('inventory_sources')->restrictOnDelete();
        });

        // 4. inventory_transfer_items: add tenant_id, source-provable backfill, composite FK (ADR-0126)
        Schema::table('inventory_transfer_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });

        // Portable correlated-subquery UPDATE (works identically on PostgreSQL and SQLite/testing)
        DB::statement(<<<'SQL'
            UPDATE inventory_transfer_items
            SET tenant_id = (
                SELECT tenant_id FROM inventory_transfers
                WHERE inventory_transfers.id = inventory_transfer_items.inventory_transfer_id
            )
        SQL);

        Schema::table('inventory_transfer_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
            $table->foreign(['tenant_id', 'inventory_transfer_id'], 'fk_iti_tenant_transfer')
                ->references(['tenant_id', 'id'])->on('inventory_transfers')->restrictOnDelete();
        });

        // 5. inventory_operation_keys: payload-hash for conflicting-payload detection (ADR-0125, ADR-0126)
        Schema::table('inventory_operation_keys', function (Blueprint $table): void {
            $table->string('payload_hash', 64)->nullable()->after('idempotency_key');
        });

        // 6. return_items: RMA physical-disposition contract fields (ADR-0128)
        Schema::table('return_items', function (Blueprint $table): void {
            $table->uuid('disposition_operation_uuid')->nullable()->after('restock_action');
            $table->unsignedBigInteger('destination_inventory_source_id')->nullable()->after('disposition_operation_uuid');
            $table->timestampTz('disposed_at')->nullable()->after('destination_inventory_source_id');
        });

        Schema::table('return_items', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'destination_inventory_source_id'], 'fk_ri_tenant_dest_source')
                ->references(['tenant_id', 'id'])->on('inventory_sources')->restrictOnDelete();
            $table->unique(['seller_return_id', 'order_item_id', 'disposition_operation_uuid'], 'uq_ri_disposition_op');
        });

        // 7. seller_returns: inspected_at marker (physical disposition complete), mirroring received_at/approved_at
        Schema::table('seller_returns', function (Blueprint $table): void {
            $table->timestampTz('inspected_at')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('seller_returns', function (Blueprint $table): void {
            $table->dropColumn('inspected_at');
        });

        Schema::table('return_items', function (Blueprint $table): void {
            $table->dropUnique('uq_ri_disposition_op');
            $table->dropForeign('fk_ri_tenant_dest_source');
            $table->dropColumn(['disposition_operation_uuid', 'destination_inventory_source_id', 'disposed_at']);
        });

        Schema::table('inventory_operation_keys', function (Blueprint $table): void {
            $table->dropColumn('payload_hash');
        });

        Schema::table('inventory_transfer_items', function (Blueprint $table): void {
            $table->dropForeign('fk_iti_tenant_transfer');
            $table->dropColumn('tenant_id');
        });

        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->dropForeign('fk_it_tenant_source');
            $table->dropForeign('fk_it_tenant_destination');
            $table->dropUnique('uq_inventory_transfers_tenant_id');
        });

        Schema::table('inventory_sources', function (Blueprint $table): void {
            $table->dropUnique('uq_inventory_sources_tenant_id');
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropForeign('fk_warehouses_vendor');
            $table->dropUnique('uq_warehouses_tenant_id');
            $table->dropColumn(['ownership_type', 'vendor_id']);
        });
    }
};
