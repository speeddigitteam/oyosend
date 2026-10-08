<?php

declare(strict_types=1);

namespace App\Core\Identity\Events;

use App\Core\Events\TenantDomainEvent;

final class MemberRemoved extends TenantDomainEvent
{
    public function __construct(
        public readonly string $membershipId,
        public readonly string $userId,
        public readonly string $email,
    ) {
        parent::__construct();
    }

    public static function name(): string
    {
        return 'core.member.removed';
    }

    public function payload(): array
    {
        return [
            'membership_id' => $this->membershipId,
            'user_id' => $this->userId,
            'email' => $this->email,
        ];
    }
}
