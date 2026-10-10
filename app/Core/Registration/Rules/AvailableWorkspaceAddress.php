<?php

declare(strict_types=1);

namespace App\Core\Registration\Rules;

use App\Core\Registration\Models\WorkspaceRegistration;
use App\Core\Tenancy\Models\Domain;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * A workspace address (subdomain label) nobody has: well-formed, not
 * reserved, not taken by a workspace - a deleted one included, its address is
 * not handed out again - and not held by a sign-up that can still be confirmed.
 */
final class AvailableWorkspaceAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $slug = is_string($value) ? Str::lower(trim($value)) : '';
        $hosts = app(TenantHost::class);

        if (preg_match('/^'.TenantHost::LABEL_PATTERN.'$/D', $slug) !== 1 || strlen($slug) < 3) {
            $fail(__('Use 3 to 63 lower-case letters, digits or hyphens, starting and ending with a letter or digit.'));

            return;
        }

        $taken = $hosts->isReserved($slug)
            || Tenant::withTrashed()->where('slug', $slug)->exists()
            || Domain::query()->where('domain', $slug)->exists()
            || WorkspaceRegistration::query()->waiting()->where('slug', $slug)->exists();

        if ($taken) {
            $fail(__('This workspace address is not available.'));
        }
    }
}
