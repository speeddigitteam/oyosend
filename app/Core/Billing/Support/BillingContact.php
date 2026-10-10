<?php

declare(strict_types=1);

namespace App\Core\Billing\Support;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;

/**
 * Who billing mail of the active workspace goes to: its owner, in the
 * owner's language.
 */
final readonly class BillingContact
{
    public function __construct(
        public string $name,
        public string $email,
        public string $locale,
    ) {}

    public static function ofActiveWorkspace(): ?self
    {
        $tenant = app(TenantContext::class)->require();

        $owner = TenantMembership::query()->where('is_owner', true)->with('user:id,name,email,locale')->first()?->user;

        return $owner === null ? null : new self($owner->name, $owner->email, $owner->locale ?? $tenant->locale);
    }
}
