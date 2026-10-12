<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\ProfileConnection;
use App\Modules\Social\Platforms\TokenSet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

beforeEach(function () {
    $this->tenant = $this->socialTenant('soc-acct');
    $this->fakePlatform();
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);
    // Start the OAuth redirect and return the state the platform would hand back.
    $this->begin = function (array $data = []): string {
        $response = $this->post(($this->url)('/accounts/connect/fake'), $data);
        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return (string) $query['state'];
    };
});

it('connects an account through the authorise redirect and callback and stores the tokens encrypted', function () {
    $this->actingAsOwner($this->tenant);

    $state = ($this->begin)();
    $this->get(($this->url)('/accounts/callback/fake?code=ok&state='.$state))->assertRedirect(($this->url)('/accounts'));

    $account = $this->inTenant($this->tenant, fn () => SocialAccount::query()->sole());
    $raw = DB::table('social_accounts')->where('id', $account->id)->first();

    expect($account->status)->toBe(AccountStatus::Connected)
        ->and($account->platform)->toBe('fake')
        ->and($account->name)->toBe('Fake Page')
        ->and($account->access_token)->toBe('access-secret-1')
        ->and($account->refresh_token)->toBe('refresh-secret-1')
        ->and($raw->access_token)->not->toContain('access-secret')
        ->and($raw->refresh_token)->not->toContain('refresh-secret');
});

it('rejects a callback that is forged, replayed, expired or completed by someone else', function () {
    $this->actingAsOwner($this->tenant);
    $state = ($this->begin)();

    // Wrong state.
    $this->get(($this->url)('/accounts/callback/fake?code=ok&state=forged'))->assertRedirect(($this->url)('/accounts'));
    $this->inTenant($this->tenant, fn () => expect(SocialAccount::query()->count())->toBe(0));

    // The state is spent by the failed attempt: even the right value no longer works.
    $this->get(($this->url)('/accounts/callback/fake?code=ok&state='.$state));
    $this->inTenant($this->tenant, fn () => expect(SocialAccount::query()->count())->toBe(0));

    // A different member cannot finish someone else's authorisation.
    $state = ($this->begin)();
    [$other] = $this->socialMember($this->tenant, ['social.accounts.manage']);
    $this->startNewBrowserSession();
    $this->actingAsMember($this->tenant, $other);
    $this->get(($this->url)('/accounts/callback/fake?code=ok&state='.$state));
    $this->inTenant($this->tenant, fn () => expect(SocialAccount::query()->count())->toBe(0));

    // Expired.
    $this->actingAsOwner($this->tenant);
    $state = ($this->begin)();
    $this->travel(11)->minutes();
    $this->get(($this->url)('/accounts/callback/fake?code=ok&state='.$state));
    $this->inTenant($this->tenant, fn () => expect(SocialAccount::query()->count())->toBe(0));
});

it('turns a refused code and a platform "access denied" into a message, not an account', function () {
    $this->actingAsOwner($this->tenant);

    $state = ($this->begin)();
    $this->get(($this->url)('/accounts/callback/fake?error=access_denied&state='.$state))->assertRedirect(($this->url)('/accounts'));

    $state = ($this->begin)();
    $this->get(($this->url)('/accounts/callback/fake?code=bad&state='.$state))->assertSessionHasErrors('platform');

    $this->inTenant($this->tenant, fn () => expect(SocialAccount::query()->count())->toBe(0));
});

it('lets the member choose among several granted pages', function () {
    $token = fn (string $n): TokenSet => new TokenSet('access-'.$n, 'refresh-'.$n, CarbonImmutable::now()->addDays(30));
    $this->fake->profiles = [
        new ProfileConnection('p1', 'Page One', null, null, 'page', $token('1')),
        new ProfileConnection('p2', 'Page Two', null, null, 'page', $token('2')),
    ];
    $this->actingAsOwner($this->tenant);

    $state = ($this->begin)();
    $response = $this->get(($this->url)('/accounts/callback/fake?code=ok&state='.$state));
    $response->assertRedirect();
    $selectUrl = (string) $response->headers->get('Location');
    expect($selectUrl)->toContain('/social/accounts/select/');

    $this->get($selectUrl)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('profiles', 2)
        ->where('profiles.0.name', 'Page One'));
    // The tokens are not part of what the browser receives.
    expect(json_encode($this->get($selectUrl)->viewData('page')))->not->toContain('access-1')->not->toContain('refresh-2');

    $this->post($selectUrl, ['external_ids' => ['p2']])->assertRedirect(($this->url)('/accounts'));

    $this->inTenant($this->tenant, function (): void {
        $account = SocialAccount::query()->sole();
        expect($account->external_id)->toBe('p2')->and($account->access_token)->toBe('access-2');
    });

    // Spent: the same selection cannot be used again.
    $this->get($selectUrl)->assertRedirect(($this->url)('/accounts'));
});

