<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Contracts;

use App\Modules\Communication\Enums\Channel;

/**
 * A provider that sends WhatsApp messages through the official Business API only.
 * `channel()` must return {@see Channel::Whatsapp}.
 */
interface WhatsAppChannel extends ChannelProvider {}
