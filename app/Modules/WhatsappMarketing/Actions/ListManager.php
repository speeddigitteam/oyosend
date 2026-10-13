<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Modules\WhatsappMarketing\Models\ContactList;
use App\Modules\WhatsappMarketing\Models\ListMember;
use App\Modules\WhatsappMarketing\Support\ContactRules;
use App\Modules\WhatsappMarketing\Support\Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Contact lists. A list holds references to shared contacts; a contact is never copied. Adding members goes
 * through the actor's contact scope: you can only add people you may see.
 */
final class ListManager
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly TenantContext $context,
    ) {}

    /** @param array{name: string, description?: string|null} $input */
    public function save(?ContactList $list, array $input, ?string $actorMembershipId = null): ContactList
    {
        $name = Text::clean($input['name'], 120, false);

        if (ContactList::query()->where('name', $name)->when($list !== null, fn ($query) => $query->whereKeyNot($list?->id))->exists()) {
            throw ValidationException::withMessages(['name' => __('A list with this name already exists.')]);
        }

        $list ??= new ContactList(['created_by' => $actorMembershipId]);
        $list->fill([
            'name' => $name,
            'description' => ($input['description'] ?? null) === null ? null : (Text::clean($input['description'], 300, false) ?: null),
        ])->save();

        return $list;
    }

    /**
     * @param  list<string>  $contactIds  Up to 500.
     * @return int How many were added (those already on the list and those the actor cannot see are not).
     */
    public function addMembers(ContactList $list, array $contactIds, User $actor, ?TenantMembership $membership): int
    {
        $ids = array_values(array_unique(array_filter($contactIds, static fn (string $id): bool => $id !== '')));

        if (count($ids) > 500) {
            throw ValidationException::withMessages(['contact_ids' => __('Add at most 500 contacts at a time.')]);
        }

        $visible = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->whereIn('id', $ids)->pluck('id')->all();

        return $this->insert($list, $visible, $membership?->id);
    }

    /**
     * Add every contact the actor may see that has a phone number, up to the list's cap.
     *
     * @return int How many were added.
     */
    public function addAllVisible(ContactList $list, User $actor, ?TenantMembership $membership): int
    {
        $added = 0;

        $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
            ->whereNotNull('phone')
            ->select('id')
            ->chunkById(500, function ($contacts) use ($list, $membership, &$added): bool {
                $added += $this->insert($list, $contacts->pluck('id')->all(), $membership?->id);

                return $this->count($list) < $this->cap();
            });

        return $added;
    }

    /**
     * Add every contact the actor may see, with a phone number, that matches the rules ({@see ContactRules}), up
     * to the list's cap. A one-time fill: contacts that match later are not added by themselves.
     *
     * @param  list<array{field: string, operator: string, value: string|null}>  $rules  Normalised.
     * @return int How many were added.
     */
    public function addByRules(ContactList $list, array $rules, string $match, User $actor, ?TenantMembership $membership): int
    {
        $added = 0;
        $query = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->whereNotNull('phone');
        ContactRules::apply($query, $rules, $match);

        $query->select('id')->chunkById(500, function ($contacts) use ($list, $membership, &$added): bool {
            if ($this->count($list) >= $this->cap()) {
                return false;
            }

            $added += $this->insert($list, $contacts->pluck('id')->all(), $membership?->id);

            return $this->count($list) < $this->cap();
        });

        return $added;
    }

    public function remove(ListMember $member): void
    {
        $member->delete();
    }

    public function count(ContactList $list): int
    {
        return ListMember::query()->where('list_id', $list->id)->count();
    }

    public function cap(): int
    {
        return max(1, (int) config('whatsapp_marketing.max_list_members', 10000));
    }

    /** @param list<string> $contactIds */
    private function insert(ContactList $list, array $contactIds, ?string $addedBy): int
    {
        if ($contactIds === []) {
            return 0;
        }

        return (int) DB::transaction(function () use ($list, $contactIds, $addedBy): int {
            $room = $this->cap() - $this->count($list);

            if ($room <= 0) {
                throw ValidationException::withMessages(['contact_ids' => __('A list holds up to :max contacts.', ['max' => $this->cap()])]);
            }

            $existing = ListMember::query()->where('list_id', $list->id)->whereIn('contact_id', $contactIds)->pluck('contact_id')->all();
            $new = array_slice(array_values(array_diff($contactIds, $existing)), 0, $room);
            $now = now();
            $tenantId = $this->context->requireId();

            ListMember::query()->insertOrIgnore(array_map(static fn (string $contactId): array => [
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'list_id' => $list->id,
                'contact_id' => $contactId,
                'added_by' => $addedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ], $new));

            return count($new);
        });
    }
}
