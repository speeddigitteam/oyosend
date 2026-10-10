<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('module_key', 60)->unique();
            $table->string('name', 100);
            $table->text('description');
            $table->string('category', 40);
            $table->string('icon', 60)->default('package');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_published')->default(true);
            $table->foreignUlid('current_version_id')->nullable();
            $table->timestamps();

            $table->index(['is_published', 'category', 'name']);
            $table->index(['is_published', 'is_featured']);
        });

        Schema::create('app_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('version', 40);
            $table->string('compatibility', 80);
            $table->json('features');
            $table->json('screenshots');
            $table->json('offers');
            $table->json('dependencies');
            $table->json('permissions');
            $table->json('routes');
            $table->json('scheduled_jobs');
            $table->json('settings');
            $table->text('release_notes')->nullable();
            $table->char('manifest_hash', 64);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'version']);
        });

        Schema::table('apps', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('app_versions')->nullOnDelete();
        });

        Schema::create('app_installations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('app_id')->constrained('apps')->restrictOnDelete();
            $table->foreignUlid('app_version_id')->nullable()->constrained('app_versions')->nullOnDelete();
            $table->string('status', 24);
            $table->string('deactivation_reason', 24)->nullable();
            // Cast as `encrypted:array`; LONGTEXT leaves room for the encrypted envelope.
            $table->longText('settings')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'app_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('app_id')->constrained('apps')->restrictOnDelete();
            $table->foreignUlid('installation_id')->constrained('app_installations')->cascadeOnDelete();
            $table->string('offer_key', 60);
            $table->string('billing_type', 24);
            $table->string('status', 20);
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'app_id', 'status']);
            $table->index(['status', 'current_period_end']);
            $table->index(['status', 'trial_ends_at']);
        });

        Schema::create('app_entitlements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('app_id')->constrained('apps')->restrictOnDelete();
            $table->foreignUlid('installation_id')->constrained('app_installations')->cascadeOnDelete();
            $table->foreignUlid('subscription_item_id')->nullable()->constrained('subscription_items')->nullOnDelete();
            $table->string('status', 20);
            $table->string('source', 20);
            $table->ulid('license_id')->unique();
            $table->timestamp('valid_from');
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'app_id', 'status']);
            $table->index(['tenant_id', 'status', 'valid_until']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUlid('app_id')->nullable()->after('plan_id')->constrained('apps')->nullOnDelete();
            $table->foreignUlid('subscription_item_id')->nullable()->after('subscription_id')->constrained('subscription_items')->nullOnDelete();
            $table->index(['tenant_id', 'app_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'app_id', 'status']);
            $table->dropConstrainedForeignId('subscription_item_id');
            $table->dropConstrainedForeignId('app_id');
        });

        Schema::dropIfExists('app_entitlements');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('app_installations');

        Schema::table('apps', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('app_versions');
        Schema::dropIfExists('apps');
    }
};
