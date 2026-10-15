<?php

declare(strict_types=1);

namespace App\Core\Website;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\Modules\AppOffer;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;

/**
 * The apps the website presents, each with its own page.
 *
 * Only a module that this build registers is shown, so the website never
 * advertises an app the product does not have. The words come from
 * lang/{locale}/site_apps.php; the price from the module's own App Store offers.
 */
final readonly class SiteApps
{
    /**
     * Address on the website => module key, website group and icon, in the order the website lists them.
     *
     * @var array<string, array{module: string, category: string, icon: string}>
     */
    private const array APPS = [
        'crm' => ['module' => 'crm', 'category' => 'sales', 'icon' => 'handshake'],
        'invoicing' => ['module' => 'invoicing', 'category' => 'sales', 'icon' => 'receipt'],
        'hr-management' => ['module' => 'hrm', 'category' => 'people', 'icon' => 'users-round'],
        'payroll' => ['module' => 'payroll', 'category' => 'people', 'icon' => 'wallet'],
        'talent-and-performance' => ['module' => 'talent', 'category' => 'people', 'icon' => 'trending-up'],
        'whatsapp-business' => ['module' => 'whatsapp_marketing', 'category' => 'marketing', 'icon' => 'message-circle'],
        'email-marketing' => ['module' => 'email_marketing', 'category' => 'marketing', 'icon' => 'mail-open'],
        'sms-marketing' => ['module' => 'sms_marketing', 'category' => 'marketing', 'icon' => 'smartphone'],
        'social-media-management' => ['module' => 'social', 'category' => 'marketing', 'icon' => 'share-2'],
        'meta-ads' => ['module' => 'meta_ads', 'category' => 'marketing', 'icon' => 'megaphone'],
        'customer-support' => ['module' => 'support', 'category' => 'service', 'icon' => 'life-buoy'],
        'communication' => ['module' => 'communication', 'category' => 'service', 'icon' => 'mail'],
        'project-management' => ['module' => 'projects', 'category' => 'operations', 'icon' => 'folder-kanban'],
        'workflow-automation' => ['module' => 'automation', 'category' => 'operations', 'icon' => 'workflow'],
        'ai-assistant' => ['module' => 'ai', 'category' => 'operations', 'icon' => 'sparkles'],
        'reports-and-analytics' => ['module' => 'reports', 'category' => 'operations', 'icon' => 'chart-no-axes-combined'],
        'notes' => ['module' => 'notes', 'category' => 'operations', 'icon' => 'file-text'],
    ];

    /** @var list<string> */
    public const array CATEGORIES = ['sales', 'people', 'marketing', 'service', 'operations'];

    public function __construct(private ModuleRegistry $modules) {}

    /**
     * @return list<SiteApp>
     */
    public function all(): array
    {
        $apps = [];

        foreach (self::APPS as $slug => $app) {
            $manifest = $this->modules->find($app['module']);

            if ($manifest !== null && ! $manifest->core) {
                $apps[] = new SiteApp($slug, $app['module'], $app['category'], $app['icon'], $this->price($manifest));
            }
        }

        return $apps;
    }

    public function find(string $slug): ?SiteApp
    {
        foreach ($this->all() as $app) {
            if ($app->slug === $slug) {
                return $app;
            }
        }

        return null;
    }

    public function forModule(string $module): ?SiteApp
    {
        foreach ($this->all() as $app) {
            if ($app->module === $module) {
                return $app;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<SiteApp>> category => its apps, in website order
     */
    public function byCategory(): array
    {
        $groups = array_fill_keys(self::CATEGORIES, []);

        foreach ($this->all() as $app) {
            $groups[$app->category][] = $app;
        }

        return array_filter($groups);
    }

    /**
     * @return list<string> every address the website answers under /apps/
     */
    public static function slugs(): array
    {
        return array_keys(self::APPS);
    }

    /**
     * The cheapest public self-serve offer: free, the cheapest monthly price, or else the cheapest yearly one.
     */
    private function price(ModuleManifest $manifest): SitePrice
    {
        $cheapest = ['month' => null, 'year' => null];
        $trialDays = 0;
        $free = false;

        foreach ($manifest->offers as $offer) {
            /** @var AppOffer $offer */
            if (! $offer->public) {
                continue;
            }

            if ($offer->type === AppBillingType::Free) {
                $free = true;

                continue;
            }

            $period = match ($offer->type) {
                AppBillingType::Monthly => 'month',
                AppBillingType::Yearly => 'year',
                default => null,
            };

            if ($period === null || $offer->amount === null || $offer->currency === null) {
                continue;
            }

            if ($cheapest[$period] === null || $offer->amount < $cheapest[$period]->amount) {
                $cheapest[$period] = $offer;
            }

            $trialDays = max($trialDays, $offer->trialDays);
        }

        $period = $cheapest['month'] !== null ? 'month' : 'year';
        $offer = $cheapest[$period];

        return $offer === null || $free
            ? new SitePrice(true, trialDays: $trialDays)
            : new SitePrice(false, $offer->amount, $offer->currency, $period, $trialDays);
    }
}
