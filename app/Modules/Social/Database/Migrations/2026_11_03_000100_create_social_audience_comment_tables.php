<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audience comments (what people write on a connected Page's posts), the keyword rules that
 * moderate them, their audit-friendly run log, the per-Page ingestion state and the platform-wide
 * Page claim. Additive and guarded: safe to run where an earlier build already created them.
 *
 * `social_comment_page_claims` is deliberately NOT unique per tenant: its unique index is what stops
 * a second workspace from feeding comments from a Page another workspace already claimed
 * (docs/DATABASE.md section 2, the same pattern as ADR 0016). It is owned through its account row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_audience_comments')) {
            Schema::create('social_audience_comments', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('account_id')->constrained('social_accounts')->cascadeOnDelete();
                $table->foreignUlid('post_id')->nullable()->constrained('social_posts')->nullOnDelete();
                $table->string('platform', 32);
                // The platform's ids. `external_id` is the comment; `external_post_id` the post it is on.
                $table->string('external_id', 100);
                $table->string('external_post_id', 100)->nullable();
                $table->string('parent_external_id', 100)->nullable();
                $table->ulid('parent_id')->nullable();
                $table->string('author_external_id', 100)->nullable();
                $table->string('author_name', 190)->nullable();
                // Untrusted text: control characters stripped and length capped before it is stored.
                $table->text('message')->nullable();
                $table->boolean('has_attachment')->default(false);
                $table->boolean('is_from_page')->default(false);
                $table->unsignedInteger('like_count')->default(0);
                $table->boolean('liked_by_page')->default(false);
                $table->string('permalink', 500)->nullable();
                // visible | hidden | deleted (as last known on the platform).
                $table->string('remote_state', 16)->default('visible');
                $table->string('source', 16)->default('webhook');
                // Placeholder for a later classifier: always null today.
                $table->string('sentiment', 16)->nullable();
                $table->boolean('needs_attention')->default(false);
                $table->string('attention_reason', 64)->nullable();
                $table->json('tags')->nullable();
                $table->ulid('owner_id')->nullable();
                $table->ulid('department_id')->nullable();
                $table->ulid('branch_id')->nullable();
                $table->timestamp('commented_at')->nullable();
                $table->timestamp('received_at');
                $table->timestamp('read_at')->nullable();
                $table->ulid('read_by')->nullable();
                $table->timestamp('replied_at')->nullable();
                $table->unsignedInteger('reply_count')->default(0);
                $table->timestamp('handled_at')->nullable();
                $table->ulid('handled_by')->nullable();
                $table->string('last_action_error', 64)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'account_id', 'external_id'], 'social_audience_comments_unique');
                $table->index(['tenant_id', 'account_id', 'commented_at'], 'social_audience_comments_account_time');
                $table->index(['tenant_id', 'external_post_id'], 'social_audience_comments_post');
                $table->index(['tenant_id', 'handled_at', 'remote_state'], 'social_audience_comments_open');
                $table->index(['tenant_id', 'owner_id'], 'social_audience_comments_owner');
                $table->index(['tenant_id', 'author_external_id'], 'social_audience_comments_author');
            });
        }

        if (! Schema::hasTable('social_comment_notes')) {
            Schema::create('social_comment_notes', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('comment_id')->constrained('social_audience_comments')->cascadeOnDelete();
                $table->ulid('author_id')->nullable();
                $table->text('body');
                $table->timestamps();

                $table->index(['tenant_id', 'comment_id']);
            });
        }

        if (! Schema::hasTable('social_comment_rules')) {
            Schema::create('social_comment_rules', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 120);
                $table->boolean('is_enabled')->default(false);
                // any | all
                $table->string('match_mode', 8)->default('any');
                // word | contains
                $table->string('match_type', 12)->default('word');
                $table->json('keywords');
                $table->foreignUlid('account_id')->nullable()->constrained('social_accounts')->cascadeOnDelete();
                $table->foreignUlid('post_id')->nullable()->constrained('social_posts')->cascadeOnDelete();
                $table->foreignUlid('campaign_id')->nullable()->constrained('social_campaigns')->cascadeOnDelete();
                // Ordered list of {type, ...} actions; only first-party action types are accepted.
                $table->json('actions');
                $table->json('reply_templates')->nullable();
                $table->unsignedSmallInteger('hourly_cap')->default(20);
                $table->unsignedSmallInteger('cooldown_minutes')->default(60);
                // Workspace-local "HH:MM"; the window may cross midnight. Null means no quiet hours.
                $table->string('quiet_start', 5)->nullable();
                $table->string('quiet_end', 5)->nullable();
                $table->unsignedSmallInteger('priority')->default(100);
                // The member whose permissions the rule runs with, re-checked before every action.
                $table->ulid('actor_id')->nullable();
                $table->ulid('created_by')->nullable();
                $table->unsignedInteger('matched_count')->default(0);
                $table->timestamp('last_matched_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'name'], 'social_comment_rules_name_unique');
                $table->index(['tenant_id', 'is_enabled', 'priority'], 'social_comment_rules_active');
            });
        }

        if (! Schema::hasTable('social_comment_rule_runs')) {
            Schema::create('social_comment_rule_runs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('rule_id')->constrained('social_comment_rules')->cascadeOnDelete();
                $table->foreignUlid('comment_id')->constrained('social_audience_comments')->cascadeOnDelete();
                $table->foreignUlid('account_id')->constrained('social_accounts')->cascadeOnDelete();
                $table->string('author_external_id', 100)->nullable();
                $table->string('action', 24);
                // done | skipped | failed
                $table->string('outcome', 12);
                $table->string('reason', 64)->nullable();
                $table->timestamps();

                // One action of one rule per comment, ever: a re-delivered comment never replies twice.
                $table->unique(['tenant_id', 'rule_id', 'comment_id', 'action'], 'social_comment_rule_runs_unique');
                $table->index(['tenant_id', 'rule_id', 'outcome', 'created_at'], 'social_comment_rule_runs_rule_time');
                $table->index(['tenant_id', 'account_id', 'outcome', 'created_at'], 'social_comment_rule_runs_account_time');
            });
        }

        if (! Schema::hasTable('social_comment_sources')) {
            Schema::create('social_comment_sources', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('account_id')->constrained('social_accounts')->cascadeOnDelete();
                $table->boolean('is_enabled')->default(true);
                // none | subscribed | failed: the state of the Page's `feed` webhook subscription.
                $table->string('webhook_state', 16)->default('none');
                $table->timestamp('webhook_checked_at')->nullable();
                $table->timestamp('last_event_at')->nullable();
                $table->timestamp('last_polled_at')->nullable();
                $table->string('last_error_code', 64)->nullable();
                $table->unsignedSmallInteger('consecutive_failures')->default(0);
                $table->timestamps();

                $table->unique(['tenant_id', 'account_id'], 'social_comment_sources_account_unique');
            });
        }

        if (! Schema::hasTable('social_comment_settings')) {
            Schema::create('social_comment_settings', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                // Global kill switch: while true no rule acts (comments are still received and shown).
                $table->boolean('automation_paused')->default(false);
                $table->ulid('paused_by')->nullable();
                $table->timestamp('paused_at')->nullable();
                // Automatic actions (replies, hides, private replies) per connected Page per hour.
                $table->unsignedSmallInteger('page_hourly_cap')->default(60);
                $table->timestamps();

                $table->unique(['tenant_id']);
            });
        }

        if (! Schema::hasTable('social_comment_page_claims')) {
            Schema::create('social_comment_page_claims', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                // Plain column (no tenant scope on the model): used to route a central webhook delivery.
                $table->ulid('tenant_id')->index();
                $table->foreignUlid('account_id')->constrained('social_accounts')->cascadeOnDelete();
                $table->string('platform', 32);
                $table->string('external_id', 100);
                $table->timestamps();

                $table->unique(['platform', 'external_id'], 'social_comment_page_claims_page_unique');
                $table->unique('account_id', 'social_comment_page_claims_account_unique');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'social_comment_page_claims', 'social_comment_settings', 'social_comment_sources',
            'social_comment_rule_runs', 'social_comment_rules', 'social_comment_notes', 'social_audience_comments',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
