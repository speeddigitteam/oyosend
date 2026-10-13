<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\CRM\Actions\CrmContentReader;
use App\Modules\CRM\Actions\DealManager;
use App\Modules\CRM\Actions\SalesMath;
use App\Modules\CRM\Actions\SegmentQuery;
use App\Modules\CRM\Actions\TimelineRecorder;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Enums\DealStatus;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\CRM\Models\PipelineStage;
use App\Modules\CRM\Models\Segment;
use App\Modules\CRM\Models\TimelineEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class DealController
{
    public const string VIEW = 'crm.deals.view';

    public const string EDIT = 'crm.deals.edit';

    public const string ASSIGN = 'crm.deals.assign';

    public function __construct(private readonly RecordAccess $records, private readonly CrmControllerSupport $support, private readonly DealManager $deals, private readonly TimelineRecorder $timeline, private readonly CrmContentReader $content, private readonly SegmentQuery $segments) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $input = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(DealStatus::class)], 'pipeline' => ['nullable', 'string', 'size:26'], 'stage' => ['nullable', 'string', 'size:26'], 'segment' => ['nullable', 'string', 'size:26'], 'view' => ['nullable', 'string', Rule::in(['list', 'board'])]]);
        $search = trim((string) ($input['search'] ?? ''));
        $segment = ($input['segment'] ?? null) === null ? null : Segment::query()
            ->where('entity_type', CrmEntityType::Deal)->where('is_active', true)->findOrFail($input['segment']);
        $query = $this->records->constrain(Deal::query(), $actor, self::VIEW)
            ->with(['pipeline:id,name', 'stage:id,name', 'lead:id,title', 'contact:id,first_name,last_name', 'company:id,name', 'owner.user:id,name']);

        if ($segment !== null) {
            /** @var array<string, scalar|null> $segmentFilters */
            $segmentFilters = $segment->filters;
            $this->segments->apply($query, CrmEntityType::Deal, $segmentFilters);
        }

        $deals = $query
            ->when($search !== '', fn ($query) => $query->where('title', 'like', Like::contains($search)))
            ->when($input['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($input['pipeline'] ?? null, fn ($query, string $pipeline) => $query->where('pipeline_id', $pipeline))
            ->when($input['stage'] ?? null, fn ($query, string $stage) => $query->where('stage_id', $stage))
            ->orderBy('expected_close_date')->latest('created_at')->paginate(25)->withQueryString()->through(fn (Deal $deal): array => $this->present($deal, $actor));

        $boardDeals = null;
        $boardPipelineId = $input['pipeline'] ?? null;

        if (($input['view'] ?? null) === 'board') {
            $boardPipeline = $boardPipelineId !== null
                ? Pipeline::query()->where('is_active', true)->find($boardPipelineId)
                : (Pipeline::query()->where('is_default', true)->where('is_active', true)->first()
                    ?? Pipeline::query()->where('is_active', true)->orderBy('name')->first());

            if ($boardPipeline !== null) {
                $boardPipelineId = $boardPipeline->id;
                $boardDeals = $this->records->constrain(Deal::query(), $actor, self::VIEW)
                    ->with(['pipeline:id,name', 'stage:id,name', 'lead:id,title', 'contact:id,first_name,last_name', 'company:id,name', 'owner.user:id,name'])
                    ->where('status', DealStatus::Open)
                    ->where('pipeline_id', $boardPipeline->id)
                    ->orderBy('created_at')
                    ->limit(200)
                    ->get()
                    ->map(fn (Deal $deal): array => $this->present($deal, $actor))
                    ->all();
            }
        }

        return Inertia::render('Modules/CRM/Pages/Deals/Index', ['deals' => $deals, 'filters' => ['search' => $search, 'status' => $input['status'] ?? null, 'pipeline' => $boardPipelineId ?? ($input['pipeline'] ?? null), 'stage' => $input['stage'] ?? null, 'segment' => $input['segment'] ?? null, 'view' => $input['view'] ?? null], 'segments' => Segment::query()->where('entity_type', CrmEntityType::Deal)->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'pipelines' => $this->pipelines(), 'leads' => $this->support->leadOptions($actor), 'contacts' => $this->support->contactOptions($actor), 'companies' => $this->support->companyOptions($actor), 'owners' => $this->support->ownerOptions($actor, self::ASSIGN), 'canCreate' => $actor->can(self::EDIT), 'boardDeals' => $boardDeals]);
    }

    public function show(Request $request, Deal $deal): Response
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::VIEW, $deal);
        $deal->load(['pipeline.stages', 'stage', 'lead:id,title', 'contact:id,first_name,last_name,email,phone', 'company:id,name', 'owner.user:id,name', 'stageHistory.fromStage:id,name', 'stageHistory.toStage:id,name', 'stageHistory.changedBy.user:id,name']);

        return Inertia::render('Modules/CRM/Pages/Deals/Show', [
            'deal' => $this->present($deal, $actor),
            'pipelines' => $this->pipelines(),
            'stages' => $deal->pipeline->stages->map(fn (PipelineStage $stage): array => $this->presentStage($stage))->all(),
            'history' => $deal->stageHistory->map(fn ($entry): array => ['id' => $entry->id, 'from' => $entry->fromStage?->name, 'to' => $entry->toStage->name, 'note' => $entry->note, 'actor' => $entry->changedBy?->user?->name, 'changedAt' => $entry->changed_at->toIso8601String()])->all(),
            'timeline' => $this->timeline->forDeal($deal->id)->map(fn (TimelineEntry $entry): array => ['id' => $entry->id, 'event' => $entry->event, 'summary' => $entry->summary, 'actor' => $entry->actor?->user?->name, 'metadata' => $entry->metadata, 'occurredAt' => $entry->occurred_at->toIso8601String()])->all(),
            'content' => $this->content->for(CrmEntityType::Deal, $deal->id),
            'leads' => $this->support->leadOptions($actor),
            'contacts' => $this->support->contactOptions($actor),
            'companies' => $this->support->companyOptions($actor),
            'owners' => $this->support->ownerOptions($actor, self::ASSIGN),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $this->validated($request, $actor);
        $pipeline = Pipeline::query()->where('is_active', true)->findOrFail($input['pipeline_id']);
        $stage = PipelineStage::query()->findOrFail($input['stage_id']);
        $owner = $this->support->ownerForCreate($input, $actor, self::ASSIGN);
        $deal = $this->deals->create($this->attributes($input), $pipeline, $stage, $owner, $this->support->membership($actor));
        Toast::success(__('Deal created.'));

        return redirect()->route('crm.deals.show', $deal);
    }

    public function update(Request $request, Deal $deal): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::EDIT, $deal);
        $input = $this->validated($request, $actor, updating: true);
        $this->deals->update($deal, $this->attributes($input), $this->support->membership($actor));
        Toast::success(__('Deal saved.'));

        return back();
    }

    public function move(Request $request, Deal $deal): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::EDIT, $deal);
        $input = $request->validate(['stage_id' => ['required', 'string', 'size:26'], 'note' => ['nullable', 'string', 'max:500']]);
        $stage = PipelineStage::query()->findOrFail($input['stage_id']);
        $this->deals->move($deal, $stage, $this->support->membership($actor), $input['note'] ?? null);
        Toast::success(__('Deal stage changed.'));

        return back();
    }

    public function assign(Request $request, Deal $deal): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::ASSIGN, $deal);
        $input = $request->validate(['owner_id' => ['nullable', 'string', 'size:26']]);
        $this->deals->assign(
            $deal,
            $this->support->assignmentTarget($input['owner_id'] ?? null, $actor, self::ASSIGN),
            $this->support->membership($actor),
        );
        Toast::success(__('Deal assignment saved.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, User $actor, bool $updating = false): array
    {
        $input = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'pipeline_id' => [$updating ? 'sometimes' : 'required', 'string', 'size:26'], 'stage_id' => [$updating ? 'sometimes' : 'required', 'string', 'size:26'],
            'lead_id' => ['nullable', 'string', 'size:26'], 'contact_id' => ['nullable', 'string', 'size:26'], 'company_id' => ['nullable', 'string', 'size:26'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'], 'amount' => ['required', 'integer', 'min:0', 'max:'.SalesMath::MAX_AMOUNT], 'expected_close_date' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:10000'], 'owner_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);
        $input['contact_id'] = $this->support->contact($input['contact_id'] ?? null, $actor)?->id;
        $input['company_id'] = $this->support->company($input['company_id'] ?? null, $actor)?->id;

        if (($input['lead_id'] ?? null) !== null) {
            $input['lead_id'] = $this->records->constrain(Lead::query(), $actor, LeadController::VIEW)->findOrFail($input['lead_id'])->id;
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributes(array $input): array
    {
        unset($input['owner_id'], $input['pipeline_id'], $input['stage_id']);

        return $input;
    }

    /** @return array<string, mixed> */
    private function present(Deal $deal, User $actor): array
    {
        return ['id' => $deal->id, 'title' => $deal->title, 'status' => $deal->status->value, 'amount' => $deal->amount, 'currency' => $deal->currency, 'expectedCloseDate' => $deal->expected_close_date?->toDateString(), 'wonAt' => $deal->won_at?->toIso8601String(), 'lostAt' => $deal->lost_at?->toIso8601String(), 'lostReason' => $deal->lost_reason, 'description' => $deal->description, 'pipeline' => $deal->pipeline->only(['id', 'name']), 'stage' => $deal->stage->only(['id', 'name']), 'lead' => $deal->lead?->only(['id', 'title']), 'contact' => $deal->contact === null ? null : ['id' => $deal->contact->id, 'name' => $deal->contact->fullName()], 'company' => $deal->company?->only(['id', 'name']), 'owner' => $deal->owner === null ? null : ['id' => $deal->owner->id, 'name' => $deal->owner->user->name], 'editable' => $deal->status === DealStatus::Open && $this->records->allows($actor, self::EDIT, $deal), 'assignable' => $this->records->allows($actor, self::ASSIGN, $deal), 'createdAt' => $deal->created_at->toIso8601String()];
    }

    /** @return list<array<string, mixed>> */
    private function pipelines(): array
    {
        return Pipeline::query()->where('is_active', true)->with('stages')->orderByDesc('is_default')->orderBy('name')->get()->map(fn (Pipeline $pipeline): array => ['id' => $pipeline->id, 'name' => $pipeline->name, 'stages' => $pipeline->stages->map(fn (PipelineStage $stage): array => $this->presentStage($stage))->all()])->all();
    }

    /** @return array<string, mixed> */
    private function presentStage(PipelineStage $stage): array
    {
        return ['id' => $stage->id, 'name' => $stage->name, 'probability' => $stage->probability, 'position' => $stage->position, 'won' => $stage->is_won, 'lost' => $stage->is_lost];
    }
}
