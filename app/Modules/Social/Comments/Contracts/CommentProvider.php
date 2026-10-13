<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Contracts;

use App\Modules\Social\Comments\Data\CommentActionFailed;
use App\Modules\Social\Comments\Data\CommentPage;
use App\Modules\Social\Comments\Data\RemoteComment;
use App\Modules\Social\Platforms\AccountCredentials;
use Carbon\CarbonImmutable;

/**
 * Reads and moderates the audience comments of one platform, only through documented API calls. An
 * implementation never logs or returns a token, never pretends an action happened, and reports a refused
 * call as {@see CommentActionFailed}.
 */
interface CommentProvider
{
    public function platform(): string;

    /** Whether this installation can use it at all (enabled and the platform app configured). */
    public function available(): bool;

    /**
     * Whether a private reply (a message answering a public comment) can be offered for an account holding these scopes.
     *
     * @param  list<string>|null  $grantedScopes
     */
    public function supportsPrivateReplies(?array $grantedScopes): bool;

    /** Recent comments of one post, newest first. A refused read is reported in the page, not thrown. */
    public function recent(AccountCredentials $account, string $postId, ?CarbonImmutable $since, int $limit): CommentPage;

    /**
     * Ids of the Page's most recent posts (organic and published through this application).
     *
     * @return list<string>
     *
     * @throws CommentActionFailed
     */
    public function recentPostIds(AccountCredentials $account, int $limit): array;

    /** @throws CommentActionFailed */
    public function find(AccountCredentials $account, string $commentId): ?RemoteComment;

    /**
     * @return string The id of the new reply.
     *
     * @throws CommentActionFailed
     */
    public function reply(AccountCredentials $account, string $commentId, string $message): string;

    /** @throws CommentActionFailed */
    public function setHidden(AccountCredentials $account, string $commentId, bool $hidden): void;

    /** @throws CommentActionFailed */
    public function delete(AccountCredentials $account, string $commentId): void;

    /** @throws CommentActionFailed */
    public function setLiked(AccountCredentials $account, string $commentId, bool $liked): void;

    /** @throws CommentActionFailed */
    public function privateReply(AccountCredentials $account, string $commentId, string $message): void;

    /**
     * Subscribe the Page to this application's `feed` events.
     *
     * @throws CommentActionFailed
     */
    public function subscribe(AccountCredentials $account): void;
}
