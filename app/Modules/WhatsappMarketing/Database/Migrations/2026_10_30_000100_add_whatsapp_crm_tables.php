<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| WhatsApp Business CRM bridge (module `whatsapp_marketing`, 1.2)
|--------------------------------------------------------------------------
|
| Two additive, guarded tables for the CRM bridge:
|   - `whatsapp_lead_capture_settings`: the per-workspace gate that turns an
|     inbound conversation into a CRM lead (off by default), plus the optional
|     keywords that also mark a message a lead. No credential is stored.
|   - `whatsapp_captured_leads`: the record that a conversation produced a CRM
|     lead. Ids only: the shared contact id and the CRM lead id, never any CRM
|     content. Unique on (tenant_id, message_id) so one inbound message makes at
|     most one capture row. Every unique index starts with `tenant_id`.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_lead_capture_settings')) {
            Schema::create('whatsapp_lead_capture_settings', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->boolean('enabled')->default(false);
                $table->json('keywords')->nullable();
                $table->timestamps();

                $table->unique('tenant_id');
            });
        }

        if (! Schema::hasTable('whatsapp_captured_leads')) {
            Schema::create('whatsapp_captured_leads', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUlid('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
                // The triggering WhatsApp message (this app's own message id), the shared contact and the CRM lead.
                $table->ulid('message_id');
                $table->ulid('contact_id')->nullable();
                $table->string('lead_id', 26)->nullable();
                $table->string('source', 40)->default('whatsapp');
                $table->string('status', 20)->default('captured');
                $table->timestamps();

                $table->unique(['tenant_id', 'message_id']);
                $table->index(['tenant_id', 'conversation_id']);
                $table->index(['tenant_id', 'contact_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_captured_leads');
        Schema::dropIfExists('whatsapp_lead_capture_settings');
    }
};
