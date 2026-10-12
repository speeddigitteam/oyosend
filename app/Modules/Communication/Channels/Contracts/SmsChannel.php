<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Contracts;

use App\Modules\Communication\Enums\Channel;

/** A provider that sends SMS. `channel()` must return {@see Channel::Sms}. */
interface SmsChannel extends ChannelProvider {}
