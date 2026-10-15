<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Core\Billing\CurrentSubscription;
use App\Core\Tenancy\TenantContext;
use App\Modules\Support\Actions\LiveChatManager;
use App\Modules\Support\Actions\LiveChatSettings;
use App\Modules\Support\Enums\VisitorDetails;
use App\Modules\Support\Models\ChatConversation;
use App\Modules\Support\Models\ChatMessage;
use App\Modules\Support\Models\ChatSetting;
use App\Modules\Support\Support\ChatText;
use App\Modules\Support\Support\LiveChatLimits;
use App\Modules\Support\Support\VisitorToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The public side of the website live chat (ADR 0032, docs/SUPPORT.md section 15). Stateless: loaded through
 * `loadTenantCallbackRoutes()`, so there is no session, no cookie, no CSRF token and no Inertia, and the workspace
 * comes from the hostname only.
 *
 *  - `widget.js` is a small loader the workspace puts on its website: a launcher button and an iframe of the frame.
 *  - `frame` is a standalone page that the allowed origins (and nobody else) may embed (`frame-ancestors`).
 *  - the JSON API is called by the frame only: same origin, a visitor token in the `X-Chat-Visitor` header, which
 *    reaches one conversation of this workspace. A missing, wrong or foreign token is a 404.
 *
 * Everything answers 404 while the chat is off, the workspace is suspended or locked, or not entitled to Support.
 */
