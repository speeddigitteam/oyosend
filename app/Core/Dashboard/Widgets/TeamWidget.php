<?php

declare(strict_types=1);

namespace App\Core\Dashboard\Widgets;

use App\Core\Access\Models\Role;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use Illuminate\Support\Carbon;

/**
 * Who is in the workspace. Every figure is counted from tenant-scoped tables.
 */
final class TeamWidget
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user): array
    {
        $recent = TenantMembership::query()
            ->with('user:id,name,email')
            ->where('status', MembershipStatus::Active)
            ->latest('joined_at')
            ->limit(5)
            ->get();

        return [
            'members' => TenantMembership::query()->where('status', MembershipStatus::Active)->count(),
            'suspended' => TenantMembership::query()->where('status', MembershipStatus::Suspended)->count(),
            'pendingInvitations' => Invitation::query()->pending()->count(),
            'roles' => Role::query()->forCurrentTenant()->count(),
            'joinedThisMonth' => TenantMembership::query()
                ->where('joined_at', '>=', Carbon::now()->startOfMonth())
                ->count(),
            'recent' => $recent->map(fn (TenantMembership $membership): array => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'initials' => $membership->user->initials(),
                'joinedAt' => $membership->joined_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
