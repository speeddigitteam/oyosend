<?php

declare(strict_types=1);

namespace App\Core\AppStore\Http\Controllers;

use App\Core\AppStore\AppStoreManager;
use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\AppStore\Enums\AppSettingType;
use App\Core\AppStore\Enums\EntitlementStatus;
use App\Core\AppStore\Enums\SubscriptionItemStatus;
use App\Core\AppStore\Models\AppEntitlement;
use App\Core\AppStore\Models\AppInstallation;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\AppStore\Models\SubscriptionItem;
use App\Core\AppStore\Support\AppStorePresenter;
use App\Core\Modules\ModuleRegistry;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AppStoreController
{
    public function __construct(
        private readonly AppStoreManager $manager,
        private readonly ModuleRegistry $modules,
    ) {}

    public function index(Request $request): Response
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::enum(AppCategory::class)],
            'pricing' => ['nullable', Rule::enum(AppBillingType::class)],
        ]);

        $apps = $this->catalogueQuery()
            ->when($input['q'] ?? null, function ($query, string $search): void {
                $pattern = Like::contains($search);
                $query->where(fn ($query) => $query->where('name', 'like', $pattern)->orWhere('description', 'like', $pattern));
            })
            ->when($input['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->orderByDesc('is_featured')
            ->orderByDesc('is_popular')
            ->orderBy('name')
            ->get();

        if (isset($input['pricing'])) {
            $apps = $apps->filter(fn (CatalogApp $app): bool => collect($app->currentVersion->offers)
                ->contains(fn (array $offer): bool => $offer['type'] === $input['pricing']));
        }

        [$installations, $items, $entitlements] = $this->stateFor($apps);

        return Inertia::render('Core/Pages/AppStore/Index', [
            'apps' => $apps->map(fn (CatalogApp $app): array => AppStorePresenter::app(
                $app,
                $installations->get($app->id),
                $items->get($app->id),
                $entitlements->get($app->id),
                $this->manager->isIncludedInPlan($app),
            ))->values()->all(),
            'filters' => [
                'q' => $input['q'] ?? '',
                'category' => $input['category'] ?? null,
                'pricing' => $input['pricing'] ?? null,
            ],
            'categories' => array_map(static fn (AppCategory $category): string => $category->value, AppCategory::cases()),
            'pricingTypes' => array_map(static fn (AppBillingType $type): string => $type->value, AppBillingType::cases()),
        ]);
    }

    public function show(string $app): Response
    {
        $catalogApp = $this->manager->catalogApp($app);
        [$installations, $items, $entitlements] = $this->stateFor(collect([$catalogApp]));

        return Inertia::render('Core/Pages/AppStore/Show', [
            // Not `app`: that name is the shared prop with the locale, numerals, timezone and currency.
            'storeApp' => AppStorePresenter::app(
                $catalogApp,
                $installations->get($catalogApp->id),
                $items->get($catalogApp->id),
                $entitlements->get($catalogApp->id),
                $this->manager->isIncludedInPlan($catalogApp),
            ),
            'dependencyNames' => collect($catalogApp->currentVersion->dependencies)
                ->reject(fn (string $key): bool => $key === 'core')
                ->mapWithKeys(fn (string $key): array => [$key => $this->modules->find($key)->name])
                ->all(),
        ]);
    }

    public function mine(): Response
    {
        $installations = AppInstallation::query()
            ->with(['app.currentVersion', 'version'])
            ->latest('updated_at')
            ->get();

        // Apps the plan already includes work without an installation; they belong here too.
        $included = $this->catalogueQuery()
            ->get()
            ->reject(fn (CatalogApp $app): bool => $installations->contains('app_id', $app->id))
            ->filter(fn (CatalogApp $app): bool => $this->manager->isIncludedInPlan($app))
            ->values();

        $apps = $installations->pluck('app')->concat($included);
        [$stateInstallations, $items, $entitlements] = $this->stateFor($apps);

        $presented = $installations->map(fn (AppInstallation $installation): array => AppStorePresenter::app(
            $installation->app,
            $installation,
            $items->get($installation->app_id),
            $entitlements->get($installation->app_id),
            $this->manager->isIncludedInPlan($installation->app),
        ))->concat($included->map(fn (CatalogApp $app): array => AppStorePresenter::app(
            $app,
            $stateInstallations->get($app->id),
            $items->get($app->id),
            $entitlements->get($app->id),
            true,
        )));

        return Inertia::render('Core/Pages/AppStore/MyApps', ['apps' => $presented->values()->all()]);
    }

    public function install(Request $request, string $app): RedirectResponse
    {
        $input = $request->validate(['offer' => ['required', 'string', 'max:60']]);
        $catalogApp = $this->manager->catalogApp($app);
        $this->manager->installFree($catalogApp, $input['offer']);
        Toast::success(__(':app is installed and active.', ['app' => $catalogApp->name]));

        return redirect()->route('core.apps.show', ['app' => $app]);
    }

    public function purchase(Request $request, string $app): RedirectResponse
    {
        $input = $request->validate(['offer' => ['required', 'string', 'max:60']]);
        $catalogApp = $this->manager->catalogApp($app);
        $invoice = $this->manager->purchase($catalogApp, $input['offer']);

        if ($invoice === null) {
            Toast::success(__('The free trial for :app has started.', ['app' => $catalogApp->name]));

            return redirect()->route('core.apps.show', ['app' => $app]);
        }

        Toast::info(__(':app activates after this invoice is verified as paid.', ['app' => $catalogApp->name]));

        return redirect()->route('core.billing.invoices.show', ['invoice' => $invoice->getKey()]);
    }

    public function activate(string $app): RedirectResponse
    {
        $catalogApp = $this->manager->catalogApp($app);
        $this->manager->activate($catalogApp);
        Toast::success(__(':app is active.', ['app' => $catalogApp->name]));

        return back();
    }

    public function deactivate(string $app): RedirectResponse
    {
        $catalogApp = $this->manager->catalogApp($app);
        $this->manager->deactivate($catalogApp);
        Toast::success(__(':app is inactive. Its data is still here.', ['app' => $catalogApp->name]));

        return back();
    }

    public function uninstall(Request $request, string $app): RedirectResponse
    {
        $catalogApp = $this->manager->catalogApp($app);
        $hasCommercialCommitment = SubscriptionItem::query()
            ->where('app_id', $catalogApp->getKey())
            ->whereIn('status', [
                SubscriptionItemStatus::Pending,
                SubscriptionItemStatus::Trialing,
                SubscriptionItemStatus::Active,
                SubscriptionItemStatus::PastDue,
            ])
            ->exists();

        abort_if($hasCommercialCommitment && ! $request->user('web')?->can('core.billing.manage'), 403);

        $this->manager->uninstall($catalogApp);
        Toast::success(__(':app was uninstalled. Its business data was retained.', ['app' => $catalogApp->name]));

        return redirect()->route('core.apps.mine');
    }

    public function reinstall(string $app): RedirectResponse
    {
        $catalogApp = $this->manager->catalogApp($app);
        $this->manager->reinstall($catalogApp);
        Toast::success(__(':app was reinstalled and its retained data is available.', ['app' => $catalogApp->name]));

        return redirect()->route('core.apps.show', ['app' => $app]);
    }

    public function upgrade(string $app): RedirectResponse
    {
        $catalogApp = $this->manager->catalogApp($app);
        $this->manager->upgrade($catalogApp);
        Toast::success(__(':app was updated to the latest version.', ['app' => $catalogApp->name]));

        return back();
    }

    public function cancel(SubscriptionItem $item): RedirectResponse
    {
        $this->manager->cancel($item);
        Toast::success(__('The app subscription will end at the end of its current period.'));

        return back();
    }

    public function resume(SubscriptionItem $item): RedirectResponse
    {
        $this->manager->resume($item);
        Toast::success(__('The app subscription will continue.'));

        return back();
    }

    public function updateSettings(Request $request, string $app): RedirectResponse
    {
        $input = $request->validate(['settings' => ['required', 'array']]);
        $catalogApp = $this->manager->catalogApp($app);

        /** @var array<string, mixed> $settings */
        $settings = $input['settings'];

        // Laravel flashes request input on validation failure. Remove compiled
        // secret fields from that copy before the manager performs validation.
        $safeSettings = $settings;

        foreach ($this->modules->get($catalogApp->module_key)->settings as $definition) {
            if ($definition->type === AppSettingType::Secret) {
                unset($safeSettings[$definition->key]);
            }
        }

        $request->merge(['settings' => $safeSettings]);
        $this->manager->updateSettings($catalogApp, $settings);
        Toast::success(__('App settings saved.'));

        return back();
    }

    /** @return Builder<CatalogApp> */
    private function catalogueQuery(): Builder
    {
        $keys = collect($this->modules->all())
            ->reject(fn ($manifest): bool => $manifest->core)
            ->keys()
            ->all();

        return CatalogApp::query()
            ->where('is_published', true)
            ->whereIn('module_key', $keys)
            ->whereNotNull('current_version_id')
            ->with('currentVersion');
    }

    /**
     * @param  Collection<int, CatalogApp>  $apps
     * @return array{Collection<string, AppInstallation>, Collection<string, SubscriptionItem>, Collection<string, AppEntitlement>}
     */
    private function stateFor(Collection $apps): array
    {
        $ids = $apps->pluck('id');
        $installations = AppInstallation::query()->with('version')->whereIn('app_id', $ids)->get()->keyBy('app_id');
        $items = SubscriptionItem::query()
            ->whereIn('app_id', $ids)
            ->latest('created_at')
            ->get()
            ->unique('app_id')
            ->keyBy('app_id');
        $entitlements = AppEntitlement::query()
            ->whereIn('app_id', $ids)
            ->where('status', EntitlementStatus::Active)
            ->latest('created_at')
            ->get()
            ->unique('app_id')
            ->keyBy('app_id');

        return [$installations, $items, $entitlements];
    }
}
