<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\EmailMarketing\Actions\PublicSignup;
use App\Modules\EmailMarketing\Enums\SignupOutcome;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Support\ContentBlocks;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * The public sign-up form of a list and its double opt-in confirmation. Stateless (see `callbacks.php`): no
 * session, cookie, CSRF token or Inertia, and the tenant comes from the hostname only.
 *
 * The form can be framed only when the list's embed option is on (default off); the confirmation pages never
 * can. Submitting answers the same page whatever the address is, and viewing a confirmation link changes nothing:
 * only its POST subscribes. Every invalid link, form token or foreign-workspace value is the same 404 page.
 */
final class SignupController
{
    /** Per tenant and visitor, per hour (the route also throttles per minute). */
    private const int DEFAULT_PER_HOUR = 20;

    public function __construct(
        private readonly PublicSignup $signups,
        private readonly TenantContext $context,
    ) {}

    public function show(string $form): Response
    {
        $this->locale();
        $list = $this->signups->formFor($form);

        return $list === null ? $this->invalid() : $this->form($list);
    }

    public function store(Request $request, string $form): Response
    {
        $this->locale();
        $list = $this->signups->formFor($form);

        if ($list === null) {
            return $this->invalid();
        }

        // A bot fills every field. The answer is the same page a person gets, and nothing is recorded.
        if (trim((string) $request->input('website_url', '')) !== '') {
            return $this->page($list, 'sent');
        }

        $input = [
            'email' => is_string($request->input('email')) ? trim($request->input('email')) : '',
            'first_name' => is_string($request->input('first_name')) ? ContentBlocks::line($request->input('first_name')) : '',
            'last_name' => is_string($request->input('last_name')) ? ContentBlocks::line($request->input('last_name')) : '',
        ];

        if (! $this->signups->trapPasses($list, $request->input('t'))) {
            return $this->form($list, [__('Please wait a moment and send the form again.')], $input, 422);
        }

        $name = ['nullable', 'string', 'max:100', 'not_regex:/[<>@]|:\/\//u'];
        $validator = Validator::make($input, [
            'email' => ['required', 'string', 'max:190'],
            'first_name' => $name,
            'last_name' => $name,
        ], [
            'email.required' => __('Enter your email address.'),
            'first_name.not_regex' => __('Names cannot contain links or the characters < > @.'),
            'last_name.not_regex' => __('Names cannot contain links or the characters < > @.'),
        ]);

        $email = $this->signups->normalizeEmail($input['email']);

        if ($validator->fails() || $email === null) {
            $errors = $validator->errors()->all();

            if ($email === null && ! $validator->errors()->has('email')) {
                $errors[] = __('Enter a valid email address.');
            }

            return $this->form($list, $errors, $input, 422);
        }

        $key = 'em-signup-hour:'.$this->context->requireId().':'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, (int) config('email_marketing.signup.per_hour', self::DEFAULT_PER_HOUR))) {
            return $this->page($list, 'busy', 429);
        }

        RateLimiter::hit($key, 3600);

        $this->signups->request(
            $list,
            $email,
            $input['first_name'] === '' ? null : $input['first_name'],
            $input['last_name'] === '' ? null : $input['last_name'],
        );

        return $this->page($list, 'sent');
    }

    /** Shows the confirm button. Changes nothing, so a mail scanner that opens the link subscribes nobody. */
    public function confirmShow(string $token): Response
    {
        $this->locale();
        $found = $this->signups->find($token);
        $list = $found === null ? null : $this->signups->listOf($found);

        if ($list === null) {
            return $this->invalid();
        }

        return $this->hardened(response()->view('email_marketing::signup.page', [
            'state' => 'confirm',
            'workspace' => $this->context->require()->name,
            'list' => $list->name,
        ]), embeddable: false);
    }

    public function confirmStore(Request $request, string $token): Response
    {
        $this->locale();
        $outcome = $this->signups->confirm($token, (string) $request->userAgent());

        if ($outcome === SignupOutcome::Invalid) {
            return $this->invalid();
        }

        return $this->hardened(response()->view('email_marketing::signup.page', [
            'state' => $outcome->value,
            'workspace' => $this->context->require()->name,
        ], $outcome === SignupOutcome::Unavailable ? 503 : 200), embeddable: false);
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, string>  $old
     */
    private function form(MailingList $list, array $errors = [], array $old = [], int $status = 200): Response
    {
        return $this->hardened(response()->view('email_marketing::signup.page', [
            'state' => 'form',
            'workspace' => $this->context->require()->name,
            'list' => $list->name,
            'errors' => $errors,
            'old' => $old,
            'trap' => $this->signups->trap($list),
        ], $status), embeddable: $list->signup_embed);
    }

    private function page(MailingList $list, string $state, int $status = 200): Response
    {
        return $this->hardened(response()->view('email_marketing::signup.page', [
            'state' => $state,
            'workspace' => $this->context->require()->name,
            'list' => $list->name,
            'days' => PublicSignup::TTL_DAYS,
            'errors' => [],
        ], $status), embeddable: $list->signup_embed);
    }

    private function invalid(): Response
    {
        return $this->hardened(response()->view('email_marketing::signup.page', [
            'state' => 'invalid',
            'workspace' => $this->context->require()->name,
            'errors' => [],
        ], 404), embeddable: false);
    }

    /**
     * No scripts, no external resources, no referrer, no caching. Framing is refused unless the list's embed
     * option is on and the page is the form itself.
     */
    private function hardened(Response $response, bool $embeddable): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors ".($embeddable ? '*' : "'none'"),
        );

        if (! $embeddable) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        return $response;
    }

    private function locale(): void
    {
        App::setLocale($this->context->require()->locale);
    }
}
