<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Website live chat (ADR 0032, docs/SUPPORT.md section 15): the widget settings of a workspace, the conversations
 * visitors start on the workspace's own website and their messages. Additive: no existing table changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_chat_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            // ["https://www.example.com", ...]: canonical origins allowed to embed the chat (CSP frame-ancestors), at most 10.
            $table->json('allowed_origins')->nullable();
            $table->string('widget_title', 60)->nullable();
            $table->string('greeting', 300)->nullable();
            // none | optional | required: whether a name and an email address are asked before the first message.
            $table->string('visitor_details', 10)->default('optional');
            $table->string('offline_note', 300)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id']);
        });

        Schema::create('support_chat_conversations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // SHA-256 of the visitor's 256-bit token; the token itself is never stored.
            $table->char('visitor_token_hash', 64);
            // What the visitor typed. Never turned into a shared contact automatically.
            $table->string('name', 100)->nullable();
            $table->string('email', 190)->nullable();
            // Scheme, host and path of the page the chat was started on; query and fragment are dropped.
            $table->string('page_url', 500)->nullable();
            $table->string('status', 10)->default('open');
            // Messages so far; the next message takes `message_count + 1` as its `seq` under the row lock.
            $table->unsignedInteger('message_count')->default(0);
            // Visitor messages since a member last read or answered the conversation.
            $table->unsignedInteger('unread_count')->default(0);
            $table->foreignUlid('assignee_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_visitor_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'visitor_token_hash'], 'support_chat_token_unique');
            $table->index(['tenant_id', 'last_message_at', 'id'], 'support_chat_order_index');
            $table->index(['tenant_id', 'status', 'assignee_membership_id'], 'support_chat_state_index');
        });

        Schema::create('support_chat_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained('support_chat_conversations')->cascadeOnDelete();
            // Position in the conversation (1, 2, ...): polling continues after a message by this, not by time.
            $table->unsignedInteger('seq');
            // in = the visitor, out = a member.
            $table->string('direction', 3);
            // Plain text, control characters removed, at most 2000 characters.
            $table->text('text');
            $table->foreignUlid('sent_by_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'conversation_id', 'seq'], 'support_chat_messages_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_chat_messages');
        Schema::dropIfExists('support_chat_conversations');
        Schema::dropIfExists('support_chat_settings');
    }
};
