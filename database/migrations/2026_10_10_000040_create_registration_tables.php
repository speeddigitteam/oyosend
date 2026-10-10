<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The company behind a workspace, and whether its owner has been
        // through the first-run setup.
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('email')->nullable()->after('currency');
            $table->string('phone', 40)->nullable()->after('email');
            $table->string('website')->nullable()->after('phone');
            $table->string('address', 500)->nullable()->after('website');
            $table->string('city', 120)->nullable()->after('address');
            $table->string('postal_code', 20)->nullable()->after('city');
            $table->char('country', 2)->nullable()->after('postal_code');
            $table->string('tax_id', 60)->nullable()->after('country');
            // Tenant-relative path of the logo on the tenant disk (TenantStorage).
            $table->string('logo_path')->nullable()->after('tax_id');
            $table->timestamp('onboarded_at')->nullable()->after('suspended_at');
        });

        // Workspaces that already exist were set up by hand.
        DB::table('tenants')->whereNull('onboarded_at')->update(['onboarded_at' => DB::raw('created_at')]);

        // A sign-up waiting for its email address to be confirmed. Nothing
        // else exists yet: the account and the workspace are created when the
        // emailed link is opened.
        Schema::create('workspace_registrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email');
            $table->string('name', 120);
            // Hashed. Only used when the address has no account yet.
            $table->string('password');
            $table->string('company');
            $table->string('slug', 63);
            $table->string('locale', 8);
            $table->foreignUlid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('interval', 10)->nullable();
            // SHA-256 of the emailed token. The token itself is never stored.
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // An address stays reserved while its sign-up can still be completed.
            $table->index(['slug', 'completed_at', 'expires_at']);
            $table->index(['email', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_registrations');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name', 'email', 'phone', 'website', 'address', 'city',
                'postal_code', 'country', 'tax_id', 'logo_path', 'onboarded_at',
            ]);
        });
    }
};
