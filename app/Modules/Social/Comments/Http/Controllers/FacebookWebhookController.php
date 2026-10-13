<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Comments\Ingest\WebhookReceiver;
use App\Modules\Social\Platforms\Facebook\FacebookCommentsWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Facebook Page webhooks. Stateless: no session, no cookies. On the workspace host the workspace is the hostname's;
 * on the central host (the one callback URL a Meta app can have) it is the one that claimed the Page. Either way
 * nothing is read before the signature is verified, and a request that fails changes nothing.
 */
final class FacebookWebhookController
{
    public function __construct(
        private readonly WebhookReceiver $receiver,
        private readonly TenantContext $context,
    ) {}

    /** Workspace host. */
    public function receive(Request $request): Response
    {
        return $this->respond($this->receiver->receive($request->getContent(), $request->header('X-Hub-Signature-256'), $this->context->requireId()));
    }

    /** Central host. */
    public function receiveCentral(Request $request): Response
    {
        return $this->respond($this->receiver->receive($request->getContent(), $request->header('X-Hub-Signature-256'), null));
    }

    /** The one-time subscription handshake (`hub.mode=subscribe`). */
    public function verify(Request $request): Response
    {
        $expected = config('social_comments.facebook.verify_token');
        $challenge = FacebookCommentsWebhook::handshake(
            $this->query($request, 'hub_mode', 'hub.mode'),
            $this->query($request, 'hub_verify_token', 'hub.verify_token'),
            $this->query($request, 'hub_challenge', 'hub.challenge'),
            is_string($expected) ? $expected : null,
        );

        return $challenge === null
            ? response('Forbidden', 403)->header('Content-Type', 'text/plain')
            : response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /** @param array{0: int, 1: string} $result */
    private function respond(array $result): Response
    {
        $response = response($result[1], $result[0])->header('Content-Type', 'text/plain');

        return $result[0] === 503 ? $response->header('Retry-After', '60') : $response;
    }

    /** PHP turns the dot in `hub.mode` into an underscore; accept either spelling. */
    private function query(Request $request, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $request->query($key);

            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }
}
