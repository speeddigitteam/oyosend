<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tenant = static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
        };

        // owner_id is the employee's own workspace membership: `own` scope self-service hangs on it.
        $placement = static function (Blueprint $table): void {
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
        };

        Schema::create('hrm_settings', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('employee_prefix', 20)->default('EMP-');
            $table->unsignedTinyInteger('employee_padding')->default(4);
            // ISO weekdays, 1 = Monday ... 7 = Sunday.
            $table->json('weekly_off_days');
            $table->unsignedTinyInteger('leave_year_start_month')->default(1);
            $table->unsignedSmallInteger('work_day_minutes')->default(480);
            $table->timestamps();

            $table->unique(['tenant_id']);
        });

        Schema::create('hrm_number_sequences', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('kind', 20);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'kind'], 'hrm_sequences_tenant_kind_unique');
        });

        Schema::create('hrm_designations', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('name', 120);
            $table->string('code', 30)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('hrm_employees', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $table->string('employee_number', 40);
            $placement($table);
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('work_email', 190)->nullable();
            $table->string('personal_email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->text('national_id')->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country', 2)->nullable();
            $table->foreignUlid('designation_id')->nullable()->constrained('hrm_designations')->nullOnDelete();
            $table->foreignUlid('manager_id')->nullable()->constrained('hrm_employees')->nullOnDelete();
            $table->string('employment_type', 20);
            $table->string('status', 20);
            $table->date('joined_on');
            $table->date('probation_ends_on')->nullable();
            $table->date('confirmed_on')->nullable();
            $table->date('status_changed_on')->nullable();
            $table->text('status_reason')->nullable();
            $table->date('terminated_on')->nullable();
            $table->date('last_working_on')->nullable();
            $table->string('termination_type', 24)->nullable();
            $table->text('termination_reason')->nullable();
            // Hourly cost in integer minor units of `cost_currency`: a hook for Projects/Payroll, not a salary.
            $table->unsignedBigInteger('cost_rate_amount')->nullable();
            $table->char('cost_currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_number']);
            $table->unique(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'manager_id']);
            $table->index(['tenant_id', 'joined_on']);
            $table->index(['tenant_id', 'last_name', 'first_name']);
        });

        Schema::create('hrm_employment_history', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->string('event', 32);
            $table->date('effective_on');
            $table->json('changes');
            $table->text('reason')->nullable();
            // A plain id, deliberately without a foreign key: the ledger is never updated.
            $table->ulid('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'employee_id', 'effective_on']);
        });

        Schema::create('hrm_emergency_contacts', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('relationship', 80);
            $table->string('phone', 40);
            $table->string('email', 190)->nullable();
            $table->string('address', 300)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('hrm_documents', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('title', 160);
            $table->string('original_name', 255);
            $table->string('path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->date('expires_on')->nullable();
            $table->ulid('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'expires_on']);
        });

        Schema::create('hrm_assets', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('asset_tag', 60)->nullable();
            $table->string('category', 60)->nullable();
            $table->date('assigned_on');
            $table->date('returned_on')->nullable();
            $table->string('return_condition', 60)->nullable();
            $table->text('notes')->nullable();
            $table->ulid('assigned_by')->nullable();
            $table->ulid('returned_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'asset_tag']);
        });

        Schema::create('hrm_holidays', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('name', 120);
            $table->date('holiday_on');
            $table->string('type', 16);
            $table->timestamps();

            $table->unique(['tenant_id', 'holiday_on']);
        });

        Schema::create('hrm_shifts', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('name', 100);
            $table->char('starts_at', 5);
            $table->char('ends_at', 5);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('late_grace_minutes')->default(0);
            $table->unsignedSmallInteger('early_grace_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_threshold_minutes')->default(0);
            $table->unsignedSmallInteger('half_day_minutes')->nullable();
            // Null: use the workspace weekly days off.
            $table->json('weekly_off_days')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hrm_shift_assignments', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $table->foreignUlid('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->foreignUlid('shift_id')->constrained('hrm_shifts')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $placement($table);
            $table->ulid('assigned_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'starts_on']);
            $table->index(['tenant_id', 'shift_id']);
        });

        Schema::create('hrm_attendance_entries', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $table->foreignUlid('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->dateTime('clock_in_at');
            $table->dateTime('clock_out_at')->nullable();
            // 1 while the entry has no clock-out. Unique per employee: at most one open entry.
            $table->unsignedTinyInteger('open_marker')->nullable();
            $table->string('source', 16);
            $table->string('note', 300)->nullable();
            $table->ulid('created_by')->nullable();
            $placement($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'open_marker'], 'hrm_entries_one_open_unique');
            $table->index(['tenant_id', 'employee_id', 'work_date']);
            $table->index(['tenant_id', 'work_date']);
        });

        Schema::create('hrm_attendance_days', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $table->foreignUlid('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignUlid('shift_id')->nullable()->constrained('hrm_shifts')->nullOnDelete();
            $table->string('status', 16);
            $table->unsignedSmallInteger('scheduled_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->dateTime('first_in_at')->nullable();
            $table->dateTime('last_out_at')->nullable();
            $table->unsignedSmallInteger('entries_count')->default(0);
            $table->unsignedTinyInteger('leave_units')->default(0);
            $table->boolean('is_closed')->default(false);
            $table->dateTime('closed_at')->nullable();
            $table->unsignedSmallInteger('revision')->default(0);
            $placement($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'work_date'], 'hrm_days_employee_date_unique');
            $table->index(['tenant_id', 'work_date', 'status']);
            $table->index(['tenant_id', 'department_id', 'work_date']);
        });

        Schema::create('hrm_regularizations', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $table->foreignUlid('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->dateTime('clock_in_at');
            $table->dateTime('clock_out_at');
            $table->text('reason');
            $table->string('status', 16);
            $table->ulid('requested_by')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $placement($table);
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'employee_id', 'work_date']);
        });

        Schema::create('hrm_leave_types', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('name', 100);
            $table->string('code', 20);
            $table->boolean('is_paid')->default(true);
            $table->boolean('tracks_balance')->default(true);
            $table->boolean('allows_half_day')->default(true);
            $table->boolean('requires_reason')->default(false);
            $table->unsignedSmallInteger('max_consecutive_days')->nullable();
            $table->unsignedSmallInteger('min_notice_days')->default(0);
            $table->string('approval_flow', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('hrm_leave_policies', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('leave_type_id')->constrained('hrm_leave_types')->cascadeOnDelete();
            $table->string('name', 100);
            // Null: every employment type.
            $table->json('employment_types')->nullable();
            $table->string('accrual', 16);
            // Integer units: one day is two units (see LeaveUnits).
            $table->unsignedInteger('annual_units');
            $table->unsignedInteger('carry_forward_max_units')->default(0);
            $table->unsignedInteger('max_balance_units')->nullable();
            $table->unsignedInteger('max_units_per_request')->nullable();
            $table->boolean('sandwich_rule')->default(false);
            $table->unsignedSmallInteger('min_service_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'leave_type_id', 'name'], 'hrm_policies_type_name_unique');
        });

        Schema::create('hrm_leave_requests', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $table->foreignUlid('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->foreignUlid('leave_type_id')->constrained('hrm_leave_types')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('start_session', 12);
            $table->string('end_session', 12);
            $table->unsignedInteger('units');
            // date => units for each counted day, fixed at submission (sessions and sandwich rule applied).
            $table->json('days');
            $table->unsignedSmallInteger('leave_year');
            $table->string('status', 20);
            $table->text('reason')->nullable();
            $table->ulid('submitted_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->ulid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $placement($table);
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'starts_on']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'starts_on', 'ends_on']);
        });

        Schema::create('hrm_leave_approvals', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('leave_request_id')->constrained('hrm_leave_requests')->cascadeOnDelete();
            $table->string('step', 12);
            $table->string('decision', 12);
            $table->ulid('decided_by')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('decided_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'leave_request_id', 'step'], 'hrm_approvals_request_step_unique');
        });

        // Append-only. A balance is the sum of rows; nothing is ever updated or deleted.
        Schema::create('hrm_leave_ledger', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->foreignUlid('leave_type_id')->constrained('hrm_leave_types')->restrictOnDelete();
            $table->unsignedSmallInteger('leave_year');
            $table->integer('units');
            $table->string('kind', 16);
            $table->date('effective_on');
            $table->ulid('leave_request_id')->nullable();
            $table->string('idempotency_key', 160);
            $table->text('note')->nullable();
            $table->ulid('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'idempotency_key'], 'hrm_ledger_idempotency_unique');
            $table->index(['tenant_id', 'employee_id', 'leave_type_id', 'leave_year'], 'hrm_ledger_balance_index');
            $table->index(['tenant_id', 'leave_request_id']);
        });

        Schema::create('hrm_automation_action_receipts', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();

            $table->unique(['tenant_id', 'action', 'idempotency_key'], 'hrm_receipts_action_key_unique');
        });
    }

    public function down(): void
    {
        foreach ([
            'hrm_automation_action_receipts', 'hrm_leave_ledger', 'hrm_leave_approvals', 'hrm_leave_requests',
            'hrm_leave_policies', 'hrm_leave_types', 'hrm_regularizations', 'hrm_attendance_days',
            'hrm_attendance_entries', 'hrm_shift_assignments', 'hrm_shifts', 'hrm_holidays', 'hrm_assets',
            'hrm_documents', 'hrm_emergency_contacts', 'hrm_employment_history', 'hrm_employees',
            'hrm_designations', 'hrm_number_sequences', 'hrm_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
