<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the interface language: the signed-in person's choice, then the
 * language they chose as a guest, then the workspace default.
 */
final class SetLocale
{
    public const string COOKIE = 'locale';

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<string, string> $supported */
        $supported = config('platform.locales', []);

        $candidates = [
            $request->user()?->getAttribute('locale'),
            $request->cookie(self::COOKIE),
            $this->context->tenant()?->locale,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && isset($supported[$candidate])) {
                App::setLocale($candidate);

                break;
            }
        }

        return $next($request);
    }
}
