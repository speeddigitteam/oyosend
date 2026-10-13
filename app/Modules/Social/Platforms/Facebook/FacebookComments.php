<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use App\Modules\Social\Comments\Contracts\CommentProvider;
use App\Modules\Social\Comments\Data\CommentActionFailed;
use App\Modules\Social\Comments\Data\CommentPage;
use App\Modules\Social\Comments\Data\RemoteComment;
use App\Modules\Social\Comments\Support\UntrustedText;
use App\Modules\Social\Platforms\AccountCredentials;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Facebook Page comments through the documented Graph API. Every call uses the Page access token in the
 * `Authorization: Bearer` header; it is never put in a URL, a message or a log line.
 *
 *  - `GET  /{post-id}/comments`            read (`filter=stream`, `order=reverse_chronological`, `since`)
 *  - `GET  /{page-id}/posts`               the Page's recent posts (ids), for the polling fallback
 *  - `GET  /{comment-id}`                  one comment
 *  - `POST /{comment-id}/comments`         reply (`message`)
 *  - `POST /{comment-id}`                  hide or unhide (`is_hidden`)
 *  - `DELETE /{comment-id}`                delete
 *  - `POST|DELETE /{comment-id}/likes`     like / unlike as the Page
 *  - `POST /{comment-id}/private_replies`  one private reply per comment (needs `pages_messaging`)
 *  - `POST /{page-id}/subscribed_apps`     subscribe the Page to `feed` webhook events
 *
 * Permissions: `pages_read_engagement` (read), `pages_manage_engagement` (reply, hide, delete, like),
 * `pages_manage_metadata` (webhook subscription) and, for private replies only, `pages_messaging`, each
 * through Meta app review. NOT yet exercised against Meta's live API in this repository.
 */
