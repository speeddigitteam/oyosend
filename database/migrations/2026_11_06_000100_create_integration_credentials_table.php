<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operator-owned integration credentials the Super Admin sets in the panel: the Social
        // platform apps (Facebook, LinkedIn, X) and the Meta Ads app
        // (docs/adr/0029-social-and-meta-ads-panel-credentials.md, following ADR 0028 / ADR 0013).
        // Platform data on purpose: no `tenant_id`, never tenant-scoped. `secrets` is an encrypted
        // payload and is never returned by any query a page uses; the config overlay reads it only to
        // overlay the live config at boot. One row per (group, name); the environment
        // (config/*.php env defaults) is the fallback default. `group` is a reserved SQL word, so any
        // raw query must wrap it in backticks; Blueprint handles the column name here.
        Schema::create('integration_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('group', 40);
            $table->string('name', 40);
            $table->boolean('is_enabled')->default(true);
            // Non-secret operator settings shown back to the panel (e.g. client_id / app_id).
            $table->json('settings')->nullable();
            // Encrypted credentials (client_secret / app_secret / webhook_verify_token).
            $table->longText('secrets')->nullable();
            $table->timestamps();

            $table->unique(['group', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};
