<?php

declare(strict_types=1);

use App\Core\Communication\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contacts', 'phone_e164')) {
            Schema::table('contacts', function (Blueprint $table): void {
                // The canonical E.164 form of `phone` (maintained by the Contact model), so a channel address
                // is found with an indexed equality instead of a scan. Null when the phone cannot be read.
                $table->string('phone_e164', 20)->nullable()->after('phone');
                $table->index(['tenant_id', 'phone_e164']);
            });
        }

        // Backfill (maintenance: every tenant, soft-deleted rows included, so a restore finds them too).
        DB::table('contacts')
            ->whereNotNull('phone')
            ->whereNull('phone_e164')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $e164 = PhoneNumber::normalize($row->phone);

                    if ($e164 !== null) {
                        DB::table('contacts')->where('id', $row->id)->where('tenant_id', $row->tenant_id)
                            ->update(['phone_e164' => $e164]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contacts', 'phone_e164')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'phone_e164']);
            $table->dropColumn('phone_e164');
        });
    }
};
