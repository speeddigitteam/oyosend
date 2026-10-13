<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms\Facebook;

use RuntimeException;

/**
 * A Graph API call of the Lead Ads integration that did not succeed, reduced to what the processor decides on.
 * The message is ours and never carries a token or the platform's raw text.
 */
final class LeadAdsGraphException extends RuntimeException
{
    /** The access token is no longer valid (expired, revoked, app removed). */
    public const string TOKEN = 'token';

    /** The token is valid but lacks a permission or a role (leads access) on the Page. */
    public const string PERMISSION = 'permission';

    /** Throttled: nothing was processed; try again later. */
    public const string RATE_LIMIT = 'rate_limit';

    /** The platform failed or could not be reached; trying again may work. */
    public const string TRANSIENT = 'transient';

    /** The lead or form does not exist (any more). */
    public const string NOT_FOUND = 'not_found';

    /** The platform refused the request for a reason retrying does not change. */
    public const string REJECTED = 'rejected';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
