<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\CRM\Actions\CrmContentManager;
use App\Modules\CRM\Actions\PipelineManager;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Enums\CustomFieldType;
use App\Modules\CRM\Models\CustomField;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\CRM\Models\PipelineStage;
use App\Modules\CRM\Models\Segment;
use App\Modules\CRM\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class CrmSettingsController
{
    public function __construct(private readonly PipelineManager $pipelines, private readonly CrmContentManager $content, private readonly CrmControllerSupport $support) {}

    public function index(): Response
    {
        return Inertia::render('Modules/CRM/Pages/Settings/Index', [
            'pipelines' => Pipeline::query()->with('stages')->orderByDesc('is_default')->orderBy('name')->get()->map(fn (Pipeline $pipeline): array => ['id' => $pipeline->id, 'name' => $pipeline->name, 'default' => $pipeline->is_default, 'active' => $pipeline->is_active, 'stages' => $pipeline->stages->map(fn (PipelineStage $stage): array => ['id' => $stage->id, 'name' => $stage->name, 'probability' => $stage->probability, 'position' => $stage->position, 'won' => $stage->is_won, 'lost' => $stage->is_lost])->all()])->all(),
            'sources' => LeadSource::query()->orderBy('position')->orderBy('name')->get()->map(fn (LeadSource $source): array => ['id' => $source->id, 'name' => $source->name, 'description' => $source->description, 'active' => $source->is_active, 'position' => $source->position])->all(),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name', 'colour']),
            'customFields' => CustomField::query()->orderBy('entity_type')->orderBy('position')->get()->map(fn (CustomField $field): array => ['id' => $field->id, 'entityType' => $field->entity_type->value, 'key' => $field->key, 'name' => $field->name, 'type' => $field->type->value, 'required' => $field->is_required, 'options' => $field->options, 'active' => $field->is_active, 'position' => $field->position])->all(),
            'segments' => Segment::query()->latest()->get()->map(fn (Segment $segment): array => ['id' => $segment->id, 'name' => $segment->name, 'entityType' => $segment->entity_type->value, 'filters' => $segment->filters, 'active' => $segment->is_active])->all(),
        ]);
    }

    public function storePipeline(Request $request): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:120'], 'default' => ['sometimes', 'boolean'], 'stages' => ['required', 'array', 'min:1', 'max:30'], 'stages.*.name' => ['required', 'string', 'max:100'], 'stages.*.probability' => ['required', 'integer', 'between:0,100'], 'stages.*.is_won' => ['sometimes', 'boolean'], 'stages.*.is_lost' => ['sometimes', 'boolean']]);
        $this->pipelines->create($input['name'], $input['stages'], (bool) ($input['default'] ?? false));
        Toast::success(__('Pipeline created.'));

        return back();
    }

    public function updatePipeline(Request $request, Pipeline $pipeline): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:120'], 'active' => ['required', 'boolean'], 'default' => ['required', 'boolean']]);
        $this->pipelines->update($pipeline, $input['name'], $input['active'], $input['default']);
        Toast::success(__('Pipeline saved.'));

        return back();
    }

    public function storeStage(Request $request, Pipeline $pipeline): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:100'], 'probability' => ['required', 'integer', 'between:0,100'], 'is_won' => ['sometimes', 'boolean'], 'is_lost' => ['sometimes', 'boolean']]);
        $this->pipelines->addStage($pipeline, $input);
        Toast::success(__('Pipeline stage added.'));

        return back();
    }

    public function storeSource(Request $request): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'], 'position' => ['sometimes', 'integer', 'between:0,65535']]);
        $this->pipelines->createSource([...$input, 'is_active' => true]);
        Toast::success(__('Lead source created.'));

        return back();
    }

    public function storeTag(Request $request): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:80'], 'colour' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_-]{0,23}$/']]);
        $this->content->createTag($input['name'], $input['colour'] ?? null);
        Toast::success(__('Tag created.'));

        return back();
    }

    public function storeCustomField(Request $request): RedirectResponse
    {
        $input = $request->validate(['entity_type' => ['required', Rule::in([CrmEntityType::Lead->value, CrmEntityType::Deal->value, CrmEntityType::Contact->value, CrmEntityType::Company->value])], 'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:60'], 'name' => ['required', 'string', 'max:100'], 'type' => ['required', Rule::enum(CustomFieldType::class)], 'required' => ['sometimes', 'boolean'], 'options' => ['nullable', 'array', 'max:100'], 'options.*' => ['required', 'string', 'max:100'], 'position' => ['sometimes', 'integer', 'between:0,65535']]);

        if ($input['type'] === CustomFieldType::Select->value && ($input['options'] ?? []) === []) {
            throw ValidationException::withMessages(['options' => __('A select field needs at least one option.')]);
        }

        $this->content->createCustomField(['entity_type' => $input['entity_type'], 'key' => $input['key'], 'name' => $input['name'], 'type' => $input['type'], 'is_required' => (bool) ($input['required'] ?? false), 'options' => $input['options'] ?? null, 'is_active' => true, 'position' => $input['position'] ?? 0]);
        Toast::success(__('Custom field created.'));

        return back();
    }

    public function storeSegment(Request $request): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:120'], 'entity_type' => ['required', Rule::enum(CrmEntityType::class)], 'filters' => ['required', 'array', 'max:20']]);

        foreach ($input['filters'] as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw ValidationException::withMessages(['filters' => __('Segment filters must contain simple values.')]);
            }
        }

        /** @var array<string, scalar|null> $filters */
        $filters = $input['filters'];
        $this->content->createSegment($input['name'], CrmEntityType::from($input['entity_type']), $filters, $this->support->membership($this->support->actor($request)));
        Toast::success(__('Segment created.'));

        return back();
    }
}
