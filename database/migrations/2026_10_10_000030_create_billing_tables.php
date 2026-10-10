<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Platform billing: what Oyosend charges its tenants. Central data, keyed by
 * `tenant_id`; it stays in the central database even for a tenant that is
 * later moved to a dedicated one.
 *
 * Money is an integer number of minor units (paisa, cents) in a column named
 * `amount` or `*_amount`, next to a 3-letter `currency` on the same row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->char('currency', 3);
            // null = the plan is not sold for that interval. 0 = free.
            $table->unsignedBigInteger('monthly_amount')->nullable();
            $table->unsignedBigInteger('yearly_amount')->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            // Keys of the optional modules the plan includes.
            $table->json('modules');
            // Limit key => maximum. A missing key means unlimited.
            $table->json('limits');
            // Public plans are offered at registration and on the billing page;
            // the others can only be assigned from the Super Admin panel.
            $table->boolean('is_public')->default(true);
            // Archived plans keep their subscribers but accept no new ones.
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'is_public', 'sort_order']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // One subscription per tenant; its history is in invoices and the audit trail.
            $table->foreignUlid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status', 20);
            $table->string('interval', 10);
            $table->char('currency', 3);
            // Price per interval, fixed when the plan was taken.
            $table->unsignedBigInteger('amount');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            // null = no end: a free plan.
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            // A downgrade waits here until the paid period is over.
            $table->foreignUlid('pending_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('pending_interval', 10)->nullable();
            $table->timestamps();

            $table->index(['status', 'current_period_end']);
            $table->index(['status', 'trial_ends_at']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            // Platform-wide document number, hence unique without tenant_id.
            $table->string('number', 30)->unique();
            $table->string('kind', 20);
            $table->string('status', 20);
            // What paying this invoice buys: a plan, for a period.
            $table->foreignUlid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('interval', 10)->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_amount');
            $table->unsignedBigInteger('credit_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            // The lines and the billed party as they were when the invoice was issued.
            $table->json('lines');
            $table->json('billed_to')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'issued_at']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('gateway', 40);
            $table->string('method', 40)->nullable();
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount');
            // Our own transaction id, the one handed to the gateway.
            $table->string('reference', 40)->unique();
            $table->string('gateway_reference', 120)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            // What the gateway's verification answered, or the note of an offline payment.
            $table->json('details')->nullable();
            $table->foreignUlid('recorded_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            // A provider transaction is recorded once, however often its notification arrives.
            $table->unique(['gateway', 'gateway_reference']);
            $table->index(['tenant_id', 'status']);
            $table->index(['invoice_id', 'status']);
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('meter', 60);
            $table->unsignedBigInteger('quantity');
            $table->string('idempotency_key', 120);
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            // The same event is counted once, however often it is reported.
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'meter', 'occurred_at']);
        });

        // Gap-free counters for document numbers (invoices).
        Schema::create('platform_sequences', function (Blueprint $table) {
            $table->string('name', 60)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_sequences');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
