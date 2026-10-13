<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Communication\Contracts\MediaGateway;
use App\Core\Communication\Data\ChannelAvailability;
use App\Core\Communication\Data\ChannelState;
use App\Core\Communication\Data\InboundMedia;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MediaContents;
use App\Core\Communication\Data\MediaOutcome;
use App\Core\Communication\Data\StoredMedia;
use App\Core\Communication\Data\Whatsapp\WhatsappMediaType;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Communication\Channels\Contracts\HandlesMedia;
use App\Modules\Communication\Channels\CredentialResolver;
use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\MediaTransfer;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\ChannelPhoneNumber;
use App\Modules\Communication\Models\MediaAsset;
use App\Modules\Communication\Models\MediaUpload;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The implementation of the core {@see MediaGateway}: the workspace's media store for WhatsApp.
 *
 * Rules (ADR 0022): files live only in `TenantStorage` under a generated name (`communication/media/{id}.{ext}`),
 * the database holds metadata and a tenant-relative path that is never returned; an allow-list of types per media
 * type and a per-type size cap decide what is accepted; the bytes are sniffed with libmagic and must agree with
 * the declared type (a disguised file is refused, never stored); the stored name's extension comes from the
 * sniffed type, not from the sender's file name; nothing is fetched from a URL an app or a customer supplied.
 */
final class MediaLibrary implements MediaGateway
{
    /**
     * Allowed sniffed MIME types per media type, and the extension a stored file gets.
     *
     * @var array<string, array<string, string>>
     */
    private const array ALLOWED = [
        'image' => ['image/jpeg' => 'jpg', 'image/png' => 'png'],
        'sticker' => ['image/webp' => 'webp'],
        'audio' => ['audio/aac' => 'aac', 'audio/mp4' => 'm4a', 'audio/mpeg' => 'mp3', 'audio/amr' => 'amr', 'audio/ogg' => 'ogg'],
        'video' => ['video/mp4' => 'mp4', 'video/3gpp' => '3gp'],
        'document' => [
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'application/msword' => 'doc',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        ],
    ];

    /** What libmagic calls the same thing. */
    private const array ALIASES = [
        'audio/x-hx-aac-adts' => 'audio/aac',
        'audio/x-aac' => 'audio/aac',
        'audio/x-m4a' => 'audio/mp4',
        'audio/m4a' => 'audio/mp4',
        'application/ogg' => 'audio/ogg',
        'audio/opus' => 'audio/ogg',
        'audio/x-amr' => 'audio/amr',
        'audio/amr-wb' => 'audio/amr',
        'video/3gp' => 'video/3gpp',
    ];

    /** Container formats libmagic reports for Office files. */
    private const array OFFICE_CONTAINERS = [
        'application/zip', 'application/x-zip-compressed', 'application/octet-stream',
        'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/CDFV2-corrupt',
    ];

