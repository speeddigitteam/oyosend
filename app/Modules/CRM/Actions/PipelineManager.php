<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\CRM\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PipelineManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param list<array{name: string, probability: int, is_won?: bool, is_lost?: bool}> $stages */
    public function create(string $name, array $stages, bool $default = false): Pipeline
    {
        if ($stages === []) {
            throw ValidationException::withMessages(['stages' => __('A pipeline needs at least one stage.')]);
        }

        return DB::transaction(function () use ($name, $stages, $default): Pipeline {
            $default = $default || ! Pipeline::query()->exists();

            if ($default) {
                Pipeline::query()->update(['is_default' => false]);
            }

            /** @var Pipeline $pipeline */
            $pipeline = Pipeline::query()->create(['name' => $name, 'is_default' => $default, 'is_active' => true]);

            foreach ($stages as $position => $input) {
                $this->guardStage($input);
                $pipeline->stages()->create([...$input, 'position' => $position]);
            }

            $this->audit->record('crm.pipeline.created', $pipeline, ['stages' => count($stages), 'default' => $default]);

            return $pipeline->load('stages');
        });
    }

    public function update(Pipeline $pipeline, string $name, bool $active, bool $default): Pipeline
    {
        if ($default && ! $active) {
            throw ValidationException::withMessages(['active' => __('The default pipeline must remain active.')]);
        }

        return DB::transaction(function () use ($pipeline, $name, $active, $default): Pipeline {
            if ($default) {
                Pipeline::query()->whereKeyNot($pipeline->getKey())->update(['is_default' => false]);
            }

            $pipeline->update(['name' => $name, 'is_active' => $active, 'is_default' => $default]);
            $this->audit->record('crm.pipeline.updated', $pipeline, ['active' => $active, 'default' => $default]);

            return $pipeline;
        });
    }

    /** @param array{name: string, probability: int, is_won?: bool, is_lost?: bool} $input */
    public function addStage(Pipeline $pipeline, array $input): PipelineStage
    {
        $this->guardStage($input);

        return DB::transaction(function () use ($pipeline, $input): PipelineStage {
            $position = ((int) $pipeline->stages()->max('position')) + 1;
            /** @var PipelineStage $stage */
            $stage = $pipeline->stages()->create([...$input, 'position' => $position]);
            $this->audit->record('crm.pipeline_stage.created', $stage, ['pipeline_id' => $pipeline->id]);

            return $stage;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createSource(array $attributes): LeadSource
    {
        return DB::transaction(function () use ($attributes): LeadSource {
            /** @var LeadSource $source */
            $source = LeadSource::query()->create($attributes);
            $this->audit->record('crm.lead_source.created', $source);

            return $source;
        });
    }

    /** @param array{name: string, probability: int, is_won?: bool, is_lost?: bool} $stage */
    private function guardStage(array $stage): void
    {
        if ($stage['probability'] < 0 || $stage['probability'] > 100 || (($stage['is_won'] ?? false) && ($stage['is_lost'] ?? false))) {
            throw ValidationException::withMessages(['stages' => __('A pipeline stage has invalid outcome settings.')]);
        }
    }
}
