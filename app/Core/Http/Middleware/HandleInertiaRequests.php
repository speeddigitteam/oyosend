<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared Inertia setup for the three areas of the platform. Each area
 * (central site, tenant workspace, Super Admin panel) extends this with its
 * own props; none of them shares another area's data.
 */
abstract class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * `central`, `tenant` or `platform`. Also selects which named routes are
     * exposed to the browser (see config/ziggy.php).
     */
    abstract protected function area(): string;

    public function handle(Request $request, Closure $next): Response
    {
        View::share('area', $this->area());

        return parent::handle($request, $next);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => fn (): array => [
                'name' => config('app.name'),
                'area' => $this->area(),
                'locale' => app()->getLocale(),
                'fallbackLocale' => config('app.fallback_locale'),
                'locales' => config('platform.locales'),
                ...$this->regional($request),
            ],
        ];
    }

    /**
     * Timezone, currency and numeral system used to format values on screen.
     *
     * @return array{timezone: string, currency: string, numerals: string}
     */
    protected function regional(Request $request): array
    {
        return [
            'timezone' => (string) config('platform.defaults.timezone'),
            'currency' => (string) config('platform.defaults.currency'),
            'numerals' => 'latn',
        ];
    }
}
