<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\SmsMarketing\Models\Group;
use App\Modules\SmsMarketing\Models\GroupMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contact groups: named lists of references to shared contacts. A group never copies a contact, so a changed
 * phone number or an opt-out is always seen at send time.
 */
final class GroupManager
{
    /** Gauge: distinct contacts that are in at least one group. */
    public const string LIMIT_CONTACTS = 'sms_marketing.contacts';

    public function __construct(
        private readonly UsageLimits $limits,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array{name: string, description?: string|null}  $attributes */
    public function create(array $attributes): Group
    {
        return $this->save(new Group, $attributes);
    }

    /** @param  array{name: string, description?: string|null}  $attributes */
    public function update(Group $group, array $attributes): Group
    {
        return $this->save($group, $attributes);
    }

    public function delete(Group $group): void
    {
        DB::transaction(static fn () => $group->delete());
    }

    /**
     * Add contacts to a group. Contacts already in it are left alone. All or nothing against the contact limit.
     *
     * @param  list<string>  $contactIds  Contact ids the caller may see.
     * @return array{added: int, existing: int, missing: int}
     */
    public function addContacts(Group $group, array $contactIds, ?TenantMembership $by = null): array
    {
        $ids = array_values(array_unique($contactIds));
        $known = Contact::query()->whereIn('id', $ids)->pluck('id')->all();
        $missing = count($ids) - count($known);

        return DB::transaction(function () use ($group, $known, $missing, $by): array {
            $inGroup = GroupMember::query()->where('group_id', $group->id)->whereIn('contact_id', $known)->pluck('contact_id')->all();
            $fresh = array_values(array_diff($known, $inGroup));

            if ($fresh === []) {
                return ['added' => 0, 'existing' => count($inGroup), 'missing' => $missing];
            }

            $elsewhere = GroupMember::query()->whereIn('contact_id', $fresh)->distinct()->pluck('contact_id')->all();
            $newlyCounted = count(array_diff($fresh, $elsewhere));

            if ($newlyCounted > 0) {
                $this->limits->ensure(self::LIMIT_CONTACTS, $newlyCounted, 'contacts');
            }

            $added = 0;

            foreach ($fresh as $id) {
                try {
                    GroupMember::query()->create(['group_id' => $group->id, 'contact_id' => $id, 'added_by' => $by?->id]);
                    $added++;
                } catch (UniqueConstraintViolationException) {
                    // Added by a concurrent request: the membership exists, which is what was asked.
                }
            }

            if ($added > 0) {
                $this->audit->record('sms_marketing.group.members_added', $group, ['count' => $added]);
            }

            return ['added' => $added, 'existing' => count($inGroup) + (count($fresh) - $added), 'missing' => $missing];
        });
    }

    public function removeMember(Group $group, GroupMember $member): void
    {
        DB::transaction(function () use ($group, $member): void {
            $member->delete();
            $this->audit->record('sms_marketing.group.member_removed', $group, ['contact_id' => $member->contact_id]);
        });
    }

    /** Distinct contacts in any group: the gauge the plan limit reads. */
    public static function distinctContacts(): int
    {
        return GroupMember::query()->distinct()->count('contact_id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function save(Group $group, array $attributes): Group
    {
        try {
            return DB::transaction(function () use ($group, $attributes): Group {
                $description = trim((string) ($attributes['description'] ?? ''));
                $group->fill([
                    'name' => trim((string) $attributes['name']),
                    'description' => $description === '' ? null : $description,
                ])->save();

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('A group with this name already exists.')]);
        }
    }
}
