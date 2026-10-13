<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('support_tickets', 'resolution_first_breached_at')) {
            return;
        }

        Schema::table('support_tickets', function (Blueprint $table): void {
            // The first resolution cycle's breach survives a reopen (resolution_breached_at is per cycle).
            $table->timestamp('resolution_first_breached_at')->nullable()->after('resolution_breached_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('support_tickets', 'resolution_first_breached_at')) {
            return;
        }

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropColumn('resolution_first_breached_at');
        });
    }
};
