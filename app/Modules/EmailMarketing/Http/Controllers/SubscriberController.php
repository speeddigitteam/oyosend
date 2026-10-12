<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Contacts\Models\Contact;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\EmailMarketing\Actions\SubscriberManager;
use App\Modules\EmailMarketing\Enums\ConsentMethod;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Members across all lists, and changes to one membership. */
final class SubscriberController
{
    public function __construct(
        private readonly SubscriberManager $subscribers,
        private readonly MarketingAudience $platform,
        private readonly RecordAccess $records,
        private readonly EmailMarketingSupport $support,
        private readonly EmailMarketingPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
            'list_id' => ['nullable', 'string', 'size:26'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $members = Subscription::query()
            ->with(['contact', 'list'])
            ->whereHas('contact')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['list_id'] ?? null, fn ($query, string $id) => $query->where('list_id', $id))
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->whereHas('contact', function ($match) use ($term): void {
                $match->where('email', 'like', Like::contains($term))
                    ->orWhere('first_name', 'like', Like::contains($term))
                    ->orWhere('last_name', 'like', Like::contains($term));
            }))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $decisions = $this->platform->decideMany($members->getCollection()->pluck('contact_id')->unique()->values()->all(), MarketingChannel::Email);

        return Inertia::render('Modules/EmailMarketing/Pages/Subscribers/Index', [
            'members' => $members->through(fn (Subscription $member): array => $this->presenter->subscription($member, $decisions[$member->contact_id] ?? null)),
            'filters' => ['status' => $filters['status'] ?? null, 'list_id' => $filters['list_id'] ?? null, 'q' => $filters['q'] ?? null],
            'statuses' => array_map(static fn (SubscriptionStatus $status): string => $status->value, SubscriptionStatus::cases()),
            'lists' => $this->support->listOptions(false),
            'consentMethods' => array_map(static fn (ConsentMethod $method): string => $method->value, ConsentMethod::cases()),
            'canManage' => $actor->can('email_marketing.subscribers.manage'),
        ]);
    }

    /** Contacts to pick from when adding a member: a bounded name/email search over the shared directory. */
    public function contacts(Request $request): JsonResponse
    {
        $actor = $this->support->actor($request);
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);

        // Only contacts the member may see in the shared directory (their `core.contacts.view` scope).
        $contacts = $this->records->constrain(Contact::query(), $actor, 'core.contacts.view')
            ->where(function ($match) use ($term): void {
                $match->where('email', 'like', Like::contains($term))
                    ->orWhere('first_name', 'like', Like::contains($term))
                    ->orWhere('last_name', 'like', Like::contains($term));
            })
            ->whereNotNull('email')
            ->orderBy('first_name')
            ->limit(10)
            ->get();

        return response()->json($contacts->map(fn (Contact $contact): array => [
            'id' => $contact->id,
            'name' => $contact->fullName(),
            'email' => $contact->email,
        ])->all());
    }

    public function update(Request $request, string $subscription): RedirectResponse
    {
        $this->support->actor($request);
        $record = Subscription::query()->findOrFail($subscription);

        $data = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'properties'])],
            'consent_method' => ['nullable', Rule::enum(ConsentMethod::class)],
            'consent_evidence' => ['nullable', 'string', 'max:500'],
            'consented_at' => ['nullable', 'date'],
            'properties' => ['nullable', 'array', 'max:'.(int) config('email_marketing.import.max_properties', 10)],
            'properties.*' => ['nullable', 'string', 'max:190'],
        ]);

        if ($data['action'] === 'confirm') {
            $this->subscribers->confirm($record, $data);
            Toast::success(__('Consent recorded. The member is subscribed.'));
        } else {
            $this->subscribers->updateProperties($record, $data['properties'] ?? []);
            Toast::success(__('Properties saved.'));
        }

        return back();
    }

    public function unsubscribe(Request $request, string $subscription): RedirectResponse
    {
        $this->support->actor($request);
        $this->subscribers->unsubscribe(Subscription::query()->findOrFail($subscription));
        Toast::success(__('Member unsubscribed from this list.'));

        return back();
    }

    public function destroy(Request $request, string $subscription): RedirectResponse
    {
        $this->support->actor($request);
        $this->subscribers->remove(Subscription::query()->findOrFail($subscription));
        Toast::success(__('Member removed from the list.'));

        return back();
    }
}
