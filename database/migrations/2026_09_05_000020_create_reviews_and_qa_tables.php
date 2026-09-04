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
        $isPgsql = DB::getDriverName() === 'pgsql';

        // ── Product Reviews ────────────────────────────────────────────────────
        Schema::create('product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 150)->nullable();
            $table->text('body');
            $table->boolean('is_verified_purchase')->default(false);
            $table->string('status', 20)->default('pending');
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_reason')->nullable();
            $table->unsignedInteger('helpful_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id', 'user_id']);
            $table->index(['tenant_id', 'product_id', 'status']);
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE product_reviews ADD CONSTRAINT chk_product_reviews_rating CHECK (rating BETWEEN 1 AND 5)');
            DB::statement("ALTER TABLE product_reviews ADD CONSTRAINT chk_product_reviews_status CHECK (status IN ('pending', 'approved', 'rejected', 'flagged'))");
        }

        Schema::create('product_review_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_review_id')->constrained('product_reviews')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->string('status', 20)->default('approved');
            $table->timestamps();
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE product_review_replies ADD CONSTRAINT chk_product_review_replies_status CHECK (status IN ('pending', 'approved', 'rejected'))");
        }

        Schema::create('product_rating_aggregates', function (Blueprint $table): void {
            $table->foreignId('product_id')->primary()->constrained('products')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->timestamp('updated_at')->useCurrent();
        });

        // ── Vendor Reviews ─────────────────────────────────────────────────────
        Schema::create('vendor_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->unsignedTinyInteger('communication_rating')->nullable();
            $table->unsignedTinyInteger('shipping_rating')->nullable();
            $table->string('title', 150)->nullable();
            $table->text('body');
            $table->boolean('is_verified_purchase')->default(false);
            $table->string('status', 20)->default('pending');
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_reason')->nullable();
            $table->unsignedInteger('helpful_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'vendor_id', 'user_id']);
            $table->index(['tenant_id', 'vendor_id', 'status']);
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE vendor_reviews ADD CONSTRAINT chk_vendor_reviews_rating CHECK (rating BETWEEN 1 AND 5)');
            DB::statement('ALTER TABLE vendor_reviews ADD CONSTRAINT chk_vendor_reviews_communication_rating CHECK (communication_rating IS NULL OR communication_rating BETWEEN 1 AND 5)');
            DB::statement('ALTER TABLE vendor_reviews ADD CONSTRAINT chk_vendor_reviews_shipping_rating CHECK (shipping_rating IS NULL OR shipping_rating BETWEEN 1 AND 5)');
            DB::statement("ALTER TABLE vendor_reviews ADD CONSTRAINT chk_vendor_reviews_status CHECK (status IN ('pending', 'approved', 'rejected', 'flagged'))");
        }

        Schema::create('vendor_review_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_review_id')->constrained('vendor_reviews')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->string('status', 20)->default('approved');
            $table->timestamps();
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE vendor_review_replies ADD CONSTRAINT chk_vendor_review_replies_status CHECK (status IN ('pending', 'approved', 'rejected'))");
        }

        Schema::create('vendor_rating_aggregates', function (Blueprint $table): void {
            $table->foreignId('vendor_id')->primary()->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->timestamp('updated_at')->useCurrent();
        });

        // ── Product Q&A ────────────────────────────────────────────────────────
        Schema::create('product_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('upvote_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'status']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE product_questions ADD CONSTRAINT chk_product_questions_status CHECK (status IN ('pending', 'approved', 'rejected'))");
        }

        Schema::create('product_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained('product_questions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_vendor_answer')->default(false);
            $table->text('body');
            $table->string('status', 20)->default('pending');
            $table->boolean('is_accepted')->default(false);
            $table->timestamps();
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE product_answers ADD CONSTRAINT chk_product_answers_status CHECK (status IN ('pending', 'approved', 'rejected'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_answers');
        Schema::dropIfExists('product_questions');
        Schema::dropIfExists('vendor_rating_aggregates');
        Schema::dropIfExists('vendor_review_replies');
        Schema::dropIfExists('vendor_reviews');
        Schema::dropIfExists('product_rating_aggregates');
        Schema::dropIfExists('product_review_replies');
        Schema::dropIfExists('product_reviews');
    }
};
