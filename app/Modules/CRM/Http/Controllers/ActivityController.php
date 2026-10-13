<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\CRM\Actions\ActivityManager;
use App\Modules\CRM\Actions\SegmentQuery;
use App\Modules\CRM\Enums\ActivityPriority;
use App\Modules\CRM\Enums\ActivityStatus;
use App\Modules\CRM\Enums\ActivityType;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Models\Activity;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Segment;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ActivityController
{
    public const string VIEW = 'crm.activities.view';

    public const string EDIT = 'crm.activities.edit';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly CrmControllerSupport $support,
        private readonly ActivityManager $activities,
        private readonly TenantContext $context,
        private readonly SegmentQuery $segments,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $input = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(ActivityStatus::class)], 'type' => ['nullable', Rule::enum(ActivityType::class)], 'overdue' => ['nullable', 'boolean'], 'segment' => ['nullable', 'string', 'size:26'], 'create_for' => ['nullable', 'string', 'size:26']]);
        $prefill = $actor->can(self::EDIT) ? $this->support->prefillContact($input['create_for'] ?? null, $actor) : null;
        $search = trim((string) ($input['search'] ?? ''));
        $segment = ($input['segment'] ?? null) === null ? null : Segment::query()
            ->where('entity_type', CrmEntityType::Activity)->where('is_active', true)->findOrFail($input['segment']);
        $query = $this->records->constrain(Activity::query(), $actor, self::VIEW)
            ->with(['lead:id,title', 'deal:id,title', 'contact:id,first_name,last_name', 'company:id,name', 'owner.user:id,name']);

        if ($segment !== null) {
            /** @var array<string, scalar|null> $segmentFilters */
            $segmentFilters = $segment->filters;
            $this->segments->apply($query, CrmEntityType::Activity, $segmentFilters);
        }

        $activities = $query
            ->when($search !== '', fn ($query) => $query->where('subject', 'like', Like::contains($search)))
            ->when($input['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($input['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when(($input['overdue'] ?? false) === true, fn ($query) => $query->where('status', ActivityStatus::Planned)->where('due_at', '<', now()))
            ->orderByRaw('due_at is null')->orderBy('due_at')->latest('created_at')->paginate(25)->withQueryString()->through(fn (Activity $activity): array => $this->present($activity, $actor));

        return Inertia::render('Modules/CRM/Pages/Activities/Index', ['activities' => $activities, 'filters' => ['search' => $search, 'status' => $input['status'] ?? null, 'type' => $input['type'] ?? null, 'overdue' => $input['overdue'] ?? false, 'segment' => $input['segment'] ?? null], 'segments' => Segment::query()->where('entity_type', CrmEntityType::Activity)->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'leads' => $this->support->leadOptions($actor), 'deals' => $this->support->dealOptions($actor), 'contacts' => $this->support->contactOptions($actor, $prefill), 'companies' => $this->support->companyOptions($actor), 'owners' => $this->support->ownerOptions($actor, self::EDIT), 'canCreate' => $actor->can(self::EDIT), 'prefillContact' => $prefill]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $this->validated($request, $actor);
        $owner = $this->support->ownerForCreate($input, $actor, self::EDIT);
        $this->activities->create($this->attributes($input), $owner, $this->support->membership($actor));
        Toast::success(__('Activity created.'));

        return back();
    }

    public function update(Request $request, Activity $activity): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::EDIT, $activity);
        $input = $this->validated($request, $actor);
        $this->activities->update($activity, $this->attributes($input), $this->support->membership($actor));
        Toast::success(__('Activity saved.'));

        return back();
    }

    public function complete(Request $request, Activity $activity): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::EDIT, $activity);
        $this->activities->complete($activity, $this->support->membership($actor));
        Toast::success(__('Activity completed.'));

        return back();
    }

    public function cancel(Request $request, Activity $activity): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::EDIT, $activity);
        $this->activities->cancel($activity, $this->support->membership($actor));
        Toast::success(__('Activity canceled.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, User $actor): array
    {
        $input = $request->validate([
            'type' => ['required', Rule::enum(ActivityType::class)], 'priority' => ['required', Rule::enum(ActivityPriority::class)], 'subject' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:10000'],
            'starts_at' => ['nullable', 'date'], 'due_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'remind_at' => ['nullable', 'date', 'before_or_equal:due_at'],
            'lead_id' => ['nullable', 'string', 'size:26'], 'deal_id' => ['nullable', 'string', 'size:26'], 'contact_id' => ['nullable', 'string', 'size:26'], 'company_id' => ['nullable', 'string', 'size:26'], 'owner_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);
        $input['contact_id'] = $this->support->contact($input['contact_id'] ?? null, $actor)?->id;
        $input['company_id'] = $this->support->company($input['company_id'] ?? null, $actor)?->id;
        $input['lead_id'] = ($input['lead_id'] ?? null) === null ? null : $this->records->constrain(Lead::query(), $actor, LeadController::VIEW)->findOrFail($input['lead_id'])->id;
        $input['deal_id'] = ($input['deal_id'] ?? null) === null ? null : $this->records->constrain(Deal::query(), $actor, DealController::VIEW)->findOrFail($input['deal_id'])->id;

        $timezone = $this->context->require()->timezone;

        foreach (['starts_at', 'due_at', 'remind_at'] as $field) {
            if (($input[$field] ?? null) !== null) {
                $input[$field] = CarbonImmutable::parse((string) $input[$field], $timezone)->utc();
            }
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
    private function present(Activity $activity, User $actor): array
    {
        return ['id' => $activity->id, 'type' => $activity->type->value, 'status' => $activity->status->value, 'priority' => $activity->priority->value, 'subject' => $activity->subject, 'description' => $activity->description, 'startsAt' => $activity->starts_at?->toIso8601String(), 'dueAt' => $activity->due_at?->toIso8601String(), 'remindAt' => $activity->remind_at?->toIso8601String(), 'completedAt' => $activity->completed_at?->toIso8601String(), 'lead' => $activity->lead?->only(['id', 'title']), 'deal' => $activity->deal?->only(['id', 'title']), 'contact' => $activity->contact === null ? null : ['id' => $activity->contact->id, 'name' => $activity->contact->fullName()], 'company' => $activity->company?->only(['id', 'name']), 'owner' => $activity->owner === null ? null : ['id' => $activity->owner->id, 'name' => $activity->owner->user->name], 'editable' => $this->records->allows($actor, self::EDIT, $activity), 'createdAt' => $activity->created_at->toIso8601String()];
    }
}
