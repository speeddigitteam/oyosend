<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operator-owned provider accounts (docs/adr/0013-platform-provider-accounts.md). Platform data on
        // purpose: no `tenant_id`, never tenant-scoped. `secrets` is an encrypted payload and is never
        // returned by any query a page uses; workspaces reference an account by `acct_{id}` only.
        Schema::create('communication_provider_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('channel', 20);
            $table->string('provider', 40);
            $table->string('name', 80);
            $table->boolean('is_enabled')->default(true);
            $table->json('settings')->nullable();
            $table->longText('secrets')->nullable();
            // Null = every workspace may use the account; otherwise the workspace ids that may.
            $table->json('tenant_ids')->nullable();
            $table->timestamps();

            $table->index(['channel', 'is_enabled']);
            $table->unique(['channel', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_provider_accounts');
    }
};
