<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_workflows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            // The member whose permissions the workflow runs with: whoever last published it.
            $table->foreignUlid('actor_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('created_by_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->ulid('published_version_id')->nullable();
            // Copied from the published version so a trigger is matched with one indexed query.
            $table->string('trigger_key', 100)->nullable();
            $table->json('trigger_config')->nullable();
            $table->char('webhook_token_hash', 64)->nullable();
            $table->text('webhook_secret')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_run_status', 20)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'webhook_token_hash']);
            $table->index(['tenant_id', 'status', 'trigger_key']);
            $table->index(['tenant_id', 'status', 'next_run_at']);
            $table->index(['tenant_id', 'updated_at']);
        });

        Schema::create('automation_workflow_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('graph');
            $table->char('graph_hash', 64)->nullable();
            $table->string('trigger_key', 100)->nullable();
            $table->json('trigger_config')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignUlid('created_by_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('published_by_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'workflow_id', 'number']);
            $table->index(['tenant_id', 'workflow_id', 'status']);
        });

        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
            $table->foreignUlid('version_id')->constrained('automation_workflow_versions')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('mode', 12)->default('live');
            $table->string('source', 12);
            $table->string('status', 24)->default('queued');
            $table->string('trigger_key', 100)->nullable();
            $table->string('idempotency_key', 120);
            $table->unsignedTinyInteger('depth')->default(0);
            $table->ulid('parent_run_id')->nullable();
            $table->foreignUlid('started_by_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            // A test run executes a snapshot of the draft; a live run reads the immutable version.
            $table->json('graph')->nullable();
            $table->json('context')->nullable();
            $table->unsignedInteger('steps_executed')->default(0);
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('resume_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'workflow_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'resume_at']);
            $table->index(['tenant_id', 'workflow_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'parent_run_id']);
        });

        Schema::create('automation_run_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->string('node_type', 24);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedInteger('sequence')->default(0);
            $table->unsignedInteger('iteration')->default(0);
            $table->ulid('scope')->nullable();
            $table->ulid('parent_step_id')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('resume_at')->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('meta')->nullable();
            $table->json('logs')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'run_id', 'status', 'available_at']);
            $table->index(['tenant_id', 'run_id', 'sequence']);
        });

        Schema::create('automation_approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->foreignUlid('run_step_id')->constrained('automation_run_steps')->cascadeOnDelete();
            $table->foreignUlid('workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('message')->nullable();
            $table->string('approver_type', 16);
            $table->string('approver_permission', 120)->nullable();
            $table->foreignUlid('approver_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->boolean('prevent_self_approval')->default(true);
            $table->foreignUlid('requested_by_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('on_timeout', 12)->default('reject');
            $table->foreignUlid('decided_by_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'run_step_id']);
            $table->index(['tenant_id', 'status', 'expires_at']);
            $table->index(['tenant_id', 'run_id']);
        });

        Schema::create('automation_webhook_endpoints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('url');
            $table->text('secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_called_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->foreignUlid('created_by_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_webhook_endpoints');
        Schema::dropIfExists('automation_approvals');
        Schema::dropIfExists('automation_run_steps');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_workflow_versions');
        Schema::dropIfExists('automation_workflows');
    }
};
