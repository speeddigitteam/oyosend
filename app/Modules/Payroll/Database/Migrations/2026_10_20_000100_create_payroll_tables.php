<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll tables. Money is stored as unsigned integer minor units next to a currency; rates are basis
 * points; dates are local `Y-m-d`. Employees are referenced by plain ULID columns (no foreign key to the
 * HRM tables on purpose): Payroll reads HRM only through the `App\Core\Workforce` contracts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenant = static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
        };

        // owner_id is the employee's workspace membership: the `own` scope of self-service hangs on it.
        $placement = static function (Blueprint $table): void {
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
        };

        Schema::create('payroll_settings', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('days_basis', 20)->default('calendar_days');
            $table->unsignedTinyInteger('fixed_days')->default(30);
            $table->string('daily_rate_base', 10)->default('gross');
            $table->boolean('deduct_unpaid_leave')->default(true);
            $table->boolean('deduct_absence')->default(true);
            $table->unsignedSmallInteger('work_day_minutes')->default(480);
            $table->unsignedInteger('overtime_multiplier_bps')->default(15000);
            $table->boolean('overtime_taxable')->default(true);
            $table->unsignedInteger('max_overtime_minutes')->nullable();
            $table->boolean('require_separate_approver')->default(true);
            $table->string('payslip_prefix', 20)->default('PSL-');
            $table->string('run_prefix', 20)->default('RUN-');
            $table->unsignedTinyInteger('number_padding')->default(6);
            $table->text('payslip_note')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id']);
        });

        Schema::create('payroll_number_sequences', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('kind', 20);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'kind'], 'payroll_sequences_tenant_kind_unique');
        });

        Schema::create('payroll_structures', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('name', 120);
            $table->string('code', 30)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('payroll_structure_components', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('structure_id')->constrained('payroll_structures')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 30);
            $table->string('kind', 12);
            $table->string('calc', 16);
            $table->unsignedBigInteger('amount')->nullable();
            $table->unsignedInteger('rate_bps')->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'structure_id', 'code'], 'payroll_components_code_unique');
            $table->index(['tenant_id', 'structure_id']);
        });

        Schema::create('payroll_profiles', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $table->ulid('employee_id');
            $placement($table);
            $table->string('payment_method', 20)->nullable();
            $table->string('account_holder', 160)->nullable();
            $table->string('bank_name', 160)->nullable();
            $table->string('bank_branch', 160)->nullable();
            $table->string('routing_number', 40)->nullable();
            // Encrypted at rest (cast `encrypted`).
            $table->text('account_number')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('payroll_salary_assignments', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->ulid('employee_id');
            $table->foreignUlid('profile_id')->constrained('payroll_profiles')->restrictOnDelete();
            $table->foreignUlid('structure_id')->constrained('payroll_structures')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedBigInteger('basic_amount');
            $table->char('currency', 3);
            $table->date('effective_from');
            $table->text('reason')->nullable();
            // A plain id, deliberately without a foreign key: the history is never updated.
            $table->ulid('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'employee_id', 'revision'], 'payroll_assignments_revision_unique');
            $table->unique(['tenant_id', 'employee_id', 'effective_from'], 'payroll_assignments_effective_unique');
        });

        Schema::create('payroll_periods', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('name', 120);
            $table->string('kind', 12);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('pay_date')->nullable();
            $table->string('status', 16);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'starts_on', 'ends_on']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('period_id')->constrained('payroll_periods')->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('status', 12);
            // Assigned at approval, so the sequence has no holes.
            $table->string('number', 40)->nullable();
            $table->foreignUlid('reverses_run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            // 1 for the single regular run of a period, null otherwise (a unique index on it).
            $table->unsignedTinyInteger('regular_marker')->nullable();
            $table->foreignUlid('prepared_by_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('submitted_by_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->char('currency', 3);
            $table->unsignedInteger('employee_count')->default(0);
            $table->unsignedBigInteger('gross_amount')->default(0);
            $table->unsignedBigInteger('deduction_amount')->default(0);
            $table->unsignedBigInteger('reimbursement_amount')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->unsignedBigInteger('employer_amount')->default(0);
            $table->char('input_fingerprint', 64)->nullable();
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'period_id', 'regular_marker'], 'payroll_runs_regular_unique');
            $table->unique(['tenant_id', 'reverses_run_id'], 'payroll_runs_reversal_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'period_id']);
        });

        Schema::create('payroll_payslips', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $table->foreignUlid('run_id')->constrained('payroll_runs')->restrictOnDelete();
            $table->foreignUlid('period_id')->constrained('payroll_periods')->restrictOnDelete();
            $table->ulid('employee_id');
            $placement($table);
            $table->string('number', 40)->nullable();
            $table->string('employee_number', 40);
            $table->string('employee_name', 220);
            $table->string('designation_name', 120)->nullable();
            $table->string('department_name', 120)->nullable();
            $table->string('employment_type', 20);
            $table->date('joined_on');
            $table->char('currency', 3);
            $table->ulid('assignment_id')->nullable();
            $table->string('structure_name', 120)->nullable();
            $table->unsignedSmallInteger('payable_days')->default(0);
            $table->unsignedSmallInteger('period_days')->default(0);
            $table->unsignedBigInteger('basic_amount')->default(0);
            $table->unsignedBigInteger('gross_amount')->default(0);
            $table->unsignedBigInteger('taxable_amount')->default(0);
            $table->unsignedBigInteger('deduction_amount')->default(0);
            $table->unsignedBigInteger('reimbursement_amount')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->unsignedBigInteger('employer_amount')->default(0);
            $table->json('attendance')->nullable();
            $table->json('warnings')->nullable();
            $table->boolean('is_reversal')->default(false);
            $table->ulid('reverses_payslip_id')->nullable();
            $table->string('payment_status', 10)->default('unpaid');
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->date('paid_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'run_id', 'employee_id'], 'payroll_payslips_run_employee_unique');
            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'period_id']);
            $table->index(['tenant_id', 'payment_status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('payroll_payslip_lines', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('payslip_id')->constrained('payroll_payslips')->restrictOnDelete();
            $table->string('kind', 14);
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('source', 24);
            $table->ulid('source_id')->nullable();
            $table->unsignedBigInteger('amount');
            $table->boolean('is_taxable')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('detail')->nullable();

            $table->index(['tenant_id', 'payslip_id']);
            $table->index(['tenant_id', 'kind', 'code']);
        });

        Schema::create('payroll_payments', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('payslip_id')->constrained('payroll_payslips')->restrictOnDelete();
            $table->string('status', 10);
            $table->string('method', 20)->nullable();
            $table->string('reference', 120)->nullable();
            $table->date('paid_on')->nullable();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('idempotency_key', 120);
            $table->text('note')->nullable();
            $table->ulid('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'idempotency_key'], 'payroll_payments_key_unique');
            $table->index(['tenant_id', 'payslip_id']);
        });

        Schema::create('payroll_items', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $table->ulid('employee_id');
            $placement($table);
            $table->foreignUlid('period_id')->constrained('payroll_periods')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('name', 160);
            $table->unsignedBigInteger('amount')->nullable();
            // Alternative to `amount`: a share of the full monthly basic, in basis points.
            $table->unsignedInteger('basic_bps')->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->string('status', 12);
            $table->ulid('payslip_id')->nullable();
            $table->text('note')->nullable();
            $table->ulid('created_by_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'period_id', 'status']);
            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('payroll_loans', function (Blueprint $table) use ($tenant, $placement): void {
            $tenant($table);
            $table->ulid('employee_id');
            $placement($table);
            $table->string('kind', 10);
            $table->string('title', 160);
            $table->unsignedBigInteger('principal_amount');
            $table->unsignedSmallInteger('installment_count');
            $table->date('first_due_on');
            $table->char('currency', 3);
            $table->string('status', 12);
            $table->text('reason')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignUlid('requested_by_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('approved_by_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('payroll_loan_installments', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('loan_id')->constrained('payroll_loans')->restrictOnDelete();
            $table->ulid('employee_id');
            $table->unsignedSmallInteger('sequence');
            $table->date('due_on');
            $table->unsignedBigInteger('amount');
            $table->string('status', 12);
            $table->ulid('payslip_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'loan_id', 'sequence'], 'payroll_installments_sequence_unique');
            $table->index(['tenant_id', 'employee_id', 'status', 'due_on'], 'payroll_installments_due_index');
        });

        Schema::create('payroll_rule_sets', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('name', 120);
            $table->string('code', 40);
            $table->char('country', 2)->nullable();
            $table->string('engine', 30)->default('slab');
            $table->string('category', 24);
            $table->string('payer', 10);
            $table->string('basis', 16);
            $table->string('method', 14);
            $table->boolean('annualize')->default(false);
            $table->unsignedSmallInteger('periods_per_year')->default(12);
            $table->unsignedBigInteger('min_amount')->nullable();
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->json('employment_types')->nullable();
            $table->json('parameters')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code', 'effective_from'], 'payroll_rule_sets_code_unique');
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('payroll_rule_slabs', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->foreignUlid('rule_set_id')->constrained('payroll_rule_sets')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedBigInteger('from_amount');
            $table->unsignedBigInteger('to_amount')->nullable();
            $table->unsignedInteger('rate_bps')->default(0);
            $table->unsignedBigInteger('fixed_amount')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'rule_set_id', 'position'], 'payroll_slabs_position_unique');
        });

        Schema::create('payroll_automation_action_receipts', function (Blueprint $table) use ($tenant): void {
            $tenant($table);
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();

            $table->unique(['tenant_id', 'action', 'idempotency_key'], 'payroll_receipts_action_key_unique');
        });
    }

    public function down(): void
    {
        foreach ([
            'payroll_automation_action_receipts', 'payroll_rule_slabs', 'payroll_rule_sets', 'payroll_loan_installments',
            'payroll_loans', 'payroll_items', 'payroll_payments', 'payroll_payslip_lines', 'payroll_payslips',
            'payroll_runs', 'payroll_periods', 'payroll_salary_assignments', 'payroll_profiles',
            'payroll_structure_components', 'payroll_structures', 'payroll_number_sequences', 'payroll_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
