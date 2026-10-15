<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, guarded: an index for reading a comment thread by its parent (ADR 0030). The unified inbox and the
 * comments page fetch a thread's replies with `parent_id IN (...)` ordered by `commented_at`, list top-level comments
 * with `parent_id IS NULL`, and join a reply to its parent; none of that had an index. Leads with `tenant_id` like
 * every index on a tenant-owned table. Safe to run twice.
 */
return new class extends Migration
{
    private const string TABLE = 'social_audience_comments';

    private const string INDEX = 'social_audience_comments_thread';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasIndex(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(['tenant_id', 'parent_id', 'commented_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasIndex(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
