<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\TenantContext;

/**
 * The server-side gate for code reached without a route (a channel-event listener, a queued job): is the active
 * workspace entitled to the WhatsApp Business app?
 */
final class WhatsappAccess
{
    public const string MODULE = 'whatsapp_marketing';

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
    ) {}

    public function entitled(): bool
    {
        $tenant = $this->context->tenant();

        return $tenant !== null && $this->entitlements->allows($tenant, self::MODULE);
    }
}
