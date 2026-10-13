<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoicing_settings', function (Blueprint $table): void {
            // The printable document's look. `letterhead` lays it on the uploaded bands below.
            $table->string('document_template', 20)->default('classic')->after('invoice_accepted_quotes');
            // Tenant-disk paths (via TenantStorage) to the uploaded letterhead header/footer images.
            $table->string('letterhead_header_path')->nullable()->after('document_template');
            $table->string('letterhead_footer_path')->nullable()->after('letterhead_header_path');
        });
    }

    public function down(): void
    {
        Schema::table('invoicing_settings', function (Blueprint $table): void {
            $table->dropColumn(['document_template', 'letterhead_header_path', 'letterhead_footer_path']);
        });
    }
};
