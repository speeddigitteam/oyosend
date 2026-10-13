<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use App\Modules\Social\Comments\Data\CommentEvent;
use App\Modules\Social\Comments\Data\RemoteComment;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The Page `feed` webhook: signature check and parsing. Nothing here trusts the body before the signature
 * (HMAC-SHA256 over the raw bytes with the app secret, header `X-Hub-Signature-256`) has been verified.
 *
 * Documented payload: `{"object":"page","entry":[{"id":"<page>","time":..,"changes":[{"field":"feed",
 * "value":{"item":"comment","verb":"add|edited|remove|hide|unhide","comment_id":"..","post_id":"..",
 * "parent_id":"..","from":{"id":"..","name":".."},"message":"..","created_time":..}}]}]}`.
 */
final class FacebookCommentsWebhook
{
    public static function validSignature(string $rawBody, ?string $header, string $secret): bool
    {
        if ($secret === '' || $header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), substr($header, 7));
    }

    /** The one-time subscription handshake: the challenge to echo, or null when the request is not ours. */
    public static function handshake(?string $mode, ?string $token, ?string $challenge, ?string $expected): ?string
    {
        if ($mode !== 'subscribe' || $expected === null || $expected === '' || $token === null || ! hash_equals($expected, $token)) {
            return null;
        }

        return $challenge !== null && preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $challenge) === 1 ? $challenge : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<CommentEvent>
     */
    public static function events(array $payload, int $maxChanges = 200): array
    {
        if (($payload['object'] ?? null) !== 'page' || ! is_array($payload['entry'] ?? null)) {
            return [];
        }

        $events = [];

        foreach ($payload['entry'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $pageId = self::id($entry['id'] ?? null);

            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if ($pageId === null || ! is_array($change) || ($change['field'] ?? null) !== 'feed' || ! is_array($change['value'] ?? null)) {
                    continue;
                }

                $value = $change['value'];
                $verb = is_string($value['verb'] ?? null) ? $value['verb'] : '';
                $commentId = self::id($value['comment_id'] ?? null);

                if (($value['item'] ?? null) !== 'comment' || $commentId === null || ! in_array($verb, ['add', 'edited', 'remove', 'hide', 'unhide'], true)) {
                    continue;
                }

                $from = is_array($value['from'] ?? null) ? $value['from'] : [];
                $created = $value['created_time'] ?? null;

                try {
                    $createdAt = is_numeric($created)
                        ? CarbonImmutable::createFromTimestampUTC((int) $created)
                        : (is_string($created) ? CarbonImmutable::parse($created)->utc() : null);
                } catch (Throwable) {
                    $createdAt = null;
                }

                $parent = self::id($value['parent_id'] ?? null);
                $post = self::id($value['post_id'] ?? null);

                $events[] = new CommentEvent(
                    $pageId,
                    $verb,
                    new RemoteComment(
                        id: $commentId,
                        postId: $post,
                        // A top-level comment names its post as parent; only a reply has another comment as parent.
                        parentId: $parent !== null && $parent !== $post ? $parent : null,
                        authorId: self::id($from['id'] ?? null),
                        authorName: is_string($from['name'] ?? null) ? $from['name'] : null,
                        message: is_string($value['message'] ?? null) ? $value['message'] : null,
                        createdAt: $createdAt,
                        isHidden: $verb === 'hide',
                    ),
                    sha1($pageId.'|'.$verb.'|'.$commentId.'|'.(string) ($entry['time'] ?? '').'|'.(is_scalar($created) ? (string) $created : '')),
                );

                if (count($events) >= $maxChanges) {
                    return $events;
                }
            }
        }

        return $events;
    }

    private static function id(mixed $value): ?string
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[A-Za-z0-9_\-]{1,100}$/', (string) $value) === 1 ? (string) $value : null;
    }
}
