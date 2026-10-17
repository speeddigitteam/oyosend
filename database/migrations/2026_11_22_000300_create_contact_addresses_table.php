<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Typed addresses of a company or a contact, like Odoo's "Contacts & Addresses": where invoices go, where goods are
 * delivered, and any other place, each with its own person, phone and email (ADR 0039).
 *
 * Every row belongs to exactly one parent: `company_id` or `contact_id`, never both and never neither. That rule and
 * "one default per type per parent" are kept by ContactDirectory, not by the schema: MySQL has no partial unique
 * index, and it refuses a CHECK constraint on a column whose foreign key has a referential action (the cascades
 * below).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_addresses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Parents are soft-deleted; a hard delete (a workspace being purged) takes their addresses with them.
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label', 120)->nullable();
            $table->string('name', 200)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country', 2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_addresses');
    }
};
