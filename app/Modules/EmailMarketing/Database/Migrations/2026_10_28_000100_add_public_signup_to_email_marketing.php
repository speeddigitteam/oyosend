<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_marketing_lists', function (Blueprint $table): void {
            // The public sign-up form of a list: off by default, framing off by default.
            $table->boolean('signup_enabled')->default(false)->after('status');
            $table->boolean('signup_embed')->default(false)->after('signup_enabled');
            // Random, unguessable path segment of the form. Not a credential: anyone with the link may sign up.
            $table->string('signup_token', 32)->nullable()->after('signup_embed');

            $table->unique(['tenant_id', 'signup_token'], 'em_lists_tenant_id_signup_token_unique');
        });

        // A sign-up that waits for its confirmation link. Nothing else is created before the person confirms:
        // no contact, no membership. The row is deleted when the link is used and pruned when it expires.
        Schema::create('email_marketing_signup_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('list_id')->constrained('email_marketing_lists')->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            // SHA-256 of the 256-bit token that exists only in the confirmation email.
            $table->char('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'token_hash'], 'em_signup_requests_tenant_id_token_hash_unique');
            $table->index(['tenant_id', 'list_id', 'email', 'created_at'], 'em_signup_requests_tenant_id_list_id_email_created_at_index');
            $table->index(['tenant_id', 'expires_at'], 'em_signup_requests_tenant_id_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_marketing_signup_requests');

        Schema::table('email_marketing_lists', function (Blueprint $table): void {
            $table->dropUnique('em_lists_tenant_id_signup_token_unique');
            $table->dropColumn(['signup_enabled', 'signup_embed', 'signup_token']);
        });
    }
};
