<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Models\Contact;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\SmsMarketing\Actions\GroupCsv;
use App\Modules\SmsMarketing\Actions\GroupManager;
use App\Modules\SmsMarketing\Actions\SmsAnalytics;
use App\Modules\SmsMarketing\Http\Requests\GroupImportRequest;
use App\Modules\SmsMarketing\Http\Requests\GroupMembersRequest;
use App\Modules\SmsMarketing\Http\Requests\GroupRequest;
use App\Modules\SmsMarketing\Models\Group;
use App\Modules\SmsMarketing\Models\GroupMember;
use App\Modules\SmsMarketing\Models\Import;
use App\Modules\SmsMarketing\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class GroupController
{
    public function __construct(
        private readonly GroupManager $groups,
        private readonly GroupCsv $csv,
        private readonly RecordAccess $records,
        private readonly SmsSupport $support,
        private readonly SmsPresenter $presenter,
        private readonly SmsAnalytics $analytics,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);

        $groups = Group::query()
            ->withCount('members')
            ->orderBy('name')
            ->paginate(25)
            ->through(fn (Group $group): array => $this->presenter->group($group));

        return Inertia::render('Modules/SmsMarketing/Pages/Groups/Index', [
            'groups' => $groups,
            'canManage' => $actor->can('sms_marketing.groups.manage'),
            'contacts' => $this->analytics->standing()['contacts'],
        ]);
    }

    public function show(Request $request, string $group): Response
    {
        $actor = $this->support->actor($request);
        $record = Group::query()->withCount('members')->findOrFail($group);
        $filters = $request->validate(['contact_q' => ['nullable', 'string', 'max:100']]);

        // A member list is a list of contacts: each one only for someone who may see that contact.
        $visible = $this->records->constrain(Contact::query(), $actor, 'core.contacts.view')->select('id');
        $hidden = $record->members_count - GroupMember::query()->where('group_id', $record->id)->whereIn('contact_id', $visible)->count();

        $members = GroupMember::query()
            ->where('group_id', $record->id)
            ->whereIn('contact_id', $visible)
            ->with('contact:id,first_name,last_name,phone,email')
            ->orderBy('id')
            ->paginate(25)
            ->through(static fn (GroupMember $member): array => [
                'id' => $member->id,
                'contactId' => $member->contact_id,
                'name' => $member->contact?->fullName() ?? '',
                'phone' => $member->contact?->phone,
                'email' => $member->contact?->email,
                'validNumber' => PhoneNumber::normalize($member->contact?->phone) !== null,
            ]);

        $term = trim((string) ($filters['contact_q'] ?? ''));
        $canManage = $actor->can('sms_marketing.groups.manage');
        $candidates = [];

        if ($canManage && mb_strlen($term) >= 2) {
            $candidates = $this->records->constrain(Contact::query(), $actor, 'core.contacts.view')
                ->whereNotIn('id', GroupMember::query()->where('group_id', $record->id)->select('contact_id'))
                ->where(function ($match) use ($term): void {
                    $like = Like::contains($term);
                    $match->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like);
                })
                ->orderBy('first_name')
                ->limit(25)
                ->get(['id', 'first_name', 'last_name', 'phone', 'email'])
                ->map(static fn (Contact $contact): array => [
                    'id' => $contact->id,
                    'name' => $contact->fullName(),
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'validNumber' => PhoneNumber::normalize($contact->phone) !== null,
                ])->all();
        }

        return Inertia::render('Modules/SmsMarketing/Pages/Groups/Show', [
            'group' => $this->presenter->group($record),
            'members' => $members,
            'hiddenMembers' => max(0, $hidden),
            'candidates' => $candidates,
            'filters' => ['contact_q' => $term === '' ? null : $term],
            'imports' => Import::query()->where('group_id', $record->id)->latest()->limit(10)->get()->map(static fn (Import $import): array => [
                'id' => $import->id,
                'fileName' => $import->file_name,
                'totalRows' => $import->total_rows,
                'addedRows' => $import->added_rows,
                'failedRows' => $import->failed_rows,
                'errors' => $import->errors ?? [],
                'createdAt' => $import->created_at?->toIso8601String(),
            ])->all(),
            'importInfo' => [
                'columns' => GroupCsv::COLUMNS,
                'maxRows' => (int) config('sms_marketing.import.max_rows'),
                'maxBytes' => (int) config('sms_marketing.import.max_bytes'),
            ],
            'contacts' => $this->analytics->standing()['contacts'],
            'canManage' => $canManage,
        ]);
    }

    public function store(GroupRequest $request): RedirectResponse
    {
        $this->support->actor($request);
        $group = $this->groups->create($request->validated());
        Toast::success(__('Group created.'));

        return redirect()->route('sms_marketing.groups.show', ['group' => $group->id]);
    }

    public function update(GroupRequest $request, string $group): RedirectResponse
    {
        $this->support->actor($request);
        $this->groups->update(Group::query()->findOrFail($group), $request->validated());
        Toast::success(__('Group saved.'));

        return back();
    }

    public function destroy(Request $request, string $group): RedirectResponse
    {
        $this->support->actor($request);
        $this->groups->delete(Group::query()->findOrFail($group));
        Toast::success(__('Group deleted.'));

        return redirect()->route('sms_marketing.groups.index');
    }

    public function addMembers(GroupMembersRequest $request, string $group): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = Group::query()->findOrFail($group);

        // Only contacts the member may see can be added.
        $visible = $this->records->constrain(Contact::query(), $actor, 'core.contacts.view')
            ->whereIn('id', $request->validated('contact_ids'))
            ->pluck('id')
            ->all();

        $result = $this->groups->addContacts($record, array_values(array_map('strval', $visible)), $this->support->membership($actor));
        Toast::success(trans_choice(':count contact added.|:count contacts added.', $result['added'], ['count' => $result['added']]));

        return back();
    }

    public function removeMember(Request $request, string $group, string $member): RedirectResponse
    {
        $this->support->actor($request);
        $record = Group::query()->findOrFail($group);
        $membership = GroupMember::query()->where('group_id', $record->id)->findOrFail($member);
        $this->groups->removeMember($record, $membership);
        Toast::success(__('Contact removed from the group.'));

        return back();
    }

    public function import(GroupImportRequest $request, string $group): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = Group::query()->findOrFail($group);
        $import = $this->csv->import($record, $request->file('file'), $actor, $this->support->membership($actor));

        if ($import->added_rows === 0) {
            Toast::warning(__('No contacts were added. See the rows below.'));
        } elseif ($import->failed_rows > 0) {
            Toast::warning(__(':added contacts added, :failed rows could not be matched.', ['added' => $import->added_rows, 'failed' => $import->failed_rows]));
        } else {
            Toast::success(trans_choice(':count contact added.|:count contacts added.', $import->added_rows, ['count' => $import->added_rows]));
        }

        return back();
    }

    public function export(Request $request, string $group): StreamedResponse
    {
        $actor = $this->support->actor($request);
        $record = Group::query()->findOrFail($group);
        $csv = $this->csv->exportMembers($record, $actor);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, 'sms-group-'.substr($record->id, -8).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function template(Request $request): StreamedResponse
    {
        $this->support->actor($request);
        $csv = GroupCsv::template();

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, 'sms-group-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
