<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->boolean('require_approval')->default(false);
            $table->unsignedSmallInteger('account_hourly_limit')->default(10);
            $table->timestamps();

            $table->unique(['tenant_id']);
        });

        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('external_id', 191);
            $table->string('name', 191);
            $table->string('handle', 191)->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->string('account_type', 32)->default('page');
            $table->string('status', 24);
            $table->string('status_reason', 500)->nullable();
            // Encrypted by the model cast; never read back to a browser or written to a log.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->foreignUlid('connected_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform', 'external_id'], 'social_accounts_tenant_platform_external_unique');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('social_campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 16);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('social_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('social_hashtag_sets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('tags');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('social_media', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 190);
            // Tenant-relative path on the private TenantStorage disk; never a URL.
            $table->string('path', 255);
            $table->string('mime', 100);
            $table->string('kind', 10);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('sha256', 64);
            $table->string('alt_text', 500)->nullable();
            $table->foreignUlid('uploaded_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'sha256']);
            $table->index(['tenant_id', 'kind']);
        });

        Schema::create('social_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('file_name', 190);
            $table->char('file_sha256', 64);
            $table->string('mode', 16);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('errors')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'file_sha256']);
        });

        Schema::create('social_posts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title', 160)->nullable();
            $table->text('body')->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->json('hashtags')->nullable();
            $table->string('status', 24);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignUlid('campaign_id')->nullable()->constrained('social_campaigns')->nullOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('social_categories')->nullOnDelete();
            $table->foreignUlid('hashtag_set_id')->nullable()->constrained('social_hashtag_sets')->nullOnDelete();
            $table->foreignUlid('import_id')->nullable()->constrained('social_imports')->nullOnDelete();
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignUlid('submitted_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUlid('approved_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('rejected_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'scheduled_for']);
            $table->index(['tenant_id', 'scheduled_for']);
            $table->index(['tenant_id', 'campaign_id']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('social_post_media', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignUlid('media_id')->constrained('social_media')->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'post_id', 'media_id']);
            $table->index(['tenant_id', 'media_id']);
        });

        Schema::create('social_post_targets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained('social_accounts')->restrictOnDelete();
            $table->text('body_override')->nullable();
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('external_id', 191)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->boolean('needs_manual_check')->default(false);
            $table->char('idempotency_key', 40);
            $table->timestamp('metrics_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'post_id', 'account_id'], 'social_targets_tenant_post_account_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'social_targets_tenant_idempotency_unique');
            $table->index(['tenant_id', 'status', 'next_attempt_at'], 'social_targets_due_index');
            $table->index(['tenant_id', 'account_id']);
        });

        Schema::create('social_publish_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('target_id')->constrained('social_post_targets')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('outcome', 16);
            $table->string('error_code', 64)->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'target_id']);
        });

        Schema::create('social_comments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignUlid('author_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['tenant_id', 'post_id', 'created_at']);
        });

        Schema::create('social_post_metrics', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('target_id')->constrained('social_post_targets')->cascadeOnDelete();
            $table->timestamp('captured_at');
            // Each column is null when the platform did not return that figure; null is never shown as zero.
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'target_id', 'captured_at']);
        });

        Schema::create('social_automation_action_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();

            $table->unique(['tenant_id', 'action', 'idempotency_key'], 'social_receipts_tenant_action_key_unique');
        });
    }

    public function down(): void
    {
        foreach ([
            'social_automation_action_receipts', 'social_post_metrics', 'social_comments', 'social_publish_attempts',
            'social_post_targets', 'social_post_media', 'social_posts', 'social_imports', 'social_media',
            'social_hashtag_sets', 'social_categories', 'social_campaigns', 'social_accounts', 'social_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
