<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoicing_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('invoice_prefix', 20)->default('INV-');
            $table->string('credit_note_prefix', 20)->default('CN-');
            $table->unsignedTinyInteger('number_padding')->default(4);
            $table->boolean('fiscal_year_numbering')->default(false);
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->unsignedSmallInteger('default_due_days')->default(14);
            $table->text('default_terms')->nullable();
            $table->text('default_notes')->nullable();
            $table->boolean('reminders_enabled')->default(false);
            $table->unsignedTinyInteger('reminder_before_days')->default(3);
            $table->unsignedSmallInteger('reminder_overdue_every_days')->default(7);
            $table->boolean('invoice_accepted_quotes')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id']);
        });

        Schema::create('invoicing_tax_rates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('rate_bps');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('invoicing_number_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 20);
            // 0 for a continuous sequence, otherwise the year the fiscal year starts.
            $table->unsignedSmallInteger('period')->default(0);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'period'], 'invoicing_sequences_tenant_kind_period_unique');
        });

        Schema::create('invoicing_recurring_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('name', 160);
            $table->string('status', 16);
            $table->string('frequency', 16);
            $table->unsignedTinyInteger('every')->default(1);
            $table->char('currency', 3);
            $table->date('starts_on');
            $table->date('next_run_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('due_days')->default(14);
            $table->boolean('auto_issue')->default(false);
            $table->boolean('auto_send')->default(false);
            $table->string('title', 200)->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->json('lines');
            $table->date('last_run_on')->nullable();
            $table->unsignedInteger('runs_count')->default(0);
            $table->string('last_error', 500)->nullable();
            $this->ownership($table);
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'next_run_on'], 'invoicing_recurring_tenant_due_index');
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'company_id']);
            $this->ownershipIndexes($table, 'invoicing_recurring');
        });

        Schema::create('invoicing_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUlid('recurring_template_id')->nullable()->constrained('invoicing_recurring_templates')->nullOnDelete();
            $table->string('number', 60)->nullable();
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->string('title', 200)->nullable();
            $table->string('reference', 120)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->unsignedBigInteger('subtotal_amount')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedBigInteger('credited_amount')->default(0);
            $table->unsignedBigInteger('balance_amount')->default(0);
            $table->string('customer_name', 200)->nullable();
            $table->string('customer_contact_name', 200)->nullable();
            $table->string('customer_email', 190)->nullable();
            $table->string('customer_phone', 60)->nullable();
            $table->text('customer_address')->nullable();
            $table->string('customer_tax_id', 80)->nullable();
            $table->json('seller_snapshot')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->string('source_type', 30)->nullable();
            $table->string('source_id', 60)->nullable();
            $table->string('source_key', 160)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('overdue_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $this->ownership($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'source_key']);
            $table->index(['tenant_id', 'status', 'due_on']);
            $table->index(['tenant_id', 'contact_id', 'created_at']);
            $table->index(['tenant_id', 'company_id', 'created_at']);
            $table->index(['tenant_id', 'issued_on']);
            $table->index(['tenant_id', 'recurring_template_id']);
            $this->ownershipIndexes($table, 'invoicing_invoices');
        });

        Schema::create('invoicing_invoice_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained('invoicing_invoices')->cascadeOnDelete();
            $table->foreignUlid('tax_rate_id')->nullable()->constrained('invoicing_tax_rates')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('unit', 40)->default('unit');
            $table->unsignedInteger('quantity_milli');
            $table->unsignedBigInteger('unit_price_amount');
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedSmallInteger('discount_bps')->nullable();
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('net_amount');
            $table->string('tax_name', 80)->nullable();
            $table->unsignedSmallInteger('tax_bps')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('line_total_amount');
            $table->timestamps();

            $table->unique(['tenant_id', 'invoice_id', 'position']);
        });

        Schema::create('invoicing_payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained('invoicing_invoices')->cascadeOnDelete();
            $table->foreignUlid('recorded_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('reversed_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('method', 24);
            $table->string('reference', 120)->nullable();
            $table->date('paid_on');
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'paid_on']);
        });

        Schema::create('invoicing_credit_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained('invoicing_invoices')->cascadeOnDelete();
            $table->foreignUlid('issued_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->string('number', 60);
            $table->char('currency', 3);
            $table->date('issued_on');
            $table->string('reason', 500);
            $table->unsignedBigInteger('subtotal_amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'issued_on']);
        });

        Schema::create('invoicing_credit_note_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('credit_note_id')->constrained('invoicing_credit_notes')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('unit', 40)->default('unit');
            $table->unsignedInteger('quantity_milli');
            $table->unsignedBigInteger('unit_price_amount');
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedSmallInteger('discount_bps')->nullable();
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('net_amount');
            $table->string('tax_name', 80)->nullable();
            $table->unsignedSmallInteger('tax_bps')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('line_total_amount');
            $table->timestamps();

            $table->unique(['tenant_id', 'credit_note_id', 'position'], 'invoicing_cn_lines_tenant_note_position_unique');
        });

        Schema::create('invoicing_billable_time', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->nullable()->constrained('invoicing_invoices')->nullOnDelete();
            // Plain identifiers from the Projects event contract: never foreign keys into another module.
            $table->string('project_id', 26);
            $table->string('time_entry_id', 26);
            $table->string('membership_id', 26)->nullable();
            $table->unsignedInteger('minutes');
            $table->unsignedBigInteger('rate_amount');
            $table->char('currency', 3);
            $table->timestamp('invoiced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'time_entry_id']);
            $table->index(['tenant_id', 'project_id', 'invoice_id'], 'invoicing_billable_tenant_project_invoice_index');
            $table->index(['tenant_id', 'invoice_id']);
        });

        Schema::create('invoicing_reminder_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained('invoicing_invoices')->cascadeOnDelete();
            $table->string('reminder_key', 24);
            $table->string('sent_to', 190);
            $table->date('sent_on');
            $table->timestamps();

            $table->unique(['tenant_id', 'invoice_id', 'reminder_key'], 'invoicing_reminders_tenant_invoice_key_unique');
        });

        Schema::create('invoicing_automation_action_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'action', 'idempotency_key'],
                'invoicing_action_receipts_tenant_action_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoicing_automation_action_receipts');
        Schema::dropIfExists('invoicing_reminder_logs');
        Schema::dropIfExists('invoicing_billable_time');
        Schema::dropIfExists('invoicing_credit_note_lines');
        Schema::dropIfExists('invoicing_credit_notes');
        Schema::dropIfExists('invoicing_payments');
        Schema::dropIfExists('invoicing_invoice_lines');
        Schema::dropIfExists('invoicing_invoices');
        Schema::dropIfExists('invoicing_recurring_templates');
        Schema::dropIfExists('invoicing_number_sequences');
        Schema::dropIfExists('invoicing_tax_rates');
        Schema::dropIfExists('invoicing_settings');
    }

    private function ownership(Blueprint $table): void
    {
        $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
        $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
        $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
    }

    private function ownershipIndexes(Blueprint $table, string $prefix): void
    {
        $table->index(['tenant_id', 'owner_id'], $prefix.'_tenant_owner_index');
        $table->index(['tenant_id', 'department_id'], $prefix.'_tenant_department_index');
        $table->index(['tenant_id', 'branch_id'], $prefix.'_tenant_branch_index');
    }
};