final class LiveChatPublicController
{
    private const string ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/iD';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LiveChatSettings $settings,
        private readonly LiveChatManager $chats,
        private readonly CurrentSubscription $subscription,
    ) {}

    /** The loader script. Small, framework-free, and it requests nothing until the visitor opens the chat. */
    public function widget(Request $request): Response
    {
        $settings = $this->available();

        if ($settings === null) {
            return $this->missing();
        }

        App::setLocale($this->context->require()->locale);

        $response = response()->view('support.live-chat.widget', ['config' => [
            'frame' => route('support.live_chat.frame'),
            'origin' => $request->getSchemeAndHttpHost(),
            'allowed' => $settings->origins(),
            'label' => $settings->widget_title ?? __('Chat with us'),
        ]]);
        $response->headers->set('Content-Type', 'application/javascript; charset=utf-8');
        $response->headers->set('Cache-Control', 'public, max-age=300');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Loaded by other sites as a script: allow that even where they require cross-origin resource policies.
        $response->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');

        return $response;
    }

    /** The chat page inside the iframe: inline CSS and script under a per-response nonce, framed by allowed origins only. */
    public function frame(Request $request): Response
    {
        $settings = $this->available();

        if ($settings === null) {
            return $this->missing();
        }

        $tenant = $this->context->require();
        App::setLocale($tenant->locale);
        $nonce = base64_encode(random_bytes(18));
        $origins = $settings->origins();
        $title = $settings->widget_title ?? __('Chat with us');

        $response = response()->view('support.live-chat.frame', [
            'nonce' => $nonce,
            'title' => $title,
            'greeting' => $settings->greeting,
            'offline' => $settings->offline_note,
            'details' => $settings->visitor_details,
            'config' => [
                'api' => ['start' => route('support.live_chat.api.start'), 'messages' => route('support.live_chat.api.send')],
                'origins' => $origins,
                'pollSeconds' => 5,
                'team' => $tenant->name,
                'strings' => [
                    'you' => __('You'),
                    'sending' => __('Sending…'),
                    'empty' => __('Write a message.'),
                    'failed' => __('The message could not be sent. Try again.'),
                    'offline' => __('No connection. Check your internet connection and try again.'),
                    'slowDown' => __('Too many messages. Wait a moment and try again.'),
                    'expired' => __('This chat is no longer available. Start a new one.'),
                ],
            ],
        ]);

        $ancestors = $origins === [] ? "'none'" : implode(' ', $origins);
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'nonce-{$nonce}'",
            "style-src 'nonce-{$nonce}'",
            "img-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'none'",
            "frame-ancestors {$ancestors}",
        ]));

        if ($origins === []) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        return $response;
    }

    /** POST api/conversations: the first message, optional or required name and email, a honeypot, the page. */
    public function start(Request $request): JsonResponse
    {
        $settings = $this->available();

        if ($settings === null) {
            return $this->json(['message' => 'Not found.'], 404);
        }

        if (! $this->sameOrigin($request)) {
            return $this->json(['message' => 'Forbidden.'], 403);
        }

        App::setLocale($this->context->require()->locale);

        // A script fills every field. Nothing is recorded and nothing says why.
        if (! in_array($request->input('website'), [null, ''], true)) {
            return $this->json(['message' => __('This chat could not be started.')], 422);
        }

        // One conversation per visitor token: a browser that already has one keeps writing there.
        if ($this->chats->forToken($request->header(VisitorToken::HEADER)) !== null) {
            return $this->json(['message' => __('This browser already has a chat open here.')], 409);
        }

        try {
            [$name, $email] = $this->visitor($request, $settings->visitor_details);
        } catch (ValidationException $invalid) {
            return $this->invalid($invalid);
        }

        $tenantId = $this->context->requireId();
        $byAddress = 'support-chat-started:'.$tenantId.':'.LiveChatLimits::addressKey($request);
        $byWorkspace = 'support-chat-started:'.$tenantId;

        if (RateLimiter::tooManyAttempts($byAddress, LiveChatLimits::STARTS_PER_ADDRESS_PER_HOUR)
            || RateLimiter::tooManyAttempts($byWorkspace, LiveChatLimits::STARTS_PER_WORKSPACE_PER_HOUR)) {
            return $this->json(['message' => __('Too many chats were started from here. Try again later.')], 429, [
                'Retry-After' => (string) max(RateLimiter::availableIn($byAddress), RateLimiter::availableIn($byWorkspace), 1),
            ]);
        }

        try {
            $started = $this->chats->start($request->input('text'), $name, $email, $this->pageUrl($request->input('page_url')));
        } catch (ValidationException $invalid) {
            return $this->invalid($invalid);
        }

        RateLimiter::hit($byAddress, 3600);
        RateLimiter::hit($byWorkspace, 3600);

        return $this->json([
            'token' => $started['token'],
            'status' => $started['conversation']->status->value,
            'messages' => [$this->present($started['message'])],
        ], 201);
    }

    /** POST api/messages: the visitor writes. A closed conversation opens again. */
    public function send(Request $request): JsonResponse
    {
        $conversation = $this->conversation($request);

        if ($conversation instanceof JsonResponse) {
            return $conversation;
        }

        try {
            $message = $this->chats->receive($conversation, $request->input('text'));
        } catch (ValidationException $invalid) {
            return $this->invalid($invalid);
        }

        return $this->json(['status' => 'open', 'message' => $this->present($message)], 201);
    }

    /** GET api/messages?after={id}: messages after the given one (oldest first), or the newest ones without it. */
    public function poll(Request $request): JsonResponse
    {
        $conversation = $this->conversation($request);

        if ($conversation instanceof JsonResponse) {
            return $conversation;
        }

        $after = $request->query('after');

        if ($after !== null && (! is_string($after) || preg_match(self::ULID, $after) !== 1)) {
            return $this->json(['message' => __('The request is not valid.')], 422);
        }

        $messages = ChatMessage::query()->where('conversation_id', $conversation->id);
        $afterSeq = $after === null ? null : (clone $messages)->whereKey($after)->value('seq');
        $more = false;

        if ($afterSeq !== null) {
            $rows = $messages->where('seq', '>', $afterSeq)->orderBy('seq')->limit(LiveChatLimits::POLL_BATCH + 1)->get();
            $more = $rows->count() > LiveChatLimits::POLL_BATCH;
            $rows = $rows->take(LiveChatLimits::POLL_BATCH);
        } else {
            $rows = $messages->orderByDesc('seq')->limit(LiveChatLimits::POLL_BATCH)->get()->reverse();
        }

        return $this->json([
            'status' => $conversation->status->value,
            'messages' => $rows->map(fn (ChatMessage $message): array => $this->present($message))->values()->all(),
            'more' => $more,
        ]);
    }

    /** The settings, while the chat may answer at all; null means 404. */
    private function available(): ?ChatSetting
    {
        if (! $this->context->require()->isAccessible()) {
            return null;
        }

        $subscription = $this->subscription->get();

        if ($subscription !== null && ! $subscription->grantsAccess()) {
            return null;
        }

        $settings = $this->settings->find();

        return $settings !== null && $settings->enabled ? $settings : null;
    }

    /**
     * The API is for the frame only, which runs on this very origin. A browser always says where a POST comes from
     * (`Origin`); a same-origin GET carries `Sec-Fetch-Site: same-origin` instead.
     */
    private function sameOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        if ($origin !== null) {
            return $origin === $request->getSchemeAndHttpHost();
        }

        return $request->headers->get('Sec-Fetch-Site') === 'same-origin';
    }

    /** The token holder's conversation in this workspace, or the response that refuses the request. */
    private function conversation(Request $request): ChatConversation|JsonResponse
    {
        if ($this->available() === null) {
            return $this->json(['message' => 'Not found.'], 404);
        }

        if (! $this->sameOrigin($request)) {
            return $this->json(['message' => 'Forbidden.'], 403);
        }

        App::setLocale($this->context->require()->locale);

        return $this->chats->forToken($request->header(VisitorToken::HEADER)) ?? $this->json(['message' => 'Not found.'], 404);
    }

    /**
     * Name and email as the settings ask for them. Ignored when not asked.
     *
     * @return array{0: string|null, 1: string|null}
     *
     * @throws ValidationException
     */
    private function visitor(Request $request, VisitorDetails $details): array
    {
        if ($details === VisitorDetails::None) {
            return [null, null];
        }

        $rawName = $request->input('name');
        $rawEmail = $request->input('email');
        $name = is_string($rawName) ? ChatText::line($rawName) : null;
        $email = is_string($rawEmail) ? Str::lower(trim($rawEmail)) : null;
        $name = $name === '' ? null : $name;
        $email = $email === '' ? null : $email;
        $errors = [];

        if (is_string($rawName) && $name === null && trim($rawName) !== '') {
            $errors['name'] = __('Enter your name as plain text.');
        } elseif ($name !== null && mb_strlen($name) > LiveChatLimits::MAX_NAME) {
            $errors['name'] = __('A name can be at most :max characters.', ['max' => LiveChatLimits::MAX_NAME]);
        } elseif ($name === null && $details === VisitorDetails::Required) {
            $errors['name'] = __('Enter your name.');
        }

        if ($email !== null && (strlen($email) > LiveChatLimits::MAX_EMAIL || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = __('Enter a valid email address.');
        } elseif ($email === null && $details === VisitorDetails::Required) {
            $errors['email'] = __('Enter your email address.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$name, $email];
    }

    /** Scheme, host and path of the visitor's page (http or https only): the query and fragment may hold secrets. */
    private function pageUrl(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 2048) {
            return null;
        }

        $parts = parse_url(trim($value));

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! isset($parts['host']) || isset($parts['user'])) {
            return null;
        }

        $url = strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
        $url = ChatText::line($url);

        return $url === null || $url === '' ? null : mb_substr($url, 0, LiveChatLimits::MAX_PAGE_URL);
    }

    /** @return array{id: string, direction: string, text: string, at: string} */
    private function present(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'direction' => $message->direction->value,
            'text' => $message->text,
            'at' => $message->created_at->utc()->toIso8601ZuluString(),
        ];
    }

    private function invalid(ValidationException $invalid): JsonResponse
    {
        $errors = array_map(static fn (array $messages): array => array_values(array_map('strval', $messages)), $invalid->errors());

        return $this->json(['message' => (string) (Arr::first(Arr::flatten($errors)) ?? __('The request is not valid.')), 'errors' => $errors], 422);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function json(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            ...$headers,
        ]);
    }

    /** A plain 404 that no page can frame. */
    private function missing(): Response
    {
        return new Response('Not found.', 404, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
    }
}
