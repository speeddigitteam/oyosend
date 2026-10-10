<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 30)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            // Several branches may have no code: MySQL does not compare NULLs in a unique index.
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 30)->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);
        });

        // Where a person sits in the organisation. It is what the `branch`
        // and `department` permission scopes are evaluated against.
        Schema::table('tenant_memberships', function (Blueprint $table) {
            $table->foreignUlid('branch_id')->nullable()->after('is_owner')->constrained('branches')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->after('branch_id')->constrained('departments')->nullOnDelete();

            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_memberships', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'branch_id']);
            $table->dropIndex(['tenant_id', 'department_id']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('departments');
        Schema::dropIfExists('branches');
    }
};
