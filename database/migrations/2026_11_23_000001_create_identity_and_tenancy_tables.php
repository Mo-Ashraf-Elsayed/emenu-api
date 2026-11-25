<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 254)->unique();
            $table->string('password');
            $table->timestampTz('email_verified_at')->nullable();
            $table->boolean('is_platform_admin')->default(false);
            $table->unsignedBigInteger('session_version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email', 254)->primary();
            $table->string('token');
            $table->timestampTz('created_at');
            $table->foreign('email')->references('email')->on('users')->restrictOnDelete();
        });

        Schema::create('restaurants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 63)->unique();
            $table->char('currency', 3);
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['created_at', 'id']);
        });

        Schema::create('restaurant_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->enum('role', ['owner', 'manager', 'staff']);
            $table->enum('status', ['active', 'revoked'])->default('active');
            $table->timestampsTz();
            $table->unique(['restaurant_id', 'user_id']);
            $table->index(['user_id', 'status', 'restaurant_id']);
            $table->index(['restaurant_id', 'status', 'created_at', 'id'], 'memberships_restaurant_listing');
        });

        DB::statement("CREATE UNIQUE INDEX memberships_one_active_owner ON restaurant_memberships (restaurant_id) WHERE role = 'owner' AND status = 'active'");

        Schema::create('restaurant_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('restaurant_id')->constrained()->restrictOnDelete();
            $table->string('email', 254);
            $table->enum('role', ['manager', 'staff']);
            $table->enum('status', ['pending', 'accepted', 'revoked', 'expired'])->default('pending');
            $table->uuid('inviter_id');
            $table->char('token_digest', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->uuid('accepted_user_id')->nullable();
            $table->foreign(['restaurant_id', 'inviter_id'], 'invitations_inviter_membership')
                ->references(['restaurant_id', 'user_id'])->on('restaurant_memberships')->restrictOnDelete();
            $table->foreign(['restaurant_id', 'accepted_user_id'], 'invitations_recipient_membership')
                ->references(['restaurant_id', 'user_id'])->on('restaurant_memberships')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['restaurant_id', 'created_at', 'id'], 'invitations_restaurant_listing');
            $table->index(['restaurant_id', 'inviter_id']);
            $table->index(['restaurant_id', 'accepted_user_id']);
        });

        DB::statement("CREATE UNIQUE INDEX invitations_one_pending_email ON restaurant_invitations (restaurant_id, email) WHERE status = 'pending'");

        Schema::create('restaurant_branding', function (Blueprint $table): void {
            $table->foreignUuid('restaurant_id')->primary()->constrained()->restrictOnDelete();
            $table->string('display_name_en', 120);
            $table->string('display_name_ar', 120);
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->char('theme_color', 7)->default('#08735b');
            $table->timestampsTz();
        });

        Schema::create('public_menu_settings', function (Blueprint $table): void {
            $table->foreignUuid('restaurant_id')->primary()->constrained()->restrictOnDelete();
            $table->enum('default_locale', ['en', 'ar'])->default('en');
            $table->unsignedBigInteger('draft_revision')->default(1);
            $table->unsignedBigInteger('publication_revision')->default(0);
            $table->unsignedBigInteger('published_source_revision')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->jsonb('snapshot')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_positive_session_version CHECK (session_version >= 1)');
        DB::statement("ALTER TABLE restaurants ADD CONSTRAINT restaurants_slug_shape CHECK (length(slug) BETWEEN 3 AND 63 AND slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE restaurant_memberships ADD CONSTRAINT memberships_active_owner CHECK (role <> 'owner' OR status = 'active')");
        DB::statement("ALTER TABLE restaurant_invitations ADD CONSTRAINT invitations_acceptance_state CHECK ((status = 'accepted' AND accepted_at IS NOT NULL AND accepted_user_id IS NOT NULL) OR (status <> 'accepted' AND accepted_at IS NULL AND accepted_user_id IS NULL))");
        DB::statement('ALTER TABLE public_menu_settings ADD CONSTRAINT menu_publication_state CHECK ((published_source_revision IS NULL AND published_at IS NULL AND snapshot IS NULL) OR (published_source_revision IS NOT NULL AND published_at IS NOT NULL AND snapshot IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('public_menu_settings');
        Schema::dropIfExists('restaurant_branding');
        Schema::dropIfExists('restaurant_invitations');
        Schema::dropIfExists('restaurant_memberships');
        Schema::dropIfExists('restaurants');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
