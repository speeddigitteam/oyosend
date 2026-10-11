<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_comments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignUlid('author_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'task_id', 'created_at']);
        });

        Schema::create('project_comment_mentions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('comment_id')->constrained('project_task_comments')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['tenant_id', 'comment_id', 'membership_id'], 'project_comment_mentions_tenant_comment_member_unique');
            $table->index(['tenant_id', 'membership_id', 'created_at'], 'project_mentions_tenant_member_created_index');
        });

        Schema::create('project_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('entity_type', 24);
            $table->ulid('entity_id');
            $table->string('original_name', 255);
            $table->string('path', 500);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->foreignUlid('uploaded_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'project_attachments_tenant_entity_index');
        });

        Schema::create('project_time_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->restrictOnDelete();
            $table->date('worked_on');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('minutes');
            $table->text('description')->nullable();
            $table->boolean('is_billable')->default(false);
            $table->char('currency', 3);
            $table->unsignedBigInteger('billable_rate_amount')->default(0);
            $table->unsignedBigInteger('cost_rate_amount')->default(0);
            $table->string('status', 20);
            $table->foreignUlid('approved_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'project_id', 'status', 'worked_on', 'id'], 'project_time_tenant_project_status_date_id_index');
            $table->index(['tenant_id', 'membership_id', 'worked_on']);
            $table->index(['tenant_id', 'status', 'worked_on']);
            $table->index(['tenant_id', 'worked_on', 'created_at', 'id'], 'project_time_tenant_date_created_id_index');
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('project_expenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->string('description', 500);
            $table->date('incurred_on');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->boolean('is_billable')->default(false);
            $table->string('status', 20);
            $table->foreignUlid('submitted_by')->constrained('tenant_memberships')->restrictOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'project_id', 'status', 'incurred_on', 'id'], 'project_expense_tenant_project_status_date_id_index');
            $table->index(['tenant_id', 'submitted_by', 'incurred_on']);
            $table->index(['tenant_id', 'status', 'incurred_on'], 'project_expense_tenant_status_date_index');
            $table->index(['tenant_id', 'incurred_on', 'created_at', 'id'], 'project_expense_tenant_date_created_id_index');
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('project_approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('entity_type', 24);
            $table->ulid('entity_id');
            $table->string('status', 20);
            $table->string('pending_key', 64)->nullable();
            $table->text('request_note')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignUlid('requested_by')->constrained('tenant_memberships')->restrictOnDelete();
            $table->foreignUlid('approver_id')->constrained('tenant_memberships')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'approver_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->unique(['tenant_id', 'pending_key'], 'project_approvals_tenant_pending_key_unique');
        });

        Schema::create('project_client_access', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('status', 20);
            $table->string('token_hash', 64)->nullable();
            $table->boolean('can_comment')->default(true);
            $table->boolean('can_view_financials')->default(false);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignUlid('invited_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'project_id', 'contact_id'], 'project_client_access_tenant_project_contact_unique');
            $table->unique(['tenant_id', 'token_hash']);
            $table->index(['tenant_id', 'contact_id', 'status']);
        });

        Schema::table('project_task_comments', function (Blueprint $table): void {
            $table->foreignUlid('client_access_id')->nullable()->after('author_id')->constrained('project_client_access')->nullOnDelete();
            $table->index(['tenant_id', 'client_access_id', 'created_at'], 'project_comments_tenant_client_created_index');
        });

        Schema::create('project_activity_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('task_id')->nullable()->constrained('project_tasks')->cascadeOnDelete();
            $table->string('event', 100);
            $table->string('summary', 500);
            $table->foreignUlid('actor_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'project_id', 'occurred_at']);
            $table->index(['tenant_id', 'task_id', 'occurred_at']);
        });

        Schema::create('project_automation_action_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();
            $table->unique(['tenant_id', 'action', 'idempotency_key'], 'project_action_receipts_tenant_action_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_automation_action_receipts');
        Schema::dropIfExists('project_activity_history');
        Schema::dropIfExists('project_approvals');
        Schema::dropIfExists('project_expenses');
        Schema::dropIfExists('project_time_entries');
        Schema::dropIfExists('project_attachments');
        Schema::dropIfExists('project_comment_mentions');
        Schema::dropIfExists('project_task_comments');
        Schema::dropIfExists('project_client_access');
    }
};
