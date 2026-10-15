<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per workspace: how to reach its PBX (ADR 0036). Secrets are encrypted by the model and write-only.
        Schema::create('telephony_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('driver', 20)->default('asterisk');
            $table->string('wss_url', 255)->nullable();
            $table->string('sip_domain', 190)->nullable();
            $table->string('api_url', 255)->nullable();
            $table->string('api_username', 120)->nullable();
            $table->text('api_secret')->nullable();
            $table->string('origination_context', 80)->nullable();
            $table->string('caller_id', 40)->nullable();
            $table->json('stun_urls')->nullable();
            $table->string('turn_url', 255)->nullable();
            $table->string('turn_username', 120)->nullable();
            $table->text('turn_credential')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id']);
        });

        // A member's SIP extension on the workspace's PBX, and their own phone for bridge calls.
        Schema::create('telephony_extensions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->string('extension', 32);
            $table->text('sip_password')->nullable();
            $table->string('bridge_phone', 20)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'membership_id']);
            $table->unique(['tenant_id', 'extension']);
        });

        // The call log. No recording column: calls are not recorded (ADR 0036).
        Schema::create('telephony_calls', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('direction', 10);
            $table->string('mode', 12);
            $table->string('status', 16);
            $table->string('remote_number', 20);
            $table->string('extension', 32)->nullable();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('client_call_id', 120)->nullable();
            $table->string('pbx_call_id', 120)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'pbx_call_id']);
            $table->unique(['tenant_id', 'client_call_id']);
            $table->index(['tenant_id', 'started_at']);
            $table->index(['tenant_id', 'contact_id', 'started_at']);
            $table->index(['tenant_id', 'owner_id', 'started_at']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        // PBX events already handled, so a redelivered event changes nothing.
        Schema::create('telephony_webhook_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('event_key', 64);
            $table->timestamp('received_at');

            $table->unique(['tenant_id', 'event_key']);
            $table->index(['tenant_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telephony_webhook_events');
        Schema::dropIfExists('telephony_calls');
        Schema::dropIfExists('telephony_extensions');
        Schema::dropIfExists('telephony_settings');
    }
};
