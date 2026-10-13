<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

use App\Modules\Social\Platforms\Exceptions\PublishFailed;

/**
 * An adapter whose publish cannot finish inside one short worker run (a chunked video upload, several
 * photo uploads, then platform-side processing). The publisher persists the {@see PublishSession} between
 * runs and calls `advance()` again until it returns a published item.
 *
 * Contract:
 *  - `advance()` stops starting new network requests once `$deadline` (unix seconds) is near;
 *  - nothing is public before the platform's final "publish" call, so a failure during the upload steps is
 *    safe to retry (`PublishFailed::$retryable`); only the final call may be an unknown outcome;
 *  - it never repeats the final call once the session carries `externalId`;
 *  - a failure is reported by exception; no message contains a token.
 */
interface StepwisePublisher
{
    /** Whether this payload needs steps (otherwise {@see SocialPlatform::publish()} is used). */
    public function usesSteps(PublishPayload $payload): bool;

    /**
     * @throws PublishFailed
     */
    public function advance(AccountCredentials $account, PublishPayload $payload, PublishSession $session, float $deadline): PublishProgress;
}