final class FacebookComments implements CommentProvider
{
    private const array RATE_LIMIT_CODES = [4, 17, 32, 613, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

    private const array TOKEN_CODES = [102, 190, 459, 464, 467];

    private const string FIELDS = 'id,message,created_time,from{id,name},parent{id},like_count,attachment{type},is_hidden,permalink_url';

    public function __construct(private readonly Factory $http) {}

    public function platform(): string
    {
        return 'facebook';
    }

    public function available(): bool
    {
        return (bool) config('social_comments.enabled', true)
            && is_string(config('social.platforms.facebook.client_id')) && config('social.platforms.facebook.client_id') !== ''
            && is_string(config('social.platforms.facebook.client_secret')) && config('social.platforms.facebook.client_secret') !== '';
    }

    /** @param list<string>|null $grantedScopes */
    public function supportsPrivateReplies(?array $grantedScopes): bool
    {
        return (bool) config('social_comments.facebook.private_replies', false)
            && is_array($grantedScopes) && in_array('pages_messaging', $grantedScopes, true);
    }

    /**
     * The extra permissions the connect dialog asks for when comments are enabled.
     *
     * @return list<string>
     */
    public static function requestedScopes(): array
    {
        if (! (bool) config('social_comments.enabled', true)) {
            return [];
        }

        $scopes = ['pages_manage_engagement', 'pages_manage_metadata'];

        if ((bool) config('social_comments.facebook.private_replies', false)) {
            $scopes[] = 'pages_messaging';
        }

        return $scopes;
    }

    public function recent(AccountCredentials $account, string $postId, ?CarbonImmutable $since, int $limit): CommentPage
    {
        $collected = [];
        $after = null;
        $pages = max(1, (int) config('social_comments.facebook.max_pages', 2));

        for ($page = 0; $page < $pages && count($collected) < $limit; $page++) {
            $query = [
                'fields' => self::FIELDS,
                'filter' => 'stream',
                'order' => 'reverse_chronological',
                'limit' => min(25, max(1, $limit)),
            ];

            if ($since !== null) {
                $query['since'] = $since->getTimestamp();
            }

            if ($after !== null) {
                $query['after'] = $after;
            }

            try {
                $response = $this->request($account)->get($this->url($postId.'/comments'), $query);
            } catch (ConnectionException) {
                return new CommentPage($collected, 'unavailable', 'network_unreachable');
            }

            if (! $response->successful()) {
                $failure = $this->failure($response);

                return new CommentPage($collected, $failure->kind, $failure->errorCode, $failure->retryAfter);
            }

            foreach ((array) $response->json('data', []) as $row) {
                $comment = is_array($row) ? $this->map($row, $postId) : null;

                if ($comment !== null) {
                    $collected[] = $comment;
                }
            }

            $next = $response->json('paging.cursors.after');
            $hasNext = is_string($response->json('paging.next'));

            if (! $hasNext || ! is_string($next) || $next === '') {
                break;
            }

            $after = $next;
        }

        return new CommentPage(array_slice($collected, 0, $limit));
    }

    public function recentPostIds(AccountCredentials $account, int $limit): array
    {
        $response = $this->send(fn (): Response => $this->request($account)->get($this->url($account->externalId.'/posts'), [
            'fields' => 'id',
            'limit' => min(100, max(1, $limit)),
        ]));

        $ids = [];

        foreach ((array) $response->json('data', []) as $row) {
            $id = is_array($row) ? $this->id($row['id'] ?? null) : null;

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function find(AccountCredentials $account, string $commentId): ?RemoteComment
    {
        $response = $this->send(fn (): Response => $this->request($account)->get($this->url($commentId), ['fields' => self::FIELDS]));
        $row = $response->json();

        return is_array($row) ? $this->map($row, null) : null;
    }

    public function reply(AccountCredentials $account, string $commentId, string $message): string
    {
        $response = $this->send(fn (): Response => $this->request($account)->asForm()->post($this->url($commentId.'/comments'), ['message' => $message]));
        $id = $this->id($response->json('id'));

        if ($id === null) {
            // Accepted but unidentified: the reply may be public, so this must not be retried blindly.
            throw new CommentActionFailed(__('Facebook accepted the reply but returned no id. Check the post before trying again.'), CommentActionFailed::UNAVAILABLE, 'missing_comment_id');
        }

        return $id;
    }

    public function setHidden(AccountCredentials $account, string $commentId, bool $hidden): void
    {
        $this->send(fn (): Response => $this->request($account)->asForm()->post($this->url($commentId), ['is_hidden' => $hidden ? 'true' : 'false']));
    }

    public function delete(AccountCredentials $account, string $commentId): void
    {
        $this->send(fn (): Response => $this->request($account)->delete($this->url($commentId)));
    }

    public function setLiked(AccountCredentials $account, string $commentId, bool $liked): void
    {
        $this->send(fn (): Response => $liked
            ? $this->request($account)->asForm()->post($this->url($commentId.'/likes'))
            : $this->request($account)->delete($this->url($commentId.'/likes')));
    }

    public function privateReply(AccountCredentials $account, string $commentId, string $message): void
    {
        $this->send(fn (): Response => $this->request($account)->asForm()->post($this->url($commentId.'/private_replies'), ['message' => $message]));
    }

    public function subscribe(AccountCredentials $account): void
    {
        $this->send(fn (): Response => $this->request($account)->asForm()->post($this->url($account->externalId.'/subscribed_apps'), ['subscribed_fields' => 'feed']));
    }

    /** @param Closure(): Response $call */
    private function send(Closure $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $exception) {
            $message = $exception->getMessage();
            $neverSent = str_contains($message, 'Could not resolve host') || str_contains($message, 'Failed to connect')
                || str_contains($message, 'Connection refused') || str_contains($message, 'cURL error 6') || str_contains($message, 'cURL error 7');

            throw new CommentActionFailed(
                $neverSent ? __('Facebook could not be reached.') : __('Facebook did not confirm the request. Check the post before trying again.'),
                CommentActionFailed::UNAVAILABLE,
                $neverSent ? 'network_unreachable' : 'network_unconfirmed',
            );
        } catch (Throwable $exception) {
            // The exception text can carry a URL; never pass it on.
            throw new CommentActionFailed(__('Facebook did not accept the request.'), CommentActionFailed::UNAVAILABLE, 'transport_error');
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        return $response;
    }

    private function failure(Response $response): CommentActionFailed
    {
        $code = (int) $response->json('error.code', 0);
        $subcode = (int) $response->json('error.error_subcode', 0);
        $text = FacebookGraphErrors::clean($response->json('error.message'), __('Facebook rejected the request.'));

        if (in_array($code, self::TOKEN_CODES, true) || $response->status() === 401) {
            return new CommentActionFailed(
                __('Facebook no longer accepts this Page connection. Connect the Page again to continue.'),
                CommentActionFailed::TOKEN,
                FacebookGraphErrors::TOKEN,
            );
        }

        if (in_array($code, [3, 10], true) || ($code >= 200 && $code <= 299)) {
            return new CommentActionFailed(
                __('Facebook says this connection is missing a permission needed for comments. Connect the Page again and approve every permission it asks for.'),
                CommentActionFailed::PERMISSION,
                FacebookGraphErrors::PERMISSION,
            );
        }

        if (in_array($code, self::RATE_LIMIT_CODES, true) || $response->status() === 429) {
            $header = $response->header('Retry-After');

            return new CommentActionFailed(
                __('Facebook is limiting requests right now. Try again in a little while.'),
                CommentActionFailed::RATE_LIMIT,
                'rate_limited',
                is_numeric($header) ? max(1, min(3600, (int) $header)) : null,
            );
        }

        if ($response->status() === 404 || ($code === 100 && $subcode === 33) || $code === 1705) {
            return new CommentActionFailed(__('This comment no longer exists on Facebook.'), CommentActionFailed::GONE, 'comment_gone');
        }

        if ($response->serverError() || in_array($code, [1, 2], true)) {
            return new CommentActionFailed($text, CommentActionFailed::UNAVAILABLE, 'platform_error');
        }

        return new CommentActionFailed($text, CommentActionFailed::REJECTED, 'facebook_'.($code > 0 ? $code : $response->status()));
    }

    /** @param array<string, mixed> $row */
    private function map(array $row, ?string $postId): ?RemoteComment
    {
        $id = $this->id($row['id'] ?? null);

        if ($id === null) {
            return null;
        }

        $created = $row['created_time'] ?? null;

        try {
            $createdAt = is_string($created) ? CarbonImmutable::parse($created)->utc() : null;
        } catch (Throwable) {
            $createdAt = null;
        }

        $from = is_array($row['from'] ?? null) ? $row['from'] : [];
        $parent = is_array($row['parent'] ?? null) ? $this->id($row['parent']['id'] ?? null) : null;
        $permalink = $row['permalink_url'] ?? null;

        return new RemoteComment(
            id: $id,
            postId: $postId ?? $this->postIdOf($id),
            parentId: $parent,
            authorId: $this->id($from['id'] ?? null),
            authorName: isset($from['name']) ? UntrustedText::line($from['name'], 190) : null,
            message: is_string($row['message'] ?? null) ? $row['message'] : null,
            createdAt: $createdAt,
            likeCount: is_numeric($row['like_count'] ?? null) ? max(0, (int) $row['like_count']) : 0,
            hasAttachment: is_array($row['attachment'] ?? null),
            isHidden: ($row['is_hidden'] ?? false) === true,
            permalink: is_string($permalink) && str_starts_with($permalink, 'https://') ? mb_substr($permalink, 0, 500) : null,
        );
    }

    /** A comment id is `{post-id}_{comment}`; the part before the last underscore names its post. */
    private function postIdOf(string $commentId): ?string
    {
        $position = strrpos($commentId, '_');

        return $position === false || $position === 0 ? null : substr($commentId, 0, $position);
    }

    private function id(mixed $value): ?string
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[A-Za-z0-9_\-]{1,100}$/', (string) $value) === 1 ? (string) $value : null;
    }

    private function request(AccountCredentials $account): PendingRequest
    {
        return $this->http->timeout(20)->connectTimeout(10)->acceptJson()->withUserAgent('Oyosend-Social/1.0')->withToken($account->accessToken);
    }

    private function url(string $path): string
    {
        $version = config('social.platforms.facebook.api_version');

        return 'https://graph.facebook.com/'.(is_string($version) && $version !== '' ? $version : 'v23.0').'/'.ltrim($path, '/');
    }
}
