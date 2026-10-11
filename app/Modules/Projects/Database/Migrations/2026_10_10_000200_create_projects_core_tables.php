<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('colour', 24)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active', 'position']);
        });

        Schema::create('project_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('project_categories')->nullOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->json('task_blueprints')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active', 'category_id']);
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('template_id')->nullable()->constrained('project_templates')->nullOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('project_categories')->nullOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('status', 24);
            $table->string('priority', 16);
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('budget_amount')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status', 'due_on']);
            $table->index(['tenant_id', 'due_on'], 'projects_tenant_due_index');
            $table->index(['tenant_id', 'starts_on'], 'projects_tenant_start_index');
            $table->index(['tenant_id', 'name', 'id'], 'projects_tenant_name_id_index');
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'category_id']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('project_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->string('role', 32)->default('member');
            $table->unsignedSmallInteger('allocation_percent')->default(100);
            $table->foreignUlid('added_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'project_id', 'membership_id'], 'project_members_tenant_project_member_unique');
            $table->index(['tenant_id', 'membership_id', 'project_id']);
        });

        Schema::create('project_sprints', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('goal')->nullable();
            $table->string('status', 24);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'project_id', 'name']);
            $table->index(['tenant_id', 'project_id', 'status', 'starts_on']);
        });

        Schema::create('project_milestones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->date('due_on');
            $table->string('status', 24);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'project_id', 'status', 'due_on']);
        });

        Schema::create('project_tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignUlid('milestone_id')->nullable()->constrained('project_milestones')->nullOnDelete();
            $table->foreignUlid('sprint_id')->nullable()->constrained('project_sprints')->nullOnDelete();
            $table->string('title', 240);
            $table->text('description')->nullable();
            $table->string('status', 24);
            $table->string('priority', 16);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('estimated_minutes')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('client_visible')->default(false);
            $table->json('recurrence_rule')->nullable();
            $table->timestamp('next_recurrence_at')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->string('automation_key', 160)->nullable();
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'project_id', 'status', 'position']);
            $table->index(['tenant_id', 'project_id', 'starts_at', 'due_at']);
            $table->index(['tenant_id', 'sprint_id', 'status']);
            $table->index(['tenant_id', 'milestone_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'reminder_sent_at', 'remind_at', 'id'], 'project_tasks_tenant_unsent_reminder_index');
            $table->index(['tenant_id', 'next_recurrence_at']);
            $table->unique(['tenant_id', 'automation_key']);
        });

        Schema::create('project_task_assignees', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->unsignedSmallInteger('allocation_percent')->default(100);
            $table->timestamp('assigned_at');
            $table->unique(['tenant_id', 'task_id', 'membership_id'], 'project_task_assignees_tenant_task_member_unique');
            $table->index(['tenant_id', 'membership_id', 'task_id']);
        });

        Schema::create('project_task_watchers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['tenant_id', 'task_id', 'membership_id'], 'project_task_watchers_tenant_task_member_unique');
            $table->index(['tenant_id', 'membership_id', 'task_id']);
        });

        Schema::create('project_task_dependencies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignUlid('depends_on_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->string('type', 24)->default('finish_to_start');
            $table->timestamps();
            $table->unique(['tenant_id', 'task_id', 'depends_on_task_id'], 'project_task_dependencies_tenant_tasks_unique');
            $table->index(['tenant_id', 'depends_on_task_id']);
        });

        Schema::create('project_task_checklist_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->string('title', 240);
            $table->boolean('is_completed')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignUlid('completed_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'task_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_checklist_items');
        Schema::dropIfExists('project_task_dependencies');
        Schema::dropIfExists('project_task_watchers');
        Schema::dropIfExists('project_task_assignees');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('project_sprints');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('project_templates');
        Schema::dropIfExists('project_categories');
    }
};
