<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page-level insights (one row per account per day), the per-account diagnostics the collector
 * keeps (granted permissions, Meta tasks, last sync) and the per-account allow-list of who may
 * use an account. Additive and guarded: safe to run where an earlier build already created them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_page_insights')) {
            Schema::create('social_page_insights', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('account_id')->constrained('social_accounts')->cascadeOnDelete();
                // The platform's own day (Meta reports Page insights in Pacific Time days), `Y-m-d`.
                $table->date('metric_date');
                // Every figure is null when the platform did not return it; null is never shown as zero.
                $table->unsignedBigInteger('followers')->nullable();
                $table->unsignedBigInteger('fans')->nullable();
                $table->unsignedBigInteger('new_followers')->nullable();
                $table->unsignedBigInteger('unfollows')->nullable();
                $table->unsignedBigInteger('impressions')->nullable();
                $table->unsignedBigInteger('reach')->nullable();
                $table->unsignedBigInteger('engaged_users')->nullable();
                $table->unsignedBigInteger('post_engagements')->nullable();
                $table->unsignedBigInteger('page_views')->nullable();
                $table->unsignedBigInteger('reactions')->nullable();
                $table->unsignedBigInteger('video_views')->nullable();
                // Which API metric supplied each figure (figure => metric name), for honesty and debugging.
                $table->json('sources')->nullable();
                $table->timestamp('captured_at');
                $table->timestamps();

                $table->unique(['tenant_id', 'account_id', 'metric_date'], 'social_page_insights_unique');
                $table->index(['tenant_id', 'metric_date']);
            });
        }

        if (! Schema::hasTable('social_account_diagnostics')) {
            Schema::create('social_account_diagnostics', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('account_id')->constrained('social_accounts')->cascadeOnDelete();
                // Permissions the platform says the token holds (names only), and the Meta tasks of its holder.
                $table->json('granted_scopes')->nullable();
                $table->json('tasks')->nullable();
                $table->string('tasks_status', 32)->nullable();
                $table->timestamp('tasks_checked_at')->nullable();
                $table->string('insights_status', 32)->nullable();
                $table->string('insights_error_code', 64)->nullable();
                $table->timestamp('insights_synced_at')->nullable();
                $table->timestamp('insights_backfilled_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'account_id'], 'social_account_diagnostics_unique');
            });
        }

        if (! Schema::hasTable('social_account_access')) {
            Schema::create('social_account_access', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('account_id')->constrained('social_accounts')->cascadeOnDelete();
                // `membership` (a tenant_memberships id) or `role` (a roles id).
                $table->string('subject_type', 16);
                $table->ulid('subject_id');
                $table->boolean('can_publish')->default(false);
                $table->boolean('can_approve')->default(false);
                $table->boolean('can_analytics')->default(false);
                $table->timestamps();

                $table->unique(['tenant_id', 'account_id', 'subject_type', 'subject_id'], 'social_account_access_unique');
                $table->index(['tenant_id', 'subject_type', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('social_account_access');
        Schema::dropIfExists('social_account_diagnostics');
        Schema::dropIfExists('social_page_insights');
    }
};
