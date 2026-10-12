<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| WhatsApp Business (module `whatsapp_marketing`)
|--------------------------------------------------------------------------
|
| No provider credential, token or secret is stored anywhere in these tables:
| the connection to the WhatsApp Cloud API belongs to the communication app and
| is a credential REFERENCE resolved from the environment. Customers are the
| shared `contacts`; a conversation with a number that is not a contact yet
| carries the number only. Every unique index starts with `tenant_id`.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('business_name', 120)->nullable();
            $table->string('display_phone', 32)->nullable();
            $table->boolean('auto_assign')->default(false);
            $table->boolean('auto_create_contacts')->default(false);
            $table->boolean('bots_enabled')->default(true);
            $table->timestamps();

            $table->unique('tenant_id');
        });

        Schema::create('whatsapp_agents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('max_open_conversations')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'membership_id']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('language', 16);
            $table->string('category', 16);
            $table->string('status', 16);
            // Header / body / footer / buttons exactly as the provider describes them (text only is rendered).
            $table->json('components');
            $table->text('body_text');
            $table->unsignedTinyInteger('variable_count')->default(0);
            $table->string('status_source', 16)->default('admin');
            $table->string('status_reason', 300)->nullable();
            $table->string('provider_template_id', 64)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name', 'language']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('whatsapp_quick_replies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('shortcut', 40);
            $table->string('title', 120);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'shortcut']);
        });

        Schema::create('whatsapp_lists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('whatsapp_list_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('list_id')->constrained('whatsapp_lists')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUlid('added_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'list_id', 'contact_id']);
            $table->index(['tenant_id', 'contact_id']);
        });

        Schema::create('whatsapp_campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name', 120);
            $table->string('status', 16)->default('draft');
            $table->foreignUlid('template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->foreignUlid('list_id')->nullable()->constrained('whatsapp_lists')->nullOnDelete();
            // Snapshots: what the campaign was built from stays readable after the template or list changes.
            $table->string('template_name', 255);
            $table->string('template_language', 16);
            $table->string('template_category', 16);
            $table->text('template_body');
            $table->string('list_name', 120)->nullable();
            // {"1": {"type": "field", "field": "contact.first_name"}, "2": {"type": "text", "value": "Dhaka"}}
            $table->json('variable_map');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('snapshot_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->string('stop_reason', 40)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status', 'scheduled_at']);
            $table->index(['tenant_id', 'owner_id']);
        });

        Schema::create('whatsapp_conversations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            // E.164 with the plus: `+8801711000000`.
            $table->string('phone', 20);
            $table->string('profile_name', 120)->nullable();
            $table->string('status', 12)->default('open');
            // The assignee (a membership). Department and branch are copied from them.
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->json('tags')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 160)->nullable();
            $table->string('last_message_direction', 8)->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('bot_paused')->default(false);
            $table->json('bot_state')->nullable();
            $table->timestamp('bot_state_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'status', 'last_message_at']);
            $table->index(['tenant_id', 'owner_id', 'status']);
            $table->index(['tenant_id', 'contact_id']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->nullable()->constrained('whatsapp_campaigns')->nullOnDelete();
            $table->foreignUlid('template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->string('direction', 8);
            $table->string('sender_type', 12);
            $table->foreignUlid('author_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->string('type', 16)->default('text');
            // Untrusted customer text when inbound. Rendered as text only, capped at 4096 characters.
            $table->text('body')->nullable();
            $table->string('template_name', 255)->nullable();
            $table->string('status', 12);
            $table->string('failure_code', 60)->nullable();
            // The WhatsApp message id of an inbound message: the de-duplication key.
            $table->string('provider_message_id', 190)->nullable();
            // The communication outbox message of an outbound one.
            $table->string('communication_message_id', 26)->nullable();
            // Metadata only: [{"kind": "image", "mime": "image/jpeg", "caption": true}]. Content is never fetched.
            $table->json('attachments')->nullable();
            // Only what the provider reported; null is "not reported".
            $table->boolean('billable')->nullable();
            $table->string('pricing_category', 30)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider_message_id']);
            $table->index(['tenant_id', 'conversation_id', 'occurred_at']);
            $table->index(['tenant_id', 'communication_message_id']);
            $table->index(['tenant_id', 'campaign_id']);
            $table->index(['tenant_id', 'direction', 'occurred_at']);
        });

        Schema::create('whatsapp_campaign_recipients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained('whatsapp_campaigns')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('state', 12)->default('pending');
            $table->string('reason', 60)->nullable();
            $table->foreignUlid('message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->string('communication_message_id', 26)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'campaign_id', 'contact_id'], 'whatsapp_recipients_campaign_contact_unique');
            $table->index(['tenant_id', 'campaign_id', 'state'], 'whatsapp_recipients_campaign_state_index');
            $table->index(['tenant_id', 'contact_id'], 'whatsapp_recipients_contact_index');
        });

        Schema::create('whatsapp_flows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('trigger', 12);
            $table->string('match_type', 12)->nullable();
            $table->json('keywords')->nullable();
            // A closed list of declarative steps (reply, menu, tag, assign, handoff). Never code.
            $table->json('actions');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active', 'priority']);
        });

        Schema::create('whatsapp_windows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->string('origin', 12);
            $table->timestamp('opened_at');
            $table->timestamp('expires_at');
            $table->string('template_category', 16)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'conversation_id', 'expires_at']);
            $table->index(['tenant_id', 'opened_at']);
        });

        Schema::create('whatsapp_automation_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();

            $table->unique(['tenant_id', 'action', 'idempotency_key'], 'whatsapp_receipts_tenant_action_key_unique');
        });
    }

    public function down(): void
    {
        foreach ([
            'whatsapp_automation_receipts', 'whatsapp_windows', 'whatsapp_flows', 'whatsapp_campaign_recipients',
            'whatsapp_messages', 'whatsapp_conversations', 'whatsapp_campaigns', 'whatsapp_list_members',
            'whatsapp_lists', 'whatsapp_quick_replies', 'whatsapp_templates', 'whatsapp_agents', 'whatsapp_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
