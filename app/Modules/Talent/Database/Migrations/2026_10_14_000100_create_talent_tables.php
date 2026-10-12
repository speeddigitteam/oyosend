<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Talent & Performance. Every table is tenant-owned (`tenant_id` first in every unique index).
 * Employees are referenced by a plain `employee_id` (no foreign key): the HR record belongs to the
 * `hrm` module and is only ever read through `App\Core\Workforce\Contracts\EmployeeDirectory`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenant = static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
        };

        // owner_id is a workspace membership: the `own` scope hangs on it. department/branch are core records.
        $placement = static function (Blueprint $table): void {
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
        };

        Schema::create('talent_settings', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->unsignedSmallInteger('default_probation_days')->default(90);
            $table->unsignedSmallInteger('probation_reminder_days')->default(14);
            $table->unsignedSmallInteger('certification_reminder_days')->default(30);
            $table->unsignedSmallInteger('candidate_retention_months')->default(24);
            $table->timestamps();

            $table->unique(['tenant_id']);
        });

        // ---- Recruitment -------------------------------------------------------------------------------
        Schema::create('talent_pipelines', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('talent_pipeline_stages', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('pipeline_id')->constrained('talent_pipelines')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('position')->default(0);
            // active | hired | rejected
            $table->string('type', 12)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'pipeline_id', 'name']);
            $table->index(['tenant_id', 'pipeline_id', 'position']);
        });

        Schema::create('talent_rejection_reasons', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('talent_job_openings', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $placement($table);
            $table->foreignUlid('pipeline_id')->constrained('talent_pipelines')->restrictOnDelete();
            // The hiring manager is an HRM employee: a plain id, read through the employee directory.
            $table->ulid('hiring_manager_id')->nullable();
            $table->string('title', 160);
            $table->string('employment_type', 20)->nullable();
            $table->string('location', 160)->nullable();
            $table->unsignedSmallInteger('headcount')->default(1);
            $table->string('status', 12)->default('draft');
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->date('opens_on')->nullable();
            $table->date('closes_on')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'hiring_manager_id']);
        });

        Schema::create('talent_candidates', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $placement($table);
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('headline', 160)->nullable();
            $table->string('current_company', 160)->nullable();
            $table->string('location', 160)->nullable();
            $table->string('source', 60)->nullable();
            // Expected compensation: integer minor units + currency.
            $table->unsignedBigInteger('expected_amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->text('summary')->nullable();
            // Consent and retention: how the data came to us, and when it must go.
            $table->string('consent_basis', 24)->default('candidate_provided');
            $table->timestamp('consent_given_at')->nullable();
            $table->date('retain_until')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'anonymized_at']);
            $table->index(['tenant_id', 'retain_until']);
            $table->index(['tenant_id', 'owner_id']);
        });

        Schema::create('talent_applications', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $placement($table);
            $table->foreignUlid('opening_id')->constrained('talent_job_openings')->cascadeOnDelete();
            $table->foreignUlid('candidate_id')->constrained('talent_candidates')->cascadeOnDelete();
            $table->foreignUlid('stage_id')->constrained('talent_pipeline_stages')->restrictOnDelete();
            // open | hired | rejected | withdrawn
            $table->string('status', 12)->default('open');
            $table->foreignUlid('rejection_reason_id')->nullable()->constrained('talent_rejection_reasons')->nullOnDelete();
            $table->text('rejection_note')->nullable();
            $table->string('source', 60)->nullable();
            $table->date('applied_on');
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('hired_at')->nullable();
            $table->date('start_on')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'opening_id', 'candidate_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'stage_id']);
            $table->index(['tenant_id', 'candidate_id']);
            $table->index(['tenant_id', 'owner_id']);
        });

        // Append-only: one row per move. Plain ids: history outlives a deleted stage or reason.
        Schema::create('talent_application_history', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('application_id')->constrained('talent_applications')->cascadeOnDelete();
            $table->string('event', 16);
            $table->ulid('from_stage_id')->nullable();
            $table->ulid('to_stage_id')->nullable();
            $table->ulid('reason_id')->nullable();
            $table->text('note')->nullable();
            $table->ulid('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'application_id', 'created_at'], 'talent_history_application_idx');
        });

        Schema::create('talent_candidate_documents', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('candidate_id')->constrained('talent_candidates')->cascadeOnDelete();
            $table->foreignUlid('application_id')->nullable()->constrained('talent_applications')->nullOnDelete();
            $table->string('type', 16);
            $table->string('title', 160);
            $table->string('original_name', 255);
            $table->string('path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->ulid('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'candidate_id']);
        });

        Schema::create('talent_notes', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('candidate_id')->constrained('talent_candidates')->cascadeOnDelete();
            $table->foreignUlid('application_id')->nullable()->constrained('talent_applications')->cascadeOnDelete();
            $table->ulid('author_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->index(['tenant_id', 'candidate_id']);
            $table->index(['tenant_id', 'application_id']);
        });

        Schema::create('talent_interviews', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $placement($table);
            $table->foreignUlid('application_id')->constrained('talent_applications')->cascadeOnDelete();
            $table->string('round_name', 120);
            // in_person | video | phone
            $table->string('mode', 12)->default('in_person');
            // Instants are UTC; `timezone` is the workspace zone the time was entered in.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 64);
            $table->string('location', 255)->nullable();
            // scheduled | completed | cancelled | no_show
            $table->string('status', 12)->default('scheduled');
            // advance | hold | reject
            $table->string('outcome', 12)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->ulid('scheduled_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'application_id']);
            $table->index(['tenant_id', 'status', 'starts_at']);
            $table->index(['tenant_id', 'owner_id']);
        });

        Schema::create('talent_interview_panel', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('interview_id')->constrained('talent_interviews')->cascadeOnDelete();
            $table->ulid('employee_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'interview_id', 'employee_id'], 'talent_panel_unique');
            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('talent_scorecards', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('interview_id')->constrained('talent_interviews')->cascadeOnDelete();
            $table->foreignUlid('application_id')->constrained('talent_applications')->cascadeOnDelete();
            $table->ulid('employee_id');
            $table->unsignedTinyInteger('rating');
            // strong_no | no | yes | strong_yes
            $table->string('recommendation', 12);
            $table->text('strengths')->nullable();
            $table->text('concerns')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'interview_id', 'employee_id'], 'talent_scorecards_unique');
            $table->index(['tenant_id', 'application_id']);
        });

        Schema::create('talent_offers', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('application_id')->constrained('talent_applications')->cascadeOnDelete();
            // draft | sent | accepted | declined | withdrawn
            $table->string('status', 12)->default('draft');
            $table->unsignedBigInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->date('proposed_start_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('notes')->nullable();
            // 1 while the offer is live (draft, sent or accepted): at most one per application.
            $table->unsignedTinyInteger('live_marker')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'application_id', 'live_marker'], 'talent_offers_live_unique');
        });

        // ---- Probation ---------------------------------------------------------------------------------
        Schema::create('talent_probations', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $placement($table);
            $table->ulid('employee_id');
            $table->date('started_on');
            $table->date('ends_on');
            // The end date last read from HRM: a change there replaces `ends_on`, an extension decided here does not.
            $table->date('observed_ends_on')->nullable();
            // in_progress | confirmed | ended
            $table->string('status', 12)->default('in_progress');
            // 1 while in progress: one open probation per employee.
            $table->unsignedTinyInteger('open_marker')->nullable();
            $table->date('closed_on')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'open_marker'], 'talent_probations_open_unique');
            $table->index(['tenant_id', 'status', 'ends_on']);
            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('talent_probation_reviews', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('probation_id')->constrained('talent_probations')->cascadeOnDelete();
            // confirm | extend | end
            $table->string('decision', 12);
            $table->date('extend_until')->nullable();
            $table->text('note')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->date('decided_on');
            $table->timestamps();

            $table->index(['tenant_id', 'probation_id']);
        });

        // ---- Onboarding / offboarding -------------------------------------------------------------------
        Schema::create('talent_checklist_templates', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('name', 120);
            // onboarding | offboarding
            $table->string('kind', 12);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'name']);
        });

        Schema::create('talent_template_tasks', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('template_id')->constrained('talent_checklist_templates')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            // employee | manager | hr | person
            $table->string('assignee_role', 12)->default('hr');
            $table->ulid('assignee_employee_id')->nullable();
            // Days from the anchor date (joining date / last working day); negative = before.
            $table->smallInteger('due_offset_days')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'template_id', 'position']);
        });

        Schema::create('talent_checklists', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $placement($table);
            $table->ulid('employee_id');
            $table->string('kind', 12);
            $table->foreignUlid('template_id')->nullable()->constrained('talent_checklist_templates')->nullOnDelete();
            $table->string('name', 160);
            $table->date('anchor_date');
            // open | completed | cancelled
            $table->string('status', 12)->default('open');
            // Makes starting from an event idempotent: `hrm.employee.created:{employee_id}` or `manual:{ulid}`.
            $table->string('source_key', 190);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'source_key']);
            $table->index(['tenant_id', 'employee_id', 'kind']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('talent_checklist_tasks', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('checklist_id')->constrained('talent_checklists')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('assignee_role', 12)->default('hr');
            $table->ulid('assignee_employee_id')->nullable();
            $table->date('due_on')->nullable();
            // pending | done | skipped
            $table->string('status', 8)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->ulid('completed_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'checklist_id', 'position']);
            $table->index(['tenant_id', 'assignee_employee_id', 'status'], 'talent_tasks_assignee_idx');
            $table->index(['tenant_id', 'status', 'due_on']);
        });

        // ---- Performance -------------------------------------------------------------------------------
        Schema::create('talent_review_forms', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('scale_max')->default(5);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('talent_review_form_items', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('form_id')->constrained('talent_review_forms')->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            // Basis points: the items of a form add up to exactly 10 000.
            $table->unsignedSmallInteger('weight_bp');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'form_id', 'position']);
        });

        Schema::create('talent_review_cycles', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('name', 160);
            $table->foreignUlid('form_id')->constrained('talent_review_forms')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('self_due_on')->nullable();
            $table->date('manager_due_on')->nullable();
            $table->boolean('requires_self_review')->default(true);
            // draft | active | calibration | closed
            $table->string('status', 12)->default('draft');
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('calibration_started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('talent_reviews', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $placement($table);
            $table->foreignUlid('cycle_id')->constrained('talent_review_cycles')->cascadeOnDelete();
            $table->ulid('employee_id');
            $table->ulid('reviewer_employee_id')->nullable();
            // pending_self | pending_manager | pending_calibration | calibrated | acknowledged
            $table->string('status', 24);
            // Weighted scores in hundredths (4.35 is stored as 435).
            $table->unsignedSmallInteger('self_score')->nullable();
            $table->unsignedSmallInteger('manager_score')->nullable();
            $table->unsignedSmallInteger('final_score')->nullable();
            $table->boolean('final_overridden')->default(false);
            $table->text('self_comment')->nullable();
            $table->text('manager_comment')->nullable();
            $table->text('calibration_note')->nullable();
            $table->text('employee_comment')->nullable();
            $table->timestamp('self_submitted_at')->nullable();
            $table->timestamp('manager_submitted_at')->nullable();
            $table->timestamp('calibrated_at')->nullable();
            $table->ulid('calibrated_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'cycle_id', 'employee_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'reviewer_employee_id']);
            $table->index(['tenant_id', 'owner_id']);
        });

        Schema::create('talent_review_ratings', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('review_id')->constrained('talent_reviews')->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained('talent_review_form_items')->cascadeOnDelete();
            // self | manager
            $table->string('party', 8);
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'review_id', 'item_id', 'party'], 'talent_ratings_unique');
        });

        Schema::create('talent_goals', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $placement($table);
            $table->ulid('employee_id');
            $table->foreignUlid('cycle_id')->nullable()->constrained('talent_review_cycles')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            // goal | kpi
            $table->string('kind', 8)->default('goal');
            $table->string('unit', 40)->nullable();
            $table->bigInteger('start_value')->default(0);
            $table->bigInteger('target_value');
            $table->bigInteger('current_value')->default(0);
            // active | achieved | missed | cancelled
            $table->string('status', 12)->default('active');
            $table->date('due_on')->nullable();
            $table->timestamp('achieved_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'cycle_id']);
        });

        Schema::create('talent_goal_updates', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('goal_id')->constrained('talent_goals')->cascadeOnDelete();
            $table->bigInteger('value');
            $table->text('note')->nullable();
            $table->date('recorded_on');
            $table->ulid('recorded_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'goal_id', 'recorded_on']);
        });

        // ---- Training ----------------------------------------------------------------------------------
        Schema::create('talent_courses', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('title', 160);
            $table->string('code', 30)->nullable();
            $table->string('provider', 160)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            // A certification: completing it is valid for this many months.
            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'title']);
        });

        Schema::create('talent_training_sessions', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('course_id')->constrained('talent_courses')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 64);
            $table->string('location', 255)->nullable();
            $table->string('trainer', 160)->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            // scheduled | completed | cancelled
            $table->string('status', 12)->default('scheduled');
            $table->timestamps();

            $table->index(['tenant_id', 'course_id', 'starts_at']);
        });

        Schema::create('talent_enrolments', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $placement($table);
            $table->foreignUlid('course_id')->constrained('talent_courses')->cascadeOnDelete();
            $table->foreignUlid('session_id')->nullable()->constrained('talent_training_sessions')->nullOnDelete();
            $table->ulid('employee_id');
            // enrolled | completed | failed | cancelled
            $table->string('status', 12)->default('enrolled');
            $table->date('enrolled_on');
            $table->date('completed_on')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->date('expires_on')->nullable();
            // 1 while enrolled: a person has at most one open enrolment per course.
            $table->unsignedTinyInteger('open_marker')->nullable();
            $table->text('notes')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'course_id', 'employee_id', 'open_marker'], 'talent_enrolments_open_unique');
            $table->index(['tenant_id', 'employee_id', 'status']);
            $table->index(['tenant_id', 'status', 'expires_on']);
            $table->index(['tenant_id', 'owner_id']);
        });

        // ---- Plumbing ----------------------------------------------------------------------------------
        // One row per reminder that was announced: the unique key makes the scheduled job idempotent.
        Schema::create('talent_reminders', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('kind', 40);
            $table->ulid('subject_id');
            $table->string('reminder_key', 190);
            $table->date('due_on')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'reminder_key']);
            $table->index(['tenant_id', 'kind']);
        });

        Schema::create('talent_automation_action_receipts', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();

            $table->unique(['tenant_id', 'action', 'idempotency_key'], 'talent_receipts_unique');
        });
    }

    public function down(): void
    {
        foreach ([
            'talent_automation_action_receipts', 'talent_reminders', 'talent_enrolments', 'talent_training_sessions',
            'talent_courses', 'talent_goal_updates', 'talent_goals', 'talent_review_ratings', 'talent_reviews',
            'talent_review_cycles', 'talent_review_form_items', 'talent_review_forms', 'talent_checklist_tasks',
            'talent_checklists', 'talent_template_tasks', 'talent_checklist_templates', 'talent_probation_reviews',
            'talent_probations', 'talent_offers', 'talent_scorecards', 'talent_interview_panel', 'talent_interviews',
            'talent_notes', 'talent_candidate_documents', 'talent_application_history', 'talent_applications',
            'talent_candidates', 'talent_job_openings', 'talent_rejection_reasons', 'talent_pipeline_stages',
            'talent_pipelines', 'talent_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
