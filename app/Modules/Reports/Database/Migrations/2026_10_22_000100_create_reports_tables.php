<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports_saved_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('owner_membership_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->string('name', 120);
            // `{module}.{report}` of a registered report provider. Validated on every run.
            $table->string('provider_key', 80);
            // Chosen column keys; null = the report's default columns.
            $table->json('columns')->nullable();
            // Declared filter values only: {"currency": "BDT", ...}.
            $table->json('filters')->nullable();
            $table->string('range_mode', 12)->default('last_days');
            $table->unsignedSmallInteger('range_days')->default(30);
            $table->date('range_from')->nullable();
            $table->date('range_to')->nullable();
            $table->string('visibility', 12)->default('private');
            // A deleted role or department makes the report private again (nullOnDelete).
            $table->foreignUlid('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'owner_membership_id', 'name'], 'reports_saved_owner_name_unique');
            $table->index(['tenant_id', 'provider_key']);
            $table->index(['tenant_id', 'visibility']);
            $table->index(['tenant_id', 'role_id']);
            $table->index(['tenant_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports_saved_reports');
    }
};
