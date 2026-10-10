<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\CRM\Actions\CrmContentReader;
use App\Modules\CRM\Actions\CustomerLifecycleManager;
use App\Modules\CRM\Actions\SegmentQuery;
use App\Modules\CRM\Actions\TimelineRecorder;
use App\Modules\CRM\Enums\CrmEntityType;
use App\Modules\CRM\Enums\CustomerLifecycleStage;
use App\Modules\CRM\Models\CustomerProfile;
use App\Modules\CRM\Models\Segment;
use App\Modules\CRM\Models\TimelineEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerLifecycleController
{
    public const string VIEW = 'crm.customers.view';

    public const string MANAGE = 'crm.customers.manage';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly CustomerLifecycleManager $customers,
        private readonly TenantContext $context,
        private readonly SegmentQuery $segments,
        private readonly TimelineRecorder $timeline,
        private readonly CrmContentReader $content,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'stage' => ['nullable', Rule::enum(CustomerLifecycleStage::class)],
            'segment' => ['nullable', 'string', 'size:26'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $segment = ($filters['segment'] ?? null) === null ? null : Segment::query()
            ->whereIn('entity_type', [CrmEntityType::Contact, CrmEntityType::Company])
            ->where('is_active', true)
            ->findOrFail($filters['segment']);

        $query = $this->records->constrain(CustomerProfile::query(), $actor, self::VIEW)
            ->with(['contact:id,first_name,last_name,email', 'company:id,name,email', 'owner.user:id,name'])
            ->when(isset($filters['stage']), fn ($query) => $query->where('stage', $filters['stage']))
            ->when($search !== '', fn ($query) => $query->where(function ($match) use ($search): void {
                $match->whereHas('contact', fn ($contacts) => $contacts->where(fn ($fields) => $fields
                    ->where('first_name', 'like', Like::contains($search))
                    ->orWhere('last_name', 'like', Like::contains($search))
                    ->orWhere('email', 'like', Like::contains($search))))
                    ->orWhereHas('company', fn ($companies) => $companies->where(fn ($fields) => $fields
                        ->where('name', 'like', Like::contains($search))
                        ->orWhere('email', 'like', Like::contains($search))));
            }));

        if ($segment?->entity_type === CrmEntityType::Contact) {
            /** @var array<string, scalar|null> $segmentFilters */
            $segmentFilters = $segment->filters;
            $query->whereHas('contact', fn ($contacts) => $this->segments->apply($contacts, CrmEntityType::Contact, $segmentFilters));
        } elseif ($segment?->entity_type === CrmEntityType::Company) {
            /** @var array<string, scalar|null> $segmentFilters */
            $segmentFilters = $segment->filters;
            $query->whereHas('company', fn ($companies) => $this->segments->apply($companies, CrmEntityType::Company, $segmentFilters));
        }

        $profiles = $query
            ->latest('last_activity_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CustomerProfile $profile): array => $this->present($profile, $actor));

        return Inertia::render('Modules/CRM/Pages/Sales/Customers/Index', [
            'customers' => $profiles,
            'filters' => ['search' => $search, 'stage' => $filters['stage'] ?? null, 'segment' => $filters['segment'] ?? null],
            'stages' => array_map(static fn (CustomerLifecycleStage $stage): string => $stage->value, CustomerLifecycleStage::cases()),
            'segments' => Segment::query()->whereIn('entity_type', [CrmEntityType::Contact, CrmEntityType::Company])
                ->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, CustomerProfile $customerProfile): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::VIEW, $customerProfile);
        $customerProfile->load(['contact:id,first_name,last_name,email', 'company:id,name,email', 'owner.user:id,name']);
        $type = $customerProfile->contact_id !== null ? CrmEntityType::Contact : CrmEntityType::Company;
        $entityId = $customerProfile->contact_id ?? $customerProfile->company_id ?? abort(404);
        $timeline = $type === CrmEntityType::Contact
            ? $this->timeline->forContact($entityId)
            : $this->timeline->forCompany($entityId);

        return Inertia::render('Modules/CRM/Pages/Sales/Customers/Show', [
            'customer' => $this->present($customerProfile, $actor),
            'entityType' => $type->value,
            'entityId' => $entityId,
            'content' => $this->content->for($type, $entityId),
            'timeline' => $timeline->map(fn (TimelineEntry $entry): array => [
                'id' => $entry->id,
                'event' => $entry->event,
                'summary' => $entry->summary,
                'actor' => $entry->actor?->user?->name,
                'metadata' => $entry->metadata,
                'occurredAt' => $entry->occurred_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function update(Request $request, CustomerProfile $customerProfile): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $this->records->authorize($actor, self::MANAGE, $customerProfile);
        $input = $request->validate([
            'stage' => ['required', Rule::enum(CustomerLifecycleStage::class)],
            'next_follow_up_at' => ['nullable', 'date'],
        ]);
        $this->customers->setStage(
            $customerProfile,
            CustomerLifecycleStage::from((string) $input['stage']),
            isset($input['next_follow_up_at'])
                ? CarbonImmutable::parse((string) $input['next_follow_up_at'], $this->context->require()->timezone)->utc()
                : null,
        );
        Toast::success(__('Customer lifecycle saved.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function present(CustomerProfile $profile, User $actor): array
    {
        return [
            'id' => $profile->id,
            'stage' => $profile->stage->value,
            'contact' => $profile->contact === null ? null : [
                'id' => $profile->contact->id,
                'name' => $profile->contact->fullName(),
                'email' => $profile->contact->email,
            ],
            'company' => $profile->company?->only(['id', 'name', 'email']),
            'lifetimeValues' => $profile->lifetime_values ?? [],
            'firstWonAt' => $profile->first_won_at?->toIso8601String(),
            'lastWonAt' => $profile->last_won_at?->toIso8601String(),
            'lastActivityAt' => $profile->last_activity_at?->toIso8601String(),
            'nextFollowUpAt' => $profile->next_follow_up_at?->toIso8601String(),
            'owner' => $profile->owner === null ? null : ['id' => $profile->owner->id, 'name' => $profile->owner->user->name],
            'editable' => $this->records->allows($actor, self::MANAGE, $profile),
        ];
    }
}
