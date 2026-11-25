<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Calendar\Models\Preference;

/** One member's calendar settings, with the workspace's time zone as the default. */
final readonly class Preferences
{
    public function __construct(private TenantContext $context) {}

    /** The member's settings; an unsaved row with the defaults until they change something. */
    public function for(TenantMembership $member): Preference
    {
        return Preference::query()->where('membership_id', $member->id)->first()
            ?? new Preference(['membership_id' => $member->id]);
    }

    public function timezone(Preference $preference): string
    {
        return $preference->timezone
            ?? $this->context->require()->timezone
            ?? (string) config('platform.defaults.timezone');
    }

    /** @param array<string, mixed> $attributes */
    public function save(TenantMembership $member, array $attributes): Preference
    {
        $preference = $this->for($member);
        $preference->fill($attributes);
        $preference->membership_id = $member->id;
        $preference->save();

        return $preference;
    }
}