it('never exposes a token in any page, JSON, array form or audit entry', function () {
    $account = $this->socialAccount($this->tenant, ['access_token' => 'zzz-access-token', 'refresh_token' => 'zzz-refresh-token']);
    $this->actingAsOwner($this->tenant);
    $post = $this->socialPost($this->tenant, [$account->id]);

    foreach (['/accounts', '/posts', '/calendar', "/posts/{$post->id}", '/posts/create', "/posts/{$post->id}/edit"] as $path) {
        $body = $this->get(($this->url)($path))->assertOk()->getContent();

        expect($body)->not->toContain('zzz-access-token')->not->toContain('zzz-refresh-token');
    }

    $this->inTenant($this->tenant, function () use ($account): void {
        $model = SocialAccount::query()->findOrFail($account->id);
        $model->forceFill(['status_reason' => 'changed'])->save();

        expect($model->toArray())->not->toHaveKeys(['access_token', 'refresh_token'])
            ->and($model->toJson())->not->toContain('zzz-')
            ->and(json_encode($model))->not->toContain('zzz-')
            ->and(AuditLog::query()->get()->toJson())->not->toContain('zzz-');
    });
});

it('blocks connecting past the plan limit and tells the person why', function () {
    $plan = $this->createFreePlan(['key' => 'one-account', 'modules' => ['social'], 'limits' => ['social.connected_accounts' => 1]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);
    $this->socialAccount($this->tenant);
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/accounts/connect/fake'))->assertSessionHasErrors('platform');
});

it('says a platform cannot be connected instead of pretending to', function () {
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/accounts/connect/instagram'))
        ->assertSessionHasErrors('platform');
    $this->post(($this->url)('/accounts/connect/facebook'))
        ->assertSessionHasErrors('platform');
    $this->post(($this->url)('/accounts/connect/not-a-platform'))->assertSessionHasErrors('platform');
});

it('reconnects an account that needs reauthorization only as the same profile', function () {
    $account = $this->socialAccount($this->tenant, [
        'external_id' => 'ext-1', 'status' => AccountStatus::NeedsReauthorization,
        'access_token' => null, 'refresh_token' => null, 'status_reason' => 'Token revoked',
    ]);
    $this->actingAsOwner($this->tenant);

    $this->get(($this->url)('/accounts'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accounts.0.needsReauthorization', true)
        ->where('accounts.0.statusReason', 'Token revoked'));

    $state = ($this->begin)(['account_id' => $account->id]);
    $this->get(($this->url)('/accounts/callback/fake?code=ok&state='.$state))->assertRedirect(($this->url)('/accounts'));

    $this->inTenant($this->tenant, function () use ($account): void {
        $fresh = SocialAccount::query()->findOrFail($account->id);

        expect($fresh->status)->toBe(AccountStatus::Connected)->and($fresh->status_reason)->toBeNull()
            ->and($fresh->access_token)->toBe('access-secret-1')
            ->and(SocialAccount::query()->count())->toBe(1);
    });

    // The platform returns another profile than the account being reconnected.
    $other = $this->socialAccount($this->tenant, ['external_id' => 'someone-else', 'status' => AccountStatus::NeedsReauthorization, 'access_token' => null]);
    $state = ($this->begin)(['account_id' => $other->id]);
    $this->get(($this->url)('/accounts/callback/fake?code=ok&state='.$state));

    $this->inTenant($this->tenant, fn () => expect(SocialAccount::query()->findOrFail($other->id)->status)->toBe(AccountStatus::NeedsReauthorization));
});

it('disconnects an account, discards its tokens and frees its seat', function () {
    $account = $this->socialAccount($this->tenant);
    $this->actingAsOwner($this->tenant);

    $this->delete(($this->url)("/accounts/{$account->id}"))->assertRedirect(($this->url)('/accounts'));

    $this->inTenant($this->tenant, function () use ($account): void {
        $fresh = SocialAccount::query()->findOrFail($account->id);

        expect($fresh->status)->toBe(AccountStatus::Disconnected)
            ->and($fresh->access_token)->toBeNull()
            ->and($fresh->refresh_token)->toBeNull();
    });

    $this->get(($this->url)('/accounts'))->assertInertia(fn (AssertableInertia $page) => $page->has('accounts', 0));
});
