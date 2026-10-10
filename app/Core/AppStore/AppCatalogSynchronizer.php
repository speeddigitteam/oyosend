<?php

declare(strict_types=1);

namespace App\Core\AppStore;

use App\Core\AppStore\Enums\EntitlementStatus;
use App\Core\AppStore\Enums\InstallationStatus;
use App\Core\AppStore\Enums\SubscriptionItemStatus;
use App\Core\AppStore\Models\AppVersion;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\Modules\AppOffer;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Mirrors compiled first-party manifests into the queryable App Store catalogue. */
final class AppCatalogSynchronizer
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @return array{apps: int, versions: int, unpublished: int}
     */
    public function sync(bool $prune = false): array
    {
        return DB::transaction(function () use ($prune): array {
            $seen = [];
            $versions = 0;

            foreach ($this->modules->all() as $manifest) {
                if ($manifest->core) {
                    continue;
                }

                $this->validateCatalogueMetadata($manifest);
                $seen[] = $manifest->key;

                /** @var CatalogApp $app */
                $app = CatalogApp::query()->updateOrCreate(
                    ['module_key' => $manifest->key],
                    [
                        'name' => $manifest->name,
                        'description' => $manifest->description,
                        'category' => $manifest->category,
                        'icon' => $manifest->icon,
                        'is_featured' => $manifest->featured,
                        'is_popular' => $manifest->popular,
                        'is_published' => true,
                    ],
                );

                $payload = $this->versionPayload($manifest);
                $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                $version = AppVersion::query()
                    ->where('app_id', $app->getKey())
                    ->where('version', $manifest->version)
                    ->first();

                if ($version !== null && $version->manifest_hash !== $hash) {
                    throw new RuntimeException(
                        "App [{$manifest->key}] version [{$manifest->version}] changed without a version bump.",
                    );
                }

                if ($version === null) {
                    /** @var AppVersion $version */
                    $version = AppVersion::query()->create([
                        'app_id' => $app->getKey(),
                        ...$payload,
                        'manifest_hash' => $hash,
                        'published_at' => Carbon::now(),
                    ]);
                    $versions++;
                }

                if ($app->current_version_id !== $version->getKey()) {
                    $app->update(['current_version_id' => $version->getKey()]);
                }
            }

            $unpublished = 0;

            if ($prune) {
                $query = CatalogApp::query()->where('is_published', true);

                if ($seen !== []) {
                    $query->whereNotIn('module_key', $seen);
                }

                $missing = $query->get(['id', 'module_key']);
                $blocked = $missing->filter(function (CatalogApp $app): bool {
                    $liveInstallation = DB::table('app_installations')
                        ->where('app_id', $app->getKey())
                        ->where('status', '!=', InstallationStatus::Uninstalled->value)
                        ->exists();
                    $liveEntitlement = DB::table('app_entitlements')
                        ->where('app_id', $app->getKey())
                        ->where('status', EntitlementStatus::Active->value)
                        ->exists();
                    $liveSubscription = DB::table('subscription_items')
                        ->where('app_id', $app->getKey())
                        ->whereIn('status', [
                            SubscriptionItemStatus::Pending->value,
                            SubscriptionItemStatus::Trialing->value,
                            SubscriptionItemStatus::Active->value,
                            SubscriptionItemStatus::PastDue->value,
                        ])
                        ->exists();

                    return $liveInstallation || $liveEntitlement || $liveSubscription;
                })->pluck('module_key')->all();

                if ($blocked !== []) {
                    throw new RuntimeException(
                        'Cannot unpublish apps with live installations or licences: '.implode(', ', $blocked).'.',
                    );
                }

                $unpublished = CatalogApp::query()
                    ->whereIn('id', $missing->pluck('id'))
                    ->update(['is_published' => false]);
            }

            return ['apps' => count($seen), 'versions' => $versions, 'unpublished' => $unpublished];
        });
    }

    private function validateCatalogueMetadata(ModuleManifest $manifest): void
    {
        if ($manifest->description === null || trim($manifest->description) === '' || $manifest->category === null) {
            throw new RuntimeException("Optional module [{$manifest->key}] needs an App Store description and category.");
        }

        if ($manifest->offers === []) {
            throw new RuntimeException("Optional module [{$manifest->key}] needs at least one App Store offer.");
        }
    }

    /** @return array<string, mixed> */
    private function versionPayload(ModuleManifest $manifest): array
    {
        return [
            'version' => $manifest->version,
            'compatibility' => $manifest->compatibility,
            'features' => $manifest->features,
            'screenshots' => $manifest->screenshots,
            'offers' => array_map(static fn (AppOffer $offer): array => $offer->toArray(), $manifest->offers),
            'dependencies' => $manifest->dependencies,
            'permissions' => array_map(static fn ($permission): array => [
                'name' => $permission->name,
                'group' => $permission->group,
                'scopable' => $permission->scopable,
                'sensitive' => $permission->sensitive,
            ], $manifest->permissions),
            'routes' => $manifest->routes,
            'scheduled_jobs' => array_map(static fn (ScheduledJobDefinition $job): array => $job->toArray(), $manifest->scheduledJobs),
            'settings' => array_map(static fn (AppSettingDefinition $setting): array => $setting->toArray(), $manifest->settings),
            'release_notes' => $manifest->releaseNotes,
        ];
    }
}
