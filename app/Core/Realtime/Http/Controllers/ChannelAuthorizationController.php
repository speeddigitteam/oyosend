<?php

declare(strict_types=1);

namespace App\Core\Realtime\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Realtime\Contracts\RealtimeChannel;
use App\Core\Realtime\Realtime;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Signs a browser's subscription to a private workspace channel (ADR 0035): `POST /broadcasting/auth` on the
 * workspace host only, behind the session, the membership check, the paywall and a throttle (routes/tenant.php).
 *
 * The answer is the websocket server's signature over the socket id and the channel name, made with the app secret;
 * without it the server refuses the subscription. A channel is signed only when a module registered it
 * ({@see RealtimeChannel}) and its `join()` says yes for the signed-in member in the workspace of this hostname.
 * Anything else is a 403, the same for every reason. With realtime off the route does not exist (404).
 */
final class ChannelAuthorizationController
{
    /** Private workspace channels only: no presence channels, no encrypted ones, nothing outside a workspace. */
    private const string CHANNEL = '/^private-tenant\.[A-Za-z0-9]{1,64}\.[a-z][a-z0-9_]{0,63}$/';

    public function __construct(
        private readonly Realtime $realtime,
        private readonly BroadcastFactory $broadcast,
        private readonly Container $container,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->realtime->enabled(), 404);

        $request->validate([
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^\d{1,30}\.\d{1,30}$/'],
            'channel_name' => ['required', 'string', 'max:160'],
            // The framework would answer JSONP to a `callback`; nothing here needs it.
            'callback' => ['prohibited'],
        ]);

        if (preg_match(self::CHANNEL, (string) $request->input('channel_name')) !== 1) {
            throw new AccessDeniedHttpException;
        }

        $broadcaster = $this->broadcast->connection();

        if (! $broadcaster instanceof Broadcaster) {
            abort(404);
        }

        // Registered here, not at boot: building the broadcaster needs the realtime settings, and only this route
        // needs the channels. Every channel answers for the signed-in member of the `web` guard.
        foreach ($this->container->tagged(RealtimeChannel::TAG) as $channel) {
            /** @var RealtimeChannel $channel */
            $broadcaster->channel(
                $channel->pattern(),
                static fn (User $user, string $tenantId): bool => $channel->join($user, $tenantId),
                ['guards' => ['web']],
            );
        }

        $signed = $broadcaster->auth($request);

        if (! is_array($signed)) {
            throw new AccessDeniedHttpException;
        }

        return response()->json($signed);
    }
}
