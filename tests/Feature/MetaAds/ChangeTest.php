<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Modules\MetaAds\Enums\ChangeStatus;
use App\Modules\MetaAds\Enums\ConnectionStatus;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\AdObject;
use App\Modules\MetaAds\Models\Change;
use App\Modules\MetaAds\Models\Connection;
use App\Modules\MetaAds\Models\MetaAdsSetting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\InteractsWithMetaAds;

uses(InteractsWithMetaAds::class);

beforeEach(function () {
    $this->tenant = $this->metaTenant('meta-chg');
    $this->connection = $this->metaConnection($this->tenant);
    $this->account = $this->metaAccount($this->tenant, $this->connection, [
        'external_id' => 'act_111', 'currency' => 'BDT', 'daily_budget_ceiling' => 200000, 'lifetime_budget_ceiling' => 9000000,
    ]);
    $this->object = $this->metaObject($this->tenant, $this->account, ['external_id' => '1001', 'name' => 'Eid Sale', 'daily_budget' => 100000]);
    $this->inTenant($this->tenant, fn () => MetaAdsSetting::query()->create(['management_enabled' => true]));
    $this->url = fn (string $path = '') => $this->metaUrl($this->tenant, $path);

    // Meta, as a small stateful fake of one campaign: GET reads it, POST changes it.
    $this->live = new ArrayObject(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '100000', 'lifetime_budget' => '0']);
    $this->posts = new ArrayObject;
    $this->meta = function (array $extra = []): void {
        $live = $this->live;
        $posts = $this->posts;
        $this->fakeMeta($extra + [
            '1001*' => function (Request $request) use ($live, $posts) {
                if ($request->method() === 'POST') {
                    $posts[] = $request->data();

                    foreach (['status', 'daily_budget', 'lifetime_budget'] as $field) {
                        if (isset($request[$field])) {
                            $live[$field] = (string) $request[$field];
                            $live['effective_status'] = $field === 'status' ? (string) $request[$field] : $live['effective_status'];
                        }
                    }

                    return Http::response(['success' => true]);
                }

                return Http::response($live->getArrayCopy());
            },
        ]);
    };
    $this->pause = fn (array $overrides = []) => $this->post(($this->url)('/campaigns/'.$this->object->id.'/status'), [
        'action' => 'pause', 'expected' => 'ACTIVE', 'request_id' => 'req-'.bin2hex(random_bytes(8)), ...$overrides,
    ]);
    $this->budget = fn (string $amount, array $overrides = []) => $this->post(($this->url)('/campaigns/'.$this->object->id.'/budget'), [
        'amount' => $amount, 'expected' => '100000', 'request_id' => 'req-'.bin2hex(random_bytes(8)), ...$overrides,
    ]);
    $this->changes = fn () => $this->inTenant($this->tenant, fn () => Change::query()->orderBy('requested_at')->get());
    $this->current = fn () => $this->inTenant($this->tenant, fn () => AdObject::query()->findOrFail($this->object->id));
    $this->actingAsOwner($this->tenant);
});

it('pauses a campaign on Meta after re-reading it, writes a receipt and an audit entry', function () {
    ($this->meta)();

    ($this->pause)(['request_id' => 'req-pause-0000000001'])->assertRedirect()->assertSessionHasNoErrors();

    $change = ($this->changes)()->sole();
    expect($change->status)->toBe(ChangeStatus::Applied)
        ->and($change->action->value)->toBe('pause')
        ->and($change->field)->toBe('status')
        ->and($change->before_value)->toBe('ACTIVE')
        ->and($change->after_value)->toBe('PAUSED')
        ->and($change->request_id)->toBe('req-pause-0000000001')
        ->and($change->object_name)->toBe('Eid Sale')
        ->and($change->requested_by)->not->toBeNull()
        ->and($change->applied_at)->not->toBeNull()
        ->and(($this->current)()->status)->toBe('PAUSED')
        ->and(($this->current)()->effective_status)->toBe('PAUSED');
    expect(($this->posts)->getArrayCopy())->toHaveCount(1)
        ->and($this->posts[0]['status'])->toBe('PAUSED');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer '.$this->connection->access_token));

    $audit = $this->inTenant($this->tenant, fn () => AuditLog::query()->where('event', 'meta_ads.change.applied')->sole());
    expect($audit->properties)->toMatchArray(['action' => 'pause', 'before' => 'ACTIVE', 'after' => 'PAUSED', 'account' => 'act_111', 'object' => '1001'])
        ->and(json_encode($audit->properties))->not->toContain((string) $this->connection->access_token);
});

