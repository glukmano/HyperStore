<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['website', 'mobile_app', 'pos', 'b2b', 'marketplace', 'custom'])->default('website');
            $table->string('name', 100);
            $table->string('handle', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->jsonb('settings')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        Schema::create('store_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->jsonb('settings')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'channel_id']);
        });

        Schema::create('markets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 50);
            $table->boolean('is_active')->default(true);
            $table->string('default_currency_code', 3);
            $table->string('default_locale_code', 10);
            $table->string('timezone', 50)->default('UTC');
            $table->jsonb('settings')->nullable();
            $table->timestamps();

            $table->foreign('default_currency_code')->references('code')->on('currencies')->cascadeOnUpdate();
            $table->foreign('default_locale_code')->references('code')->on('languages')->cascadeOnUpdate();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('market_countries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->timestamps();

            $table->foreign('country_code')->references('iso2')->on('countries')->cascadeOnDelete();
            $table->unique(['market_id', 'country_code']);
        });

        Schema::create('market_currencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->string('currency_code', 3);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('currencies')->cascadeOnDelete();
            $table->unique(['market_id', 'currency_code']);
        });

        Schema::create('market_languages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->string('locale_code', 10);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('locale_code')->references('code')->on('languages')->cascadeOnDelete();
            $table->unique(['market_id', 'locale_code']);
        });

        Schema::create('store_markets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'market_id']);
        });

        Schema::create('market_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['market_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_channels');
        Schema::dropIfExists('store_markets');
        Schema::dropIfExists('market_languages');
        Schema::dropIfExists('market_currencies');
        Schema::dropIfExists('market_countries');
        Schema::dropIfExists('markets');
        Schema::dropIfExists('store_channels');
        Schema::dropIfExists('channels');
    }
};
