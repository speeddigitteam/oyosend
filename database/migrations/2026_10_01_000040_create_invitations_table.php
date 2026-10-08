<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email');
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            // SHA-256 of the emailed token. The token itself is never stored.
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // "Only one open invitation per address" cannot be a unique index
            // (accepted and revoked rows are kept), so it is enforced in
            // InviteMember under a row lock; this index serves that lookup.
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'accepted_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
