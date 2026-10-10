<?php

declare(strict_types=1);

namespace App\Core\Settings\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Settings\UpdateWorkspaceProfile;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-run setup of a workspace someone signed up for: confirm the company
 * details and regional settings once, then never again.
 */
final class OnboardingController
{
    /** What first-run setup asks for; the rest of the profile is on the settings page. */
    private const array FIELDS = ['name', 'legal_name', 'phone', 'country', 'locale', 'timezone', 'currency'];

    public function __construct(private readonly TenantContext $context) {}

    public function show(): Response|RedirectResponse
    {
        $tenant = $this->context->require();

        if ($tenant->isOnboarded()) {
            return redirect()->route('core.dashboard');
        }

        return Inertia::render('Core/Pages/Onboarding', [
            'workspace' => [
                'name' => $tenant->name,
                'legalName' => $tenant->legal_name,
                'phone' => $tenant->phone,
                'country' => $tenant->country,
                'locale' => $tenant->locale,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
            'currencies' => config('platform.currencies', []),
        ]);
    }

    public function store(Request $request, UpdateWorkspaceProfile $profile, AuditLogger $audit): RedirectResponse
    {
        $tenant = $this->context->require();
        $input = $request->validate(Arr::only($profile->rules(), self::FIELDS));

        DB::transaction(function () use ($tenant, $input, $profile, $audit): void {
            $profile->handle($tenant, $input);

            if (! $tenant->isOnboarded()) {
                $tenant->update(['onboarded_at' => Carbon::now()]);

                $audit->record('workspace.onboarded', $tenant);
            }
        });

        Toast::success(__('Your workspace is ready.'));

        return redirect()->route('core.dashboard');
    }
}
