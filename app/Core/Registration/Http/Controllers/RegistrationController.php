<?php

declare(strict_types=1);

namespace App\Core\Registration\Http\Controllers;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\Support\BillingPresenter;
use App\Core\Identity\Auth\WorkspaceHandoff;
use App\Core\Registration\Actions\CompleteRegistration;
use App\Core\Registration\Actions\StartRegistration;
use App\Core\Registration\Exceptions\InvalidRegistration;
use App\Core\Registration\Models\WorkspaceRegistration;
use App\Core\Registration\Rules\AvailableWorkspaceAddress;
use App\Core\Settings\Enums\RegistrationMode;
use App\Core\Settings\PlatformSettings;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Signing up for a workspace on the central site.
 *
 *  1. the form is submitted and a confirmation link is emailed;
 *  2. the link opens a page with one button - a page, not an action, so a
 *     mail scanner that follows links cannot use the link up;
 *  3. pressing it creates the account and the workspace.
 */
final class RegistrationController
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly TenantHost $hosts,
    ) {}

    public function create(Request $request): Response
    {
        $this->abortWhenClosed();

        $plans = Plan::query()->offered()->get();
        $requested = $request->query('plan');

        return Inertia::render('Core/Pages/Central/Register', [
            'domain' => $this->hosts->domain(),
            'plans' => $plans->map(BillingPresenter::plan(...))->all(),
            // A plan picked on the landing page; otherwise the first one on offer.
            'selectedPlan' => ($plans->firstWhere('key', is_string($requested) ? $requested : null) ?? $plans->first())?->key,
            'needsApproval' => $this->settings->registrationMode() === RegistrationMode::Approval,
            // What the website's start form was given, to begin the form with (see start()).
            'prefill' => $this->prefill($request),
        ]);
    }

    /**
     * The website's "start your workspace" form: the company and email typed there are carried to the sign-up form
     * in the session, never in the address, where an email would end up in logs and histories.
     */
    public function start(Request $request): RedirectResponse
    {
        $this->abortWhenClosed();

        $input = $request->validate([
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
        ]);

        $email = trim((string) ($input['email'] ?? ''));

        $request->session()->flash('registration.prefill', [
            'company' => trim((string) ($input['company'] ?? '')),
            // A typo here is fixed on the next page; only a well-formed address is carried.
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
        ]);

        return redirect()->route('central.register');
    }

    public function store(Request $request, StartRegistration $start): RedirectResponse
    {
        $this->abortWhenClosed();

        /** @var array<string, string> $locales */
        $locales = config('platform.locales', []);
        $plans = Plan::query()->offered()->get();

        /** @var array{name: string, email: string, password: string, company: string, slug: string, locale: string, plan?: string|null, interval?: string|null} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'company' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:63', new AvailableWorkspaceAddress],
            'locale' => ['required', Rule::in(array_keys($locales))],
            // Where plans are on offer, one of them has to be chosen.
            'plan' => [$plans->isEmpty() ? 'nullable' : 'required', 'string', Rule::in($plans->pluck('key')->all())],
            'interval' => ['nullable', Rule::enum(BillingInterval::class)],
        ]);

        /** @var Plan|null $plan */
        $plan = $plans->firstWhere('key', $input['plan'] ?? null);
        $interval = BillingInterval::tryFrom((string) ($input['interval'] ?? '')) ?? BillingInterval::Monthly;

        if ($plan !== null && ! $plan->offers($interval)) {
            $interval = $plan->intervals()[0] ?? $interval;
        }

        $start->handle($input, $plan, $interval, $request->ip());

        // The same page whether or not the address already has an account.
        return redirect()->route('central.register.sent')->with('registration_email', $input['email']);
    }

    public function sent(Request $request): Response|RedirectResponse
    {
        $email = $request->session()->get('registration_email');

        if (! is_string($email)) {
            return redirect()->route('central.register');
        }

        // Survives a reload of this page, not a visit elsewhere.
        $request->session()->keep('registration_email');

        return Inertia::render('Core/Pages/Central/RegistrationSent', [
            'email' => $email,
            'expiresInHours' => (int) config('platform.registration.verification_ttl_hours', 24),
        ]);
    }

    /**
     * The emailed link. Shows what is about to be created; creates nothing.
     */
    public function confirm(string $token): Response
    {
        $registration = WorkspaceRegistration::query()
            ->where('token_hash', WorkspaceRegistration::hashToken($token))
            ->with('plan:id,name')
            ->first();

        if ($registration === null || $this->settings->registrationMode() === RegistrationMode::Closed) {
            return $this->outcome('invalid');
        }

        // Already confirmed (perhaps in another tab): point at the workspace instead of failing -
        // unless it still waits for approval, where a link to its sign-in would lead nowhere.
        if ($registration->completed_at !== null) {
            $awaitsApproval = Tenant::query()
                ->whereKey($registration->tenant_id)
                ->where('status', TenantStatus::Pending)
                ->exists();

            return $this->outcome($awaitsApproval ? 'pending' : 'ready', $registration->company, $registration->slug);
        }

        if (! $registration->isWaiting()) {
            return $this->outcome('invalid');
        }

        return Inertia::render('Core/Pages/Central/ConfirmRegistration', [
            'state' => 'confirm',
            'token' => $token,
            'company' => $registration->company,
            'email' => $registration->email,
            'address' => parse_url($this->hosts->url($registration->slug), PHP_URL_HOST),
            'plan' => $registration->plan?->name,
            'loginUrl' => null,
            'registrationOpen' => true,
        ]);
    }

    public function complete(string $token, CompleteRegistration $complete, WorkspaceHandoff $handoff): HttpResponse
    {
        try {
            $result = $complete->handle($token);
        } catch (InvalidRegistration) {
            return $this->outcome('invalid')->toResponse(request());
        }

        if ($result->awaitsApproval()) {
            return $this->outcome('pending', $result->tenant->name, $result->tenant->slug)->toResponse(request());
        }

        // A brand-new account was just proven by its email link and has no
        // second factor yet: take it straight in. An existing account signs
        // in the usual way, with its own password and second factor.
        if ($result->accountCreated) {
            return Inertia::location($handoff->urlFor($result->user, $result->tenant));
        }

        return $this->outcome('ready', $result->tenant->name, $result->tenant->slug)->toResponse(request());
    }

    private function outcome(string $state, ?string $company = null, ?string $slug = null): Response
    {
        return Inertia::render('Core/Pages/Central/ConfirmRegistration', [
            'state' => $state,
            'token' => null,
            'company' => $company,
            'email' => null,
            'address' => $slug === null ? null : parse_url($this->hosts->url($slug), PHP_URL_HOST),
            'plan' => null,
            'loginUrl' => $slug === null || $state !== 'ready' ? null : $this->hosts->url($slug, 'login'),
            // Whether the page may offer to sign up again.
            'registrationOpen' => $this->settings->registrationMode() !== RegistrationMode::Closed,
        ]);
    }

    /**
     * @return array{company: string, email: string}
     */
    private function prefill(Request $request): array
    {
        $carried = $request->session()->get('registration.prefill');
        $carried = is_array($carried) ? $carried : [];

        return [
            'company' => is_string($carried['company'] ?? null) ? mb_substr($carried['company'], 0, 255) : '',
            'email' => is_string($carried['email'] ?? null) ? $carried['email'] : '',
        ];
    }

    private function abortWhenClosed(): void
    {
        abort_if($this->settings->registrationMode() === RegistrationMode::Closed, 404);
    }
}