it('resumes a paused campaign', function () {
    $this->live['status'] = 'PAUSED';
    $this->live['effective_status'] = 'PAUSED';
    $this->inTenant($this->tenant, fn () => AdObject::query()->whereKey($this->object->id)->update(['status' => 'PAUSED', 'effective_status' => 'PAUSED']));
    ($this->meta)();

    ($this->pause)(['action' => 'resume', 'expected' => 'PAUSED'])->assertSessionHasNoErrors();

    expect(($this->posts)[0]['status'])->toBe('ACTIVE')
        ->and(($this->current)()->status)->toBe('ACTIVE');
});

it('refuses to pause what is not active or to resume what is not paused', function () {
    ($this->meta)();

    ($this->pause)(['action' => 'resume', 'expected' => 'ACTIVE'])->assertSessionHasErrors('change');
    expect(($this->posts)->count())->toBe(0)->and(($this->changes)())->toHaveCount(0);
});

it('edits an existing daily budget within the ceiling and sends it as exact minor units', function () {
    ($this->meta)();

    ($this->budget)('1,500.00')->assertSessionHasNoErrors();

    $change = ($this->changes)()->sole();
    expect($change->field)->toBe('daily_budget')
        ->and($change->before_value)->toBe('100000')
        ->and($change->after_value)->toBe('150000')
        ->and($change->currency)->toBe('BDT')
        ->and(($this->posts)[0]['daily_budget'])->toBe('150000')
        ->and(($this->current)()->daily_budget)->toBe(150000);
});

it('never sets a budget above the ceiling, and never calls Meta when refusing', function () {
    ($this->meta)();

    ($this->budget)('2000.01')->assertSessionHasErrors('budget');
    ($this->budget)('999999')->assertSessionHasErrors('budget');

    expect(($this->posts)->count())->toBe(0)
        ->and(($this->changes)())->toHaveCount(0)
        ->and(($this->current)()->daily_budget)->toBe(100000);
    Http::assertNothingSent();

    // Exactly at the ceiling is allowed.
    ($this->budget)('2000')->assertSessionHasNoErrors();
    expect(($this->posts)[0]['daily_budget'])->toBe('200000');
});

it('bounds one increase to a share of the current budget but always allows lowering it', function () {
    $this->inTenant($this->tenant, fn () => AdAccount::query()->whereKey($this->account->id)->update(['daily_budget_ceiling' => 5000000]));
    config(['meta_ads.changes.max_increase_percent' => 100]);
    ($this->meta)();

    ($this->budget)('2000.01')->assertSessionHasErrors('budget');
    expect(($this->posts)->count())->toBe(0);

    ($this->budget)('2000')->assertSessionHasNoErrors();
    $this->live['daily_budget'] = '200000';
    $this->inTenant($this->tenant, fn () => AdObject::query()->whereKey($this->object->id)->update(['daily_budget' => 200000]));

    ($this->budget)('10', ['expected' => '200000'])->assertSessionHasNoErrors();
    expect(($this->posts)[1]['daily_budget'])->toBe('1000');
});

it('refuses a budget change when no ceiling is set, when nothing changes, or when the amount is not a plain amount', function () {
    ($this->meta)();

    ($this->budget)('1000')->assertSessionHasErrors('budget'); // same as current
    ($this->budget)('abc')->assertSessionHasErrors('budget');
    ($this->budget)('0')->assertSessionHasErrors('budget');
    ($this->budget)('-5')->assertSessionHasErrors('budget');
    ($this->budget)('1e3')->assertSessionHasErrors('budget');

    $this->inTenant($this->tenant, fn () => AdAccount::query()->whereKey($this->account->id)->update(['daily_budget_ceiling' => null]));
    ($this->budget)('1200')->assertSessionHasErrors('budget');

    expect(($this->posts)->count())->toBe(0);
});

