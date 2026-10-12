<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_marketing_sender_ids', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('label', 80);
            // The text or number itself: alphanumeric up to 11 characters, or numeric.
            $table->string('value', 16);
            $table->string('type', 16);
            // What an operator of the workspace recorded about the regulator registration. Never verified here.
            $table->string('registration_status', 16)->default('not_registered');
            $table->string('registration_reference', 120)->nullable();
            $table->string('registration_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'value'], 'sms_marketing_sender_ids_tenant_value_unique');
        });

        Schema::create('sms_marketing_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('daily_cap')->default(1000);
            $table->unsignedSmallInteger('throttle_per_minute')->default(60);
            $table->string('opt_out_wording', 60)->nullable();
            // The workspace's own price per segment in minor units; never read from a provider.
            $table->unsignedBigInteger('segment_cost_amount')->nullable();
            $table->string('currency', 3)->nullable();
            $table->foreignUlid('default_sender_id')->nullable()->constrained('sms_marketing_sender_ids')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id']);
        });

        Schema::create('sms_marketing_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('sms_marketing_group_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('group_id')->constrained('sms_marketing_groups')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUlid('added_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'group_id', 'contact_id'], 'sms_marketing_members_unique');
            $table->index(['tenant_id', 'contact_id']);
        });

        Schema::create('sms_marketing_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('purpose', 16);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('sms_marketing_campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name', 120);
            $table->foreignUlid('template_id')->nullable()->constrained('sms_marketing_templates')->nullOnDelete();
            // The text this campaign sends (a template's text is copied in, so editing a template changes nothing sent).
            $table->text('body');
            $table->foreignUlid('sender_identity_id')->nullable()->constrained('sms_marketing_sender_ids')->nullOnDelete();
            $table->string('status', 16);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('materialized_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // Why a running campaign is waiting: daily_cap, limit_reached, channel_unavailable, platform_limit.
            $table->string('hold_reason', 32)->nullable();
            $table->unsignedInteger('estimated_recipients')->default(0);
            $table->unsignedInteger('estimated_segments')->default(0);
            $table->foreignUlid('launched_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status', 'scheduled_for']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('sms_marketing_campaign_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained('sms_marketing_campaigns')->cascadeOnDelete();
            $table->foreignUlid('group_id')->constrained('sms_marketing_groups')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'campaign_id', 'group_id'], 'sms_marketing_campaign_groups_unique');
        });

        Schema::create('sms_marketing_recipients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained('sms_marketing_campaigns')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            // E.164 as resolved when the audience was built.
            $table->string('phone', 20)->nullable();
            $table->string('status', 16);
            $table->string('reason', 60)->nullable();
            $table->string('encoding', 8)->nullable();
            $table->unsignedSmallInteger('segments')->default(0);
            $table->unsignedTinyInteger('attempts')->default(0);
            // The communication platform's message id, used by status events and reconciliation.
            $table->string('message_id', 26)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'campaign_id', 'contact_id'], 'sms_marketing_recipients_unique');
            $table->index(['tenant_id', 'campaign_id', 'status'], 'sms_marketing_recipients_status_index');
            $table->index(['tenant_id', 'status', 'next_attempt_at'], 'sms_marketing_recipients_due_index');
            $table->index(['tenant_id', 'message_id']);
            $table->index(['tenant_id', 'queued_at']);
            $table->index(['tenant_id', 'contact_id']);
        });

        Schema::create('sms_marketing_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('group_id')->nullable()->constrained('sms_marketing_groups')->nullOnDelete();
            $table->string('file_name', 190);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('added_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('errors')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('sms_marketing_automation_action_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();

            $table->unique(['tenant_id', 'action', 'idempotency_key'], 'sms_marketing_receipts_unique');
        });
    }

    public function down(): void
    {
        foreach ([
            'sms_marketing_automation_action_receipts', 'sms_marketing_imports', 'sms_marketing_recipients',
            'sms_marketing_campaign_groups', 'sms_marketing_campaigns', 'sms_marketing_templates',
            'sms_marketing_group_members', 'sms_marketing_groups', 'sms_marketing_settings', 'sms_marketing_sender_ids',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
