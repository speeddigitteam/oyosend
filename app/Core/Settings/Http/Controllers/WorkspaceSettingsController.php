<?php

declare(strict_types=1);

namespace App\Core\Settings\Http\Controllers;

use App\Core\Settings\UpdateWorkspaceProfile;
use App\Core\Settings\WorkspaceLogo;
use App\Core\Support\Toast;
use App\Core\Tenancy\Support\TenantHost;
use App\Core\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company profile, language, timezone, currency and logo of the workspace.
 */
final class WorkspaceSettingsController
{
    public function __construct(private readonly TenantContext $context) {}

    public function edit(TenantHost $hosts, WorkspaceLogo $logo): Response
    {
        $tenant = $this->context->require();

        return Inertia::render('Core/Pages/Settings/Workspace', [
            'workspace' => [
                'name' => $tenant->name,
                'legalName' => $tenant->legal_name,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'website' => $tenant->website,
                'address' => $tenant->address,
                'city' => $tenant->city,
                'postalCode' => $tenant->postal_code,
                'country' => $tenant->country,
                'taxId' => $tenant->tax_id,
                'locale' => $tenant->locale,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                // The address is fixed: links, bookmarks and sign-in all depend on it.
                'url' => $hosts->url($tenant),
                'logoUrl' => $logo->url($tenant),
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
            'currencies' => config('platform.currencies', []),
        ]);
    }

    public function update(Request $request, UpdateWorkspaceProfile $profile): RedirectResponse
    {
        $profile->handle($this->context->require(), $request->validate($profile->rules()));

        Toast::success(__('Workspace settings saved.'));

        return back();
    }

    public function storeLogo(Request $request, WorkspaceLogo $logo): RedirectResponse
    {
        $input = $request->validate(['logo' => $logo->rules()]);

        $logo->replace($this->context->require(), $input['logo']);

        Toast::success(__('Logo updated.'));

        return back();
    }

    public function destroyLogo(WorkspaceLogo $logo): RedirectResponse
    {
        $logo->remove($this->context->require());

        Toast::success(__('Logo removed.'));

        return back();
    }
}
