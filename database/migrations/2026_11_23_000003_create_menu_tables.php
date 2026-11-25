<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->string('description_en', 1000)->nullable();
            $table->string('description_ar', 1000)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_visible')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['restaurant_id', 'id']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('restaurant_id')->constrained()->restrictOnDelete();
            $table->uuid('category_id');
            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->string('description_en', 1000)->nullable();
            $table->string('description_ar', 1000)->nullable();
            $table->decimal('price_amount', 14, 4);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_visible')->default(false);
            $table->boolean('is_available')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['restaurant_id', 'id']);
            $table->index(['restaurant_id', 'category_id']);
            $table->foreign(['restaurant_id', 'category_id'])->references(['restaurant_id', 'id'])
                ->on('categories')->restrictOnDelete();
        });

        Schema::create('media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('restaurant_id')->constrained()->restrictOnDelete();
            $table->uuid('storage_id')->unique();
            $table->enum('status', ['pending', 'ready', 'failed', 'deleted'])->default('pending');
            $table->enum('mime_type', ['image/jpeg', 'image/png', 'image/webp']);
            $table->integer('byte_size');
            $table->integer('width');
            $table->integer('height');
            $table->jsonb('variants')->nullable();
            $table->enum('failure_code', ['invalid_image', 'processing_failed'])->nullable();
            $table->timestampTz('source_cleaned_at')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->timestampsTz();
            $table->unique(['restaurant_id', 'id']);
            $table->index(['restaurant_id', 'status', 'created_at', 'id'], 'media_restaurant_status_listing');
        });

        Schema::create('product_media', function (Blueprint $table): void {
            $table->uuid('restaurant_id');
            $table->uuid('product_id');
            $table->uuid('media_id');
            $table->integer('sort_order');
            $table->primary(['restaurant_id', 'product_id', 'media_id']);
            $table->unique(['restaurant_id', 'product_id', 'sort_order']);
            $table->index(['restaurant_id', 'media_id']);
            $table->foreign(['restaurant_id', 'product_id'])->references(['restaurant_id', 'id'])
                ->on('products')->cascadeOnDelete();
            $table->foreign(['restaurant_id', 'media_id'])->references(['restaurant_id', 'id'])
                ->on('media')->restrictOnDelete();
        });

        Schema::table('restaurant_branding', function (Blueprint $table): void {
            foreach (['logo_media_id', 'cover_media_id'] as $field) {
                $table->uuid($field)->nullable();
                $table->index(['restaurant_id', $field]);
                $table->foreign(['restaurant_id', $field])->references(['restaurant_id', 'id'])
                    ->on('media')->restrictOnDelete();
            }
        });

        DB::statement('CREATE INDEX categories_live_position ON categories (restaurant_id, sort_order, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX products_live_position ON products (restaurant_id, sort_order, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX products_live_category_position ON products (restaurant_id, category_id, sort_order, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX media_reconciliation ON media (updated_at, id) WHERE purged_at IS NULL');
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_sort_bounds CHECK (sort_order BETWEEN 0 AND 10000)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_sort_bounds CHECK (sort_order BETWEEN 0 AND 10000)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_finite_price CHECK (price_amount >= 0 AND price_amount <> 'NaN'::numeric)");
        DB::statement('ALTER TABLE product_media ADD CONSTRAINT product_media_sort_bounds CHECK (sort_order BETWEEN 0 AND 3)');
        DB::statement('ALTER TABLE media ADD CONSTRAINT media_size_bounds CHECK (byte_size BETWEEN 1 AND 8388608 AND width BETWEEN 1 AND 4096 AND height BETWEEN 1 AND 4096 AND width * height <= 16000000)');
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_variants_state CHECK ((status = 'ready') = (variants IS NOT NULL) AND (status = 'failed') = (failure_code IS NOT NULL) AND (purged_at IS NULL OR status = 'deleted'))");
        DB::statement("ALTER TABLE restaurant_branding ADD CONSTRAINT branding_theme_color CHECK (theme_color ~ '^#[0-9A-Fa-f]{6}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE restaurant_branding DROP CONSTRAINT branding_theme_color');
        Schema::table('restaurant_branding', function (Blueprint $table): void {
            foreach (['logo_media_id', 'cover_media_id'] as $field) {
                $table->dropForeign(['restaurant_id', $field]);
                $table->dropColumn($field);
            }
        });
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('media');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
