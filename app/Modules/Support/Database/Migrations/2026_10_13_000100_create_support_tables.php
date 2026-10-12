<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_number_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'kind'], 'support_sequences_tenant_kind_unique');
        });

        Schema::create('support_calendars', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            // Null means the workspace timezone.
            $table->string('timezone', 64)->nullable();
            // {"1": [["09:00","17:00"]], ...} keyed by ISO weekday (1 = Monday).
            $table->json('hours');
            // ["2026-12-16", ...] local dates on which nothing counts.
            $table->json('holidays')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('support_sla_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('calendar_id')->nullable()->constrained('support_calendars')->nullOnDelete();
            $table->string('name', 120);
            // {"low": {"first_response": 480, "resolution": 2880}, ...} in integer minutes.
            $table->json('targets');
            $table->boolean('pause_on_waiting')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('support_queues', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('sla_policy_id')->nullable()->constrained('support_sla_policies')->nullOnDelete();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->string('assignment_mode', 16)->default('manual');
            $table->ulid('last_assigned_agent_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('support_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('queue_id')->nullable()->constrained('support_queues')->nullOnDelete();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('support_agents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('max_open_tickets')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'membership_id']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('support_queue_agents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('queue_id')->constrained('support_queues')->cascadeOnDelete();
            $table->foreignUlid('agent_id')->constrained('support_agents')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'queue_id', 'agent_id'], 'support_queue_agents_unique');
            $table->index(['tenant_id', 'agent_id']);
        });

        Schema::create('support_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('ticket_prefix', 20)->default('TKT-');
            $table->unsignedTinyInteger('number_padding')->default(5);
            $table->string('default_priority', 16)->default('normal');
            $table->foreignUlid('default_queue_id')->nullable()->constrained('support_queues')->nullOnDelete();
            $table->foreignUlid('default_sla_policy_id')->nullable()->constrained('support_sla_policies')->nullOnDelete();
            $table->boolean('csat_enabled')->default(true);
            $table->unsignedSmallInteger('csat_expiry_days')->default(14);
            $table->boolean('csat_send_email')->default(true);
            // Resolved tickets are closed after this many days without activity; null disables it.
            $table->unsignedSmallInteger('auto_close_days')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id']);
        });

        Schema::create('support_assignment_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('match_channel', 16)->nullable();
            $table->string('match_priority', 16)->nullable();
            $table->foreignUlid('match_category_id')->nullable()->constrained('support_categories')->cascadeOnDelete();
            $table->foreignUlid('match_company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('match_keyword', 80)->nullable();
            $table->foreignUlid('queue_id')->constrained('support_queues')->cascadeOnDelete();
            $table->foreignUlid('assign_agent_id')->nullable()->constrained('support_agents')->nullOnDelete();
            $table->string('set_priority', 16)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'position'], 'support_rules_order_index');
        });

        Schema::create('support_tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 60);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('subject', 200);
            $table->string('status', 24);
            $table->string('priority', 16);
            $table->string('channel', 16);
            $table->foreignUlid('category_id')->nullable()->constrained('support_categories')->nullOnDelete();
            $table->foreignUlid('queue_id')->nullable()->constrained('support_queues')->nullOnDelete();
            $table->foreignUlid('sla_policy_id')->nullable()->constrained('support_sla_policies')->nullOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('requester_name', 160)->nullable();
            $table->string('requester_email', 190)->nullable();
            // The assignee. owner/department/branch follow the HasRecordOwner column contract.
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('merged_into_id')->nullable()->constrained('support_tickets')->nullOnDelete();
            $table->foreignUlid('split_from_id')->nullable()->constrained('support_tickets')->nullOnDelete();
            $table->string('source_key', 190)->nullable();
            $table->unsignedSmallInteger('reopened_count')->default(0);
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_customer_message_at')->nullable();
            $table->timestamp('last_agent_message_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->unsignedInteger('first_response_remaining_minutes')->nullable();
            $table->unsignedInteger('resolution_remaining_minutes')->nullable();
            $table->timestamp('sla_paused_at')->nullable();
            $table->timestamp('first_response_breached_at')->nullable();
            $table->timestamp('resolution_breached_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'source_key']);
            $table->index(['tenant_id', 'status', 'priority'], 'support_tickets_status_index');
            $table->index(['tenant_id', 'owner_id', 'status'], 'support_tickets_owner_index');
            $table->index(['tenant_id', 'queue_id', 'status'], 'support_tickets_queue_index');
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'last_activity_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status', 'first_response_due_at'], 'support_tickets_fr_due_index');
            $table->index(['tenant_id', 'status', 'resolution_due_at'], 'support_tickets_res_due_index');
        });

        Schema::create('support_ticket_tag', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignUlid('tag_id')->constrained('support_tags')->cascadeOnDelete();

            $table->unique(['tenant_id', 'ticket_id', 'tag_id'], 'support_ticket_tag_unique');
            $table->index(['tenant_id', 'tag_id']);
        });

        Schema::create('support_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignUlid('author_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->string('author_name', 160)->nullable();
            $table->string('author_email', 190)->nullable();
            $table->mediumText('body');
            $table->string('source_key', 190)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_key']);
            $table->index(['tenant_id', 'ticket_id', 'created_at'], 'support_messages_ticket_index');
        });

        Schema::create('support_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignUlid('message_id')->nullable()->constrained('support_messages')->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->foreignUlid('uploaded_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'ticket_id']);
            $table->index(['tenant_id', 'message_id']);
        });

        Schema::create('support_ticket_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->string('kind', 40);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'ticket_id', 'created_at'], 'support_events_ticket_index');
        });

        Schema::create('support_canned_responses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('support_csat_surveys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            // SHA-256 of the emailed token. Nulled once answered, superseded or expired.
            $table->char('token_hash', 64)->nullable();
            $table->string('status', 12);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'token_hash']);
            $table->index(['tenant_id', 'ticket_id', 'status'], 'support_surveys_ticket_index');
            $table->index(['tenant_id', 'status', 'expires_at'], 'support_surveys_expiry_index');
        });

        Schema::create('support_kb_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('support_kb_articles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('support_kb_categories')->nullOnDelete();
            $table->string('title', 200);
            $table->string('slug', 220);
            $table->string('summary', 300)->nullable();
            $table->mediumText('body');
            $table->string('status', 12);
            $table->unsignedInteger('current_version')->default(1);
            $table->foreignUlid('author_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'category_id']);
        });

        Schema::create('support_kb_article_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('article_id')->constrained('support_kb_articles')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->string('summary', 300)->nullable();
            $table->mediumText('body');
            $table->foreignUlid('edited_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'article_id', 'version'], 'support_kb_versions_unique');
        });

        Schema::create('support_automation_action_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();

            $table->unique(['tenant_id', 'action', 'idempotency_key'], 'support_receipts_unique');
        });
    }

    public function down(): void
    {
        foreach ([
            'support_automation_action_receipts', 'support_kb_article_versions', 'support_kb_articles',
            'support_kb_categories', 'support_csat_surveys', 'support_canned_responses', 'support_ticket_events',
            'support_attachments', 'support_messages', 'support_ticket_tag', 'support_tickets', 'support_tags',
            'support_assignment_rules', 'support_settings', 'support_queue_agents', 'support_agents',
            'support_categories', 'support_queues', 'support_sla_policies', 'support_calendars',
            'support_number_sequences',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
