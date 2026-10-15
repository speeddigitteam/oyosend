<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facebook Messenger and Instagram direct messages (ADR 0031). Additive and guarded: safe to run twice.
 *
 *  - `social_dm_settings`: per connected Page, whether message capture is on, what the token holds and the Instagram
 *    professional account linked to the Page.
 *  - `social_dm_conversations`: one per (Page account, platform, person). The person is the platform's page-scoped id
 *    (PSID on Messenger, IGSID on Instagram); a name is stored only when the platform gave one.
 *  - `social_dm_messages`: deduplicated by the platform's message id (`mid`), so a redelivered webhook stores nothing.
 *
 * Every table is tenant-owned and every unique index leads with `tenant_id`. The Instagram account id is also written
 * to the platform-wide Page claim (`social_comment_page_claims.instagram_id`, docs/DATABASE.md section 2): the central
 * webhook receives an Instagram event keyed by that id alone and must find the one workspace that claimed the Page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_dm_settings')) {
            Schema::create('social_dm_settings', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
                $table->boolean('is_enabled')->default(false);
                // off | on | failed
                $table->string('state', 16)->default('off');
                // off | on | not_linked | missing_permissions | taken | failed
                $table->string('instagram_state', 24)->default('off');
                $table->string('instagram_account_id', 64)->nullable();
                $table->json('granted_scopes')->nullable();
                $table->json('missing_scopes')->nullable();
                $table->string('last_error_code', 64)->nullable();
                $table->string('last_error', 500)->nullable();
                $table->timestamp('enabled_at')->nullable();
                $table->timestamp('last_event_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'social_account_id'], 'social_dm_settings_account_unique');
                $table->index(['tenant_id', 'instagram_account_id'], 'social_dm_settings_instagram');
            });
        }

        if (! Schema::hasTable('social_dm_conversations')) {
            Schema::create('social_dm_conversations', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
                // messenger | instagram
                $table->string('platform', 16);
                // The page-scoped id of the person (PSID / IGSID).
                $table->string('participant_id', 100);
                $table->string('participant_name', 190)->nullable();
                $table->string('last_message_preview', 280)->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('last_inbound_at')->nullable();
                $table->unsignedInteger('unread_count')->default(0);
                // open | closed
                $table->string('status', 16)->default('open');
                // The member the conversation is assigned to (record scope of social.messages.*).
                $table->ulid('owner_id')->nullable();
                $table->ulid('department_id')->nullable();
                $table->ulid('branch_id')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'social_account_id', 'platform', 'participant_id'], 'social_dm_conversations_unique');
                $table->index(['tenant_id', 'last_message_at', 'id'], 'social_dm_conversations_inbox');
                $table->index(['tenant_id', 'owner_id'], 'social_dm_conversations_owner');
            });
        }

        if (! Schema::hasTable('social_dm_messages')) {
            Schema::create('social_dm_messages', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('conversation_id')->constrained('social_dm_conversations')->cascadeOnDelete();
                // in | out
                $table->string('direction', 8);
                // The platform's message id; null only while a reply is being sent.
                $table->string('provider_message_id', 191)->nullable();
                // Untrusted text: control characters stripped and length capped before it is stored.
                $table->text('text')->nullable();
                // image | video | audio | file | other: a description only, the file is never fetched.
                $table->string('attachment_type', 16)->nullable();
                $table->ulid('sent_by_membership_id')->nullable();
                // received | sending | sent | delivered | read | deleted
                $table->string('status', 16);
                $table->timestamp('sent_at');
                $table->timestamps();

                $table->unique(['tenant_id', 'provider_message_id'], 'social_dm_messages_provider_unique');
                $table->index(['tenant_id', 'conversation_id', 'sent_at'], 'social_dm_messages_thread');
            });
        }

        if (Schema::hasTable('social_comment_page_claims') && ! Schema::hasColumn('social_comment_page_claims', 'instagram_id')) {
            Schema::table('social_comment_page_claims', function (Blueprint $table): void {
                // The Instagram professional account linked to the claimed Page, while message capture includes it.
                $table->string('instagram_id', 64)->nullable()->after('external_id');
                $table->unique('instagram_id', 'social_comment_page_claims_instagram_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('social_comment_page_claims') && Schema::hasColumn('social_comment_page_claims', 'instagram_id')) {
            Schema::table('social_comment_page_claims', function (Blueprint $table): void {
                $table->dropUnique('social_comment_page_claims_instagram_unique');
                $table->dropColumn('instagram_id');
            });
        }

        Schema::dropIfExists('social_dm_messages');
        Schema::dropIfExists('social_dm_conversations');
        Schema::dropIfExists('social_dm_settings');
    }
};
