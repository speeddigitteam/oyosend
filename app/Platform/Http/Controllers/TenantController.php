<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Core\Audit\Models\AuditLog;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\Support\BillingPresenter;
use App\Core\Registration\Rules\AvailableWorkspaceAddress;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use App\Platform\Impersonation\Impersonation;
use App\Platform\Tenants\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace directory of the platform: find a workspace, look into it,
 * create one, approve, suspend and reactivate it.
 */
final class TenantController
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly TenantHost $hosts,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TenantStatus::class)],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));

        $tenants = Tenant::query()
            ->withCount('memberships')
            ->with('subscription.plan:id,name')
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
                'url' => $this->hosts->url($tenant),
                'status' => $tenant->status->value,
                'locale' => $tenant->locale,
                'currency' => $tenant->currency,
                'timezone' => $tenant->timezone,
                'members' => (int) $tenant->getAttribute('memberships_count'),
                'plan' => $tenant->subscription?->plan->name,
                'subscriptionStatus' => $tenant->subscription?->status->value,
                'createdAt' => $tenant->created_at->toIso8601String(),
            ]);

        return Inertia::render('Platform/Pages/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => [
                'search' => $search,
                'status' => $filters['status'] ?? '',
            ],
            'canManage' => $request->user('platform')?->can('platform.tenants.manage') === true,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Platform/Pages/Tenants/Create', [
            'domain' => $this->hosts->domain(),
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
                ->map(BillingPresenter::plan(...))
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array<string, string> $locales */
        $locales = config('platform.locales', []);

        /** @var array{name: string, slug: string, owner_name: string, owner_email: string, locale: string, plan_id?: string|null, interval?: string|null} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:63', new AvailableWorkspaceAddress],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'locale' => ['required', Rule::in(array_keys($locales))],
            'plan_id' => ['nullable', 'string', 'size:26'],
            'interval' => ['nullable', Rule::enum(BillingInterval::class)],
        ]);

        $plan = isset($input['plan_id']) ? Plan::query()->where('is_active', true)->findOrFail($input['plan_id']) : null;
        $interval = BillingInterval::tryFrom((string) ($input['interval'] ?? ''));

        if ($plan !== null && $interval !== null && ! $plan->offers($interval)) {
            $interval = null;
        }

        $tenant = $this->tenants->create($input, $plan, $interval);

        Toast::success(__('Workspace ":name" created. Its owner has been sent an email.', ['name' => $tenant->name]));

        return redirect()->route('platform.tenants.show', ['workspace' => $tenant->getKey()]);
    }

    public function show(Request $request, Tenant $workspace): Response
    {
        $operator = $request->user('platform');
        $workspace->load(['subscription.plan', 'subscription.pendingPlan']);
        $owner = $this->tenants->ownerOf($workspace);

        return Inertia::render('Platform/Pages/Tenants/Show', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'legalName' => $workspace->legal_name,
                'slug' => $workspace->slug,
                'url' => $this->hosts->url($workspace),
                'status' => $workspace->status->value,
                'locale' => $workspace->locale,
                'timezone' => $workspace->timezone,
                'currency' => $workspace->currency,
                'email' => $workspace->email,
                'phone' => $workspace->phone,
                'members' => $workspace->memberships()->count(),
                'owner' => $owner?->only(['name', 'email']),
                'onboarded' => $workspace->isOnboarded(),
                'suspendedAt' => $workspace->suspended_at?->toIso8601String(),
                'createdAt' => $workspace->created_at->toIso8601String(),
            ],
            'subscription' => BillingPresenter::subscription($workspace->subscription),
            // Deliberate cross-tenant reads from here on: this is the platform's view of one workspace.
            'invoices' => Invoice::forTenant($workspace)
                ->latest('issued_at')
                ->latest('id')
                ->limit(12)
                ->get()
                ->map(BillingPresenter::invoice(...))
                ->all(),
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
                ->map(BillingPresenter::plan(...))
                ->all(),
            'activity' => AuditLog::forTenant($workspace)
                ->latest('created_at')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (AuditLog $entry): array => [
                    'id' => $entry->id,
                    'event' => $entry->event,
                    'actor' => $entry->actor_label,
                    'subject' => $entry->subject_label,
                    'createdAt' => $entry->created_at->toIso8601String(),
                ])
                ->all(),
            'supportSessions' => Impersonation::query()
                ->where('tenant_id', $workspace->getKey())
                ->with('platformUser:id,name,email')
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (Impersonation $session): array => [
                    'id' => $session->id,
                    'by' => $session->platformUser->email,
                    'reason' => $session->reason,
                    'startedAt' => $session->started_at?->toIso8601String(),
                    'endedAt' => $session->ended_at?->toIso8601String(),
                    'expiresAt' => $session->expires_at->toIso8601String(),
                    'createdAt' => $session->created_at->toIso8601String(),
                ])
                ->all(),
            'can' => [
                'manage' => $operator?->can('platform.tenants.manage') === true,
                'impersonate' => $operator?->can('platform.tenants.impersonate') === true,
                'manageBilling' => $operator?->can('platform.billing.manage') === true,
            ],
        ]);
    }

    public function approve(Tenant $workspace): RedirectResponse
    {
        $this->tenants->approve($workspace);

        Toast::success(__('Workspace approved. Its owner has been sent an email.'));

        return back();
    }

    public function suspend(Request $request, Tenant $workspace): RedirectResponse
    {
        $input = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->tenants->suspend($workspace, $input['reason']);

        Toast::success(__('Workspace suspended.'));

        return back();
    }

    public function reactivate(Request $request, Tenant $workspace): RedirectResponse
    {
        $input = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->tenants->reactivate($workspace, $input['reason'] ?? null);

        Toast::success(__('Workspace reactivated.'));

        return back();
    }
}
