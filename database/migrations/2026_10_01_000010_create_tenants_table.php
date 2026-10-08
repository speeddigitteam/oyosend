<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            // Also the tenant's subdomain label, hence the DNS label limit.
            $table->string('slug', 63)->unique();
            $table->string('status', 20)->default('active')->index();
            $table->string('locale', 8)->default('en');
            $table->string('timezone', 64)->default('Asia/Dhaka');
            $table->char('currency', 3)->default('BDT');
            // null = shared database. Set only when a tenant is moved to a dedicated one.
            $table->string('db_connection', 64)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            // Free-form attributes that do not deserve a column yet.
            $table->json('data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            // The subdomain label for `subdomain` rows, a full hostname for `custom` rows.
            $table->string('domain')->unique();
            $table->string('type', 20)->default('subdomain');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('tenants');
    }
};
