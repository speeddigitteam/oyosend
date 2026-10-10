<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings the operator changes from the Super Admin panel. A missing
        // key falls back to config/platform.php.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->timestamps();
        });

        // A Super Admin working inside a workspace as one of its members.
        // Every row carries the reason, and the session it opened ends at
        // `expires_at` at the latest.
        Schema::create('impersonations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('platform_user_id')->constrained('platform_users')->cascadeOnDelete();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 500);
            // SHA-256 of the one-time token that carries the session to the
            // workspace's host; cleared when it is redeemed.
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['platform_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonations');
        Schema::dropIfExists('platform_settings');
    }
};
