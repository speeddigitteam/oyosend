<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Platform\Support\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Probes for a load balancer or monitor. They carry no session, no tenant and
 * no secrets: the readiness report is platform-wide numbers and names of
 * services, and is answered only to an allow-listed address or a bearer token.
 */
final class HealthController
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok'])->header('Cache-Control', 'no-store');
    }

    public function ready(Request $request, SystemHealth $health): JsonResponse
    {
        if (! $this->mayRead($request)) {
            // Not 403: a probe has no business learning that the endpoint exists.
            abort(404);
        }

        $checks = $health->readiness();
        $strict = $request->boolean('strict');

        $criticalFailed = array_filter(
            $checks,
            static fn (array $check): bool => ! $check['ok'] && in_array($check['key'], SystemHealth::CRITICAL, true),
        );
        $anyFailed = array_filter($checks, static fn (array $check): bool => ! $check['ok']);

        $status = $criticalFailed !== [] ? 'unavailable' : ($anyFailed !== [] ? 'degraded' : 'ok');
        $httpStatus = $criticalFailed !== [] || ($strict && $anyFailed !== []) ? 503 : 200;

        return response()
            ->json(['status' => $status, 'checks' => $checks], $httpStatus)
            ->header('Cache-Control', 'no-store');
    }

    private function mayRead(Request $request): bool
    {
        $token = config('platform.health.token');
        $bearer = $request->bearerToken();

        if (is_string($token) && $token !== '' && is_string($bearer) && hash_equals($token, $bearer)) {
            return true;
        }

        /** @var list<string> $allowed */
        $allowed = (array) config('platform.health.allowed_ips', []);
        $ip = $request->ip();

        return $ip !== null && $allowed !== [] && IpUtils::checkIp($ip, $allowed);
    }
}
