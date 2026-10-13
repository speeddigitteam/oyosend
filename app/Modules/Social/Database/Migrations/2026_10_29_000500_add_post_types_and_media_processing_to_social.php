<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: post type (post / photos / video / reel), probed video metadata on library media, and the
 * remote upload/processing session of a target (a video is uploaded in steps and then processed by the
 * platform before it counts as published).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('social_posts', 'post_type')) {
            Schema::table('social_posts', function (Blueprint $table): void {
                $table->string('post_type', 12)->default('post')->after('link_url');
            });
        }

        if (! Schema::hasColumn('social_media', 'duration_ms')) {
            Schema::table('social_media', function (Blueprint $table): void {
                // Read from the MP4/QuickTime container header; null with probe_status "unknown" when unreadable.
                $table->unsignedInteger('duration_ms')->nullable()->after('height');
                $table->string('probe_status', 12)->nullable()->after('duration_ms');
            });
        }

        if (! Schema::hasColumn('social_post_targets', 'remote_state')) {
            Schema::table('social_post_targets', function (Blueprint $table): void {
                $table->string('remote_id', 191)->nullable()->after('external_url');
                $table->string('remote_state', 20)->nullable()->after('remote_id');
                $table->unsignedBigInteger('upload_offset')->default(0)->after('remote_state');
                $table->json('remote_meta')->nullable()->after('upload_offset');
                $table->timestamp('remote_started_at')->nullable()->after('remote_meta');

                $table->index(['tenant_id', 'status', 'remote_started_at'], 'social_targets_remote_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('social_post_targets', 'remote_state')) {
            Schema::table('social_post_targets', function (Blueprint $table): void {
                $table->dropIndex('social_targets_remote_index');
                $table->dropColumn(['remote_id', 'remote_state', 'upload_offset', 'remote_meta', 'remote_started_at']);
            });
        }

        if (Schema::hasColumn('social_media', 'duration_ms')) {
            Schema::table('social_media', function (Blueprint $table): void {
                $table->dropColumn(['duration_ms', 'probe_status']);
            });
        }

        if (Schema::hasColumn('social_posts', 'post_type')) {
            Schema::table('social_posts', function (Blueprint $table): void {
                $table->dropColumn('post_type');
            });
        }
    }
};
