<?php

declare(strict_types=1);

namespace App\Core\Identity\Events;

use App\Core\Events\TenantDomainEvent;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;

final class MemberJoined extends TenantDomainEvent
{
    public readonly string $membershipId;

    public readonly string $userId;

    public readonly string $name;

    public readonly string $email;

    public function __construct(TenantMembership $membership, User $user, public readonly string $roleName)
    {
        parent::__construct();

        $this->membershipId = $membership->id;
        $this->userId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
    }

    public static function name(): string
    {
        return 'core.member.joined';
    }

    public function payload(): array
    {
        return [
            'membership_id' => $this->membershipId,
            'user_id' => $this->userId,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->roleName,
        ];
    }
}
