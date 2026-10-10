<?php

declare(strict_types=1);

namespace App\Core\AppStore\Support;

use App\Core\AppStore\Models\AppEntitlement;
use App\Core\AppStore\Models\AppInstallation;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\AppStore\Models\SubscriptionItem;

final class AppStorePresenter
{
    /** @return array<string, mixed> */
    public static function app(
        CatalogApp $app,
        ?AppInstallation $installation = null,
        ?SubscriptionItem $item = null,
        ?AppEntitlement $entitlement = null,
        bool $includedInPlan = false,
    ): array {
        $version = $app->currentVersion;
        $storedSettings = $installation === null ? [] : ($installation->settings ?? []);

        return [
            'key' => $app->module_key,
            'name' => $app->name,
            'description' => $app->description,
            'category' => $app->category->value,
            'icon' => $app->icon,
            'featured' => $app->is_featured,
            'popular' => $app->is_popular,
            'includedInPlan' => $includedInPlan,
            'version' => $version->version,
            'compatibility' => $version->compatibility,
            'features' => $version->features,
            'screenshots' => $version->screenshots,
            'offers' => array_values(array_filter(
                $version->offers,
                static fn (array $offer): bool => $offer['public'],
            )),
            'dependencies' => $version->dependencies,
            'permissions' => array_column($version->permissions, 'name'),
            'settings' => self::settings($version->settings, $storedSettings),
            'releaseNotes' => $version->release_notes,
            'installation' => self::installation($installation),
            'subscription' => self::subscription($item),
            'licence' => $entitlement === null ? null : [
                'id' => $entitlement->license_id,
                'source' => $entitlement->source->value,
                'status' => $entitlement->status->value,
                'validUntil' => $entitlement->valid_until?->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function installation(?AppInstallation $installation): ?array
    {
        if ($installation === null) {
            return null;
        }

        return [
            'id' => $installation->id,
            'status' => $installation->status->value,
            'installedAt' => $installation->installed_at?->toIso8601String(),
            'activatedAt' => $installation->activated_at?->toIso8601String(),
            'deactivatedAt' => $installation->deactivated_at?->toIso8601String(),
            'uninstalledAt' => $installation->uninstalled_at?->toIso8601String(),
            'installedVersion' => $installation->version?->version,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function subscription(?SubscriptionItem $item): ?array
    {
        if ($item === null) {
            return null;
        }

        return [
            'id' => $item->id,
            'offerKey' => $item->offer_key,
            'billingType' => $item->billing_type->value,
            'status' => $item->status->value,
            'currency' => $item->currency,
            'amount' => $item->amount,
            'quantity' => $item->quantity,
            'trialEndsAt' => $item->trial_ends_at?->toIso8601String(),
            'periodEnd' => $item->current_period_end?->toIso8601String(),
            'graceEndsAt' => $item->grace_ends_at?->toIso8601String(),
            'cancelAtPeriodEnd' => $item->cancel_at_period_end,
            'endedAt' => $item->ended_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array{key: string, label: string, type: string, required: bool, description: string|null, default: string|int|bool|null, options: array<string, string>, min: int|null, max: int|null, max_length: int}>  $schema
     * @param  array<string, mixed>  $stored
     * @return list<array<string, mixed>>
     */
    private static function settings(array $schema, array $stored): array
    {
        return array_map(static function (array $setting) use ($stored): array {
            $key = $setting['key'];
            $hasValue = array_key_exists($key, $stored)
                && $stored[$key] !== null
                && $stored[$key] !== '';

            if ($setting['type'] === 'secret') {
                unset($setting['default']);
                $setting['hasValue'] = $hasValue;

                return $setting;
            }

            $setting['value'] = $hasValue ? $stored[$key] : $setting['default'];
            $setting['hasValue'] = $hasValue;

            return $setting;
        }, $schema);
    }
}
