<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-account publishing options chosen in the composer (ADR 0033, amendment 2026-10-14): for TikTok the privacy
 * level, the comment / duet / stitch switches and the commercial content disclosure. Null means nothing was chosen
 * (every target created before this column existed). Additive and guarded: safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_post_targets') && ! Schema::hasColumn('social_post_targets', 'options')) {
            Schema::table('social_post_targets', function (Blueprint $table): void {
                $table->json('options')->nullable()->after('body_override');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('social_post_targets') && Schema::hasColumn('social_post_targets', 'options')) {
            Schema::table('social_post_targets', function (Blueprint $table): void {
                $table->dropColumn('options');
            });
        }
    }
};
