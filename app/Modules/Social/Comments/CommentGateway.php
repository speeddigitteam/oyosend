<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments;

use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Comments\Contracts\CommentProvider;
use App\Modules\Social\Comments\Data\CommentActionFailed;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use Closure;

/**
 * The one way comment code reaches a platform: it finds the provider, gets a token that is valid now and, when
 * the platform rejects the stored authorisation, moves the account to "needs reauthorization" (the same state
 * publishing uses) before rethrowing.
 */
final class CommentGateway
{
    public function __construct(
        private readonly CommentProviders $providers,
        private readonly AccountManager $accounts,
    ) {}

    public function provider(SocialAccount $account): CommentProvider
    {
        $provider = $this->providers->for($account->platform);

        if ($provider === null || ! $provider->available()) {
            throw new CommentActionFailed(
                __('Comments are not available for this platform on this installation.'),
                CommentActionFailed::NOT_ALLOWED,
                'comments_unavailable',
            );
        }

        return $provider;
    }

    public function available(SocialAccount $account): bool
    {
        $provider = $this->providers->for($account->platform);

        return $provider !== null && $provider->available();
    }

    public function credentials(SocialAccount $account): AccountCredentials
    {
        try {
            return $this->accounts->credentials($account);
        } catch (PublishFailed $failure) {
            throw new CommentActionFailed(
                $failure->reauthorize
                    ? __('This account must be connected again before comments can be used.')
                    : __('The access token could not be refreshed right now. Try again shortly.'),
                $failure->reauthorize ? CommentActionFailed::TOKEN : CommentActionFailed::UNAVAILABLE,
                $failure->errorCode,
                $failure->retryAfterSeconds,
            );
        }
    }

    /**
     * @template T
     *
     * @param  Closure(CommentProvider, AccountCredentials): T  $call
     * @return T
     *
     * @throws CommentActionFailed
     */
    public function call(SocialAccount $account, Closure $call): mixed
    {
        $provider = $this->provider($account);
        $credentials = $this->credentials($account);

        try {
            return $call($provider, $credentials);
        } catch (CommentActionFailed $failure) {
            if ($failure->kind === CommentActionFailed::TOKEN) {
                $this->accounts->markNeedsReauthorization($account, $failure->getMessage());
            }

            throw $failure;
        }
    }
}
