<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Data;

use RuntimeException;

/**
 * A comment call the platform did not accept, in terms the interface can act on. The message is written for
 * a person and never carries a token, a URL or a request body.
 */
final class CommentActionFailed extends RuntimeException
{
    public const string TOKEN = 'token';

    public const string PERMISSION = 'permission';

    public const string RATE_LIMIT = 'rate_limit';

    public const string GONE = 'gone';

    public const string REJECTED = 'rejected';

    public const string UNAVAILABLE = 'unavailable';

    public const string NOT_ALLOWED = 'not_allowed';

    public function __construct(
        string $message,
        public readonly string $kind,
        public readonly string $errorCode = 'comment_action_failed',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /** The stored authorisation no longer works: the account must be connected again. */
    public function needsReauthorization(): bool
    {
        return in_array($this->kind, [self::TOKEN, self::PERMISSION], true);
    }

    /** Safe to try again later without risking a duplicate. */
    public function isTemporary(): bool
    {
        return in_array($this->kind, [self::RATE_LIMIT, self::UNAVAILABLE], true);
    }
}
