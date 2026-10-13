<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Modules\Social\Support\OAuthBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The single registered OAuth redirect URI, on the central host. Stateless: no session and no
 * cookies. It verifies the signed envelope in `state` and forwards the same query to the
 * workspace's own callback, which does the real (session-bound) state check and token exchange.
 * Any failure is a plain 404, so the route cannot be used to probe workspaces.
 */
final class OAuthBrokerController
{
    public function __construct(private readonly OAuthBroker $broker) {}

    public function __invoke(Request $request, string $platform): RedirectResponse
    {
        abort_unless($this->broker->enabled(), 404);

        $state = $request->query('state');
        $envelope = is_string($state) ? $this->broker->unwrap($state, $platform) : null;
        $target = $envelope === null ? null : $this->broker->target($envelope['slug'], $platform);

        abort_if($target === null, 404);

        $query = [];

        foreach (['code', 'state', 'error', 'error_description', 'error_reason'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && strlen($value) <= 2048) {
                $query[$key] = $value;
            }
        }

        $response = redirect()->away($target.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