it('edits only the kind of budget the item already has, and not an ad or an item without one', function () {
    $lifetime = $this->metaObject($this->tenant, $this->account, ['external_id' => '1002', 'daily_budget' => null, 'lifetime_budget' => 5000000]);
    $none = $this->metaObject($this->tenant, $this->account, ['external_id' => '1003', 'daily_budget' => null]);
    $ad = $this->metaObject($this->tenant, $this->account, ['external_id' => '3001', 'level' => 'ad', 'daily_budget' => 100000]);
    $state = new ArrayObject(['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '0', 'lifetime_budget' => '5000000']);
    $this->fakeMeta(['1002*' => function (Request $request) use ($state) {
        if ($request->method() === 'POST') {
            $state['lifetime_budget'] = (string) $request['lifetime_budget'];

            return Http::response(['success' => true]);
        }

        return Http::response($state->getArrayCopy());
    }]);

    foreach ([$none, $ad] as $target) {
        $this->post(($this->url)('/campaigns/'.$target->id.'/budget'), ['amount' => '500', 'expected' => '100000', 'request_id' => 'req-'.bin2hex(random_bytes(8))])
            ->assertSessionHasErrors('budget');
    }

    $this->post(($this->url)('/campaigns/'.$lifetime->id.'/budget'), ['amount' => '60000', 'expected' => '5000000', 'request_id' => 'req-'.bin2hex(random_bytes(8))])
        ->assertSessionHasNoErrors();
    $change = ($this->changes)()->sole();
    expect($change->field)->toBe('lifetime_budget')->and($change->after_value)->toBe('6000000');
});

it('does not apply a change the person did not see: a stale confirmation is refused and the item refreshed', function () {
    $this->live['status'] = 'PAUSED';
    $this->live['effective_status'] = 'PAUSED';
    ($this->meta)();

    ($this->pause)()->assertSessionHasErrors('change');

    expect(($this->posts)->count())->toBe(0)
        ->and(($this->current)()->status)->toBe('PAUSED')
        ->and(($this->changes)()->sole()->status)->toBe(ChangeStatus::Failed)
        ->and(($this->changes)()->sole()->error_code)->toBe('stale');

    // A budget confirmed against an old value is refused before Meta is asked at all.
    ($this->budget)('1200', ['expected' => '90000'])->assertSessionHasErrors('budget');
});

it('is idempotent per confirmation: a replayed request id calls Meta once', function () {
    ($this->meta)();

    ($this->pause)(['request_id' => 'req-same-000000001'])->assertSessionHasNoErrors();
    ($this->pause)(['request_id' => 'req-same-000000001'])->assertSessionHasNoErrors();

    expect(($this->posts)->count())->toBe(1)->and(($this->changes)())->toHaveCount(1);
});

it('records a Meta refusal as a failed receipt and leaves the item unchanged', function () {
    $this->fakeMeta(['1001*' => fn (Request $request) => $request->method() === 'POST'
        ? $this->graphError(100, 'Invalid parameter', 400, ['error_subcode' => 1815857])
        : Http::response($this->live->getArrayCopy())]);

    ($this->pause)()->assertSessionHasErrors('change');

    $change = ($this->changes)()->sole();
    expect($change->status)->toBe(ChangeStatus::Failed)
        ->and($change->error_message)->toBe('Invalid parameter')
        ->and($change->error_code)->toBe('meta_100_1815857')
        ->and(($this->current)()->status)->toBe('ACTIVE');
    $this->inTenant($this->tenant, fn () => expect(AuditLog::query()->where('event', 'meta_ads.change.failed')->count())->toBe(1));
});

it('sends the connection back to authorisation when Meta rejects the token during a change', function () {
    $this->fakeMeta(['1001*' => $this->graphError(190, 'token expired', 401)]);

    ($this->pause)()->assertSessionHasErrors('change');

    $this->inTenant($this->tenant, function (): void {
        $connection = Connection::query()->findOrFail($this->connection->id);
        expect($connection->status)->toBe(ConnectionStatus::NeedsReauthorization)->and($connection->access_token)->toBeNull();
    });
    expect(($this->current)()->status)->toBe('ACTIVE');
});

it('is off until the workspace turns it on, and needs the ads_management grant', function () {
    ($this->meta)();

    $this->inTenant($this->tenant, fn () => MetaAdsSetting::query()->update(['management_enabled' => false]));
    ($this->pause)()->assertSessionHasErrors('change');

    $this->inTenant($this->tenant, fn () => MetaAdsSetting::query()->update(['management_enabled' => true]));
    $this->inTenant($this->tenant, fn () => Connection::query()->update(['granted_scopes' => ['ads_read']]));
    ($this->pause)()->assertSessionHasErrors('change');

    $this->inTenant($this->tenant, fn () => Connection::query()->update(['granted_scopes' => ['ads_read', 'ads_management']]));
    config(['meta_ads.request_management_scope' => false]);
    ($this->pause)()->assertSessionHasErrors('change');

    expect(($this->posts)->count())->toBe(0);
});

it('stops at the daily limit of changes and when Meta is limiting the account', function () {
    config(['meta_ads.changes.max_changes_per_day' => 1]);
    ($this->meta)();

    ($this->pause)()->assertSessionHasNoErrors();
    ($this->pause)(['action' => 'resume', 'expected' => 'PAUSED'])->assertSessionHasErrors('change');
    expect(($this->posts)->count())->toBe(1);

    config(['meta_ads.changes.max_changes_per_day' => 30]);
    $this->inTenant($this->tenant, fn () => AdAccount::query()->update(['throttled_until' => now()->addHour()]));
    ($this->pause)(['action' => 'resume', 'expected' => 'PAUSED'])->assertSessionHasErrors('change');
    expect(($this->posts)->count())->toBe(1);
});

it('refuses archived or vanished items', function () {
    ($this->meta)();
    $this->inTenant($this->tenant, fn () => AdObject::query()->whereKey($this->object->id)->update(['effective_status' => 'ARCHIVED']));
    ($this->pause)()->assertSessionHasErrors('change');

    $this->inTenant($this->tenant, fn () => AdObject::query()->whereKey($this->object->id)->update(['effective_status' => 'ACTIVE', 'missing_at' => now()]));
    ($this->pause)()->assertSessionHasErrors('change');

    expect(($this->posts)->count())->toBe(0);
});

it('requires an explicit confirmation payload', function () {
    ($this->meta)();

    $this->post(($this->url)('/campaigns/'.$this->object->id.'/status'), ['action' => 'pause'])->assertSessionHasErrors(['expected', 'request_id']);
    ($this->pause)(['action' => 'archive'])->assertSessionHasErrors('action');
    ($this->pause)(['action' => 'delete'])->assertSessionHasErrors('action');
    ($this->pause)(['expected' => 'ARCHIVED'])->assertSessionHasErrors('expected');
    ($this->pause)(['request_id' => 'short'])->assertSessionHasErrors('request_id');

    expect(($this->posts)->count())->toBe(0);
});

it('lets only holders of meta_ads.campaigns.manage change anything', function () {
    ($this->meta)();
    [$viewer] = $this->metaMember($this->tenant, ['meta_ads.campaigns.view', 'meta_ads.insights.view']);
    [$manager] = $this->metaMember($this->tenant, ['meta_ads.campaigns.view', 'meta_ads.campaigns.manage']);

    $this->startNewBrowserSession();
    $this->actingAsMember($this->tenant, $viewer);
    ($this->pause)()->assertForbidden();
    ($this->budget)('1200')->assertForbidden();

    $this->startNewBrowserSession();
    $this->actingAsMember($this->tenant, $manager);
    ($this->pause)()->assertSessionHasNoErrors();

    expect(($this->posts)->count())->toBe(1);
});

it('has no way to create, archive or delete anything on Meta', function () {
    // No route deletes or creates Meta objects; the two DELETE routes only stop tracking / discard a token locally.
    $deletes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'meta_ads.') && in_array('DELETE', $route->methods(), true))
        ->map(fn ($route) => $route->getName())->sort()->values()->all();
    expect($deletes)->toBe(['meta_ads.accounts.destroy', 'meta_ads.accounts.untrack']);

    // The Marketing API client can only read and post an update to an existing object.
    $source = '';

    foreach (glob(app_path('Modules/MetaAds/Api/*.php')) as $file) {
        $source .= file_get_contents($file);
    }

    expect($source)->not->toContain('->delete(')->not->toContain('->put(')->not->toContain('->patch(')->not->toContain("'DELETE'");

    // The only values ever written for `status` are ACTIVE and PAUSED.
    $manager = file_get_contents(app_path('Modules/MetaAds/Actions/ChangeManager.php'));
    expect($manager)->not->toContain("'ARCHIVED' ?")->not->toContain("'DELETED' ?");
});
