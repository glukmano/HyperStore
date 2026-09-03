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
        // 1. Ensure tenants.status supports provisioning, active, suspended, terminated
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tenants ALTER COLUMN status TYPE VARCHAR(30)');
        }

        // 2. platform_saas_plans
        Schema::create('platform_saas_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('status', 30)->default('active'); // draft, active, deprecated, retired
            $table->jsonb('limits')->default('{}');
            $table->jsonb('feature_entitlements')->default('{}');
            $table->jsonb('billing_metadata')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // 3. tenant_licenses
        Schema::create('tenant_licenses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('platform_saas_plan_id')->constrained('platform_saas_plans')->restrictOnDelete();
            $table->string('license_key_hash', 64)->unique();
            $table->string('status', 30)->default('active'); // active, suspended, expired
            $table->timestampTz('valid_until')->nullable();
            $table->jsonb('override_limits')->default('{}');
            $table->jsonb('override_features')->default('{}');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // 4. platform_releases
        Schema::create('platform_releases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('version', 50)->unique();
            $table->string('channel', 30)->default('stable');
            $table->string('status', 30)->default('draft'); // draft, published, withdrawn
            $table->text('release_notes');
            $table->jsonb('compatibility_metadata')->default('{}');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
        });

        // 5. official_extensions
        Schema::create('official_extensions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 100)->unique();
            $table->string('name', 255);
            $table->string('publisher_name', 255);
            $table->string('category', 50);
            $table->string('status', 30)->default('draft'); // draft, approved, published, suspended, withdrawn
            $table->string('approved_version', 50)->nullable();
            $table->jsonb('compatibility_metadata')->default('{}');
            $table->string('visibility', 30)->default('public'); // public, private, restricted
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();

            $table->index(['category', 'status']);
        });

        // 6. platform_settings
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value');
            $table->boolean('is_encrypted')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 7. impersonation_sessions
        Schema::create('impersonation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('impersonator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('status', 30)->default('active'); // active, terminated, revoked, expired
            $table->string('token_hash', 64)->unique();
            $table->string('reason', 255);
            $table->timestampTz('started_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('terminated_at')->nullable();
            $table->string('termination_reason', 50)->nullable();
            $table->timestamps();

            $table->index(['impersonator_user_id', 'status']);
            $table->index(['target_user_id', 'status']);
        });

        // Partial unique index: strictly prevent more than one active session per impersonator
        DB::statement("
            CREATE UNIQUE INDEX unique_active_impersonation_per_impersonator 
            ON impersonation_sessions (impersonator_user_id) 
            WHERE status = 'active'
        ");

        // 8. impersonation_events
        Schema::create('impersonation_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('session_uuid');
            $table->string('event_type', 50); // started, terminated, revoked, expired, privileged_action_blocked
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at');

            $table->index('session_uuid');
            $table->index('event_type');
        });

        // 9. Append-only trigger on impersonation_events in PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                CREATE OR REPLACE FUNCTION prevent_impersonation_events_mutation()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION \'impersonation_events is append-only and rejects UPDATE or DELETE.\';
                END;
                $$ LANGUAGE plpgsql;
            ');

            DB::statement('
                CREATE TRIGGER trg_prevent_impersonation_events_mutation
                BEFORE UPDATE OR DELETE ON impersonation_events
                FOR EACH ROW
                EXECUTE FUNCTION prevent_impersonation_events_mutation();
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_prevent_impersonation_events_mutation ON impersonation_events;');
            DB::statement('DROP FUNCTION IF EXISTS prevent_impersonation_events_mutation();');
        }

        DB::statement('DROP INDEX IF EXISTS unique_active_impersonation_per_impersonator;');

        Schema::dropIfExists('impersonation_events');
        Schema::dropIfExists('impersonation_sessions');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('official_extensions');
        Schema::dropIfExists('platform_releases');
        Schema::dropIfExists('tenant_licenses');
        Schema::dropIfExists('platform_saas_plans');
    }
};
