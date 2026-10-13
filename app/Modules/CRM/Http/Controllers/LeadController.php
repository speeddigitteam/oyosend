<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\CRM\Actions\CrmContentReader;
use App\Modules\CRM\Actions\LeadManager;
use App\Modules\CRM\Actions\SegmentQuery;
use App\Modules\CRM\Actions\TimelineRecorder;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\Segment;
use App\Modules\CRM\Models\TimelineEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class LeadController
{
    public const string VIEW = 'crm.leads.view';

    public const string EDIT = 'crm.leads.edit';

    public const string QUALIFY = 'crm.leads.qualify';

    public const string ASSIGN = 'crm.leads.assign';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly CrmControllerSupport $support,
        private readonly LeadManager $leads,
        private readonly TimelineRecorder $timeline,
        private readonly CrmContentReader $content,
        private readonly SegmentQuery $segments,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $input = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(LeadStatus::class)], 'source' => ['nullable', 'string', 'size:26'], 'segment' => ['nullable', 'string', 'size:26'], 'create_for' => ['nullable', 'string', 'size:26']]);
        $prefill = $actor->can(self::EDIT) ? $this->support->prefillContact($input['create_for'] ?? null, $actor) : null;
        $search = trim((string) ($input['search'] ?? ''));
        $segment = ($input['segment'] ?? null) === null ? null : Segment::query()
            ->where('entity_type', CrmEntityType::Lead)->where('is_active', true)->findOrFail($input['segment']);
        $query = $this->records->constrain(Lead::query(), $actor, self::VIEW)
            ->with(['contact:id,first_name,last_name,email,phone', 'company:id,name', 'source:id,name', 'owner.user:id,name']);

        if ($segment !== null) {
            /** @var array<string, scalar|null> $segmentFilters */
            $segmentFilters = $segment->filters;
            $this->segments->apply($query, CrmEntityType::Lead, $segmentFilters);
        }

        $leads = $query
            ->when($search !== '', fn ($query) => $query->where('title', 'like', Like::contains($search)))
            ->when($input['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($input['source'] ?? null, fn ($query, string $source) => $query->where('source_id', $source))
            ->latest()->paginate(25)->withQueryString()->through(fn (Lead $lead): array => $this->present($lead, $actor));

        return Inertia::render('Modules/CRM/Pages/Leads/Index', [
            'leads' => $leads,
            'filters' => ['search' => $search, 'status' => $input['status'] ?? null, 'source' => $input['source'] ?? null, 'segment' => $input['segment'] ?? null],
            'segments' => Segment::query()->where('entity_type', CrmEntityType::Lead)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'sources' => LeadSource::query()->where('is_active', true)->orderBy('position')->get(['id', 'name']),
            'contacts' => $this->support->contactOptions($actor, $prefill),
            'companies' => $this->support->companyOptions($actor),
            'owners' => $this->support->ownerOptions($actor, self::ASSIGN),
            'canCreate' => $actor->can(self::EDIT),
            'prefillContact' => $prefill,
        ]);
    }

    public function show(Request $request, Lead $lead): Response
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::VIEW, $lead);
        $lead->load(['contact:id,first_name,last_name,email,phone', 'company:id,name', 'source:id,name', 'owner.user:id,name']);
        $deals = $actor->can(DealController::VIEW)
            ? $this->records->constrain(Deal::query()->where('lead_id', $lead->id), $actor, DealController::VIEW)
                ->with('stage:id,name')->get()
            : collect();

        return Inertia::render('Modules/CRM/Pages/Leads/Show', [
            'lead' => $this->present($lead, $actor),
            'deals' => $deals->map(fn (Deal $deal): array => ['id' => $deal->id, 'title' => $deal->title, 'status' => $deal->status->value, 'amount' => $deal->amount, 'currency' => $deal->currency, 'stage' => $deal->stage->name])->all(),
            'timeline' => $this->timeline->forLead($lead->id)->map($this->presentTimeline(...))->all(),
            'content' => $this->content->for(CrmEntityType::Lead, $lead->id),
            'sources' => LeadSource::query()->where('is_active', true)->orderBy('position')->get(['id', 'name']),
            'contacts' => $this->support->contactOptions($actor),
            'companies' => $this->support->companyOptions($actor),
            'owners' => $this->support->ownerOptions($actor, self::ASSIGN),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $this->validated($request, $actor);
        $owner = $this->support->ownerForCreate($input, $actor, self::ASSIGN);
        $lead = $this->leads->create($this->attributes($input), $owner);
        Toast::success(__('Lead created.'));

        return redirect()->route('crm.leads.show', $lead);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::EDIT, $lead);
        $input = $this->validated($request, $actor, $lead);
        $this->leads->update($lead, $this->attributes($input), $this->support->membership($actor));
        Toast::success(__('Lead saved.'));

        return back();
    }

    public function qualify(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::QUALIFY, $lead);
        $input = $request->validate(['score' => ['required', 'integer', 'between:0,100'], 'notes' => ['nullable', 'string', 'max:5000']]);
        $this->leads->qualify($lead, $input['score'], $input['notes'] ?? null, $this->support->membership($actor));
        Toast::success(__('Lead qualified.'));

        return back();
    }

    public function assign(Request $request, Lead $lead): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::ASSIGN, $lead);
        $input = $request->validate(['owner_id' => ['nullable', 'string', 'size:26']]);
        $this->leads->assign(
            $lead,
            $this->support->assignmentTarget($input['owner_id'] ?? null, $actor, self::ASSIGN),
            $this->support->membership($actor),
        );
        Toast::success(__('Lead assignment saved.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, User $actor, ?Lead $lead = null): array
    {
        $statuses = [LeadStatus::New->value, LeadStatus::Contacted->value, LeadStatus::Disqualified->value];

        if ($lead !== null && in_array($lead->status, [LeadStatus::Qualified, LeadStatus::Converted], true)) {
            $statuses[] = $lead->status->value;
        }

        $input = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'contact_id' => ['nullable', 'string', 'size:26'], 'company_id' => ['nullable', 'string', 'size:26'],
            'source_id' => ['nullable', 'string', 'size:26'], 'status' => ['sometimes', Rule::in($statuses)],
            'owner_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);
        $input['contact_id'] = $this->support->contact($input['contact_id'] ?? null, $actor)?->id;
        $input['company_id'] = $this->support->company($input['company_id'] ?? null, $actor)?->id;

        if (($input['source_id'] ?? null) !== null) {
            $input['source_id'] = LeadSource::query()->findOrFail($input['source_id'])->id;
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributes(array $input): array
    {
        unset($input['owner_id']);

        return $input;
    }

    /** @return array<string, mixed> */
    private function present(Lead $lead, User $actor): array
    {
        return ['id' => $lead->id, 'title' => $lead->title, 'status' => $lead->status->value, 'score' => $lead->score, 'qualificationNotes' => $lead->qualification_notes, 'qualifiedAt' => $lead->qualified_at?->toIso8601String(), 'convertedAt' => $lead->converted_at?->toIso8601String(), 'contact' => $lead->contact === null ? null : ['id' => $lead->contact->id, 'name' => $lead->contact->fullName(), 'email' => $lead->contact->email, 'phone' => $lead->contact->phone], 'company' => $lead->company?->only(['id', 'name']), 'source' => $lead->source?->only(['id', 'name']), 'owner' => $lead->owner === null ? null : ['id' => $lead->owner->id, 'name' => $lead->owner->user->name], 'editable' => $this->records->allows($actor, self::EDIT, $lead), 'qualifiable' => $this->records->allows($actor, self::QUALIFY, $lead), 'assignable' => $this->records->allows($actor, self::ASSIGN, $lead), 'createdAt' => $lead->created_at->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function presentTimeline(TimelineEntry $entry): array
    {
        return ['id' => $entry->id, 'event' => $entry->event, 'summary' => $entry->summary, 'actor' => $entry->actor?->user?->name, 'metadata' => $entry->metadata, 'occurredAt' => $entry->occurred_at->toIso8601String()];
    }
}
