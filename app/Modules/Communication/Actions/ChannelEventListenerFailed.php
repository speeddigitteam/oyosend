<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use RuntimeException;

/** An app's inbound-message listener failed; the provider event must be redelivered. Carries no content. */
final class ChannelEventListenerFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A channel event listener failed; the provider event will be redelivered.');
    }
}
