<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, guarded: the "AI-assisted" marker on a post, the workspace's daily cap for queued AI analysis and
 * the sentiment result of one audience comment (never the comment text).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('social_posts', 'ai_assisted')) {
            Schema::table('social_posts', function (Blueprint $table): void {
                // Sticky: set when AI suggestions were used on the draft; approvers can see it. Informational.
                $table->boolean('ai_assisted')->default(false);
            });
        }

        if (! Schema::hasColumn('social_settings', 'ai_daily_cap')) {
            Schema::table('social_settings', function (Blueprint $table): void {
                // Comments analysed by queued AI jobs per day (workspace timezone-independent: UTC day).
                $table->unsignedSmallInteger('ai_daily_cap')->default(200);
            });
        }

        if (! Schema::hasTable('social_comment_sentiments')) {
            Schema::create('social_comment_sentiments', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('account_id')->constrained('social_accounts')->cascadeOnDelete();
                // The platform's own id of the comment; the comment text is never stored here.
                $table->string('external_comment_id', 191);
                $table->char('text_hash', 64);
                $table->string('sentiment', 12);
                // Signed basis points -10000..10000; null when the model gave no usable score.
                $table->smallInteger('score_basis_points')->nullable();
                $table->string('language', 12)->nullable();
                $table->string('prompt_version', 40)->default('');
                $table->timestamp('commented_at')->nullable();
                $table->timestamp('analysed_at');
                $table->timestamps();

                $table->unique(['tenant_id', 'account_id', 'external_comment_id'], 'social_sentiment_unique');
                $table->index(['tenant_id', 'analysed_at'], 'social_sentiment_day_index');
                $table->index(['tenant_id', 'account_id', 'commented_at'], 'social_sentiment_period_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('social_comment_sentiments');

        if (Schema::hasColumn('social_settings', 'ai_daily_cap')) {
            Schema::table('social_settings', function (Blueprint $table): void {
                $table->dropColumn('ai_daily_cap');
            });
        }

        if (Schema::hasColumn('social_posts', 'ai_assisted')) {
            Schema::table('social_posts', function (Blueprint $table): void {
                $table->dropColumn('ai_assisted');
            });
        }
    }
};
