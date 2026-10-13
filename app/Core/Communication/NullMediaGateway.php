<?php

declare(strict_types=1);

namespace App\Core\Communication;

use App\Core\Communication\Contracts\MediaGateway;
use App\Core\Communication\Data\ChannelAvailability;
use App\Core\Communication\Data\ChannelState;
use App\Core\Communication\Data\InboundMedia;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MediaContents;
use App\Core\Communication\Data\MediaOutcome;
use App\Core\Communication\Data\Whatsapp\WhatsappMediaType;

/** Bound when no communication module is installed: nothing is stored or transferred. */
final class NullMediaGateway implements MediaGateway
{
    public const string UNAVAILABLE = 'module_unavailable';

    public function availability(): ChannelAvailability
    {
        return new ChannelAvailability(MarketingChannel::Whatsapp, ChannelState::NotConfigured, self::UNAVAILABLE);
    }

    public function store(WhatsappMediaType $type, string $contents, ?string $filename = null, ?string $declaredMime = null): MediaOutcome
    {
        return MediaOutcome::refused(self::UNAVAILABLE);
    }

    public function storeFromTenantFile(WhatsappMediaType $type, string $tenantPath, ?string $filename = null): MediaOutcome
    {
        return MediaOutcome::refused(self::UNAVAILABLE);
    }

    public function upload(string $mediaId, ?string $senderNumberId = null): MediaOutcome
    {
        return MediaOutcome::refused(self::UNAVAILABLE);
    }

    public function download(InboundMedia $media, ?string $numberId = null): MediaOutcome
    {
        return MediaOutcome::refused(self::UNAVAILABLE);
    }

    public function read(string $mediaId): ?MediaContents
    {
        return null;
    }
}
