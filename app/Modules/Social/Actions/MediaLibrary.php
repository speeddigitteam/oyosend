<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Social\Enums\MediaKind;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\PostMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The workspace media library. Files are checked by their content (not the name or the
 * browser-supplied type), stored only through `TenantStorage` on the private disk under a
 * generated name, and served only by the authorised file route.
 */
final class MediaLibrary
{
    private const int MAX_DIMENSION = 20000;

    public function __construct(
        private readonly TenantStorage $storage,
    ) {}

    /**
     * @throws ValidationException
     */
    public function store(UploadedFile $file, ?TenantMembership $by): Media
    {
        if (! $file->isValid() || $file->getRealPath() === false) {
            throw ValidationException::withMessages(['file' => __('The file could not be uploaded.')]);
        }

        $mime = (string) $file->getMimeType();
        $kind = $this->kind($mime);
        $sizeBytes = (int) $file->getSize();
        $maxKb = (int) config($kind === MediaKind::Video ? 'social.media.video_max_kb' : 'social.media.image_max_kb', 8192);

        if ($sizeBytes < 1 || $sizeBytes > $maxKb * 1024) {
            throw ValidationException::withMessages(['file' => __('The file must be smaller than :size MB.', ['size' => round($maxKb / 1024, 1)])]);
        }

        $width = $height = null;

        if ($kind === MediaKind::Image) {
            $info = @getimagesize($file->getRealPath());

            if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
                throw ValidationException::withMessages(['file' => __('The image could not be read or is too large.')]);
            }

            [$width, $height] = [$info[0], $info[1]];
        }

        $hash = hash_file('sha256', $file->getRealPath());

        if ($hash === false) {
            throw ValidationException::withMessages(['file' => __('The file could not be read.')]);
        }

        $existing = Media::query()->where('sha256', $hash)->first();

        if ($existing !== null) {
            return $existing;
        }

        $path = $this->storage->putFile('social/media', $file);

        try {
            return DB::transaction(fn (): Media => Media::query()->create([
                'name' => $this->displayName($file->getClientOriginalName()),
                'path' => $path,
                'mime' => $mime,
                'kind' => $kind,
                'size_bytes' => $sizeBytes,
                'width' => $width,
                'height' => $height,
                'sha256' => $hash,
                'uploaded_by' => $by?->id,
            ]));
        } catch (\Throwable $exception) {
            $this->storage->delete($path);

            throw $exception;
        }
    }

    public function update(Media $media, ?string $altText, ?string $name = null): Media
    {
        $media->fill(['alt_text' => $altText === null || trim($altText) === '' ? null : trim($altText)]);

        if ($name !== null && trim($name) !== '') {
            $media->name = $this->displayName($name);
        }

        $media->save();

        return $media;
    }

    public function isInUse(Media $media): bool
    {
        return PostMedia::query()->where('media_id', $media->id)->exists();
    }

    public function delete(Media $media): void
    {
        if ($this->isInUse($media)) {
            throw ValidationException::withMessages(['media' => __('This file is used by a post. Remove it from the post first.')]);
        }

        $path = $media->path;
        DB::transaction(fn () => $media->delete());
        $this->storage->delete($path);
    }

    public function contents(Media $media): ?string
    {
        return $this->storage->get($media->path);
    }

    /** @throws ValidationException */
    private function kind(string $mime): MediaKind
    {
        if (in_array($mime, (array) config('social.media.image_mimes', []), true)) {
            return MediaKind::Image;
        }

        if (in_array($mime, (array) config('social.media.video_mimes', []), true)) {
            return MediaKind::Video;
        }

        throw ValidationException::withMessages(['file' => __('This file type is not allowed. Use JPEG, PNG, GIF, WebP, MP4 or MOV.')]);
    }

    private function displayName(string $original): string
    {
        $name = basename(str_replace('\\', '/', $original));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return Str::limit(trim($name) === '' ? 'file' : trim($name), 190, '');
    }
}
