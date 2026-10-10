<?php

declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Billing\Models\Plan;
use App\Core\Billing\Support\BillingPresenter;
use App\Core\Settings\Enums\RegistrationMode;
use App\Core\Settings\PlatformSettings;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class WelcomeController
{
    public function show(TenantHost $hosts, PlatformSettings $settings): Response
    {
        $registrationOpen = $settings->registrationMode() !== RegistrationMode::Closed;

        return Inertia::render('Core/Pages/Central/Welcome', [
            'domain' => $hosts->domain(),
            'registrationOpen' => $registrationOpen,
            // Prices are only shown where someone can act on them.
            'plans' => $registrationOpen
                ? Plan::query()->offered()->get()->map(BillingPresenter::plan(...))->all()
                : [],
        ]);
    }

    /**
     * Send someone to their workspace's sign-in page.
     */
    public function find(Request $request, TenantHost $hosts): HttpResponse
    {
        $input = $request->validate([
            'workspace' => ['required', 'string', 'max:63'],
        ]);

        $slug = Str::lower(trim($input['workspace']));

        // Accept a pasted address ("acme.example.com") as well as the bare name.
        $slug = Str::before(Str::after($slug, '://'), '.');

        $tenant = $hosts->isReserved($slug)
            ? null
            : Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            throw ValidationException::withMessages([
                'workspace' => __('We could not find a workspace with that address.'),
            ]);
        }

        return Inertia::location($hosts->url($tenant, 'login'));
    }
}
