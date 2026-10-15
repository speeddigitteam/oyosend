<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('title', 150);
            $table->string('address', 2048);
            $table->string('description', 500)->nullable();
            $table->string('folder', 60)->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'is_pinned', 'title']);
            $table->index(['tenant_id', 'kind']);
            $table->index(['tenant_id', 'folder']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
