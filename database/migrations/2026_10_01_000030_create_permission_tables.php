<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-permission schema in teams mode (team = tenant), adapted for
 * ULID keys. Differences from the package stub:
 *
 *  - `roles.id`, `model_id` and `tenant_id` are ULIDs;
 *  - `permissions` records which module registered each permission;
 *  - `roles` gains `description` and `is_system`;
 *  - `role_has_permissions` gains the access `scope` of each grant.
 *
 * `permissions` stays an auto-increment lookup table: it is global, written
 * only by `permissions:sync`, and never addressed by id from the outside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('guard_name', 40);
            $table->string('module', 60)->index();
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('guard_name', 40);
            $table->string('description')->nullable();
            // System roles are provisioned with the tenant and cannot be deleted.
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type', 100);
            $table->ulid('model_id');
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->primary(
                ['tenant_id', 'permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary',
            );
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->ulid('role_id');
            $table->string('model_type', 100);
            $table->ulid('model_id');
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(
                ['tenant_id', 'role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary',
            );
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->ulid('role_id');
            // How far the grant reaches: own | department | branch | all.
            $table->string('scope', 16)->default('all');

            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
