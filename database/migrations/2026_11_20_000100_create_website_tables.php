<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Messages from the public website's contact form (docs/WEBSITE.md). Platform data on purpose: they are
        // written to the company behind the platform, before any workspace exists, so there is no `tenant_id`.
        // Read and handled from the Super Admin panel; each one is also mailed to the contact recipient.
        Schema::create('website_enquiries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 40)->nullable();
            $table->string('company', 160)->nullable();
            $table->string('topic', 20);
            $table->text('message');
            $table->string('locale', 5);
            $table->string('status', 20)->default('new');
            $table->timestamp('handled_at')->nullable();
            $table->foreignUlid('handled_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // The website's words as the operator changed them in the Super Admin panel, over the defaults in
        // lang/{locale}/site*.php. One row per (locale, translation key); only changed words are stored.
        // Platform data: no `tenant_id`.
        Schema::create('website_texts', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 5);
            $table->string('key', 191);
            $table->text('value');
            $table->foreignUlid('updated_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['locale', 'key']);
        });

        // A plan's name and description in Bengali, shown on the Bengali website; the plain columns stay English.
        Schema::table('plans', function (Blueprint $table): void {
            $table->string('name_bn', 120)->nullable()->after('name');
            $table->text('description_bn')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['name_bn', 'description_bn']);
        });

        Schema::dropIfExists('website_texts');
        Schema::dropIfExists('website_enquiries');
    }
};
