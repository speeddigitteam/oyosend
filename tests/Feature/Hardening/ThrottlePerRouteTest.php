<?php

declare(strict_types=1);

use App\Core\Http\Middleware\ThrottleRequestsPerRoute;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
| `throttle` keeps one counter per route and limit (ThrottleRequestsPerRoute). The framework's own middleware keys a
| plain `throttle:N,M` by the user or IP alone, so every route shared one budget and a route limit inside a throttled
| group counted each request twice.
*/

beforeEach(function () {
    Route::get('/_throttle/a', fn () => 'a')->middleware('throttle:2,1')->name('throttle-test.a');
    Route::get('/_throttle/b', fn () => 'b')->middleware('throttle:2,1')->name('throttle-test.b');
    Route::get('/_throttle/shared-a', fn () => 'a')->middleware('throttle:2,1,throttle-test-shared')->name('throttle-test.shared-a');
    Route::get('/_throttle/shared-b', fn () => 'b')->middleware('throttle:2,1,throttle-test-shared')->name('throttle-test.shared-b');
    Route::middleware('throttle:60,1')->group(function (): void {
        Route::get('/_throttle/grouped', fn () => 'g')->middleware('throttle:3,1')->name('throttle-test.grouped');
    });
    RateLimiter::for('throttle-test-named', fn () => Limit::perMinute(1));
    Route::get('/_throttle/named', fn () => 'n')->middleware('throttle:throttle-test-named')->name('throttle-test.named');
    app('router')->getRoutes()->refreshNameLookups();
});

it('is the middleware behind the throttle alias', function () {
    expect(app(Kernel::class)->getMiddlewareAliases()['throttle'])->toBe(ThrottleRequestsPerRoute::class);
});

it('keeps a separate budget for each route with the same limit', function () {
    $this->get('/_throttle/a')->assertOk();
    $this->get('/_throttle/a')->assertOk();
    $this->get('/_throttle/a')->assertTooManyRequests();

    // Spending one route's budget leaves the other's untouched.
    $this->get('/_throttle/b')->assertOk();
    $this->get('/_throttle/b')->assertOk();
});

it('counts a request once against a route limit inside a throttled group', function () {
    foreach (range(1, 3) as $attempt) {
        $this->get('/_throttle/grouped')->assertOk();
    }

    $this->get('/_throttle/grouped')->assertTooManyRequests();
});

it('still shares a budget between routes that name the same prefix', function () {
    $this->get('/_throttle/shared-a')->assertOk();
    $this->get('/_throttle/shared-b')->assertOk();
    $this->get('/_throttle/shared-a')->assertTooManyRequests();
});

it('leaves named limiters as they are', function () {
    $this->get('/_throttle/named')->assertOk();
    $this->get('/_throttle/named')->assertTooManyRequests();
});
