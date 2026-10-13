<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('email_marketing_deliveries', 'send_epoch')) {
            return;
        }

        Schema::table('email_marketing_deliveries', function (Blueprint $table): void {
            // Raised each time the platform refused the hand-over for a plan limit: the gateway key gets this
            // suffix, because the platform keeps the refused message under the old key forever.
            $table->unsignedTinyInteger('send_epoch')->default(0)->after('attempts');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('email_marketing_deliveries', 'send_epoch')) {
            return;
        }

        Schema::table('email_marketing_deliveries', function (Blueprint $table): void {
            $table->dropColumn('send_epoch');
        });
    }
};
