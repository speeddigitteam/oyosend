<?php

declare(strict_types=1);

namespace App\Modules\Links\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Links\Actions\LinkManager;
use App\Modules\Links\Enums\LinkKind;
use App\Modules\Links\Models\Link;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class LinkController
{
    public const string VIEW = 'links.links.view';

    public const string MANAGE = 'links.links.manage';

    public function __construct(
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
        private readonly LinkManager $links,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(LinkKind::class)],
            'folder' => ['nullable', 'string', 'max:60'],
        ]);
        $search = trim((string) ($input['search'] ?? ''));
        $folder = trim((string) ($input['folder'] ?? ''));
        $visible = fn () => $this->records->constrain(Link::query(), $actor, self::VIEW);

        $links = $visible()
            ->with('owner.user:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('title', 'like', Like::contains($search))
                ->orWhere('address', 'like', Like::contains($search))
                ->orWhere('description', 'like', Like::contains($search))
                ->orWhere('folder', 'like', Like::contains($search))))
            ->when($input['kind'] ?? null, fn ($query, string $kind) => $query->where('kind', $kind))
            ->when($folder !== '', fn ($query) => $query->where('folder', $folder))
            ->orderByDesc('is_pinned')->orderBy('title')
            ->paginate(60)->withQueryString()
            ->through(fn (Link $link): array => $this->present($link, $actor));

        $kindCounts = $visible()->toBase()->groupBy('kind')->selectRaw('kind, COUNT(*) as total')->pluck('total', 'kind')
            ->map(fn (mixed $total): int => (int) $total)->all();

        return Inertia::render('Modules/Links/Pages/Index', [
            'links' => $links,
            'filters' => ['search' => $search, 'kind' => $input['kind'] ?? null, 'folder' => $folder === '' ? null : $folder],
            'kinds' => array_map(static fn (LinkKind $kind): string => $kind->value, LinkKind::cases()),
            'kindCounts' => $kindCounts,
            'folders' => $visible()->toBase()->whereNotNull('folder')->distinct()->orderBy('folder')->limit(100)->pluck('folder')->all(),
            'owners' => $this->ownerOptions($actor),
            'canCreate' => $actor->can(self::MANAGE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $input = $this->validated($request);
        $this->links->create($this->attributes($input), $this->ownerForCreate($input, $actor));
        Toast::success(__('Link saved.'));

        return redirect()->route('links.index');
    }

    public function update(Request $request, Link $link): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->records->authorize($actor, self::MANAGE, $link);
        $input = $this->validated($request);

        if (array_key_exists('owner_id', $input) && $this->access->scopeFor($actor, self::MANAGE) === AccessScope::All) {
            $link->assignOwner($this->assignmentTarget($input['owner_id']));
        }

        $this->links->update($link, $this->attributes($input));
        Toast::success(__('Link saved.'));

        return back();
    }

    public function destroy(Request $request, Link $link): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->records->authorize($actor, self::MANAGE, $link);
        $this->links->delete($link);
        Toast::success(__('Link deleted.'));

        return redirect()->route('links.index');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /**
     * An email link holds a plain address; every other kind an http(s) URL, so
     * no `javascript:` or `data:` address can ever reach an `href`.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $request->merge(['address' => trim((string) $request->input('address', ''))]);
        $kind = LinkKind::tryFrom((string) $request->input('kind'));

        $input = $request->validate([
            'kind' => ['required', Rule::enum(LinkKind::class)],
            'title' => ['required', 'string', 'max:150'],
            'address' => $kind?->holdsEmail() === true
                ? ['required', 'string', 'max:254', 'email:rfc']
                : ['required', 'string', 'max:2048', 'url:http,https'],
            'description' => ['nullable', 'string', 'max:500'],
            'folder' => ['nullable', 'string', 'max:60'],
            'is_pinned' => ['sometimes', 'boolean'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);
        $input['folder'] = trim((string) ($input['folder'] ?? '')) ?: null;

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
        $self = $this->access->membership($actor) ?? abort(403);

        if (($input['owner_id'] ?? null) === null || $this->access->scopeFor($actor, self::MANAGE) !== AccessScope::All) {
            return $self;
        }

        return $this->assignmentTarget($input['owner_id']) ?? $self;
    }

    private function assignmentTarget(mixed $id): ?TenantMembership
    {
        if ($id === null) {
            return null;
        }

        abort_unless(is_string($id), 404);

        return TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($id);
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
    private function present(Link $link, User $actor): array
    {
        return [
            'id' => $link->id,
            'kind' => $link->kind->value,
            'title' => $link->title,
            'address' => $link->address,
            'description' => $link->description,
            'folder' => $link->folder,
            'isPinned' => $link->is_pinned,
            'owner' => $link->owner === null ? null : ['id' => $link->owner->id, 'name' => $link->owner->user->name],
            'editable' => $this->records->allows($actor, self::MANAGE, $link),
            'createdAt' => $link->created_at->toIso8601String(),
        ];
    }
}
