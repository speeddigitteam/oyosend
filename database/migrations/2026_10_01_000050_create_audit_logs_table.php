<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // null = a platform-level event (Super Admin panel, system).
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            // The actor is a workspace user, a platform user, or null for the system.
            $table->string('actor_type', 40)->nullable();
            $table->ulid('actor_id')->nullable();
            // Denormalised so the trail still reads after the actor is deleted.
            $table->string('actor_label')->nullable();
            $table->string('event', 100);
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 40)->nullable();
            $table->string('subject_label')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            // Append-only: there is no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'event']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
