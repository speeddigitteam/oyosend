<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_marketing_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->boolean('track_clicks')->default(true);
            $table->unsignedSmallInteger('send_per_minute')->default(60);
            // The postal address printed in every footer; sending is refused while it is empty.
            $table->string('footer_address', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id'], 'em_settings_tenant_id_unique');
        });

        Schema::create('email_marketing_lists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('status', 16);
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'em_lists_tenant_id_name_unique');
        });

        // A subscriber is a membership of a list that points at a shared contact. There is no subscriber identity.
        Schema::create('email_marketing_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('list_id')->constrained('email_marketing_lists')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('source', 24);
            $table->string('consent_method', 24)->nullable();
            $table->string('consent_evidence', 500)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('status_reason', 120)->nullable();
            $table->json('properties')->nullable();
            $table->foreignUlid('added_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'list_id', 'contact_id'], 'em_subscriptions_tenant_id_list_id_contact_id_unique');
            $table->index(['tenant_id', 'contact_id'], 'em_subscriptions_tenant_id_contact_id_index');
            $table->index(['tenant_id', 'list_id', 'status'], 'em_subscriptions_tenant_id_list_id_status_index');
            $table->index(['tenant_id', 'status'], 'em_subscriptions_tenant_id_status_index');
        });

        Schema::create('email_marketing_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('subject', 200)->nullable();
            $table->string('preheader', 200)->nullable();
            $table->json('blocks');
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'em_templates_tenant_id_name_unique');
        });

        Schema::create('email_marketing_segments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('list_id')->constrained('email_marketing_lists')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('match', 3);
            $table->json('rules');
            $table->timestamps();

            $table->unique(['tenant_id', 'list_id', 'name'], 'em_segments_tenant_id_list_id_name_unique');
        });

        Schema::create('email_marketing_campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('subject', 200);
            $table->string('preheader', 200)->nullable();
            $table->json('blocks');
            $table->foreignUlid('template_id')->nullable()->constrained('email_marketing_templates')->nullOnDelete();
            $table->foreignUlid('list_id')->constrained('email_marketing_lists')->restrictOnDelete();
            $table->foreignUlid('segment_id')->nullable()->constrained('email_marketing_segments')->nullOnDelete();
            $table->string('status', 20);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            // Set when the whole audience was worked out into deliveries.
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('paused_reason', 120)->nullable();
            $table->boolean('track_clicks')->default(true);
            $table->boolean('ab_enabled')->default(false);
            $table->string('ab_subject_b', 200)->nullable();
            $table->unsignedTinyInteger('ab_test_percent')->default(20);
            $table->unsignedSmallInteger('ab_wait_hours')->default(4);
            $table->string('ab_metric', 8)->default('clicks');
            $table->char('ab_winner', 1)->nullable();
            $table->string('ab_winner_reason', 40)->nullable();
            $table->timestamp('ab_decide_at')->nullable();
            $table->timestamp('ab_decided_at')->nullable();
            // Keyset cursor (subscription id) of the audience materialisation.
            $table->string('cursor', 26)->nullable();
            $table->unsignedInteger('audience_total')->default(0);
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'scheduled_for'], 'em_campaigns_tenant_id_status_scheduled_for_index');
            $table->index(['tenant_id', 'list_id'], 'em_campaigns_tenant_id_list_id_index');
            $table->index(['tenant_id', 'owner_id'], 'em_campaigns_tenant_id_owner_id_index');
            $table->index(['tenant_id', 'department_id'], 'em_campaigns_tenant_id_department_id_index');
            $table->index(['tenant_id', 'branch_id'], 'em_campaigns_tenant_id_branch_id_index');
        });

        Schema::create('email_marketing_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->foreignUlid('list_id')->constrained('email_marketing_lists')->restrictOnDelete();
            $table->string('status', 16);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'em_sequences_tenant_id_name_unique');
            $table->index(['tenant_id', 'status'], 'em_sequences_tenant_id_status_index');
        });

        Schema::create('email_marketing_sequence_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('sequence_id')->constrained('email_marketing_sequences')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->string('subject', 200);
            $table->string('preheader', 200)->nullable();
            $table->json('blocks');
            $table->timestamps();

            $table->unique(['tenant_id', 'sequence_id', 'position'], 'em_sequence_steps_tenant_id_sequence_id_position_unique');
        });

        Schema::create('email_marketing_enrollments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('sequence_id')->constrained('email_marketing_sequences')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUlid('subscription_id')->nullable()->constrained('email_marketing_subscriptions')->nullOnDelete();
            $table->string('status', 16);
            $table->unsignedSmallInteger('next_position')->default(1);
            $table->timestamp('next_due_at')->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('stopped_reason', 60)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sequence_id', 'contact_id'], 'em_enrollments_tenant_id_sequence_id_contact_id_unique');
            $table->index(['tenant_id', 'status', 'next_due_at'], 'em_enrollments_tenant_id_status_next_due_at_index');
            $table->index(['tenant_id', 'contact_id'], 'em_enrollments_tenant_id_contact_id_index');
        });

        // One row per planned or attempted email of a campaign or a sequence step.
        Schema::create('email_marketing_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->nullable()->constrained('email_marketing_campaigns')->cascadeOnDelete();
            $table->foreignUlid('sequence_id')->nullable()->constrained('email_marketing_sequences')->cascadeOnDelete();
            $table->foreignUlid('step_id')->nullable()->constrained('email_marketing_sequence_steps')->nullOnDelete();
            $table->foreignUlid('enrollment_id')->nullable()->constrained('email_marketing_enrollments')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUlid('subscription_id')->nullable()->constrained('email_marketing_subscriptions')->nullOnDelete();
            // The idempotency key: one email per campaign and contact, or per enrolment and step.
            $table->string('send_key', 120);
            $table->char('variant', 1)->default('a');
            $table->string('status', 16);
            $table->string('reason', 120)->nullable();
            // The communication message id. Plain column: that module's tables are not ours to reference.
            $table->string('message_id', 26)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'send_key'], 'em_deliveries_tenant_id_send_key_unique');
            $table->index(['tenant_id', 'campaign_id', 'status'], 'em_deliveries_tenant_id_campaign_id_status_index');
            $table->index(['tenant_id', 'sequence_id', 'status'], 'em_deliveries_tenant_id_sequence_id_status_index');
            $table->index(['tenant_id', 'contact_id'], 'em_deliveries_tenant_id_contact_id_index');
            $table->index(['tenant_id', 'message_id'], 'em_deliveries_tenant_id_message_id_index');
        });

        Schema::create('email_marketing_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('list_id')->constrained('email_marketing_lists')->cascadeOnDelete();
            $table->string('file_name', 190);
            $table->char('file_sha256', 64);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('subscribed_rows')->default(0);
            $table->unsignedInteger('pending_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('errors')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'list_id', 'file_sha256'], 'em_imports_tenant_id_list_id_file_sha256_unique');
        });

        Schema::create('email_marketing_automation_action_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();

            $table->unique(['tenant_id', 'action', 'idempotency_key'], 'email_marketing_receipts_unique');
        });
    }

    public function down(): void
    {
        foreach ([
            'email_marketing_automation_action_receipts', 'email_marketing_imports', 'email_marketing_deliveries',
            'email_marketing_enrollments', 'email_marketing_sequence_steps', 'email_marketing_sequences',
            'email_marketing_campaigns', 'email_marketing_segments', 'email_marketing_templates',
            'email_marketing_subscriptions', 'email_marketing_lists', 'email_marketing_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
