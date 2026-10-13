<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A platform-wide registry, deliberately NOT tenant-owned (no `tenant_id`, so its unique index is not a
        // per-tenant one): it exists to make a provider account (a WhatsApp `phone_number_id`) usable by one
        // workspace only, which a per-tenant unique index cannot express. The owner is the connection (a
        // globally unique ULID, deleted with its workspace). It is written only by ChannelConnections::save()
        // and never read across tenants.
        Schema::create('communication_account_claims', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('connection_id')->constrained('communication_channel_connections')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('account_key', 40);
            $table->string('account_value', 190);
            $table->timestamps();

            $table->unique(['provider', 'account_key', 'account_value'], 'communication_account_claims_account_unique');
            $table->index('connection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_account_claims');
    }
};
