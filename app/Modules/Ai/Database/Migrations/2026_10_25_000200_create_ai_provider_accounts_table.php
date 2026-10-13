<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operator-owned AI provider accounts (extends docs/adr/0013-platform-provider-accounts.md). Platform data
        // on purpose: no `tenant_id`, never tenant-scoped. `secrets` is an encrypted payload that no query a page
        // uses returns. Workspaces never see or choose an account; the platform default is used for all of them.
        Schema::create('ai_provider_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('driver', 30);
            $table->string('name', 80)->unique();
            $table->string('model', 120);
            $table->string('base_url', 255)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->longText('secrets')->nullable();
            // Outcome of the last "test connection": `ok` or `failed`, with a safe reason code. Never provider text.
            $table->string('last_test_status', 20)->nullable();
            $table->string('last_test_reason', 40)->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();

            $table->index(['is_default', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_accounts');
    }
};
