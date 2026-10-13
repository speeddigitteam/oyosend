<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use Illuminate\Http\Request;

/** Shared helpers of the Meta Ads controllers: the actor and their workspace membership. */
final class MetaAdsSupport
{
    public function __construct(private readonly AccessChecker $access) {}

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
}
