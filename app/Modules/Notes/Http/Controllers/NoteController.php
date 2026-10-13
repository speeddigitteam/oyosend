<?php

declare(strict_types=1);

namespace App\Modules\Notes\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Notes\Actions\NoteManager;
use App\Modules\Notes\Models\Note;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class NoteController
{
    public const string VIEW = 'notes.notes.view';

    public const string MANAGE = 'notes.notes.manage';

    /** The note background colours a member may choose, each a contrast-safe design token. */
    public const array COLORS = ['brand', 'accent', 'ok', 'warn', 'danger', 'info'];

    public function __construct(
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
        private readonly NoteManager $notes,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'pinned' => ['nullable', 'boolean'],
            'contact' => ['nullable', 'string', 'size:26'],
            'create_for' => ['nullable', 'string', 'size:26'],
        ]);
        $canManage = $actor->can(self::MANAGE);
        $prefill = $canManage ? $this->prefillContact($input['create_for'] ?? null, $actor) : null;
        $search = trim((string) ($input['search'] ?? ''));

        $notes = $this->records->constrain(Note::query(), $actor, self::VIEW)
            ->with(['contact:id,first_name,last_name', 'company:id,name', 'owner.user:id,name'])
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('title', 'like', Like::contains($search))
                ->orWhere('body', 'like', Like::contains($search))))
            ->when(($input['pinned'] ?? null) !== null, fn ($query) => $query->where('is_pinned', (bool) $input['pinned']))
            ->when($input['contact'] ?? null, fn ($query, string $contact) => $query->where('contact_id', $contact))
            ->orderByDesc('is_pinned')->latest()
            ->paginate(48)->withQueryString()
            ->through(fn (Note $note): array => $this->present($note, $actor));

        return Inertia::render('Modules/Notes/Pages/Index', [
            'notes' => $notes,
            'filters' => ['search' => $search, 'pinned' => $input['pinned'] ?? null, 'contact' => $input['contact'] ?? null],
            'contacts' => $this->contactOptions($actor, $prefill),
            'companies' => $this->companyOptions($actor),
            'owners' => $this->ownerOptions($actor),
            'canCreate' => $canManage,
            'prefillContact' => $prefill,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $input = $this->validated($request, $actor);
        $owner = $this->ownerForCreate($input, $actor);
        $this->notes->create($this->attributes($input), $owner);
        Toast::success(__('Note created.'));

        return redirect()->route('notes.index');
    }

    public function update(Request $request, Note $note): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->records->authorize($actor, self::MANAGE, $note);
        $input = $this->validated($request, $actor);

        if (array_key_exists('owner_id', $input) && $this->access->scopeFor($actor, self::MANAGE) === AccessScope::All) {
            $note->assignOwner($this->assignmentTarget($input['owner_id']));
        }

        $this->notes->update($note, $this->attributes($input));
        Toast::success(__('Note saved.'));

        return back();
    }

    public function destroy(Request $request, Note $note): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->records->authorize($actor, self::MANAGE, $note);
        $this->notes->delete($note);
        Toast::success(__('Note deleted.'));

        return redirect()->route('notes.index');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, User $actor): array
    {
        $input = $request->validate([
            'title' => ['nullable', 'required_without:body', 'string', 'max:200'],
            'body' => ['nullable', 'required_without:title', 'string', 'max:20000'],
            'is_pinned' => ['sometimes', 'boolean'],
            'color' => ['sometimes', 'nullable', Rule::in(self::COLORS)],
            'contact_id' => ['nullable', 'string', 'size:26'],
            'company_id' => ['nullable', 'string', 'size:26'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);
        $input['contact_id'] = $this->resolveContact($input['contact_id'] ?? null, $actor)?->id;
        $input['company_id'] = $this->resolveCompany($input['company_id'] ?? null, $actor)?->id;

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

    /** @param array<string, mixed> $input */
    private function ownerForCreate(array $input, User $actor): TenantMembership
    {
        $self = $this->membership($actor);

        if (($input['owner_id'] ?? null) === null || $this->access->scopeFor($actor, self::MANAGE) !== AccessScope::All) {
            return $self;
        }

        return $this->assignmentTarget($input['owner_id']) ?? $self;
    }

    private function membership(User $actor): TenantMembership
    {
        return $this->access->membership($actor) ?? abort(403);
    }

    private function assignmentTarget(mixed $id): ?TenantMembership
    {
        if ($id === null) {
            return null;
        }

        abort_unless(is_string($id), 404);

        return TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($id);
    }

    private function resolveContact(?string $id, User $actor): ?Contact
    {
        return $id === null ? null : $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->findOrFail($id);
    }

    private function resolveCompany(?string $id, User $actor): ?Company
    {
        return $id === null ? null : $this->records->constrain(Company::query(), $actor, ContactController::VIEW)->findOrFail($id);
    }

    /**
     * @param  array{id: string, name: string}|null  $include
     * @return list<array{id: string, name: string}>
     */
    private function contactOptions(User $actor, ?array $include = null): array
    {
        if (! $actor->can(ContactController::VIEW)) {
            return $include === null ? [] : [$include];
        }

        $options = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
            ->orderBy('first_name')->orderBy('last_name')->limit(500)->get(['id', 'first_name', 'last_name'])
            ->map(fn (Contact $contact): array => ['id' => $contact->id, 'name' => $contact->fullName()])->all();

        if ($include !== null && ! in_array($include['id'], array_column($options, 'id'), true)) {
            array_unshift($options, $include);
        }

        return $options;
    }

    /** @return list<array{id: string, name: string}> */
    private function companyOptions(User $actor): array
    {
        if (! $actor->can(ContactController::VIEW)) {
            return [];
        }

        return $this->records->constrain(Company::query(), $actor, ContactController::VIEW)
            ->orderBy('name')->limit(500)->get(['id', 'name'])
            ->map(fn (Company $company): array => ['id' => $company->id, 'name' => $company->name])->all();
    }

    /** @return array{id: string, name: string}|null */
    private function prefillContact(?string $id, User $actor): ?array
    {
        if ($id === null || ! $actor->can(ContactController::VIEW)) {
            return null;
        }

        $contact = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->find($id);

        return $contact === null ? null : ['id' => $contact->id, 'name' => $contact->fullName()];
    }

    /** @return list<array{id: string, name: string}> */
    private function ownerOptions(User $actor): array
    {
        if ($this->access->scopeFor($actor, self::MANAGE) !== AccessScope::All) {
            return [];
        }

        return TenantMembership::query()->where('status', MembershipStatus::Active)->with('user:id,name')->get()
            ->map(fn (TenantMembership $membership): array => ['id' => $membership->id, 'name' => $membership->user->name])
            ->sortBy('name')->values()->all();
    }

    /** @return array<string, mixed> */
    private function present(Note $note, User $actor): array
    {
        return [
            'id' => $note->id,
            'title' => $note->title,
            'body' => $note->body,
            'isPinned' => $note->is_pinned,
            'color' => $note->color,
            'contact' => $note->contact === null ? null : ['id' => $note->contact->id, 'name' => $note->contact->fullName()],
            'company' => $note->company?->only(['id', 'name']),
            'owner' => $note->owner === null ? null : ['id' => $note->owner->id, 'name' => $note->owner->user->name],
            'editable' => $this->records->allows($actor, self::MANAGE, $note),
            'createdAt' => $note->created_at->toIso8601String(),
        ];
    }
}
