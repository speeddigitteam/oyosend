<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Contact;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\WhatsappMarketing\Actions\ListManager;
use App\Modules\WhatsappMarketing\Http\Presenter;
use App\Modules\WhatsappMarketing\Models\ContactList;
use App\Modules\WhatsappMarketing\Models\ListMember;
use App\Modules\WhatsappMarketing\Support\Permissions;
use App\Modules\WhatsappMarketing\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Contact lists, and who is on them with the opt-in answer the communication platform gives today. */
final class ListController
{
    public function __construct(
        private readonly ListManager $lists,
        private readonly MarketingAudience $audience,
        private readonly RecordAccess $records,
        private readonly Presenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->presenter->actor($request);

        return Inertia::render('Modules/WhatsappMarketing/Pages/Lists/Index', [
            'lists' => ContactList::query()->withCount('members')->orderBy('name')->limit(500)->get()
                ->map(fn (ContactList $list): array => [
                    'id' => $list->id,
                    'name' => $list->name,
                    'description' => $list->description,
                    'memberCount' => (int) $list->getAttribute('members_count'),
                ])->all(),
            'canManage' => $actor->can(Permissions::LISTS_MANAGE),
        ]);
    }

    public function show(Request $request, ContactList $list): Response
    {
        $actor = $this->presenter->actor($request);
        $search = trim((string) $request->query('search', ''));

        // Members are contacts: each one only for someone who may see that contact; the rest are counted.
        $visible = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->select('id');
        $hidden = max(0, $this->lists->count($list) - ListMember::query()->where('list_id', $list->id)->whereIn('contact_id', $visible)->count());

        $members = ListMember::query()
            ->where('list_id', $list->id)
            ->whereIn('contact_id', $visible)
            ->with('contact:id,first_name,last_name,phone')
            ->whereHas('contact')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $decisions = $this->audience->decideMany(
            $members->getCollection()->pluck('contact_id')->all(),
            MarketingChannel::Whatsapp,
        );

        $manage = $actor->can(Permissions::LISTS_MANAGE);
        $candidates = [];

        if ($manage && $search !== '') {
            $like = Like::contains($search);
            $candidates = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
                ->whereNotNull('phone')
                ->whereNotIn('id', ListMember::query()->where('list_id', $list->id)->select('contact_id'))
                ->where(fn ($match) => $match->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('phone', 'like', $like))
                ->orderBy('first_name')->limit(20)->get(['id', 'first_name', 'last_name', 'phone'])
                ->map(fn (Contact $contact): array => [...$this->presenter->contact($contact), 'validPhone' => Phone::e164($contact->phone) !== null])
                ->all();
        }

        return Inertia::render('Modules/WhatsappMarketing/Pages/Lists/Show', [
            'list' => ['id' => $list->id, 'name' => $list->name, 'description' => $list->description, 'memberCount' => $this->lists->count($list), 'cap' => $this->lists->cap()],
            'members' => $members->through(function (ListMember $member) use ($decisions): array {
                $contact = $member->contact;
                $decision = $decisions[$member->contact_id];

                return [
                    'id' => $member->id,
                    'contactId' => $member->contact_id,
                    'name' => $contact?->fullName(),
                    'phone' => $contact?->phone,
                    'validPhone' => Phone::e164($contact?->phone) !== null,
                    // The platform's answer now; it is checked again when a message is sent.
                    'canReceive' => $decision->allowed,
                    'reason' => $decision->reason->value,
                ];
            }),
            'hiddenMembers' => $hidden,
            'candidates' => $candidates,
            'search' => $search,
            'canManage' => $manage,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $list = $this->lists->save(null, $this->validated($request), $this->presenter->membership($actor)->id);
        Toast::success(__('List :name created.', ['name' => $list->name]));

        return redirect()->route('whatsapp_marketing.lists.show', $list);
    }

    public function update(Request $request, ContactList $list): RedirectResponse
    {
        $this->lists->save($list, $this->validated($request));
        Toast::success(__('List saved.'));

        return back();
    }

    public function destroy(ContactList $list): RedirectResponse
    {
        $list->delete();
        Toast::success(__('List deleted.'));

        return redirect()->route('whatsapp_marketing.lists.index');
    }

    public function addMembers(Request $request, ContactList $list): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $input = $request->validate(['contact_ids' => ['required', 'array', 'min:1', 'max:500'], 'contact_ids.*' => ['string', 'size:26']]);
        $added = $this->lists->addMembers($list, $input['contact_ids'], $actor, $this->presenter->membership($actor));
        Toast::success(trans_choice('{0} Nobody was added.|{1} One contact added.|[2,*] :count contacts added.', $added, ['count' => $added]));

        return back();
    }

    public function addAll(Request $request, ContactList $list): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $added = $this->lists->addAllVisible($list, $actor, $this->presenter->membership($actor));
        Toast::success(trans_choice('{0} Nobody was added.|{1} One contact added.|[2,*] :count contacts added.', $added, ['count' => $added]));

        return back();
    }

    public function removeMember(ContactList $list, ListMember $member): RedirectResponse
    {
        abort_unless($member->list_id === $list->id, 404);
        $this->lists->remove($member);
        Toast::success(__('Contact removed from the list.'));

        return back();
    }

    /** @return array{name: string, description?: string|null} */
    private function validated(Request $request): array
    {
        /** @var array{name: string, description?: string|null} */
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:300'],
        ]);
    }
}
