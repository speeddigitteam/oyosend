<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Core\Audit\Models\AuditLog;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Platform\Support\SystemHealth;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __invoke(SystemHealth $health, ModuleRegistry $modules): Response
    {
        $byStatus = Tenant::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Platform/Pages/Dashboard', [
            'stats' => [
                'tenants' => (int) $byStatus->sum(),
                'active' => (int) ($byStatus[TenantStatus::Active->value] ?? 0),
                'suspended' => (int) ($byStatus[TenantStatus::Suspended->value] ?? 0),
                'pending' => (int) ($byStatus[TenantStatus::Pending->value] ?? 0),
                'newThisMonth' => Tenant::query()->where('created_at', '>=', Carbon::now()->startOfMonth())->count(),
                'users' => User::query()->count(),
                // Deliberate cross-tenant count: this is the platform's own view.
                'memberships' => TenantMembership::withoutTenancy()->count(),
                'modules' => count($modules->all()),
            ],
            'recentTenants' => Tenant::query()
                ->withCount('memberships')
                ->latest()
                ->limit(6)
                ->get()
                ->map(fn (Tenant $tenant): array => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status->value,
                    'members' => (int) $tenant->getAttribute('memberships_count'),
                    'createdAt' => $tenant->created_at->toIso8601String(),
                ])
                ->all(),
            // Platform-level entries only (no tenant): sign-ins to this panel and system events.
            'activity' => AuditLog::withoutTenancy()
                ->whereNull('tenant_id')
                ->latest('created_at')
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (AuditLog $entry): array => [
                    'id' => $entry->id,
                    'event' => $entry->event,
                    'actor' => $entry->actor_label,
                    'subject' => $entry->subject_label,
                    'ipAddress' => $entry->ip_address,
                    'createdAt' => $entry->created_at->toIso8601String(),
                ])
                ->all(),
            'health' => $health->checks(),
        ]);
    }
}
