<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('sku', 80)->nullable();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('unit', 40)->default('unit');
            $table->char('currency', 3);
            $table->unsignedBigInteger('unit_price_amount')->default(0);
            $table->unsignedSmallInteger('tax_bps')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'is_active', 'name']);
            $table->index(['tenant_id', 'type', 'name']);
        });

        Schema::create('crm_price_lists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->char('currency', 3);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active', 'starts_on', 'ends_on']);
        });

        Schema::create('crm_price_list_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('price_list_id')->constrained('crm_price_lists')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('crm_products')->cascadeOnDelete();
            $table->unsignedInteger('minimum_quantity_milli')->default(1000);
            $table->unsignedBigInteger('unit_price_amount');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'price_list_id', 'product_id', 'minimum_quantity_milli'],
                'crm_price_items_tenant_list_product_qty_unique',
            );
            $table->index(['tenant_id', 'product_id', 'price_list_id'], 'crm_price_items_tenant_product_list_index');
        });

        Schema::create('crm_quotes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('deal_id')->nullable()->constrained('crm_deals')->nullOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUlid('price_list_id')->nullable()->constrained('crm_price_lists')->nullOnDelete();
            $table->string('number', 40);
            $table->string('kind', 16)->default('quotation');
            $table->string('title', 200);
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->unsignedTinyInteger('probability_percent')->default(50);
            $table->unsignedBigInteger('subtotal_amount')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->date('valid_until')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $this->ownership($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'valid_until']);
            $table->index(['tenant_id', 'contact_id', 'created_at']);
            $table->index(['tenant_id', 'company_id', 'created_at']);
            $this->ownershipIndexes($table, 'crm_quotes');
        });

        Schema::create('crm_quote_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('quote_id')->constrained('crm_quotes')->cascadeOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained('crm_products')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('item_type', 16);
            $table->string('sku', 80)->nullable();
            $table->string('name', 180);
            $table->string('unit', 40);
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity_milli');
            $table->char('currency', 3);
            $table->unsignedBigInteger('unit_price_amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedSmallInteger('tax_bps')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('line_total_amount');
            $table->timestamps();

            $table->unique(['tenant_id', 'quote_id', 'position']);
            $table->index(['tenant_id', 'product_id']);
        });

        Schema::create('crm_sales_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('quote_id')->nullable()->constrained('crm_quotes')->nullOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('number', 40);
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_amount');
            $table->unsignedBigInteger('discount_amount');
            $table->unsignedBigInteger('tax_amount');
            $table->unsignedBigInteger('total_amount');
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('ordered_at');
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $this->ownership($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'quote_id']);
            $table->index(['tenant_id', 'status', 'ordered_at']);
            $table->index(['tenant_id', 'contact_id', 'ordered_at']);
            $table->index(['tenant_id', 'company_id', 'ordered_at']);
            $this->ownershipIndexes($table, 'crm_orders');
        });

        Schema::create('crm_sales_order_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('sales_order_id')->constrained('crm_sales_orders')->cascadeOnDelete();
            $table->foreignUlid('quote_line_id')->nullable()->constrained('crm_quote_lines')->nullOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained('crm_products')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('item_type', 16);
            $table->string('sku', 80)->nullable();
            $table->string('name', 180);
            $table->string('unit', 40);
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity_milli');
            $table->char('currency', 3);
            $table->unsignedBigInteger('unit_price_amount');
            $table->unsignedBigInteger('discount_amount');
            $table->unsignedSmallInteger('tax_bps');
            $table->unsignedBigInteger('tax_amount');
            $table->unsignedBigInteger('line_total_amount');
            $table->timestamps();

            $table->unique(['tenant_id', 'sales_order_id', 'position'], 'crm_order_lines_tenant_order_position_unique');
            $table->index(['tenant_id', 'product_id']);
        });

        Schema::create('crm_contracts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('sales_order_id')->nullable()->constrained('crm_sales_orders')->nullOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('number', 40);
            $table->string('title', 200);
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->unsignedBigInteger('value_amount')->default(0);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('renews_on')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->text('terms')->nullable();
            $this->ownership($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'ends_on']);
            $table->index(['tenant_id', 'contact_id', 'status']);
            $table->index(['tenant_id', 'company_id', 'status']);
            $this->ownershipIndexes($table, 'crm_contracts');
        });

        Schema::create('crm_sales_targets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->char('currency', 3);
            $table->unsignedBigInteger('target_amount');
            $table->date('period_start');
            $table->date('period_end');
            $this->ownership($table);
            $table->timestamps();

            $table->index(['tenant_id', 'period_start', 'period_end']);
            $this->ownershipIndexes($table, 'crm_targets');
        });

        Schema::create('crm_customer_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->cascadeOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('stage', 20);
            // Currency => integer minor-unit total. Keeping currencies separate
            // prevents a mathematically invalid cross-currency lifetime value.
            $table->json('lifetime_values')->nullable();
            $table->timestamp('first_won_at')->nullable();
            $table->timestamp('last_won_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $this->ownership($table);
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id']);
            $table->unique(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'stage', 'last_activity_at']);
            $this->ownershipIndexes($table, 'crm_customers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_customer_profiles');
        Schema::dropIfExists('crm_sales_targets');
        Schema::dropIfExists('crm_contracts');
        Schema::dropIfExists('crm_sales_order_lines');
        Schema::dropIfExists('crm_sales_orders');
        Schema::dropIfExists('crm_quote_lines');
        Schema::dropIfExists('crm_quotes');
        Schema::dropIfExists('crm_price_list_items');
        Schema::dropIfExists('crm_price_lists');
        Schema::dropIfExists('crm_products');
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
