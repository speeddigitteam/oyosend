<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Contracts;

use App\Modules\Communication\Enums\Channel;

/** A provider that sends email. `channel()` must return {@see Channel::Email}. */
interface EmailChannel extends ChannelProvider {}
