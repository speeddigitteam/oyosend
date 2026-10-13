<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facebook Lead Ads: the per-Page setup, the platform-wide Page claim registry, the Page's lead forms with
 * their answer mapping, every received lead with its processing state, and a small processing log.
 * Additive and guarded: safe to run where an earlier build already created a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_lead_ad_pages')) {
            Schema::create('social_lead_ad_pages', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('account_id')->constrained('social_accounts')->cascadeOnDelete();
                // The Facebook Page id (the account's external id), kept here because webhooks name it.
                $table->string('page_id', 64);
                // off | subscribed | failed
                $table->string('state', 24)->default('off');
                $table->json('granted_scopes')->nullable();
                $table->json('missing_scopes')->nullable();
                $table->timestamp('permissions_checked_at')->nullable();
                $table->timestamp('subscribed_at')->nullable();
                $table->string('last_error_code', 64)->nullable();
                $table->string('last_error', 500)->nullable();
                $table->timestamp('forms_synced_at')->nullable();
                $table->string('forms_sync_error', 500)->nullable();
                $table->timestamp('last_backfill_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'account_id'], 'social_lead_ad_pages_account_unique');
                $table->index(['tenant_id', 'page_id']);
            });
        }

        // Platform-wide on purpose (no tenant_id): one Page can feed one workspace. Owned through a tenant-owned row.
        if (! Schema::hasTable('social_lead_ad_page_claims')) {
            Schema::create('social_lead_ad_page_claims', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('page_id', 64);
                $table->foreignUlid('lead_page_id')->constrained('social_lead_ad_pages')->cascadeOnDelete();
                $table->timestamps();

                $table->unique('page_id', 'social_lead_ad_page_claims_page_unique');
                $table->unique('lead_page_id', 'social_lead_ad_page_claims_owner_unique');
            });
        }

        if (! Schema::hasTable('social_lead_forms')) {
            Schema::create('social_lead_forms', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('account_id')->constrained('social_accounts')->cascadeOnDelete();
                $table->string('external_id', 64);
                $table->string('name', 200);
                // Meta's own form status (ACTIVE, ARCHIVED, DELETED, DRAFT).
                $table->string('status', 24)->nullable();
                $table->string('locale', 16)->nullable();
                // The questions as the platform listed them: [{key, label, type}].
                $table->json('questions')->nullable();
                $table->boolean('is_enabled')->default(false);
                // The person's choices: {question key => {target, answer_key?}}. Null = automatic mapping.
                $table->json('mapping')->nullable();
                $table->timestamp('fetched_at')->nullable();
                $table->timestamp('last_lead_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'account_id', 'external_id'], 'social_lead_forms_external_unique');
                $table->index(['tenant_id', 'is_enabled']);
            });
        }

        if (! Schema::hasTable('social_lead_ad_leads')) {
            Schema::create('social_lead_ad_leads', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('account_id')->constrained('social_accounts')->cascadeOnDelete();
                $table->foreignUlid('form_id')->nullable()->constrained('social_lead_forms')->nullOnDelete();
                $table->string('leadgen_id', 64);
                $table->string('page_id', 64);
                $table->string('external_form_id', 64)->nullable();
                $table->string('ad_id', 64)->nullable();
                $table->string('campaign_ref', 120)->nullable();
                // webhook | backfill
                $table->string('origin', 16)->default('webhook');
                // received | captured | duplicate | failed | waiting_for_crm | waiting_for_account | skipped
                $table->string('status', 24)->default('received');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->string('reason_code', 64)->nullable();
                $table->string('reason', 500)->nullable();
                // The fetched answers, encrypted; removed as soon as the CRM has the lead.
                $table->longText('payload')->nullable();
                $table->string('crm_contact_id', 64)->nullable();
                $table->string('crm_lead_id', 64)->nullable();
                $table->timestamp('created_time_at')->nullable();
                $table->timestamp('received_at');
                $table->timestamp('fetched_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'leadgen_id'], 'social_lead_ad_leads_leadgen_unique');
                $table->index(['tenant_id', 'status', 'next_attempt_at'], 'social_lead_ad_leads_work_index');
                $table->index(['tenant_id', 'account_id', 'received_at'], 'social_lead_ad_leads_account_index');
                $table->index(['tenant_id', 'external_form_id', 'received_at'], 'social_lead_ad_leads_form_index');
            });
        }

        if (! Schema::hasTable('social_lead_ad_logs')) {
            Schema::create('social_lead_ad_logs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('lead_id')->constrained('social_lead_ad_leads')->cascadeOnDelete();
                $table->string('step', 24);
                $table->string('code', 64)->nullable();
                $table->string('message', 300)->nullable();
                $table->timestamp('created_at');

                $table->index(['tenant_id', 'lead_id', 'created_at'], 'social_lead_ad_logs_lead_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('social_lead_ad_logs');
        Schema::dropIfExists('social_lead_ad_leads');
        Schema::dropIfExists('social_lead_forms');
        Schema::dropIfExists('social_lead_ad_page_claims');
        Schema::dropIfExists('social_lead_ad_pages');
    }
};
