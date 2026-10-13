<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta Ads: the workspace's authorisation (token encrypted), the ad accounts it tracks, the synced
 * campaigns / ad sets / ads, daily insights, change receipts and settings.
 *
 * Money is an unsigned BIGINT of minor units next to a `currency`; a null figure means Meta did not
 * report it and is never shown as zero. Every unique index starts with `tenant_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // The Facebook user who authorised; the token below acts for that person.
            $table->string('external_user_id', 64);
            $table->string('user_name', 255)->nullable();
            // Encrypted by the model's `encrypted` cast; null once discarded.
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('token_renewed_at')->nullable();
            $table->json('granted_scopes')->nullable();
            $table->json('declined_scopes')->nullable();
            $table->string('status', 32)->default('connected');
            $table->string('status_reason', 500)->nullable();
            $table->ulid('connected_by')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'external_user_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('meta_ads_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('connection_id')->constrained('meta_ads_connections')->cascadeOnDelete();
            // `act_<number>`.
            $table->string('external_id', 40);
            $table->string('name', 255);
            $table->string('business_name', 255)->nullable();
            $table->char('currency', 3);
            $table->string('timezone_name', 64)->nullable();
            // Meta's own account_status / disable_reason codes, kept for honest display.
            $table->unsignedSmallInteger('meta_status')->nullable();
            $table->unsignedSmallInteger('meta_disable_reason')->nullable();
            // available (listed, not used) | tracked (synced) | removed (no longer synced; history kept).
            $table->string('status', 24)->default('available');
            // Workspace-set ceilings in minor units of `currency`; null means budgets cannot be edited.
            $table->unsignedBigInteger('daily_budget_ceiling')->nullable();
            $table->unsignedBigInteger('lifetime_budget_ceiling')->nullable();
            $table->timestamp('structure_synced_at')->nullable();
            $table->date('insights_through')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_state', 24)->nullable();
            $table->string('sync_error_code', 64)->nullable();
            $table->string('sync_error', 300)->nullable();
            $table->timestamp('throttled_until')->nullable();
            $table->unsignedTinyInteger('usage_percent')->nullable();
            $table->timestamp('last_manual_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'external_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'connection_id']);
        });

        Schema::create('meta_ads_objects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained('meta_ads_accounts')->cascadeOnDelete();
            // campaign | adset | ad
            $table->string('level', 8);
            $table->string('external_id', 40);
            $table->string('parent_external_id', 40)->nullable();
            $table->string('campaign_external_id', 40)->nullable();
            $table->string('name', 255);
            $table->string('status', 24)->nullable();
            $table->string('effective_status', 40)->nullable();
            $table->string('objective', 64)->nullable();
            $table->unsignedBigInteger('daily_budget')->nullable();
            $table->unsignedBigInteger('lifetime_budget')->nullable();
            $table->unsignedBigInteger('budget_remaining')->nullable();
            $table->char('currency', 3);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamp('meta_updated_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('missing_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'account_id', 'level', 'external_id'], 'meta_ads_objects_unique');
            $table->index(['tenant_id', 'account_id', 'level', 'status'], 'meta_ads_objects_list');
            $table->index(['tenant_id', 'level', 'campaign_external_id'], 'meta_ads_objects_campaign');
        });

        Schema::create('meta_ads_insights', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained('meta_ads_accounts')->cascadeOnDelete();
            // account | campaign
            $table->string('level', 8);
            $table->string('object_external_id', 40);
            // The ad account's own calendar day.
            $table->date('metric_date');
            $table->char('currency', 3);
            // Every figure is null when Meta did not return it; null is never shown as zero.
            $table->unsignedBigInteger('spend')->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedBigInteger('leads')->nullable();
            // action_type => integer count, as returned (capped).
            $table->json('actions')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'account_id', 'level', 'object_external_id', 'metric_date'], 'meta_ads_insights_unique');
            $table->index(['tenant_id', 'level', 'metric_date'], 'meta_ads_insights_range');
        });

        Schema::create('meta_ads_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained('meta_ads_accounts')->cascadeOnDelete();
            $table->foreignUlid('object_id')->constrained('meta_ads_objects')->cascadeOnDelete();
            $table->ulid('requested_by')->nullable();
            // One per confirmation dialog; replaying it returns the stored outcome and never calls Meta again.
            $table->string('request_id', 64);
            // pause | resume | budget
            $table->string('action', 16);
            // status | daily_budget | lifetime_budget
            $table->string('field', 24);
            $table->string('level', 8);
            $table->string('object_name', 255);
            $table->string('before_value', 40)->nullable();
            $table->string('after_value', 40)->nullable();
            $table->char('currency', 3)->nullable();
            // pending | applied | failed
            $table->string('status', 16)->default('pending');
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 300)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'request_id']);
            $table->index(['tenant_id', 'account_id', 'requested_at'], 'meta_ads_changes_account');
            $table->index(['tenant_id', 'requested_at']);
        });

        Schema::create('meta_ads_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->boolean('management_enabled')->default(false);
            $table->unsignedSmallInteger('lookback_days')->default(30);
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_settings');
        Schema::dropIfExists('meta_ads_changes');
        Schema::dropIfExists('meta_ads_insights');
        Schema::dropIfExists('meta_ads_objects');
        Schema::dropIfExists('meta_ads_accounts');
        Schema::dropIfExists('meta_ads_connections');
    }
};
