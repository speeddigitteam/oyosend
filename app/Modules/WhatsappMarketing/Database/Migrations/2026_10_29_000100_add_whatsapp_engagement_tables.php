<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| WhatsApp Business 1.1: interactive messages, internal notes, feedback
|--------------------------------------------------------------------------
|
| `interactive` keeps the buttons or list rows an outbound message offered (the
| body column keeps the text). Notes are internal to the team and never sent.
| A feedback row is one rating request after a person closed a conversation.
| Every unique index starts with `tenant_id`.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            // {"kind": "buttons"|"list", "button": "Choose"|null, "options": ["Pricing", "Support"]}
            $table->json('interactive')->nullable()->after('attachments');
        });

        Schema::table('whatsapp_settings', function (Blueprint $table): void {
            $table->boolean('feedback_enabled')->default(false)->after('bots_enabled');
        });

        Schema::create('whatsapp_conversation_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->foreignUlid('author_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id', 'created_at'], 'whatsapp_notes_conversation_index');
        });

        Schema::create('whatsapp_feedback', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            // The agent who owned the conversation when it was closed.
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->string('status', 12)->default('pending');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('expires_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id', 'status'], 'whatsapp_feedback_conversation_index');
            $table->index(['tenant_id', 'requested_at'], 'whatsapp_feedback_requested_index');
            $table->index(['tenant_id', 'owner_id', 'answered_at'], 'whatsapp_feedback_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_feedback');
        Schema::dropIfExists('whatsapp_conversation_notes');

        Schema::table('whatsapp_settings', function (Blueprint $table): void {
            $table->dropColumn('feedback_enabled');
        });

        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            $table->dropColumn('interactive');
        });
    }
};
