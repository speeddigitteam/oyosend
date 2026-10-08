<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->acme = $this->createTenant('acme');

    // Routes that fail on purpose, one per area.
    Route::domain('{tenant}.localhost')->middleware(['web', 'tenant'])->group(function () {
        Route::get('_test/boom', fn () => throw new RuntimeException('database password is hunter2'));
        Route::get('_test/expired', fn () => abort(419));
        Route::get('_test/maintenance', fn () => abort(503));
    });

    Route::domain('localhost')->middleware(['web', 'central'])
        ->get('_test/boom', fn () => throw new RuntimeException('database password is hunter2'));
});

it('renders the application\'s own error page for the statuses it handles', function (string $url, int $status) {
    $this->actingAsOwner($this->acme)
        ->get($url)
        ->assertStatus($status)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/Errors/Error')
            ->where('status', $status));
})->with([
    'not found, in a workspace' => ['http://acme.localhost/no/such/page', 404],
    'not found, on the central site' => ['http://localhost/no/such/page', 404],
    'not found, in the admin panel' => ['http://admin.localhost/no/such/page', 404],
    'not found, on a host that is no workspace' => ['http://nobody.localhost/', 404],
    'server error, in a workspace' => ['http://acme.localhost/_test/boom', 500],
    'server error, on the central site' => ['http://localhost/_test/boom', 500],
    'maintenance' => ['http://acme.localhost/_test/maintenance', 503],
]);

it('does not show what went wrong inside to the visitor', function () {
    expect(config('app.debug'))->toBeFalse();

    $response = $this->get('http://acme.localhost/_test/boom')->assertStatus(500);

    expect($response->getContent())->not->toContain('hunter2', 'RuntimeException', 'ErrorPagesTest.php');
});

it('renders the error page for a throttled request', function () {
    foreach (range(1, 6) as $attempt) {
        $this->post('http://acme.localhost/forgot-password', ['email' => 'nobody@example.test']);
    }

    $this->post('http://acme.localhost/forgot-password', ['email' => 'nobody@example.test'])
        ->assertTooManyRequests()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Core/Pages/Errors/Error')->where('status', 429));
});

it('sends an expired page back where it came from with a notice', function () {
    $this->from('http://acme.localhost/login')
        ->get('http://acme.localhost/_test/expired')
        ->assertRedirect('http://acme.localhost/login')
        ->assertInertiaFlash('toast.type', 'warning');
});

it('answers an API client in JSON, not with a page', function () {
    $this->getJson('http://acme.localhost/no/such/page')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);

    $this->getJson('http://acme.localhost/_test/boom')
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Server Error']);
});

it('still answers an Inertia visit with the error page', function () {
    $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
        ->get('http://acme.localhost/no/such/page')
        ->assertNotFound()
        ->assertJsonPath('component', 'Core/Pages/Errors/Error')
        ->assertJsonPath('props.status', 404);
});

it('leaves statuses it does not handle to the framework', function () {
    $this->get('http://acme.localhost/logout')->assertStatus(405);
});
