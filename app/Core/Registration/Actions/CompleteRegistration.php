<?php

declare(strict_types=1);

namespace App\Core\Registration\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\SubscriptionManager;
use App\Core\Identity\Models\User;
use App\Core\Registration\Exceptions\InvalidRegistration;
use App\Core\Registration\Models\WorkspaceRegistration;
use App\Core\Registration\RegistrationResult;
use App\Core\Settings\Enums\RegistrationMode;
use App\Core\Settings\PlatformSettings;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Domain;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a confirmed sign-up into an account (unless the address already has
 * one), a workspace it owns, and that workspace's subscription.
 *
 * Opening the emailed link is what proves control of the address. For an
 * address that already has an account that is all the proof asked for - the
 * same proof a password reset rests on - and the account, its password and
 * its second factor are left exactly as they are.
 */
final class CompleteRegistration
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly SubscriptionManager $subscriptions,
        private readonly PlatformSettings $settings,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws InvalidRegistration
     */
    public function handle(string $token): RegistrationResult
    {
        $mode = $this->settings->registrationMode();

        if ($mode === RegistrationMode::Closed) {
            throw new InvalidRegistration('Registration is closed.');
        }

        return DB::transaction(function () use ($token, $mode): RegistrationResult {
            // Under a lock, so a link cannot be confirmed twice in parallel.
            $registration = WorkspaceRegistration::query()
                ->where('token_hash', WorkspaceRegistration::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($registration === null || ! $registration->isWaiting()) {
                throw new InvalidRegistration('This confirmation link is no longer valid.');
            }

            // Reserved since sign-up, but a workspace made from the panel may have taken it.
            if (Tenant::withTrashed()->where('slug', $registration->slug)->exists()
                || Domain::query()->where('domain', $registration->slug)->exists()) {
                throw new InvalidRegistration('The workspace address has been taken.');
            }

            $user = User::query()->where('email', $registration->email)->first();
            $accountCreated = $user === null;

            if ($user === null) {
                $user = new User(['name' => $registration->name, 'email' => $registration->email, 'locale' => $registration->locale]);
                // Already hashed when the sign-up was taken.
                $user->password = $registration->password;
            }

            $user->email_verified_at ??= Carbon::now();
            $user->save();

            $tenant = $this->provisioner->provision($registration->company, $registration->slug, $user, [
                'locale' => $registration->locale,
                'status' => $mode === RegistrationMode::Approval ? TenantStatus::Pending : TenantStatus::Active,
                'onboarded' => false,
            ]);

            $plan = $registration->plan_id === null
                ? null
                : Plan::query()->where('is_active', true)->find($registration->plan_id);

            $this->context->run($tenant, function () use ($plan, $registration, $user, $tenant): void {
                if ($plan !== null) {
                    $interval = $registration->interval ?? BillingInterval::Monthly;

                    $this->subscriptions->start($plan, $plan->offers($interval) ? $interval : $plan->intervals()[0]);
                }

                $this->audit->record('workspace.registered', $tenant, ['plan' => $plan?->key], $user);
            });

            // And once in the platform's own trail, where sign-ups are watched.
            $this->context->central(fn () => $this->audit->record(
                'platform.workspace.registered',
                $tenant,
                ['status' => $tenant->status->value, 'plan' => $plan?->key, 'owner' => $user->email],
                $user,
            ));

            $registration->update(['completed_at' => Carbon::now(), 'tenant_id' => $tenant->getKey()]);

            return new RegistrationResult($tenant, $user, $accountCreated);
        });
    }
}
