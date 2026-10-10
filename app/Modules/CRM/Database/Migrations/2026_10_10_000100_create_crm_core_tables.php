<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active', 'position']);
        });

        Schema::create('crm_pipelines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active', 'is_default']);
        });

        Schema::create('crm_pipeline_stages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('pipeline_id')->constrained('crm_pipelines')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'pipeline_id', 'name'], 'crm_stages_tenant_pipeline_name_unique');
            $table->unique(['tenant_id', 'pipeline_id', 'position'], 'crm_stages_tenant_pipeline_position_unique');
            $table->index(['tenant_id', 'pipeline_id', 'is_won']);
            $table->index(['tenant_id', 'pipeline_id', 'is_lost']);
        });

        Schema::create('crm_leads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUlid('source_id')->nullable()->constrained('crm_lead_sources')->nullOnDelete();
            $table->string('title', 200);
            $table->string('status', 24);
            $table->unsignedTinyInteger('score')->default(0);
            $table->text('qualification_notes')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'score', 'created_at']);
            $table->index(['tenant_id', 'source_id']);
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('crm_deals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('pipeline_id')->constrained('crm_pipelines')->restrictOnDelete();
            $table->foreignUlid('stage_id')->constrained('crm_pipeline_stages')->restrictOnDelete();
            $table->foreignUlid('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('title', 200);
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount')->default(0);
            $table->date('expected_close_date')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('lost_reason', 500)->nullable();
            $table->text('description')->nullable();
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'pipeline_id', 'stage_id']);
            $table->index(['tenant_id', 'status', 'expected_close_date']);
            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('crm_deal_stage_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('deal_id')->constrained('crm_deals')->cascadeOnDelete();
            $table->foreignUlid('from_stage_id')->nullable()->constrained('crm_pipeline_stages')->nullOnDelete();
            $table->foreignUlid('to_stage_id')->constrained('crm_pipeline_stages')->restrictOnDelete();
            $table->foreignUlid('changed_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->index(['tenant_id', 'deal_id', 'changed_at'], 'crm_deal_history_tenant_deal_changed_index');
        });

        Schema::create('crm_activities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->foreignUlid('deal_id')->nullable()->constrained('crm_deals')->nullOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('type', 24);
            $table->string('status', 20);
            $table->string('priority', 16);
            $table->string('subject', 200);
            $table->text('description')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('automation_key', 160)->nullable();
            $table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'remind_at', 'status']);
            $table->index(['tenant_id', 'lead_id', 'created_at']);
            $table->index(['tenant_id', 'deal_id', 'created_at']);
            $table->index(['tenant_id', 'contact_id', 'created_at']);
            $table->index(['tenant_id', 'company_id', 'created_at']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'branch_id']);
            $table->unique(['tenant_id', 'automation_key']);
        });

        Schema::create('crm_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('lead_id')->nullable()->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignUlid('deal_id')->nullable()->constrained('crm_deals')->cascadeOnDelete();
            $table->foreignUlid('activity_id')->nullable()->constrained('crm_activities')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->cascadeOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('author_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'lead_id', 'created_at']);
            $table->index(['tenant_id', 'deal_id', 'created_at']);
            $table->index(['tenant_id', 'contact_id', 'created_at']);
            $table->index(['tenant_id', 'company_id', 'created_at']);
        });

        Schema::create('crm_tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('colour', 24)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('crm_taggables', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('tag_id')->constrained('crm_tags')->cascadeOnDelete();
            $table->string('entity_type', 24);
            $table->ulid('entity_id');
            $table->timestamp('created_at');
            $table->unique(['tenant_id', 'tag_id', 'entity_type', 'entity_id'], 'crm_taggables_tenant_tag_entity_unique');
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'crm_taggables_tenant_entity_index');
        });

        Schema::create('crm_custom_fields', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('entity_type', 24);
            $table->string('key', 60);
            $table->string('name', 100);
            $table->string('type', 20);
            $table->boolean('is_required')->default(false);
            $table->json('options')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'entity_type', 'key'], 'crm_custom_fields_tenant_entity_key_unique');
            $table->index(['tenant_id', 'entity_type', 'is_active', 'position'], 'crm_custom_fields_tenant_entity_active_index');
        });

        Schema::create('crm_custom_field_values', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('custom_field_id')->constrained('crm_custom_fields')->cascadeOnDelete();
            $table->string('entity_type', 24);
            $table->ulid('entity_id');
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'custom_field_id', 'entity_type', 'entity_id'], 'crm_custom_values_tenant_field_entity_unique');
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'crm_custom_values_tenant_entity_index');
        });

        Schema::create('crm_segments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('entity_type', 24);
            $table->json('filters');
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'entity_type', 'name'], 'crm_segments_tenant_entity_name_unique');
            $table->index(['tenant_id', 'entity_type', 'is_active']);
        });

        Schema::create('crm_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('entity_type', 24);
            $table->ulid('entity_id');
            $table->string('original_name', 255);
            $table->string('path', 500);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->foreignUlid('uploaded_by')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'crm_attachments_tenant_entity_index');
        });

        Schema::create('crm_timeline_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('event', 80);
            $table->string('summary', 500);
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUlid('lead_id')->nullable()->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignUlid('deal_id')->nullable()->constrained('crm_deals')->cascadeOnDelete();
            $table->foreignUlid('activity_id')->nullable()->constrained('crm_activities')->cascadeOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'contact_id', 'occurred_at']);
            $table->index(['tenant_id', 'company_id', 'occurred_at']);
            $table->index(['tenant_id', 'lead_id', 'occurred_at']);
            $table->index(['tenant_id', 'deal_id', 'occurred_at']);
        });

        Schema::create('crm_automation_action_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('idempotency_key', 160);
            $table->json('output');
            $table->timestamps();
            $table->unique(
                ['tenant_id', 'action', 'idempotency_key'],
                'crm_action_receipts_tenant_action_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_automation_action_receipts');
        Schema::dropIfExists('crm_timeline_entries');
        Schema::dropIfExists('crm_attachments');
        Schema::dropIfExists('crm_segments');
        Schema::dropIfExists('crm_custom_field_values');
        Schema::dropIfExists('crm_custom_fields');
        Schema::dropIfExists('crm_taggables');
        Schema::dropIfExists('crm_tags');
        Schema::dropIfExists('crm_notes');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_deal_stage_history');
        Schema::dropIfExists('crm_deals');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_pipeline_stages');
        Schema::dropIfExists('crm_pipelines');
        Schema::dropIfExists('crm_lead_sources');
    }
};
