<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Changes the company profile and regional settings of a workspace. Used by
 * the settings page and by first-run setup.
 */
final class UpdateWorkspaceProfile
{
    /** Attributes a workspace may change about itself. Its address (slug) is not one of them. */
    private const array EDITABLE = [
        'name', 'legal_name', 'email', 'phone', 'website', 'address', 'city', 'postal_code', 'country', 'tax_id',
        'locale', 'timezone', 'currency',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Validation rules for every editable attribute.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var array<string, string> $locales */
        $locales = config('platform.locales', []);

        /** @var list<string> $currencies */
        $currencies = config('platform.currencies', []);

        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'tax_id' => ['nullable', 'string', 'max:60'],
            'locale' => ['required', Rule::in(array_keys($locales))],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', Rule::in($currencies)],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes  Validated against {@see rules()}; other keys are ignored.
     */
    public function handle(Tenant $tenant, array $attributes): Tenant
    {
        $tenant->fill(array_intersect_key($attributes, array_flip(self::EDITABLE)));

        // Only what this action may change. A tenant loaded from the database
        // also reports its `data` column as dirty: the tenancy package empties
        // it in memory. That is no change to the workspace and nothing to record.
        $changes = array_intersect_key($tenant->getDirty(), array_flip(self::EDITABLE));

        if ($changes === []) {
            return $tenant;
        }

        DB::transaction(function () use ($tenant, $changes): void {
            $old = array_intersect_key($tenant->getRawOriginal(), $changes);

            $tenant->save();

            $this->audit->record('workspace.settings_updated', $tenant, ['old' => $old, 'attributes' => $changes]);
        });

        return $tenant;
    }
}
