<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Modules\Talent\Enums\StageType;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\JobOpening;
use App\Modules\Talent\Models\Pipeline;
use App\Modules\Talent\Models\PipelineStage;
use App\Modules\Talent\Models\RejectionReason;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Hiring pipelines, their stages and the rejection reasons: the configurable part of recruitment. */
final class RecruitmentCatalogue
{
    /** @var list<array{0: string, 1: StageType}> */
    private const array DEFAULT_STAGES = [
        ['Applied', StageType::Active],
        ['Screening', StageType::Active],
        ['Interview', StageType::Active],
        ['Offer', StageType::Active],
        ['Hired', StageType::Hired],
        ['Rejected', StageType::Rejected],
    ];

    /** The workspace's default pipeline; the standard six-stage one is created on first use. */
    public function defaultPipeline(): Pipeline
    {
        $pipeline = Pipeline::query()->where('is_default', true)->where('is_active', true)->first()
            ?? Pipeline::query()->where('is_active', true)->orderBy('created_at')->first();

        if ($pipeline !== null) {
            return $pipeline;
        }

        try {
            return DB::transaction(function (): Pipeline {
                $pipeline = Pipeline::query()->create(['name' => 'Default pipeline', 'is_default' => true, 'is_active' => true]);

                foreach (self::DEFAULT_STAGES as $position => [$name, $type]) {
                    PipelineStage::query()->create([
                        'pipeline_id' => $pipeline->id, 'name' => $name, 'position' => ($position + 1) * 10, 'type' => $type,
                    ]);
                }

                return $pipeline;
            });
        } catch (UniqueConstraintViolationException) {
            return Pipeline::query()->where('name', 'Default pipeline')->firstOrFail();
        }
    }

    /** @param array{name: string, is_default?: bool, is_active?: bool} $attributes */
    public function savePipeline(?Pipeline $pipeline, array $attributes): Pipeline
    {
        return DB::transaction(function () use ($pipeline, $attributes): Pipeline {
            $isDefault = (bool) ($attributes['is_default'] ?? false);
            $isActive = (bool) ($attributes['is_active'] ?? true);

            if (! $isActive && ($isDefault || $pipeline?->is_default === true)) {
                throw ValidationException::withMessages(['is_active' => __('The default pipeline cannot be deactivated.')]);
            }

            if ($pipeline === null) {
                $pipeline = new Pipeline;
            } elseif (! $isActive && JobOpening::query()->where('pipeline_id', $pipeline->id)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['is_active' => __('A pipeline used by an open job opening cannot be deactivated.')]);
            }

            $pipeline->fill(['name' => $attributes['name'], 'is_default' => $isDefault, 'is_active' => $isActive])->save();

            if ($isDefault) {
                Pipeline::query()->whereKeyNot($pipeline->id)->where('is_default', true)->update(['is_default' => false]);
            }

            return $pipeline;
        });
    }

    /** @param array{name: string, type: string, position?: int|null} $attributes */
    public function saveStage(Pipeline $pipeline, ?PipelineStage $stage, array $attributes): PipelineStage
    {
        return DB::transaction(function () use ($pipeline, $stage, $attributes): PipelineStage {
            $type = StageType::from($attributes['type']);

            if ($stage !== null && $stage->pipeline_id !== $pipeline->id) {
                abort(404);
            }

            if ($stage !== null && $stage->type !== $type
                && Application::query()->where('stage_id', $stage->id)->exists()) {
                throw ValidationException::withMessages(['type' => __('The type of a stage that holds applications cannot change.')]);
            }

            if ($type !== StageType::Active) {
                $clash = PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('type', $type)
                    ->when($stage !== null, fn ($query) => $query->whereKeyNot($stage?->id))->exists();

                if ($clash) {
                    throw ValidationException::withMessages(['type' => __('A pipeline has at most one hired stage and one rejected stage.')]);
                }
            }

            $stage ??= new PipelineStage;
            $position = $attributes['position'] ?? null;

            if ($position === null) {
                $position = ((int) PipelineStage::query()->where('pipeline_id', $pipeline->id)->max('position')) + 10;
            }

            $stage->fill(['pipeline_id' => $pipeline->id, 'name' => $attributes['name'], 'type' => $type, 'position' => $position]);

            try {
                $stage->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => __('This pipeline already has a stage with that name.')]);
            }

            return $stage;
        });
    }

    /** @param array{name: string, is_active?: bool} $attributes */
    public function saveReason(?RejectionReason $reason, array $attributes): RejectionReason
    {
        $reason ??= new RejectionReason;
        $reason->fill(['name' => $attributes['name'], 'is_active' => (bool) ($attributes['is_active'] ?? true)]);

        try {
            $reason->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('A rejection reason with that name already exists.')]);
        }

        return $reason;
    }

    /** The first `active` stage of a pipeline: where a new application starts. */
    public function firstStage(Pipeline|string $pipeline): PipelineStage
    {
        $pipelineId = $pipeline instanceof Pipeline ? $pipeline->id : $pipeline;
        $stage = PipelineStage::query()->where('pipeline_id', $pipelineId)->where('type', StageType::Active)
            ->orderBy('position')->first();

        return $stage ?? throw ValidationException::withMessages(['pipeline_id' => __('The pipeline needs at least one active stage.')]);
    }

    public function outcomeStage(string $pipelineId, StageType $type): ?PipelineStage
    {
        return PipelineStage::query()->where('pipeline_id', $pipelineId)->where('type', $type)->orderBy('position')->first();
    }
}
