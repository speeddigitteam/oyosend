<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\SmsMarketing\Actions\SmsSettings;
use App\Modules\SmsMarketing\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Shared helpers of the SMS Marketing controllers: the actor, their membership and scoped records. */
final class SmsSupport
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
        private readonly SmsSettings $settings,
    ) {}

    public function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    public function membership(User $actor): TenantMembership
    {
        return $this->access->membership($actor) ?? abort(403);
    }

    /** A campaign the actor may act on with `$permission`; 404 outside their scope. */
    public function campaign(User $actor, string $id, string $permission): Campaign
    {
        $campaign = Campaign::query()->findOrFail($id);
        $this->records->authorize($actor, $permission, $campaign);

        return $campaign;
    }

    /** Local date-time (workspace timezone) from a form into UTC, or null. */
    public function utc(?string $local): ?CarbonImmutable
    {
        return $local === null || $local === '' ? null : $this->settings->toUtc($local);
    }
}
