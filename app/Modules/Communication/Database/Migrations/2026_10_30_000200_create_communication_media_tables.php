<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ADR 0022 - WhatsApp media, additive and guarded.
 *
 *  - `communication_media`: tenant-owned metadata of a file held in TenantStorage (the bytes never live in the
 *    database; `storage_path` is tenant-relative and is never exposed to apps, who hold the row id).
 *    Inbound files are unique per (tenant, direction, provider_media_id), so a repeated download is idempotent.
 *  - `communication_media_uploads`: the provider media id a stored file got when uploaded for one number.
 *    Provider media ids expire (about 30 days): `expires_at` is when this build stops reusing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('communication_media')) {
            Schema::create('communication_media', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('direction', 10);
                $table->string('type', 10);
                $table->string('mime', 100);
                $table->unsignedBigInteger('size_bytes');
                $table->char('sha256', 64);
                $table->string('filename', 240)->nullable();
                $table->string('storage_path', 255);
                $table->string('provider_media_id', 100)->nullable();
                $table->foreignUlid('number_id')->nullable()->constrained('communication_channel_numbers')->nullOnDelete();
                $table->timestamps();

                $table->unique(['tenant_id', 'direction', 'provider_media_id'], 'communication_media_provider_unique');
                $table->index(['tenant_id', 'direction', 'created_at'], 'communication_media_tenant_direction_index');
            });
        }

        if (! Schema::hasTable('communication_media_uploads')) {
            Schema::create('communication_media_uploads', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('media_id')->constrained('communication_media')->cascadeOnDelete();
                $table->foreignUlid('number_id')->constrained('communication_channel_numbers')->cascadeOnDelete();
                $table->string('provider_media_id', 100);
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->unique(['tenant_id', 'media_id', 'number_id'], 'communication_media_uploads_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_media_uploads');
        Schema::dropIfExists('communication_media');
    }
};
