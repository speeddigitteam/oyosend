<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operator-owned payment gateway credentials the Super Admin sets in the panel
        // (docs/adr/0028-operator-managed-integration-credentials.md, extending ADR 0013).
        // Platform data on purpose: no `tenant_id`, never tenant-scoped. `secrets` is an encrypted
        // payload and is never returned by any query a page uses; a gateway only ever reads it to talk
        // to its provider. One row per compiled gateway key; the environment is the fallback default.
        Schema::create('payment_gateway_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('gateway', 40)->unique();
            $table->boolean('is_enabled')->default(true);
            // Non-secret operator settings (e.g. sandbox mode).
            $table->json('settings')->nullable();
            // Encrypted credentials (store id / password / signature key).
            $table->longText('secrets')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_accounts');
    }
};
