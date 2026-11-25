<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Core\Billing\Models\Plan;
use App\Core\Billing\Support\BillingPresenter;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The plans workspaces subscribe to. A plan is never deleted: it is archived,
 * so the workspaces on it and their invoices keep making sense.
 */
final class PlanController
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Platform/Pages/Plans/Index', [
            'plans' => Plan::query()
                ->withCount('subscriptions')
                ->orderByDesc('is_active')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Plan $plan): array => [
                    ...BillingPresenter::plan($plan),
                    'subscribers' => (int) $plan->getAttribute('subscriptions_count'),
                ])
                ->all(),
            'canManage' => $request->user('platform')?->can('platform.plans.manage') === true,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Platform/Pages/Plans/Edit', [
            'plan' => null,
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plan = Plan::query()->create($this->validated($request));

        Toast::success(__('Plan ":name" created.', ['name' => $plan->name]));

        return redirect()->route('platform.plans.index');
    }

    public function edit(Plan $plan): Response
    {
        return Inertia::render('Platform/Pages/Plans/Edit', [
            'plan' => [
                ...BillingPresenter::plan($plan),
                // The form edits the plan's own prices, never what a running offer makes of them.
                'monthlyAmount' => $plan->monthly_amount,
                'yearlyAmount' => $plan->yearly_amount,
                // The offer as stored, ended or not, so it can be changed or cleared; its last day in the platform's zone.
                'offerMonthlyAmount' => $plan->offer_monthly_amount,
                'offerYearlyAmount' => $plan->offer_yearly_amount,
                'offerEndsOn' => $plan->offer_ends_at?->timezone(self::timezone())->toDateString(),
                // The Bengali name and description, for the Bengali website (docs/WEBSITE.md).
                'nameBn' => $plan->name_bn,
                'descriptionBn' => $plan->description_bn,
                'subscribers' => $plan->subscriptions()->count(),
            ],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->validated($request, $plan));

        Toast::success(__('Plan ":name" saved.', ['name' => $plan->name]));

        return redirect()->route('platform.plans.index');
    }

    /**
     * What a plan can include and cap in this build of the platform.
     *
     * @return array{modules: list<array{key: string, name: string}>, limits: list<array{key: string, kind: string}>, currencies: list<string>}
     */
    private function options(): array
    {
        /** @var list<string> $currencies */
        $currencies = config('platform.currencies', []);

        return [
            // Core modules belong to every workspace; only optional ones are a plan's to give.
            'modules' => array_values(array_map(
                static fn (ModuleManifest $manifest): array => ['key' => $manifest->key, 'name' => $manifest->name],
                array_filter($this->modules->all(), static fn (ModuleManifest $manifest): bool => ! $manifest->core),
            )),
            'limits' => array_values(array_map(
                static fn (LimitDefinition $limit): array => ['key' => $limit->key, 'kind' => $limit->kind->value],
                $this->modules->limits(),
            )),
            'currencies' => $currencies,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        /** @var list<string> $currencies */
        $currencies = config('platform.currencies', []);

        $optionalModules = array_keys(array_filter(
            $this->modules->all(),
            static fn (ModuleManifest $manifest): bool => ! $manifest->core,
        ));

        $input = $request->validate([
            'key' => [
                'required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_-]*$/',
                Rule::unique('plans', 'key')->ignore($plan?->getKey()),
            ],
            'name' => ['required', 'string', 'max:80'],
            'name_bn' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'description_bn' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', Rule::in($currencies)],
            // Minor units. Empty = not sold for that interval; 0 = free.
            'monthly_amount' => ['nullable', 'integer', 'min:0', 'max:99999999999', 'required_without:yearly_amount'],
            'yearly_amount' => ['nullable', 'integer', 'min:0', 'max:99999999999', 'required_without:monthly_amount'],
            // A limited-time offer: a lower price for an interval the plan is sold for, until the end of a day.
            'offer_monthly_amount' => ['nullable', 'integer', 'min:1', 'max:99999999999'],
            'offer_yearly_amount' => ['nullable', 'integer', 'min:1', 'max:99999999999'],
            'offer_ends_on' => [
                'nullable', 'date_format:Y-m-d', 'required_with:offer_monthly_amount,offer_yearly_amount',
                'after_or_equal:'.Carbon::now(self::timezone())->toDateString(),
            ],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'modules' => ['array'],
            'modules.*' => ['string', Rule::in($optionalModules)],
            // Limit key => maximum. Keys contain dots, so the map is checked below.
            'limits' => ['array'],
            'is_public' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:-999', 'max:999'],
        ]);

        $registered = $this->modules->limits();
        $limits = [];

        foreach ($input['limits'] ?? [] as $key => $maximum) {
            // An empty field means "no limit".
            if ($maximum === null || $maximum === '') {
                continue;
            }

            if (! isset($registered[$key]) || filter_var($maximum, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                throw ValidationException::withMessages([
                    'limits' => __('A limit must be a whole number of zero or more.'),
                ]);
            }

            $limits[$key] = (int) $maximum;
        }

        return [
            ...Arr::except($input, 'offer_ends_on'),
            ...$this->offer($input),
            'modules' => array_values(array_unique($input['modules'] ?? [])),
            'limits' => $limits,
        ];
    }

    /**
     * The offer's columns from the form: each price below the plan's own for that interval, the end at the close of the
     * chosen day in the platform's time zone. No price means no offer.
     *
     * @param  array<string, mixed>  $input
     * @return array{offer_monthly_amount: int|null, offer_yearly_amount: int|null, offer_ends_at: Carbon|null}
     */
    private function offer(array $input): array
    {
        $errors = [];
        $amounts = [];

        foreach (['monthly', 'yearly'] as $interval) {
            $offer = isset($input["offer_{$interval}_amount"]) ? (int) $input["offer_{$interval}_amount"] : null;
            $list = isset($input["{$interval}_amount"]) ? (int) $input["{$interval}_amount"] : null;

            if ($offer !== null && ($list === null || $offer >= $list)) {
                $errors["offer_{$interval}_amount"] = __('An offer price must be lower than the plan\'s own price for that period.');
            }

            $amounts[$interval] = $offer;
        }

        $endsOn = $input['offer_ends_on'] ?? null;

        if (is_string($endsOn) && $amounts['monthly'] === null && $amounts['yearly'] === null) {
            $errors['offer_monthly_amount'] = __('Give an offer price for at least one period, or clear the last day.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $none = $amounts['monthly'] === null && $amounts['yearly'] === null;

        return [
            'offer_monthly_amount' => $amounts['monthly'],
            'offer_yearly_amount' => $amounts['yearly'],
            'offer_ends_at' => $none || ! is_string($endsOn)
                ? null
                : Carbon::createFromFormat('Y-m-d', $endsOn, self::timezone())->endOfDay()->utc(),
        ];
    }

    /** The operator's time zone, in which an offer's last day ends. */
    private static function timezone(): string
    {
        return (string) config('platform.defaults.timezone', 'Asia/Dhaka');
    }
}
