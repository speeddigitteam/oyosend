<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('communication_webhook_events', 'status')) {
            return;
        }

        Schema::table('communication_webhook_events', function (Blueprint $table): void {
            // `applied` (the default) or `failed`: an event whose handling kept failing and was given up on, so
            // the provider stops redelivering it. A failed event is a duplicate like any other.
            $table->string('status', 12)->default('applied')->after('type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('communication_webhook_events', 'status')) {
            return;
        }

        Schema::table('communication_webhook_events', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
