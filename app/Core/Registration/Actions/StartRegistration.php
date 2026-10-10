<?php

declare(strict_types=1);

namespace App\Core\Registration\Actions;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Models\Plan;
use App\Core\Registration\Mail\ConfirmRegistrationMail;
use App\Core\Registration\Models\WorkspaceRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Takes a sign-up and emails the link that confirms it.
 *
 * Nothing else happens yet - no account, no workspace. That keeps an address
 * that was never confirmed from owning anything, and makes the answer the
 * same whether or not the email already has an account: the form never says.
 */
final class StartRegistration
{
    /**
     * @param  array{name: string, email: string, password: string, company: string, slug: string, locale: string}  $input
     */
    public function handle(
        #[SensitiveParameter] array $input,
        ?Plan $plan,
        ?BillingInterval $interval,
        ?string $ipAddress = null,
    ): WorkspaceRegistration {
        $email = Str::lower(trim($input['email']));

        // The token is shown once, by email, and only its hash is kept.
        $token = Str::random(64);

        $registration = DB::transaction(function () use ($input, $email, $plan, $interval, $ipAddress, $token): WorkspaceRegistration {
            // Signing up again replaces the earlier attempt and frees the address it held.
            WorkspaceRegistration::query()->where('email', $email)->whereNull('completed_at')->delete();

            return WorkspaceRegistration::query()->create([
                'email' => $email,
                'name' => trim($input['name']),
                // Only used if the address turns out to have no account yet.
                'password' => Hash::make($input['password']),
                'company' => trim($input['company']),
                'slug' => Str::lower(trim($input['slug'])),
                'locale' => $input['locale'],
                'plan_id' => $plan?->getKey(),
                'interval' => $plan === null ? null : $interval,
                'token_hash' => WorkspaceRegistration::hashToken($token),
                'expires_at' => Carbon::now()->addHours((int) config('platform.registration.verification_ttl_hours', 24)),
                'ip_address' => $ipAddress,
            ]);
        });

        Mail::to($email)->locale($registration->locale)->queue(new ConfirmRegistrationMail($registration, $token));

        return $registration;
    }
}
