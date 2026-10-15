<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A website chat can be linked, by a member's explicit action, to the shared contact the visitor was saved as and to
 * the support ticket it was turned into (ADR 0032, amendment of 2026-10-14). Both are optional references: deleting
 * the contact or the ticket only clears the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('support_chat_conversations', 'contact_id')) {
            return;
        }

        Schema::table('support_chat_conversations', function (Blueprint $table): void {
            $table->foreignUlid('contact_id')->nullable()->after('page_url')->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('ticket_id')->nullable()->after('contact_id')->constrained('support_tickets')->nullOnDelete();

            // Contact merges re-point by (tenant, contact); the ticket page finds its chat by (tenant, ticket).
            $table->index(['tenant_id', 'contact_id'], 'support_chat_contact_index');
            $table->index(['tenant_id', 'ticket_id'], 'support_chat_ticket_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('support_chat_conversations', 'contact_id')) {
            return;
        }

        Schema::table('support_chat_conversations', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
            $table->dropForeign(['ticket_id']);
            $table->dropIndex('support_chat_contact_index');
            $table->dropIndex('support_chat_ticket_index');
            $table->dropColumn(['contact_id', 'ticket_id']);
        });
    }
};
