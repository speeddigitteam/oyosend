<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Billing\UsageLimits;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Http\Requests\ConnectRequest;
use App\Modules\Social\Http\Requests\SelectAccountsRequest;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\ProfileConnection;
use App\Modules\Social\Support\OAuthBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Connected accounts. The OAuth redirect and callback run on the workspace host with the
 * member's session: the `state` value and PKCE verifier live in that session and are checked
 * (and consumed) on return, so a callback cannot be replayed or forged from another browser.
 */
final class AccountController
{
    private const string SESSION = 'social.oauth';

    private const int STATE_MINUTES = 10;

    public function __construct(
        private readonly AccountManager $accounts,
        private readonly SocialSupport $support,
        private readonly SocialPresenter $presenter,
        private readonly UsageLimits $limits,
        private readonly OAuthBroker $broker,
        private readonly TenantContext $tenants,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);

        return Inertia::render('Modules/Social/Pages/Accounts/Index', [
            'accounts' => SocialAccount::query()->where('status', '!=', AccountStatus::Disconnected->value)
                ->orderBy('platform')->orderBy('name')->get()
                ->map(fn (SocialAccount $account): array => $this->presenter->account($account))->all(),
            'platforms' => $this->presenter->platforms(),
            'limit' => [
                'limit' => $this->limits->limit(AccountManager::LIMIT),
                'used' => $this->limits->used(AccountManager::LIMIT),
            ],
            'canManage' => $actor->can('social.accounts.manage'),
            'redirectUris' => collect($this->support->platforms()->all())
                ->map(fn ($platform): array => ['platform' => $platform->key(), 'uri' => $this->redirectUri($platform->key())])
                ->values()->all(),
        ]);
    }

    /** Send the member to the platform's authorisation page. */
    public function connect(ConnectRequest $request, string $platform): SymfonyResponse
    {
        $actor = $this->support->actor($request);
        $adapter = $this->accounts->usablePlatform($platform);
        $reauthorize = null;

        if ($request->validated('account_id') !== null) {
            $reauthorize = SocialAccount::query()->findOrFail($request->validated('account_id'));

            if ($reauthorize->platform !== $platform) {
                throw ValidationException::withMessages(['platform' => __('That account belongs to a different platform.')]);
            }
        }

        $this->accounts->assertSeatAvailable($reauthorize);

        // The session keeps the random value; the platform sees it wrapped in the broker's signed envelope.
        $inner = Str::random(40);
        $state = $this->broker->wrap($this->tenants->require()->slug, $platform, $inner);
        $authorization = $adapter->authorizationRequest($this->redirectUri($platform), $state);

        $request->session()->put(self::SESSION, [
            'platform' => $platform,
            'state' => $inner,
            'wrapped' => $state !== $inner,
            'verifier' => $authorization->codeVerifier,
            'user' => $actor->getKey(),
            'account_id' => $reauthorize?->id,
            'expires' => now()->addMinutes(self::STATE_MINUTES)->getTimestamp(),
        ]);

        return Inertia::location($authorization->url);
    }

    /** The platform sends the member back here with `code` and `state` (or an `error`). */
    public function callback(Request $request, string $platform): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $pending = $request->session()->pull(self::SESSION);
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        $valid = is_array($pending)
            && ($pending['platform'] ?? null) === $platform
            && ($pending['user'] ?? null) === $actor->getKey()
            && ($pending['expires'] ?? 0) >= now()->getTimestamp()
            && $this->stateMatches($platform, $state, (string) ($pending['state'] ?? ''), ($pending['wrapped'] ?? false) === true);

        if (! $valid) {
            Toast::error(__('The authorisation expired or could not be verified. Start again.'));

            return redirect()->route('social.accounts.index');
        }

        if ($request->query('error') !== null || $code === '') {
            Toast::warning(__('The platform did not grant access.'));

            return redirect()->route('social.accounts.index');
        }

        $adapter = $this->accounts->usablePlatform($platform);
        $profiles = $this->accounts->exchange(
            $adapter,
            $code,
            $this->redirectUri($platform),
            is_string($pending['verifier'] ?? null) ? $pending['verifier'] : null,
        );

        $reauthorize = isset($pending['account_id']) ? SocialAccount::query()->find($pending['account_id']) : null;
        $membership = $this->support->membership($actor);

        if ($reauthorize !== null) {
            $profile = collect($profiles)->first(fn (ProfileConnection $p): bool => $p->externalId === $reauthorize->external_id);

            if ($profile === null) {
                Toast::error(__('The authorised profile is a different account from the one being reconnected.'));

                return redirect()->route('social.accounts.index');
            }

            $this->accounts->connect($platform, $profile, $membership, $reauthorize);
            Toast::success(__('Account reconnected.'));

            return redirect()->route('social.accounts.index');
        }

        if (count($profiles) === 1) {
            $this->accounts->connect($platform, $profiles[0], $membership);
            Toast::success(__('Account connected.'));

            return redirect()->route('social.accounts.index');
        }

        $selection = $this->accounts->stashSelection($platform, $profiles, (string) $actor->getKey());

        return redirect()->route('social.accounts.select', ['selection' => $selection]);
    }

    /** The URI registered with the platform: the central broker's, or this workspace's own. */
    private function redirectUri(string $platform): string
    {
        return $this->broker->redirectUri($platform, route('social.accounts.callback', ['platform' => $platform]));
    }

    /**
     * The returned `state` against the session's random value. A wrapped state must also carry a valid
     * envelope for this very workspace and platform; the inner value is then compared in constant time.
     */
    private function stateMatches(string $platform, string $returned, string $expected, bool $wrapped): bool
    {
        if ($returned === '' || $expected === '') {
            return false;
        }

        if (! $wrapped) {
            return hash_equals($expected, $returned);
        }

        $envelope = $this->broker->unwrap($returned, $platform);

        return $envelope !== null
            && $envelope['slug'] === $this->tenants->require()->slug
            && hash_equals($expected, $envelope['inner']);
    }

    /** Choose which of several granted pages/profiles to connect. */
    public function select(Request $request, string $selection): Response|RedirectResponse
    {
        $actor = $this->support->actor($request);
        $stash = $this->accounts->selection($selection, (string) $actor->getKey());

        if ($stash === null) {
            Toast::error(__('The selection expired. Authorise again.'));

            return redirect()->route('social.accounts.index');
        }

        $connected = SocialAccount::query()->where('platform', $stash['platform'])
            ->where('status', '!=', AccountStatus::Disconnected->value)->pluck('external_id')->all();

        return Inertia::render('Modules/Social/Pages/Accounts/Select', [
            'selection' => $selection,
            'platform' => $stash['platform'],
            'platformLabel' => $this->support->platforms()->find($stash['platform'])?->label() ?? $stash['platform'],
            'profiles' => array_map(static fn (ProfileConnection $profile): array => [
                'externalId' => $profile->externalId,
                'name' => $profile->name,
                'handle' => $profile->handle,
                'avatarUrl' => $profile->avatarUrl,
                'type' => $profile->type,
                'alreadyConnected' => in_array($profile->externalId, $connected, true),
            ], $stash['profiles']),
            'limit' => [
                'limit' => $this->limits->limit(AccountManager::LIMIT),
                'used' => $this->limits->used(AccountManager::LIMIT),
            ],
        ]);
    }

    public function connectSelected(SelectAccountsRequest $request, string $selection): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $stash = $this->accounts->selection($selection, (string) $actor->getKey(), consume: false);

        if ($stash === null) {
            Toast::error(__('The selection expired. Authorise again.'));

            return redirect()->route('social.accounts.index');
        }

        $wanted = array_flip((array) $request->validated('external_ids'));
        $membership = $this->support->membership($actor);
        $connected = 0;

        foreach ($stash['profiles'] as $profile) {
            if (isset($wanted[$profile->externalId])) {
                $this->accounts->connect($stash['platform'], $profile, $membership);
                $connected++;
            }
        }

        if ($connected === 0) {
            throw ValidationException::withMessages(['external_ids' => __('Choose at least one account.')]);
        }

        // Spent: the tokens must not stay in the cache once saved.
        $this->accounts->selection($selection, (string) $actor->getKey(), consume: true);
        Toast::success(trans_choice(':count account connected.|:count accounts connected.', $connected, ['count' => $connected]));

        return redirect()->route('social.accounts.index');
    }

    public function disconnect(Request $request, string $account): RedirectResponse
    {
        $this->support->actor($request);
        $this->accounts->disconnect(SocialAccount::query()->findOrFail($account));
        Toast::success(__('Account disconnected.'));

        return redirect()->route('social.accounts.index');
    }
}