    private const array OOXML_BY_EXTENSION = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly ChannelConnections $connections,
        private readonly ChannelNumbers $numbers,
        private readonly CredentialResolver $credentials,
        private readonly TenantStorage $storage,
    ) {}

    public function availability(): ChannelAvailability
    {
        $channel = MarketingChannel::Whatsapp;

        if (! $this->access->entitled()) {
            return new ChannelAvailability($channel, ChannelState::NotConfigured, 'module_unavailable');
        }

        ['connection' => $connection, 'provider' => $provider] = $this->connections->resolve(Channel::Whatsapp);

        if (! $connection->is_enabled || ! $provider instanceof HandlesMedia
            || $this->connections->stateOf($connection, $provider) === ConfigurationState::NotConfigured) {
            return new ChannelAvailability($channel, ChannelState::NotConfigured, 'provider_not_configured', $provider->isTestDouble());
        }

        return new ChannelAvailability($channel, ChannelState::Available);
    }

    public function store(WhatsappMediaType $type, string $contents, ?string $filename = null, ?string $declaredMime = null): MediaOutcome
    {
        if (! $this->access->entitled()) {
            return MediaOutcome::refused('module_unavailable');
        }

        return $this->persist($type->value, 'outbound', $contents, $filename, $declaredMime, null, null, null);
    }

    public function storeFromTenantFile(WhatsappMediaType $type, string $tenantPath, ?string $filename = null): MediaOutcome
    {
        if (! $this->access->entitled()) {
            return MediaOutcome::refused('module_unavailable');
        }

        try {
            $contents = $this->storage->get($tenantPath);
        } catch (InvalidArgumentException) {
            return MediaOutcome::refused('not_found');
        }

        if ($contents === null) {
            return MediaOutcome::refused('not_found');
        }

        return $this->persist($type->value, 'outbound', $contents, $filename ?? basename($tenantPath), null, null, null, null);
    }

    public function upload(string $mediaId, ?string $senderNumberId = null): MediaOutcome
    {
        if (! $this->access->entitled()) {
            return MediaOutcome::refused('module_unavailable');
        }

        $asset = MediaAsset::query()->whereKey($mediaId)->first();

        if ($asset === null) {
            return MediaOutcome::refused('not_found');
        }

        ['connection' => $connection, 'provider' => $provider] = $this->connections->resolve(Channel::Whatsapp);

        if (! $connection->is_enabled || ! $provider instanceof HandlesMedia
            || $this->connections->stateOf($connection, $provider) === ConfigurationState::NotConfigured) {
            return MediaOutcome::refused('provider_not_configured');
        }

        $number = $this->numbers->senderRow(MarketingChannel::Whatsapp, $senderNumberId);

        if ($number === null) {
            return MediaOutcome::refused('number_unavailable');
        }

        $credentials = $this->credentials->resolve($connection->credential_ref, $connection->provider);

        if ($credentials === null) {
            return MediaOutcome::refused('provider_not_configured');
        }

        return $this->uploadAsset($asset, $number, $connection, $provider, $credentials);
    }

    /**
     * Upload a stored file for one number (or return the fresh provider id it already has). Used by the
     * dispatcher at send time and by `upload()`.
     */
    public function uploadAsset(MediaAsset $asset, ChannelPhoneNumber $number, ChannelConnection $connection, HandlesMedia $provider, ChannelCredentials $credentials): MediaOutcome
    {
        $reuse = MediaUpload::query()
            ->where('media_id', $asset->id)
            ->where('number_id', $number->id)
            ->where('expires_at', '>', CarbonImmutable::now())
            ->first();

        if ($reuse !== null) {
            return MediaOutcome::ok($this->present($asset, $reuse->provider_media_id, $number->id));
        }

        try {
            $bytes = $this->storage->get($asset->storage_path);
        } catch (Throwable) {
            $bytes = null;
        }

        if ($bytes === null) {
            return MediaOutcome::refused('not_found');
        }

        $transfer = $provider->uploadMedia($connection, $credentials, $number->phone_number_id, $bytes, $asset->mime, $asset->filename ?? $asset->id.'.bin');

        if (! $transfer->ok || $transfer->providerMediaId === null) {
            return MediaOutcome::refused($transfer->reason ?? 'provider_unavailable');
        }

        $days = max(1, min(29, (int) config('communication.whatsapp.media.upload_reuse_days', 25)));

        MediaUpload::query()->updateOrCreate(
            ['media_id' => $asset->id, 'number_id' => $number->id],
            ['provider_media_id' => $transfer->providerMediaId, 'expires_at' => CarbonImmutable::now()->addDays($days)],
        );

        return MediaOutcome::ok($this->present($asset, $transfer->providerMediaId, $number->id));
    }

    public function download(InboundMedia $media, ?string $numberId = null): MediaOutcome
    {
        if (! $this->access->entitled()) {
            return MediaOutcome::refused('module_unavailable');
        }

        $type = $media->type;
        $max = $this->maxBytes($type);

        if (! isset(self::ALLOWED[$type]) || $max === null) {
            return MediaOutcome::refused('unsupported_media_type');
        }

        $existing = MediaAsset::query()->where('direction', 'inbound')->where('provider_media_id', $media->providerMediaId)->first();

        if ($existing !== null) {
            return MediaOutcome::ok($this->present($existing));
        }

        ['connection' => $connection, 'provider' => $provider] = $this->connections->resolve(Channel::Whatsapp);

        if (! $connection->is_enabled || ! $provider instanceof HandlesMedia
            || $this->connections->stateOf($connection, $provider) === ConfigurationState::NotConfigured) {
            return MediaOutcome::refused('provider_not_configured');
        }

        $number = $numberId === null
            ? $this->numbers->senderRow(MarketingChannel::Whatsapp, null)
            : ChannelPhoneNumber::query()->whereKey($numberId)->where('connection_id', $connection->id)->first();

        $credentials = $this->credentials->resolve($connection->credential_ref, $connection->provider);

        if ($credentials === null) {
            return MediaOutcome::refused('provider_not_configured');
        }

        $transfer = $provider->fetchMedia($connection, $credentials, $media->providerMediaId, $number?->phone_number_id, $max);

        if (! $transfer->ok || $transfer->bytes === null) {
            return MediaOutcome::refused($this->mapTransferFailure($transfer));
        }

        if ($transfer->sha256 !== null && ! hash_equals($transfer->sha256, hash('sha256', $transfer->bytes))) {
            return MediaOutcome::refused('content_mismatch');
        }

        return $this->persist($type, 'inbound', $transfer->bytes, $media->filename, $transfer->mime ?? $media->mimeType, $media->providerMediaId, $number?->id, $max);
    }

    public function read(string $mediaId): ?MediaContents
    {
        if (! $this->access->entitled()) {
            return null;
        }

        $asset = MediaAsset::query()->whereKey($mediaId)->first();

        if ($asset === null) {
            return null;
        }

        try {
            $bytes = $this->storage->get($asset->storage_path);
        } catch (Throwable) {
            return null;
        }

        return $bytes === null ? null : new MediaContents($this->present($asset), $bytes);
    }

    /** The asset of THIS workspace for a media id (the dispatcher's lookup). */
    public function find(string $mediaId): ?MediaAsset
    {
        return MediaAsset::query()->whereKey($mediaId)->first();
    }

    // ---- Internals ---------------------------------------------------------------------------------------

    private function persist(string $type, string $direction, string $contents, ?string $filename, ?string $declaredMime, ?string $providerMediaId, ?string $numberId, ?int $cap): MediaOutcome
    {
        $max = $cap ?? $this->maxBytes($type);

        if (! isset(self::ALLOWED[$type]) || $max === null) {
            return MediaOutcome::refused('unsupported_media_type');
        }

        $size = strlen($contents);

        if ($size === 0) {
            return MediaOutcome::refused('empty_file');
        }

        if ($size > $max) {
            return MediaOutcome::refused('too_large');
        }

        $name = $this->displayName($filename);
        $mime = $this->resolveMime($type, $contents, $declaredMime, $name);

        if ($mime === null) {
            return MediaOutcome::refused($this->sniff($contents) !== null && ! $this->isAllowedAnywhere($contents)
                ? 'unsupported_media_type'
                : 'content_mismatch');
        }

        $extension = self::ALLOWED[$type][$mime];
        $path = 'communication/media/'.Str::ulid()->toBase32().'.'.$extension;
        $sha256 = hash('sha256', $contents);

        if (! $this->storage->put($path, $contents)) {
            return MediaOutcome::refused('provider_unavailable');
        }

        try {
            $asset = MediaAsset::query()->create([
                'direction' => $direction,
                'type' => $type,
                'mime' => $mime,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'filename' => $name,
                'storage_path' => $path,
                'provider_media_id' => $providerMediaId,
                'number_id' => $numberId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent download of the same provider media won: its row is the answer.
            $this->storage->delete($path);
            $existing = MediaAsset::query()->where('direction', $direction)->where('provider_media_id', $providerMediaId)->first();

            return $existing === null ? MediaOutcome::refused('provider_unavailable') : MediaOutcome::ok($this->present($existing));
        }

        return MediaOutcome::ok($this->present($asset));
    }

    /**
     * The canonical MIME type of the bytes for this media type, or null when the content is not an allowed
     * file of that type. The sniffed type decides; the declared type and the file extension only disambiguate
     * container formats (Office files are ZIP or OLE inside).
     */
    private function resolveMime(string $type, string $bytes, ?string $declared, ?string $filename): ?string
    {
        $allowed = self::ALLOWED[$type];
        $sniffed = $this->sniff($bytes);

        if ($sniffed === null) {
            return null;
        }

        if (isset($allowed[$sniffed])) {
            return $this->declaredAgrees($declared, $sniffed, $allowed) ? $sniffed : null;
        }

        // m4a/mp4 audio is often sniffed as video/mp4.
        if ($type === 'audio' && $sniffed === 'video/mp4' && ($declared === null || $this->normalize($declared) === 'audio/mp4')) {
            return 'audio/mp4';
        }

        if ($type === 'document' && in_array($sniffed, self::OFFICE_CONTAINERS, true)) {
            $candidate = $declared === null ? null : $this->normalize($declared);

            if ($candidate === null || ! isset($allowed[$candidate])) {
                $extension = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));
                $candidate = self::OOXML_BY_EXTENSION[$extension] ?? ($extension === 'doc' ? 'application/msword' : ($extension === 'xls' ? 'application/vnd.ms-excel' : ($extension === 'ppt' ? 'application/vnd.ms-powerpoint' : null)));
            }

            $isOoxml = $candidate !== null && in_array($candidate, self::OOXML_BY_EXTENSION, true);
            $isLegacy = in_array($candidate, ['application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint'], true);
            $zip = in_array($sniffed, ['application/zip', 'application/x-zip-compressed'], true);
            $ole = in_array($sniffed, ['application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/CDFV2-corrupt'], true);

            // The container must match the claimed Office family; octet-stream is never accepted as a document.
            if (($isOoxml && $zip && $this->hasZipSignature($bytes)) || ($isLegacy && $ole)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string, string> $allowed */
    private function declaredAgrees(?string $declared, string $sniffed, array $allowed): bool
    {
        if ($declared === null || $declared === '') {
            return true;
        }

        $declared = $this->normalize($declared);

        // A declared type of the same media type but another allowed format is a mismatch ("a png sent as jpeg").
        return $declared === $sniffed || ! isset($allowed[$declared]);
    }

    private function isAllowedAnywhere(string $bytes): bool
    {
        $sniffed = $this->sniff($bytes);

        foreach (self::ALLOWED as $types) {
            if ($sniffed !== null && isset($types[$sniffed])) {
                return true;
            }
        }

        return false;
    }

    private function sniff(string $bytes): ?string
    {
        if (! class_exists(\finfo::class)) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($mime) && $mime !== '' ? $this->normalize($mime) : null;
    }

    private function normalize(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));

        return self::ALIASES[$mime] ?? $mime;
    }

    private function hasZipSignature(string $bytes): bool
    {
        return str_starts_with($bytes, "PK\x03\x04");
    }

    private function maxBytes(string $type): ?int
    {
        $value = config('communication.whatsapp.media.max_bytes.'.$type);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function displayName(?string $filename): ?string
    {
        if ($filename === null) {
            return null;
        }

        // Never a path: the last segment only, control characters removed.
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $filename))));

        return $name === '' ? null : mb_substr($name, 0, 240);
    }

    private function mapTransferFailure(MediaTransfer $transfer): string
    {
        return match ($transfer->reason) {
            MediaTransfer::TOO_LARGE => 'too_large',
            MediaTransfer::UNREACHABLE => 'provider_unreachable',
            MediaTransfer::UNAVAILABLE => 'provider_unavailable',
            MediaTransfer::AUTH => 'provider_authentication_failed',
            default => 'provider_rejected',
        };
    }

    private function present(MediaAsset $asset, ?string $providerMediaId = null, ?string $numberId = null): StoredMedia
    {
        return new StoredMedia(
            $asset->id,
            $asset->type,
            $asset->mime,
            $asset->size_bytes,
            $asset->sha256,
            $asset->filename,
            $asset->direction,
            $providerMediaId ?? $asset->provider_media_id,
            $numberId ?? $asset->number_id,
        );
    }
}
