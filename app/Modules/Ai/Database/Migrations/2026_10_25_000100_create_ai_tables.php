<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Assistant. Every table is tenant-owned (`tenant_id` first in every unique index).
 * No credential, prompt secret or provider payload is stored here: only the person's own conversation text,
 * proposals awaiting a human decision, and the workspace's AI settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->boolean('chat_enabled')->default(true);
            $table->boolean('drafting_enabled')->default(true);
            $table->boolean('automation_enabled')->default(false);
            // Data scopes the assistant may read: a list of tool data scopes (`contacts`, `companies`, ...).
            $table->json('data_scopes');
            $table->unsignedSmallInteger('retention_days')->default(90);
            $table->boolean('redact_pii')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id']);
        });

        // A conversation is private to its member: nobody else, owner included, reads it.
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->string('title', 120);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'membership_id', 'last_message_at']);
            $table->index(['tenant_id', 'last_message_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            // user | assistant
            $table->string('role', 12);
            $table->text('content');
            // For assistant messages: [{tool, status, record_ids}] - names and ids, never content.
            $table->json('tool_calls')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('prompt_version', 32)->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id', 'created_at']);
        });

        Schema::create('ai_proposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->string('tool', 64);
            $table->string('summary', 500);
            // Encrypted array: the validated input of ProposalTool::apply().
            $table->text('payload');
            // pending | confirmed | rejected | failed | expired
            $table->string('status', 12)->default('pending');
            $table->json('result')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'membership_id', 'status']);
            $table->index(['tenant_id', 'conversation_id']);
        });

        Schema::create('ai_automation_action_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();

            $table->unique(['tenant_id', 'action', 'idempotency_key'], 'ai_action_receipts_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_automation_action_receipts');
        Schema::dropIfExists('ai_proposals');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_settings');
    }
};
