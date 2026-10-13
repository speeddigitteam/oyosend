<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Campaign media header and button variables (ADR 0022)
|--------------------------------------------------------------------------
|
| A campaign may carry one fixed media header (an image, video or document the
| workspace stored in the communication media library) and fixed dynamic button
| values for its approved template. The media is referenced by its communication
| media id only (no path, no provider token). Additive and guarded.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table): void {
            if (! Schema::hasColumn('whatsapp_campaigns', 'header_media_type')) {
                $table->string('header_media_type', 16)->nullable()->after('variable_map');
            }

            if (! Schema::hasColumn('whatsapp_campaigns', 'header_media_id')) {
                $table->ulid('header_media_id')->nullable()->after('header_media_type');
            }

            if (! Schema::hasColumn('whatsapp_campaigns', 'button_params')) {
                $table->json('button_params')->nullable()->after('header_media_id');
            }

            if (! Schema::hasColumn('whatsapp_campaigns', 'sender_number_id')) {
                $table->ulid('sender_number_id')->nullable()->after('button_params');
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table): void {
            foreach (['header_media_type', 'header_media_id', 'button_params', 'sender_number_id'] as $column) {
                if (Schema::hasColumn('whatsapp_campaigns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
