<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * ADR 0022 - several WhatsApp numbers per workspace, additive and guarded:
 *
 *  - `communication_channel_numbers`: tenant-owned; one connection owns several numbers. Exactly one default per
 *    connection (kept by ChannelNumbers under a row lock; the generated `default_key` makes it a unique index).
 *  - `communication_account_claims.number_id`: the platform-wide claim of a number (ADR 0016) points at its
 *    number row, so a number is claimed exactly once whoever holds it.
 *  - `communication_messages.sender_number_id`: the workspace number a message was queued from.
 *
 * Existing single-number workspaces are backfilled: one default number per WhatsApp connection that already has a
 * `phone_number_id` setting, and its claim is linked. Nothing is dropped or renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('communication_channel_numbers')) {
            Schema::create('communication_channel_numbers', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('connection_id')->constrained('communication_channel_connections')->cascadeOnDelete();
                $table->string('channel', 12)->default('whatsapp');
                $table->string('phone_number_id', 40);
                $table->string('display_number', 32)->nullable();
                $table->string('label', 60);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                // 1 for the default number of a connection, NULL otherwise: a unique index over it is the
                // database's own guarantee of "at most one default" (NULLs never collide).
                $table->unsignedTinyInteger('default_key')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'phone_number_id'], 'communication_numbers_tenant_account_unique');
                $table->unique(['tenant_id', 'label'], 'communication_numbers_tenant_label_unique');
                $table->unique(['tenant_id', 'connection_id', 'default_key'], 'communication_numbers_one_default_unique');
                $table->index(['tenant_id', 'connection_id'], 'communication_numbers_tenant_connection_index');
            });
        }

        if (! Schema::hasColumn('communication_account_claims', 'number_id')) {
            Schema::table('communication_account_claims', function (Blueprint $table): void {
                $table->foreignUlid('number_id')->nullable()->after('connection_id')
                    ->constrained('communication_channel_numbers')->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('communication_messages', 'sender_number_id')) {
            Schema::table('communication_messages', function (Blueprint $table): void {
                $table->foreignUlid('sender_number_id')->nullable()
                    ->constrained('communication_channel_numbers')->nullOnDelete();
                $table->index(['tenant_id', 'sender_number_id'], 'communication_messages_tenant_sender_index');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (Schema::hasColumn('communication_messages', 'sender_number_id')) {
            Schema::table('communication_messages', function (Blueprint $table): void {
                $table->dropForeign(['sender_number_id']);
                $table->dropIndex('communication_messages_tenant_sender_index');
                $table->dropColumn('sender_number_id');
            });
        }

        if (Schema::hasColumn('communication_account_claims', 'number_id')) {
            Schema::table('communication_account_claims', function (Blueprint $table): void {
                $table->dropForeign(['number_id']);
                $table->dropColumn('number_id');
            });
        }

        Schema::dropIfExists('communication_channel_numbers');
    }

    /** One default number per existing WhatsApp connection that names a phone number id. */
    private function backfill(): void
    {
        $connections = DB::table('communication_channel_connections')->where('channel', 'whatsapp')->get();

        foreach ($connections as $connection) {
            $settings = json_decode((string) $connection->settings, true);
            $phoneNumberId = is_array($settings) ? ($settings['phone_number_id'] ?? null) : null;

            if (! is_string($phoneNumberId) || $phoneNumberId === '') {
                continue;
            }

            $exists = DB::table('communication_channel_numbers')
                ->where('tenant_id', $connection->tenant_id)
                ->where('phone_number_id', $phoneNumberId)
                ->exists();

            if ($exists) {
                continue;
            }

            $id = (string) Str::ulid();
            $now = now();

            DB::table('communication_channel_numbers')->insert([
                'id' => $id,
                'tenant_id' => $connection->tenant_id,
                'connection_id' => $connection->id,
                'channel' => 'whatsapp',
                'phone_number_id' => $phoneNumberId,
                'display_number' => null,
                'label' => 'Main number',
                'is_active' => true,
                'is_default' => true,
                'default_key' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('communication_account_claims')
                ->where('connection_id', $connection->id)
                ->where('account_key', 'phone_number_id')
                ->where('account_value', $phoneNumberId)
                ->update(['number_id' => $id]);
        }
    }
};
