<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Data\ChannelAvailability;
use App\Core\Communication\Data\InboundMedia;
use App\Core\Communication\Data\MediaContents;
use App\Core\Communication\Data\MediaOutcome;
use App\Core\Communication\Data\Whatsapp\WhatsappMediaType;

/**
 * Media for WhatsApp messages (ADR 0022): files the workspace sends and files customers send.
 *
 * Files live only in the workspace's own storage (`TenantStorage`, private disk, generated names); an app holds
 * a media id, never a path, a URL or a provider token. The platform owns the provider credential. Every file is
 * checked against a type allow-list and a per-type size cap, and its bytes are sniffed: a file whose content does
 * not match its declared type is refused. Nothing is ever fetched from a URL a customer or an app supplied.
 *
 * Outbound: `store()` or `storeFromTenantFile()` give a media id; put it in a
 * `Data\Whatsapp\MediaReference` of a `WhatsappContent`. The platform uploads it to the provider for the
 * sending number at send time (`upload()` is available for an explicit upload) and caches the provider id.
 *
 * Inbound: the webhook only carries metadata (`ChannelEvent::$content->media`). Call `download()` from a
 * listener or later; provider media ids are short-lived, so download soon. Serve a downloaded file only through
 * your own authorized controller, with the headers of {@see MediaContents::headers()} (nosniff, attachment).
 *
 * Without the communication app, without entitlement or without a configured WhatsApp connection every method
 * answers a not-ok {@see MediaOutcome} (`module_unavailable`, `provider_not_configured`); nothing is faked.
 * Call inside the active tenant context.
 */
interface MediaGateway
{
    public function availability(): ChannelAvailability;

    /**
     * Put bytes into the workspace's media store.
     *
     * @param  string|null  $filename  A display name (never used as a path).
     * @param  string|null  $declaredMime  Used only to pick an allowed type; the sniffed type decides.
     */
    public function store(WhatsappMediaType $type, string $contents, ?string $filename = null, ?string $declaredMime = null): MediaOutcome;

    /**
     * Register a file the caller already holds in `TenantStorage` (a tenant-relative path under the workspace's
     * own root; the platform re-validates and copies it into the media store).
     */
    public function storeFromTenantFile(WhatsappMediaType $type, string $tenantPath, ?string $filename = null): MediaOutcome;

    /**
     * Upload a stored file to the provider for a sending number and return its provider media id in
     * `media->providerMediaId`. Idempotent per (media, number) while the provider id is fresh.
     *
     * @param  string|null  $senderNumberId  Null uses the default number.
     */
    public function upload(string $mediaId, ?string $senderNumberId = null): MediaOutcome;

    /**
     * Download an inbound media into the media store (type allow-list, size cap, sniffed). Idempotent per
     * provider media id: a second call returns the stored file.
     *
     * @param  string|null  $numberId  The number the message was received on (`ChannelEvent::$numberId`); null uses the default.
     */
    public function download(InboundMedia $media, ?string $numberId = null): MediaOutcome;

    /** The stored file's bytes, or null for an id that is not this workspace's. */
    public function read(string $mediaId): ?MediaContents;
}
