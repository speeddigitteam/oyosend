<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('from_name', 120)->nullable();
            $table->string('reply_to_email', 190)->nullable();
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->string('quiet_hours_start', 5)->default('21:00');
            $table->string('quiet_hours_end', 5)->default('08:00');
            $table->text('footer_text')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id']);
        });

        // One row per channel and workspace. Holds NO secret: `credential_ref` only names an entry
        // of config('communication.credentials').
        Schema::create('communication_channel_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('channel', 12);
            $table->string('provider', 40);
            $table->boolean('is_enabled')->default(true);
            $table->json('settings')->nullable();
            $table->string('credential_ref', 60)->nullable();
            $table->string('webhook_secret_ref', 60)->nullable();
            $table->unsignedSmallInteger('rate_limit_per_minute')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel']);
            $table->index(['tenant_id', 'provider']);
        });

        Schema::create('communication_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('severity', 12)->default('info');
            $table->string('title_key', 255);
            $table->string('body_key', 500)->nullable();
            $table->json('params')->nullable();
            $table->string('link_route', 120)->nullable();
            $table->json('link_params')->nullable();
            $table->string('source_module', 40)->nullable();
            $table->string('dedupe_key', 160)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'membership_id', 'dedupe_key'], 'communication_notifications_dedupe_unique');
            $table->index(['tenant_id', 'membership_id', 'read_at'], 'communication_notifications_unread_index');
            $table->index(['tenant_id', 'membership_id', 'created_at'], 'communication_notifications_inbox_index');
        });

        Schema::create('communication_notification_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->string('channel', 12);
            $table->string('category', 40);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['tenant_id', 'membership_id', 'channel', 'category'], 'communication_preferences_unique');
        });

        Schema::create('communication_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('name', 160);
            $table->string('channel', 12);
            $table->string('locale', 5);
            $table->string('purpose', 16);
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug', 'channel', 'locale'], 'communication_templates_unique');
            $table->index(['tenant_id', 'channel', 'is_active']);
        });

        Schema::create('communication_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUlid('membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('template_id')->nullable()->constrained('communication_templates')->nullOnDelete();
            $table->string('channel', 12);
            $table->string('purpose', 16);
            $table->string('status', 16);
            $table->string('recipient', 190);
            $table->string('subject', 255)->nullable();
            $table->mediumText('body');
            $table->string('locale', 5);
            $table->string('idempotency_key', 160);
            $table->string('source', 60)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->string('failure_message', 300)->nullable();
            $table->json('meta')->nullable();
            $this->ownership($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key'], 'communication_messages_idempotency_unique');
            $table->index(['tenant_id', 'status', 'next_attempt_at'], 'communication_messages_due_index');
            $table->index(['tenant_id', 'contact_id', 'created_at']);
            $table->index(['tenant_id', 'company_id', 'created_at']);
            $table->index(['tenant_id', 'provider', 'provider_message_id'], 'communication_messages_provider_index');
            $table->index(['tenant_id', 'created_at']);
            $this->ownershipIndexes($table, 'communication_messages');
        });

        Schema::create('communication_interactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUlid('message_id')->nullable()->constrained('communication_messages')->nullOnDelete();
            $table->string('type', 16);
            $table->string('direction', 8)->nullable();
            $table->string('summary', 200);
            $table->text('body')->nullable();
            $table->timestamp('occurred_at');
            $table->string('source_module', 40)->nullable();
            $table->string('source_type', 60)->nullable();
            $table->string('source_id', 60)->nullable();
            $table->string('dedupe_key', 160)->nullable();
            $table->json('meta')->nullable();
            $this->ownership($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'dedupe_key'], 'communication_interactions_dedupe_unique');
            $table->index(['tenant_id', 'contact_id', 'occurred_at'], 'communication_interactions_contact_index');
            $table->index(['tenant_id', 'company_id', 'occurred_at'], 'communication_interactions_company_index');
            $this->ownershipIndexes($table, 'communication_interactions');
        });

        Schema::create('communication_suppressions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->string('channel', 12);
            $table->string('address', 190);
            $table->string('scope', 12);
            $table->string('reason', 16);
            $table->string('note', 300)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'address', 'scope'], 'communication_suppressions_unique');
            $table->index(['tenant_id', 'contact_id']);
        });

        Schema::create('communication_consents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUlid('recorded_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->string('channel', 12);
            $table->string('purpose', 16);
            $table->string('status', 12);
            $table->string('method', 24);
            $table->string('evidence', 500)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id', 'channel', 'purpose'], 'communication_consents_unique');
            $table->index(['tenant_id', 'status', 'channel']);
        });

        // Only a SHA-256 hash of the token is stored; the token itself exists in the outgoing message.
        Schema::create('communication_unsubscribe_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->cascadeOnDelete();
            $table->foreignUlid('message_id')->nullable()->constrained('communication_messages')->cascadeOnDelete();
            $table->string('channel', 12);
            $table->string('address', 190);
            $table->char('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'token_hash'], 'communication_unsubscribe_tokens_unique');
            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::create('communication_webhook_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('event_id', 190);
            $table->string('type', 16);
            $table->string('provider_message_id', 190)->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'event_id'], 'communication_webhook_events_unique');
            $table->index(['tenant_id', 'received_at']);
        });

        Schema::create('communication_automation_action_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'action', 'idempotency_key'],
                'communication_action_receipts_tenant_action_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_automation_action_receipts');
        Schema::dropIfExists('communication_webhook_events');
        Schema::dropIfExists('communication_unsubscribe_tokens');
        Schema::dropIfExists('communication_consents');
        Schema::dropIfExists('communication_suppressions');
        Schema::dropIfExists('communication_interactions');
        Schema::dropIfExists('communication_messages');
        Schema::dropIfExists('communication_templates');
        Schema::dropIfExists('communication_notification_preferences');
        Schema::dropIfExists('communication_notifications');
        Schema::dropIfExists('communication_channel_connections');
        Schema::dropIfExists('communication_settings');
    }

    private function ownership(Blueprint $table): void
    {
        $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
        $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
        $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
    }

    private function ownershipIndexes(Blueprint $table, string $prefix): void
    {
        $table->index(['tenant_id', 'owner_id'], $prefix.'_tenant_owner_index');
        $table->index(['tenant_id', 'department_id'], $prefix.'_tenant_department_index');
        $table->index(['tenant_id', 'branch_id'], $prefix.'_tenant_branch_index');
    }
};
