<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Http\Controllers;

use App\Core\Billing\UsageLimits;
use App\Core\Social\Contracts\ConnectedPages;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\MetaAds\Actions\ConnectionManager;
use App\Modules\MetaAds\Actions\SetupReadiness;
use App\Modules\MetaAds\Api\MarketingApi;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Enums\ConnectionStatus;
use App\Modules\MetaAds\Jobs\SyncAdAccounts;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\Connection;
use App\Modules\MetaAds\Support\OAuthBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The setup wizard: authorise Meta, choose ad accounts, sync. The redirect and callback run on the workspace
 * host with the member's session: the `state` value lives in that session, is consumed on return and expires
 * after ten minutes, so a callback cannot be replayed or forged from another browser.
 */
final class AccountController
{
    private const string SESSION = 'meta_ads.oauth';

    private const int STATE_MINUTES = 10;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly SetupReadiness $readiness,
        private readonly MetaAdsSupport $support,
        private readonly MetaAdsPresenter $presenter,
        private readonly MarketingApi $api,
        private readonly UsageLimits $limits,
        private readonly OAuthBroker $broker,
        private readonly TenantContext $tenants,
        private readonly ConnectedPages $pages,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $canManage = $actor->can('meta_ads.accounts.manage');

        return Inertia::render('Modules/MetaAds/Pages/Accounts/Index', [
            'readiness' => $this->readiness->items(),
            'connections' => Connection::query()->where('status', '!=', ConnectionStatus::Disconnected->value)->orderBy('user_name')->get()
                ->map(fn (Connection $connection): array => $this->presenter->connection($connection))->all(),
            'accounts' => AdAccount::query()->where('status', '!=', AdAccountStatus::Removed->value)->orderBy('name')->get()
                ->map(fn (AdAccount $account): array => $this->presenter->account($account))->all(),
            'limit' => [
                'limit' => $this->limits->limit(ConnectionManager::LIMIT),
                'used' => $this->limits->used(ConnectionManager::LIMIT),
            ],
            'appConfigured' => $this->api->configured(),
            'requestsManagement' => in_array('ads_management', $this->api->scopes(), true),
            'canManage' => $canManage,
            'redirectUri' => $canManage ? $this->redirectUri() : null,
            'socialPages' => [
                'available' => $this->pages->isAvailable(),
                'pages' => array_map(static fn ($page): array => [
                    'id' => $page->id,
                    'name' => $page->name,
                    'status' => $page->status,
                ], $this->pages->pages()),
            ],
        ]);
    }

    /** Send the member to Meta's authorisation dialog. */
    public function connect(Request $request): SymfonyResponse
    {
        $actor = $this->support->actor($request);

        if (! $this->api->configured()) {
            throw ValidationException::withMessages(['authorization' => __('The Meta app is not configured on this installation. Ask your platform operator to set it up.')]);
        }

        // The session keeps the random value; Meta sees it wrapped in the broker's signed envelope.
        $inner = Str::random(40);
        $state = $this->broker->wrap($this->tenants->require()->slug, $inner);

        $request->session()->put(self::SESSION, [
            'state' => $inner,
            'wrapped' => $state !== $inner,
            'user' => $actor->getKey(),
            'expires' => now()->addMinutes(self::STATE_MINUTES)->getTimestamp(),
        ]);

        return Inertia::location($this->api->authorizationUrl($this->redirectUri(), $state));
    }

    /** Meta sends the member back here with `code` and `state` (or an `error`). */
    public function callback(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $pending = $request->session()->pull(self::SESSION);
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        $valid = is_array($pending)
            && ($pending['user'] ?? null) === $actor->getKey()
            && ($pending['expires'] ?? 0) >= now()->getTimestamp()
            && $this->stateMatches($state, (string) ($pending['state'] ?? ''), ($pending['wrapped'] ?? false) === true);

        if (! $valid) {
            Toast::error(__('The authorisation expired or could not be verified. Start again.'));

            return redirect()->route('meta_ads.accounts.index');
        }

        if ($request->query('error') !== null || $code === '') {
            Toast::warning(__('Meta did not grant access.'));

            return redirect()->route('meta_ads.accounts.index');
        }

        try {
            $connection = $this->connections->complete($code, $this->redirectUri(), $this->support->membership($actor));
        } catch (ValidationException $exception) {
            Toast::error((string) collect($exception->errors())->flatten()->first());

            return redirect()->route('meta_ads.accounts.index');
        }

        Toast::success(__('Authorised as :name. Choose the ad accounts to track.', ['name' => $connection->user_name ?? __('your Meta account')]));

        return redirect()->route('meta_ads.accounts.index');
    }

    public function track(Request $request, string $account): RedirectResponse
    {
        $this->support->actor($request);
        $tracked = $this->connections->track(AdAccount::query()->findOrFail($account));
        SyncAdAccounts::dispatch($tracked->id);
        Toast::success(__('Tracking :name. The first sync has started.', ['name' => $tracked->name]));

        return redirect()->route('meta_ads.accounts.index');
    }

    public function untrack(Request $request, string $account): RedirectResponse
    {
        $this->support->actor($request);
        $this->connections->untrack(AdAccount::query()->findOrFail($account));
        Toast::success(__('Stopped tracking this ad account. Its history is kept.'));

        return redirect()->route('meta_ads.accounts.index');
    }

    public function sync(Request $request, string $account): RedirectResponse
    {
        $this->support->actor($request);
        $target = AdAccount::query()->findOrFail($account);
        abort_unless($target->status === AdAccountStatus::Tracked, 404);

        $every = (int) config('meta_ads.sync.manual_interval_minutes', 10);

        if ($target->last_manual_sync_at !== null && $target->last_manual_sync_at->gt(now()->subMinutes($every))) {
            Toast::info(__('This ad account was synced a moment ago. Try again in a few minutes.'));

            return redirect()->route('meta_ads.accounts.index');
        }

        $target->forceFill(['last_manual_sync_at' => now()])->save();
        SyncAdAccounts::dispatch($target->id);
        Toast::success(__('Sync started.'));

        return redirect()->route('meta_ads.accounts.index');
    }

    public function disconnect(Request $request, string $connection): RedirectResponse
    {
        $this->support->actor($request);
        $this->connections->disconnect(Connection::query()->findOrFail($connection));
        Toast::success(__('Disconnected from Meta. Your history is kept.'));

        return redirect()->route('meta_ads.accounts.index');
    }

    /** The URI to register with Meta: the central broker's, or this workspace's own. */
    private function redirectUri(): string
    {
        return $this->broker->redirectUri(route('meta_ads.accounts.callback'));
    }

    /**
     * The returned `state` against the session's random value. A wrapped state must also carry a valid envelope
     * for this very workspace; the inner value is then compared in constant time.
     */
    private function stateMatches(string $returned, string $expected, bool $wrapped): bool
    {
        if ($returned === '' || $expected === '') {
            return false;
        }

        if (! $wrapped) {
            return hash_equals($expected, $returned);
        }

        $envelope = $this->broker->unwrap($returned);

        return $envelope !== null
            && $envelope['slug'] === $this->tenants->require()->slug
            && hash_equals($expected, $envelope['inner']);
    }
}
