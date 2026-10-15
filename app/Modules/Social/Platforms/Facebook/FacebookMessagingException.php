<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use RuntimeException;

/**
 * A Messenger / Instagram messaging call Meta did not accept, in words a person can act on. The message never carries
 * a token, a URL, a request body or the text of a message.
 */
final class FacebookMessagingException extends RuntimeException
{
    /** The Page token was rejected: the account must be connected again. */
    public const string TOKEN = 'token';

    /** The token lacks a permission or the person who connected the Page lacks a role on it. */
    public const string PERMISSION = 'permission';

    /** Meta is limiting requests; nothing was sent. */
    public const string RATE_LIMIT = 'rate_limit';

    /** Meta refused the reply because the standard messaging window has closed. */
    public const string WINDOW = 'window';

    /** The person cannot receive messages from the Page now (blocked it, deleted the account, ...). */
    public const string RECIPIENT = 'recipient';

    /** Meta could not be reached, or did not confirm the call. */
    public const string UNAVAILABLE = 'unavailable';

    /** Meta rejected the request for another reason. */
    public const string REJECTED = 'rejected';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly string $errorCode = 'messaging_failed',
        public readonly bool $maybeSent = false,
    ) {
        parent::__construct($message);
    }
}
