<?php

declare(strict_types=1);

use App\Modules\CRM\Enums\ActivityPriority;
use App\Modules\CRM\Enums\ActivityStatus;
use App\Modules\CRM\Enums\ActivityType;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Enums\CustomFieldType;
use App\Modules\CRM\Enums\DealStatus;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Models\Activity;
use App\Modules\CRM\Models\Attachment;
use App\Modules\CRM\Models\AutomationActionReceipt;
use App\Modules\CRM\Models\CustomField;
use App\Modules\CRM\Models\CustomFieldValue;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\DealStageHistory;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\Note;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\CRM\Models\PipelineStage;
use App\Modules\CRM\Models\Segment;
use App\Modules\CRM\Models\Tag;
use App\Modules\CRM\Models\Taggable;
use App\Modules\CRM\Models\TimelineEntry;

it('isolates every CRM core table by workspace', function () {
    $pipeline = function (): Pipeline {
        /** @var Pipeline $pipeline */
        $pipeline = Pipeline::query()->create(['name' => 'Pipeline '.str()->random(6), 'is_default' => true, 'is_active' => true]);

        return $pipeline;
    };
    $stage = function () use ($pipeline): PipelineStage {
        $pipeline = $pipeline();
        /** @var PipelineStage $stage */
        $stage = PipelineStage::query()->create(['pipeline_id' => $pipeline->id, 'name' => 'Open', 'probability' => 20, 'position' => 0]);

        return $stage;
    };
    $lead = function (): Lead {
        /** @var Lead $lead */
        $lead = Lead::query()->create(['title' => 'Lead', 'status' => LeadStatus::New, 'score' => 0]);

        return $lead;
    };
    $deal = function () use ($stage): Deal {
        $stage = $stage();
        /** @var Deal $deal */
        $deal = Deal::query()->create(['pipeline_id' => $stage->pipeline_id, 'stage_id' => $stage->id, 'title' => 'Deal', 'status' => DealStatus::Open, 'currency' => 'BDT', 'amount' => 0]);

        return $deal;
    };

    $this->assertTenantIsolated(LeadSource::class, fn () => LeadSource::query()->create(['name' => 'Source '.str()->random(6)]));
    $this->assertTenantIsolated(Pipeline::class, fn () => $pipeline());
    $this->assertTenantIsolated(PipelineStage::class, fn () => $stage());
    $this->assertTenantIsolated(Lead::class, fn () => $lead());
    $this->assertTenantIsolated(Deal::class, fn () => $deal());
    $this->assertTenantIsolated(DealStageHistory::class, fn () => DealStageHistory::query()->create(['deal_id' => ($deal = $deal())->id, 'to_stage_id' => $deal->stage_id, 'changed_at' => now()]));
    $this->assertTenantIsolated(Activity::class, fn () => Activity::query()->create(['type' => ActivityType::Task, 'status' => ActivityStatus::Planned, 'priority' => ActivityPriority::Normal, 'subject' => 'Task']));
    $this->assertTenantIsolated(Note::class, fn () => Note::query()->create(['body' => 'Note']));
    $this->assertTenantIsolated(Tag::class, fn () => Tag::query()->create(['name' => 'Tag '.str()->random(6)]));
    $this->assertTenantIsolated(Taggable::class, fn () => Taggable::query()->create(['tag_id' => Tag::query()->create(['name' => 'Tag '.str()->random(6)])->id, 'entity_type' => CrmEntityType::Lead, 'entity_id' => (string) str()->ulid()]));
    $this->assertTenantIsolated(CustomField::class, fn () => CustomField::query()->create(['entity_type' => CrmEntityType::Lead, 'key' => 'field_'.str()->lower(str()->random(6)), 'name' => 'Field', 'type' => CustomFieldType::Text]));
    $this->assertTenantIsolated(CustomFieldValue::class, fn () => CustomFieldValue::query()->create(['custom_field_id' => CustomField::query()->create(['entity_type' => CrmEntityType::Lead, 'key' => 'field_'.str()->lower(str()->random(6)), 'name' => 'Field', 'type' => CustomFieldType::Text])->id, 'entity_type' => CrmEntityType::Lead, 'entity_id' => (string) str()->ulid(), 'value' => 'Value']));
    $this->assertTenantIsolated(Segment::class, fn () => Segment::query()->create(['name' => 'Segment '.str()->random(6), 'entity_type' => CrmEntityType::Lead, 'filters' => []]));
    $this->assertTenantIsolated(Attachment::class, fn () => Attachment::query()->create(['entity_type' => CrmEntityType::Lead, 'entity_id' => (string) str()->ulid(), 'original_name' => 'file.txt', 'path' => 'crm/file.txt', 'mime_type' => 'text/plain', 'size' => 4]));
    $this->assertTenantIsolated(TimelineEntry::class, fn () => TimelineEntry::query()->create(['event' => 'crm.test', 'summary' => 'Test', 'occurred_at' => now()]));
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create(['action' => 'crm.test', 'idempotency_key' => (string) str()->ulid(), 'output' => ['ok' => true]]));
});
