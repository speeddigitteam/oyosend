<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers\Website;

use App\Core\Audit\AuditLogger;
use App\Core\Settings\PlatformSettings;
use App\Core\Support\Toast;
use App\Core\Website\SiteBrand;
use App\Core\Website\SiteCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company behind the platform as the public website shows it: name, email, phones, address, map link,
 * where contact-form messages go, and the company's pages elsewhere. Stored as platform settings over the
 * defaults in config/site.php (docs/WEBSITE.md).
 */
final class CompanyController
{
    /** Form field => platform setting. */
    private const array FIELDS = [
        'name' => 'website.name',
        'legal_name' => 'website.legal_name',
        'founded' => 'website.founded',
        'email' => 'website.email',
        'phone_1' => 'website.phone_1',
        'phone_2' => 'website.phone_2',
        'street' => 'website.street',
        'locality' => 'website.locality',
        'city' => 'website.city',
        'postal_code' => 'website.postal_code',
        'address_bn' => 'website.address_bn',
        'map_url' => 'website.map_url',
        'contact_recipient' => 'website.contact_recipient',
    ];

    public function edit(PlatformSettings $settings, SiteBrand $brand): Response
    {
        $values = [];

        foreach (self::FIELDS as $field => $key) {
            $values[$field] = $settings->get($key);
        }

        foreach (SiteCompany::SOCIAL as $network) {
            $values['social'][$network] = $settings->get("website.social.{$network}");
        }

        return Inertia::render('Platform/Pages/Website/Company', [
            'company' => $values,
            'networks' => SiteCompany::SOCIAL,
            // The uploaded images, or null where the built-in one is used.
            'brand' => array_combine(SiteBrand::ASSETS, array_map($brand->url(...), SiteBrand::ASSETS)),
            'website' => route('central.home'),
        ]);
    }

    public function update(Request $request, PlatformSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $phone = ['regex:/^\+?[0-9][0-9 ()-]{5,38}$/'];

        $input = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'founded' => ['nullable', 'digits:4', 'integer', 'min:1900', 'max:'.now()->year],
            'email' => ['required', 'email:rfc', 'max:150'],
            'phone_1' => ['required', 'string', 'max:40', ...$phone],
            'phone_2' => ['nullable', 'string', 'max:40', ...$phone],
            'street' => ['required', 'string', 'max:160'],
            'locality' => ['nullable', 'string', 'max:80'],
            'city' => ['required', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'address_bn' => ['nullable', 'string', 'max:400'],
            'map_url' => ['nullable', 'url:https', 'max:500'],
            'contact_recipient' => ['required', 'email:rfc', 'max:150'],
            'social' => ['array'],
            'social.*' => ['nullable', 'url:https', 'max:300'],
        ]);

        $values = [];

        foreach (self::FIELDS as $field => $key) {
            $value = isset($input[$field]) ? trim((string) $input[$field]) : '';
            $values[$key] = $value === '' ? null : $value;
        }

        foreach (SiteCompany::SOCIAL as $network) {
            $value = trim((string) ($input['social'][$network] ?? ''));
            $values["website.social.{$network}"] = $value === '' ? null : $value;
        }

        $old = array_combine(array_keys($values), array_map($settings->get(...), array_keys($values)));
        $changed = array_filter($values, static fn (mixed $value, string $key): bool => $value !== $old[$key], ARRAY_FILTER_USE_BOTH);

        if ($changed !== []) {
            DB::transaction(function () use ($settings, $audit, $values, $old, $changed): void {
                $settings->put($values);

                $audit->record('platform.website.company_updated', null, [
                    'old' => array_intersect_key($old, $changed),
                    'attributes' => $changed,
                ]);
            });
        }

        Toast::success(__('Website details saved.'));

        return back();
    }
}
