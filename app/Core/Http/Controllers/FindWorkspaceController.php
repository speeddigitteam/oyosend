<?php

declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends someone to their workspace's sign-in page from the website. Answers a
 * plain form with a redirect and an Inertia visit with a location change.
 */
final class FindWorkspaceController
{
    public function __invoke(Request $request, TenantHost $hosts): Response
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
