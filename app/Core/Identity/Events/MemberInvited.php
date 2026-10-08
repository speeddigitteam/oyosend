<?php

declare(strict_types=1);

namespace App\Core\Identity\Events;

use App\Core\Events\TenantDomainEvent;
use App\Core\Identity\Models\Invitation;

final class MemberInvited extends TenantDomainEvent
{
    public readonly string $invitationId;

    public readonly string $email;

    public readonly string $roleId;

    public function __construct(Invitation $invitation)
    {
        parent::__construct();

        $this->invitationId = $invitation->id;
        $this->email = $invitation->email;
        $this->roleId = $invitation->role_id;
    }

    public static function name(): string
    {
        return 'core.member.invited';
    }

    public function payload(): array
    {
        return [
            'invitation_id' => $this->invitationId,
            'email' => $this->email,
            'role_id' => $this->roleId,
        ];
    }
}
