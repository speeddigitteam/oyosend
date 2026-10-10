<?php

declare(strict_types=1);

namespace App\Platform\Tenants;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\SubscriptionManager;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
use App\Platform\Mail\WorkspaceReadyMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What an operator does to a workspace as a whole: create it, approve it,
 * suspend it, bring it back.
 *
 * Every action is written to the audit trail twice: in the platform's own
 * trail, and in the workspace's, where its owner can read what was done to
 * their workspace and why.
 */
final class TenantManager
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly SubscriptionManager $subscriptions,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a workspace for a customer. The owner is an existing account
     * with that email, or a new one that is mailed a link to choose a password.
     *
     * @param  array{name: string, slug: string, owner_name: string, owner_email: string, locale: string}  $input
     */
    public function create(array $input, ?Plan $plan, ?BillingInterval $interval): Tenant
    {
        $email = Str::lower(trim($input['owner_email']));

        [$tenant, $owner, $accountCreated] = DB::transaction(function () use ($input, $email, $plan, $interval): array {
            $owner = User::query()->where('email', $email)->first();
            $accountCreated = $owner === null;

            if ($owner === null) {
                $owner = new User(['name' => trim($input['owner_name']), 'email' => $email, 'locale' => $input['locale']]);
                // Nobody knows this password: the owner chooses their own from the emailed link.
                $owner->password = Str::password(40);
                $owner->save();
            }

            $tenant = $this->provisioner->provision(trim($input['name']), $input['slug'], $owner, ['locale' => $input['locale']]);

            if ($plan !== null) {
                $this->context->run($tenant, function () use ($plan, $interval): void {
                    $this->subscriptions->start($plan, $interval ?? $plan->intervals()[0] ?? BillingInterval::Monthly);
                });
            }

            $this->record($tenant, 'created', ['owner' => $email, 'plan' => $plan?->key]);

            return [$tenant, $owner, $accountCreated];
        });

        $this->notifyOwner($tenant, $owner, $accountCreated);

        return $tenant;
    }

    /**
     * Let a sign-up that was waiting for approval in.
     */
    public function approve(Tenant $tenant): void
    {
        if ($tenant->status !== TenantStatus::Pending) {
            throw ValidationException::withMessages(['workspace' => __('This workspace is not waiting for approval.')]);
        }

        DB::transaction(function () use ($tenant): void {
            $tenant->update(['status' => TenantStatus::Active]);

            // The trial starts when the workspace can be used, not when it was asked for.
            $this->context->run($tenant, function (): void {
                $subscription = $this->subscriptions->current();

                if ($subscription?->status === SubscriptionStatus::Trialing && $subscription->plan->trial_days > 0) {
                    $this->subscriptions->extendTrial(Carbon::now()->addDays($subscription->plan->trial_days));
                }
            });

            $this->record($tenant, 'approved');
        });

        if (($owner = $this->ownerOf($tenant)) !== null) {
            $this->notifyOwner($tenant, $owner, false);
        }
    }

    /**
     * Block every request to the workspace. Its data is kept.
     */
    public function suspend(Tenant $tenant, string $reason): void
    {
        if ($tenant->status !== TenantStatus::Active) {
            throw ValidationException::withMessages(['workspace' => __('Only an active workspace can be suspended.')]);
        }

        DB::transaction(function () use ($tenant, $reason): void {
            $tenant->update(['status' => TenantStatus::Suspended, 'suspended_at' => Carbon::now()]);

            $this->record($tenant, 'suspended', ['reason' => $reason]);
        });
    }

    public function reactivate(Tenant $tenant, ?string $reason = null): void
    {
        if ($tenant->status !== TenantStatus::Suspended) {
            throw ValidationException::withMessages(['workspace' => __('This workspace is not suspended.')]);
        }

        DB::transaction(function () use ($tenant, $reason): void {
            $tenant->update(['status' => TenantStatus::Active, 'suspended_at' => null]);

            $this->record($tenant, 'reactivated', array_filter(['reason' => $reason]));
        });
    }

    public function ownerOf(Tenant $tenant): ?User
    {
        // Deliberate cross-tenant read: the platform looks up a workspace's owner.
        return TenantMembership::forTenant($tenant)->where('is_owner', true)->with('user')->first()?->user;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(Tenant $tenant, string $verb, array $properties = []): void
    {
        $this->context->central(fn () => $this->audit->record("platform.workspace.{$verb}", $tenant, $properties));

        // The same fact where the workspace's own people can read it.
        $this->context->run($tenant, fn () => $this->audit->record("workspace.{$verb}", $tenant, $properties));
    }

    private function notifyOwner(Tenant $tenant, User $owner, bool $needsPassword): void
    {
        $this->context->run($tenant, function () use ($tenant, $owner, $needsPassword): void {
            Mail::to($owner->email)
                ->locale($owner->locale ?? $tenant->locale)
                ->queue(new WorkspaceReadyMail($owner, $needsPassword));
        });
    }
}
