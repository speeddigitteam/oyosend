<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Contacts\Models\Contact;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\EmailMarketing\Actions\AudienceBuilder;
use App\Modules\EmailMarketing\Actions\ListManager;
use App\Modules\EmailMarketing\Actions\SubscriberExporter;
use App\Modules\EmailMarketing\Actions\SubscriberManager;
use App\Modules\EmailMarketing\Enums\ConsentMethod;
use App\Modules\EmailMarketing\Enums\ListStatus;
use App\Modules\EmailMarketing\Enums\SubscriptionSource;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Segment;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Support\SegmentRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Lists, their saved segments and the members of one list. */
final class ListController
{
    public function __construct(
        private readonly ListManager $lists,
        private readonly SubscriberManager $subscribers,
        private readonly SubscriberExporter $exporter,
        private readonly AudienceBuilder $audience,
        private readonly MarketingAudience $platform,
        private readonly RecordAccess $records,
        private readonly EmailMarketingSupport $support,
        private readonly EmailMarketingPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);

        $page = MailingList::query()->orderBy('name')->paginate(25);
        $counts = Subscription::query()
            ->whereIn('list_id', $page->getCollection()->pluck('id'))
            ->selectRaw('list_id, status, count(*) as total')
            ->groupBy('list_id', 'status')
            ->get();

        $byList = [];

        foreach ($counts as $row) {
            $byList[$row->list_id][$row->status->value] = (int) $row->getAttribute('total');
        }

        return Inertia::render('Modules/EmailMarketing/Pages/Lists/Index', [
            'lists' => $page->through(fn (MailingList $list): array => $this->presenter->list($list, $byList[$list->id] ?? [])),
            'canManage' => $actor->can('email_marketing.lists.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->support->actor($request);
        $list = $this->lists->create($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]));
        Toast::success(__('List created.'));

        return redirect()->route('email_marketing.lists.show', ['list' => $list->id]);
    }

    public function show(Request $request, string $list): Response
    {
        $actor = $this->support->actor($request);
        $record = MailingList::query()->findOrFail($list);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
            'segment' => ['nullable', 'string', 'size:26'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $segments = Segment::query()->where('list_id', $record->id)->orderBy('name')->get();
        $segment = isset($filters['segment']) ? $segments->firstWhere('id', $filters['segment']) : null;

        $members = Subscription::query()
            ->with(['contact', 'list'])
            ->whereHas('contact')
            ->where('list_id', $record->id)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($segment !== null, fn ($query) => SegmentRules::apply($query, $segment->rules, $segment->match))
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->whereHas('contact', function ($match) use ($term): void {
                $match->where('email', 'like', Like::contains($term))
                    ->orWhere('first_name', 'like', Like::contains($term))
                    ->orWhere('last_name', 'like', Like::contains($term));
            }))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $decisions = $this->platform->decideMany($members->getCollection()->pluck('contact_id')->unique()->values()->all(), MarketingChannel::Email);

        $counts = Subscription::query()->where('list_id', $record->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total): int => (int) $total)->all();

        return Inertia::render('Modules/EmailMarketing/Pages/Lists/Show', [
            'list' => $this->presenter->list($record, $counts),
            'members' => $members->through(fn (Subscription $member): array => $this->presenter->subscription($member, $decisions[$member->contact_id] ?? null)),
            'segments' => $segments->map(fn (Segment $item): array => $this->presenter->segment($item, $this->audience->count($record->id, $item)))->values()->all(),
            'filters' => ['status' => $filters['status'] ?? null, 'segment' => $filters['segment'] ?? null, 'q' => $filters['q'] ?? null],
            'statuses' => array_map(static fn (SubscriptionStatus $status): string => $status->value, SubscriptionStatus::cases()),
            'consentMethods' => array_map(static fn (ConsentMethod $method): string => $method->value, ConsentMethod::cases()),
            'segmentFields' => array_keys(SegmentRules::FIELDS),
            'segmentOperators' => SegmentRules::FIELDS,
            'canManage' => $actor->can('email_marketing.lists.manage'),
            'canManageSubscribers' => $actor->can('email_marketing.subscribers.manage'),
            'canExport' => $actor->can('email_marketing.subscribers.export'),
        ]);
    }

    public function update(Request $request, string $list): RedirectResponse
    {
        $this->support->actor($request);
        $record = MailingList::query()->findOrFail($list);
        $this->lists->update($record, $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::enum(ListStatus::class)],
        ]));
        Toast::success(__('List saved.'));

        return back();
    }

    public function destroy(Request $request, string $list): RedirectResponse
    {
        $this->support->actor($request);
        $this->lists->delete(MailingList::query()->findOrFail($list));
        Toast::success(__('List deleted.'));

        return redirect()->route('email_marketing.lists.index');
    }

    public function storeSegment(Request $request, string $list): RedirectResponse
    {
        $this->support->actor($request);
        $this->lists->createSegment(MailingList::query()->findOrFail($list), $this->segmentInput($request));
        Toast::success(__('Segment saved.'));

        return back();
    }

    public function updateSegment(Request $request, string $segment): RedirectResponse
    {
        $this->support->actor($request);
        $this->lists->updateSegment(Segment::query()->findOrFail($segment), $this->segmentInput($request));
        Toast::success(__('Segment saved.'));

        return back();
    }

    public function destroySegment(Request $request, string $segment): RedirectResponse
    {
        $this->support->actor($request);
        $this->lists->deleteSegment(Segment::query()->findOrFail($segment));
        Toast::success(__('Segment deleted.'));

        return back();
    }

    public function addSubscriber(Request $request, string $list): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = MailingList::query()->findOrFail($list);

        $data = $request->validate([
            'contact_id' => ['required', 'string', 'size:26'],
            'consent_method' => ['nullable', Rule::enum(ConsentMethod::class)],
            'consent_evidence' => ['nullable', 'string', 'max:500'],
            'consented_at' => ['nullable', 'date'],
            'properties' => ['nullable', 'array', 'max:'.(int) config('email_marketing.import.max_properties', 10)],
            'properties.*' => ['nullable', 'string', 'max:190'],
        ]);

        $contact = Contact::query()->find($data['contact_id']);
        abort_if($contact === null, 404);
        // A contact outside the member's contact scope is not disclosed to exist.
        $this->records->authorize($actor, 'core.contacts.view', $contact);

        $subscription = $this->subscribers->add($record, $contact->id, $data, SubscriptionSource::Manual, $this->support->membership($actor));

        Toast::success($subscription->status === SubscriptionStatus::Subscribed
            ? __('Subscriber added.')
            : __('Added as pending. They will not receive email until consent is recorded.'));

        return back();
    }

    public function export(Request $request, string $list): StreamedResponse
    {
        $this->support->actor($request);
        $record = MailingList::query()->findOrFail($list);
        $csv = $this->exporter->csv($record);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, 'subscribers-'.preg_replace('/[^a-z0-9]+/i', '-', $record->name).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function segmentInput(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'match' => ['required', Rule::in(['all', 'any'])],
            'rules' => ['required', 'array', 'min:1', 'max:'.SegmentRules::MAX_RULES],
            'rules.*' => ['array'],
            'rules.*.field' => ['required', 'string', 'max:50'],
            'rules.*.operator' => ['required', 'string', 'max:20'],
            'rules.*.value' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
