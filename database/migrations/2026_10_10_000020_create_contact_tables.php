<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The one shared customer identity of a workspace: organisations and people.
 * CRM, marketing, support and projects attach their own data to these rows;
 * no module creates a customer table of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website')->nullable();
            $table->string('industry', 120)->nullable();
            $table->string('tax_id', 60)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 120)->nullable();
            $table->char('country', 2)->nullable();
            $table->text('notes')->nullable();
            $this->ownership($table);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            // "One contact per address" cannot be a unique index because
            // deleted contacts are kept; validation enforces it among live rows.
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 120)->nullable();
            $table->char('country', 2)->nullable();
            $table->text('notes')->nullable();
            $this->ownership($table);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'first_name', 'last_name']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('companies');
    }

    /**
     * Who a record belongs to. `own`, `department` and `branch` permission
     * scopes are evaluated against these three columns; department and branch
     * are copied from the owner when the owner is set.
     */
    private function ownership(Blueprint $table): void
    {
        $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
        $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
        $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
    }
};
