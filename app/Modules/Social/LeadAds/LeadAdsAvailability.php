<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\TenantContext;

/**
 * Whether Facebook Lead Ads can work at all, and why not. Honest codes the page turns into a sentence:
 * nothing here is simulated.
 */
final class LeadAdsAvailability
{
    public const string NOT_ENABLED = 'not_enabled';

    public const string NOT_CONFIGURED = 'not_configured';

    public const string WEBHOOK_NOT_CONFIGURED = 'webhook_not_configured';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly EntitlementChecker $entitlements,
    ) {}

    /** The reason lead capture cannot be switched on, or null when the operator side is ready. */
    public function operatorProblem(): ?string
    {
        if (config('social.lead_ads.enabled') !== true) {
            return self::NOT_ENABLED;
        }

        if (! self::filled(config('social.platforms.facebook.client_id')) || ! self::filled(config('social.platforms.facebook.client_secret'))) {
            return self::NOT_CONFIGURED;
        }

        if (! self::filled(config('social.lead_ads.webhook_verify_token'))) {
            return self::WEBHOOK_NOT_CONFIGURED;
        }

        return null;
    }

    /** Whether the workspace has the CRM, the only place a lead can be handed to. */
    public function crmAvailable(): bool
    {
        return $this->entitlements->allows($this->tenants->require(), 'crm');
    }

    /** @return list<string> */
    public function requiredScopes(): array
    {
        return array_values(array_map('strval', (array) config('social.lead_ads.required_scopes', [])));
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
