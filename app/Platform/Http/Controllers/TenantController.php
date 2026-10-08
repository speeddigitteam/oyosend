<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Core\Support\Like;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only tenant directory. Creating, suspending and impersonating tenants
 * arrive with Phase 2, together with the audited reasons they require.
 */
final class TenantController
{
    public function index(Request $request, TenantHost $hosts): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TenantStatus::class)],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        $tenants = Tenant::query()
            ->withCount('memberships')
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('name', 'like', Like::contains($search))
                ->orWhere('slug', 'like', Like::contains($search))))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Tenant $tenant): array => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'url' => $hosts->url($tenant),
                'status' => $tenant->status->value,
                'locale' => $tenant->locale,
                'currency' => $tenant->currency,
                'timezone' => $tenant->timezone,
                'members' => (int) $tenant->getAttribute('memberships_count'),
                'createdAt' => $tenant->created_at->toIso8601String(),
            ]);

        return Inertia::render('Platform/Pages/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => [
                'search' => $search,
                'status' => $filters['status'] ?? '',
            ],
        ]);
    }
}
